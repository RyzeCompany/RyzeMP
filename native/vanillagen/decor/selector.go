package decor

import "fmt"

const (
	selRandom = iota
	selSimpleRandom
	selWeighted
	selBoolean
	selSequence
)

// selectorFeature is type/SelectorFeature.php.
type selectorFeature struct {
	mode        int
	features    []*PlacedFeature
	chances     []float64
	weights     []int
	totalWeight int
	def         *PlacedFeature
}

func newSelector(reg *Registry, mode int, json obj) (Feature, error) {
	f := &selectorFeature{mode: mode}
	switch mode {
	case selRandom:
		list, err := jArray(json, "features")
		if err != nil {
			return nil, err
		}
		for _, e := range list {
			eo, ok := asObj(e)
			if !ok {
				return nil, fmt.Errorf("invalid random_selector entry")
			}
			p, err := reg.PlacedRef(eo["feature"])
			if err != nil {
				return nil, err
			}
			chance, err := jFloat(eo, "chance")
			if err != nil {
				return nil, err
			}
			f.features = append(f.features, p)
			f.chances = append(f.chances, fround(chance))
		}
		def, err := reg.PlacedRef(json["default"])
		if err != nil {
			return nil, err
		}
		f.def = def
	case selSimpleRandom, selSequence:
		list, ok := json["features"].([]any)
		if !ok {
			list = []any{json["features"]}
		}
		for _, e := range list {
			p, err := reg.PlacedRef(e)
			if err != nil {
				return nil, err
			}
			f.features = append(f.features, p)
		}
	case selWeighted:
		list, ok := json["features"].([]any)
		if !ok {
			return nil, fmt.Errorf("invalid weighted list")
		}
		for _, e := range list {
			eo, ok := asObj(e)
			if !ok {
				return nil, fmt.Errorf("invalid weighted list entry")
			}
			p, err := reg.PlacedRef(eo["data"])
			if err != nil {
				return nil, err
			}
			w, err := jInt(eo, "weight")
			if err != nil {
				return nil, err
			}
			f.features = append(f.features, p)
			f.weights = append(f.weights, w)
			f.totalWeight += w
		}
	case selBoolean:
		t, err := reg.PlacedRef(json["feature_true"])
		if err != nil {
			return nil, err
		}
		fl, err := reg.PlacedRef(json["feature_false"])
		if err != nil {
			return nil, err
		}
		f.features = []*PlacedFeature{t, fl}
	}
	return f, nil
}

func (f *selectorFeature) Place(c *Ctx, x, y, z int) bool {
	r := c.R
	switch f.mode {
	case selRandom:
		for i, feature := range f.features {
			if r.NextFloat() < f.chances[i] {
				return feature.Place(c, x, y, z)
			}
		}
		return f.def != nil && f.def.Place(c, x, y, z)
	case selSimpleRandom:
		return f.features[r.NextBoundedInt(len(f.features))].Place(c, x, y, z)
	case selWeighted:
		if f.totalWeight <= 0 {
			return false
		}
		return f.features[pickWeighted(r, f.weights, f.totalWeight)].Place(c, x, y, z)
	case selBoolean:
		if r.NextBoolean() {
			return f.features[0].Place(c, x, y, z)
		}
		return f.features[1].Place(c, x, y, z)
	}
	for _, feature := range f.features {
		if !feature.Place(c, x, y, z) {
			return false
		}
	}
	return true
}
