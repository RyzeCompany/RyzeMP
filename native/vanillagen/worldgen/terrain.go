package worldgen

import (
	"fmt"
)

// Options of a generator session.
type Options struct {
	Settings string // noise settings id, "overworld"
	// NoAquifers disables aquifers (global sea/lava levels only), for comparisons with deepslate
	NoAquifers bool
	// DeepslateFloats keeps spline coordinates and constants in double precision (deepslate 0.27.2 behaviour),
	// only used to compare against deepslate reference values
	DeepslateFloats bool
	// Materials applies the material rules (Chunk.Materials then hold MaterialSystem palette ids)
	Materials bool
	// Carvers also runs the carvers (needs Materials)
	Carvers bool
}

// Generator fills the terrain stage of chunks for one seed (CompiledTerrainFiller.php). Not safe for concurrent
// use: create one per goroutine.
type Generator struct {
	Router   *Router
	Tree     *ClimateBiomeTree
	opts     Options
	channels []*InterpolatedFn
	cellW    int
	cellH    int
	aqRandom *PositionalFactory

	comp       *compiler
	channelIn  []Eval
	final      Eval
	aquiferFns *aquiferFunctions
	climate    [6]Eval // temperature, vegetation, continents, erosion, depth, ridges

	corners [][]float64
	density []float64

	// ReuseChunks makes every Fill return the same Chunk buffers, overwritten by the next Fill (for callers that are
	// done with a chunk before asking for the next one, like the server): no 100 KB allocation per chunk.
	ReuseChunks bool
	reused      *Chunk

	// Materials and Carvers are the later stages, when enabled in the options (Carvers is nil without carver data)
	Materials *MaterialSystem
	Carvers   *CarverSystem
}

// Chunk is the result of the terrain stage (TerrainChunk.php).
type Chunk struct {
	ChunkX, ChunkZ int
	MinY, Height   int
	// Materials per block, index ((x << 4) | z) * Height + (y - MinY)
	Materials []byte
	// Biomes per 4x4x4 cell (index into Tree.Names()), index ((qx << 2) | qz) * (Height >> 2) + qy
	Biomes []uint16
	// Stage reached: 0 terrain (Material* ids), 1 material rules, 2 carved (MaterialSystem palette ids)
	Stage int
	// Mask is the carving mask after the carver stage (same index as Materials; reused by the next Fill)
	Mask []bool
}

func NewGenerator(data *Data, seed int64, opts Options, tree *ClimateBiomeTree) (*Generator, error) {
	if opts.Settings == "" {
		opts.Settings = "overworld"
	}
	r, err := NewRouter(data, seed, opts.Settings, !opts.DeepslateFloats)
	if err != nil {
		return nil, err
	}
	g := &Generator{Router: r, Tree: tree, opts: opts}
	seen := map[Fn]bool{}
	g.collect(r.FinalDensity, seen)
	if len(g.channels) == 0 {
		return nil, fmt.Errorf("the final density has no interpolated part")
	}
	g.cellW = g.channels[0].CellWidth
	g.cellH = g.channels[0].CellHeight
	for _, c := range g.channels {
		if c.CellWidth != g.cellW || c.CellHeight != g.cellH {
			return nil, fmt.Errorf("all interpolated functions of the final density must use the same cell size")
		}
	}
	if 16%g.cellW != 0 || r.Height%g.cellH != 0 {
		return nil, fmt.Errorf("unsupported cell size %dx%d", g.cellW, g.cellH)
	}
	if r.AquifersEnabled {
		g.aqRandom = r.Random.FromHashOf("minecraft:aquifer").ForkPositional()
	}
	roots := []Fn{r.FinalDensity, r.Temperature, r.Vegetation, r.Continents, r.Erosion, r.Depth, r.Ridges}
	for _, ch := range g.channels {
		roots = append(roots, ch.Input)
	}
	if r.AquifersEnabled {
		roots = append(roots, r.AquiferBarrier, r.AquiferFloodedness, r.AquiferSpread, r.AquiferLava, r.AquiferExclusion, r.AquiferSurface)
	}
	g.comp = newCompiler(roots...)
	for _, ch := range g.channels {
		g.channelIn = append(g.channelIn, g.comp.compile(ch.Input))
	}
	g.final = g.comp.compile(r.FinalDensity)
	for i, f := range []Fn{r.Temperature, r.Vegetation, r.Continents, r.Erosion, r.Depth, r.Ridges} {
		g.climate[i] = g.comp.compile(f)
	}
	if r.AquifersEnabled {
		g.aquiferFns = &aquiferFunctions{
			barrier:     g.comp.compile(r.AquiferBarrier),
			floodedness: g.comp.compile(r.AquiferFloodedness),
			spread:      g.comp.compile(r.AquiferSpread),
			lava:        g.comp.compile(r.AquiferLava),
			exclusion:   g.comp.compile(r.AquiferExclusion),
			surface:     g.comp.compile(r.AquiferSurface),
		}
	}
	cellsXZ := 16 / g.cellW
	cornersY := r.Height/g.cellH + 1
	g.corners = make([][]float64, len(g.channels))
	for k := range g.corners {
		g.corners[k] = make([]float64, (cellsXZ+1)*(cellsXZ+1)*cornersY)
	}
	g.density = make([]float64, 256*r.Height)
	if opts.Materials || opts.Carvers {
		if g.Materials, err = NewMaterialSystem(data, r, tree, &g.climate, seed, opts.Settings); err != nil {
			return nil, err
		}
	}
	if opts.Carvers {
		if g.Carvers, err = NewCarverSystem(data, tree, &g.climate, seed, g.Materials, r.MinY, r.Height); err != nil {
			return nil, err
		}
	}
	return g, nil
}

