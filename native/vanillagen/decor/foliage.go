package decor

import (
	"fmt"
	"math"
)

// type/tree/FoliagePlacer.php

const (
	foliageBlob = iota
	foliageSpruce
	foliagePine
	foliageAcacia
	foliageBush
	foliageFancy
	foliageJungle
	foliageMegaPine
	foliageDarkOak
	foliageRandomSpread
	foliageCherry
	foliagePoplar
)

type foliagePlacer struct {
	typ                          int
	radius, offset               *IntProvider
	height                       int
	heightProvider               *IntProvider
	leafPlacementAttempts        int
	wideBottomLayerHoleChance    float64
	cornerHoleChance             float64
	hangingLeavesChance          float64
	hangingLeavesExtensionChance float64
	sideHoleChance               float64
}

func newFoliagePlacer(json obj) (*foliagePlacer, error) {
	t, err := typeOf(json, "type")
	if err != nil {
		return nil, err
	}
	types := map[string]int{
		"blob_foliage_placer": foliageBlob, "spruce_foliage_placer": foliageSpruce, "pine_foliage_placer": foliagePine,
		"acacia_foliage_placer": foliageAcacia, "bush_foliage_placer": foliageBush, "fancy_foliage_placer": foliageFancy,
		"jungle_foliage_placer": foliageJungle, "mega_pine_foliage_placer": foliageMegaPine, "dark_oak_foliage_placer": foliageDarkOak,
		"random_spread_foliage_placer": foliageRandomSpread, "cherry_foliage_placer": foliageCherry, "poplar_foliage_placer": foliagePoplar,
	}
	typ, ok := types[t]
	if !ok {
		return nil, fmt.Errorf("unknown foliage placer %s", t)
	}
	p := &foliagePlacer{typ: typ}
	var errs [10]error
	p.radius, errs[0] = parseIntProvider(json["radius"])
	p.offset, errs[1] = parseIntProvider(json["offset"])
	switch typ {
	case foliageBlob, foliageBush, foliageFancy, foliageJungle:
		p.height, errs[2] = jInt(json, "height")
	case foliageSpruce:
		p.heightProvider, errs[2] = parseIntProvider(json["trunk_height"])
	case foliagePine, foliagePoplar, foliageCherry:
		p.heightProvider, errs[2] = parseIntProvider(json["height"])
	case foliageMegaPine:
		p.heightProvider, errs[2] = parseIntProvider(json["crown_height"])
	case foliageRandomSpread:
		p.heightProvider, errs[2] = parseIntProvider(json["foliage_height"])
		p.leafPlacementAttempts, errs[3] = jInt(json, "leaf_placement_attempts")
	}
	if typ == foliageCherry {
		var a, b, c, d float64
		a, errs[4] = jFloat(json, "wide_bottom_layer_hole_chance")
		b, errs[5] = jFloat(json, "corner_hole_chance")
		c, errs[6] = jFloat(json, "hanging_leaves_chance")
		d, errs[7] = jFloat(json, "hanging_leaves_extension_chance")
		p.wideBottomLayerHoleChance, p.cornerHoleChance, p.hangingLeavesChance, p.hangingLeavesExtensionChance = fround(a), fround(b), fround(c), fround(d)
	}
	if typ == foliagePoplar {
		var s float64
		s, errs[8] = jFloat(json, "side_hole_chance")
		p.sideHoleChance = fround(s)
	}
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	return p, nil
}

func (p *foliagePlacer) foliageHeight(r *Random, treeHeight int) int {
	switch p.typ {
	case foliageBlob, foliageBush, foliageFancy, foliageJungle:
		return p.height
	case foliageAcacia:
		return 0
	case foliageDarkOak:
		return 4
	case foliageSpruce:
		return maxInt(4, treeHeight-sampleOr0(p.heightProvider, r))
	}
	return sampleOr0(p.heightProvider, r)
}

func (p *foliagePlacer) foliageRadius(r *Random, trunkHeight int) int {
	radius := p.radius.Sample(r)
	if p.typ == foliagePine {
		return radius + r.NextBoundedInt(maxInt(trunkHeight+1, 1))
	}
	return radius
}

