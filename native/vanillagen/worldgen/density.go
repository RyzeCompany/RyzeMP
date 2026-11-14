package worldgen

import (
	"math"
)

// Fn is a node of a seed-bound density function graph (density/*.php). A graph instance carries evaluation
// state (memoization, interpolated values) and must only be used by one goroutine.
type Fn interface {
	Compute(x, y, z float64) float64
	MinValue() float64
	MaxValue() float64
	Children() []Fn
	DependsOnY() bool
}

type bounds struct{ min, max float64 }

func (b *bounds) MinValue() float64 { return b.min }
func (b *bounds) MaxValue() float64 { return b.max }

func childrenDependOnY(children []Fn) bool {
	for _, c := range children {
		if c.DependsOnY() {
			return true
		}
	}
	return false
}

// ---- constant ----

type ConstantFn struct {
	bounds
	Value float64
}

func newConstant(v float64) *ConstantFn { return &ConstantFn{bounds{v, v}, v} }

func (f *ConstantFn) Compute(x, y, z float64) float64 { return f.Value }
func (f *ConstantFn) Children() []Fn                  { return nil }
func (f *ConstantFn) DependsOnY() bool                { return false }

// ---- named (registered) function, memoized per position ----

type NamedFn struct {
	bounds
	Name                string
	Input               Fn
	lastX, lastY, lastZ float64
	lastValue           float64
}

func newNamed(name string, input Fn) *NamedFn {
	return &NamedFn{bounds: bounds{input.MinValue(), input.MaxValue()}, Name: name, Input: input, lastX: math.NaN(), lastY: math.NaN(), lastZ: math.NaN()}
}

func (f *NamedFn) Compute(x, y, z float64) float64 {
	if x == f.lastX && y == f.lastY && z == f.lastZ {
		return f.lastValue
	}
	f.lastX, f.lastY, f.lastZ = x, y, z
	f.lastValue = f.Input.Compute(x, y, z)
	return f.lastValue
}
func (f *NamedFn) Children() []Fn   { return []Fn{f.Input} }
func (f *NamedFn) DependsOnY() bool { return f.Input.DependsOnY() }
func (f *NamedFn) reset()           { f.lastX = math.NaN() }

// ---- cache markers (memoized; per column when the input does not depend on y) ----

type CacheFn struct {
	bounds
	Input               Fn
	flat                bool
	lastX, lastY, lastZ float64
	lastValue           float64
}

func newCache(input Fn) *CacheFn {
	return &CacheFn{bounds: bounds{input.MinValue(), input.MaxValue()}, Input: input, flat: !input.DependsOnY(), lastX: math.NaN(), lastY: math.NaN(), lastZ: math.NaN()}
}

func (f *CacheFn) Compute(x, y, z float64) float64 {
	if x == f.lastX && z == f.lastZ && (f.flat || y == f.lastY) {
		return f.lastValue
	}
	f.lastX, f.lastY, f.lastZ = x, y, z
	f.lastValue = f.Input.Compute(x, y, z)
	return f.lastValue
}
func (f *CacheFn) Children() []Fn   { return []Fn{f.Input} }
func (f *CacheFn) DependsOnY() bool { return f.Input.DependsOnY() }
func (f *CacheFn) reset()           { f.lastX = math.NaN() }

// ---- interpolated ----

type InterpolatedFn struct {
	bounds
	Input                 Fn
	CellWidth, CellHeight int
	current               float64
	useCurrent            bool
}

func (f *InterpolatedFn) Compute(x, y, z float64) float64 {
	if f.useCurrent {
		return f.current
	}
	return f.interpolate(f.Input.Compute, x, y, z)
}

