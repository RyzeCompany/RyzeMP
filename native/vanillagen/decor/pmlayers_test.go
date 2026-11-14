package decor

import (
	"encoding/binary"
	"math/rand"
	"testing"
)

// The replica must behave like chunkutils2's BlockArrayContainer: get() returns what was set, the palette keeps
// insertion order, the bits grow to the smallest size that fits only when the palette is full, and words keep bits
// they do not own (PHP compares the arrays byte for byte, see NativeDecorationTest).
func TestPmArrayLikeChunkutils(t *testing.T) {
	rng := rand.New(rand.NewSource(1))
	for round := 0; round < 50; round++ {
		var a pmArray
		a.init(7)
		want := make([]uint32, 4096)
		for i := range want {
			want[i] = 7
		}
		seen := []uint32{7}
		distinct := 1 + rng.Intn(300)
		for n := 0; n < 3000; n++ {
			idx := rng.Intn(4096)
			v := uint32(100 + rng.Intn(distinct))
			if !a.set(idx, v) {
				t.Fatalf("set refused")
			}
			want[idx] = v
			found := false
			for _, s := range seen {
				if s == v {
					found = true
					break
				}
			}
			if !found {
				seen = append(seen, v)
			}
			if a.bits != pmBitsFor(len(seen)) {
				t.Fatalf("round %d: %d entries with %d bits", round, len(seen), a.bits)
			}
		}
		for i, v := range want {
			if got := a.palette[a.offset(i)]; got != v {
				t.Fatalf("round %d: block %d is %d, want %d", round, i, got, v)
			}
		}
		for i, v := range seen {
			if a.palette[i] != v {
				t.Fatalf("round %d: palette order differs at %d", round, i)
			}
		}
	}
}

func TestPmArrayKeepsForeignBits(t *testing.T) {
	// 3 bits per block: 10 blocks per word, the top 2 bits of every word belong to no block
	words := make([]byte, pmWordCount(3)*4)
	for i := 0; i < len(words); i += 4 {
		binary.LittleEndian.PutUint32(words[i:], 0xC0000000)
	}
	var a pmArray
	a.load(3, words, []uint32{1, 2, 3, 4, 5})
	a.set(0, 2)
	a.set(4095, 5)
	for i, w := range a.words {
		if w&0xC0000000 != 0xC0000000 {
			t.Fatalf("word %d lost its foreign bits", i)
		}
	}
	// filling the palette upsizes to 4 bits: fresh words, as fastUpsize() builds them
	a.set(1, 6)
	a.set(2, 7)
	a.set(3, 8)
	a.set(5, 9)
	if a.bits != 4 || len(a.palette) != 9 {
		t.Fatalf("expected 4 bits and 9 entries, got %d and %d", a.bits, len(a.palette))
	}
	if a.palette[a.offset(0)] != 2 || a.palette[a.offset(5)] != 9 || a.palette[a.offset(4095)] != 5 || a.palette[a.offset(100)] != 1 {
		t.Fatalf("blocks changed by the upsize")
	}
}

func TestPmLayersLiquidLayer(t *testing.T) {
	p := newPmLayers(1)
	p.empty, p.water = 0, 99
	p.src[0] = pmSource{blockBits: 0xff, liqBits: 0xff}
	// a dry block on an empty section creates layer 0 only (setLiquidLayerStateId(air) on no layer 1 does nothing)
	if err := p.write(0, 10, 5, false, true); err != nil {
		t.Fatal(err)
	}
	if p.sec[0].layers != 1 {
		t.Fatalf("expected 1 layer, got %d", p.sec[0].layers)
	}
	// a waterlogged one adds layer 1
	if err := p.write(0, 11, 6, true, true); err != nil {
		t.Fatal(err)
	}
	s := &p.sec[0]
	if s.layers != 2 || s.l[1].palette[s.l[1].offset(11)] != 99 || s.l[1].palette[s.l[1].offset(10)] != 0 {
		t.Fatalf("liquid layer not replicated")
	}
	if s.batchWrites != 2 || s.beforeLayers != 0 || len(p.touched) != 1 {
		t.Fatalf("batch bookkeeping wrong")
	}
}
