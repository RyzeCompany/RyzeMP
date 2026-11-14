package decor

import (
	"fmt"
	"strings"
)

// BlockPredicate is a block predicate (Predicates::block()).
type BlockPredicate func(c *Ctx, x, y, z int) bool

func parseBlockPredicate(reg *Registry, js any) (BlockPredicate, error) {
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid block predicate")
	}
	b := reg.B
	t, err := typeOf(o, "type")
	if err != nil {
		return nil, err
	}
	off := vec3(o["offset"])
	ox, oy, oz := off[0], off[1], off[2]
	switch t {
	case "true":
		return func(*Ctx, int, int, int) bool { return true }, nil
	case "not":
		p, err := parseBlockPredicate(reg, o["predicate"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) bool { return !p(c, x, y, z) }, nil
	case "all_of", "any_of":
		list, err := jArray(o, "predicates")
		if err != nil {
			return nil, err
		}
		var preds []BlockPredicate
		for _, sub := range list {
			p, err := parseBlockPredicate(reg, sub)
			if err != nil {
				return nil, err
			}
			preds = append(preds, p)
		}
		if t == "all_of" {
			return func(c *Ctx, x, y, z int) bool {
				for _, p := range preds {
					if !p(c, x, y, z) {
						return false
					}
				}
				return true
			}, nil
		}
		return func(c *Ctx, x, y, z int) bool {
			for _, p := range preds {
				if p(c, x, y, z) {
					return true
				}
			}
			return false
		}, nil
	case "matching_blocks", "matching_block_tag", "replaceable":
		var set map[int]bool
		switch t {
		case "matching_blocks":
			set, err = b.BlockSet(o["blocks"])
		case "matching_block_tag":
			var tag string
			if tag, err = jString(o, "tag"); err == nil {
				set, err = b.Tag(tag)
			}
		default:
			set, err = b.Tag("minecraft:replaceable")
		}
		if err != nil {
			return nil, err
		}
		mask := blockMask(b, set)
		return func(c *Ctx, x, y, z int) bool {
			return mask[c.B.BlockOf[c.Level.GetState(x+ox, y+oy, z+oz)]]
		}, nil
	case "matching_fluids":
		fluids := fluidSet(o["fluids"])
		return func(c *Ctx, x, y, z int) bool {
			return fluids[c.B.Fluid(c.Level.GetState(x+ox, y+oy, z+oz))]
		}, nil
	case "solid":
		return func(c *Ctx, x, y, z int) bool { return c.B.IsSolid(c.Level.GetState(x+ox, y+oy, z+oz)) }, nil
	case "would_survive":
		state, err := b.StateFromJSON(o["state"])
		if err != nil {
			return nil, err
		}
		survive, err := reg.Survival(state)
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) bool { return survive(c, x+ox, y+oy, z+oz) }, nil
	case "has_sturdy_face":
		name, err := jString(o, "direction")
		if err != nil {
			return nil, err
		}
		d, err := directionFromName(name)
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) bool { return c.B.IsFaceSturdy(c.Level.GetState(x+ox, y+oy, z+oz), d) }, nil
	case "inside_world_bounds":
		return func(c *Ctx, x, y, z int) bool { return c.Level.IsInsideBuildHeight(y + oy) }, nil
	case "height_range":
		min, err := parseAnchor(o["min_inclusive"])
		if err != nil {
			return nil, err
		}
		max, err := parseAnchor(o["max_inclusive"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) bool { return y >= min(c) && y <= max(c) }, nil
	case "volume_match":
		lo := vec3(o["min"])
		hi := vec3(o["max"])
		match, err := parseBlockPredicate(reg, o["match"])
		if err != nil {
			return nil, err
		}
		return func(c *Ctx, x, y, z int) bool {
			for dx := lo[0]; dx <= hi[0]; dx++ {
				for dz := lo[2]; dz <= hi[2]; dz++ {
					for dy := lo[1]; dy <= hi[1]; dy++ {
						if !match(c, x+dx, y+dy, z+dz) {
							return false
						}
					}
				}
			}
			return true
		}, nil
	case "unobstructed":
		return func(*Ctx, int, int, int) bool { return true }, nil //no entities during worldgen
	case "matching_biomes":
		names := o["biomes"]
		return func(c *Ctx, x, y, z int) bool { return c.Reg.BiomeInSet(c.Level.GetBiome(x, y, z), names) }, nil
	}
	return nil, fmt.Errorf("unknown block predicate type %s", t)
}

