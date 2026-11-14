package worldgen

import "math"

// LegacyRandom is java.util.Random (random/LegacyRandom.php), used by the carvers and the biome temperature noises.
type LegacyRandom struct {
	seed int64
}

const (
	legacyMultiplier = 0x5DEECE66D
	legacyIncrement  = 0xB
	legacyMask       = (1 << 48) - 1
)

func NewLegacyRandom(seed int64) *LegacyRandom {
	r := &LegacyRandom{}
	r.SetSeed(seed)
	return r
}

func (r *LegacyRandom) SetSeed(seed int64) {
	r.seed = (seed ^ legacyMultiplier) & legacyMask
}

func (r *LegacyRandom) next(bits uint) int32 {
	r.seed = (r.seed*legacyMultiplier + legacyIncrement) & legacyMask
	return int32(r.seed >> (48 - bits))
}

func (r *LegacyRandom) Consume(count int) {
	for i := 0; i < count; i++ {
		r.next(1)
	}
}

func (r *LegacyRandom) NextInt() int32 { return r.next(32) }

func (r *LegacyRandom) NextBoundedInt(bound int) int {
	if bound <= 0 {
		panic("bound must be positive")
	}
	if bound&(bound-1) == 0 {
		return int(int32((int64(bound) * int64(r.next(31))) >> 31))
	}
	for {
		bits := r.next(31)
		value := bits % int32(bound)
		if bits-value+int32(bound-1) >= 0 {
			return int(value)
		}
	}
}

func (r *LegacyRandom) NextLong() int64 {
	hi := int64(r.next(32))
	lo := int64(r.next(32))
	return (hi << 32) + lo
}

func (r *LegacyRandom) NextFloat() float64 {
	return float64(r.next(24)) * 5.9604644775390625e-8
}

func (r *LegacyRandom) NextDouble() float64 {
	hi := int64(r.next(26))
	lo := int64(r.next(27))
	return float64((hi<<27)+lo) * 1.1102230246251565e-16
}

// ---- 2D simplex noise (noise/SimplexNoise.php, noise/PerlinSimplexNoise.php) ----

var simplexGradient = [16][3]float64{
	{1, 1, 0}, {-1, 1, 0}, {1, -1, 0}, {-1, -1, 0},
	{1, 0, 1}, {-1, 0, 1}, {1, 0, -1}, {-1, 0, -1},
	{0, 1, 1}, {0, -1, 1}, {0, 1, -1}, {0, -1, -1},
	{1, 1, 0}, {0, -1, 1}, {-1, 1, 0}, {0, -1, -1},
}

type SimplexNoise struct {
	xo, yo, zo float64
	p          [256]int
}

type legacyLike interface {
	NextDouble() float64
	NextBoundedInt(bound int) int
}

func NewSimplexNoise(r legacyLike) *SimplexNoise {
	n := &SimplexNoise{}
	n.xo = float64(r.NextDouble() * 256.0)
	n.yo = float64(r.NextDouble() * 256.0)
	n.zo = float64(r.NextDouble() * 256.0)
	for i := 0; i < 256; i++ {
		n.p[i] = i
	}
	for i := 0; i < 256; i++ {
		offset := r.NextBoundedInt(256 - i)
		n.p[i], n.p[offset+i] = n.p[offset+i], n.p[i]
	}
	return n
}

func (n *SimplexNoise) perm(x int) int { return n.p[x&0xff] }

func simplexCorner(index int, x, y, base float64) float64 {
	zero := 0.0
	t := base - float64(x*x) - float64(y*y) - float64(zero*zero)
	if t < 0.0 {
		return 0.0
	}
	t = float64(t * t)
	g := &simplexGradient[index]
	return float64(float64(t*t) * (float64(g[0]*x) + float64(g[1]*y) + float64(g[2]*zero)))
}

var (
	sqrt3 = math.Sqrt(3.0)
	// computed at run time like the PHP code (not folded as constants)
	simplexF2 = float64(0.5 * (sqrt3 - 1.0))
	simplexG2 = (3.0 - sqrt3) / 6.0
)

func (n *SimplexNoise) GetValue2D(x, y float64) float64 {
	f2 := simplexF2
	g2 := simplexG2
	s := float64((x + y) * f2)
	i := phpInt(math.Floor(x + s))
	j := phpInt(math.Floor(y + s))
	t := float64(float64(i+j) * g2)
	x0 := x - (float64(i) - t)
	y0 := y - (float64(j) - t)
	var i1, j1 int
	if x0 > y0 {
		i1, j1 = 1, 0
	} else {
		i1, j1 = 0, 1
	}
	x1 := x0 - float64(i1) + g2
	y1 := y0 - float64(j1) + g2
	x2 := x0 - 1.0 + float64(2.0*g2)
	y2 := y0 - 1.0 + float64(2.0*g2)
	ii := i & 0xff
	jj := j & 0xff
	gi0 := n.perm(ii+n.perm(jj)) % 12
	gi1 := n.perm(ii+i1+n.perm(jj+j1)) % 12
	gi2 := n.perm(ii+1+n.perm(jj+1)) % 12
	return float64(70.0 * (simplexCorner(gi0, x0, y0, 0.5) + simplexCorner(gi1, x1, y1, 0.5) + simplexCorner(gi2, x2, y2, 0.5)))
}

// PerlinSimplexNoise: octaves of 2D simplex noise without positive octaves.
type PerlinSimplexNoise struct {
	levels       []*SimplexNoise
	highestFreqF float64
}

func NewPerlinSimplexNoise(r *LegacyRandom, octaves []int) *PerlinSimplexNoise {
	low, high := 0, math.MinInt
	for _, o := range octaves {
		if -o > low {
			low = -o
		}
		if o > high {
			high = o
		}
	}
	if high > 0 {
		panic("positive octaves are not supported")
	}
	has := func(o int) bool {
		for _, v := range octaves {
			if v == o {
				return true
			}
		}
		return false
	}
	count := low + high + 1
	zero := NewSimplexNoise(r)
	p := &PerlinSimplexNoise{}
	if has(0) {
		p.levels = append(p.levels, zero)
	} else {
		p.levels = append(p.levels, nil)
	}
	for i := 1; i < count; i++ {
		if has(-i) {
			p.levels = append(p.levels, NewSimplexNoise(r))
		} else {
			r.Consume(262)
			p.levels = append(p.levels, nil)
		}
	}
	p.highestFreqF = 1.0 / (math.Pow(2.0, float64(len(p.levels))) - 1.0)
	return p
}

func (p *PerlinSimplexNoise) GetValue(x, y float64) float64 {
	value := 0.0
	factor := 1.0
	valueFactor := p.highestFreqF
	for _, n := range p.levels {
		if n != nil {
			value += float64(n.GetValue2D(float64(x*factor), float64(y*factor)) * valueFactor)
		}
		factor /= 2.0
		valueFactor *= 2.0
	}
	return value
}
