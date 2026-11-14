//go:build windows

package decor

import (
	"math"
	"syscall"
)

// PHP on Windows takes sin, cos and log from the Universal CRT, whose results are not always correctly rounded (and
// differ from Go's math package in about a fifth of the cases). The same functions are called here, so that the
// features compute exactly what the PHP implementation computes. The Go Windows syscall path loads the first
// arguments into XMM0-3 as well and returns XMM0 in r2, which is what a double(double) function needs.
var (
	ucrt    = syscall.NewLazyDLL("ucrtbase.dll")
	procSin = ucrt.NewProc("sin")
	procCos = ucrt.NewProc("cos")
	procLog = ucrt.NewProc("log")
	procPow = ucrt.NewProc("pow")
)

func libmCall(p *syscall.LazyProc, x float64) float64 {
	_, r2, _ := p.Call(uintptr(math.Float64bits(x)))
	return math.Float64frombits(uint64(r2))
}

func libmSin(x float64) float64 { return libmCall(procSin, x) }
func libmCos(x float64) float64 { return libmCall(procCos, x) }
func libmLog(x float64) float64 { return libmCall(procLog, x) }

// libmPow is pow(x, y): the second argument goes in XMM1 (the syscall path loads the first four arguments into
// XMM0-3 as well).
func libmPow(x, y float64) float64 {
	_, r2, _ := procPow.Call(uintptr(math.Float64bits(x)), uintptr(math.Float64bits(y)))
	return math.Float64frombits(uint64(r2))
}

// LibmCheck loads the C library functions (an error disables native decoration).
func LibmCheck() error {
	for _, p := range []*syscall.LazyProc{procSin, procCos, procLog, procPow} {
		if err := p.Find(); err != nil {
			return err
		}
	}
	if libmSin(0.5) != 0.479425538604203 || libmLog(2) != 0.6931471805599453 {
		return errLibm
	}
	return nil
}
