package decor

// Correctly rounded sin, cos and log in double-double arithmetic (about 104 bits): the result is the nearest double
// unless the exact value lies within 2^-104 of a rounding midpoint. Exact products use Dekker's splitting rather than
// fused multiply-adds (build.ps1 forbids fused instructions in these packages).

import "math"

// double-double helpers
type dd struct{ hi, lo float64 }

func twoSum(a, b float64) (float64, float64) {
	s := a + b
	bb := s - a
	e := (a - (s - bb)) + (b - bb)
	return s, e
}
func quickTwoSum(a, b float64) (float64, float64) {
	s := a + b
	e := b - (s - a)
	return s, e
}

// split is Veltkamp's splitting of a double into two 26-bit halves.
func split(a float64) (float64, float64) {
	c := float64(134217729.0 * a)
	hi := c - (c - a)
	return hi, a - hi
}

// twoProd is Dekker's exact product: p + e == a * b exactly (no overflow in the features' ranges).
func twoProd(a, b float64) (float64, float64) {
	p := float64(a * b)
	ah, al := split(a)
	bh, bl := split(b)
	e := ((float64(ah*bh) - p) + float64(ah*bl) + float64(al*bh)) + float64(al*bl)
	return p, e
}
func ddAdd(a, b dd) dd {
	s, e := twoSum(a.hi, b.hi)
	t, f := twoSum(a.lo, b.lo)
	e += t
	s, e = quickTwoSum(s, e)
	e += f
	s, e = quickTwoSum(s, e)
	return dd{s, e}
}
func ddMul(a, b dd) dd {
	p, e := twoProd(a.hi, b.hi)
	e += a.hi*b.lo + a.lo*b.hi
	p, e = quickTwoSum(p, e)
	return dd{p, e}
}
func ddMulF(a dd, b float64) dd {
	p, e := twoProd(a.hi, b)
	e += a.lo * b
	p, e = quickTwoSum(p, e)
	return dd{p, e}
}
func ddDiv(a, b dd) dd {
	q1 := a.hi / b.hi
	r := ddAdd(a, ddMulF(b, -q1))
	q2 := r.hi / b.hi
	r = ddAdd(r, ddMulF(b, -q2))
	q3 := r.hi / b.hi
	q1, q2 = quickTwoSum(q1, q2)
	return ddAdd(dd{q1, q2}, dd{q3, 0})
}

// pi/2 as three doubles
var (
	pio2_1 = 1.5707963267948966
	pio2_2 = 6.123233995736766e-17
	pio2_3 = -1.4973849048591698e-33
)

func reduce(x float64) (dd, int) {
	k := math.Round(x / (math.Pi / 2))
	// r = x - k*pio2 in dd (k small)
	p1, e1 := twoProd(k, pio2_1)
	r := ddAdd(dd{x, 0}, dd{-p1, -e1})
	p2, e2 := twoProd(k, pio2_2)
	r = ddAdd(r, dd{-p2, -e2})
	r = ddAdd(r, dd{-k * pio2_3, 0})
	return r, int(k) & 3
}

func sinTaylor(r dd) dd {
	r2 := ddMul(r, r)
	term := r
	sum := r
	for i := 1; i < 30; i++ {
		term = ddMul(term, r2)
		term = ddDiv(term, dd{float64((2 * i) * (2*i + 1)), 0})
		if i%2 == 1 {
			sum = ddAdd(sum, dd{-term.hi, -term.lo})
		} else {
			sum = ddAdd(sum, term)
		}
		if math.Abs(term.hi) < 1e-40*math.Abs(sum.hi) {
			break
		}
	}
	return sum
}
func cosTaylor(r dd) dd {
	r2 := ddMul(r, r)
	term := dd{1, 0}
	sum := dd{1, 0}
	for i := 1; i < 30; i++ {
		term = ddMul(term, r2)
		term = ddDiv(term, dd{float64((2*i - 1) * (2 * i)), 0})
		if i%2 == 1 {
			sum = ddAdd(sum, dd{-term.hi, -term.lo})
		} else {
			sum = ddAdd(sum, term)
		}
		if math.Abs(term.hi) < 1e-40 {
			break
		}
	}
	return sum
}

