package decor

import (
	"math"
	"math/bits"

	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"
)

// Random is WorldgenRandom over Xoroshiro128++ (feature/FeatureRandom.php): next(bits) takes the high bits of the
// raw output and everything else follows java.util.Random on top of next(bits).
type Random struct {
	lo, hi               uint64
	haveNextNextGaussian bool
	nextNextGaussian     float64
}

const (
	silverRatio64 uint64 = 7640891576956012809
	goldenRatio64 uint64 = 0x9E3779B97F4A7C15
)

func mixStafford13(v uint64) uint64 {
	v = (v ^ (v >> 30)) * 0xBF58476D1CE4E5B9
	v = (v ^ (v >> 27)) * 0x94D049BB133111EB
	return v ^ (v >> 31)
}

func (r *Random) SetSeed(seed int64) {
	lo := uint64(seed) ^ silverRatio64
	hi := lo + goldenRatio64
	r.lo = mixStafford13(lo)
	r.hi = mixStafford13(hi)
	if r.lo == 0 && r.hi == 0 {
		r.lo = goldenRatio64
		r.hi = silverRatio64
	}
}

func (r *Random) rawLong() uint64 {
	lo, hi := r.lo, r.hi
	result := bits.RotateLeft64(lo+hi, 17) + lo
	hi ^= lo
	r.lo = bits.RotateLeft64(lo, 49) ^ hi ^ (hi << 21)
	r.hi = bits.RotateLeft64(hi, 28)
	return result
}

// next is (int) (nextLong() >>> (64 - bits)) for 1 <= bits <= 32 (signed for 32 bits).
func (r *Random) next(n uint) int64 {
	v := r.rawLong() >> (64 - n)
	if n == 32 {
		return int64(int32(uint32(v)))
	}
	return int64(v)
}

func (r *Random) NextInt() int { return int(r.next(32)) }

// NextBoundedInt is java.util.Random.nextInt(bound).
func (r *Random) NextBoundedInt(bound int) int {
	if bound <= 0 {
		panic("bound must be positive")
	}
	if bound&(bound-1) == 0 {
		return int((int64(bound) * r.next(31)) >> 31)
	}
	for {
		sample := r.next(31)
		modulo := sample % int64(bound)
		if sample-modulo+int64(bound-1) < 0x80000000 {
			return int(modulo)
		}
	}
}

func (r *Random) NextIntBetweenInclusive(min, max int) int { return r.NextBoundedInt(max-min+1) + min }

func (r *Random) NextIntRange(origin, bound int) int {
	if origin >= bound {
		panic("bound - origin is non positive")
	}
	return origin + r.NextBoundedInt(bound-origin)
}

// MthNextInt is Mth.nextInt(random, min, max): min if min >= max.
func (r *Random) MthNextInt(min, max int) int {
	if min >= max {
		return min
	}
	return r.NextBoundedInt(max-min+1) + min
}

func (r *Random) NextLong() int64 {
	upper := r.next(32)
	lower := r.next(32)
	return upper<<32 + lower
}

func (r *Random) NextBoolean() bool { return r.next(1) != 0 }

// NextFloat is a float32 value in [0, 1) (as a float64).
func (r *Random) NextFloat() float64 { return float64(r.next(24)) * 5.9604644775390625e-8 }

func (r *Random) NextDouble() float64 {
	upper := r.next(26)
	lower := r.next(27)
	return float64(upper<<27+lower) * 1.1102230246251565e-16
}

func (r *Random) NextGaussian() float64 {
	if r.haveNextNextGaussian {
		r.haveNextNextGaussian = false
		return r.nextNextGaussian
	}
	var x, y, s float64
	for {
		x = float64(2.0*r.NextDouble()) - 1.0
		y = float64(2.0*r.NextDouble()) - 1.0
		s = float64(x*x) + float64(y*y)
		if !(s >= 1.0 || s == 0.0) {
			break
		}
	}
	multiplier := math.Sqrt(float64(-2.0*libmLog(s)) / s)
	r.nextNextGaussian = float64(y * multiplier)
	r.haveNextNextGaussian = true
	return float64(x * multiplier)
}

func (r *Random) ConsumeCount(count int) {
	for i := 0; i < count; i++ {
		r.next(32)
	}
}

// Triangle is RandomSource.triangle(mean, spread) for doubles.
func (r *Random) Triangle(mean, spread float64) float64 {
	a := r.NextDouble()
	b := r.NextDouble()
	return mean + float64(spread*(a-b))
}

// SetDecorationSeed is WorldgenRandom.setDecorationSeed(levelSeed, minBlockX, minBlockZ); returns the seed.
func (r *Random) SetDecorationSeed(levelSeed int64, x, z int) int64 {
	r.SetSeed(levelSeed)
	a := r.NextLong() | 1
	b := r.NextLong() | 1
	seed := (int64(x)*a + int64(z)*b) ^ levelSeed
	r.SetSeed(seed)
	return seed
}

// SetFeatureSeed is WorldgenRandom.setFeatureSeed(decorationSeed, index, step).
func (r *Random) SetFeatureSeed(decorationSeed int64, index, step int) {
	r.SetSeed(decorationSeed + int64(index+10000*step))
}

// Fork is WorldgenRandom.fork(): a plain Xoroshiro source (with its own nextInt(bound) semantics).
func (r *Random) Fork() *worldgen.Xoroshiro {
	lo := r.rawLong()
	hi := r.rawLong()
	return worldgen.NewXoroshiro(lo, hi)
}
