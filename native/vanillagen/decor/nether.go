package decor

// type/NetherFeatures.php

// withinManhattan is BlockPos.withinManhattan(): positions by increasing Manhattan distance from the origin (within
// the reach on each axis), x then y increasing within a distance, each z offset followed by its mirror. visit returns
// false to stop.
func withinManhattan(ox, oy, oz, reachX, reachY, reachZ int, visit func(x, y, z int) bool) {
	maxDepth := reachX + reachY + reachZ
	for depth := 0; depth <= maxDepth; depth++ {
		maxX := minInt(reachX, depth)
		for x := -maxX; x <= maxX; x++ {
			maxY := minInt(reachY, depth-absInt(x))
			for y := -maxY; y <= maxY; y++ {
				z := depth - absInt(x) - absInt(y)
				if z <= reachZ {
					if !visit(ox+x, oy+y, oz+z) {
						return
					}
					if z != 0 && !visit(ox+x, oy+y, oz-z) {
						return
					}
				}
			}
		}
	}
}

// random_neighbor_spread
type randomNeighborSpreadFeature struct {
	attempts          int
	block             StateProvider
	accepted          []bool
	canReplace        BlockPredicate
	xzOffset, yOffset *IntProvider
}

func newRandomNeighborSpread(reg *Registry, json obj) (Feature, error) {
	f := &randomNeighborSpreadFeature{}
	var err error
	if f.attempts, err = jInt(json, "attempts"); err != nil {
		return nil, err
	}
	if f.block, err = reg.ParseStateProvider(json["block"]); err != nil {
		return nil, err
	}
	set, err := reg.B.BlockSet(json["accepted_neighbors"])
	if err != nil {
		return nil, err
	}
	f.accepted = blockMask(reg.B, set)
	if f.canReplace, err = parseBlockPredicate(reg, json["can_replace"]); err != nil {
		return nil, err
	}
	if f.xzOffset, err = parseIntProvider(json["xz_offset"]); err != nil {
		return nil, err
	}
	if f.yOffset, err = parseIntProvider(json["y_offset"]); err != nil {
		return nil, err
	}
	return f, nil
}

func (f *randomNeighborSpreadFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	state := f.block(c, r, x, y, z)
	if state < 0 {
		return false
	}
	level.SetState(x, y, z, state)
	for i := 0; i < f.attempts; i++ {
		px := x + f.xzOffset.Sample(r)
		py := y + f.yOffset.Sample(r)
		pz := z + f.xzOffset.Sample(r)
		if !f.canReplace(c, px, py, pz) {
			continue
		}
		count := 0
		for d := 0; d < 6; d++ {
			if f.accepted[c.B.BlockOf[level.GetState(px+StepX[d], py+StepY[d], pz+StepZ[d])]] {
				count++
				if count > 1 {
					break
				}
			}
		}
		if count == 1 {
			if s := f.block(c, r, px, py, pz); s >= 0 {
				level.SetState(px, py, pz, s)
			}
		}
	}
	return true
}

// single_block_pillar
type singleBlockPillarFeature struct {
	block               StateProvider
	canReplace          BlockPredicate
	direction           int
	chanceToContinue    float64
	hasChanceToContinue bool
	cap                 *PlacedFeature
}

func newSingleBlockPillar(reg *Registry, json obj) (Feature, error) {
	f := &singleBlockPillarFeature{}
	var err error
	if f.block, err = reg.ParseStateProvider(json["block"]); err != nil {
		return nil, err
	}
	if f.canReplace, err = parseBlockPredicate(reg, json["can_replace"]); err != nil {
		return nil, err
	}
	name, err := jStringDef(json, "direction", "down")
	if err != nil {
		return nil, err
	}
	if f.direction, err = directionFromName(name); err != nil {
		return nil, err
	}
	if json["chance_to_continue"] != nil {
		v, err := jFloat(json, "chance_to_continue")
		if err != nil {
			return nil, err
		}
		f.chanceToContinue, f.hasChanceToContinue = fround(v), true
	}
	if json["cap_feature"] != nil {
		if f.cap, err = reg.PlacedRef(json["cap_feature"]); err != nil {
			return nil, err
		}
	}
	return f, nil
}

