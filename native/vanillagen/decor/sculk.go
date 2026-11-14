package decor

import "math"

// type/SculkPatchFeature.php (SculkSpreader.createWorldGenSpreader(), ChargeCursor, SculkVeinBlock).

const (
	sculkGrowthSpawnCost     = 50
	sculkNoGrowthRadius      = 1
	sculkChargeDecayRate     = 5
	sculkAdditionalDecayRate = 10
	sculkMaxCursors          = 32
	sculkMaxCharge           = 1000
	sculkMaxGrowthRateRadius = 24
	sculkMaxWorldgenSpread   = 12

	sculkBehaviourDefault = 0
	sculkBehaviourSculk   = 1
	sculkBehaviourVein    = 2

	spreadSamePosition = 0
	spreadSamePlane    = 1
	spreadWrapAround   = 2
)

var sculkDefaultSpreadOrder = []int{spreadSamePosition, spreadSamePlane, spreadWrapAround}

// sculkNonCornerNeighbours is ChargeCursor.NON_CORNER_NEIGHBOURS: BlockPos.betweenClosed((-1,-1,-1), (1,1,1)) (x
// fastest, then y, then z) with at least one zero coordinate, except the origin.
var sculkNonCornerNeighbours = func() [][3]int {
	var out [][3]int
	for z := -1; z <= 1; z++ {
		for y := -1; y <= 1; y++ {
			for x := -1; x <= 1; x++ {
				if (x == 0 || y == 0 || z == 0) && (x != 0 || y != 0 || z != 0) {
					out = append(out, [3]int{x, y, z})
				}
			}
		}
	}
	return out
}()

type sculkCursor struct {
	x, y, z, charge, updateDelay, decayDelay int
	facings                                  int // bitmask of Direction ordinals, -1 for none (null)
}

type sculkPatchFeature struct {
	chargeCount, amountPerCharge, spreadAttempts, growthRounds, spreadRounds int
	sculk, sculkVein, sculkCatalyst, movingPiston                            int
	sculkState, veinState, sensorState, shriekerState                        int
}

func newSculkPatch(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	f := &sculkPatchFeature{}
	var errs [5]error
	f.chargeCount, errs[0] = jInt(json, "charge_count")
	f.amountPerCharge, errs[1] = jInt(json, "amount_per_charge")
	f.spreadAttempts, errs[2] = jInt(json, "spread_attempts")
	f.growthRounds, errs[3] = jInt(json, "growth_rounds")
	f.spreadRounds, errs[4] = jInt(json, "spread_rounds")
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	f.sculk = b.MustBlock("sculk")
	f.sculkVein = b.MustBlock("sculk_vein")
	f.sculkCatalyst = b.MustBlock("sculk_catalyst")
	f.movingPiston = b.MustBlock("moving_piston")
	f.sculkState = b.MustDefault("sculk")
	f.veinState = b.MustDefault("sculk_vein")
	f.sensorState = b.MustDefault("sculk_sensor")
	f.shriekerState = b.WithBool(b.MustDefault("sculk_shrieker"), "can_summon", true)
	return f, nil
}

func (f *sculkPatchFeature) Place(c *Ctx, x, y, z int) bool {
	if !f.canSpreadFrom(c, x, y, z) {
		return false
	}
	totalRounds := f.spreadRounds + f.growthRounds
	for round := 0; round < totalRounds; round++ {
		var cursors []sculkCursor
		for i := 0; i < f.chargeCount; i++ {
			//SculkSpreader.addCursors()
			charge := f.amountPerCharge
			for charge > 0 {
				current := minInt(charge, sculkMaxCharge)
				if len(cursors) < sculkMaxCursors {
					cursors = append(cursors, sculkCursor{x, y, z, current, 0, 1, -1})
				}
				charge -= current
			}
		}
		spreadVeins := round < f.spreadRounds
		for i := 0; i < f.spreadAttempts && len(cursors) > 0; i++ {
			//SculkSpreader.updateCursors(): world generation never merges cursors
			processed := cursors[:0:0]
			for _, cursor := range cursors {
				if maxInt(absInt(cursor.x-x), maxInt(absInt(cursor.y-y), absInt(cursor.z-z))) > 1024 {
					continue
				}
				f.updateCursor(c, &cursor, x, y, z, spreadVeins)
				if cursor.charge > 0 {
					processed = append(processed, cursor)
				}
			}
			cursors = processed
		}
	}
	return true
}

