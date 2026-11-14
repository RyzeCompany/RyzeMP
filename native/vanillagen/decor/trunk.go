package decor

import (
	"fmt"
	"math"
)

// type/tree/TrunkPlacer.php

const (
	trunkStraight = iota
	trunkForking
	trunkGiant
	trunkMegaJungle
	trunkDarkOak
	trunkFancy
	trunkBending
	trunkCherry
	trunkUpwardsBranching
	trunkPoplar
)

type trunkPlacer struct {
	typ                                  int
	baseHeight, heightRandA, heightRandB int

	minHeightForLeaves int
	bendLength         *IntProvider

	branchCount, branchHorizontalLength, branchStartOffsetFromTop, secondBranchStartOffsetFromTop, branchEndOffsetFromTop *IntProvider

	extraBranchSteps             *IntProvider
	placeBranchPerLogProbability float64
	extraBranchLength            *IntProvider
	canGrowThrough               []bool

	trunkHeightAboveBranches, branchAmount *IntProvider
}

// attachment is a foliage attachment: position, radius offset, foliage height offset, double trunk.
type attachment struct {
	x, y, z                    int
	radiusOffset, heightOffset int
	doubleTrunk                bool
}

func newTrunkPlacer(reg *Registry, json obj) (*trunkPlacer, error) {
	t, err := typeOf(json, "type")
	if err != nil {
		return nil, err
	}
	p := &trunkPlacer{}
	switch t {
	case "straight_trunk_placer":
		p.typ = trunkStraight
	case "forking_trunk_placer":
		p.typ = trunkForking
	case "giant_trunk_placer":
		p.typ = trunkGiant
	case "mega_jungle_trunk_placer":
		p.typ = trunkMegaJungle
	case "dark_oak_trunk_placer":
		p.typ = trunkDarkOak
	case "fancy_trunk_placer":
		p.typ = trunkFancy
	case "bending_trunk_placer":
		p.typ = trunkBending
	case "cherry_trunk_placer":
		p.typ = trunkCherry
	case "upwards_branching_trunk_placer":
		p.typ = trunkUpwardsBranching
	case "poplar_trunk_placer":
		p.typ = trunkPoplar
	default:
		return nil, fmt.Errorf("unknown trunk placer %s", t)
	}
	var errs [8]error
	p.baseHeight, errs[0] = jInt(json, "base_height")
	p.heightRandA, errs[1] = jInt(json, "height_rand_a")
	p.heightRandB, errs[2] = jInt(json, "height_rand_b")
	switch p.typ {
	case trunkBending:
		p.minHeightForLeaves, errs[3] = jIntDef(json, "min_height_for_leaves", 1)
		p.bendLength, errs[4] = parseIntProvider(json["bend_length"])
	case trunkCherry:
		p.branchCount, errs[3] = parseIntProvider(json["branch_count"])
		p.branchHorizontalLength, errs[4] = parseIntProvider(json["branch_horizontal_length"])
		start, ok := asObj(json["branch_start_offset_from_top"])
		if !ok {
			return nil, errExpected("branch_start_offset_from_top")
		}
		var min, max int
		min, errs[5] = jInt(start, "min_inclusive")
		max, errs[6] = jInt(start, "max_inclusive")
		p.branchStartOffsetFromTop = uniformInt(min, max)
		p.secondBranchStartOffsetFromTop = uniformInt(min, max-1)
		p.branchEndOffsetFromTop, errs[7] = parseIntProvider(json["branch_end_offset_from_top"])
	case trunkUpwardsBranching:
		var prob float64
		p.extraBranchSteps, errs[3] = parseIntProvider(json["extra_branch_steps"])
		prob, errs[4] = jFloat(json, "place_branch_per_log_probability")
		p.placeBranchPerLogProbability = fround(prob)
		p.extraBranchLength, errs[5] = parseIntProvider(json["extra_branch_length"])
		set, err := reg.B.BlockSet(json["can_grow_through"])
		if err != nil {
			return nil, err
		}
		p.canGrowThrough = blockMask(reg.B, set)
	case trunkPoplar:
		p.trunkHeightAboveBranches, errs[3] = parseIntProvider(json["trunk_height_above_branches"])
		p.branchAmount, errs[4] = parseIntProvider(json["branch_amount"])
	}
	if err := firstErr(errs[:]...); err != nil {
		return nil, err
	}
	return p, nil
}