func (p *foliagePlacer) createFoliage(t *treeRun, treeHeight int, a attachment, foliageHeight, leafRadius int) {
	r := t.c.R
	offset := p.offset.Sample(r)
	ax, ay, az := a.x, a.y, a.z
	radiusOffset, heightOffset, doubleTrunk := a.radiusOffset, a.heightOffset, a.doubleTrunk
	switch p.typ {
	case foliageBlob:
		h := foliageHeight + heightOffset
		for yo := offset; yo >= offset-h; yo-- {
			rr := maxInt(leafRadius+radiusOffset-1-yo/2, 0)
			p.placeLeavesRow(t, ax, ay, az, rr, yo, doubleTrunk)
		}
	case foliageBush:
		h := foliageHeight + heightOffset
		for yo := offset; yo >= offset-h; yo-- {
			p.placeLeavesRow(t, ax, ay, az, leafRadius+radiusOffset-1-yo, yo, doubleTrunk)
		}
	case foliageFancy:
		for yo := offset; yo >= offset-foliageHeight; yo-- {
			rr := leafRadius + 1
			if yo == offset || yo == offset-foliageHeight {
				rr = leafRadius
			}
			p.placeLeavesRow(t, ax, ay, az, rr, yo, doubleTrunk)
		}
	case foliageAcacia:
		fy := ay + offset
		h := foliageHeight + heightOffset
		p.placeLeavesRow(t, ax, fy, az, leafRadius+radiusOffset, -1-h, doubleTrunk)
		p.placeLeavesRow(t, ax, fy, az, leafRadius-1, -h, doubleTrunk)
		p.placeLeavesRow(t, ax, fy, az, leafRadius+radiusOffset-1, 0, doubleTrunk)
	case foliageDarkOak:
		fy := ay + offset
		if doubleTrunk {
			p.placeLeavesRow(t, ax, fy, az, leafRadius+2, -1, doubleTrunk)
			p.placeLeavesRow(t, ax, fy, az, leafRadius+3, 0, doubleTrunk)
			p.placeLeavesRow(t, ax, fy, az, leafRadius+2, 1, doubleTrunk)
			if r.NextBoolean() {
				p.placeLeavesRow(t, ax, fy, az, leafRadius, 2, doubleTrunk)
			}
		} else {
			p.placeLeavesRow(t, ax, fy, az, leafRadius+2, -1, doubleTrunk)
			p.placeLeavesRow(t, ax, fy, az, leafRadius+1, 0, doubleTrunk)
		}
	case foliageJungle:
		var leafHeight int
		if doubleTrunk {
			leafHeight = foliageHeight
		} else {
			leafHeight = 1 + r.NextBoundedInt(2)
		}
		leafHeight += heightOffset
		for yo := offset; yo >= offset-leafHeight; yo-- {
			p.placeLeavesRow(t, ax, ay, az, leafRadius+radiusOffset+1-yo, yo, doubleTrunk)
		}
	case foliageMegaPine:
		prevRadius := 0
		h := foliageHeight + heightOffset
		for yy := ay - h + offset; yy <= ay+offset; yy++ {
			yo := ay - yy
			smooth := leafRadius + radiusOffset + phpIntOfFloat(math.Floor(fround(float64(fround(float64(yo)/float64(h))*3.5))))
			jagged := smooth
			if yo > 0 && smooth == prevRadius && yy&1 == 0 {
				jagged = smooth + 1
			}
			p.placeLeavesRow(t, ax, yy, az, jagged, 0, doubleTrunk)
			prevRadius = smooth
		}
	case foliagePine:
		current := 0
		h := foliageHeight + heightOffset
		for yo := offset; yo >= offset-h; yo-- {
			p.placeLeavesRow(t, ax, ay, az, current, yo, doubleTrunk)
			if current >= 1 && yo == offset-h+1 {
				current--
			} else if current < leafRadius+radiusOffset {
				current++
			}
		}
	case foliageSpruce:
		current := r.NextBoundedInt(2)
		maxRadius := 1
		minRadius := 0
		h := foliageHeight + heightOffset
		for yo := offset; yo >= -h; yo-- {
			p.placeLeavesRow(t, ax, ay, az, current, yo, doubleTrunk)
			if current >= maxRadius {
				current = minRadius
				minRadius = 1
				maxRadius = minInt(maxRadius+1, leafRadius+radiusOffset)
			} else {
				current++
			}
		}
	case foliageRandomSpread:
		for i := 0; i < p.leafPlacementAttempts; i++ {
			a1 := r.NextBoundedInt(leafRadius)
			dx := a1 - r.NextBoundedInt(leafRadius)
			a2 := r.NextBoundedInt(foliageHeight)
			dy := a2 - r.NextBoundedInt(foliageHeight)
			a3 := r.NextBoundedInt(leafRadius)
			dz := a3 - r.NextBoundedInt(leafRadius)
			t.tryPlaceLeaf(ax+dx, ay+dy, az+dz)
		}
	case foliageCherry:
		fy := ay + offset
		rr := leafRadius + radiusOffset - 1
		h := foliageHeight + heightOffset
		p.placeLeavesRow(t, ax, fy, az, rr-2, h-3, doubleTrunk)
		p.placeLeavesRow(t, ax, fy, az, rr-1, h-4, doubleTrunk)
		for y := h - 5; y >= 0; y-- {
			p.placeLeavesRow(t, ax, fy, az, rr, y, doubleTrunk)
		}
		p.placeLeavesRowWithHangingLeavesBelow(t, ax, fy, az, rr, -1, doubleTrunk, p.hangingLeavesChance, p.hangingLeavesExtensionChance)
		p.placeLeavesRowWithHangingLeavesBelow(t, ax, fy, az, rr-1, -2, doubleTrunk, p.hangingLeavesChance, p.hangingLeavesExtensionChance)
	case foliagePoplar:
		p.createPoplar(t, ax, ay+offset, az, leafRadius+radiusOffset-1, foliageHeight+heightOffset, doubleTrunk)
	}
}

