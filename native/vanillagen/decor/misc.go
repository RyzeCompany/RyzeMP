package decor

import (
	"math"
)

// Features of type/MiscFeatures.php.

type underwaterMagmaFeature struct {
	rng, radius int
	chance      float64
	magma       int
}

func newUnderwaterMagma(reg *Registry, json obj) (Feature, error) {
	rng, err1 := jInt(json, "floor_search_range")
	radius, err2 := jInt(json, "placement_radius_around_floor")
	chance, err3 := jFloat(json, "placement_probability_per_valid_position")
	if err := firstErr(err1, err2, err3); err != nil {
		return nil, err
	}
	magma, err := reg.B.DefaultOf("magma_block")
	if err != nil {
		return nil, err
	}
	return &underwaterMagmaFeature{rng, radius, fround(chance), magma}, nil
}

func (f *underwaterMagmaFeature) isWater(c *Ctx, x, y, z int) bool {
	return int(c.B.BlockOf[c.Level.GetState(x, y, z)]) == c.B.WaterBlock
}

func (f *underwaterMagmaFeature) visible(c *Ctx, x, y, z int) bool {
	return !c.Surv.IsSolidRender(c.Level.GetState(x, y, z))
}

func (f *underwaterMagmaFeature) Place(c *Ctx, x, y, z int) bool {
	//Column.scan() downwards in the water column
	if !f.isWater(c, x, y, z) {
		return false
	}
	fy := y
	for i := 1; i < f.rng && f.isWater(c, x, fy, z); i++ {
		fy--
	}
	if f.isWater(c, x, fy, z) {
		return false
	}
	placed := 0
	r := f.radius
	for pz := z - r; pz <= z+r; pz++ {
		for py := fy - r; py <= fy+r; py++ {
			for px := x - r; px <= x+r; px++ {
				if !(c.R.NextFloat() < f.chance) {
					continue
				}
				state := c.Level.GetState(px, py, pz)
				if c.B.IsAir(state) || int(c.B.BlockOf[state]) == c.B.WaterBlock || f.visible(c, px, py-1, pz) {
					continue
				}
				ok := true
				for _, d := range PlaneHorizontal {
					if f.visible(c, px+StepX[d], py, pz+StepZ[d]) {
						ok = false
						break
					}
				}
				if ok {
					c.Level.SetState(px, py, pz, f.magma)
					placed++
				}
			}
		}
	}
	return placed > 0
}

type freezeTopLayerFeature struct{ ice, snow int }

func newFreezeTopLayer(reg *Registry, json obj) (Feature, error) {
	ice, err := reg.B.DefaultOf("ice")
	if err != nil {
		return nil, err
	}
	snow, err := reg.B.DefaultOf("snow")
	if err != nil {
		return nil, err
	}
	return &freezeTopLayerFeature{ice, snow}, nil
}

func (f *freezeTopLayerFeature) Place(c *Ctx, x, y, z int) bool {
	climate := c.Reg.Climate()
	level := c.Level
	b := c.B
	for dx := 0; dx < 16; dx++ {
		for dz := 0; dz < 16; dz++ {
			px := x + dx
			pz := z + dz
			py := level.GetHeight(MotionBlocking, px, pz)
			if climate.ShouldFreeze(c, px, py-1, pz, false, level.GetBiome(px, py, pz)) {
				level.SetState(px, py-1, pz, f.ice)
			}
			if climate.ShouldSnow(c, px, py, pz, level.GetBiome(px, py, pz)) {
				level.SetState(px, py, pz, f.snow)
				below := level.GetState(px, py-1, pz)
				if b.HasProperty(below, "snowy") {
					level.SetState(px, py-1, pz, b.WithBool(below, "snowy", true))
				}
			}
		}
	}
	return true
}

type blockPileFeature struct {
	provider StateProvider
	dirtPath int
}

func newBlockPile(reg *Registry, json obj) (Feature, error) {
	p, err := reg.ParseStateProvider(json["state_provider"])
	if err != nil {
		return nil, err
	}
	dirtPath := -1
	if i, err := reg.B.BlockIndex("dirt_path"); err == nil {
		dirtPath = i
	}
	return &blockPileFeature{p, dirtPath}, nil
}

func (f *blockPileFeature) tryPlace(c *Ctx, x, y, z int) {
	level := c.Level
	b := c.B
	if !b.IsAir(level.GetState(x, y, z)) {
		return
	}
	below := level.GetState(x, y-1, z)
	var mayPlace bool
	if int(b.BlockOf[below]) == f.dirtPath {
		mayPlace = c.R.NextBoolean()
	} else {
		mayPlace = b.IsFaceSturdy(below, Up)
	}
	if mayPlace {
		if state := f.provider(c, c.R, x, y, z); state >= 0 {
			level.SetState(x, y, z, state)
		}
	}
}

