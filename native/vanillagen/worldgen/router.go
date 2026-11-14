package worldgen

import (
	"encoding/json"
	"fmt"
)

// Router is the seed-bound noise router of one noise settings entry (NoiseRouter.php). It owns evaluation state:
// one per goroutine.
type Router struct {
	Random *PositionalFactory
	// LegacyRandom: the settings use the legacy random source (Nether, End)
	LegacyRandom bool
	// DefaultBlock and DefaultFluid are Java block ids; SeaMaterial is the terrain material of the default fluid
	DefaultBlock, DefaultFluid string
	SeaMaterial                int
	seed                       int64
	vanillaFloats              bool
	data                       *Data

	Temperature, Vegetation, Continents, Erosion, Depth, Ridges Fn
	FinalDensity, ChunkSurfaceLevel                             Fn

	AquifersEnabled                                   bool
	AquiferBarrier, AquiferFloodedness, AquiferSpread Fn
	AquiferLava, AquiferExclusion, AquiferSurface     Fn

	SeaLevel, MinY, Height int

	parser *Parser
}

func NewRouter(data *Data, seed int64, settingsID string, vanillaFloats bool) (*Router, error) {
	settings, err := data.NoiseSettings(settingsID)
	if err != nil {
		return nil, err
	}
	r := &Router{seed: seed, vanillaFloats: vanillaFloats, data: data}
	if v, ok := settings["legacy_random_source"].(bool); ok && v {
		r.LegacyRandom = true
		r.Random = NewLegacySource(seed).ForkPositional()
	} else {
		r.Random = NewXoroshiroFromSeed(seed).ForkPositional()
	}
	r.parser = r.NewParser()
	r.DefaultBlock = "minecraft:stone"
	r.DefaultFluid = "minecraft:water"
	for _, d := range []struct {
		key string
		dst *string
	}{{"default_block", &r.DefaultBlock}, {"default_fluid", &r.DefaultFluid}} {
		switch v := settings[d.key].(type) {
		case string:
			*d.dst = "minecraft:" + stripNamespace(v)
		case map[string]any:
			name, err := jsonString(v, "Name", nil)
			if err != nil {
				return nil, err
			}
			*d.dst = "minecraft:" + stripNamespace(name)
		}
	}
	switch r.DefaultFluid {
	case "minecraft:water":
		r.SeaMaterial = MaterialWater
	case "minecraft:lava":
		r.SeaMaterial = MaterialLava
	case "minecraft:air":
		r.SeaMaterial = MaterialAir
	default:
		return nil, fmt.Errorf("unsupported default fluid %s", r.DefaultFluid)
	}

	noise, ok := settings["noise"].(map[string]any)
	if !ok {
		return nil, fmt.Errorf("expected array for \"noise\"")
	}
	var e1, e2, e3 error
	r.MinY, e1 = jsonInt(noise, "min_y", nil)
	r.Height, e2 = jsonInt(noise, "height", nil)
	r.SeaLevel, e3 = jsonInt(settings, "sea_level", nil)
	if err := firstErr(e1, e2, e3); err != nil {
		return nil, err
	}

	router, ok := settings["noise_router"].(map[string]any)
	if !ok {
		return nil, fmt.Errorf("expected array for \"noise_router\"")
	}
	parseKey := func(obj map[string]any, key string) Fn {
		if err != nil {
			return nil
		}
		v, ok := obj[key]
		if !ok || v == nil {
			v = json.Number("0.0")
		}
		var f Fn
		f, err = r.parser.Parse(v)
		if err != nil {
			err = fmt.Errorf("%s: %w", key, err)
		}
		return f
	}
	err = nil
	r.Temperature = parseKey(router, "temperature")
	r.Vegetation = parseKey(router, "vegetation")
	r.Continents = parseKey(router, "continents")
	r.Erosion = parseKey(router, "erosion")
	r.Depth = parseKey(router, "depth")
	r.Ridges = parseKey(router, "ridges")
	r.FinalDensity = parseKey(router, "final_density")
	r.ChunkSurfaceLevel = parseKey(router, "chunk_surface_level")
	if aq, ok := settings["aquifers"].(map[string]any); ok {
		r.AquifersEnabled = true
		r.AquiferBarrier = parseKey(aq, "barrier")
		r.AquiferFloodedness = parseKey(aq, "fluid_level_floodedness")
		r.AquiferSpread = parseKey(aq, "fluid_level_spread")
		r.AquiferLava = parseKey(aq, "lava")
		r.AquiferExclusion = parseKey(aq, "exclusion")
		r.AquiferSurface = parseKey(aq, "surface_level")
	}
	if err != nil {
		return nil, err
	}
	return r, nil
}

// NewParser returns a parser bound to this router's seed and random source (own noise and named function instances).
func (r *Router) NewParser() *Parser {
	p := NewParser(r.data, r.Random, r.vanillaFloats)
	if r.LegacyRandom {
		seed := r.seed
		p.legacySeed = &seed
	}
	return p
}

// GlobalFluid is the fluid picker of NoiseBasedChunkGenerator: lava below y -54 when the sea is higher, the default
// fluid below the sea level, air above (a Material* id).
func (r *Router) GlobalFluid(y int) int {
	lavaLevel := -54
	if r.SeaLevel < lavaLevel {
		lavaLevel = r.SeaLevel
	}
	if y < lavaLevel {
		return MaterialLava
	}
	if y < r.SeaLevel {
		return r.SeaMaterial
	}
	return MaterialAir
}

// resetMemos clears every memoized value (between evaluation phases).
func (r *Router) resetMemos() {
	for _, m := range r.parser.memos {
		m.reset()
	}
}
