package decor

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
)

// Fluid codes (JavaBlocks::FLUID_*), bit 4 set for source fluids.
const (
	FluidNone  = 0
	FluidWater = 1
	FluidLava  = 2
)

// Directions in Java's ordinal order (block/Direction.php).
const (
	Down  = 0
	Up    = 1
	North = 2
	South = 3
	West  = 4
	East  = 5
)

var (
	StepX    = [6]int{0, 0, 0, 0, -1, 1}
	StepY    = [6]int{-1, 1, 0, 0, 0, 0}
	StepZ    = [6]int{0, 0, -1, 1, 0, 0}
	Opposite = [6]int{1, 0, 3, 2, 5, 4}
	DirNames = [6]string{"down", "up", "north", "south", "west", "east"}
	// Horizontal directions in 2D data value order: S, W, N, E.
	Horizontal = [4]int{South, West, North, East}
	// Direction.Plane.HORIZONTAL iteration order: N, E, S, W.
	PlaneHorizontal = [4]int{North, East, South, West}
	// Axis per direction: 0 = x, 1 = y, 2 = z.
	DirAxis = [6]int{1, 1, 2, 2, 0, 0}
)

func directionFromName(name string) (int, error) {
	for i, n := range DirNames {
		if n == name {
			return i, nil
		}
	}
	return 0, fmt.Errorf("unknown direction %s", name)
}

func clockWise(d int) int {
	switch d {
	case North:
		return East
	case East:
		return South
	case South:
		return West
	case West:
		return North
	}
	panic("not a horizontal direction")
}

func counterClockWise(d int) int {
	switch d {
	case North:
		return West
	case West:
		return South
	case South:
		return East
	case East:
		return North
	}
	panic("not a horizontal direction")
}

type box struct{ x0, y0, z0, x1, y1, z1 float64 }

// Blocks is the Java block state model of feature/block/JavaBlocks.php (ids, properties, tags and the physical
// properties the features use). Shared by all sessions of a data directory; read-only after loading except for the
// lazily resolved tags, which are guarded by mu.
type Blocks struct {
	Names        []string
	blockByName  map[string]int
	FirstState   []int
	DefaultState []int
	propNames    [][]string
	propValues   [][][]string
	strides      [][]int
	explicit     map[int]map[int][]int // block => state => value indexes
	BlockOf      []int32

	mu        sync.Mutex
	tagMasks  sync.Map // name => []bool (TagMask)
	tagOrders map[string][]int
	tags      map[string]map[int]bool
	rawTags   map[string][]string
	fluidTag  map[string]map[string]bool

	collisionIndex []int32
	shapes         [][]box

	// precomputed per state (read-only, shared by the sessions)
	solid    []bool
	sturdy   []bool // per state*6+face
	fullCube []bool
	fluid    []uint8
	opacity  [4][]bool // heightmap opacity (region.go), built on first use

	Air, CaveAir, VoidAir, Water, Lava                          int
	AirBlock, CaveAirBlock, VoidAirBlock, WaterBlock, LavaBlock int
	bedrockBlock                                                int
}

var (
	blocksMu    sync.Mutex
	blocksCache = map[string]*Blocks{}
)

// LoadBlocks loads (once per directory) the Java block data of a vanilla worldgen data directory.
func LoadBlocks(dir string) (*Blocks, error) {
	blocksMu.Lock()
	defer blocksMu.Unlock()
	if b, ok := blocksCache[dir]; ok {
		return b, nil
	}
	b, err := loadBlocks(dir)
	if err != nil {
		return nil, err
	}
	blocksCache[dir] = b
	return b, nil
}