func sampleOr0(p *IntProvider, r *Random) int {
	if p == nil {
		return 0
	}
	return p.Sample(r)
}

func (p *trunkPlacer) getTreeHeight(r *Random) int {
	a := r.NextBoundedInt(p.heightRandA + 1)
	return p.baseHeight + a + r.NextBoundedInt(p.heightRandB+1)
}

func (p *trunkPlacer) validTreePos(t *treeRun, x, y, z int) bool {
	if t.validTreePos(x, y, z) {
		return true
	}
	return p.typ == trunkUpwardsBranching && p.canGrowThrough[t.c.B.BlockOf[t.c.Level.GetState(x, y, z)]]
}

func (p *trunkPlacer) isFree(t *treeRun, x, y, z int) bool {
	return p.validTreePos(t, x, y, z) || t.isLog(x, y, z)
}

// placeLog places a log (axis "" = unchanged).
func (p *trunkPlacer) placeLog(t *treeRun, x, y, z int, axis string) bool {
	if !p.validTreePos(t, x, y, z) {
		return false
	}
	state := t.trunkState(x, y, z)
	if axis != "" {
		state = t.c.B.With(state, "axis", axis)
	}
	t.setTrunk(x, y, z, state)
	return true
}

func (p *trunkPlacer) placeLogIfFree(t *treeRun, x, y, z int) {
	if p.isFree(t, x, y, z) {
		p.placeLog(t, x, y, z, "")
	}
}

func axisOf(direction int) string { return [3]string{"x", "y", "z"}[DirAxis[direction]] }