func (p *foliagePlacer) placeLeavesRow(t *treeRun, ox, oy, oz, radius, y int, doubleTrunk bool) {
	extra := 0
	if doubleTrunk {
		extra = 1
	}
	for dx := -radius; dx <= radius+extra; dx++ {
		for dz := -radius; dz <= radius+extra; dz++ {
			if !p.shouldSkipLocationSigned(t.c.R, dx, y, dz, radius, doubleTrunk) {
				t.tryPlaceLeaf(ox+dx, oy+y, oz+dz)
			}
		}
	}
}

func (p *foliagePlacer) shouldSkipLocationSigned(r *Random, dx, y, dz, radius int, doubleTrunk bool) bool {
	if p.typ == foliageDarkOak && y == 0 && doubleTrunk && (dx == -radius || dx >= radius) && (dz == -radius || dz >= radius) {
		return true
	}
	var mx, mz int
	if doubleTrunk {
		mx = minInt(absInt(dx), absInt(dx-1))
		mz = minInt(absInt(dz), absInt(dz-1))
	} else {
		mx = absInt(dx)
		mz = absInt(dz)
	}
	return p.shouldSkipLocation(r, mx, y, mz, radius, doubleTrunk)
}

func (p *foliagePlacer) shouldSkipLocation(r *Random, dx, y, dz, radius int, doubleTrunk bool) bool {
	switch p.typ {
	case foliageBlob:
		return dx == radius && dz == radius && (r.NextBoundedInt(2) == 0 || y == 0)
	case foliageBush:
		return dx == radius && dz == radius && r.NextBoundedInt(2) == 0
	case foliageFancy:
		fx := fround(float64(dx) + 0.5)
		fz := fround(float64(dz) + 0.5)
		return fround(fround(float64(fx*fx))+fround(float64(fz*fz))) > float64(radius*radius)
	case foliageAcacia:
		if y == 0 {
			return (dx > 1 || dz > 1) && dx != 0 && dz != 0
		}
		return dx == radius && dz == radius && radius > 0
	case foliageDarkOak:
		if y == -1 && !doubleTrunk {
			return dx == radius && dz == radius
		}
		if y == 1 {
			return dx+dz > radius*2-2
		}
		return false
	case foliageJungle, foliageMegaPine:
		if dx+dz >= 7 {
			return true
		}
		return dx*dx+dz*dz > radius*radius
	case foliagePine, foliageSpruce:
		return dx == radius && dz == radius && radius > 0
	case foliageCherry:
		if y == -1 && (dx == radius || dz == radius) && r.NextFloat() < p.wideBottomLayerHoleChance {
			return true
		}
		corner := dx == radius && dz == radius
		if radius > 2 {
			return corner || (dx+dz > radius*2-2 && r.NextFloat() < p.cornerHoleChance)
		}
		return corner && r.NextFloat() < p.cornerHoleChance
	}
	return false
}

func (p *foliagePlacer) placeLeavesRowWithHangingLeavesBelow(t *treeRun, ox, oy, oz, radius, y int, doubleTrunk bool, hangingChance, extensionChance float64) {
	p.placeLeavesRow(t, ox, oy, oz, radius, y, doubleTrunk)
	extra := 0
	if doubleTrunk {
		extra = 1
	}
	logX, logY, logZ := ox, oy-1, oz
	for _, alongEdge := range PlaneHorizontal {
		toEdge := clockWise(alongEdge)
		offsetToEdge := radius
		if StepX[toEdge]+StepZ[toEdge] > 0 {
			offsetToEdge = radius + extra
		}
		px := ox + StepX[toEdge]*offsetToEdge - StepX[alongEdge]*radius
		py := oy + y - 1
		pz := oz + StepZ[toEdge]*offsetToEdge - StepZ[alongEdge]*radius
		for along := -radius; along < radius+extra; along++ {
			leavesAbove := t.isFoliageSet(px, py+1, pz)
			if leavesAbove && p.tryPlaceExtension(t, hangingChance, logX, logY, logZ, px, py, pz) {
				p.tryPlaceExtension(t, extensionChance, logX, logY, logZ, px, py-1, pz)
			}
			px += StepX[alongEdge]
			pz += StepZ[alongEdge]
		}
	}
}

