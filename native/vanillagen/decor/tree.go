package decor

import (
	"fmt"
	"sort"
)

// type/tree/TreeFeature.php. The feature is shared (parsed once per session); the state of one placement lives in
// a treeRun.
type treeFeature struct {
	trunkProvider, foliageProvider, belowTrunkProvider StateProvider
	trunk                                              *trunkPlacer
	foliage                                            *foliagePlacer
	root                                               *rootPlacer
	decorators                                         []*treeDecorator
	ignoreVines                                        bool

	sizeType                                                    int
	sizeLimit, sizeUpperLimit, sizeLower, sizeMiddle, sizeUpper int
	minClippedHeight                                            int
	hasMinClippedHeight                                         bool

	replaceableByTrees, logs, leaves []bool
	vine                             int
}

type treeRun struct {
	f                                   *treeFeature
	c                                   *Ctx
	roots, trunks, foliage, decorations *posSet
}

func newTree(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	f := &treeFeature{}
	var err error
	if f.trunkProvider, err = reg.ParseStateProvider(json["trunk_provider"]); err != nil {
		return nil, err
	}
	if f.foliageProvider, err = reg.ParseStateProvider(json["foliage_provider"]); err != nil {
		return nil, err
	}
	below := json["below_trunk_provider"]
	if below == nil {
		below = "minecraft:soil_beneath_tree"
	}
	if f.belowTrunkProvider, err = reg.ParseStateProvider(below); err != nil {
		return nil, err
	}
	tp, ok := asObj(json["trunk_placer"])
	if !ok {
		return nil, errExpected("trunk_placer")
	}
	if f.trunk, err = newTrunkPlacer(reg, tp); err != nil {
		return nil, err
	}
	fp, ok := asObj(json["foliage_placer"])
	if !ok {
		return nil, errExpected("foliage_placer")
	}
	if f.foliage, err = newFoliagePlacer(fp); err != nil {
		return nil, err
	}
	if rp, ok := asObj(json["root_placer"]); ok {
		if f.root, err = newRootPlacer(reg, rp); err != nil {
			return nil, err
		}
	}
	if list, ok := json["decorators"].([]any); ok {
		for _, d := range list {
			dec, err := newTreeDecorator(reg, d)
			if err != nil {
				return nil, err
			}
			f.decorators = append(f.decorators, dec)
		}
	}
	f.ignoreVines = jBoolDef(json, "ignore_vines", false)
	size, ok := asObj(json["minimum_size"])
	if !ok {
		return nil, errExpected("minimum_size")
	}
	t, err := typeOf(size, "type")
	if err != nil {
		return nil, err
	}
	f.sizeType = 2
	if t == "three_layers_feature_size" {
		f.sizeType = 3
	}
	var errs [6]error
	f.sizeLimit, errs[0] = jIntDef(size, "limit", 1)
	f.sizeUpperLimit, errs[1] = jIntDef(size, "upper_limit", 1)
	f.sizeLower, errs[2] = jIntDef(size, "lower_size", 0)
	f.sizeMiddle, errs[3] = jIntDef(size, "middle_size", 1)
	f.sizeUpper, errs[4] = jIntDef(size, "upper_size", 1)
	if size["min_clipped_height"] != nil {
		f.hasMinClippedHeight = true
		f.minClippedHeight, errs[5] = jInt(size, "min_clipped_height")
	}
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	f.replaceableByTrees = b.TagMask("minecraft:replaceable_by_trees")
	f.logs = b.TagMask("minecraft:logs")
	f.leaves = b.TagMask("minecraft:leaves")
	if f.vine, err = b.BlockIndex("vine"); err != nil {
		return nil, err
	}
	return f, nil
}

func (f *treeFeature) Place(c *Ctx, x, y, z int) bool {
	t := &treeRun{f: f, c: c, roots: newPosSet(), trunks: newPosSet(), foliage: newPosSet(), decorations: newPosSet()}
	if !t.doPlace(x, y, z) {
		return false
	}
	if t.trunks.Len() == 0 && t.foliage.Len() == 0 {
		return false
	}
	if len(f.decorators) > 0 {
		ctx := &treeDecoratorContext{c: c, b: c.B, tree: t, logs: sortedByY(t.trunks), leaves: sortedByY(t.foliage), roots: sortedByY(t.roots)}
		for _, d := range f.decorators {
			d.place(ctx)
		}
	}
	//leaf distances (TreeFeature.updateLeaves()) are not kept: Bedrock leaves have no distance
	return true
}