func (p *trunkPlacer) placeTrunk(t *treeRun, treeHeight, x, y, z int) []attachment {
	r := t.c.R
	switch p.typ {
	case trunkStraight:
		t.placeBelowTrunkBlock(x, y-1, z)
		for i := 0; i < treeHeight; i++ {
			p.placeLog(t, x, y+i, z, "")
		}
		return []attachment{{x, y + treeHeight, z, 0, 0, false}}

	case trunkForking:
		t.placeBelowTrunkBlock(x, y-1, z)
		var attachments []attachment
		lean := PlaneHorizontal[r.NextBoundedInt(4)]
		leanHeight := treeHeight - r.NextBoundedInt(4) - 1
		leanSteps := 3 - r.NextBoundedInt(3)
		tx, tz := x, z
		ey, hasEy := 0, false
		for yo := 0; yo < treeHeight; yo++ {
			yy := y + yo
			if yo >= leanHeight && leanSteps > 0 {
				tx += StepX[lean]
				tz += StepZ[lean]
				leanSteps--
			}
			if p.placeLog(t, tx, yy, tz, "") {
				ey, hasEy = yy+1, true
			}
		}
		if hasEy {
			attachments = append(attachments, attachment{tx, ey, tz, 1, 0, false})
		}
		tx, tz = x, z
		branch := PlaneHorizontal[r.NextBoundedInt(4)]
		if branch != lean {
			branchPos := leanHeight - r.NextBoundedInt(2) - 1
			branchSteps := 1 + r.NextBoundedInt(3)
			ey, hasEy = 0, false
			for yo := branchPos; yo < treeHeight && branchSteps > 0; yo, branchSteps = yo+1, branchSteps-1 {
				if yo < 1 {
					continue
				}
				yy := y + yo
				tx += StepX[branch]
				tz += StepZ[branch]
				if p.placeLog(t, tx, yy, tz, "") {
					ey, hasEy = yy+1, true
				}
			}
			if hasEy {
				attachments = append(attachments, attachment{tx, ey, tz, 0, 0, false})
			}
		}
		return attachments

	case trunkGiant:
		return p.placeGiant(t, treeHeight, x, y, z)

	case trunkMegaJungle:
		attachments := p.placeGiant(t, treeHeight, x, y, z)
		for branchHeight := treeHeight - 2 - r.NextBoundedInt(4); branchHeight > treeHeight/2; branchHeight -= 2 + r.NextBoundedInt(4) {
			angle := fround(float64(r.NextFloat() * 6.2831854820251465))
			bx, bz := 0, 0
			for b := 0; b < 5; b++ {
				bx = phpIntOfFloat(fround(1.5 + fround(float64(MthCos(angle)*float64(b)))))
				bz = phpIntOfFloat(fround(1.5 + fround(float64(MthSin(angle)*float64(b)))))
				p.placeLog(t, x+bx, y+branchHeight-3+b/2, z+bz, "")
			}
			attachments = append(attachments, attachment{x + bx, y + branchHeight, z + bz, -2, 0, false})
		}
		return attachments

	case trunkDarkOak:
		var attachments []attachment
		t.placeBelowTrunkBlock(x, y-1, z)
		t.placeBelowTrunkBlock(x+1, y-1, z)
		t.placeBelowTrunkBlock(x, y-1, z+1)
		t.placeBelowTrunkBlock(x+1, y-1, z+1)
		lean := PlaneHorizontal[r.NextBoundedInt(4)]
		leanHeight := treeHeight - r.NextBoundedInt(4)
		leanSteps := 2 - r.NextBoundedInt(3)
		tx, tz := x, z
		ey := y + treeHeight - 1
		for dy := 0; dy < treeHeight; dy++ {
			if dy >= leanHeight && leanSteps > 0 {
				tx += StepX[lean]
				tz += StepZ[lean]
				leanSteps--
			}
			yy := y + dy
			if t.isAirOrLeaves(tx, yy, tz) {
				p.placeLog(t, tx, yy, tz, "")
				p.placeLog(t, tx+1, yy, tz, "")
				p.placeLog(t, tx, yy, tz+1, "")
				p.placeLog(t, tx+1, yy, tz+1, "")
			}
		}
		attachments = append(attachments, attachment{tx, ey, tz, 0, 0, true})
		for ox := -1; ox <= 2; ox++ {
			for oz := -1; oz <= 2; oz++ {
				if ox >= 0 && ox <= 1 && oz >= 0 && oz <= 1 {
					continue
				}
				if r.NextBoundedInt(3) > 0 {
					continue
				}
				length := r.NextBoundedInt(3) + 2
				for branchY := 0; branchY < length; branchY++ {
					p.placeLog(t, x+ox, ey-branchY-1, z+oz, "")
				}
				attachments = append(attachments, attachment{x + ox, ey, z + oz, 0, 0, false})
			}
		}
		return attachments

	case trunkFancy:
		return p.placeFancy(t, treeHeight, x, y, z)

	case trunkBending:
		direction := PlaneHorizontal[r.NextBoundedInt(4)]
		logHeight := treeHeight - 1
		px, py, pz := x, y, z
		t.placeBelowTrunkBlock(px, py-1, pz)
		var attachments []attachment
		for i := 0; i <= logHeight; i++ {
			if i+1 >= logHeight+r.NextBoundedInt(2) {
				px += StepX[direction]
				pz += StepZ[direction]
			}
			if t.validTreePos(px, py, pz) {
				p.placeLog(t, px, py, pz, "")
			}
			if i >= p.minHeightForLeaves {
				attachments = append(attachments, attachment{px, py, pz, 0, 0, false})
			}
			py++
		}
		dirLength := sampleOr0(p.bendLength, r)
		for i := 0; i <= dirLength; i++ {
			if t.validTreePos(px, py, pz) {
				p.placeLog(t, px, py, pz, "")
			}
			attachments = append(attachments, attachment{px, py, pz, 0, 0, false})
			px += StepX[direction]
			pz += StepZ[direction]
		}
		return attachments

	case trunkCherry:
		return p.placeCherry(t, treeHeight, x, y, z)

	case trunkUpwardsBranching:
		var attachments []attachment
		for heightPos := 0; heightPos < treeHeight; heightPos++ {
			currentHeight := y + heightPos
			if p.placeLog(t, x, currentHeight, z, "") && heightPos < treeHeight-1 && r.NextFloat() < p.placeBranchPerLogProbability {
				branchDir := PlaneHorizontal[r.NextBoundedInt(4)]
				branchLen := sampleOr0(p.extraBranchLength, r)
				branchPos := maxInt(0, branchLen-sampleOr0(p.extraBranchLength, r)-1)
				branchSteps := sampleOr0(p.extraBranchSteps, r)
				p.placeUpwardsBranch(t, treeHeight, &attachments, x, z, currentHeight, branchDir, branchPos, branchSteps)
			}
			if heightPos == treeHeight-1 {
				attachments = append(attachments, attachment{x, currentHeight + 1, z, 0, 0, false})
			}
		}
		return attachments

	case trunkPoplar:
		t.placeBelowTrunkBlock(x, y-1, z)
		upToBranches := treeHeight - sampleOr0(p.trunkHeightAboveBranches, r)
		for i := 0; i < treeHeight; i++ {
			p.placeLog(t, x, y+i, z, "")
			directions := shuffledHorizontalDirections(r)
			if upToBranches-1 == i {
				branches := sampleOr0(p.branchAmount, r)
				for b := 0; b < branches; b++ {
					d := directions[b]
					p.placeLog(t, x+StepX[d], y+i, z+StepZ[d], axisOf(d))
				}
			}
		}
		return []attachment{{x, y + upToBranches, z, 0, 0, false}}
	}
	return nil
}

