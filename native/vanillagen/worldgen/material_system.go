package worldgen

import (
	"encoding/json"
	"fmt"
	"math"
)

// MaterialSystem runs the noise settings' material rules on the terrain of a chunk (surface/MaterialSystem.php):
// bedrock floor, ore veins, biome surfaces, badlands bands, deepslate..., plus the eroded badlands pillars and frozen
// ocean icebergs. One per generator (not safe for concurrent use).
type MaterialSystem struct {
	Palette *MaterialPalette
	rule    materialRule
	ctx     *materialContext
	biomes  *surfaceBiomes
	interp  *interpCache
	random  *PositionalFactory

	erodedBadlands, frozenOcean, deepFrozenOcean int
	snowBlock, packedIce                         int

	badlandsPillar, badlandsPillarRoof, badlandsSurface *NoiseStack
	icebergPillar, icebergPillarRoof, icebergSurface    *NoiseStack

	frozenClimate   map[int]frozenClimate
	frozenTempNoise *PerlinSimplexNoise
	biomeInfoNoise  *PerlinSimplexNoise

	minY, height int
	baseX, baseZ int
	heights      []int
}

type frozenClimate struct {
	temperature float64
	frozen      bool
}

func NewMaterialSystem(data *Data, r *Router, tree *ClimateBiomeTree, climate *[6]Eval, seed int64, settingsID string) (*MaterialSystem, error) {
	settings, err := data.NoiseSettings(settingsID)
	if err != nil {
		return nil, err
	}
	parser := r.NewParser()
	parser.vanillaFloats = true
	m := &MaterialSystem{Palette: newMaterialPalette(r.DefaultBlock), interp: newInterpCache(), random: r.Random, minY: r.MinY, height: r.Height}
	m.biomes = newSurfaceBiomes(tree, climate, seed, r.MinY, r.Height)
	biomeIndexes := map[string]int{}
	for i, name := range tree.Names() {
		biomeIndexes["minecraft:"+stripNamespace(name)] = i
	}
	idx := func(name string) int {
		if v, ok := biomeIndexes[name]; ok {
			return v
		}
		return -1
	}
	m.erodedBadlands = idx("minecraft:eroded_badlands")
	m.frozenOcean = idx("minecraft:frozen_ocean")
	m.deepFrozenOcean = idx("minecraft:deep_frozen_ocean")

	router, ok := settings["noise_router"].(map[string]any)
	if !ok {
		return nil, fmt.Errorf("expected array for \"noise_router\"")
	}
	surface, err := parser.GetNoise("minecraft:surface")
	if err != nil {
		return nil, err
	}
	secondary, err := parser.GetNoise("minecraft:surface_secondary")
	if err != nil {
		return nil, err
	}
	csl, ok := router["chunk_surface_level"]
	if !ok || csl == nil {
		csl = json.Number("0.0")
	}
	chunkSurfaceLevel, err := parser.Parse(csl)
	if err != nil {
		return nil, err
	}
	rp := &materialRuleParser{
		data: data, density: parser, random: r.Random, palette: m.Palette, biomes: biomeIndexes, biomeCount: len(tree.Names()),
		interp: m.interp, minY: r.MinY, height: r.Height,
		rules: map[string]materialRule{}, conditions: map[string]materialCondition{}, resolving: map[string]bool{},
		noiseSlots: map[*NoiseStack]int{},
	}
	ruleJSON, ok := settings["material_rule"]
	if !ok || ruleJSON == nil {
		return nil, fmt.Errorf("noise settings without a material rule (pre-26.3 data?)")
	}
	if m.rule, err = rp.rule(ruleJSON); err != nil {
		return nil, err
	}
	m.snowBlock = m.Palette.register("minecraft:snow_block")
	m.packedIce = m.Palette.register("minecraft:packed_ice")
	if len(m.Palette.names) > 256 {
		return nil, fmt.Errorf("too many materials (%d)", len(m.Palette.names))
	}
	for _, p := range []struct {
		dst  **NoiseStack
		name string
	}{
		{&m.badlandsPillar, "minecraft:badlands_pillar"}, {&m.badlandsPillarRoof, "minecraft:badlands_pillar_roof"},
		{&m.badlandsSurface, "minecraft:badlands_surface"}, {&m.icebergPillar, "minecraft:iceberg_pillar"},
		{&m.icebergPillarRoof, "minecraft:iceberg_pillar_roof"}, {&m.icebergSurface, "minecraft:iceberg_surface"},
	} {
		if *p.dst, err = parser.GetNoise(p.name); err != nil {
			return nil, err
		}
	}
	m.frozenClimate = map[int]frozenClimate{}
	for _, i := range []int{m.frozenOcean, m.deepFrozenOcean} {
		if i < 0 {
			continue
		}
		b, err := data.Biome(tree.Names()[i])
		if err != nil {
			return nil, err
		}
		t, err := jsonFloat(b, "temperature", nil)
		if err != nil {
			return nil, err
		}
		mod, _ := b["temperature_modifier"].(string)
		m.frozenClimate[i] = frozenClimate{t, mod != "" && stripNamespace(mod) == "frozen"}
	}

	slots := len(rp.noises)
	m.ctx = &materialContext{
		surfaceNoise: surface, surfaceSecondaryNoise: secondary, random: r.Random,
		chunkSurfaceLevel: chunkSurfaceLevel, chunkSurfaceNodes: collectInterpolated(chunkSurfaceLevel),
		interp: m.interp, biomes: m.biomes, noises: rp.noises, minY: r.MinY, height: r.Height, seaLevel: r.SeaLevel,
		noise2d: make([]float64, slots), noise2dStamp: make([]int, slots), noise3d: make([]float64, slots), noise3dStamp: make([]int, slots),
		surfaceSecondaryStamp: -1, minSurfaceLevelStamp: -1, steepStamp: -1, biomeStamp: -1, waterHeight: noWater,
	}
	for i := range m.ctx.noise2dStamp {
		m.ctx.noise2dStamp[i] = -1
		m.ctx.noise3dStamp[i] = -1
	}
	m.heights = make([]int, 256)
	return m, nil
}