func (g *Generator) collect(f Fn, seen map[Fn]bool) {
	if seen[f] {
		return
	}
	seen[f] = true
	if i, ok := f.(*InterpolatedFn); ok {
		g.channels = append(g.channels, i)
		return
	}
	for _, c := range f.Children() {
		g.collect(c, seen)
	}
}

// fillDensity computes the final density of every block (KernelFactory::compileFill).
func (g *Generator) fillDensity(baseX, baseZ int) []float64 {
	r := g.Router
	w, h := g.cellW, g.cellH
	minY, height := r.MinY, r.Height
	cellsXZ := 16 / w
	cellsY := height / h
	cornersY := cellsY + 1

	g.comp.resetMemos()
	for k := range g.channels {
		in := g.channelIn[k]
		c := g.corners[k]
		i := 0
		for cx := 0; cx <= cellsXZ; cx++ {
			x := float64(baseX + cx*w)
			for cz := 0; cz <= cellsXZ; cz++ {
				z := float64(baseZ + cz*w)
				for cy := 0; cy < cornersY; cy++ {
					c[i] = in(x, float64(minY+cy*h), z)
					i++
				}
			}
		}
	}
	g.comp.resetMemos()

	n := len(g.channels)
	var a000, a010, a100, a110, a001, a011, a101, a111 [16]float64
	var e00, e10, e01, e11, g0, g1 [16]float64
	if n > 16 {
		panic("too many interpolated channels")
	}
	final := g.final
	d := g.density
	for _, ch := range g.channels {
		ch.useCurrent = true
	}
	defer func() {
		for _, ch := range g.channels {
			ch.useCurrent = false
		}
	}()
	fw := float64(w)
	fh := float64(h)
	for cx := 0; cx < cellsXZ; cx++ {
		for cz := 0; cz < cellsXZ; cz++ {
			i00 := (cx*(cellsXZ+1) + cz) * cornersY
			i10 := ((cx+1)*(cellsXZ+1) + cz) * cornersY
			i01 := (cx*(cellsXZ+1) + cz + 1) * cornersY
			i11 := ((cx+1)*(cellsXZ+1) + cz + 1) * cornersY
			for cy := cellsY - 1; cy >= 0; cy-- {
				for k := 0; k < n; k++ {
					c := g.corners[k]
					a000[k] = c[i00+cy]
					a010[k] = c[i00+cy+1]
					a100[k] = c[i10+cy]
					a110[k] = c[i10+cy+1]
					a001[k] = c[i01+cy]
					a011[k] = c[i01+cy+1]
					a101[k] = c[i11+cy]
					a111[k] = c[i11+cy+1]
				}
				for ly := h - 1; ly >= 0; ly-- {
					fy := float64(ly) / fh
					y := float64(minY + cy*h + ly)
					yi := cy*h + ly
					for k := 0; k < n; k++ {
						e00[k] = lerp1(fy, a000[k], a010[k])
						e10[k] = lerp1(fy, a100[k], a110[k])
						e01[k] = lerp1(fy, a001[k], a011[k])
						e11[k] = lerp1(fy, a101[k], a111[k])
					}
					for lx := 0; lx < w; lx++ {
						fx := float64(lx) / fw
						bx := cx*w + lx
						x := float64(baseX + bx)
						for k := 0; k < n; k++ {
							g0[k] = lerp1(fx, e00[k], e10[k])
							g1[k] = lerp1(fx, e01[k], e11[k])
						}
						for lz := 0; lz < w; lz++ {
							fz := float64(lz) / fw
							bz := cz*w + lz
							z := float64(baseZ + bz)
							for k, ch := range g.channels {
								ch.current = lerp1(fz, g0[k], g1[k])
							}
							d[((bx<<4)|bz)*height+yi] = final(x, y, z)
						}
					}
				}
			}
		}
	}
	return d
}

// Fill generates the terrain stage of a chunk.
func (g *Generator) Fill(chunkX, chunkZ int) *Chunk {
	return g.FillWithBeardifier(chunkX, chunkZ, nil)
}

