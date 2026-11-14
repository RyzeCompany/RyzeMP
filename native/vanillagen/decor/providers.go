package decor

import (
	"fmt"
	"math"
)

// fround is FloatMath::fround (Veltkamp splitting): rounding to float32 precision exactly like the PHP code.
func fround(x float64) float64 {
	c := float64(x * 536870913.0)
	return c - (c - x)
}

// IntProvider is feature/IntProvider.php.
type IntProvider struct {
	Sample   func(r *Random) int
	Min, Max int
}

func constantInt(v int) *IntProvider {
	return &IntProvider{Sample: func(*Random) int { return v }, Min: v, Max: v}
}

// parseIntProvider is Providers::int().
func parseIntProvider(js any) (*IntProvider, error) {
	if v, ok := isInt(js); ok {
		return constantInt(v), nil
	}
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid int provider")
	}
	t, err := typeOf(o, "type")
	if err != nil {
		return nil, err
	}
	switch t {
	case "constant":
		v, err := jInt(o, "value")
		if err != nil {
			return nil, err
		}
		return constantInt(v), nil
	case "uniform", "biased_to_bottom", "very_biased_to_bottom":
		min, err := jInt(o, "min_inclusive")
		if err != nil {
			return nil, err
		}
		max, err := jInt(o, "max_inclusive")
		if err != nil {
			return nil, err
		}
		switch t {
		case "uniform":
			return &IntProvider{Sample: func(r *Random) int { return r.NextBoundedInt(max-min+1) + min }, Min: min, Max: max}, nil
		case "biased_to_bottom":
			return &IntProvider{Sample: func(r *Random) int { return min + r.NextBoundedInt(r.NextBoundedInt(max-min+1)+1) }, Min: min, Max: max}, nil
		default:
			return &IntProvider{Sample: func(r *Random) int {
				return min + r.NextBoundedInt(r.NextBoundedInt(r.NextBoundedInt(max-min+1)+1)+1)
			}, Min: min, Max: max}, nil
		}
	case "clamped":
		source, err := parseIntProvider(o["source"])
		if err != nil {
			return nil, err
		}
		min, err := jInt(o, "min_inclusive")
		if err != nil {
			return nil, err
		}
		max, err := jInt(o, "max_inclusive")
		if err != nil {
			return nil, err
		}
		lo := min
		if source.Min > lo {
			lo = source.Min
		}
		hi := max
		if source.Max < hi {
			hi = source.Max
		}
		return &IntProvider{Sample: func(r *Random) int {
			v := source.Sample(r)
			if v < min {
				return min
			}
			if v > max {
				return max
			}
			return v
		}, Min: lo, Max: hi}, nil
	case "clamped_normal":
		mean, err := jFloat(o, "mean")
		if err != nil {
			return nil, err
		}
		deviation, err := jFloat(o, "deviation")
		if err != nil {
			return nil, err
		}
		mean = fround(mean)
		deviation = fround(deviation)
		min, err := jInt(o, "min_inclusive")
		if err != nil {
			return nil, err
		}
		max, err := jInt(o, "max_inclusive")
		if err != nil {
			return nil, err
		}
		return &IntProvider{Sample: func(r *Random) int {
			v := fround(mean + fround(float64(fround(r.NextGaussian())*deviation)))
			if v < float64(min) {
				return min
			}
			if v > float64(max) {
				return max
			}
			return phpIntOfFloat(v)
		}, Min: min, Max: max}, nil
	case "trapezoid":
		min, err := jInt(o, "min")
		if err != nil {
			return nil, err
		}
		max, err := jInt(o, "max")
		if err != nil {
			return nil, err
		}
		plateau, err := jIntDef(o, "plateau", 0)
		if err != nil {
			return nil, err
		}
		if plateau == 0 && max == -min {
			return &IntProvider{Sample: func(r *Random) int {
				a := r.NextBoundedInt(max + 1)
				return a - r.NextBoundedInt(max+1)
			}, Min: min, Max: max}, nil
		}
		rng := max - min
		if plateau == rng {
			return &IntProvider{Sample: func(r *Random) int { return r.NextBoundedInt(max-min+1) + min }, Min: min, Max: max}, nil
		}
		plateauStart := (rng - plateau) / 2
		plateauEnd := rng - plateauStart
		return &IntProvider{Sample: func(r *Random) int {
			a := r.NextBoundedInt(plateauEnd + 1)
			return min + a + r.NextBoundedInt(plateauStart+1)
		}, Min: min, Max: max}, nil
	case "weighted_list":
		var items []*IntProvider
		var weights []int
		total := 0
		list, ok := o["distribution"].([]any)
		if !ok {
			return nil, fmt.Errorf("invalid weighted list")
		}
		for _, e := range list {
			eo, ok := asObj(e)
			if !ok {
				return nil, fmt.Errorf("invalid weighted list entry")
			}
			item, err := parseIntProvider(eo["data"])
			if err != nil {
				return nil, err
			}
			w, err := jInt(eo, "weight")
			if err != nil {
				return nil, err
			}
			items = append(items, item)
			weights = append(weights, w)
			total += w
		}
		lo, hi := math.MaxInt, math.MinInt
		for _, it := range items {
			if it.Min < lo {
				lo = it.Min
			}
			if it.Max > hi {
				hi = it.Max
			}
		}
		return &IntProvider{Sample: func(r *Random) int { return items[pickWeighted(r, weights, total)].Sample(r) }, Min: lo, Max: hi}, nil
	}
	return nil, fmt.Errorf("unknown int provider type %s", t)
}