func loadBlocks(dir string) (*Blocks, error) {
	raw, err := os.ReadFile(filepath.Join(dir, "java_blocks.json"))
	if err != nil {
		return nil, err
	}
	var file struct {
		Blocks []json.RawMessage `json:"blocks"`
	}
	if err := json.Unmarshal(raw, &file); err != nil {
		return nil, fmt.Errorf("invalid java_blocks.json: %w", err)
	}
	b := &Blocks{blockByName: map[string]int{}, explicit: map[int]map[int][]int{}, tags: map[string]map[int]bool{}, rawTags: map[string][]string{}, fluidTag: map[string]map[string]bool{}}
	maxState := 0
	for index, entryRaw := range file.Blocks {
		var entry []json.RawMessage
		if err := json.Unmarshal(entryRaw, &entry); err != nil || len(entry) < 4 {
			return nil, fmt.Errorf("invalid block entry in java_blocks.json")
		}
		var name string
		var first, def int
		var props [][]json.RawMessage
		if json.Unmarshal(entry[0], &name) != nil || json.Unmarshal(entry[1], &first) != nil || json.Unmarshal(entry[2], &def) != nil || json.Unmarshal(entry[3], &props) != nil {
			return nil, fmt.Errorf("invalid block entry in java_blocks.json")
		}
		b.Names = append(b.Names, name)
		b.blockByName[name] = index
		b.FirstState = append(b.FirstState, first)
		b.DefaultState = append(b.DefaultState, def)
		var names []string
		var values [][]string
		for _, p := range props {
			var pn string
			var pv []string
			if len(p) < 2 || json.Unmarshal(p[0], &pn) != nil || json.Unmarshal(p[1], &pv) != nil {
				return nil, fmt.Errorf("invalid property of %s", name)
			}
			names = append(names, pn)
			values = append(values, pv)
		}
		strides := make([]int, len(values))
		stride := 1
		for i := len(values) - 1; i >= 0; i-- {
			strides[i] = stride
			stride *= len(values[i])
		}
		b.propNames = append(b.propNames, names)
		b.propValues = append(b.propValues, values)
		b.strides = append(b.strides, strides)
		if len(entry) > 4 {
			var states []json.RawMessage
			// a JSON list, or an object keyed by index (PHP's promoteKeys accepts both)
			if json.Unmarshal(entry[4], &states) != nil {
				var obj map[string]json.RawMessage
				if json.Unmarshal(entry[4], &obj) != nil {
					return nil, fmt.Errorf("invalid explicit states of %s", name)
				}
				keys := make([]string, 0, len(obj))
				for k := range obj {
					keys = append(keys, k)
				}
				sort.Strings(keys)
				for _, k := range keys {
					states = append(states, obj[k])
				}
			}
			ex := map[int][]int{}
			for _, s := range states {
				var pair []json.RawMessage
				var id int
				var idx []int
				if json.Unmarshal(s, &pair) != nil || len(pair) < 2 || json.Unmarshal(pair[0], &id) != nil || json.Unmarshal(pair[1], &idx) != nil {
					return nil, fmt.Errorf("invalid explicit state of %s", name)
				}
				ex[id] = idx
			}
			b.explicit[index] = ex
		}
		if m := first + stride - 1; m > maxState {
			maxState = m
		}
	}
	b.BlockOf = make([]int32, maxState+1)
	for index, first := range b.FirstState {
		count := 1
		for _, v := range b.propValues[index] {
			count *= len(v)
		}
		for s := first; s < first+count; s++ {
			b.BlockOf[s] = int32(index)
		}
	}
	idx := func(n string) int {
		i, ok := b.blockByName[n]
		if !ok {
			err = fmt.Errorf("unknown Java block %s", n)
		}
		return i
	}
	b.AirBlock = idx("minecraft:air")
	b.CaveAirBlock = idx("minecraft:cave_air")
	b.VoidAirBlock = idx("minecraft:void_air")
	b.WaterBlock = idx("minecraft:water")
	b.LavaBlock = idx("minecraft:lava")
	b.bedrockBlock = idx("minecraft:bedrock")
	if err != nil {
		return nil, err
	}
	b.Air = b.DefaultState[b.AirBlock]
	b.CaveAir = b.DefaultState[b.CaveAirBlock]
	b.VoidAir = b.DefaultState[b.VoidAirBlock]
	b.Water = b.DefaultState[b.WaterBlock]
	b.Lava = b.DefaultState[b.LavaBlock]

	if err := b.loadTags(filepath.Join(dir, "data", "minecraft", "tags", "block"), ""); err != nil {
		return nil, err
	}
	for _, ft := range []string{"water", "lava"} {
		path := filepath.Join(dir, "data", "minecraft", "tags", "fluid", ft+".json")
		if _, err := os.Stat(path); err == nil {
			values, err := readTagValues(path)
			if err != nil {
				return nil, err
			}
			set := map[string]bool{}
			for _, v := range values {
				set[v] = true
			}
			b.fluidTag["minecraft:"+ft] = set
		}
	}
	if err := b.loadCollisions(filepath.Join(dir, "geyser", "collisions.nbt")); err != nil {
		return nil, err
	}
	b.precompute()
	return b, nil
}

