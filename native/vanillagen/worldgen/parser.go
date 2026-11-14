package worldgen

import (
	"encoding/json"
	"fmt"
	"math"
)

// Parser builds a seed-bound density function graph from the vanilla JSON (DensityFunctionParser.php).
type Parser struct {
	data          *Data
	random        *PositionalFactory
	vanillaFloats bool
	// legacySeed is the world seed with legacy random sources (Nether, End), nil otherwise
	legacySeed *int64
	named      map[string]*NamedFn
	noises     map[string]*NoiseStack
	resolving  map[string]bool
	// every memoizing node, so that fillers can reset them
	memos []interface{ reset() }
}

func NewParser(data *Data, random *PositionalFactory, vanillaFloats bool) *Parser {
	return &Parser{data: data, random: random, vanillaFloats: vanillaFloats, named: map[string]*NamedFn{}, noises: map[string]*NoiseStack{}, resolving: map[string]bool{}}
}

// legacyNetherBiomeNoises are created with the legacy Nether biome initialization in legacy worlds, from a random
// of the world seed plus the offset (DensityFunctionParser::LEGACY_NETHER_BIOME_NOISES).
var legacyNetherBiomeNoises = map[string]int64{
	"minecraft:temperature":        0,
	"minecraft:vegetation":         1,
	"minecraft:nether/temperature": 0,
	"minecraft:nether/vegetation":  1,
}

// terrainRandom is the random of the old blended noise.
func (p *Parser) terrainRandom() *Xoroshiro {
	if p.legacySeed != nil {
		return NewLegacySource(*p.legacySeed)
	}
	return p.random.FromHashOf("minecraft:terrain")
}

func (p *Parser) GetNoise(id string) (*NoiseStack, error) {
	key := "minecraft:" + stripNamespace(id)
	if n, ok := p.noises[key]; ok {
		return n, nil
	}
	js, err := p.data.Noise(key)
	if err != nil {
		return nil, err
	}
	params, err := NoiseParameters(js)
	if err != nil {
		return nil, err
	}
	var n *NoiseStack
	if offset, ok := legacyNetherBiomeNoises[key]; ok && p.legacySeed != nil {
		n = params.CreateLegacyNetherBiome(NewLegacySource(*p.legacySeed + offset))
	} else {
		n = params.Create(p.random.FromHashOf(key))
	}
	p.noises[key] = n
	return n, nil
}

func NoiseParameters(js map[string]any) (*NormalNoiseParameters, error) {
	mode := normalizeEnabled
	if v, ok := js["normalize"]; ok && v != nil {
		switch v {
		case true, "enabled":
			mode = normalizeEnabled
		case false, "disabled":
			mode = normalizeDisabled
		case "legacy":
			mode = normalizeLegacy
		default:
			return nil, fmt.Errorf("unknown noise normalization")
		}
	}
	var modifiers []float64
	if v, ok := js["amplitude_modifiers"]; ok && v != nil {
		var err error
		if modifiers, err = jsonFloatList(js, "amplitude_modifiers"); err != nil {
			return nil, err
		}
	}
	base, err := jsonFloat(js, "base_amplitude", fptr(1.0))
	if err != nil {
		return nil, err
	}
	baseOctave, err := jsonInt(js, "base_octave", iptr(0))
	if err != nil {
		return nil, err
	}
	count, err := jsonInt(js, "octave_count", iptr(1))
	if err != nil {
		return nil, err
	}
	return NewNormalNoiseParameters(base, baseOctave, count, mode, modifiers), nil
}

func (p *Parser) Named(id string) (*NamedFn, error) {
	key := "minecraft:" + stripNamespace(id)
	if f, ok := p.named[key]; ok {
		return f, nil
	}
	if p.resolving[key] {
		return nil, fmt.Errorf("recursive density function reference %s", key)
	}
	p.resolving[key] = true
	js, err := p.data.DensityFunction(key)
	if err != nil {
		return nil, err
	}
	f, err := p.Parse(js)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", key, err)
	}
	delete(p.resolving, key)
	n := newNamed(key, f)
	p.memos = append(p.memos, n)
	p.named[key] = n
	return n, nil
}

func first(js map[string]any, keys ...string) (any, error) {
	for _, k := range keys {
		if v, ok := js[k]; ok {
			return v, nil
		}
	}
	return nil, fmt.Errorf("missing field %s", keys[0])
}

