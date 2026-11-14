package decor

import (
	"errors"
	"fmt"
	"strings"

	"github.com/PocketMine-MP-Reborn/PocketMine-MP/native/vanillagen/worldgen"
)

// errUnsupported marks feature data the native side does not implement yet: the placed feature is then left to PHP.
var errUnsupported = errors.New("not implemented natively")

func unsupported(what string) error { return fmt.Errorf("%w: %s", errUnsupported, what) }

// Registry is the native side of feature/FeatureRegistry.php: placed and configured features parsed from the vanilla
// data (lazily), and the biome feature lists used by the biome filter. One per session.
type Registry struct {
	Data *worldgen.Data
	B    *Blocks

	BiomeNames      []string
	biomeIndex      map[string]int
	biomeFeatureSet []map[string]bool

	placed     map[string]*PlacedFeature
	placedErr  map[string]error
	configured map[string]Feature
	confErr    map[string]error

	biomeInfoNoise *worldgen.SimplexNoise
	survival       map[int]func(c *Ctx, x, y, z int) bool
	climate        *Climate
}

// NewRegistry builds the registry for the biomes of a dimension, in the PHP registry's order.
func NewRegistry(data *worldgen.Data, b *Blocks, biomeNames []string) (*Registry, error) {
	reg := &Registry{
		Data: data, B: b, BiomeNames: biomeNames, biomeIndex: map[string]int{},
		placed: map[string]*PlacedFeature{}, placedErr: map[string]error{},
		configured: map[string]Feature{}, confErr: map[string]error{},
		biomeInfoNoise: worldgen.NewSimplexNoise(worldgen.NewLegacyRandom(2345)),
		survival:       map[int]func(c *Ctx, x, y, z int) bool{},
	}
	for i, name := range biomeNames {
		reg.biomeIndex[name] = i
		json, err := data.Biome(name)
		if err != nil {
			return nil, err
		}
		set := map[string]bool{}
		if steps, ok := json["features"].([]any); ok {
			for _, step := range steps {
				ids, ok := step.([]any)
				if !ok {
					ids = []any{step}
				}
				for _, id := range ids {
					if s, ok := id.(string); ok {
						set[namespaced(s)] = true
					}
				}
			}
		}
		reg.biomeFeatureSet = append(reg.biomeFeatureSet, set)
	}
	return reg, nil
}

func (reg *Registry) BiomeHasFeature(biome int, id string) bool {
	return biome >= 0 && biome < len(reg.biomeFeatureSet) && reg.biomeFeatureSet[biome][id]
}

// BiomeInSet is a biome holder set test (an id, a list of ids or a tag, which is not supported and never matches).
func (reg *Registry) BiomeInSet(biome int, set any) bool {
	if biome < 0 || biome >= len(reg.BiomeNames) {
		return false
	}
	name := reg.BiomeNames[biome]
	switch v := set.(type) {
	case string:
		return !strings.HasPrefix(v, "#") && namespaced(v) == name
	case []any:
		for _, e := range v {
			if s, ok := e.(string); ok && namespaced(s) == name {
				return true
			}
		}
	}
	return false
}

// BiomeInfoNoise is Biome.BIOME_INFO_NOISE.get(x, z) (a float in vanilla 26.x).
func (reg *Registry) BiomeInfoNoise(x, z float64) float64 {
	return fround(reg.biomeInfoNoise.GetValue2D(x, z))
}

// Placed returns a placed feature by id, or an error if it (or anything it uses) is not implemented natively.
func (reg *Registry) Placed(id string) (p *PlacedFeature, err error) {
	id = namespaced(id)
	if p, ok := reg.placed[id]; ok {
		return p, nil
	}
	if err, ok := reg.placedErr[id]; ok {
		return nil, err
	}
	defer func() {
		if r := recover(); r != nil {
			// MustBlock/MustTag on data without the block or tag
			p, err = nil, fmt.Errorf("%v", r)
		}
		if err != nil {
			reg.placedErr[id] = err
		} else {
			reg.placed[id] = p
		}
	}()
	json, err := reg.Data.ReadObject("data/minecraft/worldgen/placed_feature/" + stripNamespace(id) + ".json")
	if err != nil {
		return nil, err
	}
	return reg.parsePlaced(id, json)
}

// PlacedRef is a placed feature field: a registry id or an inline {"feature": ..., "placement": [...]}.
func (reg *Registry) PlacedRef(js any) (*PlacedFeature, error) {
	if s, ok := js.(string); ok {
		return reg.Placed(s)
	}
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid placed feature")
	}
	return reg.parsePlaced("inline", o)
}

func (reg *Registry) parsePlaced(id string, json obj) (*PlacedFeature, error) {
	feature, err := reg.ConfiguredRef(json["feature"])
	if err != nil {
		return nil, err
	}
	var mods []Modifier
	if list, ok := json["placement"].([]any); ok {
		for _, m := range list {
			mod, err := parseModifier(reg, m)
			if err != nil {
				return nil, err
			}
			mods = append(mods, mod)
		}
	}
	return &PlacedFeature{ID: id, Feature: feature, modifiers: mods}, nil
}