// interpolate samples the input at the 8 corners of the cell containing the block and interpolates.
func (f *InterpolatedFn) interpolate(in func(x, y, z float64) float64, x, y, z float64) float64 {
	w := f.CellWidth
	h := f.CellHeight
	bx := phpInt(math.Floor(x))
	by := phpInt(math.Floor(y))
	bz := phpInt(math.Floor(z))
	x0 := bx - ((bx%w + w) % w)
	y0 := by - ((by%h + h) % h)
	z0 := bz - ((bz%w + w) % w)
	fx := float64(bx-x0) / float64(w)
	fy := float64(by-y0) / float64(h)
	fz := float64(bz-z0) / float64(w)
	fw, fh := float64(w), float64(h)
	X, Y, Z := float64(x0), float64(y0), float64(z0)
	c000 := in(X, Y, Z)
	c100 := in(X+fw, Y, Z)
	c010 := in(X, Y+fh, Z)
	c110 := in(X+fw, Y+fh, Z)
	c001 := in(X, Y, Z+fw)
	c101 := in(X+fw, Y, Z+fw)
	c011 := in(X, Y+fh, Z+fw)
	c111 := in(X+fw, Y+fh, Z+fw)
	return lerp3(fx, fy, fz, c000, c100, c010, c110, c001, c101, c011, c111)
}
func (f *InterpolatedFn) Children() []Fn   { return []Fn{f.Input} }
func (f *InterpolatedFn) DependsOnY() bool { return true }

func lerp1(t, a, b float64) float64 { return a + float64(t*(b-a)) }

// lerp3 interpolates y first, then x, then z (vanilla NoiseChunk order).
func lerp3(fx, fy, fz, c000, c100, c010, c110, c001, c101, c011, c111 float64) float64 {
	c00 := lerp1(fy, c000, c010)
	c10 := lerp1(fy, c100, c110)
	c01 := lerp1(fy, c001, c011)
	c11 := lerp1(fy, c101, c111)
	c0 := lerp1(fx, c00, c10)
	c1 := lerp1(fx, c01, c11)
	return lerp1(fz, c0, c1)
}

// ---- noises ----

type NoiseFn struct {
	bounds
	Noise                  *NoiseStack
	XZScale, YScale        float64
	ShiftX, ShiftY, ShiftZ Fn
}

// scaled follows the generated PHP code: scale 0 gives 0.0, scale 1 the coordinate itself.
func scaled(c, scale float64, shift Fn, x, y, z float64) float64 {
	var base float64
	if scale == 0.0 {
		base = 0.0
	} else if scale == 1.0 {
		base = c
	} else {
		base = float64(c * scale)
	}
	if shift == nil {
		return base
	}
	return base + shift.Compute(x, y, z)
}

func (f *NoiseFn) Compute(x, y, z float64) float64 {
	sx := scaled(x, f.XZScale, f.ShiftX, x, y, z)
	sy := scaled(y, f.YScale, f.ShiftY, x, y, z)
	sz := scaled(z, f.XZScale, f.ShiftZ, x, y, z)
	return f.Noise.Get3D(sx, sy, sz)
}
func (f *NoiseFn) Children() []Fn {
	var r []Fn
	for _, s := range []Fn{f.ShiftX, f.ShiftY, f.ShiftZ} {
		if s != nil {
			r = append(r, s)
		}
	}
	return r
}
func (f *NoiseFn) DependsOnY() bool { return f.YScale != 0.0 || childrenDependOnY(f.Children()) }

const (
	shiftModeA = iota
	shiftModeB
	shiftModeXYZ
)

type ShiftNoiseFn struct {
	bounds
	Mode  int
	Noise *NoiseStack
}

func (f *ShiftNoiseFn) Compute(x, y, z float64) float64 {
	switch f.Mode {
	case shiftModeA:
		return float64(f.Noise.Get3D(float64(x*0.25), 0.0, float64(z*0.25)) * 4.0)
	case shiftModeB:
		return float64(f.Noise.Get3D(float64(z*0.25), float64(x*0.25), 0.0) * 4.0)
	default:
		return float64(f.Noise.Get3D(float64(x*0.25), float64(y*0.25), float64(z*0.25)) * 4.0)
	}
}
func (f *ShiftNoiseFn) Children() []Fn   { return nil }
func (f *ShiftNoiseFn) DependsOnY() bool { return f.Mode == shiftModeXYZ }

type BlendedNoiseFn struct {
	bounds
	Noise *BlendedNoise
}

func (f *BlendedNoiseFn) Compute(x, y, z float64) float64 { return f.Noise.Sample(x, y, z) }
func (f *BlendedNoiseFn) Children() []Fn                  { return nil }
func (f *BlendedNoiseFn) DependsOnY() bool                { return true }

// ---- unary ----

const (
	opAbs = iota
	opSquare
	opCube
	opHalfNegative
	opQuarterNegative
	opSqueeze
	opNegate
	opSqrt
	opLog
	opSign
	opReciprocal
)