func (f *blockPileFeature) Place(c *Ctx, x, y, z int) bool {
	if y < c.Level.MinY+5 {
		return false
	}
	r := c.R
	i := 2 + r.NextBoundedInt(2)
	j := 2 + r.NextBoundedInt(2)
	//BlockPos.betweenClosed(): x fastest, then y, then z
	for pz := z - j; pz <= z+j; pz++ {
		for py := y; py <= y+1; py++ {
			for px := x - i; px <= x+i; px++ {
				dx := x - px
				dz := z - pz
				a := float64(r.NextFloat() * 10.0)
				if float64(dx*dx+dz*dz) <= a-float64(r.NextFloat()*6.0) {
					f.tryPlace(c, px, py, pz)
				} else if r.NextFloat() < 0.031 {
					f.tryPlace(c, px, py, pz)
				}
			}
		}
	}
	return true
}

type blueIceFeature struct{ blueIce, packedIce, iceBlock, blueIceBlock int }

func newBlueIce(reg *Registry, json obj) (Feature, error) {
	b := reg.B
	return &blueIceFeature{b.MustDefault("blue_ice"), b.MustBlock("packed_ice"), b.MustBlock("ice"), b.MustBlock("blue_ice")}, nil
}

func (f *blueIceFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	b := c.B
	r := c.R
	if y > c.SeaLevel-1 {
		return false
	}
	if int(b.BlockOf[level.GetState(x, y, z)]) != b.WaterBlock && int(b.BlockOf[level.GetState(x, y-1, z)]) != b.WaterBlock {
		return false
	}
	found := false
	for d := 1; d < 6; d++ {
		if int(b.BlockOf[level.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])]) == f.packedIce {
			found = true
			break
		}
	}
	if !found {
		return false
	}
	level.SetState(x, y, z, f.blueIce)
	for i := 0; i < 200; i++ {
		a := r.NextBoundedInt(5)
		yOff := a - r.NextBoundedInt(6)
		xzDiff := 3
		if yOff < 2 {
			xzDiff += yOff / 2
		}
		if xzDiff < 1 {
			continue
		}
		a = r.NextBoundedInt(xzDiff)
		px := x + a - r.NextBoundedInt(xzDiff)
		py := y + yOff
		a = r.NextBoundedInt(xzDiff)
		pz := z + a - r.NextBoundedInt(xzDiff)
		state := level.GetState(px, py, pz)
		block := int(b.BlockOf[state])
		if !b.IsAir(state) && block != b.WaterBlock && block != f.packedIce && block != f.iceBlock {
			continue
		}
		for d := 0; d < 6; d++ {
			if int(b.BlockOf[level.GetState(px+StepX[d], py+StepY[d], pz+StepZ[d])]) == f.blueIceBlock {
				level.SetState(px, py, pz, f.blueIce)
				break
			}
		}
	}
	return true
}

type spikeFeature struct {
	state                  int
	canPlaceOn, canReplace BlockPredicate
}

func newSpike(reg *Registry, json obj) (Feature, error) {
	state, err := reg.B.StateFromJSON(json["state"])
	if err != nil {
		return nil, err
	}
	on, err := parseBlockPredicate(reg, json["can_place_on"])
	if err != nil {
		return nil, err
	}
	repl, err := parseBlockPredicate(reg, json["can_replace"])
	if err != nil {
		return nil, err
	}
	return &spikeFeature{state, on, repl}, nil
}

func (f *spikeFeature) replaceable(c *Ctx, x, y, z int) bool {
	return c.B.IsAir(c.Level.GetState(x, y, z)) || f.canReplace(c, x, y, z)
}

