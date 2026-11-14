//go:build !windows

package decor

// Other platforms: PHP uses the system C library (glibc, Apple's libm), whose sin, cos and log are correctly rounded
// in practice; the double-double implementation computes the correctly rounded value.
func libmSin(x float64) float64    { return crSin(x) }
func libmCos(x float64) float64    { return crCos(x) }
func libmLog(x float64) float64    { return crLog(x) }
func libmPow(x, y float64) float64 { return crPow(x, y) }

func LibmCheck() error {
	if libmSin(0.5) != 0.479425538604203 || libmLog(2) != 0.6931471805599453 {
		return errLibm
	}
	return nil
}