func (f *singleBlockPillarFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	dx, dy, dz := StepX[f.direction], StepY[f.direction], StepZ[f.direction]
	placed := false
	lastX, lastY, lastZ := x, y, z
	for f.canReplace(c, x, y, z) {
		if level.IsOutsideBuildHeight(y) {
			return placed
		}
		if placed && f.hasChanceToContinue && !(r.NextFloat() < f.chanceToContinue) {
			break
		}
		state := f.block(c, r, x, y, z)
		if state < 0 {
			break
		}
		level.SetState(x, y, z, state)
		placed = true
		lastX, lastY, lastZ = x, y, z
		x += dx
		y += dy
		z += dz
	}
	if placed && f.cap != nil {
		f.cap.Place(c, lastX, lastY, lastZ)
	}
	return placed
}

// projected_random_patchy_square
type projectedPatchySquareFeature struct {
	size, maxProjection int
	projectThrough      BlockPredicate
	block               StateProvider
}

func newProjectedPatchySquare(reg *Registry, json obj) (Feature, error) {
	f := &projectedPatchySquareFeature{}
	var err error
	if f.size, err = jInt(json, "size"); err != nil {
		return nil, err
	}
	if f.maxProjection, err = jInt(json, "max_projection_height"); err != nil {
		return nil, err
	}
	if f.projectThrough, err = parseBlockPredicate(reg, json["project_through"]); err != nil {
		return nil, err
	}
	if f.block, err = reg.ParseStateProvider(json["block"]); err != nil {
		return nil, err
	}
	return f, nil
}

func (f *projectedPatchySquareFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	placed := false
	for dx := -f.size; dx <= f.size; dx++ {
		for dz := -f.size; dz <= f.size; dz++ {
			//the further from the axes, the less likely
			if !(r.NextBoundedInt(10) < 10-absInt(dx)*absInt(dz)) {
				continue
			}
			px, py, pz := x+dx, y, z+dz
			drops := f.maxProjection
			for f.projectThrough(c, px, py-1, pz) {
				py--
				drops--
				if drops <= 0 {
					break
				}
			}
			if state := f.block(c, r, px, py, pz); state >= 0 {
				level.SetState(px, py, pz, state)
				placed = true
			}
		}
	}
	return placed
}

// stepped_column_cluster (BasaltColumnsFeature)
type steppedColumnClusterFeature struct {
	block                     StateProvider
	canReplace                BlockPredicate
	cannotPlaceOn             []bool
	continueThrough           BlockPredicate
	clusterReach, columnCount int
	columnReach, height       *IntProvider
}

func newSteppedColumnCluster(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	f := &steppedColumnClusterFeature{}
	var err error
	if f.block, err = reg.ParseStateProvider(json["block"]); err != nil {
		return nil, err
	}
	if f.canReplace, err = parseBlockPredicate(reg, json["can_replace"]); err != nil {
		return nil, err
	}
	cpo := json["cannot_place_on"]
	if cpo == nil {
		cpo = []any{}
	}
	set, err := b.BlockSet(cpo)
	if err != nil {
		return nil, err
	}
	f.cannotPlaceOn = blockMask(b, set)
	f.continueThrough = func(*Ctx, int, int, int) bool { return false }
	if json["continue_through"] != nil {
		if f.continueThrough, err = parseBlockPredicate(reg, json["continue_through"]); err != nil {
			return nil, err
		}
	}
	if f.clusterReach, err = jInt(json, "cluster_reach"); err != nil {
		return nil, err
	}
	if f.columnCount, err = jInt(json, "column_count"); err != nil {
		return nil, err
	}
	if f.columnReach, err = parseIntProvider(json["column_reach"]); err != nil {
		return nil, err
	}
	if f.height, err = parseIntProvider(json["height"]); err != nil {
		return nil, err
	}
	return f, nil
}

func (f *steppedColumnClusterFeature) canPlaceAt(c *Ctx, x, y, z int) bool {
	if !f.canReplace(c, x, y, z) {
		return false
	}
	below := c.Level.GetState(x, y-1, z)
	return !c.B.IsAir(below) && !f.cannotPlaceOn[c.B.BlockOf[below]]
}

func (f *steppedColumnClusterFeature) Place(c *Ctx, x, y, z int) bool {
	if !f.canPlaceAt(c, x, y, z) {
		return false
	}
	r := c.R
	height := f.height.Sample(r)
	rng := minInt(height, f.clusterReach)
	width := 2*rng + 1
	placed := false
	//BlockPos.randomBetweenClosed(): lazily, so the column reach is sampled between positions
	for i := 0; i < f.columnCount; i++ {
		px := x - rng + r.NextBoundedInt(width)
		py := y + r.NextBoundedInt(1)
		pz := z - rng + r.NextBoundedInt(width)
		columnHeight := height - (absInt(px-x) + absInt(py-y) + absInt(pz-z))
		if columnHeight >= 0 {
			placed = f.placeColumn(c, px, py, pz, columnHeight, f.columnReach.Sample(r)) || placed
		}
	}
	return placed
}

