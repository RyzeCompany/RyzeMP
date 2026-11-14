package decor

// vegetationPatchFeature is type/VegetationPatchFeature.php (vegetation_patch and waterlogged_vegetation_patch).
type vegetationPatchFeature struct {
	replaceable            []bool
	groundState            StateProvider
	vegetation             *PlacedFeature
	inwards                int
	depth                  *IntProvider
	extraBottomBlockChance float64
	verticalRange          int
	vegetationChance       float64
	xzRadius               *IntProvider
	extraEdgeColumnChance  float64
	waterlogged            bool
}

func newVegetationPatch(waterlogged bool) func(reg *Registry, json obj) (Feature, error) {
	return func(reg *Registry, json obj) (Feature, error) {
		b := reg.B
		f := &vegetationPatchFeature{waterlogged: waterlogged}
		set, err := b.BlockSet(json["replaceable"])
		if err != nil {
			return nil, err
		}
		f.replaceable = blockMask(b, set)
		if f.groundState, err = reg.ParseStateProvider(json["ground_state"]); err != nil {
			return nil, err
		}
		if f.vegetation, err = reg.PlacedRef(json["vegetation_feature"]); err != nil {
			return nil, err
		}
		surface, err := jString(json, "surface")
		if err != nil {
			return nil, err
		}
		f.inwards = Down
		if surface == "ceiling" {
			f.inwards = Up
		}
		if f.depth, err = parseIntProvider(json["depth"]); err != nil {
			return nil, err
		}
		var errs [5]error
		var ebc, vc, eec float64
		ebc, errs[0] = jFloat(json, "extra_bottom_block_chance")
		f.verticalRange, errs[1] = jInt(json, "vertical_range")
		vc, errs[2] = jFloat(json, "vegetation_chance")
		f.xzRadius, errs[3] = parseIntProvider(json["xz_radius"])
		eec, errs[4] = jFloat(json, "extra_edge_column_chance")
		if err := firstErr(errs[:]...); err != nil {
			return nil, err
		}
		f.extraBottomBlockChance, f.vegetationChance, f.extraEdgeColumnChance = fround(ebc), fround(vc), fround(eec)
		return f, nil
	}
}

func (f *vegetationPatchFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	xRadius := f.xzRadius.Sample(r) + 1
	zRadius := f.xzRadius.Sample(r) + 1
	surface := f.placeGroundPatch(c, x, y, z, xRadius, zRadius)
	if f.waterlogged {
		water := newPosSet()
		for _, p := range surface.IterationOrder() {
			if !f.isExposed(c, p[0], p[1], p[2]) {
				water.Add(p[0], p[1], p[2])
			}
		}
		for _, p := range water.IterationOrder() {
			c.Level.SetState(p[0], p[1], p[2], c.B.Water)
		}
		surface = water
	}
	f.distributeVegetation(c, surface)
	return surface.Len() > 0
}

func (f *vegetationPatchFeature) placeGroundPatch(c *Ctx, ox, oy, oz, xRadius, zRadius int) *posSet {
	r := c.R
	level := c.Level
	b := c.B
	iy := StepY[f.inwards]
	outwards := Opposite[f.inwards]
	surface := newPosSet()
	for dx := -xRadius; dx <= xRadius; dx++ {
		isXEdge := dx == -xRadius || dx == xRadius
		for dz := -zRadius; dz <= zRadius; dz++ {
			isZEdge := dz == -zRadius || dz == zRadius
			isEdge := isXEdge || isZEdge
			if isXEdge && isZEdge {
				continue
			}
			if isEdge && (f.extraEdgeColumnChance == 0.0 || r.NextFloat() > f.extraEdgeColumnChance) {
				continue
			}
			px := ox + dx
			py := oy
			pz := oz + dz
			for offset := 0; b.IsAir(level.GetState(px, py, pz)) && offset < f.verticalRange; offset++ {
				py += iy
			}
			for offset := 0; !b.IsAir(level.GetState(px, py, pz)) && offset < f.verticalRange; offset++ {
				py -= iy
			}
			by := py + iy
			below := level.GetState(px, by, pz)
			if !b.IsAir(level.GetState(px, py, pz)) || !b.IsFaceSturdy(below, outwards) {
				continue
			}
			depth := f.depth.Sample(r)
			if f.extraBottomBlockChance > 0.0 && r.NextFloat() < f.extraBottomBlockChance {
				depth++
			}
			if f.placeGround(c, px, by, pz, depth) {
				surface.Add(px, by, pz)
			}
		}
	}
	return surface
}

func (f *vegetationPatchFeature) placeGround(c *Ctx, x, y, z, depth int) bool {
	level := c.Level
	b := c.B
	iy := StepY[f.inwards]
	for i := 0; i < depth; i++ {
		state := f.groundState(c, c.R, x, y, z)
		if state < 0 {
			state = level.GetState(x, y, z)
		}
		current := level.GetState(x, y, z)
		if b.BlockOf[state] == b.BlockOf[current] {
			continue
		}
		if !f.replaceable[b.BlockOf[current]] {
			return i != 0
		}
		level.SetState(x, y, z, state)
		y += iy
	}
	return true
}

