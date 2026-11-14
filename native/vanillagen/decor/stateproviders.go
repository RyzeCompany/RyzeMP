package decor

import (
	"fmt"
	"math"
	"strconv"

	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"
)

// StateProvider is a block state provider (StateProviders::parse()): the state, or -1 for none (PHP's null).
type StateProvider func(c *Ctx, r *Random, x, y, z int) int

// ParseStateProvider is FeatureRegistry::stateProvider() / StateProviders::parse().
func (reg *Registry) ParseStateProvider(js any) (StateProvider, error) {
	b := reg.B
	if s, ok := js.(string); ok {
		id := namespaced(s)
		rel := "data/minecraft/worldgen/block_state_provider/" + stripNamespace(id) + ".json"
		if reg.Data.Exists(rel) {
			o, err := reg.Data.ReadObject(rel)
			if err != nil {
				return nil, err
			}
			return reg.ParseStateProvider(o)
		}
		state, err := b.ParseState(s)
		if err != nil {
			return nil, err
		}
		return func(*Ctx, *Random, int, int, int) int { return state }, nil
	}
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid block state provider")
	}
	if o["type"] == nil {
		state, err := b.StateFromJSON(o)
		if err != nil {
			return nil, err
		}
		return func(*Ctx, *Random, int, int, int) int { return state }, nil
	}
	t, err := typeOf(o, "type")
	if err != nil {
		return nil, err
	}
	switch t {
	case "simple_state_provider":
		state, err := b.StateFromJSON(o["state"])
		if err != nil {
			return nil, err
		}
		return func(*Ctx, *Random, int, int, int) int { return state }, nil
	case "weighted":
		list, ok := o["entries"].([]any)
		if !ok {
			return nil, fmt.Errorf("invalid weighted list")
		}
		var items, weights []int
		total := 0
		for _, e := range list {
			eo, ok := asObj(e)
			if !ok {
				return nil, fmt.Errorf("invalid weighted list entry")
			}
			state, err := b.StateFromJSON(eo["data"])
			if err != nil {
				return nil, err
			}
			w, err := jInt(eo, "weight")
			if err != nil {
				return nil, err
			}
			items = append(items, state)
			weights = append(weights, w)
			total += w
		}
		return func(c *Ctx, r *Random, x, y, z int) int { return items[pickWeighted(r, weights, total)] }, nil
	case "randomized_int":
		source, err := reg.ParseStateProvider(o["source"])
		if err != nil {
			return nil, err
		}
		property, err := jString(o, "property")
		if err != nil {
			return nil, err
		}
		values, err := parseIntProvider(o["values"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, r *Random, x, y, z int) int {
			state := source(c, r, x, y, z)
			if state < 0 {
				state = c.Level.GetState(x, y, z)
			}
			if !c.B.HasProperty(state, property) {
				return state
			}
			return c.B.WithInt(state, property, values.Sample(r))
		}, nil
	case "rotated":
		source, err := reg.ParseStateProvider(o["state"])
		if err != nil {
			return nil, err
		}
		fixed := -1
		if name, ok := o["direction"].(string); ok {
			if fixed, err = directionFromName(name); err != nil {
				return nil, err
			}
		}
		return func(c *Ctx, r *Random, x, y, z int) int {
			direction := fixed
			if direction < 0 {
				direction = r.NextBoundedInt(6)
			}
			state := source(c, r, x, y, z)
			if state < 0 {
				state = c.Level.GetState(x, y, z)
			}
			state = c.B.With(state, "axis", [3]string{"x", "y", "z"}[DirAxis[direction]])
			//FACING and HORIZONTAL_FACING are both called "facing"; values the block does not have are ignored
			return c.B.With(state, "facing", DirNames[direction])
		}, nil
	case "random_block":
		list, err := b.BlockSetOrder(o["blocks"])
		if err != nil {
			return nil, err
		}
		size := len(list)
		return func(c *Ctx, r *Random, x, y, z int) int {
			if size == 0 {
				return -1
			}
			return c.B.DefaultState[list[r.NextBoundedInt(size)]]
		}, nil
	case "rule_based":
		var fallback StateProvider
		if o["fallback"] != nil {
			if fallback, err = reg.ParseStateProvider(o["fallback"]); err != nil {
				return nil, err
			}
		}
		list, err := jArray(o, "rules")
		if err != nil {
			return nil, err
		}
		type rule struct {
			ifTrue BlockPredicate
			then   StateProvider
		}
		var rules []rule
		for _, e := range list {
			eo, ok := asObj(e)
			if !ok {
				return nil, fmt.Errorf("invalid rule")
			}
			p, err := parseBlockPredicate(reg, eo["if_true"])
			if err != nil {
				return nil, err
			}
			then, err := reg.ParseStateProvider(eo["then"])
			if err != nil {
				return nil, err
			}
			rules = append(rules, rule{p, then})
		}
		return func(c *Ctx, r *Random, x, y, z int) int {
			for _, rl := range rules {
				if rl.ifTrue(c, x, y, z) {
					if state := rl.then(c, r, x, y, z); state >= 0 {
						return state
					}
				}
			}
			if fallback != nil {
				return fallback(c, r, x, y, z)
			}
			return -1
		}, nil
	case "noise", "noise_threshold", "dual_noise":
		return reg.noiseStateProvider(t, o)
	}
	return nil, fmt.Errorf("unknown block state provider type %s", t)
}