func (f *steppedColumnClusterFeature) placeColumn(c *Ctx, ox, oy, oz, columnHeight, reach int) bool {
	level := c.Level
	placed := false
	for dz := -reach; dz <= reach; dz++ {
		for dx := -reach; dx <= reach; dx++ {
			x := ox + dx
			z := oz + dz
			distance := absInt(dx) + absInt(dz)
			var y int
			var ok bool
			if f.canReplace(c, x, oy, z) {
				y, ok = f.findSurface(c, x, oy, z, distance)
			} else {
				y, ok = f.findAir(c, x, oy, z, distance)
			}
			if !ok {
				continue
			}
			for h := columnHeight - distance/2; h >= 0; h-- {
				if f.canReplace(c, x, y, z) {
					if state := f.block(c, c.R, x, y, z); state >= 0 {
						level.SetState(x, y, z, state)
						placed = true
					}
					y++
				} else if f.continueThrough(c, x, y, z) {
					y++
				} else {
					break
				}
			}
		}
	}
	return placed
}

func (f *steppedColumnClusterFeature) findSurface(c *Ctx, x, y, z, limit int) (int, bool) {
	for y > c.Level.MinY+1 && limit > 0 {
		limit--
		if f.canPlaceAt(c, x, y, z) {
			return y, true
		}
		y--
	}
	return 0, false
}

func (f *steppedColumnClusterFeature) findAir(c *Ctx, x, y, z, limit int) (int, bool) {
	b := c.B
	for y < c.Level.MaxY-1 && limit > 0 {
		limit--
		state := c.Level.GetState(x, y, z)
		if f.cannotPlaceOn[b.BlockOf[state]] {
			return 0, false
		}
		if b.IsAir(state) {
			return y, true
		}
		y++
	}
	return 0, false
}

// delta_feature
type deltaFeature struct {
	contents, rim int
	size, rimSize *IntProvider
	cannotReplace []bool
	contentsBlock int
}

func newDelta(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	f := &deltaFeature{cannotReplace: make([]bool, len(b.Names))}
	for _, name := range []string{"bedrock", "nether_bricks", "nether_brick_fence", "nether_brick_stairs", "nether_wart", "chest", "spawner"} {
		if i, err := b.BlockIndex(name); err == nil {
			f.cannotReplace[i] = true
		}
	}
	var err error
	if f.contents, err = b.StateFromJSON(json["contents"]); err != nil {
		return nil, err
	}
	if f.rim, err = b.StateFromJSON(json["rim"]); err != nil {
		return nil, err
	}
	if f.size, err = parseIntProvider(json["size"]); err != nil {
		return nil, err
	}
	if f.rimSize, err = parseIntProvider(json["rim_size"]); err != nil {
		return nil, err
	}
	f.contentsBlock = int(b.BlockOf[f.contents])
	return f, nil
}

func (f *deltaFeature) isClear(c *Ctx, x, y, z int) bool {
	b := c.B
	block := int(b.BlockOf[c.Level.GetState(x, y, z)])
	if block == f.contentsBlock || f.cannotReplace[block] {
		return false
	}
	for d := 0; d < 6; d++ {
		air := b.IsAir(c.Level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d]))
		if air != (d == Up) {
			return false
		}
	}
	return true
}

func (f *deltaFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	level := c.Level
	placed := false
	hasRim := r.NextDouble() < 0.9
	rimX, rimZ := 0, 0
	if hasRim {
		rimX = f.rimSize.Sample(r)
		rimZ = f.rimSize.Sample(r)
	}
	hasRimForSure := hasRim && rimX != 0 && rimZ != 0
	radiusX := f.size.Sample(r)
	radiusZ := f.size.Sample(r)
	limit := maxInt(radiusX, radiusZ)
	withinManhattan(x, y, z, radiusX, 0, radiusZ, func(px, py, pz int) bool {
		if absInt(px-x)+absInt(py-y)+absInt(pz-z) > limit {
			return false
		}
		if f.isClear(c, px, py, pz) {
			if hasRimForSure {
				placed = true
				level.SetState(px, py, pz, f.rim)
			}
			if f.isClear(c, px+rimX, py, pz+rimZ) {
				placed = true
				level.SetState(px+rimX, py, pz+rimZ, f.contents)
			}
		}
		return true
	})
	return placed
}

