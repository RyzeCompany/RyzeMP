package worldgen

import (
	"math"
)

// Eval is a compiled density function: the same values as Fn.Compute, without the per-node interface dispatch,
// operator switches and memoization wrappers that are not needed (closures specialised per node at build time,
// like the PHP backend's generated code).
type Eval func(x, y, z float64) float64

type memoState struct {
	lastX, lastY, lastZ float64
	lastValue           float64
}

func (m *memoState) reset() { m.lastX = math.NaN() }

// compiler turns a graph into closures. Nodes shared by several parents keep their memoization (evaluated once
// per position, or per column for flat caches); nodes used once are inlined.
type compiler struct {
	refs     map[Fn]int
	compiled map[Fn]Eval
	memos    []*memoState
}

func newCompiler(roots ...Fn) *compiler {
	c := &compiler{refs: map[Fn]int{}, compiled: map[Fn]Eval{}}
	seen := map[Fn]bool{}
	var walk func(f Fn)
	walk = func(f Fn) {
		if seen[f] {
			return
		}
		seen[f] = true
		var children []Fn
		if s, ok := f.(*SplineFn); ok {
			children = s.Spline.Coordinates()
		} else {
			children = f.Children()
		}
		for _, ch := range children {
			c.refs[ch]++
			walk(ch)
		}
	}
	for _, r := range roots {
		c.refs[r] += 2 //roots are evaluated from outside, possibly repeatedly at one position: keep their memo
		walk(r)
	}
	return c
}

func (c *compiler) resetMemos() {
	for _, m := range c.memos {
		m.reset()
	}
}

func (c *compiler) compile(f Fn) Eval {
	if e, ok := c.compiled[f]; ok {
		return e
	}
	e := c.build(f)
	c.compiled[f] = e
	return e
}

func (c *compiler) newMemo() *memoState {
	m := &memoState{lastX: math.NaN(), lastY: math.NaN(), lastZ: math.NaN()}
	c.memos = append(c.memos, m)
	return m
}

func (c *compiler) build(f Fn) Eval {
	switch n := f.(type) {
	case *ConstantFn:
		v := n.Value
		return func(x, y, z float64) float64 { return v }
	case *NamedFn:
		in := c.compile(n.Input)
		m := c.newMemo()
		return func(x, y, z float64) float64 {
			if x == m.lastX && y == m.lastY && z == m.lastZ {
				return m.lastValue
			}
			m.lastX, m.lastY, m.lastZ = x, y, z
			m.lastValue = in(x, y, z)
			return m.lastValue
		}
	case *CacheFn:
		in := c.compile(n.Input)
		m := c.newMemo()
		if n.flat {
			return func(x, y, z float64) float64 {
				if x == m.lastX && z == m.lastZ {
					return m.lastValue
				}
				m.lastX, m.lastZ = x, z
				m.lastValue = in(x, y, z)
				return m.lastValue
			}
		}
		return func(x, y, z float64) float64 {
			if x == m.lastX && y == m.lastY && z == m.lastZ {
				return m.lastValue
			}
			m.lastX, m.lastY, m.lastZ = x, y, z
			m.lastValue = in(x, y, z)
			return m.lastValue
		}
	case *InterpolatedFn:
		in := c.compile(n.Input)
		return func(x, y, z float64) float64 {
			if n.useCurrent {
				return n.current
			}
			return n.interpolate(in, x, y, z)
		}
	case *NoiseFn:
		return c.buildNoise(n)
	case *ShiftNoiseFn:
		noise := n.Noise
		switch n.Mode {
		case shiftModeA:
			return func(x, y, z float64) float64 {
				return float64(noise.Get3D(float64(x*0.25), 0.0, float64(z*0.25)) * 4.0)
			}
		case shiftModeB:
			return func(x, y, z float64) float64 {
				return float64(noise.Get3D(float64(z*0.25), float64(x*0.25), 0.0) * 4.0)
			}
		default:
			return func(x, y, z float64) float64 {
				return float64(noise.Get3D(float64(x*0.25), float64(y*0.25), float64(z*0.25)) * 4.0)
			}
		}
	case *BlendedNoiseFn:
		return n.Noise.Sample
	case *UnaryFn:
		return c.buildUnary(n)
	case *BinaryFn:
		return c.buildBinary(n)
	case *ClampFn:
		in := c.compile(n.Input)
		lo, hi := n.Min, n.Max
		return func(x, y, z float64) float64 {
			v := in(x, y, z)
			if v < lo {
				return lo
			}
			if v > hi {
				return hi
			}
			return v
		}
	case *RangeChoiceFn:
		in := c.compile(n.Input)
		a := c.compile(n.WhenInRange)
		b := c.compile(n.WhenOutOfRange)
		lo, hi := n.MinInclusive, n.MaxExclusive
		return func(x, y, z float64) float64 {
			v := in(x, y, z)
			if v >= lo && v < hi {
				return a(x, y, z)
			}
			return b(x, y, z)
		}
	case *IntervalSelectFn:
		in := c.compile(n.Input)
		th := n.Thresholds
		fns := make([]Eval, len(n.Functions))
		for i, f := range n.Functions {
			fns[i] = c.compile(f)
		}
		return func(x, y, z float64) float64 {
			v := in(x, y, z)
			for i, t := range th {
				if v < t {
					return fns[i](x, y, z)
				}
			}
			return fns[len(th)](x, y, z)
		}
	case *LerpFn:
		alpha := c.compile(n.Alpha)
		first := c.compile(n.First)
		second := c.compile(n.Second)
		return func(x, y, z float64) float64 {
			a := alpha(x, y, z)
			if a == 0.0 {
				return first(x, y, z)
			}
			if a == 1.0 {
				return second(x, y, z)
			}
			return flerp(a, first(x, y, z), second(x, y, z))
		}
	case *GradientFn:
		if n.Tiling == tilingClamp && n.Axis == axisY {
			from, rng, fv, tv := n.fromCoordF, n.rangeF, n.FromValue, n.ToValue
			return func(x, y, z float64) float64 {
				a := (y - from) / rng
				if a < 0 {
					return fv
				}
				if a > 1 {
					return tv
				}
				return fv + float64(a*(tv-fv))
			}
		}
		return n.Compute
	case *FindTopSurfaceFn:
		density := c.compile(n.Density)
		upper := c.compile(n.UpperBound)
		lower, cellI := n.LowerBound, n.CellHeight
		cell := float64(cellI)
		return func(x, y, z float64) float64 {
			ub := upper(x, y, z)
			i := phpInt(float64(math.Floor(ub/cell) * cell))
			for ; i >= lower; i -= cellI {
				if density(x, float64(i), z) > 0 {
					return float64(i)
				}
			}
			return float64(lower)
		}
	case *SplineFn:
		s := c.compileSpline(n.Spline)
		return s.compute
	case *SliceFn:
		in := c.compile(n.Input)
		v := n.Coordinate
		switch n.Axis {
		case axisX:
			return func(x, y, z float64) float64 { return in(v, y, z) }
		case axisY:
			return func(x, y, z float64) float64 { return in(x, v, z) }
		}
		return func(x, y, z float64) float64 { return in(x, y, v) }
	}
	return f.Compute
}