// FillWithBeardifier generates the terrain stage of a chunk with the terrain adaptation of nearby structures (the
// final density's beardifier term), like CompiledTerrainFiller::fill().
func (g *Generator) FillWithBeardifier(chunkX, chunkZ int, beard *Beardifier) *Chunk {
	r := g.Router
	minY, height := r.MinY, r.Height
	baseX := chunkX << 4
	baseZ := chunkZ << 4
	density := g.fillDensity(baseX, baseZ)
	if beard != nil {
		for bx := 0; bx < 16; bx++ {
			for bz := 0; bz < 16; bz++ {
				y0, values, ok := beard.Column(baseX+bx, baseZ+bz, minY, minY+height-1)
				if !ok {
					continue
				}
				base := ((bx<<4)|bz)*height + y0 - minY
				for k, v := range values {
					density[base+k] = fround(density[base+k] + v)
				}
			}
		}
	}
	g.comp.resetMemos()

	var aq *Aquifer
	if !g.opts.NoAquifers && r.AquifersEnabled {
		aq = NewAquifer(r, g.aquiferFns, g.aqRandom, chunkX, chunkZ)
	}
	sea := r.SeaLevel
	lavaLevel := sea
	if -54 < sea {
		lavaLevel = -54
	}
	skipAbove := minY - 1
	if aq != nil {
		skipAbove = aq.SkipSamplingAboveY()
	}
	var materials []byte
	if g.ReuseChunks && g.reused != nil && len(g.reused.Materials) == 256*height {
		materials = g.reused.Materials
	} else {
		materials = make([]byte, 256*height)
	}
	i := 0
	for bx := 0; bx < 16; bx++ {
		x := baseX + bx
		for bz := 0; bz < 16; bz++ {
			z := baseZ + bz
			for ly := 0; ly < height; ly++ {
				dv := density[i]
				var m int
				if dv > 0 {
					m = MaterialStone
				} else {
					y := minY + ly
					if y < lavaLevel {
						m = MaterialLava
					} else if aq == nil || y > skipAbove {
						if y < sea {
							m = r.SeaMaterial
						} else {
							m = MaterialAir
						}
					} else {
						m = aq.ComputeSubstance(x, y, z, dv)
						if m == materialKeep {
							m = MaterialStone
						}
					}
				}
				materials[i] = byte(m)
				i++
			}
		}
	}
	var ch *Chunk
	if g.ReuseChunks {
		if g.reused == nil {
			g.reused = &Chunk{}
		}
		ch = g.reused
		*ch = Chunk{ChunkX: chunkX, ChunkZ: chunkZ, MinY: minY, Height: height, Materials: materials, Biomes: g.FillBiomes(chunkX, chunkZ)} // not reused: the material rules keep recent biome arrays
	} else {
		ch = &Chunk{ChunkX: chunkX, ChunkZ: chunkZ, MinY: minY, Height: height, Materials: materials, Biomes: g.FillBiomes(chunkX, chunkZ)}
	}
	if g.Materials != nil {
		g.comp.resetMemos()
		g.Materials.Apply(ch)
		ch.Stage = 1
		if g.Carvers != nil {
			ch.Mask = g.Carvers.Apply(ch, aq, sea, r.SeaMaterial)
			ch.Stage = 2
		}
	}
	return ch
}

// FillBiomes computes the biome of every 4x4x4 cell (CompiledTerrainFiller::fillBiomes).
func (g *Generator) FillBiomes(chunkX, chunkZ int) []uint16 {
	return g.fillBiomesInto(make([]uint16, 0, 16*(g.Router.Height>>2)), chunkX, chunkZ)
}

// fillBiomesInto is FillBiomes appending to out.
func (g *Generator) fillBiomesInto(out []uint16, chunkX, chunkZ int) []uint16 {
	r := g.Router
	cl := &g.climate
	quartsY := r.Height >> 2
	minQuartY := r.MinY >> 2
	if single := g.Tree.SingleBiome(); single >= 0 {
		for i := 0; i < 16*quartsY; i++ {
			out = append(out, uint16(single))
		}
		return out
	}
	for qx := 0; qx < 4; qx++ {
		for qz := 0; qz < 4; qz++ {
			x := float64((chunkX << 4) + (qx << 2))
			z := float64((chunkZ << 4) + (qz << 2))
			t := Quantize(cl[0](x, 0.0, z))
			v := Quantize(cl[1](x, 0.0, z))
			c := Quantize(cl[2](x, 0.0, z))
			e := Quantize(cl[3](x, 0.0, z))
			w := Quantize(cl[5](x, 0.0, z))
			for qy := 0; qy < quartsY; qy++ {
				dd := Quantize(cl[4](x, float64((minQuartY+qy)<<2), z))
				out = append(out, uint16(g.Tree.Search(t, v, c, e, dd, w)))
			}
		}
	}
	return out
}

// SampleBiome is the biome (index into Tree.Names()) of one 4x4x4 cell, by quart coordinates, like FillBiomes and
// the PHP generator's biomeSampler().
func (g *Generator) SampleBiome(qx, qy, qz int) int {
	if single := g.Tree.SingleBiome(); single >= 0 {
		return single
	}
	cl := &g.climate
	x := float64(qx << 2)
	z := float64(qz << 2)
	return g.Tree.Search(
		Quantize(cl[0](x, 0.0, z)),
		Quantize(cl[1](x, 0.0, z)),
		Quantize(cl[2](x, 0.0, z)),
		Quantize(cl[3](x, 0.0, z)),
		Quantize(cl[4](x, float64(qy<<2), z)),
		Quantize(cl[5](x, 0.0, z)),
	)
}
