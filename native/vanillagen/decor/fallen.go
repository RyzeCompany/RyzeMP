package decor

// type/FallenTreeFeature.php
type fallenTreeFeature struct {
	trunkProvider                  StateProvider
	logLength                      *IntProvider
	stumpDecorators, logDecorators []*treeDecorator
	replaceableByTrees             []bool
}

func newFallenTree(reg *Registry, json obj) (Feature, error) {
	f := &fallenTreeFeature{}
	var err error
	if f.trunkProvider, err = reg.ParseStateProvider(json["trunk_provider"]); err != nil {
		return nil, err
	}
	if f.logLength, err = parseIntProvider(json["log_length"]); err != nil {
		return nil, err
	}
	for _, k := range []struct {
		key string
		dst *[]*treeDecorator
	}{{"stump_decorators", &f.stumpDecorators}, {"log_decorators", &f.logDecorators}} {
		list, _ := json[k.key].([]any)
		for _, d := range list {
			dec, err := newTreeDecorator(reg, d)
			if err != nil {
				return nil, err
			}
			*k.dst = append(*k.dst, dec)
		}
	}
	f.replaceableByTrees = reg.B.TagMask("minecraft:replaceable_by_trees")
	return f, nil
}

func (f *fallenTreeFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	//stump
	f.placeLog(c, x, y, z, "")
	stump := newPosSet()
	stump.Add(x, y, z)
	f.decorate(c, stump, f.stumpDecorators)

	direction := PlaneHorizontal[r.NextBoundedInt(4)]
	logLength := f.logLength.Sample(r) - 2
	distance := 2 + r.NextBoundedInt(2)
	lx := x + StepX[direction]*distance
	ly := y
	lz := z + StepZ[direction]*distance
	//setGroundHeightForFallenLogStartPos()
	ly++
	for i := 0; i < 6; i++ {
		if f.mayPlaceOn(c, lx, ly, lz) {
			break
		}
		ly--
	}
	if !f.canPlaceEntireFallenLog(c, logLength, lx, ly, lz, direction) {
		return true
	}
	axis := axisOf(direction)
	logs := newPosSet()
	for i := 0; i < logLength; i++ {
		f.placeLog(c, lx, ly, lz, axis)
		logs.Add(lx, ly, lz)
		lx += StepX[direction]
		lz += StepZ[direction]
	}
	f.decorate(c, logs, f.logDecorators)
	return true
}

func (f *fallenTreeFeature) placeLog(c *Ctx, x, y, z int, axis string) {
	state := f.trunkProvider(c, c.R, x, y, z)
	if state < 0 {
		state = c.Level.GetState(x, y, z)
	}
	if axis != "" {
		state = c.B.With(state, "axis", axis)
	}
	c.Level.SetState(x, y, z, state)
}

func (f *fallenTreeFeature) decorate(c *Ctx, logs *posSet, decorators []*treeDecorator) {
	if len(decorators) == 0 {
		return
	}
	ctx := &treeDecoratorContext{c: c, b: c.B, logs: sortedByY(logs)}
	for _, d := range decorators {
		d.place(ctx)
	}
}

func (f *fallenTreeFeature) validTreePos(c *Ctx, x, y, z int) bool {
	state := c.Level.GetState(x, y, z)
	return c.B.IsAir(state) || f.replaceableByTrees[c.B.BlockOf[state]]
}

func (f *fallenTreeFeature) isOverSolidGround(c *Ctx, x, y, z int) bool {
	return c.B.IsFaceSturdy(c.Level.GetState(x, y-1, z), Up)
}

func (f *fallenTreeFeature) mayPlaceOn(c *Ctx, x, y, z int) bool {
	return f.validTreePos(c, x, y, z) && f.isOverSolidGround(c, x, y, z)
}

func (f *fallenTreeFeature) canPlaceEntireFallenLog(c *Ctx, logLength, x, y, z, direction int) bool {
	gap := 0
	for i := 0; i < logLength; i++ {
		if !f.validTreePos(c, x, y, z) {
			return false
		}
		if !f.isOverSolidGround(c, x, y, z) {
			gap++
			if gap > 2 {
				return false
			}
		} else {
			gap = 0
		}
		x += StepX[direction]
		z += StepZ[direction]
	}
	return true
}

// type/HugeMushroomFeature.php
type hugeMushroomFeature struct {
	capProvider, stemProvider StateProvider
	foliageRadius             int
	canPlaceOn                BlockPredicate
	replaceable, leaves       []bool
	red                       bool
}