var unaryOps = map[string]int{
	"abs": opAbs, "square": opSquare, "cube": opCube, "half_negative": opHalfNegative,
	"quarter_negative": opQuarterNegative, "squeeze": opSqueeze, "negate": opNegate, "sqrt": opSqrt,
	"log": opLog, "sign": opSign, "reciprocal": opReciprocal,
}

type UnaryFn struct {
	bounds
	Op    int
	Input Fn
}

func applyUnary(op int, d float64) float64 {
	switch op {
	case opAbs:
		return math.Abs(d)
	case opSquare:
		return d * d
	case opCube:
		return float64(d*d) * d
	case opHalfNegative:
		if d > 0 {
			return d
		}
		return d * 0.5
	case opQuarterNegative:
		if d > 0 {
			return d
		}
		return d * 0.25
	case opSqueeze:
		c := d
		if d < -1 {
			c = -1.0
		} else if d > 1 {
			c = 1.0
		}
		return c/2 - float64(float64(c*c)*c)/24
	case opNegate:
		return -d
	case opSqrt:
		return math.Sqrt(d)
	case opLog:
		return math.Log(d)
	case opSign:
		if d > 0 {
			return 1.0
		}
		if d < 0 {
			return -1.0
		}
		return d
	default: // reciprocal
		return 1 / d
	}
}

func newUnary(op int, input Fn) *UnaryFn {
	lo, hi := input.MinValue(), input.MaxValue()
	var rmin, rmax float64
	switch op {
	case opAbs:
		h := phpMax(math.Abs(lo), math.Abs(hi))
		if lo <= 0 && hi >= 0 {
			rmin, rmax = 0.0, h
		} else {
			rmin, rmax = phpMin(math.Abs(lo), math.Abs(hi)), h
		}
	case opSquare:
		h := phpMax(lo*lo, hi*hi)
		if lo <= 0 && hi >= 0 {
			rmin, rmax = 0.0, h
		} else {
			rmin, rmax = phpMin(lo*lo, hi*hi), h
		}
	case opCube, opHalfNegative, opQuarterNegative, opSqueeze:
		rmin, rmax = applyUnary(op, lo), applyUnary(op, hi)
	case opNegate:
		rmin, rmax = -hi, -lo
	case opSign:
		rmin, rmax = -1.0, 1.0
	default:
		rmin, rmax = math.Inf(-1), math.Inf(1)
	}
	return &UnaryFn{bounds{rmin, rmax}, op, input}
}

func (f *UnaryFn) Compute(x, y, z float64) float64 { return applyUnary(f.Op, f.Input.Compute(x, y, z)) }
func (f *UnaryFn) Children() []Fn                  { return []Fn{f.Input} }
func (f *UnaryFn) DependsOnY() bool                { return f.Input.DependsOnY() }

// ---- binary ----

const (
	opAdd = iota
	opSub
	opMul
	opDiv
	opMin
	opMax
)

var binaryOps = map[string]int{"add": opAdd, "sub": opSub, "mul": opMul, "div": opDiv, "min": opMin, "max": opMax}

type BinaryFn struct {
	bounds
	Op          int
	Left, Right Fn
	// plain: mul/div with a constant operand is a plain multiply/divide (no short-circuit), like the generated
	// PHP code
	plain bool
}

func mulBound(a, b float64) float64 {
	if a == 0.0 || b == 0.0 {
		return 0.0
	}
	return a * b
}

func newBinary(op int, left, right Fn) *BinaryFn {
	a, b := left.MinValue(), left.MaxValue()
	c, d := right.MinValue(), right.MaxValue()
	var lo, hi float64
	switch op {
	case opAdd:
		lo, hi = a+c, b+d
	case opSub:
		lo, hi = a-d, b-c
	case opMul:
		p := []float64{mulBound(a, c), mulBound(a, d), mulBound(b, c), mulBound(b, d)}
		lo, hi = phpArrayMin(p), phpArrayMax(p)
	case opMin:
		lo, hi = phpMin(a, c), phpMin(b, d)
	case opMax:
		lo, hi = phpMax(a, c), phpMax(b, d)
	default:
		lo, hi = math.Inf(-1), math.Inf(1)
	}
	if math.IsNaN(lo) || math.IsNaN(hi) {
		lo, hi = math.Inf(-1), math.Inf(1)
	}
	_, lc := left.(*ConstantFn)
	_, rc := right.(*ConstantFn)
	return &BinaryFn{bounds{lo, hi}, op, left, right, lc || rc}
}