func (c *compiler) buildNoise(n *NoiseFn) Eval {
	noise := n.Noise
	axis := func(scale float64, shift Fn, pick int) Eval {
		var sh Eval
		if shift != nil {
			sh = c.compile(shift)
		}
		coord := func(x, y, z float64) float64 {
			switch pick {
			case 0:
				return x
			case 1:
				return y
			}
			return z
		}
		switch {
		case scale == 0.0 && sh == nil:
			return func(x, y, z float64) float64 { return 0.0 }
		case scale == 0.0:
			return func(x, y, z float64) float64 { return 0.0 + sh(x, y, z) }
		case scale == 1.0 && sh == nil:
			return coord
		case scale == 1.0:
			return func(x, y, z float64) float64 { return coord(x, y, z) + sh(x, y, z) }
		case sh == nil:
			return func(x, y, z float64) float64 { return float64(coord(x, y, z) * scale) }
		default:
			return func(x, y, z float64) float64 { return float64(coord(x, y, z)*scale) + sh(x, y, z) }
		}
	}
	// common shapes without shifts get direct closures
	if n.ShiftX == nil && n.ShiftY == nil && n.ShiftZ == nil {
		xz, ys := n.XZScale, n.YScale
		if ys == 0.0 && xz != 0.0 {
			return func(x, y, z float64) float64 {
				return noise.Get3D(float64(x*xz), 0.0, float64(z*xz))
			}
		}
		if xz != 0.0 && xz != 1.0 && ys != 1.0 {
			return func(x, y, z float64) float64 {
				return noise.Get3D(float64(x*xz), float64(y*ys), float64(z*xz))
			}
		}
	}
	ax := axis(n.XZScale, n.ShiftX, 0)
	ay := axis(n.YScale, n.ShiftY, 1)
	az := axis(n.XZScale, n.ShiftZ, 2)
	return func(x, y, z float64) float64 {
		return noise.Get3D(ax(x, y, z), ay(x, y, z), az(x, y, z))
	}
}

