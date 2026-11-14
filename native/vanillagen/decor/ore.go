package decor

import (
	"fmt"
	"math"
)

type oreTarget struct {
	test  RuleTest
	state int
}

// oreFeature is type/OreFeature.php (ore and scattered_ore).
type oreFeature struct {
	targets   []oreTarget
	size      int
	discard   float64
	scattered bool
}

func newOreFeature(reg *Registry, json obj, scattered bool) (Feature, error) {
	size, err := jInt(json, "size")
	if err != nil {
		return nil, err
	}
	discard, err := jFloatDef(json, "discard_chance_on_air_exposure", 0.0)
	if err != nil {
		return nil, err
	}
	f := &oreFeature{size: size, discard: fround(discard), scattered: scattered}
	list, err := jArray(json, "targets")
	if err != nil {
		return nil, err
	}
	for _, t := range list {
		to, ok := asObj(t)
		if !ok {
			return nil, fmt.Errorf("invalid ore target")
		}
		test, err := parseRuleTest(reg.B, to["target"])
		if err != nil {
			return nil, err
		}
		state, err := reg.B.StateFromJSON(to["state"])
		if err != nil {
			return nil, err
		}
		f.targets = append(f.targets, oreTarget{test, state})
	}
	return f, nil
}

func (f *oreFeature) Place(c *Ctx, x, y, z int) bool {
	if f.scattered {
		return f.placeScattered(c, x, y, z)
	}
	return f.placeOre(c, x, y, z)
}

func (f *oreFeature) placeOre(c *Ctx, ox, oy, oz int) bool {
	r := c.R
	size := f.size
	dir := fround(float64(r.NextFloat() * PiF))
	spreadXY := fround(float64(size) / 8.0)
	maxRadius := phpIntOfFloat(math.Ceil(fround(fround(fround(fround(float64(size)/16.0)*2.0)+1.0) / 2.0)))
	sinDir := libmSin(dir)
	cosDir := libmCos(dir)
	x0 := float64(ox) + float64(sinDir*spreadXY)
	x1 := float64(ox) - float64(sinDir*spreadXY)
	z0 := float64(oz) + float64(cosDir*spreadXY)
	z1 := float64(oz) - float64(cosDir*spreadXY)
	y0 := float64(oy + r.NextBoundedInt(3) - 2)
	y1 := float64(oy + r.NextBoundedInt(3) - 2)
	ceilSpread := phpIntOfFloat(math.Ceil(spreadXY))
	xStart := ox - ceilSpread - maxRadius
	yStart := oy - 2 - maxRadius
	zStart := oz - ceilSpread - maxRadius
	sizeXZ := 2 * (ceilSpread + maxRadius)
	sizeY := 2 * (2 + maxRadius)
	for px := xStart; px <= xStart+sizeXZ; px++ {
		for pz := zStart; pz <= zStart+sizeXZ; pz++ {
			if yStart <= c.Level.GetHeight(OceanFloorWG, px, pz) {
				return f.doPlace(c, x0, x1, z0, z1, y0, y1, xStart, yStart, zStart, sizeXZ, sizeY)
			}
		}
	}
	return false
}