func (b *Blocks) loadTags(dir, prefix string) error {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return fmt.Errorf("missing block tags in %s", dir)
	}
	for _, e := range entries {
		path := filepath.Join(dir, e.Name())
		if e.IsDir() {
			if err := b.loadTags(path, prefix+e.Name()+"/"); err != nil {
				return err
			}
		} else if strings.HasSuffix(e.Name(), ".json") {
			values, err := readTagValues(path)
			if err != nil {
				return err
			}
			b.rawTags["minecraft:"+prefix+strings.TrimSuffix(e.Name(), ".json")] = values
		}
	}
	return nil
}

func readTagValues(path string) ([]string, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var file struct {
		Values []json.RawMessage `json:"values"`
	}
	if err := json.Unmarshal(raw, &file); err != nil {
		return nil, fmt.Errorf("invalid tag %s: %w", path, err)
	}
	var out []string
	for _, v := range file.Values {
		var s string
		if json.Unmarshal(v, &s) == nil {
			out = append(out, s)
			continue
		}
		var obj struct {
			ID *string `json:"id"`
		}
		if json.Unmarshal(v, &obj) == nil && obj.ID != nil {
			out = append(out, *obj.ID)
		}
	}
	return out, nil
}

func (b *Blocks) loadCollisions(path string) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	root, err := readNBT(raw)
	if err != nil {
		return fmt.Errorf("invalid Geyser collisions.nbt: %w", err)
	}
	indices, _ := root["indices"].([]any)
	for _, v := range indices {
		i, _ := v.(int64)
		b.collisionIndex = append(b.collisionIndex, int32(i))
	}
	shapes, ok := root["shapes"].([]any)
	if !ok {
		return fmt.Errorf("invalid Geyser collisions.nbt")
	}
	for _, s := range shapes {
		var boxes []box
		if list, ok := s.([]any); ok {
			for _, bx := range list {
				comps, ok := bx.([]any)
				if !ok || len(comps) != 6 {
					continue
				}
				var v [6]float64
				for i, c := range comps {
					switch n := c.(type) {
					case float64:
						v[i] = n
					case int64:
						v[i] = float64(n)
					}
				}
				//center + size
				boxes = append(boxes, box{
					v[0] - v[3]/2, v[1] - v[4]/2, v[2] - v[5]/2,
					v[0] + v[3]/2, v[1] + v[4]/2, v[2] + v[5]/2,
				})
			}
		}
		b.shapes = append(b.shapes, boxes)
	}
	return nil
}

// ---- states ----

func namespaced(name string) string {
	if strings.Contains(name, ":") {
		return name
	}
	return "minecraft:" + name
}

func (b *Blocks) HasBlock(name string) bool {
	_, ok := b.blockByName[namespaced(name)]
	return ok
}

func (b *Blocks) BlockIndex(name string) (int, error) {
	i, ok := b.blockByName[namespaced(name)]
	if !ok {
		return 0, fmt.Errorf("unknown Java block %s", name)
	}
	return i, nil
}

