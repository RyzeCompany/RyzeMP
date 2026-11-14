// Package worldgen is a port of PocketMine-MP's PHP vanilla terrain generator
// (src/world/generator/vanilla, itself ported from misode/deepslate, MIT). It
// must produce exactly the same values as the PHP code, bit for bit.
//
// Floating point rules followed everywhere in this package:
//   - no fused multiply-add. The Go spec allows fusing x*y+z into a single FMA
//     instruction (the arm64 compiler does), which rounds once instead of twice
//     and would make arm64 builds differ from PHP. Products are wrapped in
//     float64() as the spec asks, but Go 1.27 still fuses some of them, so the
//     package must be compiled with -gcflags=<pkg>=-d=fmahash=n: build.ps1 does
//     it and checks every target's disassembly for fused instructions.
//   - expressions that PHP evaluates at run time are never written as Go
//     constant expressions (Go folds constants with exact arithmetic).
package worldgen

import "math"

const veltkamp = 536870913.0 // 2^29 + 1

// fround rounds to the nearest float32 value exactly like the PHP port
// (FloatMath::fround, Veltkamp splitting), including outside the float32 range.
func fround(x float64) float64 {
	c := float64(x * veltkamp)
	return c - (c - x)
}

// flerp is FloatMath::lerp: fround(b + fround(a * fround(c - b))).
func flerp(a, b, c float64) float64 {
	d := fround(c - b)
	d = fround(float64(a * d))
	return fround(b + d)
}

// phpMin / phpMax: PHP 8.4 min()/max() with two float arguments.
func phpMin(a, b float64) float64 {
	if a < b {
		return a
	}
	return b
}

func phpMax(a, b float64) float64 {
	if a > b {
		return a
	}
	return b
}

// phpArrayMin / phpArrayMax: PHP 8.4 min(array)/max(array) of floats.
func phpArrayMin(v []float64) float64 {
	cur := v[0]
	for _, x := range v[1:] {
		if !(cur == x || cur < x) {
			cur = x
		}
	}
	return cur
}

func phpArrayMax(v []float64) float64 {
	cur := v[0]
	for _, x := range v[1:] {
		if cur < x {
			cur = x
		}
	}
	return cur
}

// phpMaxIntFloat is PHP's max(int, float) (generic comparison, NaN never replaces).
func phpMaxIntFloat(a int, b float64) float64 {
	fa := float64(a)
	if !(b == fa) && !(b < fa) {
		return b
	}
	return fa
}

// phpInt is PHP's (int) cast of a float (0 for NaN, infinities and out of range values).
func phpInt(f float64) int {
	if math.IsNaN(f) || math.IsInf(f, 0) || f >= 9.2233720368547758e18 || f < -9.2233720368547758e18 {
		return 0
	}
	return int(f)
}

func floorDiv(a, b int) int {
	q := a / b
	if a%b != 0 && ((a < 0) != (b < 0)) {
		return q - 1
	}
	return q
}

func absInt(a int) int {
	if a < 0 {
		return -a
	}
	return a
}
