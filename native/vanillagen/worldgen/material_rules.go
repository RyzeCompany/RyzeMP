package worldgen

import (
	"fmt"
	"math"
)

// ruleNone is MaterialRule::NONE: the rule gives no block.
const ruleNone = -1

// MaterialPalette assigns small ids to the Java block ids produced by the material rules (surface/MaterialPalette.php).
// The terrain materials (Material*) keep their ids.
type MaterialPalette struct {
	ids   map[string]int
	names []string
}

// newMaterialPalette starts with the terrain materials; MaterialStone is the noise settings' default block.
func newMaterialPalette(defaultBlock string) *MaterialPalette {
	p := &MaterialPalette{ids: map[string]int{}}
	for _, n := range []string{"minecraft:air", defaultBlock, "minecraft:water", "minecraft:lava"} {
		p.register(n)
	}
	return p
}

func (p *MaterialPalette) register(id string) int {
	name := "minecraft:" + stripNamespace(id)
	if v, ok := p.ids[name]; ok {
		return v
	}
	p.ids[name] = len(p.names)
	p.names = append(p.names, name)
	return p.ids[name]
}

func (p *MaterialPalette) get(id string) (int, bool) {
	v, ok := p.ids["minecraft:"+stripNamespace(id)]
	return v, ok
}

func (p *MaterialPalette) Names() []string { return p.names }

// ---- rules ----

type materialRule interface {
	apply(c *materialContext) int
}

type materialCondition interface {
	test(c *materialContext) bool
}

type blockRule struct{ material int }

func (r *blockRule) apply(c *materialContext) int { return r.material }

type sequenceRule struct{ rules []materialRule }

func (r *sequenceRule) apply(c *materialContext) int {
	for _, rule := range r.rules {
		if v := rule.apply(c); v != ruleNone {
			return v
		}
	}
	return ruleNone
}

type conditionRule struct {
	cond materialCondition
	then materialRule
}

func (r *conditionRule) apply(c *materialContext) int {
	if r.cond.test(c) {
		return r.then.apply(c)
	}
	return ruleNone
}

// clayBands are the badlands terracotta bands (surface/ClayBands.php).
type clayBands struct {
	bands [192]int
	slot  int // noise slot of clay_bands_offset
}

func newClayBands(r *Xoroshiro, palette *MaterialPalette, slot int) *clayBands {
	terracotta := palette.register("minecraft:terracotta")
	orange := palette.register("minecraft:orange_terracotta")
	yellow := palette.register("minecraft:yellow_terracotta")
	brown := palette.register("minecraft:brown_terracotta")
	red := palette.register("minecraft:red_terracotta")
	white := palette.register("minecraft:white_terracotta")
	lightGray := palette.register("minecraft:light_gray_terracotta")
	b := &clayBands{slot: slot}
	for i := range b.bands {
		b.bands[i] = terracotta
	}
	for i := 0; i < 192; i++ {
		i += r.NextBoundedInt(5) + 1
		if i < 192 {
			b.bands[i] = orange
		}
	}
	between := func(min, max int) int { return min + r.NextBoundedInt(max-min+1) }
	makeBands := func(baseWidth, material int) {
		count := between(6, 15)
		for i := 0; i < count; i++ {
			width := baseWidth + r.NextBoundedInt(3)
			start := r.NextBoundedInt(192)
			for p := 0; start+p < 192 && p < width; p++ {
				b.bands[start+p] = material
			}
		}
	}
	makeBands(1, yellow)
	makeBands(2, brown)
	makeBands(1, red)
	whiteBands := between(9, 15)
	for i, start := 0, 0; i < whiteBands && start < 192; i, start = i+1, start+r.NextBoundedInt(16)+4 {
		b.bands[start] = white
		if start-1 > 0 && r.NextLong()&1 != 0 {
			b.bands[start-1] = lightGray
		}
		if start+1 < 192 && r.NextLong()&1 != 0 {
			b.bands[start+1] = lightGray
		}
	}
	return b
}