func (f *sculkPatchFeature) canSpreadFrom(c *Ctx, x, y, z int) bool {
	b := c.B
	start := c.Level.GetState(x, y, z)
	block := int(b.BlockOf[start])
	if block == f.sculk || block == f.sculkVein {
		return true
	}
	if !b.IsAir(start) && !(block == b.WaterBlock && b.FluidIsSource(start)) {
		return false
	}
	for d := 0; d < 6; d++ {
		if b.IsFullCube(c.Level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])) {
			return true
		}
	}
	return false
}

func (f *sculkPatchFeature) behaviour(c *Ctx, state int) int {
	switch int(c.B.BlockOf[state]) {
	case f.sculk:
		return sculkBehaviourSculk
	case f.sculkVein:
		return sculkBehaviourVein
	}
	return sculkBehaviourDefault
}

// updateCursor is ChargeCursor.update().
func (f *sculkPatchFeature) updateCursor(c *Ctx, cursor *sculkCursor, ox, oy, oz int, spreadVeins bool) {
	if cursor.charge <= 0 {
		return
	}
	if cursor.updateDelay > 0 {
		cursor.updateDelay--
		return
	}
	level := c.Level
	x, y, z := cursor.x, cursor.y, cursor.z
	state := level.GetState(x, y, z)
	behaviour := f.behaviour(c, state)
	if spreadVeins && f.attemptSpreadVein(c, behaviour, x, y, z, state, cursor.facings) {
		if behaviour != sculkBehaviourSculk { //canChangeBlockStateOnSpread()
			state = level.GetState(x, y, z)
			behaviour = f.behaviour(c, state)
		}
	}
	cursor.charge = f.attemptUseCharge(c, behaviour, cursor, ox, oy, oz, spreadVeins)
	if cursor.charge <= 0 {
		f.onDischarged(c, behaviour, state, x, y, z)
		return
	}
	tx, ty, tz, ok := f.getValidMovementPos(c, x, y, z, ox, oz)
	if ok {
		f.onDischarged(c, behaviour, state, x, y, z)
		cursor.x, cursor.y, cursor.z = tx, ty, tz
		state = level.GetState(tx, ty, tz)
	} else {
		f.onDischarged(c, behaviour, state, x, y, z)
		cursor.charge = 0
		return
	}
	if f.behaviour(c, state) != sculkBehaviourDefault {
		cursor.facings = f.availableFaces(c, state)
	}
	if behaviour == sculkBehaviourDefault {
		cursor.decayDelay = maxInt(cursor.decayDelay-1, 0)
	} else {
		cursor.decayDelay = 1
	}
	cursor.updateDelay = 1
}

func (f *sculkPatchFeature) hasFace(c *Ctx, state, d int) bool {
	if int(c.B.BlockOf[state]) != f.sculkVein {
		return false
	}
	v, _ := c.B.GetProperty(state, DirNames[d])
	return v == "true"
}

func (f *sculkPatchFeature) availableFaces(c *Ctx, state int) int {
	faces := 0
	if int(c.B.BlockOf[state]) == f.sculkVein {
		for d := 0; d < 6; d++ {
			if f.hasFace(c, state, d) {
				faces |= 1 << d
			}
		}
	}
	return faces
}

func (f *sculkPatchFeature) attemptSpreadVein(c *Ctx, behaviour, x, y, z, state, facings int) bool {
	if behaviour == sculkBehaviourDefault {
		if facings == -1 {
			return f.spreadAll(c, []int{spreadSamePosition}, c.Level.GetState(x, y, z), x, y, z) > 0
		}
		if facings != 0 {
			if c.B.IsAir(state) || (c.B.IsWater(state) && c.B.FluidIsSource(state)) {
				return f.regrow(c, x, y, z, state, facings)
			}
			return false
		}
	}
	return f.spreadAll(c, sculkDefaultSpreadOrder, state, x, y, z) > 0
}