// shuffledHorizontalDirections is Direction.allShuffled(random) without the vertical directions.
func shuffledHorizontalDirections(r *Random) []int {
	list := utilShuffle([]int{Down, Up, North, South, West, East}, r)
	out := make([]int, 0, 4)
	for _, d := range list {
		if d != Up && d != Down {
			out = append(out, d)
		}
	}
	return out
}

func (p *trunkPlacer) placeGiant(t *treeRun, treeHeight, x, y, z int) []attachment {
	t.placeBelowTrunkBlock(x, y-1, z)
	t.placeBelowTrunkBlock(x+1, y-1, z)
	t.placeBelowTrunkBlock(x, y-1, z+1)
	t.placeBelowTrunkBlock(x+1, y-1, z+1)
	for hh := 0; hh < treeHeight; hh++ {
		p.placeLogIfFree(t, x, y+hh, z)
		if hh < treeHeight-1 {
			p.placeLogIfFree(t, x+1, y+hh, z)
			p.placeLogIfFree(t, x+1, y+hh, z+1)
			p.placeLogIfFree(t, x, y+hh, z+1)
		}
	}
	return []attachment{{x, y + treeHeight, z, 0, 0, true}}
}

func (p *trunkPlacer) placeUpwardsBranch(t *treeRun, treeHeight int, attachments *[]attachment, logX, logZ, currentHeight, branchDir, branchPos, branchSteps int) {
	heightAlongBranch := currentHeight + branchPos
	for index := branchPos; index < treeHeight && branchSteps > 0; index, branchSteps = index+1, branchSteps-1 {
		if index < 1 {
			continue
		}
		placementHeight := currentHeight + index
		logX += StepX[branchDir]
		logZ += StepZ[branchDir]
		heightAlongBranch = placementHeight
		if p.placeLog(t, logX, placementHeight, logZ, "") {
			heightAlongBranch++
		}
		*attachments = append(*attachments, attachment{logX, placementHeight, logZ, 0, 0, false})
	}
	if heightAlongBranch-currentHeight > 1 {
		*attachments = append(*attachments, attachment{logX, heightAlongBranch, logZ, 0, 0, false})
		*attachments = append(*attachments, attachment{logX, heightAlongBranch - 2, logZ, 0, 0, false})
	}
}

type fancyFoliage struct{ x, y, z, branchBase int }

