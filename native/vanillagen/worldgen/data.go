package worldgen

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
)

// Data gives read-only access to the vanilla worldgen data directory (WorldgenData.php): the same files the PHP
// generator reads. Decoded files are cached; Data is safe for concurrent use.
type Data struct {
	root  string
	mu    sync.Mutex
	cache map[string]any
	// digest per file read (for the fingerprint): size and SHA-256 of the contents
	digest map[string]fileDigest
}

type fileDigest struct {
	size int
	sum  [sha256.Size]byte
}

func OpenData(root string) (*Data, error) {
	st, err := os.Stat(filepath.Join(root, "data", "minecraft", "worldgen"))
	if err != nil || !st.IsDir() {
		return nil, fmt.Errorf("vanilla worldgen data not found in %s", root)
	}
	return &Data{root: root, cache: map[string]any{}, digest: map[string]fileDigest{}}, nil
}

func (d *Data) Root() string { return d.root }

func stripNamespace(id string) string {
	return strings.TrimPrefix(id, "minecraft:")
}

func (d *Data) read(rel string) (any, error) {
	return d.readCached(rel, true)
}

// readCached decodes a file, keeping the decoded value for later reads if keep is set. Files only needed once (the
// biome tree, ~25 MB decoded, from which each dimension builds its own ClimateBiomeTree) are not kept.
func (d *Data) readCached(rel string, keep bool) (any, error) {
	d.mu.Lock()
	defer d.mu.Unlock()
	if v, ok := d.cache[rel]; ok {
		return v, nil
	}
	path := filepath.Join(d.root, filepath.FromSlash(rel))
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("missing vanilla data file %s", path)
	}
	dec := json.NewDecoder(bytes.NewReader(b))
	dec.UseNumber()
	var v any
	if err := dec.Decode(&v); err != nil {
		return nil, fmt.Errorf("invalid JSON in %s: %w", path, err)
	}
	if keep {
		d.cache[rel] = v
	}
	d.digest[rel] = fileDigest{len(b), sha256.Sum256(b)}
	return v, nil
}

func (d *Data) readObject(rel string) (map[string]any, error) {
	return d.readObjectCached(rel, true)
}

func (d *Data) readObjectCached(rel string, keep bool) (map[string]any, error) {
	v, err := d.readCached(rel, keep)
	if err != nil {
		return nil, err
	}
	m, ok := v.(map[string]any)
	if !ok {
		return nil, fmt.Errorf("expected a JSON object in %s", rel)
	}
	return m, nil
}

func (d *Data) DensityFunction(id string) (any, error) {
	return d.read("data/minecraft/worldgen/density_function/" + stripNamespace(id) + ".json")
}

func (d *Data) Noise(id string) (map[string]any, error) {
	return d.readObject("data/minecraft/worldgen/noise/" + stripNamespace(id) + ".json")
}

func (d *Data) NoiseSettings(id string) (map[string]any, error) {
	return d.readObject("data/minecraft/worldgen/noise_settings/" + stripNamespace(id) + ".json")
}

func (d *Data) BiomeTree() (map[string]any, error) {
	return d.readObjectCached("multi_noise_biome_tree.json", false)
}

// Fingerprint is a SHA-256 over the names, sizes and SHA-256 digests of every file read so far (informational).
func (d *Data) Fingerprint() string {
	d.mu.Lock()
	defer d.mu.Unlock()
	names := make([]string, 0, len(d.digest))
	for k := range d.digest {
		names = append(names, k)
	}
	sort.Strings(names)
	h := sha256.New()
	for _, n := range names {
		fd := d.digest[n]
		fmt.Fprintf(h, "%s\x00%d\x00", n, fd.size)
		h.Write(fd.sum[:])
	}
	return hex.EncodeToString(h.Sum(nil))
}

// ---- typed JSON accessors (same rules as WorldgenData::float/int/string/array) ----

func jsonFloat(obj map[string]any, key string, def *float64) (float64, error) {
	v, ok := obj[key]
	if !ok || v == nil {
		if def != nil {
			return *def, nil
		}
		return 0, fmt.Errorf("expected number for %q", key)
	}
	n, ok := v.(json.Number)
	if !ok {
		return 0, fmt.Errorf("expected number for %q", key)
	}
	return numberToFloat(n)
}

func numberToFloat(n json.Number) (float64, error) {
	return n.Float64()
}

func isJSONInt(n json.Number) bool {
	return !strings.ContainsAny(string(n), ".eE")
}