type bandlandsRule struct{ bands *clayBands }

func (r *bandlandsRule) apply(c *materialContext) int {
	offset := phpInt(math.Floor(float64(c.noise2dAt(r.bands.slot)*4.0) + 0.5))
	return r.bands.bands[(c.blockY+offset+192)%192]
}

type oreVeinRule struct {
	density, richness, fillerGap Fn
	ore, rawOre, filler          int
	rawOreChance                 float64
	random                       *PositionalFactory
	cache                        *interpCache
	interpolated                 []*InterpolatedFn
	minBlockY, maxBlockY         int
	cells                        map[int]bool
	cellsStamp                   int
}

const (
	oreCellWidth  = 4
	oreCellHeight = 8
)

func (r *oreVeinRule) apply(c *materialContext) int {
	y := c.blockY
	if y < r.minBlockY || y > r.maxBlockY {
		return ruleNone
	}
	if !r.open(c) {
		return ruleNone
	}
	x, z := c.blockX, c.blockZ
	r.cache.inject(r.interpolated, x, y, z)
	fx, fy, fz := float64(x), float64(y), float64(z)
	density := r.density.Compute(fx, fy, fz)
	if density <= 0 {
		return ruleNone
	}
	random := r.random.At(x, y, z)
	if random.NextFloat() > density {
		return ruleNone
	}
	if random.NextFloat() < r.richness.Compute(fx, fy, fz) && r.fillerGap.Compute(fx, fy, fz) < 0 {
		if random.NextFloat() < r.rawOreChance {
			return r.rawOre
		}
		return r.ore
	}
	return r.filler
}

func (r *oreVeinRule) open(c *materialContext) bool {
	if r.cellsStamp != c.chunkStamp {
		r.cellsStamp = c.chunkStamp
		clear(r.cells)
	}
	cellY := (c.blockY - c.minY) >> 3
	key := ((((c.localX >> 2) << 2) | (c.localZ >> 2)) << 8) | cellY
	if v, ok := r.cells[key]; ok {
		return v
	}
	x0 := c.blockX &^ (oreCellWidth - 1)
	z0 := c.blockZ &^ (oreCellWidth - 1)
	y0 := c.minY + (cellY << 3)
	_, hi := densityBounds(r.density, x0, y0, z0, x0+oreCellWidth-1, y0+oreCellHeight-1, z0+oreCellWidth-1, r.cache)
	v := hi > 0
	r.cells[key] = v
	return v
}

// ---- conditions ----

type abovePreliminarySurface struct{}

func (abovePreliminarySurface) test(c *materialContext) bool { return c.blockY >= c.minSurfaceLevel() }

type biomeCondition struct{ matches []bool }

func (b *biomeCondition) test(c *materialContext) bool {
	v := c.biome()
	return v >= 0 && v < len(b.matches) && b.matches[v]
}

type holeCondition struct{}

func (holeCondition) test(c *materialContext) bool { return c.surfaceDepth <= 0 }

type noiseThreshold struct {
	slot     int
	min, max float64
	is3d     bool
}

func (n *noiseThreshold) test(c *materialContext) bool {
	var v float64
	if n.is3d {
		v = c.noise3dAt(n.slot)
	} else {
		v = c.noise2dAt(n.slot)
	}
	return n.min <= v && v <= n.max
}

type notCondition struct{ invert materialCondition }

func (n *notCondition) test(c *materialContext) bool { return !n.invert.test(c) }

type steepCondition struct{}

func (steepCondition) test(c *materialContext) bool { return c.steep() }

type stoneDepthCondition struct {
	offset          int
	addSurfaceDepth bool
	secondaryRange  int
	ceiling         bool
}