func (f *spikeFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	for level.IsEmptyBlock(x, y, z) && y > level.MinY+2 {
		y--
	}
	if !f.canPlaceOn(c, x, y, z) {
		return false
	}
	y += r.NextBoundedInt(4)
	height := r.NextBoundedInt(4) + 7
	width := height/4 + r.NextBoundedInt(2)
	if width > 1 && r.NextBoundedInt(60) == 0 {
		y += 10 + r.NextBoundedInt(30)
	}
	for yOff := 0; yOff < height; yOff++ {
		scale := fround(float64(fround(1.0-fround(float64(yOff)/float64(height))) * float64(width)))
		newWidth := phpIntOfFloat(math.Ceil(scale))
		for xo := -newWidth; xo <= newWidth; xo++ {
			dx := fround(float64(absInt(xo)) - 0.25)
			for zo := -newWidth; zo <= newWidth; zo++ {
				dz := fround(float64(absInt(zo)) - 0.25)
				if (xo != 0 || zo != 0) && fround(fround(float64(dx*dx))+fround(float64(dz*dz))) > fround(float64(scale*scale)) {
					continue
				}
				if (xo == -newWidth || xo == newWidth || zo == -newWidth || zo == newWidth) && r.NextFloat() > 0.75 {
					continue
				}
				if f.replaceable(c, x+xo, y+yOff, z+zo) {
					level.SetState(x+xo, y+yOff, z+zo, f.state)
				}
				if yOff != 0 && newWidth > 1 && f.replaceable(c, x+xo, y-yOff, z+zo) {
					level.SetState(x+xo, y-yOff, z+zo, f.state)
				}
			}
		}
	}
	pillarWidth := width - 1
	if pillarWidth < 0 {
		pillarWidth = 0
	} else if pillarWidth > 1 {
		pillarWidth = 1
	}
	for xo := -pillarWidth; xo <= pillarWidth; xo++ {
		for zo := -pillarWidth; zo <= pillarWidth; zo++ {
			cy := y - 1
			runLength := 50
			if absInt(xo) == 1 && absInt(zo) == 1 {
				runLength = r.NextBoundedInt(5)
			}
			for cy > 50 {
				current := level.GetState(x+xo, cy, z+zo)
				if !c.B.IsAir(current) && !f.canReplace(c, x+xo, cy, z+zo) && current != f.state {
					break
				}
				level.SetState(x+xo, cy, z+zo, f.state)
				cy--
				runLength--
				if runLength <= 0 {
					cy -= r.NextBoundedInt(5) + 1
					runLength = r.NextBoundedInt(5)
				}
			}
		}
	}
	return true
}

type vinesFeature struct{ vine int }

func newVines(reg *Registry, json obj) (Feature, error) {
	v, err := reg.B.DefaultOf("vine")
	if err != nil {
		return nil, err
	}
	return &vinesFeature{v}, nil
}

func (f *vinesFeature) Place(c *Ctx, x, y, z int) bool {
	if !c.Level.IsEmptyBlock(x, y, z) {
		return false
	}
	for d := 1; d < 6; d++ {
		if c.Surv.CanAttachTo(c.Level, x, y, z, d) {
			c.Level.SetState(x, y, z, c.B.WithBool(f.vine, DirNames[d], true))
			return true
		}
	}
	return false
}

type blockBlobFeature struct {
	state      int
	canPlaceOn BlockPredicate
}

func newBlockBlob(reg *Registry, json obj) (Feature, error) {
	state, err := reg.B.StateFromJSON(json["state"])
	if err != nil {
		return nil, err
	}
	on, err := parseBlockPredicate(reg, json["can_place_on"])
	if err != nil {
		return nil, err
	}
	return &blockBlobFeature{state, on}, nil
}

func (f *blockBlobFeature) Place(c *Ctx, x, y, z int) bool {
	level := c.Level
	r := c.R
	for y > level.MinY+3 && !f.canPlaceOn(c, x, y-1, z) {
		y--
	}
	if y <= level.MinY+3 {
		return false
	}
	for n := 0; n < 3; n++ {
		xr := r.NextBoundedInt(2)
		yr := r.NextBoundedInt(2)
		zr := r.NextBoundedInt(2)
		tr := fround(fround(float64(float64(xr+yr+zr)*0.3330000042915344)) + 0.5)
		for pz := z - zr; pz <= z+zr; pz++ {
			for py := y - yr; py <= y+yr; py++ {
				for px := x - xr; px <= x+xr; px++ {
					dx := px - x
					dy := py - y
					dz := pz - z
					if float64(dx*dx+dy*dy+dz*dz) <= fround(float64(tr*tr)) {
						level.SetState(px, py, pz, f.state)
					}
				}
			}
		}
		x += -1 + r.NextBoundedInt(2)
		y += -r.NextBoundedInt(2)
		z += -1 + r.NextBoundedInt(2)
	}
	return true
}

type overlayFeature struct{ features []*PlacedFeature }

func newOverlay(reg *Registry, json obj) (Feature, error) {
	list, ok := json["features"].([]any)
	if !ok {
		list = []any{json["features"]}
	}
	f := &overlayFeature{}
	for _, e := range list {
		p, err := reg.PlacedRef(e)
		if err != nil {
			return nil, err
		}
		f.features = append(f.features, p)
	}
	return f, nil
}

func (f *overlayFeature) Place(c *Ctx, x, y, z int) bool {
	placed := false
	for _, p := range f.features {
		placed = p.Place(c, x, y, z) || placed
	}
	return placed
}

func absInt(v int) int {
	if v < 0 {
		return -v
	}
	return v
}