func newHugeMushroom(red bool) func(reg *Registry, json obj) (Feature, error) {
	return func(reg *Registry, json obj) (Feature, error) {
		f := &hugeMushroomFeature{red: red}
		var err error
		if f.capProvider, err = reg.ParseStateProvider(json["cap_provider"]); err != nil {
			return nil, err
		}
		if f.stemProvider, err = reg.ParseStateProvider(json["stem_provider"]); err != nil {
			return nil, err
		}
		if f.foliageRadius, err = jIntDef(json, "foliage_radius", 2); err != nil {
			return nil, err
		}
		if f.canPlaceOn, err = parseBlockPredicate(reg, json["can_place_on"]); err != nil {
			return nil, err
		}
		f.replaceable = reg.B.TagMask("minecraft:replaceable_by_mushrooms")
		f.leaves = reg.B.TagMask("minecraft:leaves")
		return f, nil
	}
}

func (f *hugeMushroomFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	treeHeight := r.NextBoundedInt(3) + 4
	if r.NextBoundedInt(12) == 0 {
		treeHeight *= 2
	}
	if !f.isValidPosition(c, x, y, z, treeHeight) {
		return false
	}
	f.makeCap(c, x, y, z, treeHeight)
	for dy := 0; dy < treeHeight; dy++ {
		state := f.stemProvider(c, r, x, y, z)
		if state < 0 {
			state = c.B.Air
		}
		f.placeMushroomBlock(c, x, y+dy, z, state)
	}
	return true
}

func (f *hugeMushroomFeature) radiusForHeight(dy int) int {
	if f.red {
		//called with treeHeight = -1 by vanilla: only the stem column is checked
		return 0
	}
	if dy <= 3 {
		return 0
	}
	return f.foliageRadius
}

func (f *hugeMushroomFeature) isValidPosition(c *Ctx, x, y, z, treeHeight int) bool {
	level := c.Level
	if y < level.MinY+1 || y+treeHeight+1 > level.MaxY-1 {
		return false
	}
	if !f.canPlaceOn(c, x, y-1, z) {
		return false
	}
	b := c.B
	for dy := 0; dy <= treeHeight; dy++ {
		r := f.radiusForHeight(dy)
		for dx := -r; dx <= r; dx++ {
			for dz := -r; dz <= r; dz++ {
				state := level.GetState(x+dx, y+dy, z+dz)
				if !b.IsAir(state) && !f.leaves[b.BlockOf[state]] {
					return false
				}
			}
		}
	}
	return true
}

func (f *hugeMushroomFeature) placeMushroomBlock(c *Ctx, x, y, z, state int) {
	current := c.Level.GetState(x, y, z)
	if c.B.IsAir(current) || f.replaceable[c.B.BlockOf[current]] {
		c.Level.SetState(x, y, z, state)
	}
}

func hasSides(b *Blocks, state int, up bool) bool {
	ok := b.HasProperty(state, "west") && b.HasProperty(state, "east") && b.HasProperty(state, "north") && b.HasProperty(state, "south")
	if up {
		ok = ok && b.HasProperty(state, "up")
	}
	return ok
}

func (f *hugeMushroomFeature) makeCap(c *Ctx, x, y, z, treeHeight int) {
	b := c.B
	r := c.R
	if !f.red {
		rr := f.foliageRadius
		for dx := -rr; dx <= rr; dx++ {
			for dz := -rr; dz <= rr; dz++ {
				minX := dx == -rr
				maxX := dx == rr
				minZ := dz == -rr
				maxZ := dz == rr
				xEdge := minX || maxX
				zEdge := minZ || maxZ
				if xEdge && zEdge {
					continue
				}
				west := minX || (zEdge && dx == 1-rr)
				east := maxX || (zEdge && dx == rr-1)
				north := minZ || (xEdge && dz == 1-rr)
				south := maxZ || (xEdge && dz == rr-1)
				state := f.capProvider(c, r, x, y, z)
				if state < 0 {
					state = b.Air
				}
				if hasSides(b, state, false) {
					state = b.WithBool(state, "west", west)
					state = b.WithBool(state, "east", east)
					state = b.WithBool(state, "north", north)
					state = b.WithBool(state, "south", south)
				}
				f.placeMushroomBlock(c, x+dx, y+treeHeight, z+dz, state)
			}
		}
		return
	}
	for dy := treeHeight - 3; dy <= treeHeight; dy++ {
		radius := f.foliageRadius - 1
		if dy < treeHeight {
			radius = f.foliageRadius
		}
		center := f.foliageRadius - 2
		for dx := -radius; dx <= radius; dx++ {
			for dz := -radius; dz <= radius; dz++ {
				xEdge := dx == -radius || dx == radius
				zEdge := dz == -radius || dz == radius
				if dy < treeHeight && xEdge == zEdge {
					continue
				}
				state := f.capProvider(c, r, x, y, z)
				if state < 0 {
					state = b.Air
				}
				if hasSides(b, state, true) {
					state = b.WithBool(state, "up", dy >= treeHeight-1)
					state = b.WithBool(state, "west", dx < -center)
					state = b.WithBool(state, "east", dx > center)
					state = b.WithBool(state, "north", dz < -center)
					state = b.WithBool(state, "south", dz > center)
				}
				f.placeMushroomBlock(c, x+dx, y+dy, z+dz, state)
			}
		}
	}
}
