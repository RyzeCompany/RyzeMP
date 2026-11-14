package worldgen

import (
	"crypto/md5"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"math"
	"os"
	"strconv"
	"strings"
	"testing"
)

// The sine table must be the same as the PHP one (CarverMath::sinTable(), CarverTest.php checks the same hash):
// libm differences in the last bit could round a float32 entry differently.
const sinTableMD5 = "2dd3e1880ba74b5308ca5e78179b3852"

func TestSinTable(t *testing.T) {
	b := make([]byte, 0, 65536*8)
	for _, v := range SinTable() {
		b = binary.LittleEndian.AppendUint64(b, math.Float64bits(v))
	}
	sum := md5.Sum(b)
	if got := hex.EncodeToString(sum[:]); got != sinTableMD5 {
		t.Fatalf("sine table md5 %s, PHP %s", got, sinTableMD5)
	}
}

type chunkFixture struct {
	CX       int    `json:"cx"`
	CZ       int    `json:"cz"`
	MD5      string `json:"md5"`
	MaskSize int    `json:"maskSize"`
	Mask     string `json:"mask"`
	Blocks   string `json:"blocks"`
}

func loadChunkFixtures(t *testing.T, name string) map[string][]chunkFixture {
	b, err := os.ReadFile("../../../tests/phpunit/world/generator/vanilla/" + name)
	if err != nil {
		t.Fatal(err)
	}
	var f struct {
		Seeds map[string][]chunkFixture `json:"seeds"`
	}
	if err := json.Unmarshal(b, &f); err != nil {
		t.Fatal(err)
	}
	return f.Seeds
}

func blockNamesMD5(ch *Chunk, names []string) string {
	parts := make([]string, len(ch.Materials))
	for i, m := range ch.Materials {
		parts[i] = names[m]
	}
	sum := md5.Sum([]byte(strings.Join(parts, ",")))
	return hex.EncodeToString(sum[:])
}

// Material rules against the independent reference values of the PHP tests (material-fixtures.json).
func TestMaterialFixtures(t *testing.T) {
	data := testData(t)
	tree, err := NewClimateBiomeTree(data, "overworld")
	if err != nil {
		t.Fatal(err)
	}
	for seedStr, chunks := range loadChunkFixtures(t, "material-fixtures.json") {
		seed, _ := strconv.ParseInt(seedStr, 10, 64)
		g, err := NewGenerator(data, seed, Options{Materials: true}, tree.Clone())
		if err != nil {
			t.Fatal(err)
		}
		for _, c := range chunks {
			if got := blockNamesMD5(g.Fill(c.CX, c.CZ), g.Materials.Palette.Names()); got != c.MD5 {
				t.Fatalf("seed %d chunk %d,%d: materials md5 %s != %s", seed, c.CX, c.CZ, got, c.MD5)
			}
		}
	}
}

// Carvers: masks against the independent reference, blocks against the PHP implementation (carver-fixtures.json).
func TestCarverFixtures(t *testing.T) {
	data := testData(t)
	if !data.HasCarvers() {
		t.Skip("the worldgen data has no carver definitions")
	}
	tree, err := NewClimateBiomeTree(data, "overworld")
	if err != nil {
		t.Fatal(err)
	}
	for seedStr, chunks := range loadChunkFixtures(t, "carver-fixtures.json") {
		seed, _ := strconv.ParseInt(seedStr, 10, 64)
		g, err := NewGenerator(data, seed, Options{Materials: true, Carvers: true}, tree.Clone())
		if err != nil {
			t.Fatal(err)
		}
		for _, c := range chunks {
			ch := g.Fill(c.CX, c.CZ)
			var idx []string
			for i, set := range ch.Mask {
				if set {
					idx = append(idx, strconv.Itoa(i))
				}
			}
			sum := md5.Sum([]byte(strings.Join(idx, ",")))
			if len(idx) != c.MaskSize || hex.EncodeToString(sum[:]) != c.Mask {
				t.Fatalf("seed %d chunk %d,%d: carving mask differs (%d blocks, expected %d)", seed, c.CX, c.CZ, len(idx), c.MaskSize)
			}
			if got := blockNamesMD5(ch, g.Materials.Palette.Names()); got != c.Blocks {
				t.Fatalf("seed %d chunk %d,%d: blocks md5 %s != %s", seed, c.CX, c.CZ, got, c.Blocks)
			}
		}
	}
}

func BenchmarkFillAllStages(b *testing.B) {
	dir := os.Getenv("POCKETMINE_VANILLA_WORLDGEN_DATA")
	if dir == "" {
		b.Skip("POCKETMINE_VANILLA_WORLDGEN_DATA is not set")
	}
	data, err := OpenData(dir)
	if err != nil {
		b.Fatal(err)
	}
	tree, err := NewClimateBiomeTree(data, "overworld")
	if err != nil {
		b.Fatal(err)
	}
	g, err := NewGenerator(data, 12345, Options{Materials: true, Carvers: true}, tree.Clone())
	if err != nil {
		b.Fatal(err)
	}
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		g.Fill(i%20-10, i/20-10)
	}
}