// MustBlock is BlockIndex for names known to exist in every supported version (panics otherwise; parse errors are
// recovered and turn the feature into a PHP-only one).
func (b *Blocks) MustBlock(name string) int {
	i, err := b.BlockIndex(name)
	if err != nil {
		panic(err)
	}
	return i
}

func (b *Blocks) DefaultOf(name string) (int, error) {
	i, err := b.BlockIndex(name)
	if err != nil {
		return 0, err
	}
	return b.DefaultState[i], nil
}

func (b *Blocks) MustDefault(name string) int { return b.DefaultState[b.MustBlock(name)] }

func (b *Blocks) BlockName(state int) string { return b.Names[b.BlockOf[state]] }

// ParseState parses "minecraft:name[prop=value,...]".
func (b *Blocks) ParseState(s string) (int, error) {
	bracket := strings.IndexByte(s, '[')
	if bracket < 0 {
		return b.DefaultOf(s)
	}
	state, err := b.DefaultOf(s[:bracket])
	if err != nil {
		return 0, err
	}
	inner := s[bracket+1 : len(s)-1]
	if inner != "" {
		for _, pair := range strings.Split(inner, ",") {
			key, value, _ := strings.Cut(pair, "=")
			state = b.With(state, key, value)
		}
	}
	return state, nil
}

// StateFromJSON reads a block state: {"Name": ..., "Properties": {...}}, {"id": ..., "properties": {...}} or a string.
func (b *Blocks) StateFromJSON(js any) (int, error) {
	if s, ok := js.(string); ok {
		return b.ParseState(s)
	}
	obj, ok := js.(map[string]any)
	if !ok {
		return 0, fmt.Errorf("invalid block state")
	}
	name, ok := obj["id"].(string)
	if !ok {
		name, ok = obj["Name"].(string)
	}
	if !ok {
		return 0, fmt.Errorf("invalid block state (no id)")
	}
	state, err := b.DefaultOf(name)
	if err != nil {
		return 0, err
	}
	props, ok := obj["properties"].(map[string]any)
	if !ok {
		props, _ = obj["Properties"].(map[string]any)
	}
	// PHP iterates the decoded JSON object in document order; property changes commute, so map order is fine
	for k, v := range props {
		switch val := v.(type) {
		case string:
			state = b.With(state, k, val)
		case json.Number:
			if isJSONIntNumber(val) {
				state = b.With(state, k, val.String())
			}
		}
	}
	return state, nil
}

func (b *Blocks) propertyIndex(block int, property string) int {
	for i, n := range b.propNames[block] {
		if n == property {
			return i
		}
	}
	return -1
}

func (b *Blocks) HasProperty(state int, property string) bool {
	return b.propertyIndex(int(b.BlockOf[state]), property) >= 0
}

// GetProperty returns the value of a property ("" and false if the block does not have it).
func (b *Blocks) GetProperty(state int, property string) (string, bool) {
	block := int(b.BlockOf[state])
	p := b.propertyIndex(block, property)
	if p < 0 {
		return "", false
	}
	if ex, ok := b.explicit[block]; ok {
		return b.propValues[block][p][ex[state][p]], true
	}
	values := b.propValues[block][p]
	return values[((state-b.FirstState[block])/b.strides[block][p])%len(values)], true
}

func (b *Blocks) GetBool(state int, property string) bool {
	v, _ := b.GetProperty(state, property)
	return v == "true"
}

// GetInt is (int) of the property value (0 if missing or not numeric, like PHP's (int) cast of a string).
func (b *Blocks) GetInt(state int, property string) int {
	v, _ := b.GetProperty(state, property)
	return phpIntOfString(v)
}