func (s *stoneDepthCondition) test(c *materialContext) bool {
	depth := c.stoneDepthAbove
	if s.ceiling {
		depth = c.stoneDepthBelow
	}
	limit := 1 + s.offset
	if s.addSurfaceDepth {
		limit += c.surfaceDepth
	}
	if s.secondaryRange != 0 {
		limit += phpInt(float64(((c.surfaceSecondary() + 1.0) / 2.0) * float64(s.secondaryRange)))
	}
	return depth <= limit
}

type verticalGradient struct {
	random                        *PositionalFactory
	trueAtAndBelow, falseAtAndAbv int
}

func (v *verticalGradient) test(c *materialContext) bool {
	y := c.blockY
	if y <= v.trueAtAndBelow {
		return true
	}
	if y >= v.falseAtAndAbv {
		return false
	}
	probability := 1.0 - float64(y-v.trueAtAndBelow)/float64(v.falseAtAndAbv-v.trueAtAndBelow)
	return v.random.At(c.blockX, y, c.blockZ).NextFloat() < probability
}

type waterCondition struct {
	offset, multiplier int
	addStoneDepth      bool
}

func (w *waterCondition) test(c *materialContext) bool {
	if c.waterHeight == noWater {
		return true
	}
	y := c.blockY
	if w.addStoneDepth {
		y += c.stoneDepthAbove
	}
	return y >= c.waterHeight+w.offset+c.surfaceDepth*w.multiplier
}

type yAboveCondition struct {
	anchor, multiplier int
	addStoneDepth      bool
}

func (a *yAboveCondition) test(c *materialContext) bool {
	y := c.blockY
	if a.addStoneDepth {
		y += c.stoneDepthAbove
	}
	return y >= a.anchor+c.surfaceDepth*a.multiplier
}

// ---- parser (surface/MaterialRuleParser.php) ----

type materialRuleParser struct {
	data       *Data
	density    *Parser
	random     *PositionalFactory
	palette    *MaterialPalette
	biomes     map[string]int
	biomeCount int
	interp     *interpCache
	minY       int
	height     int
	rules      map[string]materialRule
	conditions map[string]materialCondition
	resolving  map[string]bool
	clay       *clayBands
	noiseSlots map[*NoiseStack]int
	noises     []*NoiseStack
}

func (p *materialRuleParser) noiseSlot(n *NoiseStack) int {
	if s, ok := p.noiseSlots[n]; ok {
		return s
	}
	p.noiseSlots[n] = len(p.noises)
	p.noises = append(p.noises, n)
	return p.noiseSlots[n]
}

