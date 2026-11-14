package decor

// type/PlantFeatures.php: bamboo, coral_tree, coral_claw.

type bambooFeature struct {
	trunk, finalLarge, topLarge, topSmall, podzol, bamboo int
	probability                                           float64
}

func newBamboo(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	p, err := jFloat(json, "probability")
	if err != nil {
		return nil, err
	}
	bamboo := b.MustDefault("bamboo")
	trunk := b.With(b.WithInt(b.WithInt(bamboo, "age", 1), "stage", 0), "leaves", "none")
	return &bambooFeature{
		trunk:       trunk,
		finalLarge:  b.WithInt(b.With(trunk, "leaves", "large"), "stage", 1),
		topLarge:    b.With(trunk, "leaves", "large"),
		topSmall:    b.With(trunk, "leaves", "small"),
		podzol:      b.MustDefault("podzol"),
		bamboo:      bamboo,
		probability: fround(p),
	}, nil
}

func (f *bambooFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	if !level.IsEmptyBlock(x, y, z) {
		return false
	}
	if c.Surv.CanSurvive(level, f.bamboo, x, y, z) {
		height := r.NextBoundedInt(12) + 5
		if r.NextFloat() < f.probability {
			rr := r.NextBoundedInt(4) + 1
			for xx := x - rr; xx <= x+rr; xx++ {
				for zz := z - rr; zz <= z+rr; zz++ {
					xd := xx - x
					zd := zz - z
					if xd*xd+zd*zd > rr*rr {
						continue
					}
					py := level.GetHeight(WorldSurface, xx, zz) - 1
					if c.B.IsTagged(level.GetState(xx, py, zz), "beneath_bamboo_podzol_replaceable") {
						level.SetState(xx, py, zz, f.podzol)
					}
				}
			}
		}
		by := y
		for i := 0; i < height && level.IsEmptyBlock(x, by, z); i++ {
			level.SetState(x, by, z, f.trunk)
			by++
		}
		if by-y >= 3 {
			level.SetState(x, by, z, f.finalLarge)
			level.SetState(x, by-1, z, f.topLarge)
			level.SetState(x, by-2, z, f.topSmall)
		}
	}
	return true
}

type coralTreeFeature struct{ feature *PlacedFeature }

func newCoralTree(reg *Registry, json obj) (Feature, error) {
	p, err := reg.PlacedRef(json["feature"])
	if err != nil {
		return nil, err
	}
	return &coralTreeFeature{p}, nil
}

func (f *coralTreeFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	trunkHeight := r.NextBoundedInt(3) + 1
	for i := 0; i < trunkHeight; i++ {
		if !f.feature.Place(c, x, y, z) {
			return true
		}
		y++
	}
	nBranches := r.NextBoundedInt(3) + 2
	directions := utilShuffle([]int{North, East, South, West}, r)[:nBranches]
	for _, d := range directions {
		px, py, pz := x+StepX[d], y, z+StepZ[d]
		branchHeight := r.NextBoundedInt(5) + 2
		segment := 0
		for j := 0; j < branchHeight; j++ {
			if !f.feature.Place(c, px, py, pz) {
				break
			}
			segment++
			py++
			if j == 0 || (segment >= 2 && r.NextFloat() < 0.25) {
				px += StepX[d]
				pz += StepZ[d]
				segment = 0
			}
		}
	}
	return true
}

type coralClawFeature struct{ feature *PlacedFeature }

func newCoralClaw(reg *Registry, json obj) (Feature, error) {
	p, err := reg.PlacedRef(json["feature"])
	if err != nil {
		return nil, err
	}
	return &coralClawFeature{p}, nil
}

func (f *coralClawFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	if !f.feature.Place(c, x, y, z) {
		return false
	}
	claw := PlaneHorizontal[r.NextBoundedInt(4)]
	nBranches := r.NextBoundedInt(2) + 2
	possible := utilShuffle([]int{claw, clockWise(claw), counterClockWise(claw)}, r)
	if nBranches > len(possible) {
		nBranches = len(possible)
	}
	for _, branch := range possible[:nBranches] {
		px, py, pz := x, y, z
		sideway := r.NextBoundedInt(2) + 1
		px += StepX[branch]
		pz += StepZ[branch]
		var segment, inway int
		if branch == claw {
			segment = claw
			inway = r.NextBoundedInt(3) + 2
		} else {
			py++
			segment = [2]int{branch, Up}[r.NextBoundedInt(2)]
			inway = r.NextBoundedInt(3) + 3
		}
		for i := 0; i < sideway; i++ {
			if !f.feature.Place(c, px, py, pz) {
				break
			}
			px += StepX[segment]
			py += StepY[segment]
			pz += StepZ[segment]
		}
		px -= StepX[segment]
		py -= StepY[segment]
		pz -= StepZ[segment]
		py++
		for i := 0; i < inway; i++ {
			px += StepX[claw]
			pz += StepZ[claw]
			if !f.feature.Place(c, px, py, pz) {
				break
			}
			if r.NextFloat() < 0.25 {
				py++
			}
		}
	}
	return true
}
