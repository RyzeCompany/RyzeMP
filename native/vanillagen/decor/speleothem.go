package decor

import (
	"math"
)

// type/SpeleothemFeatures.php: speleothem (pointed dripstone, sulfur spikes), speleothem_cluster and
// large_dripstone.

const (
	spSpeleothem = iota
	spCluster
	spLarge
)

type speleothemFeature struct {
	mode                       int
	baseBlock, baseBlockIndex  int
	pointedBlock, pointedIndex int
	replaceable                []bool
	baseStone                  []bool

	chanceOfTallerGeneration, chanceOfDirectionalSpread, chanceOfSpreadRadius2, chanceOfSpreadRadius3 float64

	searchRange                                int
	height, radius, layerThickness             *IntProvider
	maxHeightDiff, heightDeviation             int
	density, wetness                           FloatProvider
	chanceAtMaxDistance                        float64
	maxDistanceFromEdge, maxDistanceFromCenter int
	heightScale                                FloatProvider
	maxRadiusToHeightRatio                     float64
	stalactiteBluntness, stalagmiteBluntness   FloatProvider
	windSpeed                                  FloatProvider
	minRadiusForWind                           int
	minBluntnessForWind                        float64
}

func newSpeleothem(mode int) func(reg *Registry, json obj) (Feature, error) {
	return func(reg *Registry, json obj) (Feature, error) {
		b := reg.B
		zero := FloatProvider(func(*Random) float64 { return 0.0 })
		f := &speleothemFeature{mode: mode, density: zero, wetness: zero, heightScale: zero, stalactiteBluntness: zero, stalagmiteBluntness: zero, windSpeed: zero,
			chanceOfTallerGeneration: 0.2, chanceOfDirectionalSpread: 0.7, chanceOfSpreadRadius2: 0.5, chanceOfSpreadRadius3: 0.5,
			searchRange: 30, maxDistanceFromEdge: 1, maxDistanceFromCenter: 1}
		var err error
		if mode == spLarge {
			if f.baseBlock, err = b.DefaultOf("dripstone_block"); err != nil {
				return nil, err
			}
			if f.pointedBlock, err = b.DefaultOf("pointed_dripstone"); err != nil {
				return nil, err
			}
		} else {
			if f.baseBlock, err = b.StateFromJSON(json["base_block"]); err != nil {
				return nil, err
			}
			if f.pointedBlock, err = b.StateFromJSON(json["pointed_block"]); err != nil {
				return nil, err
			}
		}
		f.baseBlockIndex = int(b.BlockOf[f.baseBlock])
		f.pointedIndex = int(b.BlockOf[f.pointedBlock])
		set, err := b.BlockSet(json["replaceable_blocks"])
		if err != nil {
			return nil, err
		}
		f.replaceable = blockMask(b, set)
		f.baseStone = b.TagMask("minecraft:base_stone_overworld")
		var errs [12]error
		switch mode {
		case spSpeleothem:
			var a, c, d, e float64
			a, errs[0] = jFloatDef(json, "chance_of_taller_generation", 0.20000000298023224)
			c, errs[1] = jFloatDef(json, "chance_of_directional_spread", 0.699999988079071)
			d, errs[2] = jFloatDef(json, "chance_of_spread_radius2", 0.5)
			e, errs[3] = jFloatDef(json, "chance_of_spread_radius3", 0.5)
			f.chanceOfTallerGeneration, f.chanceOfDirectionalSpread, f.chanceOfSpreadRadius2, f.chanceOfSpreadRadius3 = fround(a), fround(c), fround(d), fround(e)
		case spCluster:
			var chance float64
			f.searchRange, errs[0] = jInt(json, "floor_to_ceiling_search_range")
			f.height, errs[1] = parseIntProvider(json["height"])
			f.radius, errs[2] = parseIntProvider(json["radius"])
			f.maxHeightDiff, errs[3] = jInt(json, "max_stalagmite_stalactite_height_diff")
			f.heightDeviation, errs[4] = jInt(json, "height_deviation")
			f.layerThickness, errs[5] = parseIntProvider(json["speleothem_block_layer_thickness"])
			f.density, errs[6] = parseFloatProvider(json["density"])
			f.wetness, errs[7] = parseFloatProvider(json["wetness"])
			chance, errs[8] = jFloat(json, "chance_of_speleothem_at_max_distance_from_center")
			f.maxDistanceFromEdge, errs[9] = jInt(json, "max_distance_from_edge_affecting_chance_of_speleothem")
			f.maxDistanceFromCenter, errs[10] = jInt(json, "max_distance_from_center_affecting_height_bias")
			f.chanceAtMaxDistance = fround(chance)
		case spLarge:
			var ratio, minBluntness float64
			f.searchRange, errs[0] = jIntDef(json, "floor_to_ceiling_search_range", 30)
			f.radius, errs[1] = parseIntProvider(json["column_radius"])
			f.heightScale, errs[2] = parseFloatProvider(json["height_scale"])
			ratio, errs[3] = jFloat(json, "max_column_radius_to_cave_height_ratio")
			f.stalactiteBluntness, errs[4] = parseFloatProvider(json["stalactite_bluntness"])
			f.stalagmiteBluntness, errs[5] = parseFloatProvider(json["stalagmite_bluntness"])
			f.windSpeed, errs[6] = parseFloatProvider(json["wind_speed"])
			f.minRadiusForWind, errs[7] = jInt(json, "min_radius_for_wind")
			minBluntness, errs[8] = jFloat(json, "min_bluntness_for_wind")
			f.maxRadiusToHeightRatio, f.minBluntnessForWind = fround(ratio), fround(minBluntness)
		}
		if err := firstErr(errs[:]...); err != nil {
			return nil, err
		}
		return f, nil
	}
}