func (p *materialRuleParser) rule(js any) (materialRule, error) {
	if s, ok := js.(string); ok {
		key := "minecraft:" + stripNamespace(s)
		if r, ok := p.rules[key]; ok {
			return r, nil
		}
		if p.resolving[key] {
			return nil, fmt.Errorf("recursive material rule reference %s", key)
		}
		p.resolving[key] = true
		v, err := p.data.MaterialRule(key)
		if err != nil {
			return nil, err
		}
		r, err := p.rule(v)
		if err != nil {
			return nil, err
		}
		delete(p.resolving, key)
		p.rules[key] = r
		return r, nil
	}
	obj, ok := js.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("invalid material rule")
	}
	typ, err := jsonString(obj, "type", nil)
	if err != nil {
		return nil, err
	}
	switch stripNamespace(typ) {
	case "block":
		id, err := blockStateID(obj["result_state"])
		if err != nil {
			return nil, err
		}
		return &blockRule{p.palette.register(id)}, nil
	case "sequence":
		list, err := jsonArray(obj, "sequence")
		if err != nil {
			return nil, err
		}
		var rules []materialRule
		for _, child := range list {
			r, err := p.rule(child)
			if err != nil {
				return nil, err
			}
			rules = append(rules, r)
		}
		if len(rules) == 1 {
			return rules[0], nil
		}
		return &sequenceRule{rules}, nil
	case "condition":
		cond, err := p.condition(obj["if_true"])
		if err != nil {
			return nil, err
		}
		then, err := p.rule(obj["then_run"])
		if err != nil {
			return nil, err
		}
		return &conditionRule{cond, then}, nil
	case "bandlands":
		if p.clay == nil {
			n, err := p.density.GetNoise("minecraft:clay_bands_offset")
			if err != nil {
				return nil, err
			}
			p.clay = newClayBands(p.random.FromHashOf("minecraft:clay_bands"), p.palette, p.noiseSlot(n))
		}
		return &bandlandsRule{p.clay}, nil
	case "ore_vein":
		density, err := p.density.Parse(obj["density"])
		if err != nil {
			return nil, err
		}
		richness, err := p.density.Parse(obj["richness"])
		if err != nil {
			return nil, err
		}
		gap, err := p.density.Parse(obj["filler_gap"])
		if err != nil {
			return nil, err
		}
		ore, e1 := jsonString(obj, "ore_block", nil)
		raw, e2 := jsonString(obj, "raw_ore_block", nil)
		filler, e3 := jsonString(obj, "filler_block", nil)
		chance, e4 := jsonFloat(obj, "raw_ore_chance", nil)
		if err := firstErr(e1, e2, e3, e4); err != nil {
			return nil, err
		}
		r := &oreVeinRule{
			density: density, richness: richness, fillerGap: gap,
			ore: p.palette.register(ore), rawOre: p.palette.register(raw), filler: p.palette.register(filler),
			rawOreChance: chance,
			random:       p.random.FromHashOf("minecraft:ore").ForkPositional(),
			cache:        p.interp,
			interpolated: unionInterpolated(collectInterpolated(density), collectInterpolated(richness), collectInterpolated(gap)),
			minBlockY:    math.MaxInt64, maxBlockY: math.MinInt64,
			cells: map[int]bool{}, cellsStamp: -1,
		}
		for y := p.minY; y < p.minY+p.height; y += oreCellHeight {
			if _, hi := densityBounds(density, 0, y, 0, 15, y+oreCellHeight-1, 15, nil); hi > 0 {
				r.minBlockY = min(r.minBlockY, y)
				r.maxBlockY = max(r.maxBlockY, y+oreCellHeight-1)
			}
		}
		return r, nil
	}
	return nil, fmt.Errorf("unsupported material rule type %q", typ)
}

