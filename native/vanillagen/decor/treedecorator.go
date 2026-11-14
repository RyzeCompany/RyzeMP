package decor

import (
	"encoding/binary"
	"fmt"
)

// type/tree/TreeDecorator.php and TreeDecoratorContext.php

type treeDecoratorContext struct {
	c                   *Ctx
	b                   *Blocks
	tree                *treeRun // nil: decorations go straight to the level
	logs, leaves, roots []pos
}

func (ctx *treeDecoratorContext) isAir(x, y, z int) bool {
	return ctx.b.IsAir(ctx.c.Level.GetState(x, y, z))
}

func (ctx *treeDecoratorContext) setBlock(x, y, z, state int) {
	if ctx.tree != nil {
		ctx.tree.setDecoration(x, y, z, state)
	} else {
		ctx.c.Level.SetState(x, y, z, state)
	}
}

// lowestTrunkOrRoot is TreeFeature.getLowestTrunkOrRootOfTree().
func (ctx *treeDecoratorContext) lowestTrunkOrRoot() []pos {
	if len(ctx.roots) == 0 {
		return ctx.logs
	}
	if len(ctx.logs) > 0 && ctx.roots[0][1] == ctx.logs[0][1] {
		out := append([]pos(nil), ctx.logs...)
		return append(out, ctx.roots...)
	}
	return ctx.roots
}

type treeDecorator struct {
	typ                                 string
	probability                         float64
	provider                            StateProvider
	tries, radius, height               int
	exclusionRadiusXZ, exclusionRadiusY int
	requiredEmptyBlocks                 int
	directions                          []int
	trunkProbability, groundProbability float64
	reg                                 *Registry
	paleMossPatch                       Feature
}

func newTreeDecorator(reg *Registry, js any) (*treeDecorator, error) {
	json, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid tree decorator")
	}
	t, err := typeOf(json, "type")
	if err != nil {
		return nil, err
	}
	d := &treeDecorator{typ: t, tries: 128, radius: 2, height: 1, requiredEmptyBlocks: 1, reg: reg}
	var errs [8]error
	prob := func(key string) float64 {
		v, err := jFloat(json, key)
		if err != nil {
			errs[7] = err
		}
		return fround(v)
	}
	switch t {
	case "beehive", "cocoa", "leave_vine", "shelf_mushroom", "creaking_heart":
		d.probability = prob("probability")
	case "trunk_vine":
	case "place_on_ground":
		d.tries, errs[0] = jIntDef(json, "tries", 128)
		d.radius, errs[1] = jIntDef(json, "radius", 2)
		d.height, errs[2] = jIntDef(json, "height", 1)
		d.provider, errs[3] = reg.ParseStateProvider(json["block_state_provider"])
	case "attached_to_leaves":
		d.probability = prob("probability")
		d.exclusionRadiusXZ, errs[0] = jInt(json, "exclusion_radius_xz")
		d.exclusionRadiusY, errs[1] = jInt(json, "exclusion_radius_y")
		d.requiredEmptyBlocks, errs[2] = jInt(json, "required_empty_blocks")
		d.provider, errs[3] = reg.ParseStateProvider(json["block_provider"])
		d.directions, errs[4] = treeDecoratorDirections(json["directions"])
	case "attached_to_logs":
		d.probability = prob("probability")
		d.provider, errs[3] = reg.ParseStateProvider(json["block_provider"])
		d.directions, errs[4] = treeDecoratorDirections(json["directions"])
	case "alter_ground":
		d.provider, errs[3] = reg.ParseStateProvider(json["provider"])
	case "pale_moss":
		d.probability = prob("leaves_probability")
		d.trunkProbability = prob("trunk_probability")
		d.groundProbability = prob("ground_probability")
		d.paleMossPatch, errs[5] = reg.ConfiguredRef("minecraft:pale_moss_patch")
	default:
		return nil, fmt.Errorf("unknown tree decorator %s", t)
	}
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	return d, nil
}