// netherrack_replace_blobs (ReplaceBlobsFeature)
type replaceBlobsFeature struct {
	target, state int
	radius        *IntProvider
}

func newReplaceBlobs(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	target, err := b.StateFromJSON(json["target"])
	if err != nil {
		return nil, err
	}
	state, err := b.StateFromJSON(json["state"])
	if err != nil {
		return nil, err
	}
	radius, err := parseIntProvider(json["radius"])
	if err != nil {
		return nil, err
	}
	return &replaceBlobsFeature{int(b.BlockOf[target]), state, radius}, nil
}

func (f *replaceBlobsFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	maxY := level.MaxY - 1
	if y < level.MinY+1 {
		y = level.MinY + 1
	} else if y > maxY {
		y = maxY
	}
	found := false
	for y > level.MinY+1 {
		if int(b.BlockOf[level.GetState(x, y, z)]) == f.target {
			found = true
			break
		}
		y--
	}
	if !found {
		return false
	}
	r := c.R
	radiusX := f.radius.Sample(r)
	radiusY := f.radius.Sample(r)
	radiusZ := f.radius.Sample(r)
	limit := maxInt(radiusX, maxInt(radiusY, radiusZ))
	placed := false
	withinManhattan(x, y, z, radiusX, radiusY, radiusZ, func(px, py, pz int) bool {
		if absInt(px-x)+absInt(py-y)+absInt(pz-z) > limit {
			return false
		}
		if int(b.BlockOf[level.GetState(px, py, pz)]) == f.target {
			level.SetState(px, py, pz, f.state)
			placed = true
		}
		return true
	})
	return placed
}

// huge_fungus (HugeFungusFeature, worldgen variant)
type hugeFungusFeature struct {
	validBase, stem, hat, decor                         int
	replaceable                                         BlockPredicate
	planted                                             bool
	weepingVines, weepingVinesPlant, wartBlock          int
	f01, f02, f001, f07, f0005, f098, f0083, f007, f006 float64
}

func newHugeFungus(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	f := &hugeFungusFeature{}
	base, err := b.StateFromJSON(json["valid_base_block"])
	if err != nil {
		return nil, err
	}
	f.validBase = int(b.BlockOf[base])
	if f.stem, err = b.StateFromJSON(json["stem_state"]); err != nil {
		return nil, err
	}
	if f.hat, err = b.StateFromJSON(json["hat_state"]); err != nil {
		return nil, err
	}
	if f.decor, err = b.StateFromJSON(json["decor_state"]); err != nil {
		return nil, err
	}
	if f.replaceable, err = parseBlockPredicate(reg, json["replaceable_blocks"]); err != nil {
		return nil, err
	}
	f.planted = jBoolDef(json, "planted", false)
	f.weepingVines = b.MustDefault("weeping_vines")
	f.weepingVinesPlant = b.MustDefault("weeping_vines_plant")
	f.wartBlock = b.MustBlock("nether_wart_block")
	f.f01, f.f02, f.f001, f.f07 = fround(0.1), fround(0.2), fround(0.01), fround(0.7)
	f.f0005, f.f098, f.f0083, f.f007, f.f006 = fround(5.0e-4), fround(0.98), fround(0.083), fround(0.07), fround(0.06)
	return f, nil
}

func (f *hugeFungusFeature) isReplaceable(c *Ctx, x, y, z int, checkConfig bool) bool {
	if c.B.IsTagged(c.Level.GetState(x, y, z), "replaceable") {
		return true
	}
	return checkConfig && f.replaceable(c, x, y, z)
}

func (f *hugeFungusFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	if int(c.B.BlockOf[level.GetState(x, y-1, z)]) != f.validBase {
		return false
	}
	totalHeight := r.MthNextInt(4, 13)
	if r.NextBoundedInt(12) == 0 {
		totalHeight *= 2
	}
	if !f.planted && y+totalHeight+1 >= c.GenDepth {
		return false
	}
	isHuge := !f.planted && r.NextFloat() < f.f006
	level.SetState(x, y, z, c.B.Air)
	f.placeStem(c, x, y, z, totalHeight, isHuge)
	f.placeHat(c, x, y, z, totalHeight, isHuge)
	return true
}