func (p *materialRuleParser) condition(js any) (materialCondition, error) {
	if s, ok := js.(string); ok {
		key := "minecraft:" + stripNamespace(s)
		if c, ok := p.conditions[key]; ok {
			return c, nil
		}
		if p.resolving["condition:"+key] {
			return nil, fmt.Errorf("recursive material condition reference %s", key)
		}
		p.resolving["condition:"+key] = true
		v, err := p.data.MaterialCondition(key)
		if err != nil {
			return nil, err
		}
		c, err := p.condition(v)
		if err != nil {
			return nil, err
		}
		delete(p.resolving, "condition:"+key)
		p.conditions[key] = c
		return c, nil
	}
	obj, ok := js.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("invalid material condition")
	}
	typ, err := jsonString(obj, "type", nil)
	if err != nil {
		return nil, err
	}
	switch stripNamespace(typ) {
	case "above_preliminary_surface":
		return abovePreliminarySurface{}, nil
	case "biome":
		matches := make([]bool, p.biomeCount)
		var list []any
		switch v := obj["biome_is"].(type) {
		case []any:
			list = v
		case nil:
		default:
			list = []any{v}
		}
		for _, b := range list {
			s, ok := b.(string)
			if !ok {
				return nil, fmt.Errorf("invalid biome in material condition")
			}
			if i, ok := p.biomes["minecraft:"+stripNamespace(s)]; ok {
				matches[i] = true
			}
		}
		return &biomeCondition{matches}, nil
	case "hole":
		return holeCondition{}, nil
	case "noise_threshold":
		name, err := jsonString(obj, "noise", nil)
		if err != nil {
			return nil, err
		}
		n, err := p.density.GetNoise(name)
		if err != nil {
			return nil, err
		}
		lo, e1 := jsonFloat(obj, "min_threshold", nil)
		hi, e2 := jsonFloat(obj, "max_threshold", nil)
		is3d, e3 := jsonBool(obj, "is_3d")
		if err := firstErr(e1, e2, e3); err != nil {
			return nil, err
		}
		return &noiseThreshold{p.noiseSlot(n), lo, hi, is3d}, nil
	case "not":
		c, err := p.condition(obj["invert"])
		if err != nil {
			return nil, err
		}
		return &notCondition{c}, nil
	case "steep":
		return steepCondition{}, nil
	case "stone_depth":
		offset, e1 := jsonInt(obj, "offset", iptr(0))
		add, e2 := jsonBool(obj, "add_surface_depth")
		rng, e3 := jsonInt(obj, "secondary_depth_range", iptr(0))
		st, e4 := jsonString(obj, "surface_type", nil)
		if err := firstErr(e1, e2, e3, e4); err != nil {
			return nil, err
		}
		return &stoneDepthCondition{offset, add, rng, st == "ceiling"}, nil
	case "vertical_gradient":
		name, err := jsonString(obj, "random_name", nil)
		if err != nil {
			return nil, err
		}
		t, err := p.anchor(obj["true_at_and_below"])
		if err != nil {
			return nil, err
		}
		f, err := p.anchor(obj["false_at_and_above"])
		if err != nil {
			return nil, err
		}
		return &verticalGradient{p.random.FromHashOf("minecraft:" + stripNamespace(name)).ForkPositional(), t, f}, nil
	case "water":
		offset, e1 := jsonInt(obj, "offset", iptr(0))
		mul, e2 := jsonInt(obj, "surface_depth_multiplier", iptr(0))
		add, e3 := jsonBool(obj, "add_stone_depth")
		if err := firstErr(e1, e2, e3); err != nil {
			return nil, err
		}
		return &waterCondition{offset, mul, add}, nil
	case "y_above":
		a, err := p.anchor(obj["anchor"])
		if err != nil {
			return nil, err
		}
		mul, e1 := jsonInt(obj, "surface_depth_multiplier", iptr(0))
		add, e2 := jsonBool(obj, "add_stone_depth")
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		return &yAboveCondition{a, mul, add}, nil
	}
	return nil, fmt.Errorf("unsupported material condition type %q", typ)
}

func (p *materialRuleParser) anchor(js any) (int, error) {
	return resolveAnchor(js, p.minY, p.height)
}

// resolveAnchor resolves a VerticalAnchor: {"absolute": y} | {"above_bottom": n} | {"below_top": n}.
func resolveAnchor(js any, minY, height int) (int, error) {
	obj, ok := js.(map[string]any)
	if !ok {
		return 0, fmt.Errorf("invalid vertical anchor")
	}
	if _, ok := obj["absolute"]; ok {
		return jsonInt(obj, "absolute", nil)
	}
	if _, ok := obj["above_bottom"]; ok {
		v, err := jsonInt(obj, "above_bottom", nil)
		return minY + v, err
	}
	if _, ok := obj["below_top"]; ok {
		v, err := jsonInt(obj, "below_top", nil)
		return minY + height - 1 - v, err
	}
	return 0, fmt.Errorf("invalid vertical anchor")
}

func blockStateID(js any) (string, error) {
	switch v := js.(type) {
	case string:
		return v, nil
	case map[string]any:
		return jsonString(v, "Name", nil)
	}
	return "", fmt.Errorf("invalid block state")
}

func jsonBool(obj map[string]any, key string) (bool, error) {
	v, ok := obj[key]
	if !ok || v == nil {
		return false, nil
	}
	b, ok := v.(bool)
	if !ok {
		return false, fmt.Errorf("expected boolean for %q", key)
	}
	return b, nil
}