func (p *Parser) input(js map[string]any, keys ...string) (Fn, error) {
	v, err := first(js, keys...)
	if err != nil {
		return nil, err
	}
	return p.Parse(v)
}

func (p *Parser) Parse(js any) (Fn, error) {
	switch v := js.(type) {
	case string:
		return p.Named(v)
	case json.Number:
		f, err := numberToFloat(v)
		if err != nil {
			return nil, err
		}
		return newConstant(f), nil
	case map[string]any:
		return p.parseObject(v)
	}
	return nil, fmt.Errorf("invalid density function")
}

func (p *Parser) parseObject(js map[string]any) (Fn, error) {
	typ, err := jsonString(js, "type", nil)
	if err != nil {
		return nil, err
	}
	typ = stripNamespace(typ)
	switch typ {
	case "constant":
		key := "argument"
		if _, ok := js["value"]; ok && js["value"] != nil {
			key = "value"
		}
		f, err := jsonFloat(js, key, nil)
		if err != nil {
			return nil, err
		}
		return newConstant(f), nil
	case "blend_alpha":
		return &ConstantFn{bounds{0.0, 1.0}, 1.0}, nil
	case "blend_offset", "beardifier":
		return &ConstantFn{bounds{math.Inf(-1), math.Inf(1)}, 0.0}, nil
	case "blend_density":
		return p.input(js, "input", "argument")
	case "old_blended_noise":
		xz, e1 := jsonFloat(js, "xz_scale", fptr(1.0))
		y, e2 := jsonFloat(js, "y_scale", fptr(1.0))
		xzf, e3 := jsonFloat(js, "xz_factor", fptr(80.0))
		yf, e4 := jsonFloat(js, "y_factor", fptr(160.0))
		sm, e5 := jsonFloat(js, "smear_scale_multiplier", fptr(8.0))
		if err := firstErr(e1, e2, e3, e4, e5); err != nil {
			return nil, err
		}
		n := NewBlendedNoise(p.terrainRandom(), xz, y, xzf, yf, sm)
		return &BlendedNoiseFn{bounds{-n.MaxValue, n.MaxValue}, n}, nil
	case "interpolated":
		in, err := p.input(js, "input", "argument")
		if err != nil {
			return nil, err
		}
		w, e1 := jsonInt(js, "cell_size_xz", iptr(4))
		h, e2 := jsonInt(js, "cell_size_y", iptr(4))
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		return &InterpolatedFn{bounds: bounds{in.MinValue(), in.MaxValue()}, Input: in, CellWidth: w, CellHeight: h}, nil
	case "cache", "flat_cache", "cache_2d", "cache_once", "cache_all_in_cell":
		in, err := p.input(js, "input", "argument")
		if err != nil {
			return nil, err
		}
		c := newCache(in)
		p.memos = append(p.memos, c)
		return c, nil
	case "noise":
		nv, err := first(js, "noise")
		if err != nil {
			return nil, err
		}
		name, err := noiseName(nv)
		if err != nil {
			return nil, err
		}
		noise, err := p.GetNoise(name)
		if err != nil {
			return nil, err
		}
		xz, e1 := jsonFloat(js, "xz_scale", fptr(1.0))
		y, e2 := jsonFloat(js, "y_scale", fptr(1.0))
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		f := &NoiseFn{bounds: bounds{-noise.MaxValue, noise.MaxValue}, Noise: noise, XZScale: xz, YScale: y}
		for i, k := range []string{"shift_x", "shift_y", "shift_z"} {
			if v, ok := js[k]; ok && v != nil {
				s, err := p.Parse(v)
				if err != nil {
					return nil, err
				}
				switch i {
				case 0:
					f.ShiftX = s
				case 1:
					f.ShiftY = s
				default:
					f.ShiftZ = s
				}
			}
		}
		return f, nil
	case "shift_a", "shift_b", "shift":
		nv, err := first(js, "noise", "argument")
		if err != nil {
			return nil, err
		}
		name, err := noiseName(nv)
		if err != nil {
			return nil, err
		}
		noise, err := p.GetNoise(name)
		if err != nil {
			return nil, err
		}
		mode := shiftModeXYZ
		if typ == "shift_a" {
			mode = shiftModeA
		} else if typ == "shift_b" {
			mode = shiftModeB
		}
		m := float64(4 * noise.MaxValue)
		return &ShiftNoiseFn{bounds{-m, m}, mode, noise}, nil
	case "find_top_surface":
		density, err := p.input(js, "density")
		if err != nil {
			return nil, err
		}
		upper, err := p.input(js, "upper_bound")
		if err != nil {
			return nil, err
		}
		lower, e1 := jsonInt(js, "lower_bound", iptr(0))
		cell, e2 := jsonInt(js, "cell_height", iptr(1))
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		return newFindTopSurface(density, upper, lower, cell), nil
	case "range_choice":
		in, err := p.input(js, "input")
		if err != nil {
			return nil, err
		}
		lo, e1 := jsonFloat(js, "min_inclusive", fptr(0.0))
		hi, e2 := jsonFloat(js, "max_exclusive", fptr(1.0))
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		a, err := p.input(js, "when_in_range")
		if err != nil {
			return nil, err
		}
		b, err := p.input(js, "when_out_of_range")
		if err != nil {
			return nil, err
		}
		return newRangeChoice(in, lo, hi, a, b), nil
	case "clamp":
		in, err := p.input(js, "input")
		if err != nil {
			return nil, err
		}
		lo, e1 := jsonFloat(js, "min", fptr(0.0))
		hi, e2 := jsonFloat(js, "max", fptr(1.0))
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		return newClamp(in, lo, hi), nil
	case "interval_select":
		arr, err := jsonArray(js, "functions")
		if err != nil {
			return nil, err
		}
		var fns []Fn
		for _, v := range arr {
			f, err := p.Parse(v)
			if err != nil {
				return nil, err
			}
			fns = append(fns, f)
		}
		in, err := p.input(js, "input")
		if err != nil {
			return nil, err
		}
		th, err := jsonFloatList(js, "thresholds")
		if err != nil {
			return nil, err
		}
		if len(fns) != len(th)+1 {
			return nil, fmt.Errorf("interval_select needs exactly one more function than thresholds")
		}
		return newIntervalSelect(in, th, fns), nil
	case "abs", "square", "cube", "half_negative", "quarter_negative", "squeeze", "negate", "sqrt", "log", "sign", "reciprocal":
		in, err := p.input(js, "input", "argument")
		if err != nil {
			return nil, err
		}
		return newUnary(unaryOps[typ], in), nil
	case "add", "sub", "mul", "div", "min", "max":
		l, err := p.input(js, "left", "argument1")
		if err != nil {
			return nil, err
		}
		r, err := p.input(js, "right", "argument2")
		if err != nil {
			return nil, err
		}
		return newBinary(binaryOps[typ], l, r), nil
	case "spline":
		sv, err := first(js, "spline")
		if err != nil {
			return nil, err
		}
		s, err := p.parseSpline(sv)
		if err != nil {
			return nil, err
		}
		if s.Spline != nil {
			return &SplineFn{bounds{s.Spline.minValue, s.Spline.maxValue}, s.Spline}, nil
		}
		return newConstant(s.Const), nil
	case "gradient":
		axisName, e1 := jsonString(js, "axis", sptr("y"))
		tilingName, e2 := jsonString(js, "tiling", sptr("clamp_to_edge"))
		from, e3 := jsonInt(js, "from_coordinate", iptr(0))
		to, e4 := jsonInt(js, "to_coordinate", iptr(1))
		fv, e5 := jsonFloat(js, "from_value", fptr(0.0))
		tv, e6 := jsonFloat(js, "to_value", fptr(1.0))
		if err := firstErr(e1, e2, e3, e4, e5, e6); err != nil {
			return nil, err
		}
		axis, ok := map[string]int{"x": axisX, "y": axisY, "z": axisZ}[axisName]
		if !ok {
			return nil, fmt.Errorf("unknown gradient axis")
		}
		tiling, ok := map[string]int{"clamp_to_edge": tilingClamp, "repeat": tilingRepeat, "mirrored_repeat": tilingMirrored}[tilingName]
		if !ok {
			return nil, fmt.Errorf("unknown gradient tiling")
		}
		return newGradient(axis, tiling, from, to, fv, tv), nil
	case "y_clamped_gradient":
		from, e1 := jsonInt(js, "from_y", nil)
		to, e2 := jsonInt(js, "to_y", nil)
		fv, e3 := jsonFloat(js, "from_value", nil)
		tv, e4 := jsonFloat(js, "to_value", nil)
		if err := firstErr(e1, e2, e3, e4); err != nil {
			return nil, err
		}
		return newGradient(axisY, tilingClamp, from, to, fv, tv), nil
	case "lerp":
		a, err := p.input(js, "alpha")
		if err != nil {
			return nil, err
		}
		b, err := p.input(js, "first")
		if err != nil {
			return nil, err
		}
		c, err := p.input(js, "second")
		if err != nil {
			return nil, err
		}
		return newLerp(a, b, c), nil
	case "slice":
		axisName, e1 := jsonString(js, "axis", nil)
		coordinate, e2 := jsonInt(js, "coordinate", iptr(0))
		if err := firstErr(e1, e2); err != nil {
			return nil, err
		}
		axis, ok := map[string]int{"x": axisX, "y": axisY, "z": axisZ}[axisName]
		if !ok {
			return nil, fmt.Errorf("unknown slice axis")
		}
		in, err := p.input(js, "input", "argument")
		if err != nil {
			return nil, err
		}
		return &SliceFn{bounds{in.MinValue(), in.MaxValue()}, axis, float64(coordinate), in}, nil
	case "distance_to_point":
		point, err := jsonFloatList(js, "point")
		if err != nil {
			return nil, err
		}
		if len(point) != 3 {
			return nil, fmt.Errorf("invalid distance_to_point point")
		}
		metricName, err := jsonString(js, "metric", sptr("euclidean"))
		if err != nil {
			return nil, err
		}
		metric, ok := map[string]int{"euclidean": metricEuclidean, "euclidean_squared": metricEuclideanSquared, "manhattan": metricManhattan, "chebyshev": metricChebyshev}[metricName]
		if !ok {
			return nil, fmt.Errorf("unknown distance metric")
		}
		return &DistanceToPointFn{bounds{0, math.Inf(1)}, point[0], point[1], point[2], metric}, nil
	case "end_outer_islands", "end_islands":
		var seed int64
		if p.legacySeed != nil {
			seed = *p.legacySeed
		}
		return newEndIslands(seed), nil
	}
	return nil, fmt.Errorf("unsupported density function type %q", typ)
}