func treeDecoratorDirections(js any) ([]int, error) {
	var out []int
	list, _ := js.([]any)
	for _, v := range list {
		if s, ok := v.(string); ok {
			d, err := directionFromName(s)
			if err != nil {
				return nil, err
			}
			out = append(out, d)
		}
	}
	return out, nil
}

// shuffledCopy is Util.shuffledCopy().
func shuffledCopy(list []pos, r *Random) []pos {
	out := append([]pos(nil), list...)
	for i := len(out); i > 1; i-- {
		swapTo := r.NextBoundedInt(i)
		out[i-1], out[swapTo] = out[swapTo], out[i-1]
	}
	return out
}

var vineFaces = [4]struct {
	d    int
	face string
}{{West, "east"}, {East, "west"}, {North, "south"}, {South, "north"}}

func (d *treeDecorator) place(ctx *treeDecoratorContext) {
	c := ctx.c
	r := c.R
	b := ctx.b
	switch d.typ {
	case "beehive":
		d.placeBeehive(ctx, r)
	case "trunk_vine":
		for _, l := range ctx.logs {
			for _, v := range vineFaces {
				if r.NextBoundedInt(3) > 0 {
					vx := l[0] + StepX[v.d]
					vz := l[2] + StepZ[v.d]
					if ctx.isAir(vx, l[1], vz) {
						d.placeVine(ctx, vx, l[1], vz, v.face)
					}
				}
			}
		}
	case "leave_vine":
		for _, l := range ctx.leaves {
			for _, v := range vineFaces {
				if r.NextFloat() < d.probability {
					vx := l[0] + StepX[v.d]
					vz := l[2] + StepZ[v.d]
					if ctx.isAir(vx, l[1], vz) {
						d.addHangingVine(ctx, vx, l[1], vz, v.face)
					}
				}
			}
		}
	case "cocoa":
		if !(r.NextFloat() < d.probability) || len(ctx.logs) == 0 {
			return
		}
		treeY := ctx.logs[0][1]
		for _, l := range ctx.logs {
			x, y, z := l[0], l[1], l[2]
			if y-treeY > 2 {
				continue
			}
			for _, direction := range PlaneHorizontal {
				if r.NextFloat() <= 0.25 {
					opposite := Opposite[direction]
					cx := x + StepX[opposite]
					cz := z + StepZ[opposite]
					if ctx.isAir(cx, y, cz) {
						state := b.MustDefault("cocoa")
						state = b.WithInt(state, "age", r.NextBoundedInt(3))
						state = b.With(state, "facing", DirNames[direction])
						ctx.setBlock(cx, y, cz, state)
					}
				}
			}
		}
	case "place_on_ground":
		positions := ctx.lowestTrunkOrRoot()
		if len(positions) == 0 {
			return
		}
		ox, minY, oz := positions[0][0], positions[0][1], positions[0][2]
		minX, maxX, minZ, maxZ := ox, ox, oz, oz
		for _, p := range positions {
			if p[1] == minY {
				minX = minInt(minX, p[0])
				maxX = maxInt(maxX, p[0])
				minZ = minInt(minZ, p[2])
				maxZ = maxInt(maxZ, p[2])
			}
		}
		bx0, bx1 := minX-d.radius, maxX+d.radius
		by0, by1 := minY-d.height, minY+d.height
		bz0, bz1 := minZ-d.radius, maxZ+d.radius
		for i := 0; i < d.tries; i++ {
			x := r.NextIntBetweenInclusive(bx0, bx1)
			y := r.NextIntBetweenInclusive(by0, by1)
			z := r.NextIntBetweenInclusive(bz0, bz1)
			above := c.Level.GetState(x, y+1, z)
			if (b.IsAir(above) || b.BlockName(above) == "minecraft:vine") &&
				c.Surv.IsSolidRender(c.Level.GetState(x, y, z)) &&
				c.Level.GetHeight(MotionBlockingNoLeaves, x, z) <= y+1 {
				if d.provider != nil {
					if state := d.provider(c, r, x, y+1, z); state >= 0 {
						ctx.setBlock(x, y+1, z, state)
					}
				}
			}
		}
	case "attached_to_leaves":
		blacklist := map[int64]bool{}
		for _, l := range shuffledCopy(ctx.leaves, r) {
			x, y, z := l[0], l[1], l[2]
			direction := d.directions[r.NextBoundedInt(len(d.directions))]
			px := x + StepX[direction]
			py := y + StepY[direction]
			pz := z + StepZ[direction]
			if blacklist[posKey(px, py, pz)] {
				continue
			}
			if !(r.NextFloat() < d.probability) || !d.hasRequiredEmptyBlocks(ctx, x, y, z, direction) {
				continue
			}
			for ex := -d.exclusionRadiusXZ; ex <= d.exclusionRadiusXZ; ex++ {
				for ey := -d.exclusionRadiusY; ey <= d.exclusionRadiusY; ey++ {
					for ez := -d.exclusionRadiusXZ; ez <= d.exclusionRadiusXZ; ez++ {
						blacklist[posKey(px+ex, py+ey, pz+ez)] = true
					}
				}
			}
			if d.provider != nil {
				if state := d.provider(c, r, px, py, pz); state >= 0 {
					ctx.setBlock(px, py, pz, state)
				}
			}
		}
	case "attached_to_logs":
		for _, l := range shuffledCopy(ctx.logs, r) {
			direction := d.directions[r.NextBoundedInt(len(d.directions))]
			px := l[0] + StepX[direction]
			py := l[1] + StepY[direction]
			pz := l[2] + StepZ[direction]
			if r.NextFloat() > d.probability || !ctx.isAir(px, py, pz) {
				continue
			}
			if d.provider != nil {
				if state := d.provider(c, r, px, py, pz); state >= 0 {
					ctx.setBlock(px, py, pz, state)
				}
			}
		}
	case "alter_ground":
		positions := ctx.lowestTrunkOrRoot()
		if len(positions) == 0 {
			return
		}
		minY := positions[0][1]
		for _, p := range positions {
			x, y, z := p[0], p[1], p[2]
			if y != minY {
				continue
			}
			d.placeCircle(ctx, x-1, y, z-1)
			d.placeCircle(ctx, x+2, y, z-1)
			d.placeCircle(ctx, x-1, y, z+2)
			d.placeCircle(ctx, x+2, y, z+2)
			for i := 0; i < 5; i++ {
				placement := r.NextBoundedInt(64)
				xx := placement % 8
				zz := placement / 8
				if xx == 0 || xx == 7 || zz == 0 || zz == 7 {
					d.placeCircle(ctx, x-3+xx, y, z-3+zz)
				}
			}
		}
	case "shelf_mushroom":
		d.placeShelfMushrooms(ctx, r)
	case "pale_moss":
		d.placePaleMoss(ctx, r)
	case "creaking_heart":
		if len(ctx.logs) == 0 || !(r.NextFloat() < d.probability) {
			return
		}
		logTag := b.TagMask("minecraft:logs")
		for _, l := range shuffledCopy(ctx.logs, r) {
			ok := true
			for dd := 0; dd < 6; dd++ {
				n := c.Level.GetState(l[0]+StepX[dd], l[1]+StepY[dd], l[2]+StepZ[dd])
				if !logTag[b.BlockOf[n]] {
					ok = false
					break
				}
			}
			if ok {
				state := b.With(b.MustDefault("creaking_heart"), "creaking_heart_state", "dormant")
				state = b.WithBool(state, "natural", true)
				ctx.setBlock(l[0], l[1], l[2], state)
				return
			}
		}
	}
}