func (reg *Registry) noiseStateProvider(t string, o obj) (StateProvider, error) {
	b := reg.B
	seed, err := jInt(o, "seed")
	if err != nil {
		return nil, err
	}
	scaleF, err := jFloat(o, "scale")
	if err != nil {
		return nil, err
	}
	scale := fround(scaleF)
	noiseJSON, ok := asObj(o["noise"])
	if !ok {
		return nil, fmt.Errorf("invalid noise parameters")
	}
	noise, err := newFloatNoise(noiseJSON, int64(seed))
	if err != nil {
		return nil, err
	}
	noiseValue := func(x, y, z int) float64 {
		return noise.get(float64(float64(x)*scale), float64(float64(y)*scale), float64(float64(z)*scale))
	}
	pickFromList := func(states []int, value float64) int {
		placement := fround(fround(1.0+value) / 2.0)
		if placement < 0.0 {
			placement = 0.0
		} else if placement > 0.9998999834060669 {
			placement = 0.9998999834060669
		}
		return states[phpIntOfFloat(fround(float64(placement*float64(len(states)))))]
	}
	stateList := func(key string) ([]int, error) {
		list, err := jArray(o, key)
		if err != nil {
			return nil, err
		}
		var out []int
		for _, s := range list {
			st, err := b.StateFromJSON(s)
			if err != nil {
				return nil, err
			}
			out = append(out, st)
		}
		return out, nil
	}
	if t == "noise_threshold" {
		th, err1 := jFloat(o, "threshold")
		hc, err2 := jFloat(o, "high_chance")
		if err := firstErr(err1, err2); err != nil {
			return nil, err
		}
		threshold, highChance := fround(th), fround(hc)
		def, err := b.StateFromJSON(o["default_state"])
		if err != nil {
			return nil, err
		}
		low, err := stateList("low_states")
		if err != nil {
			return nil, err
		}
		high, err := stateList("high_states")
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, r *Random, x, y, z int) int {
			value := noiseValue(x, y, z)
			if value < threshold {
				return low[r.NextBoundedInt(len(low))]
			}
			if r.NextFloat() < highChance {
				return high[r.NextBoundedInt(len(high))]
			}
			return def
		}, nil
	}
	states, err := stateList("states")
	if err != nil {
		return nil, err
	}
	if t == "noise" {
		return func(c *Ctx, r *Random, x, y, z int) int { return pickFromList(states, noiseValue(x, y, z)) }, nil
	}
	//dual_noise
	variety, err := jArray(o, "variety")
	if err != nil {
		return nil, err
	}
	varietyMin := 1
	if len(variety) > 0 {
		if v, ok := isInt(variety[0]); ok {
			varietyMin = v
		}
	}
	varietyMax := varietyMin
	if len(variety) > 1 {
		if v, ok := isInt(variety[1]); ok {
			varietyMax = v
		}
	}
	ss, err := jFloat(o, "slow_scale")
	if err != nil {
		return nil, err
	}
	slowScale := fround(ss)
	slowJSON, ok := asObj(o["slow_noise"])
	if !ok {
		return nil, fmt.Errorf("invalid noise parameters")
	}
	slow, err := newFloatNoise(slowJSON, int64(seed))
	if err != nil {
		return nil, err
	}
	slowValue := func(x, y, z int) float64 {
		return slow.get(fround(float64(float64(x)*slowScale)), fround(float64(float64(y)*slowScale)), fround(float64(float64(z)*slowScale)))
	}
	return func(c *Ctx, r *Random, x, y, z int) int {
		varietyNoise := slowValue(x, y, z)
		//Mth.clampedMap(varietyNoise, -1, 1, min, max + 1)
		tt := (varietyNoise - -1.0) / (1.0 - -1.0)
		var mapped float64
		if tt < 0.0 {
			mapped = float64(varietyMin)
		} else if tt > 1.0 {
			mapped = float64(varietyMax + 1)
		} else {
			mapped = float64(varietyMin) + float64(tt*float64((varietyMax+1)-varietyMin))
		}
		localVariety := phpIntOfFloat(mapped)
		possible := make([]int, 0, localVariety)
		for i := 0; i < localVariety; i++ {
			possible = append(possible, pickFromList(states, slowValue(x+i*54545, y, z+i*34234)))
		}
		return pickFromList(possible, noiseValue(x, y, z))
	}, nil
}