// sortedByY is new ObjectArrayList(hashSet) sorted (stably) by Y, like TreeDecorator.Context.
func sortedByY(set *posSet) []pos {
	list := set.IterationOrder()
	sort.SliceStable(list, func(a, b int) bool { return list[a][1] < list[b][1] })
	return list
}

func (t *treeRun) doPlace(x, y, z int) bool {
	f := t.f
	r := t.c.R
	treeHeight := f.trunk.getTreeHeight(r)
	foliageHeight := f.foliage.foliageHeight(r, treeHeight)
	trunkHeight := treeHeight - foliageHeight
	leafRadius := f.foliage.foliageRadius(r, trunkHeight)
	ty := y
	if f.root != nil {
		ty = y + f.root.trunkOffsetY.Sample(r)
	}
	minY := minInt(y, ty)
	maxY := maxInt(y, ty) + treeHeight + 1
	if minY < t.c.Level.MinY+1 || maxY > t.c.Level.MaxY {
		return false
	}
	clipped := t.getMaxFreeTreeHeight(treeHeight, x, ty, z)
	if clipped < treeHeight && (!f.hasMinClippedHeight || clipped < f.minClippedHeight) {
		return false
	}
	if f.root != nil && !f.root.placeRoots(t, x, y, z, x, ty, z) {
		return false
	}
	attachments := f.trunk.placeTrunk(t, clipped, x, ty, z)
	for _, a := range attachments {
		f.foliage.createFoliage(t, clipped, a, foliageHeight, leafRadius)
	}
	return true
}

func (t *treeRun) getSizeAtHeight(treeHeight, yo int) int {
	f := t.f
	if f.sizeType == 2 {
		if yo < f.sizeLimit {
			return f.sizeLower
		}
		return f.sizeUpper
	}
	if yo < f.sizeLimit {
		return f.sizeLower
	}
	if yo >= treeHeight-f.sizeUpperLimit {
		return f.sizeUpper
	}
	return f.sizeMiddle
}

func (t *treeRun) getMaxFreeTreeHeight(maxTreeHeight, x, y, z int) int {
	level := t.c.Level
	for yo := 0; yo <= maxTreeHeight+1; yo++ {
		r := t.getSizeAtHeight(maxTreeHeight, yo)
		for dx := -r; dx <= r; dx++ {
			for dz := -r; dz <= r; dz++ {
				if !t.f.trunk.isFree(t, x+dx, y+yo, z+dz) || (!t.f.ignoreVines && int(t.c.B.BlockOf[level.GetState(x+dx, y+yo, z+dz)]) == t.f.vine) {
					return yo - 2
				}
			}
		}
	}
	return maxTreeHeight
}

// validTreePos is TreeFeature.validTreePos(): air or replaceable by trees.
func (t *treeRun) validTreePos(x, y, z int) bool {
	state := t.c.Level.GetState(x, y, z)
	return t.c.B.IsAir(state) || t.f.replaceableByTrees[t.c.B.BlockOf[state]]
}

func (t *treeRun) isLog(x, y, z int) bool {
	return t.f.logs[t.c.B.BlockOf[t.c.Level.GetState(x, y, z)]]
}

func (t *treeRun) isAirOrLeaves(x, y, z int) bool {
	state := t.c.Level.GetState(x, y, z)
	return t.c.B.IsAir(state) || t.f.leaves[t.c.B.BlockOf[state]]
}

func (t *treeRun) setTrunk(x, y, z, state int) {
	t.trunks.Add(x, y, z)
	t.c.Level.SetState(x, y, z, state)
}

func (t *treeRun) setRoot(x, y, z, state int) {
	t.roots.Add(x, y, z)
	t.c.Level.SetState(x, y, z, state)
}

