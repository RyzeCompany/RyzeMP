package decor

import "fmt"

// type/SimpleBlockFeature.php
type simpleBlockFeature struct {
	provider     StateProvider
	doublePlants []bool
	mossyCarpet  int
}

func newSimpleBlock(reg *Registry, json obj) (Feature, error) {
	p, err := reg.ParseStateProvider(json["to_place"])
	if err != nil {
		return nil, err
	}
	b := reg.B
	f := &simpleBlockFeature{provider: p, doublePlants: make([]bool, len(b.Names))}
	for _, name := range []string{"tall_grass", "large_fern", "sunflower", "lilac", "rose_bush", "peony", "pitcher_plant", "small_dripleaf", "tall_seagrass"} {
		i, err := b.BlockIndex(name)
		if err != nil {
			return nil, err
		}
		f.doublePlants[i] = true
	}
	if f.mossyCarpet, err = b.BlockIndex("pale_moss_carpet"); err != nil {
		return nil, err
	}
	return f, nil
}

func (f *simpleBlockFeature) Place(c *Ctx, x, y, z int) bool {
	state := f.provider(c, c.R, x, y, z)
	if state < 0 {
		return false
	}
	level := c.Level
	b := c.B
	if !c.Surv.CanSurvive(level, state, x, y, z) {
		return false
	}
	block := int(b.BlockOf[state])
	if f.doublePlants[block] {
		above := level.GetState(x, y+1, z)
		if !b.IsAir(above) && !(b.Fluid(state) == b.Fluid(above) && b.IsTagged(above, "replaceable")) {
			return false
		}
		placeDoublePlant(c, state, x, y, z)
	} else if block == f.mossyCarpet {
		//MossyCarpetBlock.placeAt(): the topper uses the region random, which is not reproduced
		level.SetState(x, y, z, b.WithBool(state, "bottom", true))
	} else {
		level.SetState(x, y, z, state)
	}
	return true
}

// placeDoublePlant is DoublePlantBlock.placeAt().
func placeDoublePlant(c *Ctx, state, x, y, z int) {
	b := c.B
	level := c.Level
	lower := b.With(state, "half", "lower")
	if b.HasProperty(lower, "waterlogged") {
		lower = b.WithBool(lower, "waterlogged", level.IsWaterAt(x, y, z))
	}
	level.SetState(x, y, z, lower)
	upper := b.With(state, "half", "upper")
	if b.HasProperty(upper, "waterlogged") {
		upper = b.WithBool(upper, "waterlogged", level.IsWaterAt(x, y+1, z))
	}
	level.SetState(x, y+1, z, upper)
}

// type/BlockColumnFeature.php
type blockColumnLayer struct {
	height   *IntProvider
	provider StateProvider
}

type blockColumnFeature struct {
	layers           []blockColumnLayer
	direction        int
	allowedPlacement BlockPredicate
	prioritizeTip    bool
}

func newBlockColumn(reg *Registry, json obj) (Feature, error) {
	list, err := jArray(json, "layers")
	if err != nil {
		return nil, err
	}
	f := &blockColumnFeature{}
	for _, l := range list {
		lo, ok := asObj(l)
		if !ok {
			return nil, fmt.Errorf("invalid block column layer")
		}
		h, err := parseIntProvider(lo["height"])
		if err != nil {
			return nil, err
		}
		p, err := reg.ParseStateProvider(lo["provider"])
		if err != nil {
			return nil, err
		}
		f.layers = append(f.layers, blockColumnLayer{h, p})
	}
	name, err := jString(json, "direction")
	if err != nil {
		return nil, err
	}
	if f.direction, err = directionFromName(name); err != nil {
		return nil, err
	}
	if f.allowedPlacement, err = parseBlockPredicate(reg, json["allowed_placement"]); err != nil {
		return nil, err
	}
	f.prioritizeTip = jBoolDef(json, "prioritize_tip", false)
	return f, nil
}

func (f *blockColumnFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	heights := make([]int, len(f.layers))
	total := 0
	for i, l := range f.layers {
		h := l.height.Sample(r)
		heights[i] = h
		total += h
	}
	if total == 0 {
		return false
	}
	dx, dy, dz := StepX[f.direction], StepY[f.direction], StepZ[f.direction]
	nx, ny, nz := x+dx, y+dy, z+dz
	for i := 0; i < total; i++ {
		if !f.allowedPlacement(c, nx, ny, nz) {
			f.truncate(heights, total, i)
			break
		}
		nx += dx
		ny += dy
		nz += dz
	}
	px, py, pz := x, y, z
	for i, l := range f.layers {
		for n := 0; n < heights[i]; n++ {
			state := l.provider(c, r, px, py, pz)
			if state < 0 {
				state = c.Level.GetState(px, py, pz)
			}
			c.Level.SetState(px, py, pz, state)
			px += dx
			py += dy
			pz += dz
		}
	}
	return true
}