func (f *vegetationPatchFeature) distributeVegetation(c *Ctx, surface *posSet) {
	for _, p := range surface.IterationOrder() {
		if f.vegetationChance > 0.0 && c.R.NextFloat() < f.vegetationChance {
			f.placeVegetation(c, p[0], p[1], p[2])
		}
	}
}

func (f *vegetationPatchFeature) placeVegetation(c *Ctx, x, y, z int) {
	oy := -StepY[f.inwards]
	if !f.waterlogged {
		f.vegetation.Place(c, x, y+oy, z)
		return
	}
	//WaterloggedVegetationPatchFeature: the vegetation goes one block lower, and gets waterlogged
	if f.vegetation.Place(c, x, y-1+oy, z) {
		b := c.B
		placed := c.Level.GetState(x, y, z)
		if b.HasProperty(placed, "waterlogged") && !b.GetBool(placed, "waterlogged") {
			c.Level.SetState(x, y, z, b.WithBool(placed, "waterlogged", true))
		}
	}
}

func (f *vegetationPatchFeature) isExposed(c *Ctx, x, y, z int) bool {
	for _, d := range [5]int{North, East, South, West, Down} {
		state := c.Level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])
		if !c.B.IsFaceSturdy(state, Opposite[d]) {
			return true
		}
	}
	return false
}

// multifaceGrowthFeature is type/MultifaceGrowthFeature.php.
type multifaceGrowthFeature struct {
	block, blockState         int
	searchRange               int
	floor, ceiling, wall      bool
	chanceOfSpreading         float64
	canBePlacedOn             []bool
	sculkVein                 bool
	sculk, catalyst, mvPiston int
}

func newMultifaceGrowth(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	name, err := jString(json, "block")
	if err != nil {
		return nil, err
	}
	f := &multifaceGrowthFeature{}
	if f.block, err = b.BlockIndex(name); err != nil {
		return nil, err
	}
	f.blockState = b.DefaultState[f.block]
	if f.searchRange, err = jIntDef(json, "search_range", 10); err != nil {
		return nil, err
	}
	f.floor = jBoolDef(json, "can_place_on_floor", false)
	f.ceiling = jBoolDef(json, "can_place_on_ceiling", false)
	f.wall = jBoolDef(json, "can_place_on_wall", false)
	chance, err := jFloatDef(json, "chance_of_spreading", 0.5)
	if err != nil {
		return nil, err
	}
	f.chanceOfSpreading = fround(chance)
	set, err := b.BlockSet(json["can_be_placed_on"])
	if err != nil {
		return nil, err
	}
	f.canBePlacedOn = blockMask(b, set)
	f.sculkVein = namespaced(name) == "minecraft:sculk_vein"
	f.sculk = b.MustBlock("sculk")
	f.catalyst = b.MustBlock("sculk_catalyst")
	f.mvPiston = b.MustBlock("moving_piston")
	return f, nil
}

func (f *multifaceGrowthFeature) validDirections() []int {
	var list []int
	if f.ceiling {
		list = append(list, Up)
	}
	if f.floor {
		list = append(list, Down)
	}
	if f.wall {
		list = append(list, PlaneHorizontal[:]...)
	}
	return list
}

// utilShuffle is Util.shuffle() of a list (in place).
func utilShuffle(list []int, r *Random) []int {
	for i := len(list); i > 1; i-- {
		swapTo := r.NextBoundedInt(i)
		list[i-1], list[swapTo] = list[swapTo], list[i-1]
	}
	return list
}

func (f *multifaceGrowthFeature) isAirOrWater(c *Ctx, state int) bool {
	return c.B.IsAir(state) || int(c.B.BlockOf[state]) == c.B.WaterBlock
}

func (f *multifaceGrowthFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	if !f.isAirOrWater(c, level.GetState(x, y, z)) {
		return false
	}
	searchDirections := utilShuffle(f.validDirections(), r)
	if f.placeGrowthIfPossible(c, x, y, z, level.GetState(x, y, z), searchDirections) {
		return true
	}
	for _, search := range searchDirections {
		var except []int
		for _, d := range f.validDirections() {
			if d != Opposite[search] {
				except = append(except, d)
			}
		}
		placementDirections := utilShuffle(except, r)
		for i := 0; i < f.searchRange; i++ {
			//pos.setWithOffset(origin, searchDirection): always one block from the origin
			px := x + StepX[search]
			py := y + StepY[search]
			pz := z + StepZ[search]
			state := level.GetState(px, py, pz)
			if !f.isAirOrWater(c, state) && int(c.B.BlockOf[state]) != f.block {
				break
			}
			if f.placeGrowthIfPossible(c, px, py, pz, state, placementDirections) {
				return true
			}
		}
	}
	return false
}