// With returns the state with a property changed (unchanged if the block does not have the property or value).
func (b *Blocks) With(state int, property, value string) int {
	block := int(b.BlockOf[state])
	p := b.propertyIndex(block, property)
	if p < 0 {
		return state
	}
	valueIndex := -1
	for i, v := range b.propValues[block][p] {
		if v == value {
			valueIndex = i
			break
		}
	}
	if valueIndex < 0 {
		return state
	}
	if ex, ok := b.explicit[block]; ok {
		indexes := append([]int(nil), ex[state]...)
		indexes[p] = valueIndex
		// PHP iterates the explicit states in file order and returns the first match; states are unique, so any
		// order finds the same one
		for id, candidate := range ex {
			if equalInts(candidate, indexes) {
				return id
			}
		}
		return state
	}
	values := b.propValues[block][p]
	stride := b.strides[block][p]
	current := ((state - b.FirstState[block]) / stride) % len(values)
	return state + (valueIndex-current)*stride
}

func (b *Blocks) WithBool(state int, property string, value bool) int {
	if value {
		return b.With(state, property, "true")
	}
	return b.With(state, property, "false")
}

func (b *Blocks) WithInt(state int, property string, value int) int {
	return b.With(state, property, itoa(value))
}

func (b *Blocks) PropertyNames(state int) []string { return b.propNames[b.BlockOf[state]] }

func (b *Blocks) PropertyValues(state int, property string) []string {
	block := int(b.BlockOf[state])
	p := b.propertyIndex(block, property)
	if p < 0 {
		return nil
	}
	return b.propValues[block][p]
}

func (b *Blocks) Is(state, block int) bool { return int(b.BlockOf[state]) == block }

// ---- tags ----

// Tag returns the set of block indexes in a tag (name with or without "#" and namespace).
func (b *Blocks) Tag(name string) (map[int]bool, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.tagLocked(name)
}

func (b *Blocks) tagLocked(name string) (map[int]bool, error) {
	name = namespaced(strings.TrimPrefix(name, "#"))
	if t, ok := b.tags[name]; ok {
		return t, nil
	}
	raw, ok := b.rawTags[name]
	if !ok {
		return nil, fmt.Errorf("unknown block tag %s", name)
	}
	b.tags[name] = map[int]bool{} //cycle guard
	set := map[int]bool{}
	for _, v := range raw {
		if strings.HasPrefix(v, "#") {
			sub, err := b.tagLocked(v)
			if err != nil {
				return nil, err
			}
			for k := range sub {
				set[k] = true
			}
		} else if i, ok := b.blockByName[namespaced(v)]; ok {
			set[i] = true
		}
	}
	b.tags[name] = set
	return set, nil
}

// MustTag is Tag for tags known to exist (panics otherwise, see MustBlock).
func (b *Blocks) MustTag(name string) map[int]bool {
	t, err := b.Tag(name)
	if err != nil {
		panic(err)
	}
	return t
}

func (b *Blocks) HasTag(name string) bool {
	_, ok := b.rawTags[namespaced(strings.TrimPrefix(name, "#"))]
	return ok
}

// BlockSet resolves a block holder set: a tag ("#minecraft:x"), one block id or a list of ids.
func (b *Blocks) BlockSet(js any) (map[int]bool, error) {
	switch v := js.(type) {
	case string:
		if strings.HasPrefix(v, "#") {
			return b.Tag(v)
		}
		i, err := b.BlockIndex(v)
		if err != nil {
			return nil, err
		}
		return map[int]bool{i: true}, nil
	case []any:
		set := map[int]bool{}
		for _, e := range v {
			if s, ok := e.(string); ok {
				sub, err := b.BlockSet(s)
				if err != nil {
					return nil, err
				}
				for k := range sub {
					set[k] = true
				}
			}
		}
		return set, nil
	}
	return map[int]bool{}, nil
}

// ---- physical properties ----

func (b *Blocks) IsAir(state int) bool {
	block := int(b.BlockOf[state])
	return block == b.AirBlock || block == b.CaveAirBlock || block == b.VoidAirBlock
}

// Fluid is FLUID_NONE, FLUID_WATER or FLUID_LAVA in the low bits, with bit 4 set for source fluids.
func (b *Blocks) Fluid(state int) int { return int(b.fluid[state]) }