func (f *speleothemFeature) Place(c *Ctx, x, y, z int) bool {
	switch f.mode {
	case spSpeleothem:
		return f.placeSpeleothem(c, x, y, z)
	case spCluster:
		return f.placeCluster(c, x, y, z)
	}
	return f.placeLarge(c, x, y, z)
}

// ---- SpeleothemUtils ----

func (f *speleothemFeature) isEmptyOrWater(c *Ctx, state int) bool {
	return c.B.IsAir(state) || int(c.B.BlockOf[state]) == c.B.WaterBlock
}

func (f *speleothemFeature) isEmptyOrWaterOrLava(c *Ctx, state int) bool {
	block := int(c.B.BlockOf[state])
	return c.B.IsAir(state) || block == c.B.WaterBlock || block == c.B.LavaBlock
}

func (f *speleothemFeature) isBase(c *Ctx, state int) bool {
	block := int(c.B.BlockOf[state])
	return block == f.baseBlockIndex || f.replaceable[block]
}

func (f *speleothemFeature) placeBaseBlockIfPossible(c *Ctx, x, y, z int) bool {
	if f.replaceable[c.B.BlockOf[c.Level.GetState(x, y, z)]] {
		c.Level.SetState(x, y, z, f.baseBlock)
		return true
	}
	return false
}

func (f *speleothemFeature) pointed(c *Ctx, direction int, thickness string) int {
	dir := "down"
	if direction == Up {
		dir = "up"
	}
	return c.B.With(c.B.With(f.pointedBlock, "vertical_direction", dir), "thickness", thickness)
}

func (f *speleothemFeature) growSpeleothem(c *Ctx, x, y, z, tip, height int, mergedTip bool) {
	dy := StepY[tip]
	if !f.isBase(c, c.Level.GetState(x, y-dy, z)) {
		return
	}
	var column []string
	if height >= 3 {
		column = append(column, "base")
		for i := 0; i < height-3; i++ {
			column = append(column, "middle")
		}
	}
	if height >= 2 {
		column = append(column, "frustum")
	}
	if height >= 1 {
		if mergedTip {
			column = append(column, "tip_merge")
		} else {
			column = append(column, "tip")
		}
	}
	for _, thickness := range column {
		state := f.pointed(c, tip, thickness)
		state = c.B.WithBool(state, "waterlogged", c.Level.IsWaterAt(x, y, z))
		c.Level.SetState(x, y, z, state)
		y += dy
	}
}

// ---- speleothem ----