func (f *BinaryFn) Compute(x, y, z float64) float64 {
	a := f.Left.Compute(x, y, z)
	switch f.Op {
	case opAdd:
		return a + f.Right.Compute(x, y, z)
	case opSub:
		return a - f.Right.Compute(x, y, z)
	case opMul:
		if !f.plain && a == 0 {
			return 0.0
		}
		return a * f.Right.Compute(x, y, z)
	case opDiv:
		if !f.plain && a == 0 {
			return 0.0
		}
		return a / f.Right.Compute(x, y, z)
	case opMin:
		if a < f.Right.MinValue() {
			return a
		}
		b := f.Right.Compute(x, y, z)
		if a < b {
			return a
		}
		return b
	default:
		if a > f.Right.MaxValue() {
			return a
		}
		b := f.Right.Compute(x, y, z)
		if a > b {
			return a
		}
		return b
	}
}
func (f *BinaryFn) Children() []Fn   { return []Fn{f.Left, f.Right} }
func (f *BinaryFn) DependsOnY() bool { return f.Left.DependsOnY() || f.Right.DependsOnY() }

// ---- clamp ----

type ClampFn struct {
	bounds
	Input    Fn
	Min, Max float64
}

func newClamp(input Fn, lo, hi float64) *ClampFn {
	return &ClampFn{bounds{phpMax(lo, phpMin(hi, input.MinValue())), phpMax(lo, phpMin(hi, input.MaxValue()))}, input, lo, hi}
}

func (f *ClampFn) Compute(x, y, z float64) float64 {
	v := f.Input.Compute(x, y, z)
	if v < f.Min {
		return f.Min
	}
	if v > f.Max {
		return f.Max
	}
	return v
}
func (f *ClampFn) Children() []Fn   { return []Fn{f.Input} }
func (f *ClampFn) DependsOnY() bool { return f.Input.DependsOnY() }

// ---- range choice ----

type RangeChoiceFn struct {
	bounds
	Input                       Fn
	MinInclusive, MaxExclusive  float64
	WhenInRange, WhenOutOfRange Fn
}

func newRangeChoice(input Fn, minInclusive, maxExclusive float64, in, out Fn) *RangeChoiceFn {
	return &RangeChoiceFn{bounds{phpMin(in.MinValue(), out.MinValue()), phpMax(in.MaxValue(), out.MaxValue())}, input, minInclusive, maxExclusive, in, out}
}

func (f *RangeChoiceFn) Compute(x, y, z float64) float64 {
	v := f.Input.Compute(x, y, z)
	if v >= f.MinInclusive && v < f.MaxExclusive {
		return f.WhenInRange.Compute(x, y, z)
	}
	return f.WhenOutOfRange.Compute(x, y, z)
}
func (f *RangeChoiceFn) Children() []Fn { return []Fn{f.Input, f.WhenInRange, f.WhenOutOfRange} }
func (f *RangeChoiceFn) DependsOnY() bool {
	return childrenDependOnY(f.Children())
}

// ---- interval select ----

type IntervalSelectFn struct {
	bounds
	Input      Fn
	Thresholds []float64
	Functions  []Fn
}

func newIntervalSelect(input Fn, thresholds []float64, functions []Fn) *IntervalSelectFn {
	lo, hi := math.Inf(1), math.Inf(-1)
	for _, f := range functions {
		lo = phpMin(lo, f.MinValue())
		hi = phpMax(hi, f.MaxValue())
	}
	return &IntervalSelectFn{bounds{lo, hi}, input, thresholds, functions}
}

func (f *IntervalSelectFn) Compute(x, y, z float64) float64 {
	v := f.Input.Compute(x, y, z)
	for i, t := range f.Thresholds {
		if v < t {
			return f.Functions[i].Compute(x, y, z)
		}
	}
	return f.Functions[len(f.Thresholds)].Compute(x, y, z)
}
func (f *IntervalSelectFn) Children() []Fn   { return append([]Fn{f.Input}, f.Functions...) }
func (f *IntervalSelectFn) DependsOnY() bool { return childrenDependOnY(f.Children()) }

// ---- lerp ----

type LerpFn struct {
	bounds
	Alpha, First, Second Fn
}