func (d *treeDecorator) placeBeehive(ctx *treeDecoratorContext, r *Random) {
	logs := ctx.logs
	leaves := ctx.leaves
	if len(logs) == 0 || !(r.NextFloat() < d.probability) {
		return
	}
	var hiveY int
	if len(leaves) > 0 {
		hiveY = maxInt(leaves[0][1]-1, logs[0][1]+1)
	} else {
		hiveY = minInt(logs[0][1]+1+r.NextBoundedInt(3), logs[len(logs)-1][1])
	}
	var candidates []pos
	for _, l := range logs {
		if l[1] == hiveY {
			for _, dd := range [3]int{East, South, West} {
				candidates = append(candidates, pos{l[0] + StepX[dd], l[1], l[2] + StepZ[dd]})
			}
		}
	}
	if len(candidates) == 0 {
		return
	}
	candidates = shuffledCopy(candidates, r)
	b := ctx.b
	for _, p := range candidates {
		x, y, z := p[0], p[1], p[2]
		if ctx.isAir(x, y, z) && ctx.isAir(x, y, z+1) {
			ctx.setBlock(x, y, z, b.With(b.MustDefault("bee_nest"), "facing", "south"))
			level := ctx.c.Level
			if !level.EnsureCanWrite(x, y, z) {
				//no nest, so no block entity to put bees in
				return
			}
			//2 or 3 bees that have been inside for 0-598 ticks
			bees := 2 + r.NextBoundedInt(2)
			rec := []byte{BlockEntityBeehive, byte(bees)}
			for i := 0; i < bees; i++ {
				rec = binary.LittleEndian.AppendUint16(rec, uint16(r.NextBoundedInt(599)))
			}
			level.SetBlockEntity(x, y, z, rec)
			return
		}
	}
}

