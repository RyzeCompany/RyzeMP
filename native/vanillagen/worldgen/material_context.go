package worldgen

import (
	"crypto/sha256"
	"encoding/binary"
	"math"
)

// noWater is PHP_INT_MIN: no fluid above the block (MaterialContext::$waterHeight).
const noWater = math.MinInt64

// materialContext is the state of the block being decorated by the material rules (surface/MaterialContext.php).
type materialContext struct {
	blockX, blockY, blockZ int
	localX, localZ         int
	stoneDepthAbove        int
	stoneDepthBelow        int
	waterHeight            int
	surfaceDepth           int

	chunkStamp, columnStamp, blockStamp int

	// heights is the highest non-air block per column ((x << 4) | z), kept up to date while blocks change
	heights []int

	surfaceSecondaryV     float64
	surfaceSecondaryStamp int
	minSurfaceLevelV      int
	minSurfaceLevelStamp  int
	steepV                bool
	steepStamp            int
	biomeV                int
	biomeStamp            int

	// per noise slot (see materialNoises): value and the column/block stamp it was computed for
	noise2d      []float64
	noise2dStamp []int
	noise3d      []float64
	noise3dStamp []int

	surfaceNoise          *NoiseStack
	surfaceSecondaryNoise *NoiseStack
	random                *PositionalFactory
	chunkSurfaceLevel     Fn
	chunkSurfaceNodes     []*InterpolatedFn
	interp                *interpCache
	biomes                *surfaceBiomes
	noises                []*NoiseStack
	minY, height          int
	seaLevel              int
}

func (c *materialContext) beginChunk() { c.chunkStamp++ }

func (c *materialContext) updateXZ(localX, localZ, blockX, blockZ int) {
	c.columnStamp++
	c.blockStamp++
	c.localX, c.localZ = localX, localZ
	c.blockX, c.blockZ = blockX, blockZ
	n := c.surfaceNoise.Get3D(float64(blockX), 0.0, float64(blockZ))
	r := c.random.At(blockX, 0, blockZ).NextDouble()
	c.surfaceDepth = phpInt(float64(float64(n*2.75)+3.0) + float64(r*0.25))
}

func (c *materialContext) updateY(stoneDepthAbove, stoneDepthBelow, waterHeight, y int) {
	c.blockStamp++
	c.stoneDepthAbove = stoneDepthAbove
	c.stoneDepthBelow = stoneDepthBelow
	c.waterHeight = waterHeight
	c.blockY = y
}

func (c *materialContext) surfaceSecondary() float64 {
	if c.surfaceSecondaryStamp != c.columnStamp {
		c.surfaceSecondaryStamp = c.columnStamp
		c.surfaceSecondaryV = c.surfaceSecondaryNoise.Get3D(float64(c.blockX), 0.0, float64(c.blockZ))
	}
	return c.surfaceSecondaryV
}

func (c *materialContext) minSurfaceLevel() int {
	if c.minSurfaceLevelStamp != c.columnStamp {
		c.minSurfaceLevelStamp = c.columnStamp
		c.interp.inject(c.chunkSurfaceNodes, c.blockX, 0, c.blockZ)
		c.minSurfaceLevelV = phpInt(math.Floor(c.chunkSurfaceLevel.Compute(float64(c.blockX), 0.0, float64(c.blockZ)))) + c.surfaceDepth - 8
	}
	return c.minSurfaceLevelV
}

func (c *materialContext) steep() bool {
	if c.steepStamp != c.columnStamp {
		c.steepStamp = c.columnStamp
		x, z := c.localX, c.localZ
		h := c.heights
		north := h[(x<<4)|max(z-1, 0)]
		south := h[(x<<4)|min(z+1, 15)]
		if south >= north+4 {
			c.steepV = true
		} else {
			west := h[(max(x-1, 0)<<4)|z]
			east := h[(min(x+1, 15)<<4)|z]
			c.steepV = west >= east+4
		}
	}
	return c.steepV
}

func (c *materialContext) biome() int {
	if c.biomeStamp != c.blockStamp {
		c.biomeStamp = c.blockStamp
		c.biomeV = c.biomes.getBiome(c.localX, c.blockY, c.localZ)
	}
	return c.biomeV
}

func (c *materialContext) noise2dAt(slot int) float64 {
	if c.noise2dStamp[slot] != c.columnStamp {
		c.noise2dStamp[slot] = c.columnStamp
		c.noise2d[slot] = c.noises[slot].Get3D(float64(c.blockX), 0.0, float64(c.blockZ))
	}
	return c.noise2d[slot]
}