func (b *Blocks) computeFluid(state int) int {
	block := int(b.BlockOf[state])
	switch {
	case block == b.WaterBlock:
		if v, _ := b.GetProperty(state, "level"); v == "0" {
			return FluidWater | 16
		}
		return FluidWater
	case block == b.LavaBlock:
		if v, _ := b.GetProperty(state, "level"); v == "0" {
			return FluidLava | 16
		}
		return FluidLava
	}
	if v, _ := b.GetProperty(state, "waterlogged"); v == "true" {
		return FluidWater | 16
	}
	switch b.Names[block] {
	case "minecraft:seagrass", "minecraft:tall_seagrass", "minecraft:kelp", "minecraft:kelp_plant", "minecraft:bubble_column":
		return FluidWater | 16
	}
	return FluidNone
}

func (b *Blocks) IsWater(state int) bool       { return b.Fluid(state)&15 == FluidWater }
func (b *Blocks) IsLava(state int) bool        { return b.Fluid(state)&15 == FluidLava }
func (b *Blocks) FluidIsSource(state int) bool { return b.Fluid(state)&16 != 0 }

// FluidInTag tells whether the fluid of the state is in a fluid tag ("minecraft:water", "minecraft:lava").
func (b *Blocks) FluidInTag(state int, tag string) bool {
	fluid := b.Fluid(state) & 15
	if fluid == FluidNone {
		return false
	}
	fluids := b.fluidTag[namespaced(strings.TrimPrefix(tag, "#"))]
	source := b.FluidIsSource(state)
	var name string
	if fluid == FluidWater {
		name = "minecraft:flowing_water"
		if source {
			name = "minecraft:water"
		}
	} else {
		name = "minecraft:flowing_lava"
		if source {
			name = "minecraft:lava"
		}
	}
	return fluids[name]
}

func (b *Blocks) boxes(state int) []box {
	if state < len(b.collisionIndex) {
		i := int(b.collisionIndex[state])
		if i < len(b.shapes) {
			return b.shapes[i]
		}
		return nil
	}
	if len(b.shapes) > 0 {
		return b.shapes[0]
	}
	return nil
}

func forcedSolid(name string) (bool, bool) {
	switch name {
	case "azalea", "big_dripleaf", "chorus_flower", "chorus_plant", "end_rod", "flowering_azalea", "ladder", "snow":
		return false, true
	case "amethyst_cluster", "bamboo", "bamboo_sapling", "bell", "cake", "cobweb", "conduit", "dried_ghast", "lantern",
		"soul_lantern", "oak_fence", "pointed_dripstone", "sculk_vein", "sulfur_spike", "turtle_egg", "moving_piston", "iron_chain":
		return true, true
	}
	if strings.HasSuffix(name, "_fence_gate") || strings.HasSuffix(name, "_sign") || strings.HasSuffix(name, "_pressure_plate") ||
		strings.HasSuffix(name, "_wall") || strings.HasSuffix(name, "shulker_box") || strings.HasSuffix(name, "_banner") ||
		strings.HasSuffix(name, "_chain") || strings.HasSuffix(name, "_lantern") || (strings.HasSuffix(name, "_chest") && strings.Contains(name, "copper")) ||
		(strings.HasPrefix(name, "dead_") && strings.Contains(name, "coral")) {
		return true, true
	}
	if name == "scaffolding" {
		return false, true
	}
	return false, false
}

// IsSolid is Java's legacy "solid" flag (BlockStateBase.isSolid()).
func (b *Blocks) IsSolid(state int) bool { return b.solid[state] }

