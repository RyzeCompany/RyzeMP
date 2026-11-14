package worldgen

import (
	"fmt"
	"math"
)

// ClimateBiomeTree is the multi-noise biome lookup (biome/ClimateBiomeTree.php). It remembers the last leaf
// found (like vanilla and the PHP port), which decides exact ties: use one tree per generator session.
type ClimateBiomeTree struct {
	bounds    []int
	children  [][]int
	leafBiome []int
	names     []string
	lastLeaf  int
}

func NewClimateBiomeTree(data *Data, dimension string) (*ClimateBiomeTree, error) {
	all, err := data.BiomeTree()
	if err != nil {
		return nil, err
	}
	root, ok := all[dimension].(map[string]any)
	if !ok {
		return nil, fmt.Errorf("no biome tree for %s", dimension)
	}
	t := &ClimateBiomeTree{lastLeaf: -1}
	names := map[string]int{}
	if _, err := t.add(root, names); err != nil {
		return nil, err
	}
	return t, nil
}

// NewSingleBiomeTree is a tree with one biome that every search returns (ClimateBiomeTree::single()), for
// dimensions without a multi-noise biome source.
func NewSingleBiomeTree(biome string) *ClimateBiomeTree {
	t := &ClimateBiomeTree{lastLeaf: -1}
	t.bounds = make([]int, 14)
	t.children = [][]int{nil}
	t.leafBiome = []int{0}
	t.names = []string{biome}
	return t
}

// SingleBiome returns the biome index of a single-biome tree, -1 otherwise.
func (t *ClimateBiomeTree) SingleBiome() int {
	if t.leafBiome[0] >= 0 {
		return t.leafBiome[0]
	}
	return -1
}

// Clone returns a tree sharing the immutable node data with a fresh last-leaf state.
func (t *ClimateBiomeTree) Clone() *ClimateBiomeTree {
	c := *t
	c.lastLeaf = -1
	return &c
}

func (t *ClimateBiomeTree) add(node map[string]any, names map[string]int) (int, error) {
	index := len(t.leafBiome)
	params, err := jsonArray(node, "parameters")
	if err != nil {
		return 0, err
	}
	if len(params) != 7 {
		return 0, fmt.Errorf("climate nodes must have 7 parameters")
	}
	for _, p := range params {
		m, ok := p.(map[string]any)
		if !ok {
			return 0, fmt.Errorf("invalid climate parameter")
		}
		lo, e1 := jsonInt(m, "min", nil)
		hi, e2 := jsonInt(m, "max", nil)
		if err := firstErr(e1, e2); err != nil {
			return 0, err
		}
		t.bounds = append(t.bounds, lo, hi)
	}
	t.children = append(t.children, nil)
	typ, err := jsonString(node, "_type", nil)
	if err != nil {
		return 0, err
	}
	if typ == "leaf" {
		biome, err := jsonString(node, "biome", nil)
		if err != nil {
			return 0, err
		}
		id, ok := names[biome]
		if !ok {
			id = len(t.names)
			names[biome] = id
			t.names = append(t.names, biome)
		}
		t.leafBiome = append(t.leafBiome, id)
		return index, nil
	}
	t.leafBiome = append(t.leafBiome, -1)
	sub, err := jsonArray(node, "subTree")
	if err != nil {
		return 0, err
	}
	var children []int
	for _, c := range sub {
		m, ok := c.(map[string]any)
		if !ok {
			return 0, fmt.Errorf("invalid climate tree node")
		}
		ci, err := t.add(m, names)
		if err != nil {
			return 0, err
		}
		children = append(children, ci)
	}
	t.children[index] = children
	return index, nil
}

func (t *ClimateBiomeTree) Names() []string { return t.names }

// Quantize is vanilla's (long) ((float) value * 10000.0F), as computed by the PHP port.
func Quantize(value float64) int {
	f := fround(value)
	return phpInt(fround(float64(f * 10000.0)))
}

func dist1(target, lo, hi int) int {
	d := target - hi
	if d > 0 {
		return d * d
	}
	d = lo - target
	if d > 0 {
		return d * d
	}
	return 0
}

func (t *ClimateBiomeTree) distance(node int, p *[6]int) int {
	b := t.bounds[node*14 : node*14+14]
	sum := dist1(p[0], b[0], b[1])
	sum += dist1(p[1], b[2], b[3])
	sum += dist1(p[2], b[4], b[5])
	sum += dist1(p[3], b[6], b[7])
	sum += dist1(p[4], b[8], b[9])
	sum += dist1(p[5], b[10], b[11])
	sum += dist1(0, b[12], b[13])
	return sum
}

// Search returns the biome index (see Names()) closest to the quantized target.
func (t *ClimateBiomeTree) Search(temperature, humidity, continentalness, erosion, depth, weirdness int) int {
	if t.leafBiome[0] >= 0 {
		return t.leafBiome[0]
	}
	p := [6]int{temperature, humidity, continentalness, erosion, depth, weirdness}
	last := t.lastLeaf
	best := math.MaxInt64
	if last >= 0 {
		best = t.distance(last, &p)
	}
	leaf := t.searchNode(0, &p, last, best)
	t.lastLeaf = leaf
	return t.leafBiome[leaf]
}

func (t *ClimateBiomeTree) searchNode(node int, p *[6]int, leaf, best int) int {
	for _, child := range t.children[node] {
		b := t.bounds[child*14 : child*14+14]
		sum := dist1(p[0], b[0], b[1])
		if sum >= best {
			continue
		}
		sum += dist1(p[1], b[2], b[3])
		sum += dist1(p[2], b[4], b[5])
		sum += dist1(p[3], b[6], b[7])
		if sum >= best {
			continue
		}
		sum += dist1(p[4], b[8], b[9])
		sum += dist1(p[5], b[10], b[11])
		sum += dist1(0, b[12], b[13])
		if sum >= best {
			continue
		}
		if t.leafBiome[child] >= 0 {
			best = sum
			leaf = child
		} else {
			found := t.searchNode(child, p, leaf, best)
			if found != leaf {
				best = t.distance(found, p)
				leaf = found
			}
		}
	}
	return leaf
}

// SearchIsolated is Search without the last-result hint and without changing it (CarverSystem start biomes): the
// result never depends on earlier searches.
func (t *ClimateBiomeTree) SearchIsolated(temperature, humidity, continentalness, erosion, depth, weirdness int) int {
	if t.leafBiome[0] >= 0 {
		return t.leafBiome[0]
	}
	p := [6]int{temperature, humidity, continentalness, erosion, depth, weirdness}
	return t.leafBiome[t.searchNode(0, &p, -1, math.MaxInt64)]
}
