package decor

import "sync"

// PiF is 3.1415927F.
const PiF = 3.1415927410125732

var (
	sinTableOnce sync.Once
	sinTable     []float64
)

func mthTable() []float64 {
	sinTableOnce.Do(func() {
		t := make([]float64, 65536)
		for i := range t {
			t[i] = fround(libmSin(float64(i) / 10430.378350470453))
		}
		sinTable = t
	})
	return sinTable
}

// MthSin is Mth.sin(double): a float from the 65536-entry table.
func MthSin(x float64) float64 {
	return mthTable()[phpIntOfFloat(float64(x*10430.378350470453))&65535]
}

// MthCos is Mth.cos(double).
func MthCos(x float64) float64 {
	return mthTable()[phpIntOfFloat(float64(x*10430.378350470453)+16384.0)&65535]
}

// LerpF is Mth.lerp(float alpha, float a, float b) in float32.
func LerpF(alpha, a, b float64) float64 {
	return fround(a + fround(float64(alpha*fround(b-a))))
}
