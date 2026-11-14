package worldgen

import (
	"math"
)

const (
	MaterialAir   = 0
	MaterialStone = 1
	MaterialWater = 2
	MaterialLava  = 3

	aquiferMinLevel = -2032 << 4
	materialKeep    = -1
)

// surfaceSampling: section offsets sampled around an aquifer center, in vanilla's order (the center first).
var surfaceSampling = [13][2]int{{0, 0}, {-2, -1}, {-1, -1}, {0, -1}, {1, -1}, {-3, 0}, {-2, 0}, {-1, 0}, {1, 0}, {-2, 1}, {-1, 1}, {0, 1}, {1, 1}}

type fluidStatus struct{ level, material int }

// Aquifer is the noise aquifer of one chunk (Aquifer.php).
type Aquifer struct {
	r                  *Router
	fn                 *aquiferFunctions
	random             *PositionalFactory
	locationCache      map[int64][3]int
	statusCache        map[int64]fluidStatus
	surfaceCache       map[int64]int
	skipSamplingAboveY int
	anchorX            int
	anchorY            int
	anchorZ            int
	anchorPoints       [36]int
	anchorKeys         [12]int64
}

// aquiferFunctions are the compiled aquifer density functions of the noise router.
type aquiferFunctions struct {
	barrier, floodedness, spread, lava, exclusion, surface Eval
}

func NewAquifer(r *Router, fn *aquiferFunctions, random *PositionalFactory, chunkX, chunkZ int) *Aquifer {
	a := &Aquifer{r: r, fn: fn, random: random, locationCache: map[int64][3]int{}, statusCache: map[int64]fluidStatus{}, surfaceCache: map[int64]int{},
		anchorX: math.MinInt64, anchorY: math.MinInt64, anchorZ: math.MinInt64}
	minGridX := floorDiv(chunkX<<4, 16) - 1
	maxGridX := floorDiv((chunkX<<4)+15, 16) + 1
	minGridZ := floorDiv(chunkZ<<4, 16) - 1
	maxGridZ := floorDiv((chunkZ<<4)+15, 16) + 1
	maxSurface := math.MinInt64
	for z := minGridZ * 16; z <= maxGridZ*16+9; z += 4 {
		for x := minGridX * 16; x <= maxGridX*16+9; x += 4 {
			if s := a.surfaceLevelAt(x, z); s > maxSurface {
				maxSurface = s
			}
		}
	}
	adjusted := maxSurface + 8
	a.skipSamplingAboveY = (floorDiv(adjusted+12, 12)+1)*12 + 11 - 1
	return a
}

func (a *Aquifer) SkipSamplingAboveY() int { return a.skipSamplingAboveY }

func (a *Aquifer) globalFluid(y int) fluidStatus {
	sea := a.r.SeaLevel
	lim := -54
	if sea < lim {
		lim = sea
	}
	if y < lim {
		return fluidStatus{-54, MaterialLava}
	}
	return fluidStatus{sea, MaterialWater}
}

func (a *Aquifer) surfaceLevelAt(x, z int) int {
	qx := (x >> 2) << 2
	qz := (z >> 2) << 2
	key := int64(uint64(uint32(qx))<<32 | uint64(uint32(qz)))
	if v, ok := a.surfaceCache[key]; ok {
		return v
	}
	v := phpInt(math.Floor(a.fn.surface(float64(qx), 0, float64(qz))))
	a.surfaceCache[key] = v
	return v
}