func (t *treeRun) setFoliage(x, y, z, state int) {
	t.foliage.Add(x, y, z)
	t.c.Level.SetState(x, y, z, state)
}

func (t *treeRun) isFoliageSet(x, y, z int) bool { return t.foliage.Contains(x, y, z) }

func (t *treeRun) setDecoration(x, y, z, state int) {
	t.decorations.Add(x, y, z)
	t.c.Level.SetState(x, y, z, state)
}

// placeBelowTrunkBlock is TrunkPlacer.placeBelowTrunkBlock().
func (t *treeRun) placeBelowTrunkBlock(x, y, z int) {
	if state := t.f.belowTrunkProvider(t.c, t.c.R, x, y, z); state >= 0 {
		t.setTrunk(x, y, z, state)
	}
}

// tryPlaceLeaf is FoliagePlacer.tryPlaceLeaf().
func (t *treeRun) tryPlaceLeaf(x, y, z int) bool {
	b := t.c.B
	level := t.c.Level
	current := level.GetState(x, y, z)
	if v, _ := b.GetProperty(current, "persistent"); v == "true" || !t.validTreePos(x, y, z) {
		return false
	}
	state := t.f.foliageProvider(t.c, t.c.R, x, y, z)
	if state < 0 {
		state = current
	}
	if b.HasProperty(state, "waterlogged") {
		state = b.WithBool(state, "waterlogged", b.IsWater(current) && b.FluidIsSource(current))
	}
	t.setFoliage(x, y, z, state)
	return true
}

func (t *treeRun) trunkState(x, y, z int) int {
	if s := t.f.trunkProvider(t.c, t.c.R, x, y, z); s >= 0 {
		return s
	}
	return t.c.Level.GetState(x, y, z)
}

func (t *treeRun) foliageState(x, y, z int) int {
	if s := t.f.foliageProvider(t.c, t.c.R, x, y, z); s >= 0 {
		return s
	}
	return t.c.Level.GetState(x, y, z)
}

// ---- root placer (type/tree/RootPlacer.php, mangrove_root_placer) ----

type rootPlacer struct {
	trunkOffsetY                    *IntProvider
	rootProvider, aboveRootProvider StateProvider
	aboveRootChance                 float64
	canGrowThrough, muddyRootsIn    []bool
	muddyRootsProvider              StateProvider
	maxRootWidth, maxRootLength     int
	randomSkewChance                float64
}

func newRootPlacer(reg *Registry, json obj) (*rootPlacer, error) {
	t, err := typeOf(json, "type")
	if err != nil {
		return nil, err
	}
	if t != "mangrove_root_placer" {
		return nil, fmt.Errorf("unknown root placer %s", t)
	}
	b := reg.B
	p := &rootPlacer{}
	if p.trunkOffsetY, err = parseIntProvider(json["trunk_offset_y"]); err != nil {
		return nil, err
	}
	if p.rootProvider, err = reg.ParseStateProvider(json["root_provider"]); err != nil {
		return nil, err
	}
	if above, ok := asObj(json["above_root_placement"]); ok {
		if p.aboveRootProvider, err = reg.ParseStateProvider(above["above_root_provider"]); err != nil {
			return nil, err
		}
		chance, err := jFloat(above, "above_root_placement_chance")
		if err != nil {
			return nil, err
		}
		p.aboveRootChance = fround(chance)
	}
	placement, ok := asObj(json["mangrove_root_placement"])
	if !ok {
		return nil, errExpected("mangrove_root_placement")
	}
	set, err := b.BlockSet(placement["can_grow_through"])
	if err != nil {
		return nil, err
	}
	p.canGrowThrough = blockMask(b, set)
	if set, err = b.BlockSet(placement["muddy_roots_in"]); err != nil {
		return nil, err
	}
	p.muddyRootsIn = blockMask(b, set)
	if p.muddyRootsProvider, err = reg.ParseStateProvider(placement["muddy_roots_provider"]); err != nil {
		return nil, err
	}
	var errs [3]error
	var skew float64
	p.maxRootWidth, errs[0] = jInt(placement, "max_root_width")
	p.maxRootLength, errs[1] = jInt(placement, "max_root_length")
	skew, errs[2] = jFloat(placement, "random_skew_chance")
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	p.randomSkewChance = fround(skew)
	return p, nil
}