func (c *materialContext) noise3dAt(slot int) float64 {
	if c.noise3dStamp[slot] != c.blockStamp {
		c.noise3dStamp[slot] = c.blockStamp
		c.noise3d[slot] = c.noises[slot].Get3D(float64(c.blockX), float64(c.blockY), float64(c.blockZ))
	}
	return c.noise3d[slot]
}

// ---- interpolation cache (surface/InterpolationCache.php) ----

type interpNode struct {
	corners  map[int64]float64
	lastKey  int64
	lastOK   bool
	lastCell [8]float64
}

type interpCache struct {
	nodes map[*InterpolatedFn]*interpNode
}

func newInterpCache() *interpCache {
	return &interpCache{nodes: map[*InterpolatedFn]*interpNode{}}
}

func (ic *interpCache) clear() {
	for _, n := range ic.nodes {
		clear(n.corners)
		n.lastOK = false
	}
}

func (ic *interpCache) node(f *InterpolatedFn) *interpNode {
	n := ic.nodes[f]
	if n == nil {
		n = &interpNode{corners: map[int64]float64{}}
		ic.nodes[f] = n
	}
	return n
}

func interpKey(x, y, z int) int64 {
	return (int64(x&0xfffff) << 32) | (int64(y&0xfff) << 20) | int64(z&0xfffff)
}

func cellOrigin(v, size int) int { return v - ((v%size + size) % size) }

func (ic *interpCache) corner(f *InterpolatedFn, n *interpNode, x, y, z int) float64 {
	k := interpKey(x, y, z)
	if v, ok := n.corners[k]; ok {
		return v
	}
	v := f.Input.Compute(float64(x), float64(y), float64(z))
	n.corners[k] = v
	return v
}

func (ic *interpCache) value(f *InterpolatedFn, x, y, z int) float64 {
	w, h := f.CellWidth, f.CellHeight
	x0, y0, z0 := cellOrigin(x, w), cellOrigin(y, h), cellOrigin(z, w)
	n := ic.node(f)
	key := interpKey(x0, y0, z0)
	if !n.lastOK || n.lastKey != key {
		n.lastOK = true
		n.lastKey = key
		n.lastCell = [8]float64{
			ic.corner(f, n, x0, y0, z0),
			ic.corner(f, n, x0+w, y0, z0),
			ic.corner(f, n, x0, y0+h, z0),
			ic.corner(f, n, x0+w, y0+h, z0),
			ic.corner(f, n, x0, y0, z0+w),
			ic.corner(f, n, x0+w, y0, z0+w),
			ic.corner(f, n, x0, y0+h, z0+w),
			ic.corner(f, n, x0+w, y0+h, z0+w),
		}
	}
	c := &n.lastCell
	return lerp3(float64(x-x0)/float64(w), float64(y-y0)/float64(h), float64(z-z0)/float64(w), c[0], c[1], c[2], c[3], c[4], c[5], c[6], c[7])
}

// rangeOf is InterpolationCache::range(): bounds of the interpolated values inside a block box.
func (ic *interpCache) rangeOf(f *InterpolatedFn, x0, y0, z0, x1, y1, z1 int) (float64, float64) {
	w, h := f.CellWidth, f.CellHeight
	cx0, cy0, cz0 := cellOrigin(x0, w), cellOrigin(y0, h), cellOrigin(z0, w)
	lo, hi := math.Inf(1), math.Inf(-1)
	if x1 < cx0+w && y1 < cy0+h && z1 < cz0+w {
		for _, x := range [2]int{x0, x1} {
			for _, y := range [2]int{y0, y1} {
				for _, z := range [2]int{z0, z1} {
					v := ic.value(f, x, y, z)
					if v < lo {
						lo = v
					}
					if v > hi {
						hi = v
					}
				}
			}
		}
	} else {
		n := ic.node(f)
		cx1 := cellOrigin(x1, w) + w
		cy1 := cellOrigin(y1, h) + h
		cz1 := cellOrigin(z1, w) + w
		for cx := cx0; cx <= cx1; cx += w {
			for cy := cy0; cy <= cy1; cy += h {
				for cz := cz0; cz <= cz1; cz += w {
					v := ic.corner(f, n, cx, cy, cz)
					if v < lo {
						lo = v
					}
					if v > hi {
						hi = v
					}
				}
			}
		}
	}
	return lo - 1e-9, hi + 1e-9
}

