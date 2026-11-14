package worldgen

import (
	"crypto/sha1"
	"encoding/binary"
	"encoding/hex"
	"math"
	"testing"
)

func sha1Floats(values []float64) string {
	b := make([]byte, 0, 8*len(values))
	for _, v := range values {
		b = binary.LittleEndian.AppendUint64(b, math.Float64bits(v))
	}
	h := sha1.Sum(b)
	return hex.EncodeToString(h[:])
}

// The beard kernel and the column values must be bit-identical to the PHP Beardifier (values computed with
// Beardifier::kernel() / Beardifier::column(), sha1 of the little-endian doubles).
func TestBeardifierMatchesPHP(t *testing.T) {
	k := BeardKernel()
	if got := sha1Floats(k); got != "585c2c9c953e2bcab8ccaf2f7641283f413d8b8e" || len(k) != 13824 {
		t.Fatalf("kernel differs from PHP: %s (%d values)", got, len(k))
	}
	b := &Beardifier{
		Rigids: []int{0, 64, 0, 10, 70, 10, AdaptBeardThin, 1, 5, 60, 5, 20, 75, 20, AdaptBeardBox, 2,
			-8, 40, -8, 4, 50, 4, AdaptBury, 0, 2, 30, 2, 9, 45, 9, AdaptEncapsulate, 0},
		Junctions: []int{3, 66, -4},
		Affected:  [6]int{-40, 0, -40, 50, 120, 50},
	}
	var out []float64
	for _, c := range [][2]int{{-3, -2}, {0, 0}, {5, 5}, {11, 3}, {-6, 8}, {14, 14}} {
		y0, values, ok := b.Column(c[0], c[1], -64, 319)
		if ok {
			out = append(out, float64(y0))
			out = append(out, values...)
		}
	}
	if got := sha1Floats(out); got != "52e52d79c7bbcd3f8b56caf6118ad03fee12873e" || len(out) != 732 {
		t.Fatalf("columns differ from PHP: %s (%d values)", got, len(out))
	}
}
