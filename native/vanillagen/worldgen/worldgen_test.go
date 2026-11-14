package worldgen

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"strconv"
	"testing"
)

// The reference values are the PHP port's golden fixtures (computed with misode/deepslate 0.27.2, MIT).
const fixturePath = "../../../tests/phpunit/world/generator/vanilla/deepslate-fixtures.json"

type fixtures struct {
	NormalNoise      [][4]float64 `json:"normalNoise"`
	CaveCheeseNoise  [][4]float64 `json:"caveCheeseNoise"`
	BlendedNoise     [][4]float64 `json:"blendedNoise"`
	XoroshiroLongs   []string     `json:"xoroshiroLongs"`
	XoroshiroBounded [][2]int     `json:"xoroshiroBounded"`
	XoroshiroDoubles []float64    `json:"xoroshiroDoubles"`
	HashOf           [][2]string  `json:"hashOf"`
	Splines          map[string]struct {
		Points [][3]float64 `json:"points"`
		Values [][2]float64 `json:"values"`
	} `json:"splines"`
	Worldgen map[string]struct {
		Router          [][]float64 `json:"router"`
		Biomes          [][4]any    `json:"biomes"`
		ChunksNoAquifer []struct {
			CX  int    `json:"cx"`
			CZ  int    `json:"cz"`
			MD5 string `json:"md5"`
		} `json:"chunksNoAquifer"`
	} `json:"worldgen"`
}

func loadFixtures(t *testing.T) *fixtures {
	b, err := os.ReadFile(fixturePath)
	if err != nil {
		t.Fatal(err)
	}
	var f fixtures
	if err := json.Unmarshal(b, &f); err != nil {
		t.Fatal(err)
	}
	return &f
}

func TestXoroshiro(t *testing.T) {
	r := NewXoroshiroFromSeed(1)
	if r.lo != 5272463233947570727 || r.hi != 1927618558350093866 {
		t.Fatalf("seed upgrade: %d %d", r.lo, r.hi)
	}
	f := NewXoroshiroFromSeed(1).Fork()
	if f.lo != 17413076366490032638 || f.hi != 6451672561743293322 {
		t.Fatalf("fork: %d %d", f.lo, f.hi)
	}
	r = NewXoroshiroFromSeed(1)
	for _, v := range []int64{-1033667707219518978, 6451672561743293322, -1821890263888393630, 890086654470169703, 8094835630745194324} {
		if got := r.NextLong(); got != v {
			t.Fatalf("nextLong %d != %d", got, v)
		}
	}
	r = NewXoroshiroFromSeed(1)
	for _, c := range [][2]int{{25, 10}, {256, 49}, {255, 48}, {254, 169}, {0x7FFFFFFF, 383715241}} {
		if got := r.NextBoundedInt(c[0]); got != c[1] {
			t.Fatalf("bounded(%d) %d != %d", c[0], got, c[1])
		}
	}
	r = NewXoroshiroFromSeed(1)
	for _, v := range []float64{0.9439647613102243, 0.34974587038035987, 0.9012351308931007} {
		if got := r.NextDouble(); got != v {
			t.Fatalf("nextDouble %v != %v", got, v)
		}
	}
	fx := loadFixtures(t)
	r = NewXoroshiroFromSeed(-4172144997902289642)
	for _, v := range fx.XoroshiroLongs {
		if got := strconv.FormatInt(r.NextLong(), 10); got != v {
			t.Fatalf("long %s != %s", got, v)
		}
	}
	for _, c := range fx.XoroshiroBounded {
		if got := r.NextBoundedInt(c[0]); got != c[1] {
			t.Fatalf("bounded %d != %d", got, c[1])
		}
	}
	for _, v := range fx.XoroshiroDoubles {
		if got := r.NextDouble(); got != v {
			t.Fatalf("double %v != %v", got, v)
		}
	}
}

func TestPositional(t *testing.T) {
	named := NewXoroshiroFromSeed(124).ForkPositional().FromHashOf("minecraft:deepslate").ForkPositional()
	for _, c := range [][4]float64{{15, 8, 0, 0.9723014235496521}, {15, 7, 0, 0.9699087142944336}, {15, 6, 0, 0.9694857001304626}} {
		if got := named.At(int(c[0]), int(c[1]), int(c[2])).NextFloat(); got != c[3] {
			t.Fatalf("positional float %v != %v", got, c[3])
		}
	}
	pos := NewXoroshiroFromSeed(42).ForkPositional()
	for _, c := range loadFixtures(t).HashOf {
		if got := strconv.FormatInt(pos.FromHashOf(c[0]).NextLong(), 10); got != c[1] {
			t.Fatalf("hashOf %s: %s != %s", c[0], got, c[1])
		}
	}
}

func TestFround(t *testing.T) {
	for _, v := range []float64{0.1, -1.0e-30, 3.4e38, 1.0 + math.Pow(2, -24), 1.0 + 3*math.Pow(2, -24), -0.2222, 123456.789} {
		if got := fround(v); got != float64(float32(v)) {
			t.Fatalf("fround(%v) = %v", v, got)
		}
	}
}