func (ic *interpCache) inject(nodes []*InterpolatedFn, x, y, z int) {
	for _, n := range nodes {
		n.current = ic.value(n, x, y, z)
		n.useCurrent = true
	}
}

// collectInterpolated is InterpolationCache::collect(): every interpolated node of a graph, breadth first.
func collectInterpolated(f Fn) []*InterpolatedFn {
	var out []*InterpolatedFn
	seen := map[Fn]bool{}
	queue := []Fn{f}
	for i := 0; i < len(queue); i++ {
		node := queue[i]
		if seen[node] {
			continue
		}
		seen[node] = true
		if in, ok := node.(*InterpolatedFn); ok {
			out = append(out, in)
		}
		queue = append(queue, node.Children()...)
	}
	return out
}

// unionInterpolated is PHP's array union of collect() results (first occurrence wins, order kept).
func unionInterpolated(lists ...[]*InterpolatedFn) []*InterpolatedFn {
	var out []*InterpolatedFn
	seen := map[*InterpolatedFn]bool{}
	for _, l := range lists {
		for _, n := range l {
			if !seen[n] {
				seen[n] = true
				out = append(out, n)
			}
		}
	}
	return out
}

// ---- density bounds (surface/DensityBounds.php) ----

func densityBounds(f Fn, x0, y0, z0, x1, y1, z1 int, cache *interpCache) (float64, float64) {
	switch n := f.(type) {
	case *ConstantFn:
		return n.Value, n.Value
	case *NamedFn:
		return densityBounds(n.Input, x0, y0, z0, x1, y1, z1, cache)
	case *CacheFn:
		return densityBounds(n.Input, x0, y0, z0, x1, y1, z1, cache)
	case *InterpolatedFn:
		if cache != nil {
			return cache.rangeOf(n, x0, y0, z0, x1, y1, z1)
		}
		return densityBounds(n.Input, x0, y0, z0, x1, y1, z1, nil)
	case *GradientFn:
		if n.Tiling == tilingClamp {
			a := n.Compute(float64(x0), float64(y0), float64(z0))
			b := n.Compute(float64(x1), float64(y1), float64(z1))
			return phpMin(a, b), phpMax(a, b)
		}
	case *RangeChoiceFn:
		lo, hi := densityBounds(n.Input, x0, y0, z0, x1, y1, z1, cache)
		if lo >= n.MinInclusive && hi < n.MaxExclusive {
			return densityBounds(n.WhenInRange, x0, y0, z0, x1, y1, z1, cache)
		}
		if hi < n.MinInclusive || lo >= n.MaxExclusive {
			return densityBounds(n.WhenOutOfRange, x0, y0, z0, x1, y1, z1, cache)
		}
		a, b := densityBounds(n.WhenInRange, x0, y0, z0, x1, y1, z1, cache)
		c, d := densityBounds(n.WhenOutOfRange, x0, y0, z0, x1, y1, z1, cache)
		return phpMin(a, c), phpMax(b, d)
	case *ClampFn:
		lo, hi := densityBounds(n.Input, x0, y0, z0, x1, y1, z1, cache)
		return phpMin(phpMax(lo, n.Min), n.Max), phpMin(phpMax(hi, n.Min), n.Max)
	case *UnaryFn:
		if n.Op == opNegate {
			lo, hi := densityBounds(n.Input, x0, y0, z0, x1, y1, z1, cache)
			return -hi, -lo
		}
		if n.Op == opAbs {
			lo, hi := densityBounds(n.Input, x0, y0, z0, x1, y1, z1, cache)
			if lo >= 0 {
				return lo, hi
			}
			if hi <= 0 {
				return -hi, -lo
			}
			return 0.0, phpMax(-lo, hi)
		}
	case *BinaryFn:
		if n.Op != opDiv {
			a, b := densityBounds(n.Left, x0, y0, z0, x1, y1, z1, cache)
			c, d := densityBounds(n.Right, x0, y0, z0, x1, y1, z1, cache)
			switch n.Op {
			case opAdd:
				return a + c, b + d
			case opSub:
				return a - d, b - c
			case opMul:
				p := []float64{a * c, a * d, b * c, b * d}
				return phpArrayMin(p), phpArrayMax(p)
			case opMin:
				return phpMin(a, c), phpMin(b, d)
			case opMax:
				return phpMax(a, c), phpMax(b, d)
			}
		}
	}
	return f.MinValue(), f.MaxValue()
}