func (d *treeDecorator) placeVine(ctx *treeDecoratorContext, x, y, z int, face string) {
	ctx.setBlock(x, y, z, ctx.b.WithBool(ctx.b.MustDefault("vine"), face, true))
}

func (d *treeDecorator) addHangingVine(ctx *treeDecoratorContext, x, y, z int, face string) {
	d.placeVine(ctx, x, y, z, face)
	max := 4
	for y--; ctx.isAir(x, y, z) && max > 0; y, max = y-1, max-1 {
		d.placeVine(ctx, x, y, z, face)
	}
}

func (d *treeDecorator) hasRequiredEmptyBlocks(ctx *treeDecoratorContext, x, y, z, direction int) bool {
	for i := 1; i <= d.requiredEmptyBlocks; i++ {
		if !ctx.isAir(x+StepX[direction]*i, y+StepY[direction]*i, z+StepZ[direction]*i) {
			return false
		}
	}
	return true
}

func (d *treeDecorator) placeCircle(ctx *treeDecoratorContext, x, y, z int) {
	for xx := -2; xx <= 2; xx++ {
		for zz := -2; zz <= 2; zz++ {
			if absInt(xx) == 2 && absInt(zz) == 2 {
				continue
			}
			d.placeBlockAt(ctx, x+xx, y, z+zz)
		}
	}
}

func (d *treeDecorator) placeBlockAt(ctx *treeDecoratorContext, x, y, z int) {
	c := ctx.c
	for dy := 2; dy >= -3; dy-- {
		cy := y + dy
		state := -1
		if d.provider != nil {
			state = d.provider(c, c.R, x, cy, z)
		}
		if state >= 0 {
			ctx.setBlock(x, cy, z, state)
			return
		}
		if !ctx.isAir(x, cy, z) && dy < 0 {
			return
		}
	}
}