func (f *speleothemFeature) placeSpeleothem(c *Ctx, x, y, z int) bool {
	r := c.R
	level := c.Level
	above := f.isBase(c, level.GetState(x, y+1, z))
	below := f.isBase(c, level.GetState(x, y-1, z))
	var tip int
	switch {
	case above && below:
		if r.NextBoolean() {
			tip = Down
		} else {
			tip = Up
		}
	case above:
		tip = Down
	case below:
		tip = Up
	default:
		return false
	}
	ry := y - StepY[tip]
	f.createPatchOfBaseBlocks(c, x, ry, z)
	height := 1
	if r.NextFloat() < f.chanceOfTallerGeneration && f.isEmptyOrWater(c, level.GetState(x, y+StepY[tip], z)) {
		height = 2
	}
	f.growSpeleothem(c, x, y, z, tip, height, false)
	return true
}

func (f *speleothemFeature) createPatchOfBaseBlocks(c *Ctx, x, y, z int) {
	r := c.R
	f.placeBaseBlockIfPossible(c, x, y, z)
	for _, d := range PlaneHorizontal {
		if !(r.NextFloat() <= f.chanceOfDirectionalSpread) {
			continue
		}
		x1 := x + StepX[d]
		z1 := z + StepZ[d]
		f.placeBaseBlockIfPossible(c, x1, y, z1)
		if !(r.NextFloat() <= f.chanceOfSpreadRadius2) {
			continue
		}
		d2 := r.NextBoundedInt(6)
		x2 := x1 + StepX[d2]
		y2 := y + StepY[d2]
		z2 := z1 + StepZ[d2]
		f.placeBaseBlockIfPossible(c, x2, y2, z2)
		if !(r.NextFloat() <= f.chanceOfSpreadRadius3) {
			continue
		}
		d3 := r.NextBoundedInt(6)
		f.placeBaseBlockIfPossible(c, x2+StepX[d3], y2+StepY[d3], z2+StepZ[d3])
	}
}

// scanColumn is Column.scan(): floor and ceiling (ok false when not found); found false if the start is not inside.
func (f *speleothemFeature) scanColumn(c *Ctx, x, y, z int, inside, edge func(int) bool) (floor, ceiling int, hasFloor, hasCeiling, found bool) {
	level := c.Level
	if !inside(level.GetState(x, y, z)) {
		return 0, 0, false, false, false
	}
	var res [2]int
	var ok [2]bool
	for k, dy := range [2]int{1, -1} {
		py := y
		for i := 1; i < f.searchRange && inside(level.GetState(x, py, z)); i++ {
			py += dy
		}
		if edge(level.GetState(x, py, z)) {
			res[k] = py
			ok[k] = true
		}
	}
	return res[1], res[0], ok[1], ok[0], true
}

// ---- speleothem_cluster ----

func (f *speleothemFeature) placeCluster(c *Ctx, x, y, z int) bool {
	r := c.R
	if !f.isEmptyOrWater(c, c.Level.GetState(x, y, z)) {
		return false
	}
	height := 0
	if f.height != nil {
		height = f.height.Sample(r)
	}
	wetness := f.wetness(r)
	density := f.density(r)
	xRadius, zRadius := 0, 0
	if f.radius != nil {
		xRadius = f.radius.Sample(r)
		zRadius = f.radius.Sample(r)
	}
	for dx := -xRadius; dx <= xRadius; dx++ {
		for dz := -zRadius; dz <= zRadius; dz++ {
			chance := f.chanceOfStalagmiteOrStalactite(xRadius, zRadius, dx, dz)
			f.placeColumn(c, x+dx, y, z+dz, dx, dz, wetness, chance, height, density)
		}
	}
	return true
}

func (f *speleothemFeature) chanceOfStalagmiteOrStalactite(xRadius, zRadius, dx, dz int) float64 {
	distanceFromEdge := minInt(xRadius-absInt(dx), zRadius-absInt(dz))
	//Mth.clampedMap(float...) in float32
	t := fround(fround(float64(distanceFromEdge)-0.0) / fround(float64(f.maxDistanceFromEdge)-0.0))
	if t < 0.0 {
		return f.chanceAtMaxDistance
	}
	if t > 1.0 {
		return 1.0
	}
	return LerpF(t, f.chanceAtMaxDistance, 1.0)
}

