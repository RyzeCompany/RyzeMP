package decor

import (
	"fmt"
	"math"
)

func heightmapType(name string) (int, error) {
	switch name {
	case "WORLD_SURFACE_WG":
		return WorldSurfaceWG, nil
	case "WORLD_SURFACE":
		return WorldSurface, nil
	case "OCEAN_FLOOR_WG":
		return OceanFloorWG, nil
	case "OCEAN_FLOOR":
		return OceanFloor, nil
	case "MOTION_BLOCKING":
		return MotionBlocking, nil
	case "MOTION_BLOCKING_NO_LEAVES":
		return MotionBlockingNoLeaves, nil
	}
	return 0, fmt.Errorf("unknown heightmap %s", name)
}

func repeatPos(n, x, y, z int) []pos {
	if n <= 0 {
		return nil
	}
	out := make([]pos, n)
	for i := range out {
		out[i] = pos{x, y, z}
	}
	return out
}

// parseModifier is Placements::parse().
func parseModifier(reg *Registry, js any) (Modifier, error) {
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid placement modifier")
	}
	t, err := typeOf(o, "type")
	if err != nil {
		return nil, err
	}
	switch t {
	case "count":
		count, err := parseIntProvider(o["count"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos { return repeatPos(count.Sample(c.R), x, y, z) }, nil
	case "in_square":
		return func(c *Ctx, x, y, z int) []pos {
			px := c.R.NextBoundedInt(16) + x
			pz := c.R.NextBoundedInt(16) + z
			return []pos{{px, y, pz}}
		}, nil
	case "rarity_filter":
		n, err := jInt(o, "chance")
		if err != nil {
			return nil, err
		}
		chance := fround(1.0 / float64(n))
		return func(c *Ctx, x, y, z int) []pos {
			if c.R.NextFloat() < chance {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "random_chance":
		f, err := jFloat(o, "chance")
		if err != nil {
			return nil, err
		}
		chance := fround(f)
		return func(c *Ctx, x, y, z int) []pos {
			if c.R.NextFloat() < chance {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "height_range":
		height, err := parseHeightProvider(o["height"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos { return []pos{{x, height(c.R, c), z}} }, nil
	case "heightmap":
		name, err := jString(o, "heightmap")
		if err != nil {
			return nil, err
		}
		hm, err := heightmapType(name)
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			h := c.Level.GetHeight(hm, x, z)
			if h > c.Level.MinY {
				return []pos{{x, h, z}}
			}
			return nil
		}, nil
	case "biome":
		return func(c *Ctx, x, y, z int) []pos {
			if c.Top == nil {
				panic("tried to biome check an unregistered feature, or a feature that should not restrict the biome")
			}
			if c.Reg.BiomeHasFeature(c.Level.GetBiome(x, y, z), c.Top.ID) {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "block_predicate_filter":
		pred, err := parseBlockPredicate(reg, o["predicate"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			if pred(c, x, y, z) {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "surface_water_depth_filter":
		maxDepth, err := jInt(o, "max_water_depth")
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			floor := c.Level.GetHeight(OceanFloor, x, z)
			surface := c.Level.GetHeight(WorldSurface, x, z)
			if surface-floor <= maxDepth {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "surface_relative_threshold_filter":
		name, err := jString(o, "heightmap")
		if err != nil {
			return nil, err
		}
		hm, err := heightmapType(name)
		if err != nil {
			return nil, err
		}
		min, err := jIntDef(o, "min_inclusive", math.MinInt64>>1)
		if err != nil {
			return nil, err
		}
		max, err := jIntDef(o, "max_inclusive", math.MaxInt64>>1)
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			surface := c.Level.GetHeight(hm, x, z)
			if surface+min <= y && y <= surface+max {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "count_on_every_layer":
		count, err := parseIntProvider(o["count"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			var out []pos
			layer := 0
			for {
				found := false
				for i, n := 0, count.Sample(c.R); i < n; i, n = i+1, count.Sample(c.R) {
					px := c.R.NextBoundedInt(16) + x
					pz := c.R.NextBoundedInt(16) + z
					startY := c.Level.GetHeight(MotionBlocking, px, pz)
					if py, ok := findOnGroundYPosition(c, px, startY, pz, layer); ok {
						out = append(out, pos{px, py, pz})
						found = true
					}
				}
				layer++
				if !found {
					break
				}
			}
			return out
		}, nil
	case "environment_scan":
		dirName, err := jString(o, "direction_of_search")
		if err != nil {
			return nil, err
		}
		direction, err := directionFromName(dirName)
		if err != nil {
			return nil, err
		}
		target, err := parseBlockPredicate(reg, o["target_condition"])
		if err != nil {
			return nil, err
		}
		allowed := BlockPredicate(func(*Ctx, int, int, int) bool { return true })
		if o["allowed_search_condition"] != nil {
			if allowed, err = parseBlockPredicate(reg, o["allowed_search_condition"]); err != nil {
				return nil, err
			}
		}
		maxSteps, err := jInt(o, "max_steps")
		if err != nil {
			return nil, err
		}
		dx, dy, dz := StepX[direction], StepY[direction], StepZ[direction]
		return func(c *Ctx, x, y, z int) []pos {
			if !allowed(c, x, y, z) {
				return nil
			}
			for i := 0; i < maxSteps; i++ {
				if target(c, x, y, z) {
					return []pos{{x, y, z}}
				}
				x += dx
				y += dy
				z += dz
				if c.Level.IsOutsideBuildHeight(y) {
					return nil
				}
				if !allowed(c, x, y, z) {
					break
				}
			}
			if target(c, x, y, z) {
				return []pos{{x, y, z}}
			}
			return nil
		}, nil
	case "offset":
		ox, err := parseIntProviderDef(o, "x")
		if err != nil {
			return nil, err
		}
		oy, err := parseIntProviderDef(o, "y")
		if err != nil {
			return nil, err
		}
		oz, err := parseIntProviderDef(o, "z")
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			px := ox.Sample(c.R)
			py := oy.Sample(c.R)
			pz := oz.Sample(c.R)
			return []pos{{x + px, y + py, z + pz}}
		}, nil
	case "randomly_selected":
		list, err := jArray(o, "placements")
		if err != nil {
			return nil, err
		}
		var mods []Modifier
		for _, sub := range list {
			m, err := parseModifier(reg, sub)
			if err != nil {
				return nil, err
			}
			mods = append(mods, m)
		}
		size := len(mods)
		return func(c *Ctx, x, y, z int) []pos { return mods[c.R.NextBoundedInt(size)](c, x, y, z) }, nil
	case "noise_based_count":
		ratio, err1 := jInt(o, "noise_to_count_ratio")
		factor, err2 := jFloat(o, "noise_factor")
		offset, err3 := jFloatDef(o, "noise_offset", 0.0)
		if err := firstErr(err1, err2, err3); err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			noise := c.Reg.BiomeInfoNoise(float64(x)/factor, float64(z)/factor)
			n := phpIntOfFloat(math.Ceil(float64((noise + offset) * float64(ratio))))
			return repeatPos(n, x, y, z)
		}, nil
	case "noise_threshold_count":
		level, err1 := jFloat(o, "noise_level")
		below, err2 := jInt(o, "below_noise")
		above, err3 := jInt(o, "above_noise")
		if err := firstErr(err1, err2, err3); err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) []pos {
			noise := c.Reg.BiomeInfoNoise(float64(x)/200.0, float64(z)/200.0)
			n := above
			if noise < level {
				n = below
			}
			return repeatPos(n, x, y, z)
		}, nil
	case "cuboid":
		xzSize, err := parseIntProvider(o["xz_size"])
		if err != nil {
			return nil, err
		}
		ySize, err := parseIntProvider(o["y_size"])
		if err != nil {
			return nil, err
		}
		includeEdges := jBoolDef(o, "include_edges", true)
		includeInterior := jBoolDef(o, "include_interior", true)
		return func(c *Ctx, x, y, z int) []pos {
			height := ySize.Sample(c.R)
			width := xzSize.Sample(c.R)
			length := xzSize.Sample(c.R)
			var out []pos
			for dx := 0; dx <= width; dx++ {
				xEdge := dx == 0 || dx == width
				for dy := 0; dy <= height; dy++ {
					yEdge := dy == 0 || dy == height
					for dz := 0; dz <= length; dz++ {
						zEdge := dz == 0 || dz == length
						if !includeEdges && ((xEdge && yEdge) || (zEdge && yEdge) || (xEdge && zEdge)) {
							continue
						}
						if !includeInterior && !xEdge && !yEdge && !zEdge {
							continue
						}
						out = append(out, pos{x + dx, y + dy, z + dz})
					}
				}
			}
			return out
		}, nil
	case "fixed_placement":
		list, err := jArray(o, "positions")
		if err != nil {
			return nil, err
		}
		var positions []pos
		for _, p := range list {
			positions = append(positions, vec3(p))
		}
		return func(c *Ctx, x, y, z int) []pos {
			var out []pos
			for _, p := range positions {
				if p[0]>>4 == x>>4 && p[2]>>4 == z>>4 {
					out = append(out, p)
				}
			}
			return out
		}, nil
	}
	return nil, fmt.Errorf("unknown placement modifier type %s", t)
}

// parseIntProviderDef is Providers::int($json[key] ?? 0).
func parseIntProviderDef(o obj, key string) (*IntProvider, error) {
	if o[key] == nil {
		return constantInt(0), nil
	}
	return parseIntProvider(o[key])
}

// findOnGroundYPosition is CountOnEveryLayerPlacement.findOnGroundYPosition().
func findOnGroundYPosition(c *Ctx, x, startY, z, layerToPlaceOn int) (int, bool) {
	level := c.Level
	current := level.GetState(x, startY, z)
	currentLayer := 0
	for y := startY; y >= level.MinY+1; y-- {
		below := level.GetState(x, y-1, z)
		if !isEmptyForLayer(c, below) && isEmptyForLayer(c, current) && int(c.B.BlockOf[below]) != c.B.bedrockBlock {
			if currentLayer == layerToPlaceOn {
				return y, true
			}
			currentLayer++
		}
		current = below
	}
	return 0, false
}

func isEmptyForLayer(c *Ctx, state int) bool {
	block := int(c.B.BlockOf[state])
	return c.B.IsAir(state) || block == c.B.WaterBlock || block == c.B.LavaBlock
}