func TestNoises(t *testing.T) {
	fx := loadFixtures(t)
	pos := NewXoroshiroFromSeed(42).ForkPositional()
	offset := NewNormalNoiseParameters(0.9381732587751005, -3, 4, normalizeEnabled, []float64{1, 1, 1, 0}).Create(pos.FromHashOf("minecraft:offset"))
	for _, p := range fx.NormalNoise {
		if got := offset.Get3D(p[0], p[1], p[2]); got != p[3] {
			t.Fatalf("offset noise at %v: %v", p, got)
		}
	}
	cheese := NewNormalNoiseParameters(0.8361300524356068, -8, 9, normalizeEnabled, []float64{0.5, 1, 2, 1, 2, 1, 0, 2, 0}).Create(pos.FromHashOf("minecraft:cave_cheese"))
	for _, p := range fx.CaveCheeseNoise {
		if got := cheese.Get3D(p[0], p[1], p[2]); got != p[3] {
			t.Fatalf("cheese noise at %v: %v", p, got)
		}
	}
	blended := NewBlendedNoise(NewXoroshiroFromSeed(42).ForkPositional().FromHashOf("minecraft:terrain"), 0.25, 0.125, 80, 160, 8)
	for _, p := range fx.BlendedNoise {
		if got := blended.Sample(p[0], p[1], p[2]); got != p[3] {
			t.Fatalf("blended noise at %v: %v", p, got)
		}
	}
}

type identityFn struct{ bounds }

func (f *identityFn) Compute(x, y, z float64) float64 { return x }
func (f *identityFn) Children() []Fn                  { return nil }
func (f *identityFn) DependsOnY() bool                { return false }

func TestSplines(t *testing.T) {
	coord := &identityFn{bounds{math.Inf(-1), math.Inf(1)}}
	for name, c := range loadFixtures(t).Splines {
		var locs, ders []float64
		var vals []SplineValue
		for _, p := range c.Points {
			locs = append(locs, fround(p[0]))
			vals = append(vals, SplineValue{Const: fround(p[1])})
			ders = append(ders, fround(p[2]))
		}
		s := newCubicSpline(coord, locs, vals, ders, true)
		for _, v := range c.Values {
			if got := s.Compute(v[0], 0, 0); got != v[1] {
				t.Fatalf("spline %s at %v: %v != %v", name, v[0], got, v[1])
			}
		}
	}
}

func testData(t *testing.T) *Data {
	dir := os.Getenv("POCKETMINE_VANILLA_WORLDGEN_DATA")
	if dir == "" {
		t.Skip("POCKETMINE_VANILLA_WORLDGEN_DATA is not set")
	}
	d, err := OpenData(dir)
	if err != nil {
		t.Fatal(err)
	}
	return d
}

func TestWorldgenMatchesDeepslate(t *testing.T) {
	data := testData(t)
	tree, err := NewClimateBiomeTree(data, "overworld")
	if err != nil {
		t.Fatal(err)
	}
	chars := []byte{'.', 'X', '~', 'L'}
	for seedStr, fx := range loadFixtures(t).Worldgen {
		seed, _ := strconv.ParseInt(seedStr, 10, 64)
		g, err := NewGenerator(data, seed, Options{NoAquifers: true, DeepslateFloats: true}, tree.Clone())
		if err != nil {
			t.Fatal(err)
		}
		r := g.Router
		for _, row := range fx.Router {
			x, y, z := row[0], row[1], row[2]
			for i, f := range []Fn{r.Temperature, r.Vegetation, r.Continents, r.Erosion, r.Depth, r.Ridges} {
				if got := f.Compute(x, y, z); got != row[3+i] {
					t.Fatalf("seed %d router value %d at %v %v %v: %v != %v", seed, i, x, y, z, got, row[3+i])
				}
			}
			if got := r.FinalDensity.Compute(x, y, z); math.Abs(got-row[9]) > 1e-12 {
				t.Fatalf("seed %d final density at %v %v %v: %v != %v", seed, x, y, z, got, row[9])
			}
		}
		mismatches := 0
		names := tree.Names()
		for _, b := range fx.Biomes {
			qx, qy, qz := int(b[0].(float64)), int(b[1].(float64)), int(b[2].(float64))
			x, y, z := float64(qx<<2), float64(qy<<2), float64(qz<<2)
			q := func(f Fn) int { return Quantize(f.Compute(x, y, z)) }
			found := names[g.Tree.Search(q(r.Temperature), q(r.Vegetation), q(r.Continents), q(r.Erosion), q(r.Depth), q(r.Ridges))]
			if found != b[3].(string) {
				mismatches++
			}
		}
		if mismatches > 1 {
			t.Fatalf("seed %d: %d biome mismatches", seed, mismatches)
		}
		for _, c := range fx.ChunksNoAquifer {
			chunk := g.Fill(c.CX, c.CZ)
			buf := make([]byte, len(chunk.Materials))
			for i, m := range chunk.Materials {
				buf[i] = chars[m]
			}
			sum := md5.Sum(buf)
			if got := hex.EncodeToString(sum[:]); got != c.MD5 {
				t.Fatalf("seed %d chunk %d,%d terrain md5 %s != %s", seed, c.CX, c.CZ, got, c.MD5)
			}
		}
	}
}

func BenchmarkFill(b *testing.B) {
	dir := os.Getenv("POCKETMINE_VANILLA_WORLDGEN_DATA")
	if dir == "" {
		b.Skip("POCKETMINE_VANILLA_WORLDGEN_DATA is not set")
	}
	data, err := OpenData(filepath.Clean(dir))
	if err != nil {
		b.Fatal(err)
	}
	tree, err := NewClimateBiomeTree(data, "overworld")
	if err != nil {
		b.Fatal(err)
	}
	g, err := NewGenerator(data, 12345, Options{}, tree.Clone())
	if err != nil {
		b.Fatal(err)
	}
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		g.Fill(i%20-10, i/20-10)
	}
}