// Apply runs the material rules on a chunk of the terrain stage, in place.
func (m *MaterialSystem) Apply(ch *Chunk) {
	c := m.ctx
	c.beginChunk()
	m.interp.clear()
	m.biomes.beginChunk(ch.ChunkX, ch.ChunkZ, ch.Biomes)

	materials := ch.Materials
	minY, height := ch.MinY, ch.Height
	baseX, baseZ := ch.ChunkX<<4, ch.ChunkZ<<4
	m.baseX, m.baseZ = baseX, baseZ
	rule := m.rule

	heights := m.heights
	for column := 0; column < 256; column++ {
		base := column * height
		ly := height - 1
		for ly >= 0 && materials[base+ly] == MaterialAir {
			ly--
		}
		heights[column] = minY + ly
	}
	c.heights = heights

	for x := 0; x < 16; x++ {
		for z := 0; z < 16; z++ {
			column := (x << 4) | z
			base := column*height - minY
			blockX, blockZ := baseX+x, baseZ+z
			startingHeight := heights[column] + 1
			biome := m.biomes.getBiome(x, startingHeight, z)
			if biome == m.erodedBadlands {
				m.erodedBadlandsExtension(materials, column, base, blockX, blockZ, startingHeight)
			}

			c.updateXZ(x, z, blockX, blockZ)
			stoneDepthAbove := 0
			waterHeight := noWater
			nextCeilingStoneY := math.MaxInt64
			for y := heights[column]; y >= minY; y-- {
				mat := int(materials[base+y])
				if mat == MaterialAir {
					stoneDepthAbove = 0
					waterHeight = noWater
					continue
				}
				if mat == MaterialWater || mat == MaterialLava {
					if waterHeight == noWater {
						waterHeight = y + 1
					}
					continue
				}
				if nextCeilingStoneY >= y {
					nextCeilingStoneY = minY
					for ly := y - 1; ly >= minY; ly-- {
						below := int(materials[base+ly])
						if below == MaterialAir || below == MaterialWater || below == MaterialLava {
							nextCeilingStoneY = ly + 1
							break
						}
					}
				}
				c.blockStamp++
				stoneDepthAbove++
				c.stoneDepthAbove = stoneDepthAbove
				c.stoneDepthBelow = y - nextCeilingStoneY + 1
				c.waterHeight = waterHeight
				c.blockY = y
				result := rule.apply(c)
				if result != ruleNone {
					materials[base+y] = byte(result)
					if result == MaterialAir && y == heights[column] {
						heights[column] = topBlock(materials, base, y-1, minY)
					}
				}
			}

			if biome == m.frozenOcean || biome == m.deepFrozenOcean {
				m.frozenOceanExtension(materials, column, base, blockX, blockZ, startingHeight, biome)
			}
		}
	}
}

func topBlock(materials []byte, base, fromY, minY int) int {
	for y := fromY; y >= minY; y-- {
		if materials[base+y] != MaterialAir {
			return y
		}
	}
	return minY - 1
}

// Heights returns the highest non-air block per column of the chunk the rules were last applied to.
func (m *MaterialSystem) Heights() []int { return m.heights }

// TopMaterial is MaterialSystem::topMaterial(): the rule result for a floor block of the last chunk (stone depth 1
// above and below), optionally under a fluid. Returns a palette id or -1.
func (m *MaterialSystem) TopMaterial(heights []int, localX, y, localZ int, fluid bool) int {
	c := m.ctx
	c.heights = heights
	c.updateXZ(localX, localZ, m.baseX+localX, m.baseZ+localZ)
	water := noWater
	if fluid {
		water = y + 1
	}
	c.updateY(1, 1, water, y)
	return m.rule.apply(c)
}