func (f *speleothemFeature) placeColumn(c *Ctx, x, y, z, dx, dz int, wetness, chance float64, clusterHeight int, density float64) {
	level := c.Level
	r := c.R
	b := c.B
	emptyOrWater := func(s int) bool { return f.isEmptyOrWater(c, s) }
	baseFloor, ceiling, hasFloor, hasCeiling, found := f.scanColumn(c, x, y, z, emptyOrWater, func(s int) bool { return !f.isEmptyOrWater(c, s) })
	if !found || (!hasCeiling && !hasFloor) {
		return
	}
	wantPool := r.NextFloat() < wetness
	floor := baseFloor
	if wantPool && hasFloor && f.canPlacePool(c, x, baseFloor, z) {
		floor = baseFloor - 1
		level.SetState(x, baseFloor, z, b.Water)
	}
	wantStalactite := r.NextDouble() < chance
	stalactiteHeight := 0
	if hasCeiling && wantStalactite && !f.isLava(c, x, ceiling, z) {
		thickness := 0
		if f.layerThickness != nil {
			thickness = f.layerThickness.Sample(r)
		}
		f.replaceBlocksWithBaseBlocks(c, x, ceiling, z, thickness, 1)
		maxHeight := clusterHeight
		if hasFloor {
			maxHeight = minInt(clusterHeight, ceiling-floor)
		}
		stalactiteHeight = f.speleothemHeight(r, dx, dz, density, maxHeight)
	}
	wantStalagmite := r.NextDouble() < chance
	stalagmiteHeight := 0
	if hasFloor && wantStalagmite && !f.isLava(c, x, floor, z) {
		thickness := 0
		if f.layerThickness != nil {
			thickness = f.layerThickness.Sample(r)
		}
		f.replaceBlocksWithBaseBlocks(c, x, floor, z, thickness, -1)
		if hasCeiling {
			stalagmiteHeight = maxInt(0, stalactiteHeight+r.NextIntBetweenInclusive(-f.maxHeightDiff, f.maxHeightDiff))
		} else {
			stalagmiteHeight = f.speleothemHeight(r, dx, dz, density, clusterHeight)
		}
	}
	var actualStalactiteHeight, actualStalagmiteHeight int
	if hasCeiling && hasFloor && ceiling-stalactiteHeight <= floor+stalagmiteHeight {
		lowestStalactiteBottom := maxInt(ceiling-stalactiteHeight, floor+1)
		highestStalagmiteTop := minInt(floor+stalagmiteHeight, ceiling-1)
		actualStalactiteBottom := r.NextIntBetweenInclusive(lowestStalactiteBottom, highestStalagmiteTop+1)
		actualStalagmiteTop := actualStalactiteBottom - 1
		actualStalactiteHeight = ceiling - actualStalactiteBottom
		actualStalagmiteHeight = actualStalagmiteTop - floor
	} else {
		actualStalactiteHeight = stalactiteHeight
		actualStalagmiteHeight = stalagmiteHeight
	}
	//column height after the pool: Column.withFloor()
	mergeTips := r.NextBoolean() && actualStalactiteHeight > 0 && actualStalagmiteHeight > 0 && hasCeiling && hasFloor && actualStalactiteHeight+actualStalagmiteHeight == ceiling-floor-1
	if hasCeiling {
		f.growSpeleothem(c, x, ceiling-1, z, Down, actualStalactiteHeight, mergeTips)
	}
	if hasFloor {
		f.growSpeleothem(c, x, floor+1, z, Up, actualStalagmiteHeight, mergeTips)
	}
}

func (f *speleothemFeature) isLava(c *Ctx, x, y, z int) bool {
	return int(c.B.BlockOf[c.Level.GetState(x, y, z)]) == c.B.LavaBlock
}

func (f *speleothemFeature) speleothemHeight(r *Random, dx, dz int, density float64, maxHeight int) int {
	if !(r.NextFloat() <= density) {
		return 0
	}
	distance := absInt(dx) + absInt(dz)
	//Mth.clampedMap(double): distance in [0, maxDistanceFromCenter] -> [maxHeight / 2, 0]
	t := float64(distance) / float64(f.maxDistanceFromCenter)
	var mean float64
	half := float64(maxHeight) / 2.0
	if t < 0.0 {
		mean = half
	} else if t > 1.0 {
		mean = 0.0
	} else {
		mean = half + float64(t*(0.0-half))
	}
	mean = fround(mean)
	//ClampedNormalFloat.sample(random, mean, deviation, 0, maxHeight)
	v := fround(mean + fround(float64(fround(r.NextGaussian())*float64(f.heightDeviation))))
	if v < 0.0 {
		v = 0.0
	} else if v > float64(maxHeight) {
		v = float64(maxHeight)
	}
	return phpIntOfFloat(v)
}