func jsonInt(obj map[string]any, key string, def *int) (int, error) {
	v, ok := obj[key]
	if !ok || v == nil {
		if def != nil {
			return *def, nil
		}
		return 0, fmt.Errorf("expected integer for %q", key)
	}
	n, ok := v.(json.Number)
	if !ok || !isJSONInt(n) {
		return 0, fmt.Errorf("expected integer for %q", key)
	}
	i, err := n.Int64()
	if err != nil {
		return 0, fmt.Errorf("expected integer for %q", key)
	}
	return int(i), nil
}

func jsonString(obj map[string]any, key string, def *string) (string, error) {
	v, ok := obj[key]
	if !ok || v == nil {
		if def != nil {
			return *def, nil
		}
		return "", fmt.Errorf("expected string for %q", key)
	}
	s, ok := v.(string)
	if !ok {
		return "", fmt.Errorf("expected string for %q", key)
	}
	return s, nil
}

func jsonArray(obj map[string]any, key string) ([]any, error) {
	switch v := obj[key].(type) {
	case []any:
		return v, nil
	case map[string]any:
		// PHP decodes objects and lists alike; only lists are expected here
		return nil, fmt.Errorf("expected list for %q", key)
	}
	return nil, fmt.Errorf("expected array for %q", key)
}

func jsonFloatList(obj map[string]any, key string) ([]float64, error) {
	arr, err := jsonArray(obj, key)
	if err != nil {
		return nil, err
	}
	out := make([]float64, 0, len(arr))
	for _, v := range arr {
		n, ok := v.(json.Number)
		if !ok {
			return nil, fmt.Errorf("expected number list for %q", key)
		}
		f, err := numberToFloat(n)
		if err != nil {
			return nil, err
		}
		out = append(out, f)
	}
	return out, nil
}

func fptr(f float64) *float64 { return &f }
func iptr(i int) *int         { return &i }
func sptr(s string) *string   { return &s }

func (d *Data) MaterialRule(id string) (any, error) {
	return d.read("data/minecraft/worldgen/material_rule/" + stripNamespace(id) + ".json")
}

func (d *Data) MaterialCondition(id string) (any, error) {
	return d.read("data/minecraft/worldgen/material_condition/" + stripNamespace(id) + ".json")
}

func (d *Data) Biome(id string) (map[string]any, error) {
	return d.readObject("data/minecraft/worldgen/biome/" + stripNamespace(id) + ".json")
}

// HasCarvers reports whether the cache has the carver definitions (worldgen/carver, 26.3+).
func (d *Data) HasCarvers() bool {
	st, err := os.Stat(filepath.Join(d.root, "data", "minecraft", "worldgen", "carver"))
	return err == nil && st.IsDir()
}

func (d *Data) Carver(id string) (map[string]any, error) {
	return d.readObject("data/minecraft/worldgen/carver/" + stripNamespace(id) + ".json")
}

// BlockTag returns the block ids of a block tag with nested tags resolved, or nil if the cache does not have it
// (WorldgenData::blockTag).
func (d *Data) BlockTag(id string, depth int) ([]string, error) {
	rel := "data/minecraft/tags/block/" + stripNamespace(id) + ".json"
	if _, err := os.Stat(filepath.Join(d.root, filepath.FromSlash(rel))); err != nil {
		return nil, nil
	}
	if depth > 16 {
		return nil, fmt.Errorf("block tag %s is nested too deeply", id)
	}
	obj, err := d.readObject(rel)
	if err != nil {
		return nil, err
	}
	values, err := jsonArray(obj, "values")
	if err != nil {
		return nil, err
	}
	blocks := []string{}
	for _, v := range values {
		required := true
		if m, ok := v.(map[string]any); ok {
			if r, ok := m["required"].(bool); ok {
				required = r
			}
			v = m["id"]
		}
		s, ok := v.(string)
		if !ok {
			return nil, fmt.Errorf("invalid entry in block tag %s", id)
		}
		if strings.HasPrefix(s, "#") {
			nested, err := d.BlockTag(s[1:], depth+1)
			if err != nil {
				return nil, err
			}
			if nested == nil && required {
				return nil, fmt.Errorf("block tag %s references the missing tag %s", id, s)
			}
			blocks = append(blocks, nested...)
		} else {
			blocks = append(blocks, "minecraft:"+stripNamespace(s))
		}
	}
	return blocks, nil
}

// ReadObject reads (and caches) a JSON object of the data directory, by path relative to it.
func (d *Data) ReadObject(rel string) (map[string]any, error) { return d.readObject(rel) }

// Exists tells whether a file of the data directory exists.
func (d *Data) Exists(rel string) bool {
	st, err := os.Stat(filepath.Join(d.root, filepath.FromSlash(rel)))
	return err == nil && !st.IsDir()
}
