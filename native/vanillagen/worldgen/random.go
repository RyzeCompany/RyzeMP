package worldgen

import (
	"crypto/md5"
	"encoding/binary"
	"math/bits"
)

const (
	silverRatio64 uint64 = 7640891576956012809
	goldenRatio64 uint64 = 0x9E3779B97F4A7C15 // -7046029254386353131
	stafford1     uint64 = 0xBF58476D1CE4E5B9 // -4658895280553007687
	stafford2     uint64 = 0x94D049BB133111EB // -7723592293110705685
)

// Xoroshiro is Xoroshiro128++ as used by the 1.18+ world generator (random/XoroshiroRandom.php). With a non-nil
// leg it is instead the legacy java.util.Random source of noise settings with legacy_random_source (Nether, End):
// every method then delegates to it, so that the noise and material code works with both kinds of world.
type Xoroshiro struct {
	lo, hi uint64
	leg    *LegacyRandom
}

// NewLegacySource is LegacyRandomSource(seed) behind the Xoroshiro API.
func NewLegacySource(seed int64) *Xoroshiro {
	return &Xoroshiro{leg: NewLegacyRandom(seed)}
}

func newXoroshiro(lo, hi uint64) *Xoroshiro {
	if lo == 0 && hi == 0 {
		lo = goldenRatio64
		hi = silverRatio64
	}
	return &Xoroshiro{lo: lo, hi: hi}
}

func mixStafford13(v uint64) uint64 {
	v = (v ^ (v >> 30)) * stafford1
	v = (v ^ (v >> 27)) * stafford2
	return v ^ (v >> 31)
}

// NewXoroshiroFromSeed is XoroshiroRandom::create().
func NewXoroshiroFromSeed(seed int64) *Xoroshiro {
	lo := uint64(seed) ^ silverRatio64
	hi := lo + goldenRatio64
	return newXoroshiro(mixStafford13(lo), mixStafford13(hi))
}

func (r *Xoroshiro) NextLong() int64 {
	if r.leg != nil {
		return r.leg.NextLong()
	}
	lo, hi := r.lo, r.hi
	result := bits.RotateLeft64(lo+hi, 17) + lo
	hi ^= lo
	r.lo = bits.RotateLeft64(lo, 49) ^ hi ^ (hi << 21)
	r.hi = bits.RotateLeft64(hi, 28)
	return int64(result)
}

func (r *Xoroshiro) NextInt() int32 {
	if r.leg != nil {
		return r.leg.NextInt()
	}
	return int32(r.NextLong())
}

// Consume skips random values (consumeCount()).
func (r *Xoroshiro) Consume(count int) {
	if r.leg != nil {
		r.leg.Consume(count)
		return
	}
	for i := 0; i < count; i++ {
		r.NextLong()
	}
}

func (r *Xoroshiro) NextBoundedInt(bound int) int {
	if r.leg != nil {
		return r.leg.NextBoundedInt(bound)
	}
	value := uint64(r.NextLong()) & 0xffffffff
	product := value * uint64(bound)
	productLo := product & 0xffffffff
	if productLo < uint64(bound) {
		threshold := (0x100000000 - uint64(bound)) % uint64(bound)
		for productLo < threshold {
			value = uint64(r.NextLong()) & 0xffffffff
			product = value * uint64(bound)
			productLo = product & 0xffffffff
		}
	}
	return int(product >> 32)
}

func (r *Xoroshiro) NextFloat() float64 {
	if r.leg != nil {
		return r.leg.NextFloat()
	}
	return float64(uint64(r.NextLong())>>40) * 5.9604644775390625e-8
}

func (r *Xoroshiro) NextDouble() float64 {
	if r.leg != nil {
		return r.leg.NextDouble()
	}
	return float64(uint64(r.NextLong())>>11) * 1.1102230246251565e-16
}

func (r *Xoroshiro) Fork() *Xoroshiro {
	if r.leg != nil {
		return NewLegacySource(r.leg.NextLong())
	}
	lo := uint64(r.NextLong())
	hi := uint64(r.NextLong())
	return newXoroshiro(lo, hi)
}

func (r *Xoroshiro) ForkPositional() *PositionalFactory {
	if r.leg != nil {
		return &PositionalFactory{legacy: true, legacySeed: r.leg.NextLong()}
	}
	lo := uint64(r.NextLong())
	hi := uint64(r.NextLong())
	return &PositionalFactory{lo: lo, hi: hi}
}

// PositionalFactory is XoroshiroPositionalRandomFactory, or LegacyPositionalRandomFactory when legacy is set.
type PositionalFactory struct {
	lo, hi     uint64
	legacy     bool
	legacySeed int64
}

func (f *PositionalFactory) At(x, y, z int) *Xoroshiro {
	if f.legacy {
		return NewLegacySource(PositionSeed(x, y, z) ^ f.legacySeed)
	}
	return newXoroshiro(uint64(PositionSeed(x, y, z))^f.lo, f.hi)
}

func (f *PositionalFactory) FromHashOf(name string) *Xoroshiro {
	if f.legacy {
		return NewLegacySource(int64(javaStringHash(name)) ^ f.legacySeed)
	}
	sum := md5.Sum([]byte(name))
	return newXoroshiro(binary.BigEndian.Uint64(sum[0:8])^f.lo, binary.BigEndian.Uint64(sum[8:16])^f.hi)
}

// javaStringHash is String.hashCode() (the names are ASCII).
func javaStringHash(s string) int32 {
	var h int32
	for _, c := range []byte(s) {
		h = 31*h + int32(c)
	}
	return h
}

// PositionSeed is Mth.getSeed (Int64::positionSeed).
func PositionSeed(x, y, z int) int64 {
	l := int64(int32(int64(x)*3129871)) ^ int64(z)*116129781 ^ int64(y)
	l = l*l*42317861 + l*11
	return l >> 16
}

// NewXoroshiro is new XoroshiroRandom(lo, hi) (used by the feature code's WorldgenRandom.fork()).
func NewXoroshiro(lo, hi uint64) *Xoroshiro { return newXoroshiro(lo, hi) }