func (d *treeDecorator) placeShelfMushrooms(ctx *treeDecoratorContext, r *Random) {
	if !(r.NextFloat() < d.probability) {
		return
	}
	logs := ctx.logs
	if len(logs) == 0 {
		return
	}
	first := logs[0]
	last := logs[len(logs)-1]
	if first[1] == last[1] {
		//fallen log
		directions := []int{East, West}
		if first[0] != last[0] {
			directions = []int{North, South}
		}
		for _, l := range logs {
			x, y, z := l[0], l[1], l[2]
			for _, facing := range directions {
				if r.NextFloat() <= 0.25 {
					mx := x + StepX[facing]
					mz := z + StepZ[facing]
					if d.shelfReplaceable(ctx, mx, y, mz) && !d.adjacentShelf(ctx, mx, y, mz) && !d.adjacentShelf(ctx, x, y, z) {
						d.placeShelf(ctx, r, mx, y, mz, facing)
					}
				}
			}
		}
		return
	}
	firstDir := PlaneHorizontal[r.NextBoundedInt(4)]
	directions := []int{firstDir, clockWise(firstDir)}
	baseY := first[1]
	for _, l := range logs {
		x, y, z := l[0], l[1], l[2]
		dy := y - baseY
		if dy < 1 || dy > 4 {
			continue
		}
		for _, facing := range directions {
			if r.NextFloat() <= 0.25 {
				mx := x + StepX[facing]
				mz := z + StepZ[facing]
				if d.shelfReplaceable(ctx, mx, y, mz) && !d.isShelf(ctx, mx, y-1, mz) {
					d.placeShelf(ctx, r, mx, y, mz, facing)
					break
				}
			}
		}
	}
}

func (d *treeDecorator) placeShelf(ctx *treeDecoratorContext, r *Random, x, y, z, facing int) {
	b := ctx.b
	state := b.WithInt(b.MustDefault("shelf_mushroom"), "age", r.NextBoundedInt(2))
	ctx.setBlock(x, y, z, b.With(state, "facing", DirNames[facing]))
}

func (d *treeDecorator) isShelf(ctx *treeDecoratorContext, x, y, z int) bool {
	return ctx.b.BlockName(ctx.c.Level.GetState(x, y, z)) == "minecraft:shelf_mushroom"
}

func (d *treeDecorator) adjacentShelf(ctx *treeDecoratorContext, x, y, z int) bool {
	for _, dd := range PlaneHorizontal {
		if d.isShelf(ctx, x+StepX[dd], y, z+StepZ[dd]) {
			return true
		}
	}
	return false
}

func (d *treeDecorator) shelfReplaceable(ctx *treeDecoratorContext, x, y, z int) bool {
	b := ctx.b
	level := ctx.c.Level
	if !b.IsTagged(level.GetState(x, y, z), "replaceable") {
		return false
	}
	for _, o := range [5][2]int{{0, 0}, {1, 0}, {-1, 0}, {0, -1}, {0, 1}} {
		if int(b.BlockOf[level.GetState(x+o[0], y, z+o[1])]) == b.WaterBlock {
			return false
		}
	}
	return true
}

func (d *treeDecorator) placePaleMoss(ctx *treeDecoratorContext, r *Random) {
	c := ctx.c
	logs := shuffledCopy(ctx.logs, r)
	if len(logs) == 0 {
		return
	}
	origin := logs[0]
	for _, p := range logs {
		if p[1] < origin[1] {
			origin = p
		}
	}
	if r.NextFloat() < d.groundProbability {
		d.paleMossPatch.Place(c, origin[0], origin[1]+1, origin[2])
	}
	for _, l := range ctx.logs {
		if r.NextFloat() < d.trunkProbability && ctx.isAir(l[0], l[1]-1, l[2]) {
			d.addMossHanger(ctx, r, l[0], l[1]-1, l[2])
		}
	}
	for _, l := range ctx.leaves {
		if r.NextFloat() < d.probability && ctx.isAir(l[0], l[1]-1, l[2]) {
			d.addMossHanger(ctx, r, l[0], l[1]-1, l[2])
		}
	}
}

func (d *treeDecorator) addMossHanger(ctx *treeDecoratorContext, r *Random, x, y, z int) {
	b := ctx.b
	moss := b.MustDefault("pale_hanging_moss")
	for ctx.isAir(x, y-1, z) && r.NextFloat() >= 0.5 {
		ctx.setBlock(x, y, z, b.WithBool(moss, "tip", false))
		y--
	}
	ctx.setBlock(x, y, z, b.WithBool(moss, "tip", true))
}