func crSin(x float64) float64 {
	r, q := reduce(x)
	var v dd
	switch q {
	case 0:
		v = sinTaylor(r)
	case 1:
		v = cosTaylor(r)
	case 2:
		v = sinTaylor(r)
		v = dd{-v.hi, -v.lo}
	default:
		v = cosTaylor(r)
		v = dd{-v.hi, -v.lo}
	}
	return v.hi + v.lo
}
func crCos(x float64) float64 {
	r, q := reduce(x)
	var v dd
	switch q {
	case 0:
		v = cosTaylor(r)
	case 1:
		v = sinTaylor(r)
		v = dd{-v.hi, -v.lo}
	case 2:
		v = cosTaylor(r)
		v = dd{-v.hi, -v.lo}
	default:
		v = sinTaylor(r)
	}
	return v.hi + v.lo
}

var ln2 = dd{0.6931471805599453, 2.3190468138462996e-17}

func crLog(x float64) float64 {
	if !(x > 0) || math.IsInf(x, 1) || x < 0x1p-1000 {
		return math.Log(x) // special and tiny values (the features never produce them)
	}
	m, e := math.Frexp(x) // x = m*2^e, m in [0.5,1)
	if m < math.Sqrt2/2 {
		m *= 2
		e--
	}
	// s = (m-1)/(m+1): m-1 is exact (Sterbenz), m+1 is kept exact as a double-double
	mp1h, mp1l := twoSum(m, 1)
	s := ddDiv(dd{m - 1, 0}, dd{mp1h, mp1l})
	s2 := ddMul(s, s)
	term := s
	sum := s
	for k := 1; k < 60; k++ {
		term = ddMul(term, s2)
		t := ddDiv(term, dd{float64(2*k + 1), 0})
		sum = ddAdd(sum, t)
		if math.Abs(t.hi) < 1e-36*math.Abs(sum.hi)+1e-300 {
			break
		}
	}
	sum = ddMulF(sum, 2)
	res := ddAdd(ddMulF(ln2, float64(e)), sum)
	return res.hi + res.lo
}

// ddLog is log(x) as a double-double (x finite and positive).
func ddLog(x float64) dd {
	m, e := math.Frexp(x)
	if m < math.Sqrt2/2 {
		m *= 2
		e--
	}
	mp1h, mp1l := twoSum(m, 1)
	s := ddDiv(dd{m - 1, 0}, dd{mp1h, mp1l})
	s2 := ddMul(s, s)
	term := s
	sum := s
	for k := 1; k < 60; k++ {
		term = ddMul(term, s2)
		t := ddDiv(term, dd{float64(2*k + 1), 0})
		sum = ddAdd(sum, t)
		if math.Abs(t.hi) < 1e-36*math.Abs(sum.hi)+1e-300 {
			break
		}
	}
	sum = ddMulF(sum, 2)
	return ddAdd(ddMulF(ln2, float64(e)), sum)
}

// ddExp is exp(a) for a double-double argument of moderate size.
func ddExp(a dd) dd {
	k := math.Round(a.hi / ln2.hi)
	r := ddAdd(a, ddMulF(dd{-ln2.hi, -ln2.lo}, k))
	term := dd{1, 0}
	sum := dd{1, 0}
	for i := 1; i < 40; i++ {
		term = ddDiv(ddMul(term, r), dd{float64(i), 0})
		sum = ddAdd(sum, term)
		if math.Abs(term.hi) < 1e-36*math.Abs(sum.hi) {
			break
		}
	}
	return dd{math.Ldexp(sum.hi, int(k)), math.Ldexp(sum.lo, int(k))}
}

// crPow is a correctly rounded pow(x, y) for finite positive x and moderate results (other cases use math.Pow,
// which is exact for them or never reached by the features).
func crPow(x, y float64) float64 {
	if !(x > 0) || math.IsInf(x, 0) || math.IsInf(y, 0) || math.IsNaN(y) || x < 0x1p-1000 || x > 0x1p1000 || math.Abs(y) > 1e6 {
		return math.Pow(x, y)
	}
	l := ddLog(x)
	p := ddMulF(l, y)
	if math.Abs(p.hi) > 700 {
		return math.Pow(x, y)
	}
	v := ddExp(p)
	return v.hi + v.lo
}