// floatNoise is feature/FloatNoise.php: 26.x NormalNoise with float32 interpolation.
type floatNoise struct {
	noises      []*worldgen.ImprovedNoise
	frequencies []float64
	amplitudes  []float64
}

func newFloatNoise(js map[string]any, seed int64) (*floatNoise, error) {
	params, err := worldgen.NoiseParameters(js)
	if err != nil {
		return nil, err
	}
	factor := params.NormalizationFactor()
	random := worldgen.NewLegacySource(seed)
	first := random.ForkPositional()
	second := random.ForkPositional()
	n := &floatNoise{}
	indexes, frequencies, amplitudes := params.OctaveList()
	for i, index := range indexes {
		name := "octave_" + strconv.Itoa(index)
		valueFactor := fround(float64(factor * amplitudes[i]))
		n.noises = append(n.noises, worldgen.NewImprovedNoise(first.FromHashOf(name)))
		n.frequencies = append(n.frequencies, frequencies[i])
		n.amplitudes = append(n.amplitudes, valueFactor)
		n.noises = append(n.noises, worldgen.NewImprovedNoise(second.FromHashOf(name)))
		n.frequencies = append(n.frequencies, float64(frequencies[i]*1.0181268882175227))
		n.amplitudes = append(n.amplitudes, valueFactor)
	}
	return n, nil
}

func (n *floatNoise) get(x, y, z float64) float64 {
	value := 0.0
	for i, noise := range n.noises {
		f := n.frequencies[i]
		value = fround(value + fround(float64(n.amplitudes[i]*floatPerlin(noise, float64(x*f), float64(y*f), float64(z*f)))))
	}
	return value
}

func floatWrap(x float64) float64 {
	if x < -16777215.999999998 || x >= 16777215.999999998 {
		return x - float64(math.Floor(x/33554432.0+0.5)*33554432.0)
	}
	return x
}

var (
	gradXi = [16]float64{1, -1, 1, -1, 1, -1, 1, -1, 0, 0, 0, 0, 1, 0, -1, 0}
	gradYi = [16]float64{1, 1, -1, -1, 0, 0, 0, 0, 1, -1, 1, -1, 1, -1, 1, -1}
	gradZi = [16]float64{0, 0, 0, 0, 1, 1, -1, -1, 1, 1, -1, -1, 0, 1, 0, -1}
)

func floatGrad(hash int, x, y, z float64) float64 {
	g := hash & 15
	return fround(fround(float64(gradXi[g]*x)+float64(gradYi[g]*y)) + float64(gradZi[g]*z))
}

func floatSmoothstep(x float64) float64 {
	x3 := fround(float64(fround(float64(x*x)) * x))
	inner := fround(fround(float64(x*fround(fround(float64(x*6.0))-15.0))) + 10.0)
	return fround(float64(x3 * inner))
}

func floatLerp(alpha, a, b float64) float64 {
	return fround(a + fround(float64(alpha*fround(b-a))))
}

func floatPerlin(n *worldgen.ImprovedNoise, x, y, z float64) float64 {
	xo, yo, zo := n.Offsets()
	x = floatWrap(x) + xo
	y = floatWrap(y) + yo
	z = floatWrap(z) + zo
	fx := phpIntOfFloat(math.Floor(x))
	fy := phpIntOfFloat(math.Floor(y))
	fz := phpIntOfFloat(math.Floor(z))
	rx := fround(x - float64(fx))
	ry := fround(y - float64(fy))
	rz := fround(z - float64(fz))
	x0 := n.Perm(fx)
	x1 := n.Perm(fx + 1)
	xy00 := n.Perm(x0 + fy)
	xy01 := n.Perm(x0 + fy + 1)
	xy10 := n.Perm(x1 + fy)
	xy11 := n.Perm(x1 + fy + 1)
	rx1 := fround(rx - 1.0)
	ry1 := fround(ry - 1.0)
	rz1 := fround(rz - 1.0)
	d000 := floatGrad(n.Perm(xy00+fz), rx, ry, rz)
	d100 := floatGrad(n.Perm(xy10+fz), rx1, ry, rz)
	d010 := floatGrad(n.Perm(xy01+fz), rx, ry1, rz)
	d110 := floatGrad(n.Perm(xy11+fz), rx1, ry1, rz)
	d001 := floatGrad(n.Perm(xy00+fz+1), rx, ry, rz1)
	d101 := floatGrad(n.Perm(xy10+fz+1), rx1, ry, rz1)
	d011 := floatGrad(n.Perm(xy01+fz+1), rx, ry1, rz1)
	d111 := floatGrad(n.Perm(xy11+fz+1), rx1, ry1, rz1)
	ax := floatSmoothstep(rx)
	ay := floatSmoothstep(ry)
	az := floatSmoothstep(rz)
	return floatLerp(az,
		floatLerp(ay, floatLerp(ax, d000, d100), floatLerp(ax, d010, d110)),
		floatLerp(ay, floatLerp(ax, d001, d101), floatLerp(ax, d011, d111)))
}