func (f *blockColumnFeature) truncate(heights []int, total, newHeight int) {
	toRemove := total - newHeight
	step, start, end := -1, len(heights)-1, -1
	if f.prioritizeTip {
		step, start, end = 1, 0, len(heights)
	}
	for i := start; i != end && toRemove > 0; i += step {
		remove := minInt(heights[i], toRemove)
		toRemove -= remove
		heights[i] -= remove
	}
}

// type/DiskFeature.php
type diskFeature struct {
	provider   StateProvider
	target     BlockPredicate
	radius     *IntProvider
	halfHeight int
}

func newDisk(reg *Registry, json obj) (Feature, error) {
	p, err := reg.ParseStateProvider(json["state_provider"])
	if err != nil {
		return nil, err
	}
	t, err := parseBlockPredicate(reg, json["target"])
	if err != nil {
		return nil, err
	}
	r, err := parseIntProvider(json["radius"])
	if err != nil {
		return nil, err
	}
	hh, err := jInt(json, "half_height")
	if err != nil {
		return nil, err
	}
	return &diskFeature{p, t, r, hh}, nil
}

func (f *diskFeature) Place(c *Ctx, x, y, z int) bool {
	placed := false
	top := y + f.halfHeight
	bottom := y - f.halfHeight - 1
	r := f.radius.Sample(c.R)
	//BlockPos.betweenClosed(): x varies fastest
	for pz := z - r; pz <= z+r; pz++ {
		for px := x - r; px <= x+r; px++ {
			xd := px - x
			zd := pz - z
			if xd*xd+zd*zd > r*r {
				continue
			}
			for py := top; py > bottom; py-- {
				if f.target(c, px, py, pz) {
					if state := f.provider(c, c.R, px, py, pz); state >= 0 {
						c.Level.SetState(px, py, pz, state)
						placed = true
					}
				}
			}
		}
	}
	return placed
}

// spring_feature (DiskFeature::spring())
type springFeature struct {
	state                int
	valid                []bool
	requiresBelow        bool
	rockCount, holeCount int
}

func newSpring(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	name := "minecraft:water"
	switch v := json["state"].(type) {
	case map[string]any:
		if id, ok := v["id"].(string); ok {
			name = id
		}
	case string:
		name = v
	}
	//FluidState.createLegacyBlock() of a source fluid: level 0
	fluid := "water"
	if n := stripNamespace(name); n == "lava" || n == "flowing_lava" {
		fluid = "lava"
	}
	state, err := b.DefaultOf(fluid)
	if err != nil {
		return nil, err
	}
	set, err := b.BlockSet(json["valid_blocks"])
	if err != nil {
		return nil, err
	}
	rocks, err := jIntDef(json, "rock_count", 4)
	if err != nil {
		return nil, err
	}
	holes, err := jIntDef(json, "hole_count", 1)
	if err != nil {
		return nil, err
	}
	return &springFeature{state, blockMask(b, set), jBoolDef(json, "requires_block_below", true), rocks, holes}, nil
}

func (f *springFeature) isValid(c *Ctx, x, y, z int) bool {
	return f.valid[c.B.BlockOf[c.Level.GetState(x, y, z)]]
}

var springNeighbours = [5][3]int{{-1, 0, 0}, {1, 0, 0}, {0, 0, -1}, {0, 0, 1}, {0, -1, 0}}

func (f *springFeature) Place(c *Ctx, x, y, z int) bool {
	if !f.isValid(c, x, y+1, z) {
		return false
	}
	if f.requiresBelow && !f.isValid(c, x, y-1, z) {
		return false
	}
	current := c.Level.GetState(x, y, z)
	if !c.B.IsAir(current) && !f.valid[c.B.BlockOf[current]] {
		return false
	}
	rocks, holes := 0, 0
	for _, d := range springNeighbours {
		if f.isValid(c, x+d[0], y+d[1], z+d[2]) {
			rocks++
		}
	}
	for _, d := range springNeighbours {
		if c.Level.IsEmptyBlock(x+d[0], y+d[1], z+d[2]) {
			holes++
		}
	}
	if rocks == f.rockCount && holes == f.holeCount {
		c.Level.SetState(x, y, z, f.state)
		return true
	}
	return false
}