// regrow is SculkVeinBlock.regrow().
func (f *sculkPatchFeature) regrow(c *Ctx, x, y, z, existing, faces int) bool {
	b := c.B
	newState := f.veinState
	any := false
	for d := 0; d < 6; d++ {
		if faces&(1<<d) != 0 && c.Surv.CanAttachTo(c.Level, x, y, z, d) {
			newState = b.WithBool(newState, DirNames[d], true)
			any = true
		}
	}
	if !any {
		return false
	}
	if b.Fluid(existing) != 0 {
		newState = b.WithBool(newState, "waterlogged", true)
	}
	c.Level.SetState(x, y, z, newState)
	return true
}

func (f *sculkPatchFeature) attemptUseCharge(c *Ctx, behaviour int, cursor *sculkCursor, ox, oy, oz int, spreadVeins bool) int {
	charge := cursor.charge
	r := c.R
	if behaviour == sculkBehaviourDefault {
		if cursor.decayDelay > 0 {
			return charge
		}
		return 0
	}
	x, y, z := cursor.x, cursor.y, cursor.z
	if behaviour == sculkBehaviourVein {
		if spreadVeins && f.attemptPlaceSculk(c, x, y, z) {
			return charge - 1
		}
		if r.NextBoundedInt(sculkChargeDecayRate) == 0 {
			return phpIntOfFloat(fround(float64(float64(charge) * 0.5)))
		}
		return charge
	}
	//SculkBlock
	if charge == 0 || r.NextBoundedInt(sculkChargeDecayRate) != 0 {
		return charge
	}
	dx := x - ox
	dy := y - oy
	dz := z - oz
	distSqr := dx*dx + dy*dy + dz*dz
	closeToCatalyst := distSqr < sculkNoGrowthRadius*sculkNoGrowthRadius
	if !closeToCatalyst && f.canPlaceGrowth(c, x, y, z) {
		cost := sculkGrowthSpawnCost
		if r.NextBoundedInt(cost) < charge {
			gy := y + 1
			growth := f.sensorState
			if r.NextBoundedInt(11) == 0 {
				growth = f.shriekerState
			}
			if c.B.Fluid(c.Level.GetState(x, gy, z)) != 0 {
				growth = c.B.WithBool(growth, "waterlogged", true)
			}
			c.Level.SetState(x, gy, z, growth)
		}
		return maxInt(0, charge-cost)
	}
	if r.NextBoundedInt(sculkAdditionalDecayRate) != 0 {
		return charge
	}
	if closeToCatalyst {
		return charge - 1
	}
	return charge - sculkDecayPenalty(distSqr, charge)
}

func sculkDecayPenalty(distSqr, charge int) int {
	outer := fround(fround(math.Sqrt(float64(distSqr))) - float64(sculkNoGrowthRadius))
	outerSquared := fround(float64(outer * outer))
	maxReachSquared := float64((sculkMaxGrowthRateRadius - sculkNoGrowthRadius) * (sculkMaxGrowthRateRadius - sculkNoGrowthRadius))
	distanceFactor := fround(outerSquared / maxReachSquared)
	if 1.0 < distanceFactor {
		distanceFactor = 1.0
	}
	return maxInt(1, phpIntOfFloat(fround(float64(fround(float64(float64(charge)*distanceFactor))*0.5))))
}

func (f *sculkPatchFeature) canPlaceGrowth(c *Ctx, x, y, z int) bool {
	b := c.B
	level := c.Level
	above := level.GetState(x, y+1, z)
	if !b.IsAir(above) && !(int(b.BlockOf[above]) == b.WaterBlock && b.FluidIsSource(above)) {
		return false
	}
	found := 0
	for xx := x - 4; xx <= x+4; xx++ {
		for yy := y; yy <= y+2; yy++ {
			for zz := z - 4; zz <= z+4; zz++ {
				if b.IsTagged(level.GetState(xx, yy, zz), "sculk_growth_inhibitors") {
					found++
					if found > 2 {
						return false
					}
				}
			}
		}
	}
	return true
}

