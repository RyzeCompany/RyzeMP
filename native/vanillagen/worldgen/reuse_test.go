package worldgen

import (
	"bytes"
	"slices"
	"testing"
)

// A generator reusing its chunk buffers (the server's mode) gives the same chunks as one allocating every chunk.
func TestReuseChunksSameResult(t *testing.T) {
	data := testData(t)
	for _, dimension := range []string{"overworld", "nether", "end"} {
		tree := NewSingleBiomeTree("minecraft:the_end")
		if dimension != "end" {
			var err error
			if tree, err = NewClimateBiomeTree(data, dimension); err != nil {
				t.Fatal(err)
			}
		}
		opts := Options{Settings: dimension, Materials: true, Carvers: true}
		a, err := NewGenerator(data, 12345, opts, tree.Clone())
		if err != nil {
			t.Fatal(err)
		}
		b, err := NewGenerator(data, 12345, opts, tree.Clone())
		if err != nil {
			t.Fatal(err)
		}
		b.ReuseChunks = true
		for i := 0; i < 40; i++ {
			// neighbours, revisits and jumps
			cx, cz := i%5-2, (i/5)%4*3-1+(i/20)*400
			ca := a.Fill(cx, cz)
			cb := b.Fill(cx, cz)
			if !bytes.Equal(ca.Materials, cb.Materials) || !slices.Equal(ca.Biomes, cb.Biomes) || !slices.Equal(ca.Mask, cb.Mask) || ca.Stage != cb.Stage {
				t.Fatalf("%s chunk %d,%d differs with reused buffers", dimension, cx, cz)
			}
		}
	}
}