func newLerp(alpha, first, second Fn) *LerpFn {
	lo := phpMin(first.MinValue(), second.MinValue())
	hi := phpMax(first.MaxValue(), second.MaxValue())
	if alpha.MinValue() < 0 || alpha.MaxValue() > 1 {
		lo, hi = math.Inf(-1), math.Inf(1)
	}
	return &LerpFn{bounds{lo, hi}, alpha, first, second}
}

func (f *LerpFn) Compute(x, y, z float64) float64 {
	a := f.Alpha.Compute(x, y, z)
	if a == 0.0 {
		return f.First.Compute(x, y, z)
	}
	if a == 1.0 {
		return f.Second.Compute(x, y, z)
	}
	return flerp(a, f.First.Compute(x, y, z), f.Second.Compute(x, y, z))
}
func (f *LerpFn) Children() []Fn   { return []Fn{f.Alpha, f.First, f.Second} }
func (f *LerpFn) DependsOnY() bool { return childrenDependOnY(f.Children()) }

// ---- gradient ----

const (
	axisX = iota
	axisY
	axisZ
)
const (
	tilingClamp = iota
	tilingRepeat
	tilingMirrored
)

type GradientFn struct {
	bounds
	Axis, Tiling       int
	FromCoord, ToCoord int
	FromValue, ToValue float64
	fromCoordF, rangeF float64
}

func newGradient(axis, tiling, from, to int, fromValue, toValue float64) *GradientFn {
	return &GradientFn{bounds{phpMin(fromValue, toValue), phpMax(fromValue, toValue)}, axis, tiling, from, to, fromValue, toValue, float64(from), float64(to - from)}
}

func (f *GradientFn) Compute(x, y, z float64) float64 {
	var c float64
	switch f.Axis {
	case axisX:
		c = x
	case axisY:
		c = y
	default:
		c = z
	}
	c -= f.fromCoordF
	rng := f.rangeF
	if f.Tiling == tilingRepeat {
		c = math.Mod(math.Mod(c, rng)+rng, rng)
	} else if f.Tiling == tilingMirrored {
		tile := math.Floor(c / rng)
		local := c - float64(tile*rng)
		if phpInt(tile)&1 == 0 {
			c = local
		} else {
			c = rng - local
		}
	}
	a := c / rng
	if a < 0 {
		return f.FromValue
	}
	if a > 1 {
		return f.ToValue
	}
	return f.FromValue + float64(a*(f.ToValue-f.FromValue))
}
func (f *GradientFn) Children() []Fn   { return nil }
func (f *GradientFn) DependsOnY() bool { return f.Axis == axisY }

// ---- slice, distance to point, End islands ----

// SliceFn evaluates the input with one coordinate fixed ("minecraft:slice").
type SliceFn struct {
	bounds
	Axis       int
	Coordinate float64
	Input      Fn
}

func (f *SliceFn) Compute(x, y, z float64) float64 {
	switch f.Axis {
	case axisX:
		return f.Input.Compute(f.Coordinate, y, z)
	case axisY:
		return f.Input.Compute(x, f.Coordinate, z)
	}
	return f.Input.Compute(x, y, f.Coordinate)
}
func (f *SliceFn) Children() []Fn   { return []Fn{f.Input} }
func (f *SliceFn) DependsOnY() bool { return f.Axis != axisY && f.Input.DependsOnY() }

const (
	metricEuclidean = iota
	metricEuclideanSquared
	metricManhattan
	metricChebyshev
)

// DistanceToPointFn is the distance from the block to a fixed point ("minecraft:distance_to_point").
type DistanceToPointFn struct {
	bounds
	PX, PY, PZ float64
	Metric     int
}

func (f *DistanceToPointFn) Compute(x, y, z float64) float64 {
	dx := f.PX - x
	dy := f.PY - y
	dz := f.PZ - z
	switch f.Metric {
	case metricEuclidean:
		return math.Sqrt(float64(float64(float64(dx*dx)+float64(dy*dy)) + float64(dz*dz)))
	case metricEuclideanSquared:
		return float64(float64(dx*dx)+float64(dy*dy)) + float64(dz*dz)
	case metricManhattan:
		return math.Abs(dx) + math.Abs(dy) + math.Abs(dz)
	}
	return math.Max(math.Max(math.Abs(dx), math.Abs(dy)), math.Abs(dz))
}
func (f *DistanceToPointFn) Children() []Fn   { return nil }
func (f *DistanceToPointFn) DependsOnY() bool { return true }