// attemptPlaceSculk is SculkVeinBlock.attemptPlaceSculk().
func (f *sculkPatchFeature) attemptPlaceSculk(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	state := level.GetState(x, y, z)
	for _, support := range utilShuffle([]int{0, 1, 2, 3, 4, 5}, c.R) {
		if !f.hasFace(c, state, support) {
			continue
		}
		sx, sy, sz := x+StepX[support], y+StepY[support], z+StepZ[support]
		if !b.IsTagged(level.GetState(sx, sy, sz), "sculk_replaceable_world_gen") {
			continue
		}
		level.SetState(sx, sy, sz, f.sculkState)
		f.spreadAll(c, sculkDefaultSpreadOrder, f.sculkState, sx, sy, sz)
		skip := Opposite[support]
		for d := 0; d < 6; d++ {
			if d == skip {
				continue
			}
			vx, vy, vz := sx+StepX[d], sy+StepY[d], sz+StepZ[d]
			vein := level.GetState(vx, vy, vz)
			if int(b.BlockOf[vein]) == f.sculkVein {
				f.onDischarged(c, sculkBehaviourVein, vein, vx, vy, vz)
			}
		}
		return true
	}
	return false
}

// onDischarged is SculkVeinBlock.onDischarged(): faces against sculk are removed.
func (f *sculkPatchFeature) onDischarged(c *Ctx, behaviour, state, x, y, z int) {
	b := c.B
	if behaviour != sculkBehaviourVein || int(b.BlockOf[state]) != f.sculkVein {
		return
	}
	level := c.Level
	any := false
	for d := 0; d < 6; d++ {
		if !f.hasFace(c, state, d) {
			continue
		}
		if int(b.BlockOf[level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])]) == f.sculk {
			state = b.WithBool(state, DirNames[d], false)
		} else {
			any = true
		}
	}
	if !any {
		if b.Fluid(level.GetState(x, y, z)) != 0 {
			state = b.Water
		} else {
			state = b.Air
		}
	}
	level.SetState(x, y, z, state)
}

// getValidMovementPos is ChargeCursor.getValidMovementPos().
func (f *sculkPatchFeature) getValidMovementPos(c *Ctx, x, y, z, ox, oz int) (int, int, int, bool) {
	level := c.Level
	b := c.B
	r := c.R
	offsets := append([][3]int(nil), sculkNonCornerNeighbours...)
	for i := len(offsets); i > 1; i-- {
		swapTo := r.NextBoundedInt(i)
		offsets[i-1], offsets[swapTo] = offsets[swapTo], offsets[i-1]
	}
	rx, ry, rz, found := 0, 0, 0, false
	for _, o := range offsets {
		dx, dy, dz := o[0], o[1], o[2]
		nx, ny, nz := x+dx, y+dy, z+dz
		mx := ox - nx
		mz := oz - nz
		if mx*mx+mz*mz > sculkMaxWorldgenSpread*sculkMaxWorldgenSpread {
			continue
		}
		transferee := level.GetState(nx, ny, nz)
		block := int(b.BlockOf[transferee])
		if (block != f.sculk && block != f.sculkVein) || !f.isMovementUnobstructed(c, x, y, z, dx, dy, dz) {
			continue
		}
		rx, ry, rz, found = nx, ny, nz, true
		if f.hasSubstrateAccess(c, transferee, nx, ny, nz) {
			break
		}
	}
	return rx, ry, rz, found
}

func (f *sculkPatchFeature) hasSubstrateAccess(c *Ctx, state, x, y, z int) bool {
	if int(c.B.BlockOf[state]) != f.sculkVein {
		return false
	}
	for d := 0; d < 6; d++ {
		if f.hasFace(c, state, d) && c.B.IsTagged(c.Level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d]), "sculk_replaceable") {
			return true
		}
	}
	return false
}

func (f *sculkPatchFeature) isMovementUnobstructed(c *Ctx, x, y, z, dx, dy, dz int) bool {
	if absInt(dx)+absInt(dy)+absInt(dz) == 1 {
		return true
	}
	directionX, directionY, directionZ := East, Up, South
	if dx < 0 {
		directionX = West
	}
	if dy < 0 {
		directionY = Down
	}
	if dz < 0 {
		directionZ = North
	}
	if dx == 0 {
		return f.isUnobstructed(c, x, y, z, directionY) || f.isUnobstructed(c, x, y, z, directionZ)
	}
	if dy == 0 {
		return f.isUnobstructed(c, x, y, z, directionX) || f.isUnobstructed(c, x, y, z, directionZ)
	}
	return f.isUnobstructed(c, x, y, z, directionX) || f.isUnobstructed(c, x, y, z, directionY)
}

