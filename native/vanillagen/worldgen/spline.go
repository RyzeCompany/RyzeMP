package worldgen

import "math"

// SplineValue is either a constant (Spline == nil) or a nested spline.
type SplineValue struct {
	Const  float64
	Spline *CubicSpline
}

func (v SplineValue) min() float64 {
	if v.Spline != nil {
		return v.Spline.minValue
	}
	return v.Const
}

func (v SplineValue) max() float64 {
	if v.Spline != nil {
		return v.Spline.maxValue
	}
	return v.Const
}

func (v SplineValue) compute(x, y, z float64) float64 {
	if v.Spline != nil {
		return v.Spline.Compute(x, y, z)
	}
	return v.Const
}

// CubicSpline is density/CubicSpline.php (single precision Hermite spline).
type CubicSpline struct {
	Coordinate  Fn
	Locations   []float64
	Values      []SplineValue
	Derivatives []float64
	// vanillaFloats: round the coordinate to float32 (false = deepslate behaviour, for its reference values)
	vanillaFloats      bool
	minValue, maxValue float64
}

func newCubicSpline(coordinate Fn, locations []float64, values []SplineValue, derivatives []float64, vanillaFloats bool) *CubicSpline {
	s := &CubicSpline{Coordinate: coordinate, Locations: locations, Values: values, Derivatives: derivatives, vanillaFloats: vanillaFloats}
	s.minValue, s.maxValue = s.computeRange()
	return s
}

func (s *CubicSpline) Coordinates() []Fn {
	r := []Fn{s.Coordinate}
	for _, v := range s.Values {
		if v.Spline != nil {
			r = append(r, v.Spline.Coordinates()...)
		}
	}
	return r
}

func (s *CubicSpline) computeRange() (float64, float64) {
	last := len(s.Locations) - 1
	lo, hi := math.Inf(1), math.Inf(-1)
	for _, v := range s.Values {
		lo = phpMin(lo, v.min())
		hi = phpMax(hi, v.max())
	}
	inMin := s.Coordinate.MinValue()
	inMax := s.Coordinate.MaxValue()
	if inMin < s.Locations[0] && s.Derivatives[0] != 0.0 {
		for _, v := range []float64{s.Values[0].min(), s.Values[0].max()} {
			e := v + float64(s.Derivatives[0]*(inMin-s.Locations[0]))
			lo = phpMin(lo, e)
			hi = phpMax(hi, e)
		}
	}
	if inMax > s.Locations[last] && s.Derivatives[last] != 0.0 {
		for _, v := range []float64{s.Values[last].min(), s.Values[last].max()} {
			e := v + float64(s.Derivatives[last]*(inMax-s.Locations[last]))
			lo = phpMin(lo, e)
			hi = phpMax(hi, e)
		}
	}
	for i := 0; i < last; i++ {
		dl := s.Derivatives[i]
		dr := s.Derivatives[i+1]
		if dl != 0.0 || dr != 0.0 {
			delta := s.Locations[i+1] - s.Locations[i]
			lMin := s.Values[i].min()
			lMax := s.Values[i].max()
			rMin := s.Values[i+1].min()
			rMax := s.Values[i+1].max()
			maxDeltaLeft := float64(dl * delta)
			maxDeltaRight := float64(dr * delta)
			minDelta := phpMin(maxDeltaLeft-rMax+lMin, -maxDeltaRight+rMin-lMax)
			maxDelta := phpMax(maxDeltaLeft-rMin+lMax, -maxDeltaRight+rMax-lMin)
			lo = phpMin(lo, phpMin(lMin, rMin)+float64(0.3*minDelta))
			hi = phpMax(hi, phpMax(lMax, rMax)+float64(0.3*maxDelta))
		}
	}
	if inMin < s.Locations[0] || inMax > s.Locations[last] {
		if s.Derivatives[0] != 0.0 && math.IsInf(inMin, -1) || s.Derivatives[last] != 0.0 && math.IsInf(inMax, 1) {
			return math.Inf(-1), math.Inf(1)
		}
	}
	return lo, hi
}

func (s *CubicSpline) Compute(x, y, z float64) float64 {
	c := s.Coordinate.Compute(x, y, z)
	if s.vanillaFloats {
		c = fround(c)
	}
	locations := s.Locations
	n := len(locations) - 1
	i := -1
	for i < n && c >= locations[i+1] {
		i++
	}
	if i < 0 {
		v0 := s.Values[0].compute(x, y, z)
		return fround(v0 + fround(float64(s.Derivatives[0]*fround(c-locations[0]))))
	}
	if i == n {
		vn := s.Values[n].compute(x, y, z)
		return fround(vn + fround(float64(s.Derivatives[n]*fround(c-locations[n]))))
	}
	loc0 := locations[i]
	loc1 := locations[i+1]
	der0 := s.Derivatives[i]
	der1 := s.Derivatives[i+1]
	span := fround(loc1 - loc0)
	f := fround(fround(c-loc0) / span)
	val0 := s.Values[i].compute(x, y, z)
	val1 := s.Values[i+1].compute(x, y, z)
	dv := fround(val1 - val0)
	f8 := fround(fround(float64(der0*span)) - dv)
	f9 := fround(fround(float64(-der1*span)) + dv)
	return fround(flerp(f, val0, val1) + fround(float64(fround(float64(f*fround(1.0-f)))*flerp(f, f8, f9))))
}