func (p *trunkPlacer) placeFancy(t *treeRun, treeHeight, x, y, z int) []attachment {
	r := t.c.R
	height := treeHeight + 2
	trunkHeight := phpIntOfFloat(math.Floor(float64(float64(height) * 0.618)))
	t.placeBelowTrunkBlock(x, y-1, z)
	ratio := float64(1.0*float64(height)) / 13.0
	clustersPerY := minInt(1, phpIntOfFloat(math.Floor(1.382+libmPow(ratio, 2))))
	trunkTop := y + trunkHeight
	relativeY := height - 5
	foliageCoords := []fancyFoliage{{x, y + relativeY, z, trunkTop}}
	for ; relativeY >= 0; relativeY-- {
		treeShape := fancyTreeShape(height, relativeY)
		if treeShape < 0.0 {
			continue
		}
		for i := 0; i < clustersPerY; i++ {
			radius := float64(float64(1.0*treeShape) * (r.NextFloat() + 0.328))
			angle := float64(fround(float64(r.NextFloat()*2.0)) * 3.141592653589793)
			px := float64(radius*libmSin(angle)) + 0.5
			pz := float64(radius*libmCos(angle)) + 0.5
			sx := x + phpIntOfFloat(math.Floor(px))
			sy := y + relativeY - 1
			sz := z + phpIntOfFloat(math.Floor(pz))
			if p.makeLimb(t, sx, sy, sz, sx, sy+5, sz, false) {
				dx := x - sx
				dz := z - sz
				branchHeight := float64(sy) - float64(math.Sqrt(float64(dx*dx+dz*dz))*0.381)
				branchTop := trunkTop
				if !(branchHeight > float64(trunkTop)) {
					branchTop = phpIntOfFloat(branchHeight)
				}
				if p.makeLimb(t, x, branchTop, z, sx, sy, sz, false) {
					foliageCoords = append(foliageCoords, fancyFoliage{sx, sy, sz, branchTop})
				}
			}
		}
	}
	p.makeLimb(t, x, y, z, x, y+trunkHeight, z, true)
	for _, f := range foliageCoords {
		if trimBranches(height, f.branchBase-y) {
			p.makeLimb(t, x, f.branchBase, z, f.x, f.y, f.z, true)
		}
	}
	var attachments []attachment
	for _, f := range foliageCoords {
		if trimBranches(height, f.branchBase-y) {
			attachments = append(attachments, attachment{f.x, f.y, f.z, 0, 0, false})
		}
	}
	return attachments
}

func trimBranches(height, localY int) bool {
	return float64(localY) >= float64(float64(height)*0.2)
}

func fancyTreeShape(height, y int) float64 {
	if fround(float64(y)) < fround(float64(float64(height)*0.30000001192092896)) {
		return -1.0
	}
	radius := fround(float64(height) / 2.0)
	adjacent := fround(radius - float64(y))
	distance := fround(math.Sqrt(fround(fround(float64(radius*radius)) - fround(float64(adjacent*adjacent)))))
	if adjacent == 0.0 {
		distance = radius
	} else if math.Abs(adjacent) >= radius {
		return 0.0
	}
	return fround(float64(distance * 0.5))
}

func (p *trunkPlacer) makeLimb(t *treeRun, x0, y0, z0, x1, y1, z1 int, doPlace bool) bool {
	if !doPlace && x0 == x1 && y0 == y1 && z0 == z1 {
		return true
	}
	dx := x1 - x0
	dy := y1 - y0
	dz := z1 - z0
	steps := maxInt(absInt(dx), maxInt(absInt(dy), absInt(dz)))
	if steps == 0 {
		//vanilla divides by zero (NaN offsets, floored to 0): only the start block
		if doPlace {
			p.placeLog(t, x0, y0, z0, "y")
			return true
		}
		return p.isFree(t, x0, y0, z0)
	}
	fx := fround(float64(dx) / float64(steps))
	fy := fround(float64(dy) / float64(steps))
	fz := fround(float64(dz) / float64(steps))
	for i := 0; i <= steps; i++ {
		bx := x0 + phpIntOfFloat(math.Floor(fround(0.5+fround(float64(float64(i)*fx)))))
		by := y0 + phpIntOfFloat(math.Floor(fround(0.5+fround(float64(float64(i)*fy)))))
		bz := z0 + phpIntOfFloat(math.Floor(fround(0.5+fround(float64(float64(i)*fz)))))
		if doPlace {
			xdiff := absInt(bx - x0)
			zdiff := absInt(bz - z0)
			maxdiff := maxInt(xdiff, zdiff)
			axis := "y"
			if maxdiff > 0 {
				if xdiff == maxdiff {
					axis = "x"
				} else {
					axis = "z"
				}
			}
			p.placeLog(t, bx, by, bz, axis)
		} else if !p.isFree(t, bx, by, bz) {
			return false
		}
	}
	return true
}