// pickWeighted is WeightedList.getRandomOrThrow(): the index of the picked entry.
func pickWeighted(r *Random, weights []int, total int) int {
	if total <= 0 {
		panic("weighted list has no elements")
	}
	selection := r.NextBoundedInt(total)
	for i, w := range weights {
		selection -= w
		if selection < 0 {
			return i
		}
	}
	panic("selection exceeded total weight")
}

// FloatProvider is a float provider (Providers::float()).
type FloatProvider func(r *Random) float64

func parseFloatProvider(js any) (FloatProvider, error) {
	if v, ok := isNumber(js); ok {
		v = fround(v)
		return func(*Random) float64 { return v }, nil
	}
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid float provider")
	}
	t, err := typeOf(o, "type")
	if err != nil {
		return nil, err
	}
	switch t {
	case "constant":
		v, err := jFloat(o, "value")
		if err != nil {
			return nil, err
		}
		v = fround(v)
		return func(*Random) float64 { return v }, nil
	case "uniform":
		min, err := jFloat(o, "min_inclusive")
		if err != nil {
			return nil, err
		}
		maxEx, err := jFloat(o, "max_exclusive")
		if err != nil {
			return nil, err
		}
		min = fround(min)
		span := fround(fround(maxEx) - min)
		return func(r *Random) float64 { return fround(fround(float64(r.NextFloat()*span)) + min) }, nil
	case "clamped_normal":
		mean, err1 := jFloat(o, "mean")
		deviation, err2 := jFloat(o, "deviation")
		min, err3 := jFloat(o, "min")
		max, err4 := jFloat(o, "max")
		if err := firstErr(err1, err2, err3, err4); err != nil {
			return nil, err
		}
		mean, deviation, min, max = fround(mean), fround(deviation), fround(min), fround(max)
		return func(r *Random) float64 {
			v := fround(mean + fround(float64(fround(r.NextGaussian())*deviation)))
			if v < min {
				return min
			}
			if v > max {
				return max
			}
			return v
		}, nil
	case "trapezoid":
		min, err1 := jFloat(o, "min")
		max, err2 := jFloat(o, "max")
		plateau, err3 := jFloatDef(o, "plateau", 0.0)
		if err := firstErr(err1, err2, err3); err != nil {
			return nil, err
		}
		min, max, plateau = fround(min), fround(max), fround(plateau)
		rng := fround(max - min)
		plateauStart := fround(fround(rng-plateau) / 2.0)
		plateauEnd := fround(rng - plateauStart)
		return func(r *Random) float64 {
			a := fround(min + fround(float64(r.NextFloat()*plateauEnd)))
			return fround(a + fround(float64(r.NextFloat()*plateauStart)))
		}, nil
	}
	return nil, fmt.Errorf("unknown float provider type %s", t)
}

func firstErr(errs ...error) error {
	for _, e := range errs {
		if e != nil {
			return e
		}
	}
	return nil
}

// Anchor is a vertical anchor (Providers::anchor()).
type Anchor func(c *Ctx) int

