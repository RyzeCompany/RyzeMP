package decor

import (
	"math"
	"testing"
)

// The C library functions must agree with the correctly rounded implementation where both are exact enough to tell
// (sanity check of the syscall path); exact agreement with PHP is checked by tools/compare-vanilla-decoration.php.
func TestLibmMatchesCorrectRounding(t *testing.T) {
	if err := LibmCheck(); err != nil {
		t.Fatal(err)
	}
	bad := 0
	for i := 0; i < 100000; i++ {
		x := float64(i) * 0.0000314159
		if math.Abs(libmSin(x)-crSin(x)) > 2e-16 || math.Abs(libmCos(x)-crCos(x)) > 2e-16 {
			bad++
		}
	}
	if bad > 0 {
		t.Fatalf("%d results differ by more than one ulp", bad)
	}
	for _, c := range [][2]float64{{0.5, 1.3333333333333333}, {0.25, 0.6666666666666666}, {2.5, 2}} {
		if got, want := libmPow(c[0], c[1]), crPow(c[0], c[1]); math.Abs(got-want) > 4e-16 {
			t.Fatalf("pow(%v, %v): %v, %v", c[0], c[1], got, want)
		}
	}
}

func BenchmarkLibmSin(b *testing.B) {
	x := 0.0
	for i := 0; i < b.N; i++ {
		x += libmSin(float64(i) * 1e-6)
	}
	_ = x
}

// Positions are iterated like java.util.HashSet<BlockPos> (feature/JavaHashSet.php, FeatureTest).
func TestPosSetIterationOrder(t *testing.T) {
	s := newPosSet()
	for _, p := range []pos{{0, 0, 1}, {0, 1, 0}, {1, 0, 0}, {0, 0, 0}} {
		s.Add(p[0], p[1], p[2])
	}
	got := s.IterationOrder()
	want := []pos{{0, 0, 0}, {0, 0, 1}, {1, 0, 0}, {0, 1, 0}}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("iteration order %v, want %v", got, want)
		}
	}
}