func firstErr(errs ...error) error {
	for _, e := range errs {
		if e != nil {
			return e
		}
	}
	return nil
}

func noiseName(v any) (string, error) {
	s, ok := v.(string)
	if !ok {
		return "", fmt.Errorf("inline noise definitions are not supported")
	}
	return "minecraft:" + stripNamespace(s), nil
}

func (p *Parser) parseSpline(js any) (SplineValue, error) {
	switch v := js.(type) {
	case json.Number:
		f, err := numberToFloat(v)
		if err != nil {
			return SplineValue{}, err
		}
		if p.vanillaFloats {
			f = fround(f)
		}
		return SplineValue{Const: f}, nil
	case map[string]any:
		cv, err := first(v, "coordinate")
		if err != nil {
			return SplineValue{}, err
		}
		coordinate, err := p.Parse(cv)
		if err != nil {
			return SplineValue{}, err
		}
		points, err := jsonArray(v, "points")
		if err != nil {
			return SplineValue{}, err
		}
		var locations, derivatives []float64
		var values []SplineValue
		for _, pt := range points {
			m, ok := pt.(map[string]any)
			if !ok {
				return SplineValue{}, fmt.Errorf("invalid spline point")
			}
			loc, err := jsonFloat(m, "location", fptr(0.0))
			if err != nil {
				return SplineValue{}, err
			}
			locations = append(locations, fround(loc))
			vv, err := first(m, "value")
			if err != nil {
				return SplineValue{}, err
			}
			sv, err := p.parseSpline(vv)
			if err != nil {
				return SplineValue{}, err
			}
			values = append(values, sv)
			der, err := jsonFloat(m, "derivative", fptr(0.0))
			if err != nil {
				return SplineValue{}, err
			}
			derivatives = append(derivatives, fround(der))
		}
		if len(locations) == 0 {
			return SplineValue{Const: 0.0}, nil
		}
		return SplineValue{Spline: newCubicSpline(coordinate, locations, values, derivatives, p.vanillaFloats)}, nil
	}
	return SplineValue{}, fmt.Errorf("invalid spline")
}