// ConfiguredRef is a configured feature field: a registry id or an inline {"type": ..., ...}.
func (reg *Registry) ConfiguredRef(js any) (Feature, error) {
	if s, ok := js.(string); ok {
		id := namespaced(s)
		if f, ok := reg.configured[id]; ok {
			return f, nil
		}
		if err, ok := reg.confErr[id]; ok {
			return nil, err
		}
		rel := "data/minecraft/worldgen/feature/" + stripNamespace(id) + ".json"
		if !reg.Data.Exists(rel) {
			return nil, fmt.Errorf("unknown configured feature %s", id)
		}
		json, err := reg.Data.ReadObject(rel)
		var f Feature
		if err == nil {
			f, err = reg.parseConfigured(json)
		}
		if err != nil {
			reg.confErr[id] = err
			return nil, err
		}
		reg.configured[id] = f
		return f, nil
	}
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid configured feature")
	}
	return reg.parseConfigured(o)
}

func (reg *Registry) parseConfigured(json obj) (Feature, error) {
	t, err := typeOf(json, "type")
	if err != nil {
		return nil, err
	}
	factory, ok := featureTypes[t]
	if !ok {
		return nil, unsupported("feature type " + t)
	}
	return factory(reg, json)
}

// Survival returns BlockSurvival.canSurvive() for a state.
func (reg *Registry) Survival(state int) (func(c *Ctx, x, y, z int) bool, error) {
	s := SurvivalOf(reg.B)
	return func(c *Ctx, x, y, z int) bool { return s.CanSurvive(c.Level, state, x, y, z) }, nil
}

// featureTypes are the configured feature types implemented natively (FeatureTypes::create()).
var featureTypes map[string]func(reg *Registry, json obj) (Feature, error)

func init() {
	featureTypes = map[string]func(reg *Registry, json obj) (Feature, error){
		"no_op":                          func(*Registry, obj) (Feature, error) { return noOpFeature{}, nil },
		"ore":                            func(reg *Registry, json obj) (Feature, error) { return newOreFeature(reg, json, false) },
		"scattered_ore":                  func(reg *Registry, json obj) (Feature, error) { return newOreFeature(reg, json, true) },
		"random_selector":                func(reg *Registry, json obj) (Feature, error) { return newSelector(reg, selRandom, json) },
		"simple_random_selector":         func(reg *Registry, json obj) (Feature, error) { return newSelector(reg, selSimpleRandom, json) },
		"weighted_random_selector":       func(reg *Registry, json obj) (Feature, error) { return newSelector(reg, selWeighted, json) },
		"random_boolean_selector":        func(reg *Registry, json obj) (Feature, error) { return newSelector(reg, selBoolean, json) },
		"sequence":                       func(reg *Registry, json obj) (Feature, error) { return newSelector(reg, selSequence, json) },
		"simple_block":                   newSimpleBlock,
		"block_column":                   newBlockColumn,
		"disk":                           newDisk,
		"spring_feature":                 newSpring,
		"geode":                          newGeode,
		"underwater_magma":               newUnderwaterMagma,
		"freeze_top_layer":               newFreezeTopLayer,
		"blue_ice":                       newBlueIce,
		"block_pile":                     newBlockPile,
		"spike":                          newSpike,
		"vines":                          newVines,
		"block_blob":                     newBlockBlob,
		"overlay":                        newOverlay,
		"vegetation_patch":               newVegetationPatch(false),
		"waterlogged_vegetation_patch":   newVegetationPatch(true),
		"multiface_growth":               newMultifaceGrowth,
		"speleothem":                     newSpeleothem(spSpeleothem),
		"speleothem_cluster":             newSpeleothem(spCluster),
		"large_dripstone":                newSpeleothem(spLarge),
		"lake":                           newLake,
		"tree":                           newTree,
		"random_neighbor_spread":         newRandomNeighborSpread,
		"single_block_pillar":            newSingleBlockPillar,
		"projected_random_patchy_square": newProjectedPatchySquare,
		"stepped_column_cluster":         newSteppedColumnCluster,
		"delta_feature":                  newDelta,
		"netherrack_replace_blobs":       newReplaceBlobs,
		"huge_fungus":                    newHugeFungus,
		"iceberg":                        newIceberg,
		"sculk_patch":                    newSculkPatch,
		"bamboo":                         newBamboo,
		"coral_tree":                     newCoralTree,
		"coral_claw":                     newCoralClaw,
		"monster_room":                   newMonsterRoom,
		"root_system":                    newRootSystem,
		"fallen_tree":                    newFallenTree,
		"huge_brown_mushroom":            newHugeMushroom(false),
		"huge_red_mushroom":              newHugeMushroom(true),
	}
}