func (f *oreFeature) doPlace(c *Ctx, x0, x1, z0, z1, y0, y1 float64, xStart, yStart, zStart, sizeXZ, sizeY int) bool {
	r := c.R
	level := c.Level
	size := f.size
	placed := 0
	// scratch buffers of the session, reused by every vein (a feature is placed by one goroutine at a time)
	if cap(c.oreData) < size*4 {
		c.oreData = make([]float64, size*4)
	}
	data := c.oreData[:size*4]
	fs := float64(size)
	for i := 0; i < size; i++ {
		step := fround(float64(i) / fs)
		xx := x0 + float64(step*(x1-x0))
		yy := y0 + float64(step*(y1-y0))
		zz := z0 + float64(step*(z1-z0))
		ss := float64(r.NextDouble()*fs) / 16.0
		rad := float64(float64(fround(MthSin(fround(float64(PiF*step)))+1.0)*ss)+1.0) / 2.0
		data[i*4] = xx
		data[i*4+1] = yy
		data[i*4+2] = zz
		data[i*4+3] = rad
	}
	for i1 := 0; i1 < size-1; i1++ {
		if !(data[i1*4+3] > 0.0) {
			continue
		}
		for i2 := i1 + 1; i2 < size; i2++ {
			if !(data[i2*4+3] > 0.0) {
				continue
			}
			dx := data[i1*4] - data[i2*4]
			dy := data[i1*4+1] - data[i2*4+1]
			dz := data[i1*4+2] - data[i2*4+2]
			dr := data[i1*4+3] - data[i2*4+3]
			if float64(dr*dr) > float64(float64(dx*dx)+float64(dy*dy))+float64(dz*dz) {
				if dr > 0.0 {
					data[i2*4+3] = -1.0
				} else {
					data[i1*4+3] = -1.0
				}
			}
		}
	}
	// BitSet of the tested positions (grows past sizeXZ * sizeXZ * sizeY when needed, like Java's)
	words := (sizeXZ*sizeXZ*sizeY)>>6 + 1
	if cap(c.oreTested) < words {
		c.oreTested = make([]uint64, words)
	}
	tested := c.oreTested[:words]
	clear(tested)
	for i := 0; i < size; i++ {
		rad := data[i*4+3]
		if !(rad >= 0.0) {
			continue
		}
		xx := data[i*4]
		yy := data[i*4+1]
		zz := data[i*4+2]
		xMin := maxInt(phpIntOfFloat(math.Floor(xx-rad)), xStart)
		yMin := maxInt(phpIntOfFloat(math.Floor(yy-rad)), yStart)
		zMin := maxInt(phpIntOfFloat(math.Floor(zz-rad)), zStart)
		xMax := maxInt(phpIntOfFloat(math.Floor(xx+rad)), xMin)
		yMax := maxInt(phpIntOfFloat(math.Floor(yy+rad)), yMin)
		zMax := maxInt(phpIntOfFloat(math.Floor(zz+rad)), zMin)
		for x := xMin; x <= xMax; x++ {
			xd := ((float64(x) + 0.5) - xx) / rad
			xd2 := float64(xd * xd)
			if !(xd2 < 1.0) {
				continue
			}
			for y := yMin; y <= yMax; y++ {
				yd := ((float64(y) + 0.5) - yy) / rad
				xy := xd2 + float64(yd*yd)
				if !(xy < 1.0) {
					continue
				}
				for z := zMin; z <= zMax; z++ {
					zd := ((float64(z) + 0.5) - zz) / rad
					if !(xy+float64(zd*zd) < 1.0) {
						continue
					}
					if level.IsOutsideBuildHeight(y) {
						continue
					}
					bit := (x - xStart) + (y-yStart)*sizeXZ + (z-zStart)*sizeXZ*sizeY
					w := bit >> 6
					for w >= len(tested) {
						tested = append(tested, 0)
					}
					if tested[w]&(1<<(bit&63)) != 0 {
						continue
					}
					tested[w] |= 1 << (bit & 63)
					if !level.EnsureCanWrite(x, y, z) {
						continue
					}
					state := level.GetState(x, y, z)
					for _, t := range f.targets {
						if f.canPlaceOre(c, state, t.test, x, y, z) {
							level.SetState(x, y, z, t.state)
							placed++
							break
						}
					}
				}
			}
		}
	}
	return placed > 0
}

func (f *oreFeature) canPlaceOre(c *Ctx, state int, test RuleTest, x, y, z int) bool {
	if !test(state, c.R, y) {
		return false
	}
	//shouldSkipAirCheck()
	discard := f.discard
	if discard <= 0.0 {
		return true
	}
	if discard < 1.0 && !(c.R.NextFloat() < discard) {
		return true
	}
	l := c.Level
	b := c.B
	return !(b.IsAir(l.GetState(x, y-1, z)) || b.IsAir(l.GetState(x, y+1, z)) ||
		b.IsAir(l.GetState(x, y, z-1)) || b.IsAir(l.GetState(x, y, z+1)) ||
		b.IsAir(l.GetState(x-1, y, z)) || b.IsAir(l.GetState(x+1, y, z)))
}

func (f *oreFeature) placeScattered(c *Ctx, ox, oy, oz int) bool {
	r := c.R
	level := c.Level
	tries := r.NextBoundedInt(f.size + 1)
	for i := 0; i < tries; i++ {
		max := i
		if max > 7 {
			max = 7
		}
		x := ox + oreAxisOffset(r, max)
		y := oy + oreAxisOffset(r, max)
		z := oz + oreAxisOffset(r, max)
		state := level.GetState(x, y, z)
		for _, t := range f.targets {
			if f.canPlaceOre(c, state, t.test, x, y, z) {
				level.SetState(x, y, z, t.state)
				break
			}
		}
	}
	return true
}

func oreAxisOffset(r *Random, max int) int {
	a := r.NextFloat()
	v := fround(float64(fround(a-r.NextFloat()) * float64(max)))
	return phpIntOfFloat(math.Floor(v + 0.5))
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}