// ComputeSubstance returns a Material* constant, or -1 when the block stays solid.
func (a *Aquifer) ComputeSubstance(x, y, z int, density float64) int {
	if density > 0 {
		return materialKeep
	}
	global := a.globalFluid(y)
	if y > a.skipSamplingAboveY {
		if y < global.level {
			return global.material
		}
		return MaterialAir
	}
	if y < global.level && global.material == MaterialLava {
		return MaterialLava
	}

	gx := (x - 5) >> 4
	gy := floorDiv(y+1, 12)
	gz := (z - 5) >> 4
	if gx != a.anchorX || gy != a.anchorY || gz != a.anchorZ {
		a.loadAnchor(gx, gy, gz)
	}
	d1, d2, d3 := math.MaxInt64, math.MaxInt64, math.MaxInt64
	var k1, k2, k3 int64
	for i, o := 0, 0; i < 12; i, o = i+1, o+3 {
		dx := a.anchorPoints[o] - x
		dy := a.anchorPoints[o+1] - y
		dz := a.anchorPoints[o+2] - z
		d := dx*dx + dy*dy + dz*dz
		// vanilla: a center at the same distance as a previous one goes before it
		if d1 >= d {
			d3, k3 = d2, k2
			d2, k2 = d1, k1
			d1, k1 = d, a.anchorKeys[i]
		} else if d2 >= d {
			d3, k3 = d2, k2
			d2, k2 = d, a.anchorKeys[i]
		} else if d3 >= d {
			d3, k3 = d, a.anchorKeys[i]
		}
	}

	s1 := a.status(k1)
	state := MaterialAir
	if y < s1.level {
		state = s1.material
	}
	sim12 := similarity(d1, d2)
	if sim12 <= 0 {
		return state
	}
	if state == MaterialWater {
		g := a.globalFluid(y - 1)
		if y-1 < g.level && g.material == MaterialLava {
			return state
		}
	}
	barrier := math.NaN()
	hasBarrier := false
	s2 := a.status(k2)
	if density+float64(sim12*a.pressure(x, y, z, &barrier, &hasBarrier, s1, s2)) > 0 {
		return materialKeep
	}
	s3 := a.status(k3)
	sim13 := similarity(d1, d3)
	if sim13 > 0 {
		if density+float64(float64(sim12*sim13)*a.pressure(x, y, z, &barrier, &hasBarrier, s1, s3)) > 0 {
			return materialKeep
		}
	}
	sim23 := similarity(d2, d3)
	if sim23 > 0 {
		if density+float64(float64(sim12*sim23)*a.pressure(x, y, z, &barrier, &hasBarrier, s2, s3)) > 0 {
			return materialKeep
		}
	}
	return state
}

func (a *Aquifer) loadAnchor(gx, gy, gz int) {
	a.anchorX, a.anchorY, a.anchorZ = gx, gy, gz
	i := 0
	for ox := 0; ox <= 1; ox++ {
		for oy := -1; oy <= 1; oy++ {
			for oz := 0; oz <= 1; oz++ {
				cx, cy, cz := gx+ox, gy+oy, gz+oz
				key := int64((cx&0x1fffff)<<42 | (cy&0x1fffff)<<21 | (cz & 0x1fffff))
				loc, ok := a.locationCache[key]
				if !ok {
					r := a.random.At(cx, cy, cz)
					lx := cx*16 + r.NextBoundedInt(10)
					ly := cy*12 + r.NextBoundedInt(9)
					lz := cz*16 + r.NextBoundedInt(10)
					loc = [3]int{lx, ly, lz}
					a.locationCache[key] = loc
				}
				a.anchorPoints[i*3] = loc[0]
				a.anchorPoints[i*3+1] = loc[1]
				a.anchorPoints[i*3+2] = loc[2]
				a.anchorKeys[i] = key
				i++
			}
		}
	}
}

func similarity(a, b int) float64 {
	// b >= a (sorted distances)
	return 1.0 - float64(b-a)/25.0
}

func (a *Aquifer) pressure(x, y, z int, barrier *float64, hasBarrier *bool, f1, f2 fluidStatus) float64 {
	s1 := MaterialAir
	if y < f1.level {
		s1 = f1.material
	}
	s2 := MaterialAir
	if y < f2.level {
		s2 = f2.material
	}
	if (s1 == MaterialLava && s2 == MaterialWater) || (s1 == MaterialWater && s2 == MaterialLava) {
		return 2.0
	}
	diff := absInt(f1.level - f2.level)
	if diff == 0 {
		return 0.0
	}
	scaledY := (float64(y) + 0.5) - float64(0.5*float64(f1.level+f2.level))
	o := float64(diff)/2.0 - math.Abs(scaledY)
	var q float64
	if scaledY > 0 {
		if o > 0 {
			q = o / 1.5
		} else {
			q = o / 2.5
		}
	} else {
		pp := 3.0 + o
		if pp > 0 {
			q = pp / 3.0
		} else {
			q = pp / 10.0
		}
	}
	var r float64
	if q >= -2.0 && q <= 2.0 {
		if !*hasBarrier {
			*barrier = fround(a.fn.barrier(float64(x), float64(y), float64(z)))
			*hasBarrier = true
		}
		r = *barrier
	} else {
		r = 0.0
	}
	return 2.0 * (r + q)
}