func (p *trunkPlacer) placeCherry(t *treeRun, treeHeight, x, y, z int) []attachment {
	r := t.c.R
	t.placeBelowTrunkBlock(x, y-1, z)
	first := maxInt(0, treeHeight-1+sampleOr0(p.branchStartOffsetFromTop, r))
	second := maxInt(0, treeHeight-1+sampleOr0(p.secondBranchStartOffsetFromTop, r))
	if second >= first {
		second++
	}
	branchCount := sampleOr0(p.branchCount, r)
	hasMiddle := branchCount == 3
	hasBoth := branchCount >= 2
	var trunkHeight int
	switch {
	case hasMiddle:
		trunkHeight = treeHeight
	case hasBoth:
		trunkHeight = maxInt(first, second) + 1
	default:
		trunkHeight = first + 1
	}
	for i := 0; i < trunkHeight; i++ {
		p.placeLog(t, x, y+i, z, "")
	}
	var attachments []attachment
	if hasMiddle {
		attachments = append(attachments, attachment{x, y + trunkHeight, z, 0, 0, false})
	}
	direction := PlaneHorizontal[r.NextBoundedInt(4)]
	sideways := axisOf(direction)
	attachments = append(attachments, p.cherryBranch(t, treeHeight, x, y, z, sideways, direction, first, first < trunkHeight-1))
	if hasBoth {
		attachments = append(attachments, p.cherryBranch(t, treeHeight, x, y, z, sideways, Opposite[direction], second, second < trunkHeight-1))
	}
	return attachments
}

func (p *trunkPlacer) cherryBranch(t *treeRun, treeHeight, x, y, z int, sideways string, direction, offset int, middleContinuesUpwards bool) attachment {
	r := t.c.R
	lx, ly, lz := x, y+offset, z
	endOffset := treeHeight - 1 + sampleOr0(p.branchEndOffsetFromTop, r)
	extend := middleContinuesUpwards || endOffset < offset
	distanceToTrunk := sampleOr0(p.branchHorizontalLength, r)
	if extend {
		distanceToTrunk++
	}
	ex := x + StepX[direction]*distanceToTrunk
	ey := y + endOffset
	ez := z + StepZ[direction]*distanceToTrunk
	stepsHorizontally := 1
	if extend {
		stepsHorizontally = 2
	}
	for i := 0; i < stepsHorizontally; i++ {
		lx += StepX[direction]
		lz += StepZ[direction]
		p.placeLog(t, lx, ly, lz, sideways)
	}
	vertical := -1
	if ey > ly {
		vertical = 1
	}
	for {
		distance := phpIntOfFloat(fround(fround(float64(absInt(ex-lx))+float64(absInt(ey-ly))) + float64(absInt(ez-lz))))
		if distance == 0 {
			break
		}
		chance := fround(float64(absInt(ey-ly)) / float64(distance))
		if r.NextFloat() < chance {
			ly += vertical
			p.placeLog(t, lx, ly, lz, "")
		} else {
			lx += StepX[direction]
			lz += StepZ[direction]
			p.placeLog(t, lx, ly, lz, sideways)
		}
	}
	return attachment{ex, ey + 1, ez, 0, 0, false}
}