func (f *speleothemFeature) canPlacePool(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	block := int(b.BlockOf[level.GetState(x, y, z)])
	if block == b.WaterBlock || block == f.baseBlockIndex || block == f.pointedIndex {
		return false
	}
	if b.IsWater(level.GetState(x, y+1, z)) {
		return false
	}
	for _, d := range PlaneHorizontal {
		if !f.canBeAdjacentToWater(c, x+StepX[d], y, z+StepZ[d]) {
			return false
		}
	}
	return f.canBeAdjacentToWater(c, x, y-1, z)
}

func (f *speleothemFeature) canBeAdjacentToWater(c *Ctx, x, y, z int) bool {
	state := c.Level.GetState(x, y, z)
	return f.baseStone[c.B.BlockOf[state]] || c.B.IsWater(state)
}

func (f *speleothemFeature) replaceBlocksWithBaseBlocks(c *Ctx, x, y, z, count, dy int) {
	for i := 0; i < count; i++ {
		if !f.placeBaseBlockIfPossible(c, x, y, z) {
			return
		}
		y += dy
	}
}

// ---- large_dripstone ----

func speleothemShapeHeight(xzDistanceFromCenter, radius, scale, bluntness float64) float64 {
	if xzDistanceFromCenter < bluntness {
		xzDistanceFromCenter = bluntness
	}
	r := float64(xzDistanceFromCenter/radius) * 0.384
	part1 := float64(0.75 * libmPow(r, 1.3333333333333333))
	part2 := libmPow(r, 0.6666666666666666)
	part3 := float64(0.3333333333333333 * libmLog(r))
	height := phpMaxFloat(float64(scale*((part1-part2)-part3)), 0.0)
	return float64(height/0.384) * radius
}

// phpMaxFloat is PHP's max() of two floats.
func phpMaxFloat(a, b float64) float64 {
	if b > a {
		return b
	}
	return a
}

type speleothemData struct {
	x, y, z    int
	pointingUp bool
	radius     int
	bluntness  float64
	scale      float64
}

type speleothemWind struct {
	originY        int
	speedX, speedZ float64
	maxOffset      int
	ok             bool
}

func (f *speleothemFeature) placeLarge(c *Ctx, x, y, z int) bool {
	r := c.R
	level := c.Level
	if !f.isEmptyOrWater(c, level.GetState(x, y, z)) {
		return false
	}
	floor, ceiling, hasFloor, hasCeiling, found := f.scanColumn(c, x, y, z,
		func(s int) bool { return f.isEmptyOrWater(c, s) },
		func(s int) bool { return f.isBase(c, s) || int(c.B.BlockOf[s]) == c.B.LavaBlock })
	if !found || !hasFloor || !hasCeiling {
		return false
	}
	columnHeight := ceiling - floor - 1
	if columnHeight < 4 {
		return false
	}
	radiusProvider := f.radius
	if radiusProvider == nil {
		radiusProvider = constantInt(1)
	}
	maxByHeight := phpIntOfFloat(fround(float64(float64(columnHeight) * f.maxRadiusToHeightRatio)))
	maxRadius := maxInt(radiusProvider.Min, minInt(radiusProvider.Max, maxByHeight))
	radius := r.NextIntBetweenInclusive(radiusProvider.Min, maxRadius)
	stalactite := speleothemData{x, ceiling - 1, z, false, radius, 0, 0}
	stalactite.bluntness = f.stalactiteBluntness(r)
	stalactite.scale = f.heightScale(r)
	stalagmite := speleothemData{x, floor + 1, z, true, radius, 0, 0}
	stalagmite.bluntness = f.stalagmiteBluntness(r)
	stalagmite.scale = f.heightScale(r)
	var wind speleothemWind
	if stalactite.radius >= f.minRadiusForWind && stalactite.bluntness >= f.minBluntnessForWind && stalagmite.radius >= f.minRadiusForWind && stalagmite.bluntness >= f.minBluntnessForWind {
		speed := f.windSpeed(r)
		direction := fround(fround(float64(r.NextFloat()*3.1415927410125732)) + 0.0)
		wind = speleothemWind{y, fround(float64(MthCos(direction) * speed)), fround(float64(MthSin(direction) * speed)), 16 - radius, true}
	}
	stalactiteOk := f.moveBackUntilEmbedded(c, &stalactite, &wind)
	stalagmiteOk := f.moveBackUntilEmbedded(c, &stalagmite, &wind)
	if stalactiteOk {
		f.placeLargeBlocks(c, &stalactite, &wind)
	}
	if stalagmiteOk {
		f.placeLargeBlocks(c, &stalagmite, &wind)
	}
	return true
}