func (b *Blocks) computeSolid(state int) bool {
	name := b.Names[b.BlockOf[state]]
	if len(name) > 10 {
		name = name[10:]
	} else {
		name = ""
	}
	if v, ok := forcedSolid(name); ok {
		return v
	}
	boxes := b.boxes(state)
	if len(boxes) == 0 {
		return false
	}
	minX, minY, minZ := 1.0e9, 1.0e9, 1.0e9
	maxX, maxY, maxZ := -1.0e9, -1.0e9, -1.0e9
	for _, bx := range boxes {
		if bx.x0 < minX {
			minX = bx.x0
		}
		if bx.y0 < minY {
			minY = bx.y0
		}
		if bx.z0 < minZ {
			minZ = bx.z0
		}
		if bx.x1 > maxX {
			maxX = bx.x1
		}
		if bx.y1 > maxY {
			maxY = bx.y1
		}
		if bx.z1 > maxZ {
			maxZ = bx.z1
		}
	}
	size := ((maxX - minX) + (maxY - minY) + (maxZ - minZ)) / 3.0
	return size >= 0.7291666666666666 || (maxY-minY) >= 1.0
}

// IsFaceSturdy tells whether the collision shape covers the whole face.
func (b *Blocks) IsFaceSturdy(state, face int) bool { return b.sturdy[state*6+face] }

func (b *Blocks) computeSturdy(state, face int) bool {
	v := false
	for _, bx := range b.boxes(state) {
		var full bool
		switch face {
		case Down:
			full = bx.y0 <= 0.0 && bx.x0 <= 0.0 && bx.z0 <= 0.0 && bx.x1 >= 1.0 && bx.z1 >= 1.0
		case Up:
			full = bx.y1 >= 1.0 && bx.x0 <= 0.0 && bx.z0 <= 0.0 && bx.x1 >= 1.0 && bx.z1 >= 1.0
		case North:
			full = bx.z0 <= 0.0 && bx.x0 <= 0.0 && bx.y0 <= 0.0 && bx.x1 >= 1.0 && bx.y1 >= 1.0
		case South:
			full = bx.z1 >= 1.0 && bx.x0 <= 0.0 && bx.y0 <= 0.0 && bx.x1 >= 1.0 && bx.y1 >= 1.0
		case West:
			full = bx.x0 <= 0.0 && bx.y0 <= 0.0 && bx.z0 <= 0.0 && bx.y1 >= 1.0 && bx.z1 >= 1.0
		default:
			full = bx.x1 >= 1.0 && bx.y0 <= 0.0 && bx.z0 <= 0.0 && bx.y1 >= 1.0 && bx.z1 >= 1.0
		}
		if full {
			v = true
			break
		}
	}
	return v
}

func (b *Blocks) IsFullCube(state int) bool { return b.fullCube[state] }

func (b *Blocks) computeFullCube(state int) bool {
	v := false
	for _, bx := range b.boxes(state) {
		if bx.x0 <= 0.0 && bx.y0 <= 0.0 && bx.z0 <= 0.0 && bx.x1 >= 1.0 && bx.y1 >= 1.0 && bx.z1 >= 1.0 {
			v = true
			break
		}
	}
	return v
}

func (b *Blocks) CollisionMaxY(state int) float64 {
	m := 0.0
	for _, bx := range b.boxes(state) {
		if bx.y1 > m {
			m = bx.y1
		}
	}
	return m
}

func (b *Blocks) HasCollision(state int) bool { return len(b.boxes(state)) > 0 }

// Describe is a debug name, e.g. "minecraft:oak_log[axis=y]".
func (b *Blocks) Describe(state int) string {
	var props []string
	for _, p := range b.PropertyNames(state) {
		v, _ := b.GetProperty(state, p)
		props = append(props, p+"="+v)
	}
	if len(props) == 0 {
		return b.BlockName(state)
	}
	return b.BlockName(state) + "[" + strings.Join(props, ",") + "]"
}

// ---- helpers ----

func equalInts(a, b []int) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

func isJSONIntNumber(n json.Number) bool {
	return !strings.ContainsAny(string(n), ".eE")
}