func (p *rootPlacer) canPlaceRoot(t *treeRun, x, y, z int) bool {
	return t.validTreePos(x, y, z) || p.canGrowThrough[t.c.B.BlockOf[t.c.Level.GetState(x, y, z)]]
}

func (p *rootPlacer) placeRoots(t *treeRun, ox, oy, oz, tx, ty, tz int) bool {
	for y := oy; y < ty; y++ {
		if !p.canPlaceRoot(t, ox, y, oz) {
			return false
		}
	}
	positions := []pos{{tx, ty - 1, tz}}
	for _, dir := range PlaneHorizontal {
		px := tx + StepX[dir]
		pz := tz + StepZ[dir]
		var inDirection []pos
		if !p.simulateRoots(t, px, ty, pz, dir, tx, ty, tz, &inDirection, 0) {
			return false
		}
		positions = append(positions, inDirection...)
		positions = append(positions, pos{px, ty, pz})
	}
	for _, q := range positions {
		p.placeRoot(t, q[0], q[1], q[2])
	}
	return true
}

func (p *rootPlacer) simulateRoots(t *treeRun, x, y, z, dir, ox, oy, oz int, positions *[]pos, layer int) bool {
	if layer == p.maxRootLength || len(*positions) > p.maxRootLength {
		return false
	}
	for _, q := range p.potentialRootPositions(t.c.R, x, y, z, dir, ox, oy, oz) {
		if p.canPlaceRoot(t, q[0], q[1], q[2]) {
			*positions = append(*positions, q)
			if !p.simulateRoots(t, q[0], q[1], q[2], dir, ox, oy, oz, positions, layer+1) {
				return false
			}
		}
	}
	return true
}

func (p *rootPlacer) potentialRootPositions(r *Random, x, y, z, dir, ox, oy, oz int) []pos {
	below := pos{x, y - 1, z}
	nx := x + StepX[dir]
	nz := z + StepZ[dir]
	width := phpIntOfFloat(fround(fround(float64(absInt(ox-x))+float64(absInt(oy-y))) + float64(absInt(oz-z))))
	if width > p.maxRootWidth-3 && width <= p.maxRootWidth {
		if r.NextFloat() < p.randomSkewChance {
			return []pos{below, {nx, y - 1, nz}}
		}
		return []pos{below}
	}
	if width > p.maxRootWidth {
		return []pos{below}
	}
	if r.NextFloat() < p.randomSkewChance {
		return []pos{below}
	}
	if r.NextBoolean() {
		return []pos{{nx, y, nz}}
	}
	return []pos{below}
}

func (p *rootPlacer) placeRoot(t *treeRun, x, y, z int) {
	c := t.c
	b := c.B
	if p.muddyRootsIn[b.BlockOf[c.Level.GetState(x, y, z)]] {
		state := p.muddyRootsProvider(c, c.R, x, y, z)
		if state < 0 {
			state = c.Level.GetState(x, y, z)
		}
		t.setRoot(x, y, z, p.waterlogged(t, x, y, z, state))
		return
	}
	if !p.canPlaceRoot(t, x, y, z) {
		return
	}
	state := p.rootProvider(c, c.R, x, y, z)
	if state < 0 {
		state = c.Level.GetState(x, y, z)
	}
	t.setRoot(x, y, z, p.waterlogged(t, x, y, z, state))
	if p.aboveRootProvider != nil && c.R.NextFloat() < p.aboveRootChance && b.IsAir(c.Level.GetState(x, y+1, z)) {
		above := p.aboveRootProvider(c, c.R, x, y+1, z)
		if above < 0 {
			above = c.Level.GetState(x, y+1, z)
		}
		t.setRoot(x, y+1, z, p.waterlogged(t, x, y+1, z, above))
	}
}

func (p *rootPlacer) waterlogged(t *treeRun, x, y, z, state int) int {
	b := t.c.B
	if !b.HasProperty(state, "waterlogged") {
		return state
	}
	return b.WithBool(state, "waterlogged", b.IsWater(t.c.Level.GetState(x, y, z)))
}