// ---- block-level biomes with the fuzzy zoom (surface/SurfaceBiomes.php) ----

const (
	surfaceRecentChunks = 64
	lcgMultiplier       = 6364136223846793005
	lcgIncrement        = 1442695040888963407
)

// recentChunkCache keeps the cell biomes of the last chunks in insertion order, like the PHP array (a re-inserted
// key moves to the end; the first key is evicted).
type recentChunkCache struct {
	order []int64
	data  map[int64][]uint16
}

func (r *recentChunkCache) put(key int64, v []uint16) {
	if _, ok := r.data[key]; ok {
		for i, k := range r.order {
			if k == key {
				r.order = append(r.order[:i], r.order[i+1:]...)
				break
			}
		}
	}
	r.data[key] = v
	r.order = append(r.order, key)
	if len(r.order) > surfaceRecentChunks {
		delete(r.data, r.order[0])
		r.order = r.order[1:]
	}
}

type surfaceBiomes struct {
	tree      *ClimateBiomeTree
	climate   *[6]Eval // temperature, vegetation, continents, erosion, depth, ridges
	zoomSeed  int64
	minQuart  int
	quartsY   int
	baseQX    int
	baseQZ    int
	recent    recentChunkCache
	lastLX    int
	lastLZ    int
	lastPY    int
	lastUnif  int
	cells     []int // -1 = not computed; index ((lqx + 1) * 6 + lqz + 1) * quartsY + qy
	fiddles   []float64
	fiddleSet []bool
	uniform   []int // -2 = not computed
}

func obfuscateSeed(seed int64) int64 {
	var b [8]byte
	binary.LittleEndian.PutUint64(b[:], uint64(seed))
	sum := sha256.Sum256(b[:])
	return int64(binary.LittleEndian.Uint64(sum[:8]))
}

func newSurfaceBiomes(tree *ClimateBiomeTree, climate *[6]Eval, seed int64, minY, height int) *surfaceBiomes {
	s := &surfaceBiomes{tree: tree, climate: climate, zoomSeed: obfuscateSeed(seed), minQuart: minY >> 2, quartsY: height >> 2}
	s.recent.data = map[int64][]uint16{}
	s.cells = make([]int, 36*s.quartsY)
	s.fiddles = make([]float64, 36*(s.quartsY+2)*3)
	s.fiddleSet = make([]bool, 36*(s.quartsY+2))
	s.uniform = make([]int, 36*(s.quartsY+1))
	return s
}

func chunkHash(x, z int) int64 {
	return (int64(x&0xffffffff) << 32) | int64(z&0xffffffff)
}

func (s *surfaceBiomes) beginChunk(chunkX, chunkZ int, biomes []uint16) {
	s.baseQX = chunkX << 2
	s.baseQZ = chunkZ << 2
	for i := range s.cells {
		s.cells[i] = -1
	}
	for i := range s.fiddleSet {
		s.fiddleSet[i] = false
	}
	for i := range s.uniform {
		s.uniform[i] = -2
	}
	s.lastLX = -1
	s.recent.put(chunkHash(chunkX, chunkZ), biomes)
	q := s.quartsY
	for qx := 0; qx < 4; qx++ {
		for qz := 0; qz < 4; qz++ {
			src := ((qx << 2) | qz) * q
			dst := ((qx+1)*6 + qz + 1) * q
			for qy := 0; qy < q; qy++ {
				s.cells[dst+qy] = int(biomes[src+qy])
			}
		}
	}
}

