package decor

// type/RootSystemFeature.php
type rootSystemFeature struct {
	treeFeature                                                        *PlacedFeature
	requiredVerticalSpaceForTree, levelTestDistance, maxLevelDeviation int
	rootRadius                                                         int
	rootReplaceable                                                    []bool
	rootStateProvider                                                  StateProvider
	rootPlacementAttempts, rootColumnMaxHeight                         int
	hangingRootRadius, hangingRootsVerticalSpan                        int
	hangingRootStateProvider                                           StateProvider
	hangingRootPlacementAttempts, allowedVerticalWaterForTree          int
	allowedTreePosition                                                BlockPredicate
}

func newRootSystem(reg *Registry, json obj) (Feature, error) {
	f := &rootSystemFeature{}
	var err error
	if f.treeFeature, err = reg.PlacedRef(json["feature"]); err != nil {
		return nil, err
	}
	set, err := reg.B.BlockSet(json["root_replaceable"])
	if err != nil {
		return nil, err
	}
	f.rootReplaceable = blockMask(reg.B, set)
	if f.rootStateProvider, err = reg.ParseStateProvider(json["root_state_provider"]); err != nil {
		return nil, err
	}
	if f.hangingRootStateProvider, err = reg.ParseStateProvider(json["hanging_root_state_provider"]); err != nil {
		return nil, err
	}
	if f.allowedTreePosition, err = parseBlockPredicate(reg, json["allowed_tree_position"]); err != nil {
		return nil, err
	}
	var errs [10]error
	f.requiredVerticalSpaceForTree, errs[0] = jInt(json, "required_vertical_space_for_tree")
	f.levelTestDistance, errs[1] = jInt(json, "level_test_distance")
	f.maxLevelDeviation, errs[2] = jInt(json, "max_level_deviation")
	f.rootRadius, errs[3] = jInt(json, "root_radius")
	f.rootPlacementAttempts, errs[4] = jInt(json, "root_placement_attempts")
	f.rootColumnMaxHeight, errs[5] = jInt(json, "root_column_max_height")
	f.hangingRootRadius, errs[6] = jInt(json, "hanging_root_radius")
	f.hangingRootsVerticalSpan, errs[7] = jInt(json, "hanging_roots_vertical_span")
	f.hangingRootPlacementAttempts, errs[8] = jInt(json, "hanging_root_placement_attempts")
	f.allowedVerticalWaterForTree, errs[9] = jInt(json, "allowed_vertical_water_for_tree")
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	return f, nil
}

func (f *rootSystemFeature) Place(c *Ctx, x, y, z int) bool {
	if !c.B.IsAir(c.Level.GetState(x, y, z)) {
		return false
	}
	if f.placeDirtAndTree(c, x, y, z) {
		f.placeRoots(c, x, y, z)
	}
	return true
}

func (f *rootSystemFeature) spaceForTree(c *Ctx, x, y, z int) bool {
	b := c.B
	level := c.Level
	for i := 1; i <= f.requiredVerticalSpaceForTree; i++ {
		state := level.GetState(x, y+i, z)
		if !b.IsAir(state) && !(i+1 <= f.allowedVerticalWaterForTree && b.FluidInTag(state, "water")) {
			return false
		}
	}
	if f.levelTestDistance > 0 {
		//Direction.from2DDataValue()
		for _, d := range Horizontal {
			cx := x + StepX[d]*f.levelTestDistance
			cz := z + StepZ[d]*f.levelTestDistance
			if b.IsAir(level.GetState(cx, y-f.maxLevelDeviation, cz)) || !b.IsAir(level.GetState(cx, y+f.maxLevelDeviation, cz)) {
				return false
			}
		}
	}
	return true
}

func (f *rootSystemFeature) placeDirtAndTree(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	wy := y
	for i := 0; i < f.rootColumnMaxHeight; i++ {
		wy++
		if level.GetHeight(WorldSurface, x, z) < wy {
			return false
		}
		if !f.allowedTreePosition(c, x, wy, z) || !f.spaceForTree(c, x, wy, z) {
			continue
		}
		below := level.GetState(x, wy-1, z)
		if b.Fluid(below)&15 == FluidLava || !b.IsSolid(below) {
			return false
		}
		if f.treeFeature.Place(c, x, wy, z) {
			for dy := y; dy < y+i; dy++ {
				f.placeRootedDirt(c, x, dy, z)
			}
			return true
		}
	}
	return false
}

func (f *rootSystemFeature) placeRootedDirt(c *Ctx, x, y, z int) {
	r := c.R
	level := c.Level
	for i := 0; i < f.rootPlacementAttempts; i++ {
		a := r.NextBoundedInt(f.rootRadius)
		px := x + a - r.NextBoundedInt(f.rootRadius)
		a = r.NextBoundedInt(f.rootRadius)
		pz := z + a - r.NextBoundedInt(f.rootRadius)
		if f.rootReplaceable[c.B.BlockOf[level.GetState(px, y, pz)]] {
			if state := f.rootStateProvider(c, r, px, y, pz); state >= 0 {
				level.SetState(px, y, pz, state)
			}
		}
	}
}

func (f *rootSystemFeature) placeRoots(c *Ctx, x, y, z int) {
	r := c.R
	level := c.Level
	for i := 0; i < f.hangingRootPlacementAttempts; i++ {
		a := r.NextBoundedInt(f.hangingRootRadius)
		px := x + a - r.NextBoundedInt(f.hangingRootRadius)
		a = r.NextBoundedInt(f.hangingRootsVerticalSpan)
		py := y + a - r.NextBoundedInt(f.hangingRootsVerticalSpan)
		a = r.NextBoundedInt(f.hangingRootRadius)
		pz := z + a - r.NextBoundedInt(f.hangingRootRadius)
		if !level.IsEmptyBlock(px, py, pz) {
			continue
		}
		state := f.hangingRootStateProvider(c, r, px, py, pz)
		if state < 0 || !c.Surv.CanSurvive(level, state, px, py, pz) {
			continue
		}
		if c.B.IsFaceSturdy(level.GetState(px, py+1, pz), Down) {
			level.SetState(px, py, pz, state)
		}
	}
}
