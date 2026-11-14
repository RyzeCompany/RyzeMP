package worldgen

import (
	"math"
	"sync"
)

// Terrain adaptations (Beardifier.php constants).
const (
	AdaptNone        = 0
	AdaptBury        = 1
	AdaptBeardThin   = 2
	AdaptBeardBox    = 3
	AdaptEncapsulate = 4

	// RigidSize is the number of ints per rigid: minX, minY, minZ, maxX, maxY, maxZ, adaptation, ground level delta.
	RigidSize = 8
	// JunctionSize is the number of ints per junction: source x, source ground y, source z.
	JunctionSize = 3

	kernelRadius = 12
	kernelSize   = 24
)

// Beardifier is vanilla's Beardifier for one chunk (src/world/generator/vanilla/structure/Beardifier.php): the
// density added to the final density around the rigid pieces and junctions of structures with a terrain adaptation.
// All arithmetic is float32 (fround), in the same order as the PHP code.
type Beardifier struct {
	Rigids    []int
	Junctions []int
	Affected  [6]int // minX, minY, minZ, maxX, maxY, maxZ
}

var (
	beardKernel     []float64
	beardKernelOnce sync.Once
)

// BeardKernel returns Beardifier.BEARD_KERNEL: (float) Math.pow(e, -(dx² + (dy + 0.5)² + dz²) / 16), index
// (z * 24 + x) * 24 + y.
func BeardKernel() []float64 {
	beardKernelOnce.Do(func() {
		k := make([]float64, 0, kernelSize*kernelSize*kernelSize)
		for zi := 0; zi < kernelSize; zi++ {
			for xi := 0; xi < kernelSize; xi++ {
				for yi := 0; yi < kernelSize; yi++ {
					dx := float64(xi - kernelRadius)
					dy := float64(yi-kernelRadius) + 0.5
					dz := float64(zi - kernelRadius)
					distanceSqr := float64(dx*dx) + float64(dy*dy)
					distanceSqr = distanceSqr + float64(dz*dz)
					k = append(k, fround(math.Pow(2.718281828459045, -distanceSqr/16.0)))
				}
			}
		}
		beardKernel = k
	})
	return beardKernel
}

// fastInvSqrt is Mth.fastInvSqrt(double).
func fastInvSqrt(x float64) float64 {
	xhalf := float64(0.5 * x)
	i := int64(math.Float64bits(x))
	i = 6910469410427058090 - (i >> 1)
	y := math.Float64frombits(uint64(i))
	t := float64(xhalf * y)
	t = float64(t * y)
	return float64(y * (1.5 - t))
}

// beardContribution is Beardifier.getBeardContribution(dx, dy, dz, yToGround), before the piece weight.
func beardContribution(kernel []float64, dx, dy, dz, yToGround int) float64 {
	xi := dx + kernelRadius
	yi := dy + kernelRadius
	zi := dz + kernelRadius
	if xi < 0 || xi >= kernelSize || yi < 0 || yi >= kernelSize || zi < 0 || zi >= kernelSize {
		return 0
	}
	dyWithOffset := float64(yToGround) + 0.5
	fdx := float64(dx)
	fdz := float64(dz)
	distanceSqr := fround(fround(fround(float64(fdx*fdx)) + fround(float64(dyWithOffset*dyWithOffset))) + fround(float64(fdz*fdz)))
	inv := fround(fastInvSqrt(fround(distanceSqr / 2.0)))
	value := fround(fround(float64(-dyWithOffset*inv)) / 2.0)
	return fround(float64(value * kernel[(zi*kernelSize+xi)*kernelSize+yi]))
}

// buryContribution is Beardifier.getBuryContribution(dx, dy, dz) with float arguments.
func buryContribution(dx, dy, dz float64) float64 {
	distanceSq := fround(fround(fround(float64(dx*dx)) + fround(float64(dy*dy))) + fround(float64(dz*dz)))
	if distanceSq >= 36.0 {
		return 0
	}
	return fround(1.0 - fround(fround(math.Sqrt(distanceSq))/6.0))
}

type activeRigid struct {
	dx, dz, adaptation, minY, maxY, groundY int
}

type activeJunction struct {
	dx, dz, groundY int
}

// Column returns the density added at each y of a column from fromY to toY (clamped to the affected area): the
// first y and the values, or ok=false if the column is outside the affected area (Beardifier::column()).
func (b *Beardifier) Column(x, z, fromY, toY int) (y0 int, values []float64, ok bool) {
	a := b.Affected
	if x < a[0] || x > a[3] || z < a[2] || z > a[5] {
		return 0, nil, false
	}
	y0 = fromY
	if a[1] > y0 {
		y0 = a[1]
	}
	y1 := toY
	if a[4] < y1 {
		y1 = a[4]
	}
	if y0 > y1 {
		return 0, nil, false
	}
	kernel := BeardKernel()
	var active []activeRigid
	r := b.Rigids
	for i := 0; i+RigidSize <= len(r); i += RigidSize {
		dx := max(0, r[i]-x, x-r[i+3])
		dz := max(0, r[i+2]-z, z-r[i+5])
		adaptation := r[i+6]
		limit := 12
		if adaptation == AdaptBury {
			limit = 6
		}
		if dx < limit && dz < limit && adaptation != AdaptNone {
			active = append(active, activeRigid{dx, dz, adaptation, r[i+1], r[i+4], r[i+1] + r[i+7]})
		}
	}
	var activeJunctions []activeJunction
	j := b.Junctions
	for i := 0; i+JunctionSize <= len(j); i += JunctionSize {
		dx := x - j[i]
		dz := z - j[i+2]
		if dx >= -kernelRadius && dx < kernelRadius && dz >= -kernelRadius && dz < kernelRadius {
			activeJunctions = append(activeJunctions, activeJunction{dx, dz, j[i+1]})
		}
	}
	values = make([]float64, 0, y1-y0+1)
	for y := y0; y <= y1; y++ {
		noise := 0.0
		for _, p := range active {
			dyToGround := y - p.groundY
			var term float64
			switch p.adaptation {
			case AdaptBury:
				term = buryContribution(float64(p.dx), float64(dyToGround)/2.0, float64(p.dz))
			case AdaptBeardThin:
				term = fround(float64(beardContribution(kernel, p.dx, dyToGround, p.dz, dyToGround) * 0.800000011920929))
			case AdaptBeardBox:
				dy := max(0, p.groundY-y, y-p.maxY)
				term = fround(float64(beardContribution(kernel, p.dx, dy, p.dz, dyToGround) * 0.800000011920929))
			default: // encapsulate
				dy := max(0, p.minY-y, y-p.maxY)
				term = fround(float64(buryContribution(float64(p.dx)/2.0, float64(dy)/2.0, float64(p.dz)/2.0) * 0.800000011920929))
			}
			if term != 0 {
				noise = fround(noise + term)
			}
		}
		for _, jn := range activeJunctions {
			dy := y - jn.groundY
			term := fround(float64(beardContribution(kernel, jn.dx, dy, jn.dz, dy) * 0.4000000059604645))
			if term != 0 {
				noise = fround(noise + term)
			}
		}
		values = append(values, noise)
	}
	return y0, values, true
}