func windOffset(w *speleothemWind, y int) (int, int) {
	if !w.ok {
		return 0, 0
	}
	dy := w.originY - y
	dx := phpIntOfFloat(math.Floor(float64(w.speedX * float64(dy))))
	dz := phpIntOfFloat(math.Floor(float64(w.speedZ * float64(dy))))
	return maxInt(-w.maxOffset, minInt(w.maxOffset, dx)), maxInt(-w.maxOffset, minInt(w.maxOffset, dz))
}

func heightAtRadius(d *speleothemData, checkRadius float64) int {
	return phpIntOfFloat(speleothemShapeHeight(checkRadius, float64(d.radius), d.scale, d.bluntness))
}

func (f *speleothemFeature) moveBackUntilEmbedded(c *Ctx, d *speleothemData, w *speleothemWind) bool {
	for d.radius > 1 {
		x, y, z := d.x, d.y, d.z
		maxTries := minInt(10, heightAtRadius(d, 0.0))
		for i := 0; i < maxTries; i++ {
			if int(c.B.BlockOf[c.Level.GetState(x, y, z)]) == c.B.LavaBlock {
				return false
			}
			wx, wz := windOffset(w, y)
			if f.isCircleMostlyEmbeddedInStone(c, x+wx, y, z+wz, d.radius) {
				d.y = y
				return true
			}
			if d.pointingUp {
				y--
			} else {
				y++
			}
		}
		d.radius /= 2
	}
	return false
}

func (f *speleothemFeature) isCircleMostlyEmbeddedInStone(c *Ctx, x, y, z, radius int) bool {
	if f.isEmptyOrWaterOrLava(c, c.Level.GetState(x, y, z)) {
		return false
	}
	increment := fround(6.0 / float64(radius))
	for angle := 0.0; angle < 6.2831854820251465; angle = fround(angle + increment) {
		dx := phpIntOfFloat(fround(float64(MthCos(angle) * float64(radius))))
		dz := phpIntOfFloat(fround(float64(MthSin(angle) * float64(radius))))
		if f.isEmptyOrWaterOrLava(c, c.Level.GetState(x+dx, y, z+dz)) {
			return false
		}
	}
	return true
}

func (f *speleothemFeature) placeLargeBlocks(c *Ctx, d *speleothemData, w *speleothemWind) {
	r := c.R
	level := c.Level
	radius := d.radius
	for dx := -radius; dx <= radius; dx++ {
		for dz := -radius; dz <= radius; dz++ {
			current := fround(math.Sqrt(float64(dx*dx + dz*dz)))
			if current > float64(radius) {
				continue
			}
			height := heightAtRadius(d, current)
			if height <= 0 {
				continue
			}
			if r.NextFloat() < 0.2 {
				height = phpIntOfFloat(fround(float64(float64(height) * fround(fround(float64(r.NextFloat()*fround(1.0-0.800000011920929)))+0.800000011920929))))
			}
			px := d.x + dx
			py := d.y
			pz := d.z + dz
			outOfStone := false
			maxY := math.MaxInt
			if d.pointingUp {
				maxY = level.GetHeight(WorldSurfaceWG, px, pz)
			}
			for i := 0; i < height; i++ {
				if py >= maxY {
					break
				}
				wx, wz := windOffset(w, py)
				state := level.GetState(px+wx, py, pz+wz)
				if f.isEmptyOrWaterOrLava(c, state) {
					outOfStone = true
					level.SetState(px+wx, py, pz+wz, f.baseBlock)
				} else if outOfStone && f.baseStone[c.B.BlockOf[state]] {
					break
				}
				if d.pointingUp {
					py++
				} else {
					py--
				}
			}
		}
	}
}