func parseAnchor(js any) (Anchor, error) {
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid vertical anchor")
	}
	if o["absolute"] != nil {
		y, err := jInt(o, "absolute")
		if err != nil {
			return nil, err
		}
		return func(*Ctx) int { return y }, nil
	}
	if o["above_bottom"] != nil {
		v, err := jInt(o, "above_bottom")
		if err != nil {
			return nil, err
		}
		return func(c *Ctx) int { return c.MinGenY + v }, nil
	}
	if o["below_top"] != nil {
		v, err := jInt(o, "below_top")
		if err != nil {
			return nil, err
		}
		return func(c *Ctx) int { return c.GenDepth - 1 + c.MinGenY - v }, nil
	}
	if o["relative_to_sea_level"] != nil {
		v, err := jInt(o, "relative_to_sea_level")
		if err != nil {
			return nil, err
		}
		return func(c *Ctx) int { return c.SeaLevel + v }, nil
	}
	return nil, fmt.Errorf("invalid vertical anchor")
}

// HeightProvider is Providers::height().
type HeightProvider func(r *Random, c *Ctx) int

func parseHeightProvider(js any) (HeightProvider, error) {
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid height provider")
	}
	if o["type"] == nil {
		a, err := parseAnchor(o)
		if err != nil {
			return nil, err
		}
		return func(r *Random, c *Ctx) int { return a(c) }, nil
	}
	t, err := typeOf(o, "type")
	if err != nil {
		return nil, err
	}
	switch t {
	case "constant":
		a, err := parseAnchor(o["value"])
		if err != nil {
			return nil, err
		}
		return func(r *Random, c *Ctx) int { return a(c) }, nil
	case "uniform", "trapezoid", "biased_to_bottom", "very_biased_to_bottom":
		minA, err := parseAnchor(o["min_inclusive"])
		if err != nil {
			return nil, err
		}
		maxA, err := parseAnchor(o["max_inclusive"])
		if err != nil {
			return nil, err
		}
		switch t {
		case "uniform":
			return func(r *Random, c *Ctx) int {
				min, max := minA(c), maxA(c)
				if min > max {
					return min
				}
				return r.NextBoundedInt(max-min+1) + min
			}, nil
		case "trapezoid":
			plateau, err := jIntDef(o, "plateau", 0)
			if err != nil {
				return nil, err
			}
			return func(r *Random, c *Ctx) int {
				min, max := minA(c), maxA(c)
				if min > max {
					return min
				}
				rng := max - min
				if plateau >= rng {
					return r.NextBoundedInt(max-min+1) + min
				}
				plateauStart := (rng - plateau) / 2
				plateauEnd := rng - plateauStart
				a := r.NextBoundedInt(plateauEnd + 1)
				return min + a + r.NextBoundedInt(plateauStart+1)
			}, nil
		case "biased_to_bottom":
			inner, err := jIntDef(o, "inner", 1)
			if err != nil {
				return nil, err
			}
			return func(r *Random, c *Ctx) int {
				min, max := minA(c), maxA(c)
				if max-min-inner+1 <= 0 {
					return min
				}
				limit := r.NextBoundedInt(max - min - inner + 1)
				return r.NextBoundedInt(limit+inner) + min
			}, nil
		default:
			inner, err := jIntDef(o, "inner", 1)
			if err != nil {
				return nil, err
			}
			return func(r *Random, c *Ctx) int {
				min, max := minA(c), maxA(c)
				if max-min-inner+1 <= 0 {
					return min
				}
				upper := r.MthNextInt(min+inner, max)
				biasedUpper := r.MthNextInt(min, upper-1)
				return r.MthNextInt(min, biasedUpper-1+inner)
			}, nil
		}
	case "weighted_list":
		list, ok := o["distribution"].([]any)
		if !ok {
			return nil, fmt.Errorf("invalid weighted list")
		}
		var items []HeightProvider
		var weights []int
		total := 0
		for _, e := range list {
			eo, ok := asObj(e)
			if !ok {
				return nil, fmt.Errorf("invalid weighted list entry")
			}
			item, err := parseHeightProvider(eo["data"])
			if err != nil {
				return nil, err
			}
			w, err := jInt(eo, "weight")
			if err != nil {
				return nil, err
			}
			items = append(items, item)
			weights = append(weights, w)
			total += w
		}
		return func(r *Random, c *Ctx) int { return items[pickWeighted(r, weights, total)](r, c) }, nil
	}
	return nil, fmt.Errorf("unknown height provider type %s", t)
}

// phpIntOfFloat is PHP's (int) cast of a finite float (truncation toward zero).
func phpIntOfFloat(f float64) int {
	if math.IsNaN(f) || math.IsInf(f, 0) {
		return 0
	}
	return int(f)
}