// blockMask turns a block index set into a slice indexed by block index.
func blockMask(b *Blocks, set map[int]bool) []bool {
	mask := make([]bool, len(b.Names))
	for k := range set {
		mask[k] = true
	}
	return mask
}

// fluidSet is Predicates::fluidSet(): fluid codes (kind | 16 if source).
func fluidSet(js any) map[int]bool {
	set := map[int]bool{}
	values, ok := js.([]any)
	if !ok {
		values = []any{js}
	}
	for _, v := range values {
		s, ok := v.(string)
		if !ok {
			continue
		}
		tag := strings.HasPrefix(s, "#")
		switch stripNamespace(strings.TrimPrefix(s, "#")) {
		case "water":
			set[FluidWater|16] = true
			if tag {
				set[FluidWater] = true
			}
		case "flowing_water":
			set[FluidWater] = true
		case "lava":
			set[FluidLava|16] = true
			if tag {
				set[FluidLava] = true
			}
		case "flowing_lava":
			set[FluidLava] = true
		case "empty":
			set[FluidNone] = true
		}
	}
	return set
}

// RuleTest is a rule test (structure processors and ore targets): test(state, random, y).
type RuleTest func(state int, r *Random, y int) bool

func parseRuleTest(b *Blocks, js any) (RuleTest, error) {
	o, ok := asObj(js)
	if !ok {
		return nil, fmt.Errorf("invalid rule test")
	}
	t, err := typeOf(o, "predicate_type")
	if err != nil {
		return nil, err
	}
	switch t {
	case "always_true":
		return func(int, *Random, int) bool { return true }, nil
	case "block_match", "random_block_match":
		name, err := jString(o, "block")
		if err != nil {
			return nil, err
		}
		block, err := b.BlockIndex(name)
		if err != nil {
			return nil, err
		}
		if t == "block_match" {
			return func(state int, r *Random, y int) bool { return int(b.BlockOf[state]) == block }, nil
		}
		p, err := jFloat(o, "probability")
		if err != nil {
			return nil, err
		}
		probability := fround(p)
		return func(state int, r *Random, y int) bool {
			return int(b.BlockOf[state]) == block && r.NextFloat() < probability
		}, nil
	case "blockstate_match", "random_blockstate_match":
		target, err := b.StateFromJSON(o["block_state"])
		if err != nil {
			return nil, err
		}
		if t == "blockstate_match" {
			return func(state int, r *Random, y int) bool { return state == target }, nil
		}
		p, err := jFloat(o, "probability")
		if err != nil {
			return nil, err
		}
		probability := fround(p)
		return func(state int, r *Random, y int) bool { return state == target && r.NextFloat() < probability }, nil
	case "tag_match":
		name, err := jString(o, "tag")
		if err != nil {
			return nil, err
		}
		set, err := b.Tag(name)
		if err != nil {
			return nil, err
		}
		mask := blockMask(b, set)
		return func(state int, r *Random, y int) bool { return mask[b.BlockOf[state]] }, nil
	case "height_match":
		min, err := jInt(o, "min_inclusive")
		if err != nil {
			return nil, err
		}
		max, err := jInt(o, "max_inclusive")
		if err != nil {
			return nil, err
		}
		return func(state int, r *Random, y int) bool { return y >= min && y <= max }, nil
	case "not":
		rule, err := parseRuleTest(b, o["rule"])
		if err != nil {
			return nil, err
		}
		return func(state int, r *Random, y int) bool { return !rule(state, r, y) }, nil
	case "all_of", "any_of":
		list, err := jArray(o, "rules")
		if err != nil {
			return nil, err
		}
		var rules []RuleTest
		for _, sub := range list {
			rule, err := parseRuleTest(b, sub)
			if err != nil {
				return nil, err
			}
			rules = append(rules, rule)
		}
		if t == "all_of" {
			return func(state int, r *Random, y int) bool {
				for _, rule := range rules {
					if !rule(state, r, y) {
						return false
					}
				}
				return true
			}, nil
		}
		return func(state int, r *Random, y int) bool {
			for _, rule := range rules {
				if rule(state, r, y) {
					return true
				}
			}
			return false
		}, nil
	}
	return nil, fmt.Errorf("unknown rule test type %s", t)
}