func (f *hugeFungusFeature) placeStem(c *Ctx, ox, oy, oz, totalHeight int, isHuge bool) {
	radius := 0
	if isHuge {
		radius = 1
	}
	for dx := -radius; dx <= radius; dx++ {
		for dz := -radius; dz <= radius; dz++ {
			corner := isHuge && absInt(dx) == radius && absInt(dz) == radius
			for dy := 0; dy < totalHeight; dy++ {
				x, y, z := ox+dx, oy+dy, oz+dz
				if !f.isReplaceable(c, x, y, z, true) {
					continue
				}
				if corner {
					if c.R.NextFloat() < f.f01 {
						c.Level.SetState(x, y, z, f.stem)
					}
				} else {
					c.Level.SetState(x, y, z, f.stem)
				}
			}
		}
	}
}

func (f *hugeFungusFeature) placeHat(c *Ctx, ox, oy, oz, totalHeight int, isHuge bool) {
	r := c.R
	placeVines := int(c.B.BlockOf[f.hat]) == f.wartBlock
	hatHeight := minInt(r.NextBoundedInt(1+totalHeight/3)+5, totalHeight)
	hatStart := totalHeight - hatHeight
	vines := func(chance float64) float64 {
		if placeVines {
			return chance
		}
		return 0.0
	}
	for dy := hatStart; dy <= totalHeight; dy++ {
		radius := 1
		if dy < totalHeight-r.NextBoundedInt(3) {
			radius = 2
		}
		if hatHeight > 8 && dy < hatStart+4 {
			radius = 3
		}
		if isHuge {
			radius++
		}
		for dx := -radius; dx <= radius; dx++ {
			for dz := -radius; dz <= radius; dz++ {
				edgeX := dx == -radius || dx == radius
				edgeZ := dz == -radius || dz == radius
				inside := !edgeX && !edgeZ && dy != totalHeight
				corner := edgeX && edgeZ
				lowEdge := dy < hatStart+3
				x, y, z := ox+dx, oy+dy, oz+dz
				if !f.isReplaceable(c, x, y, z, false) {
					continue
				}
				switch {
				case lowEdge:
					if !inside {
						f.placeHatDropBlock(c, x, y, z, placeVines)
					}
				case inside:
					f.placeHatBlock(c, x, y, z, f.f01, f.f02, vines(f.f01))
				case corner:
					f.placeHatBlock(c, x, y, z, f.f001, f.f07, vines(f.f0083))
				default:
					f.placeHatBlock(c, x, y, z, f.f0005, f.f098, vines(f.f007))
				}
			}
		}
	}
}

func (f *hugeFungusFeature) placeHatBlock(c *Ctx, x, y, z int, decorChance, hatChance, vinesChance float64) {
	r := c.R
	if r.NextFloat() < decorChance {
		c.Level.SetState(x, y, z, f.decor)
	} else if r.NextFloat() < hatChance {
		c.Level.SetState(x, y, z, f.hat)
		if r.NextFloat() < vinesChance {
			f.tryPlaceWeepingVines(c, x, y, z)
		}
	}
}

func (f *hugeFungusFeature) placeHatDropBlock(c *Ctx, x, y, z int, placeVines bool) {
	r := c.R
	if c.B.BlockOf[c.Level.GetState(x, y-1, z)] == c.B.BlockOf[f.hat] {
		c.Level.SetState(x, y, z, f.hat)
	} else if r.NextFloat() < 0.15 {
		c.Level.SetState(x, y, z, f.hat)
		if placeVines && r.NextBoundedInt(11) == 0 {
			f.tryPlaceWeepingVines(c, x, y, z)
		}
	}
}

func (f *hugeFungusFeature) tryPlaceWeepingVines(c *Ctx, x, y, z int) {
	y--
	level := c.Level
	if !level.IsEmptyBlock(x, y, z) {
		return
	}
	r := c.R
	length := r.MthNextInt(1, 5)
	if r.NextBoundedInt(7) == 0 {
		length *= 2
	}
	//WeepingVinesFeature.placeWeepingVinesColumn()
	for i := 0; i <= length; i++ {
		if level.IsEmptyBlock(x, y, z) {
			if i == length || !level.IsEmptyBlock(x, y-1, z) {
				level.SetState(x, y, z, c.B.WithInt(f.weepingVines, "age", r.MthNextInt(23, 25)))
				break
			}
			level.SetState(x, y, z, f.weepingVinesPlant)
		}
		y--
	}
}