// phpIntOfString is PHP's (int) cast of a numeric string (leading integer part, 0 otherwise).
func phpIntOfString(s string) int {
	s = strings.TrimLeft(s, " \t\n\r\v\f")
	neg := false
	i := 0
	if i < len(s) && (s[i] == '-' || s[i] == '+') {
		neg = s[i] == '-'
		i++
	}
	v := 0
	for ; i < len(s) && s[i] >= '0' && s[i] <= '9'; i++ {
		v = v*10 + int(s[i]-'0')
	}
	if neg {
		return -v
	}
	return v
}

func itoa(v int) string {
	return fmt.Sprint(v)
}

func (b *Blocks) precompute() {
	n := len(b.BlockOf)
	b.solid = make([]bool, n)
	b.sturdy = make([]bool, n*6)
	b.fullCube = make([]bool, n)
	b.fluid = make([]uint8, n)
	for s := 0; s < n; s++ {
		b.solid[s] = b.computeSolid(s)
		b.fullCube[s] = b.computeFullCube(s)
		b.fluid[s] = uint8(b.computeFluid(s))
		for f := 0; f < 6; f++ {
			b.sturdy[s*6+f] = b.computeSturdy(s, f)
		}
	}
}

// StateCount is the number of Java states.
func (b *Blocks) StateCount() int { return len(b.BlockOf) }

// TagMask returns a tag as a slice indexed by block index (cached; panics if the tag does not exist, like
// JavaBlocks::tag() throws).
func (b *Blocks) TagMask(name string) []bool {
	if m, ok := b.tagMasks.Load(name); ok {
		return m.([]bool)
	}
	set, err := b.Tag(name)
	if err != nil {
		panic(err)
	}
	mask := blockMask(b, set)
	b.tagMasks.Store(name, mask)
	return mask
}

// IsTagged is JavaBlocks::isTagged().
func (b *Blocks) IsTagged(state int, tag string) bool { return b.TagMask(tag)[b.BlockOf[state]] }

// TagOrder returns the blocks of a tag in PHP's insertion order of JavaBlocks::tag() (nested tags merged with +=).
func (b *Blocks) TagOrder(name string) ([]int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.tagOrders == nil {
		b.tagOrders = map[string][]int{}
	}
	return b.tagOrderLocked(name)
}

func (b *Blocks) tagOrderLocked(name string) ([]int, error) {
	name = namespaced(strings.TrimPrefix(name, "#"))
	if t, ok := b.tagOrders[name]; ok {
		return t, nil
	}
	raw, ok := b.rawTags[name]
	if !ok {
		return nil, fmt.Errorf("unknown block tag %s", name)
	}
	b.tagOrders[name] = []int{} //cycle guard
	var out []int
	seen := map[int]bool{}
	for _, v := range raw {
		if strings.HasPrefix(v, "#") {
			sub, err := b.tagOrderLocked(v)
			if err != nil {
				return nil, err
			}
			for _, k := range sub {
				if !seen[k] {
					seen[k] = true
					out = append(out, k)
				}
			}
		} else if i, ok := b.blockByName[namespaced(v)]; ok && !seen[i] {
			seen[i] = true
			out = append(out, i)
		}
	}
	b.tagOrders[name] = out
	return out, nil
}

// BlockSetOrder is array_keys(JavaBlocks::blockSet()): a tag, one block id or a list of them, in insertion order.
func (b *Blocks) BlockSetOrder(js any) ([]int, error) {
	switch v := js.(type) {
	case string:
		if strings.HasPrefix(v, "#") {
			return b.TagOrder(v)
		}
		i, err := b.BlockIndex(v)
		if err != nil {
			return nil, err
		}
		return []int{i}, nil
	case []any:
		var out []int
		seen := map[int]bool{}
		for _, e := range v {
			if s, ok := e.(string); ok {
				sub, err := b.BlockSetOrder(s)
				if err != nil {
					return nil, err
				}
				for _, k := range sub {
					if !seen[k] {
						seen[k] = true
						out = append(out, k)
					}
				}
			}
		}
		return out, nil
	}
	return nil, nil
}