func (f *multifaceGrowthFeature) placeGrowthIfPossible(c *Ctx, x, y, z, oldState int, directions []int) bool {
	level := c.Level
	for _, d := range directions {
		neighbour := level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])
		if !f.canBePlacedOn[c.B.BlockOf[neighbour]] {
			continue
		}
		newState := f.getStateForPlacement(c, oldState, x, y, z, d)
		if newState < 0 {
			return false
		}
		level.SetState(x, y, z, newState)
		if c.R.NextFloat() < f.chanceOfSpreading {
			f.spreadFromFaceTowardRandomDirection(c, newState, x, y, z, d)
		}
		return true
	}
	return false
}

func (f *multifaceGrowthFeature) hasFace(c *Ctx, state, d int) bool {
	v, _ := c.B.GetProperty(state, DirNames[d])
	return v == "true"
}

func (f *multifaceGrowthFeature) isValidStateForPlacement(c *Ctx, oldState, x, y, z, d int) bool {
	if int(c.B.BlockOf[oldState]) == f.block && f.hasFace(c, oldState, d) {
		return false
	}
	return c.Surv.CanAttachTo(c.Level, x, y, z, d)
}

func (f *multifaceGrowthFeature) getStateForPlacement(c *Ctx, oldState, x, y, z, d int) int {
	if !f.isValidStateForPlacement(c, oldState, x, y, z, d) {
		return -1
	}
	b := c.B
	var state int
	if int(b.BlockOf[oldState]) == f.block {
		state = oldState
	} else if b.IsWater(oldState) && b.FluidIsSource(oldState) {
		state = b.WithBool(f.blockState, "waterlogged", true)
	} else {
		state = f.blockState
	}
	return b.WithBool(state, DirNames[d], true)
}

func (f *multifaceGrowthFeature) spreadFromFaceTowardRandomDirection(c *Ctx, state, x, y, z, startingFace int) {
	directions := utilShuffle([]int{Down, Up, North, South, West, East}, c.R)
	for _, spread := range directions {
		if DirAxis[spread] == DirAxis[startingFace] {
			continue
		}
		otherValidAsSource := f.sculkVein && int(c.B.BlockOf[state]) != f.block
		if !otherValidAsSource && (!f.hasFace(c, state, startingFace) || f.hasFace(c, state, spread)) {
			continue
		}
		for t := 0; t < 3; t++ {
			var px, py, pz, face int
			switch t {
			case 0:
				px, py, pz, face = x, y, z, spread
			case 1:
				px, py, pz, face = x+StepX[spread], y+StepY[spread], z+StepZ[spread], startingFace
			default:
				px = x + StepX[spread] + StepX[startingFace]
				py = y + StepY[spread] + StepY[startingFace]
				pz = z + StepZ[spread] + StepZ[startingFace]
				face = Opposite[spread]
			}
			if f.canSpreadInto(c, x, y, z, px, py, pz, face) {
				existing := c.Level.GetState(px, py, pz)
				if spreadState := f.getStateForPlacement(c, existing, px, py, pz, face); spreadState >= 0 {
					c.Level.SetState(px, py, pz, spreadState)
					return
				}
				break //this direction is done (Optional.flatMap() gave nothing)
			}
		}
	}
}

func (f *multifaceGrowthFeature) canSpreadInto(c *Ctx, sx, sy, sz, x, y, z, face int) bool {
	existing := c.Level.GetState(x, y, z)
	return f.stateCanBeReplaced(c, sx, sy, sz, x, y, z, face, existing) && f.isValidStateForPlacement(c, existing, x, y, z, face)
}

func (f *multifaceGrowthFeature) stateCanBeReplaced(c *Ctx, sx, sy, sz, x, y, z, face, existing int) bool {
	b := c.B
	if f.sculkVein {
		against := int(b.BlockOf[c.Level.GetState(x+StepX[face], y+StepY[face], z+StepZ[face])])
		if against == f.sculk || against == f.catalyst || against == f.mvPiston {
			return false
		}
		if absInt(sx-x)+absInt(sy-y)+absInt(sz-z) == 2 {
			o := Opposite[face]
			n := c.Level.GetState(sx+StepX[o], sy+StepY[o], sz+StepZ[o])
			if b.IsFaceSturdy(n, face) {
				return false
			}
		}
		if b.Fluid(existing) != FluidNone && !b.IsWater(existing) {
			return false
		}
		if b.IsTagged(existing, "fire") {
			return false
		}
		if b.IsTagged(existing, "replaceable") {
			return true
		}
	}
	return b.IsAir(existing) || int(b.BlockOf[existing]) == f.block || (int(b.BlockOf[existing]) == b.WaterBlock && b.FluidIsSource(existing))
}