func (s *surfaceBiomes) getBiome(localX, y, localZ int) int {
	absX, absY, absZ := localX-2, y-2, localZ-2
	px, py, pz := absX>>2, absY>>2, absZ>>2
	if py == s.lastPY && localX == s.lastLX && localZ == s.lastLZ {
		return s.lastUnif
	}
	parent := ((px+1)*6+pz+1)*(s.quartsY+1) + py - s.minQuart + 1
	if s.uniform[parent] == -2 {
		s.computeUniform(parent, px, py, pz)
	}
	if u := s.uniform[parent]; u >= 0 {
		s.lastLX, s.lastLZ, s.lastPY, s.lastUnif = localX, localZ, absY>>2, u
		return u
	}
	fx := float64(absX&3) / 4.0
	fy := float64(absY&3) / 4.0
	fz := float64(absZ&3) / 4.0
	best := 0
	bestDistance := math.Inf(1)
	for i := 0; i < 8; i++ {
		xEven, yEven, zEven := i&4 == 0, i&2 == 0, i&1 == 0
		cx, cy, cz := px, py, pz
		dx, dy, dz := fx, fy, fz
		if !xEven {
			cx++
			dx = fx - 1.0
		}
		if !yEven {
			cy++
			dy = fy - 1.0
		}
		if !zEven {
			cz++
			dz = fz - 1.0
		}
		o := s.fiddleIndex(cx, cy, cz)
		dx += s.fiddles[o]
		dy += s.fiddles[o+1]
		dz += s.fiddles[o+2]
		distance := float64(float64(dz*dz)+float64(dy*dy)) + float64(dx*dx)
		if bestDistance > distance {
			best = i
			bestDistance = distance
		}
	}
	cx, cy, cz := px, py, pz
	if best&4 != 0 {
		cx++
	}
	if best&2 != 0 {
		cy++
	}
	if best&1 != 0 {
		cz++
	}
	return s.cell(cx, cy, cz)
}

func (s *surfaceBiomes) computeUniform(parent, px, py, pz int) {
	first := s.cell(px, py, pz)
	for i := 1; i < 8; i++ {
		cx, cy, cz := px, py, pz
		if i&4 != 0 {
			cx++
		}
		if i&2 != 0 {
			cy++
		}
		if i&1 != 0 {
			cz++
		}
		if s.cell(cx, cy, cz) != first {
			s.uniform[parent] = -1
			return
		}
	}
	s.uniform[parent] = first
}

func (s *surfaceBiomes) cell(lqx, qy, lqz int) int {
	iy := qy - s.minQuart
	if iy < 0 {
		iy = 0
	} else if iy >= s.quartsY {
		iy = s.quartsY - 1
	}
	column := (lqx+1)*6 + lqz + 1
	index := column*s.quartsY + iy
	if v := s.cells[index]; v >= 0 {
		return v
	}
	if nb, ok := s.recent.data[chunkHash((s.baseQX+lqx)>>2, (s.baseQZ+lqz)>>2)]; ok {
		v := int(nb[((((lqx&3)<<2)|(lqz&3))*s.quartsY)+iy])
		s.cells[index] = v
		return v
	}
	cl := s.climate
	x := float64((s.baseQX + lqx) << 2)
	z := float64((s.baseQZ + lqz) << 2)
	t := Quantize(cl[0](x, 0.0, z))
	v := Quantize(cl[1](x, 0.0, z))
	c := Quantize(cl[2](x, 0.0, z))
	e := Quantize(cl[3](x, 0.0, z))
	w := Quantize(cl[5](x, 0.0, z))
	base := column * s.quartsY
	for cellY := 0; cellY < s.quartsY; cellY++ {
		y := float64((s.minQuart + cellY) << 2)
		d := Quantize(cl[4](x, y, z))
		s.cells[base+cellY] = s.tree.Search(t, v, c, e, d, w)
	}
	return s.cells[index]
}

func (s *surfaceBiomes) fiddleIndex(lqx, cy, lqz int) int {
	slot := ((lqx+1)*6+lqz+1)*(s.quartsY+2) + cy - s.minQuart + 1
	index := slot * 3
	if !s.fiddleSet[slot] {
		s.fiddleSet[slot] = true
		seed := s.zoomSeed
		x := int64(s.baseQX + lqx)
		z := int64(s.baseQZ + lqz)
		y := int64(cy)
		r := lcgNext(seed, x)
		r = lcgNext(r, y)
		r = lcgNext(r, z)
		r = lcgNext(r, x)
		r = lcgNext(r, y)
		r = lcgNext(r, z)
		s.fiddles[index] = fiddle(r)
		r = lcgNext(r, seed)
		s.fiddles[index+1] = fiddle(r)
		r = lcgNext(r, seed)
		s.fiddles[index+2] = fiddle(r)
	}
	return index
}

func lcgNext(value, add int64) int64 {
	return value*(value*lcgMultiplier+lcgIncrement) + add
}

func fiddle(value int64) float64 {
	return float64((float64((value>>24)&1023)/1024.0 - 0.5) * 0.9)
}

// ObfuscateSeed is SurfaceBiomes::obfuscateSeed() (BiomeManager's zoom seed).
func ObfuscateSeed(seed int64) int64 { return obfuscateSeed(seed) }