func (p *foliagePlacer) tryPlaceExtension(t *treeRun, chance float64, logX, logY, logZ, x, y, z int) bool {
	distance := phpIntOfFloat(fround(fround(float64(absInt(logX-x))+float64(absInt(logY-y))) + float64(absInt(logZ-z))))
	if distance >= 7 {
		return false
	}
	if t.c.R.NextFloat() > chance {
		return false
	}
	return t.tryPlaceLeaf(x, y, z)
}

func (p *foliagePlacer) createPoplar(t *treeRun, ox, oy, oz, radius, foliageHeight int, doubleTrunk bool) {
	flip := t.c.R.NextBoolean()
	p.poplarRow(t, ox, oy, oz, radius-2, foliageHeight-1, doubleTrunk, foliageHeight, flip)
	p.poplarRow(t, ox, oy, oz, radius-1, foliageHeight-2, doubleTrunk, foliageHeight, flip)
	p.poplarRow(t, ox, oy, oz, radius-1, foliageHeight-3, doubleTrunk, foliageHeight, flip)
	for y := foliageHeight - 4; y >= 1; y-- {
		p.poplarRow(t, ox, oy, oz, radius, y, doubleTrunk, foliageHeight, flip)
	}
	p.poplarLogs(t, ox, oy, oz, radius, foliageHeight-4, doubleTrunk, foliageHeight, flip)
	p.poplarRow(t, ox, oy, oz, radius-1, 0, doubleTrunk, foliageHeight, flip)
	p.poplarRow(t, ox, oy, oz, maxInt(1, minInt(2, radius-2)), -1, doubleTrunk, foliageHeight, flip)
}

func poplarPartialRow(foliageHeight, y int) bool {
	return foliageHeight-1 == y || foliageHeight-2 == y
}

func poplarCornerCut(dx, dz, radius int, partial, flip bool) int {
	var small bool
	if flip {
		small = (dx > 0 && dz > 0) || (dz < 0 && dx < 0)
	} else {
		small = (dx > 0 && dz < 0) || (dz > 0 && dx < 0)
	}
	if small {
		return radius - 1
	}
	if partial {
		return radius + 1
	}
	return radius
}

func withinRhombus(radius, absDx, absDz, cornerCut, additional int) bool {
	return absDx+absDz <= radius*2-(cornerCut+additional)
}

func (p *foliagePlacer) poplarRow(t *treeRun, ox, oy, oz, radius, y int, doubleTrunk bool, foliageHeight int, flip bool) {
	extra := 0
	if doubleTrunk {
		extra = 1
	}
	r := t.c.R
	for dx := -radius; dx <= radius+extra; dx++ {
		for dz := -radius; dz <= radius+extra; dz++ {
			partial := poplarPartialRow(foliageHeight, y)
			cut := poplarCornerCut(dx, dz, radius, partial, flip)
			absDx := absInt(dx)
			absDz := absInt(dz)
			edge := absDx == radius || absDz == radius
			if partial && edge {
				continue
			}
			additional := 0
			if r.NextFloat() <= p.sideHoleChance {
				additional = 1
			}
			if !withinRhombus(radius, absDx, absDz, cut, additional) {
				continue
			}
			t.tryPlaceLeaf(ox+dx, oy+y, oz+dz)
		}
	}
}

func (p *foliagePlacer) poplarLogs(t *treeRun, ox, oy, oz, radius, y int, doubleTrunk bool, foliageHeight int, flip bool) {
	extra := 0
	if doubleTrunk {
		extra = 1
	}
	b := t.c.B
	level := t.c.Level
	for dx := -radius; dx <= radius+extra; dx++ {
		for dz := -radius; dz <= radius+extra; dz++ {
			absDz := absInt(dz)
			absDx := absInt(dx)
			if !withinRhombus(radius, absDx, absDz, poplarCornerCut(dx, dz, radius, poplarPartialRow(foliageHeight, y), flip), 2) {
				continue
			}
			if !((absDz == 0 && radius-absDx >= 4) || (absDx == 0 && radius-absDz >= 4)) {
				continue
			}
			x := ox + dx
			py := oy + y
			z := oz + dz
			//PoplarFoliagePlacer.tryPlaceLog(): only where the foliage provider's state is (getState() consumes randoms)
			current := level.GetState(x, py, z)
			if current != t.foliageState(x, py, z) {
				continue
			}
			axis := "z"
			if absDz == 0 {
				axis = "x"
			}
			t.setFoliage(x, py, z, b.With(t.trunkState(x, py, z), "axis", axis))
		}
	}
}