func (f *sculkPatchFeature) isUnobstructed(c *Ctx, x, y, z, d int) bool {
	state := c.Level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])
	return !c.B.IsFaceSturdy(state, Opposite[d])
}

// spreadAll is MultifaceSpreader.spreadAll() with SculkVeinSpreaderConfig.
func (f *sculkPatchFeature) spreadAll(c *Ctx, spreadTypes []int, state, x, y, z int) int {
	total := 0
	otherSource := int(c.B.BlockOf[state]) != f.sculkVein
	for face := 0; face < 6; face++ {
		if !otherSource && !f.hasFace(c, state, face) {
			continue
		}
		for spread := 0; spread < 6; spread++ {
			if f.spreadFromFaceTowardDirection(c, spreadTypes, otherSource, state, x, y, z, face, spread) {
				total++
			}
		}
	}
	return total
}

func (f *sculkPatchFeature) spreadFromFaceTowardDirection(c *Ctx, spreadTypes []int, otherSource bool, state, x, y, z, startingFace, spread int) bool {
	if DirAxis[spread] == DirAxis[startingFace] {
		return false
	}
	if !otherSource && (!f.hasFace(c, state, startingFace) || f.hasFace(c, state, spread)) {
		return false
	}
	for _, t := range spreadTypes {
		var px, py, pz, face int
		switch t {
		case spreadSamePosition:
			px, py, pz, face = x, y, z, spread
		case spreadSamePlane:
			px, py, pz, face = x+StepX[spread], y+StepY[spread], z+StepZ[spread], startingFace
		default:
			px = x + StepX[spread] + StepX[startingFace]
			py = y + StepY[spread] + StepY[startingFace]
			pz = z + StepZ[spread] + StepZ[startingFace]
			face = Opposite[spread]
		}
		existing := c.Level.GetState(px, py, pz)
		if f.stateCanBeReplaced(c, x, y, z, px, py, pz, face, existing) && f.isValidStateForPlacement(c, existing, px, py, pz, face) {
			//SpreadConfig.placeBlock()
			newState := f.getStateForPlacement(c, existing, px, py, pz, face)
			if newState < 0 {
				return false
			}
			return c.Level.SetState(px, py, pz, newState)
		}
	}
	return false
}

func (f *sculkPatchFeature) isValidStateForPlacement(c *Ctx, oldState, x, y, z, d int) bool {
	if f.hasFace(c, oldState, d) {
		return false
	}
	return c.Surv.CanAttachTo(c.Level, x, y, z, d)
}

func (f *sculkPatchFeature) getStateForPlacement(c *Ctx, oldState, x, y, z, d int) int {
	if !f.isValidStateForPlacement(c, oldState, x, y, z, d) {
		return -1
	}
	b := c.B
	var state int
	if int(b.BlockOf[oldState]) == f.sculkVein {
		state = oldState
	} else if b.IsWater(oldState) && b.FluidIsSource(oldState) {
		state = b.WithBool(f.veinState, "waterlogged", true)
	} else {
		state = f.veinState
	}
	return b.WithBool(state, DirNames[d], true)
}

// stateCanBeReplaced is SculkVeinSpreaderConfig.stateCanBeReplaced().
func (f *sculkPatchFeature) stateCanBeReplaced(c *Ctx, sx, sy, sz, x, y, z, face, existing int) bool {
	b := c.B
	level := c.Level
	against := int(b.BlockOf[level.GetState(x+StepX[face], y+StepY[face], z+StepZ[face])])
	if against == f.sculk || against == f.sculkCatalyst || against == f.movingPiston {
		return false
	}
	if absInt(sx-x)+absInt(sy-y)+absInt(sz-z) == 2 {
		o := Opposite[face]
		if b.IsFaceSturdy(level.GetState(sx+StepX[o], sy+StepY[o], sz+StepZ[o]), face) {
			return false
		}
	}
	if b.Fluid(existing) != 0 && !b.IsWater(existing) {
		return false
	}
	if b.IsTagged(existing, "fire") {
		return false
	}
	if b.IsTagged(existing, "replaceable") {
		return true
	}
	return b.IsAir(existing) || int(b.BlockOf[existing]) == f.sculkVein || (int(b.BlockOf[existing]) == b.WaterBlock && b.FluidIsSource(existing))
}