func (m *MaterialSystem) erodedBadlandsExtension(materials []byte, column, base, blockX, blockZ, height int) {
	bx, bz := float64(blockX), float64(blockZ)
	pillarFloor := phpMin(
		math.Abs(float64(m.badlandsSurface.Get3D(bx, 0.0, bz)*8.25)),
		float64(m.badlandsPillar.Get3D(float64(bx*0.2), 0.0, float64(bz*0.2))*15.0),
	)
	if pillarFloor <= 0.0 {
		return
	}
	pillarRoof := math.Abs(float64(m.badlandsPillarRoof.Get3D(float64(bx*0.75), 0.0, float64(bz*0.75)) * 1.5))
	pillarHeight := 64.0 + phpMin(float64(float64(pillarFloor*pillarFloor)*2.5), math.Ceil(float64(pillarRoof*50.0))+24.0)
	startY := phpInt(math.Floor(pillarHeight))
	if height > startY {
		return
	}
	minY := m.minY
	maxY := minY + m.height - 1
	for y := startY; y >= minY; y-- {
		mat := MaterialAir
		if y <= maxY {
			mat = int(materials[base+y])
		}
		if mat == MaterialStone {
			break
		}
		if mat == MaterialWater {
			return
		}
	}
	for y := startY; y >= minY && (y > maxY || materials[base+y] == MaterialAir); y-- {
		if y <= maxY {
			materials[base+y] = MaterialStone
			m.ctx.heights[column] = max(m.ctx.heights[column], y)
		}
	}
}

func (m *MaterialSystem) frozenOceanExtension(materials []byte, column, base, blockX, blockZ, height, biome int) {
	c := m.ctx
	seaLevel := c.seaLevel
	bx, bz := float64(blockX), float64(blockZ)
	noise := phpMin(
		math.Abs(float64(m.icebergSurface.Get3D(bx, 0.0, bz)*8.25)),
		float64(m.icebergPillar.Get3D(float64(bx*1.28), 0.0, float64(bz*1.28))*15.0),
	)
	if noise <= 1.8 {
		return
	}
	roof := math.Abs(float64(m.icebergPillarRoof.Get3D(float64(bx*1.17), 0.0, float64(bz*1.17)) * 1.5))
	icebergHeight := phpMin(float64(float64(noise*noise)*1.2), math.Ceil(float64(roof*40.0))+14.0)
	if m.shouldMelt(biome, blockX, blockZ) {
		icebergHeight -= 2.0
	}
	var icebergTop float64
	if icebergHeight > 2.0 {
		icebergTop = float64(seaLevel) - icebergHeight - 7.0
		icebergHeight += float64(seaLevel)
	} else {
		icebergHeight = 0.0
		icebergTop = 0.0
	}
	maxIcebergHeight := phpInt(icebergHeight)
	icebergTopY := phpInt(icebergTop)
	random := m.random.At(blockX, 0, blockZ)
	maxSnowBlocks := 2 + random.NextBoundedInt(4)
	snowBottom := seaLevel + 18 + random.NextBoundedInt(10)
	snowBlocks := 0
	minSurfaceLevel := c.minSurfaceLevel()
	minY := m.minY
	maxY := minY + m.height - 1
	for y := max(height, maxIcebergHeight+1); y >= minSurfaceLevel; y-- {
		if y < minY {
			break
		}
		mat := MaterialAir
		if y <= maxY {
			mat = int(materials[base+y])
		}
		if (mat == MaterialAir && y < maxIcebergHeight && random.NextDouble() > 0.01) ||
			(mat == MaterialWater && y > icebergTopY && y < seaLevel && icebergTop != 0.0 && random.NextDouble() > 0.15) {
			if y > maxY {
				continue
			}
			if snowBlocks <= maxSnowBlocks && y > snowBottom {
				materials[base+y] = byte(m.snowBlock)
				snowBlocks++
			} else {
				materials[base+y] = byte(m.packedIce)
			}
			c.heights[column] = max(c.heights[column], y)
		}
	}
}

func (m *MaterialSystem) shouldMelt(biome, blockX, blockZ int) bool {
	fc := m.frozenClimate[biome]
	temperature := fc.temperature
	if fc.frozen {
		if m.frozenTempNoise == nil {
			m.frozenTempNoise = NewPerlinSimplexNoise(NewLegacyRandom(3456), []int{-2, -1, 0})
			m.biomeInfoNoise = NewPerlinSimplexNoise(NewLegacyRandom(2345), []int{0})
		}
		bx, bz := float64(blockX), float64(blockZ)
		value := float64(m.frozenTempNoise.GetValue(float64(bx*0.05), float64(bz*0.05))*7.0) + m.biomeInfoNoise.GetValue(float64(bx*0.2), float64(bz*0.2))
		if value < 0.3 && m.biomeInfoNoise.GetValue(float64(bx*0.09), float64(bz*0.09)) < 0.8 {
			temperature = 0.2
		}
	}
	return temperature > 0.1
}
