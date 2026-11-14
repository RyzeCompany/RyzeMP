package decor

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
)

// JSON helpers with WorldgenData's semantics (the data is decoded with json.Number: integers stay integers, like
// PHP's json_decode()). A key holding null counts as missing.

type obj = map[string]any

func stripNamespace(id string) string { return strings.TrimPrefix(id, "minecraft:") }

func isInt(v any) (int, bool) {
	n, ok := v.(json.Number)
	if !ok || !isJSONIntNumber(n) {
		return 0, false
	}
	i, err := strconv.ParseInt(string(n), 10, 64)
	if err != nil {
		return 0, false
	}
	return int(i), true
}

func isNumber(v any) (float64, bool) {
	n, ok := v.(json.Number)
	if !ok {
		return 0, false
	}
	f, err := strconv.ParseFloat(string(n), 64)
	if err != nil {
		return 0, false
	}
	return f, true
}

func jInt(o obj, key string) (int, error) {
	if v, ok := isInt(o[key]); ok {
		return v, nil
	}
	return 0, fmt.Errorf("expected integer for %q", key)
}

func jIntDef(o obj, key string, def int) (int, error) {
	if o[key] == nil {
		return def, nil
	}
	return jInt(o, key)
}

func jFloat(o obj, key string) (float64, error) {
	if v, ok := isNumber(o[key]); ok {
		return v, nil
	}
	return 0, fmt.Errorf("expected number for %q", key)
}

func jFloatDef(o obj, key string, def float64) (float64, error) {
	if o[key] == nil {
		return def, nil
	}
	return jFloat(o, key)
}

func jString(o obj, key string) (string, error) {
	if v, ok := o[key].(string); ok {
		return v, nil
	}
	return "", fmt.Errorf("expected string for %q", key)
}

func jStringDef(o obj, key, def string) (string, error) {
	if o[key] == nil {
		return def, nil
	}
	return jString(o, key)
}

// jArray is WorldgenData::array(): a JSON list (objects are iterated as their values, PHP arrays being both).
func jArray(o obj, key string) ([]any, error) {
	switch v := o[key].(type) {
	case []any:
		return v, nil
	case map[string]any:
		return objectValues(v), nil
	}
	return nil, fmt.Errorf("expected array for %q", key)
}

func objectValues(m map[string]any) []any {
	// only used for malformed data: PHP keeps document order, which json.Decoder into a map loses
	out := make([]any, 0, len(m))
	for _, v := range m {
		out = append(out, v)
	}
	return out
}

func asObj(v any) (obj, bool) {
	o, ok := v.(map[string]any)
	return o, ok
}

// jBool is ($json[key] ?? def) === true.
func jBoolDef(o obj, key string, def bool) bool {
	v, present := o[key]
	if !present || v == nil {
		return def
	}
	b, ok := v.(bool)
	return ok && b
}

// vec3 parses a Vec3i ([x, y, z]), defaulting to 0 0 0.
func vec3(v any) [3]int {
	l, ok := v.([]any)
	if ok && len(l) == 3 {
		x, ok1 := isInt(l[0])
		y, ok2 := isInt(l[1])
		z, ok3 := isInt(l[2])
		if ok1 && ok2 && ok3 {
			return [3]int{x, y, z}
		}
	}
	return [3]int{}
}

func typeOf(o obj, key string) (string, error) {
	t, err := jString(o, key)
	if err != nil {
		return "", err
	}
	return stripNamespace(t), nil
}

func errExpected(key string) error { return fmt.Errorf("expected array for %q", key) }