func (a *Aquifer) status(key int64) fluidStatus {
	if s, ok := a.statusCache[key]; ok {
		return s
	}
	loc := a.locationCache[key]
	s := a.computeFluid(loc[0], loc[1], loc[2])
	a.statusCache[key] = s
	return s
}

func (a *Aquifer) computeFluid(x, y, z int) fluidStatus {
	global := a.globalFluid(y)
	lowest := math.MaxInt64
	centerUnderFluid := false
	for _, s := range surfaceSampling {
		ox, oz := s[0], s[1]
		sx := x + (ox << 4)
		sz := z + (oz << 4)
		surface := a.surfaceLevelAt(sx, sz)
		adjusted := surface + 8
		start := ox == 0 && oz == 0
		if start && y-12 > adjusted {
			return global
		}
		above := y+12 > adjusted
		if above || start {
			g := a.globalFluid(adjusted)
			if adjusted < g.level && g.material != MaterialAir {
				if start {
					centerUnderFluid = true
				}
				if above {
					return g
				}
			}
		}
		if surface < lowest {
			lowest = surface
		}
	}
	level := a.fluidSurfaceLevel(x, y, z, global.level, lowest, centerUnderFluid)
	typ := global.material
	if level <= -10 && level != aquiferMinLevel && global.material != MaterialLava {
		lava := fround(a.fn.lava(float64(floorDiv(x, 64)), float64(floorDiv(y, 40)), float64(floorDiv(z, 64))))
		if math.Abs(lava) > 0.3 {
			typ = MaterialLava
		}
	}
	return fluidStatus{level, typ}
}

func (a *Aquifer) fluidSurfaceLevel(x, y, z, globalLevel, lowestSurface int, centerUnderFluid bool) int {
	var partially, fully float64
	// exact position: vanilla's cached erosion/offset volumes only answer for positions on their quart grid, where the
	// cached value is the exact one
	if fround(a.fn.exclusion(float64(x), float64(y), float64(z))) > 0 {
		partially = -1.0
		fully = -1.0
	} else {
		distanceBelowSurface := lowestSurface + 8 - y
		var factor float64
		if centerUnderFluid {
			t := float64(distanceBelowSurface) / 64.0
			zero, one := 0.0, 1.0
			if t < 0 {
				factor = 1.0
			} else if t > 1 {
				factor = 0.0
			} else {
				factor = one + float64(t*(zero-one))
			}
		} else {
			factor = 0.0
		}
		noise := fround(a.fn.floodedness(float64(x), float64(y), float64(z)))
		if noise < -1 {
			noise = -1.0
		} else if noise > 1 {
			noise = 1.0
		}
		zero, one := 0.0, 1.0
		t := (factor - one) / (zero - one)
		lo1, hi1, lo2, hi2 := -0.3, 0.8, -0.8, 0.4
		fullyThreshold := lo1 + float64(t*(hi1-lo1))
		partiallyThreshold := lo2 + float64(t*(hi2-lo2))
		partially = noise - partiallyThreshold
		fully = noise - fullyThreshold
	}
	if fully > 0 {
		return globalLevel
	}
	if partially > 0 {
		cellX := floorDiv(x, 16)
		cellY := floorDiv(y, 40)
		cellZ := floorDiv(z, 16)
		middle := cellY*40 + 20
		// sampled as a float and multiplied by 10f
		spread := fround(float64(fround(a.fn.spread(float64(cellX), float64(cellY), float64(cellZ))) * 10.0))
		quantized := phpInt(math.Floor(spread/3.0)) * 3
		v := middle + quantized
		if lowestSurface < v {
			return lowestSurface
		}
		return v
	}
	return aquiferMinLevel
}
