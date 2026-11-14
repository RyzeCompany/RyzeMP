package worldgen

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"os"
	"strconv"
	"strings"
	"testing"
)

type dimensionChunkFixture struct {
	CX     int    `json:"cx"`
	CZ     int    `json:"cz"`
	Blocks string `json:"blocks"`
	Biomes string `json:"biomes"`
}

// Nether and End terrain (material rules and carvers included) against the PHP implementation
// (dimension-fixtures.json, VanillaDimensionsTest.php). The End's biomes come from the PHP side, so only the Nether's
// are compared.
func TestDimensionFixtures(t *testing.T) {
	data := testData(t)
	b, err := os.ReadFile("../../../tests/phpunit/world/generator/vanilla/dimension-fixtures.json")
	if err != nil {
		t.Fatal(err)
	}
	var f map[string]json.RawMessage
	if err := json.Unmarshal(b, &f); err != nil {
		t.Fatal(err)
	}
	for _, dimension := range []string{"nether", "end"} {
		var seeds map[string][]dimensionChunkFixture
		if err := json.Unmarshal(f[dimension], &seeds); err != nil {
			t.Fatal(err)
		}
		var tree *ClimateBiomeTree
		if dimension == "end" {
			tree = NewSingleBiomeTree("minecraft:the_end")
		} else if tree, err = NewClimateBiomeTree(data, dimension); err != nil {
			t.Fatal(err)
		}
		for seedStr, chunks := range seeds {
			seed, _ := strconv.ParseInt(seedStr, 10, 64)
			g, err := NewGenerator(data, seed, Options{Settings: dimension, Materials: true, Carvers: true}, tree.Clone())
			if err != nil {
				t.Fatal(err)
			}
			for _, c := range chunks {
				ch := g.Fill(c.CX, c.CZ)
				if got := blockNamesMD5(ch, g.Materials.Palette.Names()); got != c.Blocks {
					t.Fatalf("%s seed %d chunk %d,%d: blocks md5 %s != %s", dimension, seed, c.CX, c.CZ, got, c.Blocks)
				}
				if dimension == "end" {
					continue
				}
				names := make([]string, len(ch.Biomes))
				for i, v := range ch.Biomes {
					names[i] = g.Tree.Names()[v]
				}
				if got := namesMD5(names); got != c.Biomes {
					t.Fatalf("%s seed %d chunk %d,%d: biomes md5 %s != %s", dimension, seed, c.CX, c.CZ, got, c.Biomes)
				}
			}
		}
	}
}

func namesMD5(names []string) string {
	sum := md5.Sum([]byte(strings.Join(names, ",")))
	return hex.EncodeToString(sum[:])
}