// EndIslandsFn is the outer End islands ("minecraft:end_outer_islands", density/EndIslandsFunction.php): one
// candidate island per chunk more than 64 chunks from 0,0 where a seeded simplex noise is low enough, computed with
// Java's int division and float arithmetic.
type EndIslandsFn struct {
	bounds
	noise *SimplexNoise
	cache map[[2]int]bool
}

func newEndIslands(seed int64) *EndIslandsFn {
	r := NewLegacyRandom(seed)
	r.Consume(17292)
	return &EndIslandsFn{bounds: bounds{-0.84375, 0.5625}, noise: NewSimplexNoise(r), cache: map[[2]int]bool{}}
}

func (f *EndIslandsFn) Compute(x, y, z float64) float64 {
	sx := int(x) / 8
	sz := int(z) / 8
	return (float64(f.heightValue(sx, sz)) - 8.0) / 128.0
}

func (f *EndIslandsFn) isIsland(cx, cz int) bool {
	key := [2]int{cx, cz}
	if v, ok := f.cache[key]; ok {
		return v
	}
	if len(f.cache) >= 65536 {
		f.cache = map[[2]int]bool{}
	}
	v := int64(cx)*int64(cx)+int64(cz)*int64(cz) > 4096 && f.noise.GetValue2D(float64(cx), float64(cz)) < float64(float32(-0.9))
	f.cache[key] = v
	return v
}

func (f *EndIslandsFn) heightValue(sectionX, sectionZ int) float32 {
	chunkX := sectionX / 2
	chunkZ := sectionZ / 2
	subX := sectionX % 2
	subZ := sectionZ % 2
	value := float32(-100.0)
	for ox := -12; ox <= 12; ox++ {
		for oz := -12; oz <= 12; oz++ {
			ix := chunkX + ox
			iz := chunkZ + oz
			if !f.isIsland(ix, iz) {
				continue
			}
			a := float32(float32(math.Abs(float64(ix))) * 3439.0)
			b := float32(float32(math.Abs(float64(iz))) * 147.0)
			size := float32(float32(math.Mod(float64(float32(a+b)), 13.0)) + 9.0)
			dx := float32(subX - ox*2)
			dz := float32(subZ - oz*2)
			distance := float32(math.Sqrt(float64(float32(float32(dx*dx) + float32(dz*dz)))))
			island := float32(100.0 - float32(distance*size))
			if island > 80.0 {
				island = 80.0
			} else if island < -100.0 {
				island = -100.0
			}
			if island > value {
				value = island
			}
		}
	}
	return value
}
func (f *EndIslandsFn) Children() []Fn   { return nil }
func (f *EndIslandsFn) DependsOnY() bool { return false }

// ---- find top surface ----

type FindTopSurfaceFn struct {
	bounds
	Density, UpperBound    Fn
	LowerBound, CellHeight int
}

func newFindTopSurface(density, upper Fn, lower, cell int) *FindTopSurfaceFn {
	return &FindTopSurfaceFn{bounds{float64(lower), phpMaxIntFloat(lower, upper.MaxValue())}, density, upper, lower, cell}
}

func (f *FindTopSurfaceFn) Compute(x, y, z float64) float64 {
	ub := f.UpperBound.Compute(x, y, z)
	cell := float64(f.CellHeight)
	i := phpInt(float64(math.Floor(ub/cell) * cell))
	for ; i >= f.LowerBound; i -= f.CellHeight {
		if f.Density.Compute(x, float64(i), z) > 0 {
			return float64(i)
		}
	}
	return float64(f.LowerBound)
}
func (f *FindTopSurfaceFn) Children() []Fn   { return []Fn{f.Density, f.UpperBound} }
func (f *FindTopSurfaceFn) DependsOnY() bool { return f.UpperBound.DependsOnY() }

// ---- spline ----

type SplineFn struct {
	bounds
	Spline *CubicSpline
}

func (f *SplineFn) Compute(x, y, z float64) float64 { return f.Spline.Compute(x, y, z) }
func (f *SplineFn) Children() []Fn                  { return f.Spline.Coordinates() }
func (f *SplineFn) DependsOnY() bool                { return childrenDependOnY(f.Children()) }
