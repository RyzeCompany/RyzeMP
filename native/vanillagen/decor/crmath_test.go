package decor

import (
	"math/big"
	"testing"
)

const bigPrec = 300

// bigSinCos is a 300-bit Taylor series (x small enough for direct summation).
func bigSinCos(x float64, cos bool) float64 {
	X := new(big.Float).SetPrec(bigPrec).SetFloat64(x)
	x2 := new(big.Float).SetPrec(bigPrec).Mul(X, X)
	term := new(big.Float).SetPrec(bigPrec).Set(X)
	start := 1
	if cos {
		term.SetInt64(1)
		start = 0
	}
	sum := new(big.Float).SetPrec(bigPrec).Set(term)
	for i := 1; i < 90; i++ {
		term.Mul(term, x2)
		term.Quo(term, new(big.Float).SetPrec(bigPrec).SetInt64(int64((2*i+start-1)*(2*i+start))))
		term.Neg(term)
		sum.Add(sum, term)
	}
	f, _ := sum.Float64()
	return f
}

// bigLog: log(x) = 2 atanh((x - 1) / (x + 1)), 300 bits (x near 1 only).
func bigLog(x float64) float64 {
	X := new(big.Float).SetPrec(bigPrec).SetFloat64(x)
	one := new(big.Float).SetPrec(bigPrec).SetInt64(1)
	num := new(big.Float).SetPrec(bigPrec).Sub(X, one)
	den := new(big.Float).SetPrec(bigPrec).Add(X, one)
	s := new(big.Float).SetPrec(bigPrec).Quo(num, den)
	s2 := new(big.Float).SetPrec(bigPrec).Mul(s, s)
	term := new(big.Float).SetPrec(bigPrec).Set(s)
	sum := new(big.Float).SetPrec(bigPrec).Set(s)
	for k := 1; k < 400; k++ {
		term.Mul(term, s2)
		t := new(big.Float).SetPrec(bigPrec).Quo(term, new(big.Float).SetPrec(bigPrec).SetInt64(int64(2*k+1)))
		sum.Add(sum, t)
	}
	sum.Mul(sum, new(big.Float).SetPrec(bigPrec).SetInt64(2))
	f, _ := sum.Float64()
	return f
}

// The double-double functions (used where PHP's C library is correctly rounded) must give the nearest double.
func TestCorrectRounding(t *testing.T) {
	r := &Random{}
	r.SetSeed(12345)
	for i := 0; i < 3000; i++ {
		x := r.NextDouble() * 3.2
		if got, want := crSin(x), bigSinCos(x, false); got != want {
			t.Fatalf("sin(%v) = %v, want %v", x, got, want)
		}
		if got, want := crCos(x), bigSinCos(x, true); got != want {
			t.Fatalf("cos(%v) = %v, want %v", x, got, want)
		}
		y := 0.5 + r.NextDouble()
		if got, want := crLog(y), bigLog(y); got != want {
			t.Fatalf("log(%v) = %v, want %v", y, got, want)
		}
	}
}