func (c *compiler) buildUnary(n *UnaryFn) Eval {
	in := c.compile(n.Input)
	switch n.Op {
	case opAbs:
		return func(x, y, z float64) float64 { return math.Abs(in(x, y, z)) }
	case opSquare:
		return func(x, y, z float64) float64 { d := in(x, y, z); return d * d }
	case opCube:
		return func(x, y, z float64) float64 { d := in(x, y, z); return float64(d*d) * d }
	case opHalfNegative:
		return func(x, y, z float64) float64 {
			d := in(x, y, z)
			if d > 0 {
				return d
			}
			return d * 0.5
		}
	case opQuarterNegative:
		return func(x, y, z float64) float64 {
			d := in(x, y, z)
			if d > 0 {
				return d
			}
			return d * 0.25
		}
	case opNegate:
		return func(x, y, z float64) float64 { return -in(x, y, z) }
	}
	op := n.Op
	return func(x, y, z float64) float64 { return applyUnary(op, in(x, y, z)) }
}

func (c *compiler) buildBinary(n *BinaryFn) Eval {
	left := c.compile(n.Left)
	right := c.compile(n.Right)
	lc, lIsConst := n.Left.(*ConstantFn)
	rc, rIsConst := n.Right.(*ConstantFn)
	switch n.Op {
	case opAdd:
		if rIsConst {
			v := rc.Value
			return func(x, y, z float64) float64 { return left(x, y, z) + v }
		}
		if lIsConst {
			v := lc.Value
			return func(x, y, z float64) float64 { return v + right(x, y, z) }
		}
		return func(x, y, z float64) float64 { return left(x, y, z) + right(x, y, z) }
	case opSub:
		return func(x, y, z float64) float64 { return left(x, y, z) - right(x, y, z) }
	case opMul:
		if rIsConst {
			v := rc.Value
			return func(x, y, z float64) float64 { return left(x, y, z) * v }
		}
		if lIsConst {
			v := lc.Value
			return func(x, y, z float64) float64 { return v * right(x, y, z) }
		}
		return func(x, y, z float64) float64 {
			a := left(x, y, z)
			if a == 0 {
				return 0.0
			}
			return a * right(x, y, z)
		}
	case opDiv:
		if n.plain {
			return func(x, y, z float64) float64 { a := left(x, y, z); return a / right(x, y, z) }
		}
		return func(x, y, z float64) float64 {
			a := left(x, y, z)
			if a == 0 {
				return 0.0
			}
			return a / right(x, y, z)
		}
	case opMin:
		bound := n.Right.MinValue()
		return func(x, y, z float64) float64 {
			a := left(x, y, z)
			if a < bound {
				return a
			}
			b := right(x, y, z)
			if a < b {
				return a
			}
			return b
		}
	default:
		bound := n.Right.MaxValue()
		return func(x, y, z float64) float64 {
			a := left(x, y, z)
			if a > bound {
				return a
			}
			b := right(x, y, z)
			if a > b {
				return a
			}
			return b
		}
	}
}

type compiledSplineValue struct {
	c float64
	s *compiledSpline
}

type compiledSpline struct {
	coordinate    Eval
	locations     []float64
	values        []compiledSplineValue
	derivatives   []float64
	vanillaFloats bool
}

func (c *compiler) compileSpline(s *CubicSpline) *compiledSpline {
	cs := &compiledSpline{coordinate: c.compile(s.Coordinate), locations: s.Locations, derivatives: s.Derivatives, vanillaFloats: s.vanillaFloats}
	for _, v := range s.Values {
		if v.Spline != nil {
			cs.values = append(cs.values, compiledSplineValue{s: c.compileSpline(v.Spline)})
		} else {
			cs.values = append(cs.values, compiledSplineValue{c: v.Const})
		}
	}
	return cs
}

func (v *compiledSplineValue) compute(x, y, z float64) float64 {
	if v.s != nil {
		return v.s.compute(x, y, z)
	}
	return v.c
}

// compute mirrors CubicSpline.Compute.
func (s *compiledSpline) compute(x, y, z float64) float64 {
	c := s.coordinate(x, y, z)
	if s.vanillaFloats {
		c = fround(c)
	}
	locations := s.locations
	n := len(locations) - 1
	i := -1
	for i < n && c >= locations[i+1] {
		i++
	}
	if i < 0 {
		v0 := s.values[0].compute(x, y, z)
		return fround(v0 + fround(float64(s.derivatives[0]*fround(c-locations[0]))))
	}
	if i == n {
		vn := s.values[n].compute(x, y, z)
		return fround(vn + fround(float64(s.derivatives[n]*fround(c-locations[n]))))
	}
	loc0 := locations[i]
	loc1 := locations[i+1]
	der0 := s.derivatives[i]
	der1 := s.derivatives[i+1]
	span := fround(loc1 - loc0)
	f := fround(fround(c-loc0) / span)
	val0 := s.values[i].compute(x, y, z)
	val1 := s.values[i+1].compute(x, y, z)
	dv := fround(val1 - val0)
	f8 := fround(fround(float64(der0*span)) - dv)
	f9 := fround(fround(float64(-der1*span)) + dv)
	return fround(flerp(f, val0, val1) + fround(float64(fround(float64(f*fround(1.0-f)))*flerp(f, f8, f9))))
}
