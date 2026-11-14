package decor

import (
	"strings"
	"sync"
)

// Survival rules of feature/block/BlockSurvival.php.
const (
	survNone = iota
	survVegetation
	survDry
	survAzalea
	survWitherRose
	survMushroom
	survDoublePlant
	survSugarCane
	survCactus
	survSeagrass
	survTallSeagrass
	survGrowsUp
	survGrowsDown
	survSeaPickle
	survLilyPad
	survSturdyBelow
	survMangrovePropagule
	survSporeBlossom
	survSturdyAbove
	survMultiface
	survSnowLayer
	survBamboo
	survCocoa
	survSmallDripleaf
	survBigDripleaf
	survBigDripleafStem
	survMossyCarpet
	survCarpet
	survFacingSupport
	survSpeleothem
	survCactusFlower
	survVine
	survSupportTag
)

// Survival is BlockSurvival (canSurvive() of the blocks features place). Shared and read-only.
type Survival struct {
	b           *Blocks
	rules       []int8
	supportTags map[int][]bool

	sugarCane, cactus, bigDripleaf, bigDripleafStem, snow int
}

var (
	survivalMu    sync.Mutex
	survivalCache = map[*Blocks]*Survival{}
)

// SurvivalOf returns the survival rules of a block model (built once).
func SurvivalOf(b *Blocks) *Survival {
	survivalMu.Lock()
	defer survivalMu.Unlock()
	if s, ok := survivalCache[b]; ok {
		return s
	}
	s := &Survival{b: b, rules: make([]int8, len(b.Names)), supportTags: map[int][]bool{}}
	s.sugarCane = b.MustBlock("sugar_cane")
	s.cactus = b.MustBlock("cactus")
	s.bigDripleaf = b.MustBlock("big_dripleaf")
	s.bigDripleafStem = b.MustBlock("big_dripleaf_stem")
	s.snow = b.MustBlock("snow")
	for index, full := range b.Names {
		name := ""
		if len(full) > 10 {
			name = full[10:]
		}
		rule := survivalRuleFor(name)
		if rule == survNone && (name == "crimson_roots" || name == "warped_roots" || name == "nether_sprouts" || name == "crimson_fungus" || name == "warped_fungus") && b.HasTag("supports_"+name) {
			rule = survSupportTag
			s.supportTags[index] = b.TagMask("supports_" + name)
		}
		s.rules[index] = int8(rule)
	}
	survivalCache[b] = s
	return s
}

func survivalRuleFor(name string) int {
	switch name {
	case "short_grass", "fern", "bush", "firefly_bush", "sweet_berry_bush", "pink_petals",
		"wildflowers", "dandelion", "golden_dandelion", "poppy", "blue_orchid", "allium", "azure_bluet",
		"red_tulip", "orange_tulip", "white_tulip", "pink_tulip", "oxeye_daisy", "cornflower",
		"lily_of_the_valley", "torchflower", "open_eyeblossom", "closed_eyeblossom", "red_shrub":
		return survVegetation
	case "mangrove_propagule":
		return survMangrovePropagule
	case "dead_bush", "short_dry_grass", "tall_dry_grass":
		return survDry
	case "azalea", "flowering_azalea":
		return survAzalea
	case "wither_rose":
		return survWitherRose
	case "brown_mushroom", "red_mushroom":
		return survMushroom
	case "tall_grass", "large_fern", "sunflower", "lilac", "rose_bush", "peony", "pitcher_plant":
		return survDoublePlant
	case "sugar_cane":
		return survSugarCane
	case "cactus":
		return survCactus
	case "seagrass":
		return survSeagrass
	case "tall_seagrass":
		return survTallSeagrass
	case "kelp", "kelp_plant", "twisting_vines", "twisting_vines_plant":
		return survGrowsUp
	case "cave_vines", "cave_vines_plant", "weeping_vines", "weeping_vines_plant":
		return survGrowsDown
	case "sea_pickle":
		return survSeaPickle
	case "lily_pad":
		return survLilyPad
	case "leaf_litter":
		return survSturdyBelow
	case "spore_blossom":
		return survSporeBlossom
	case "hanging_roots":
		return survSturdyAbove
	case "glow_lichen", "sculk_vein", "resin_clump":
		return survMultiface
	case "snow":
		return survSnowLayer
	case "bamboo", "bamboo_sapling":
		return survBamboo
	case "cocoa":
		return survCocoa
	case "small_dripleaf":
		return survSmallDripleaf
	case "big_dripleaf":
		return survBigDripleaf
	case "big_dripleaf_stem":
		return survBigDripleafStem
	case "pale_moss_carpet":
		return survMossyCarpet
	case "moss_carpet":
		return survCarpet
	case "shelf_mushroom", "small_amethyst_bud", "medium_amethyst_bud", "large_amethyst_bud", "amethyst_cluster":
		return survFacingSupport
	case "pointed_dripstone", "sulfur_spike":
		return survSpeleothem
	case "cactus_flower":
		return survCactusFlower
	case "vine":
		return survVine
	}
	if strings.HasSuffix(name, "_sapling") {
		return survVegetation
	}
	if strings.HasSuffix(name, "_carpet") {
		return survCarpet
	}
	if strings.Contains(name, "coral") {
		if strings.HasSuffix(name, "_wall_fan") {
			return survFacingSupport
		}
		if strings.HasSuffix(name, "_coral") || strings.HasSuffix(name, "_coral_fan") {
			return survSturdyBelow
		}
	}
	return survNone
}

func (s *Survival) tagged(state int, tag string) bool { return s.b.IsTagged(state, tag) }

func (s *Survival) halfLowerBelow(l *Region, state, x, y, z int) bool {
	b := s.b
	below := l.GetState(x, y-1, z)
	v, _ := b.GetProperty(below, "half")
	return b.BlockOf[below] == b.BlockOf[state] && v == "lower"
}

func (s *Survival) isUpperHalf(state int) bool {
	v, _ := s.b.GetProperty(state, "half")
	return v == "upper"
}

// CanSurvive is BlockSurvival::canSurvive().
func (s *Survival) CanSurvive(l *Region, state, x, y, z int) bool {
	b := s.b
	switch int(s.rules[b.BlockOf[state]]) {
	case survNone:
		return true
	case survVegetation:
		return s.tagged(l.GetState(x, y-1, z), "supports_vegetation")
	case survDry:
		return s.tagged(l.GetState(x, y-1, z), "supports_dry_vegetation")
	case survAzalea:
		return s.tagged(l.GetState(x, y-1, z), "supports_azalea")
	case survWitherRose:
		return s.tagged(l.GetState(x, y-1, z), "supports_wither_rose")
	case survMushroom:
		below := l.GetState(x, y-1, z)
		if s.tagged(below, "overrides_mushroom_light_requirement") {
			return true
		}
		return s.IsSolidRender(below)
	case survDoublePlant:
		if s.isUpperHalf(state) {
			return s.halfLowerBelow(l, state, x, y, z)
		}
		return s.tagged(l.GetState(x, y-1, z), "supports_vegetation")
	case survSugarCane:
		below := l.GetState(x, y-1, z)
		if int(b.BlockOf[below]) == s.sugarCane {
			return true
		}
		if !s.tagged(below, "supports_sugar_cane") {
			return false
		}
		for _, d := range PlaneHorizontal {
			n := l.GetState(x+StepX[d], y-1, z+StepZ[d])
			if b.FluidInTag(n, "supports_sugar_cane_adjacently") || s.tagged(n, "supports_sugar_cane_adjacently") {
				return true
			}
		}
		return false
	case survCactus:
		for _, d := range PlaneHorizontal {
			n := l.GetState(x+StepX[d], y, z+StepZ[d])
			if b.IsSolid(n) || b.IsLava(n) {
				return false
			}
		}
		below := l.GetState(x, y-1, z)
		if int(b.BlockOf[below]) != s.cactus && !s.tagged(below, "supports_cactus") {
			return false
		}
		above := int(b.BlockOf[l.GetState(x, y+1, z)])
		return !(above == b.WaterBlock || above == b.LavaBlock)
	case survSeagrass:
		return s.seagrassSupport(l.GetState(x, y-1, z))
	case survTallSeagrass:
		if s.isUpperHalf(state) {
			return s.halfLowerBelow(l, state, x, y, z)
		}
		here := l.GetState(x, y, z)
		return s.seagrassSupport(l.GetState(x, y-1, z)) && b.IsWater(here) && b.FluidIsSource(here)
	case survGrowsUp, survGrowsDown:
		up := int(s.rules[b.BlockOf[state]]) == survGrowsUp
		ay := y + 1
		if up {
			ay = y - 1
		}
		attached := l.GetState(x, ay, z)
		name := b.BlockName(state)
		if strings.HasPrefix(name, "minecraft:kelp") && s.tagged(attached, "cannot_support_kelp") {
			return false
		}
		head := strings.TrimSuffix(name, "_plant")
		attachedName := b.BlockName(attached)
		if attachedName == head || attachedName == head+"_plant" {
			return true
		}
		if up {
			return b.IsFaceSturdy(attached, Up)
		}
		return b.IsFaceSturdy(attached, Down)
	case survSeaPickle:
		below := l.GetState(x, y-1, z)
		return b.HasCollision(below) && (s.topFaceNotEmpty(below) || b.IsFaceSturdy(below, Up))
	case survLilyPad:
		below := l.GetState(x, y-1, z)
		here := l.GetState(x, y, z)
		return (b.FluidInTag(below, "supports_lily_pad") || s.tagged(below, "supports_lily_pad")) && b.Fluid(here) == FluidNone
	case survSturdyBelow:
		return b.IsFaceSturdy(l.GetState(x, y-1, z), Up)
	case survMangrovePropagule:
		if b.GetBool(state, "hanging") {
			return s.tagged(l.GetState(x, y+1, z), "supports_hanging_mangrove_propagule")
		}
		return s.tagged(l.GetState(x, y-1, z), "supports_mangrove_propagule")
	case survSporeBlossom:
		return b.IsFaceSturdy(l.GetState(x, y+1, z), Down) && !l.IsWaterAt(x, y, z)
	case survSturdyAbove:
		return b.IsFaceSturdy(l.GetState(x, y+1, z), Down)
	case survMultiface:
		any := false
		for d, face := range DirNames {
			if v, _ := b.GetProperty(state, face); v == "true" {
				if !s.CanAttachTo(l, x, y, z, d) {
					return false
				}
				any = true
			}
		}
		return any
	case survSnowLayer:
		below := l.GetState(x, y-1, z)
		if s.tagged(below, "cannot_support_snow_layer") {
			return false
		}
		if s.tagged(below, "support_override_snow_layer") {
			return true
		}
		layers, _ := b.GetProperty(below, "layers")
		return b.IsFaceSturdy(below, Up) || (int(b.BlockOf[below]) == s.snow && layers == "8")
	case survBamboo:
		return s.tagged(l.GetState(x, y-1, z), "supports_bamboo")
	case survCocoa:
		name, ok := b.GetProperty(state, "facing")
		if !ok {
			name = "north"
		}
		facing, err := directionFromName(name)
		if err != nil {
			panic(err)
		}
		return s.tagged(l.GetState(x+StepX[facing], y, z+StepZ[facing]), "supports_cocoa")
	case survSmallDripleaf:
		if s.isUpperHalf(state) {
			return s.halfLowerBelow(l, state, x, y, z)
		}
		below := l.GetState(x, y-1, z)
		if s.tagged(below, "supports_small_dripleaf") {
			return true
		}
		here := l.GetState(x, y, z)
		return b.IsWater(here) && b.FluidIsSource(here) && s.tagged(below, "supports_vegetation")
	case survBigDripleaf:
		below := l.GetState(x, y-1, z)
		bb := int(b.BlockOf[below])
		return bb == s.bigDripleaf || bb == s.bigDripleafStem || s.tagged(below, "supports_big_dripleaf")
	case survBigDripleafStem:
		below := l.GetState(x, y-1, z)
		above := l.GetState(x, y+1, z)
		bb := int(b.BlockOf[below])
		a := int(b.BlockOf[above])
		return (bb == s.bigDripleafStem || s.tagged(below, "supports_big_dripleaf")) && (a == s.bigDripleafStem || a == s.bigDripleaf)
	case survMossyCarpet:
		below := l.GetState(x, y-1, z)
		if b.GetBool(state, "bottom") {
			return !b.IsAir(below)
		}
		return b.BlockOf[below] == b.BlockOf[state] && b.GetBool(below, "bottom")
	case survCarpet:
		return !b.IsAir(l.GetState(x, y-1, z))
	case survFacingSupport:
		name, ok := b.GetProperty(state, "facing")
		if !ok {
			name = "up"
		}
		facing, err := directionFromName(name)
		if err != nil {
			panic(err)
		}
		o := Opposite[facing]
		return b.IsFaceSturdy(l.GetState(x+StepX[o], y+StepY[o], z+StepZ[o]), facing)
	case survSpeleothem:
		dir := Down
		if v, _ := b.GetProperty(state, "vertical_direction"); v == "up" {
			dir = Up
		}
		return speleothemValidPlacement(l, b, x, y, z, dir, int(b.BlockOf[state]))
	case survCactusFlower:
		below := l.GetState(x, y-1, z)
		return s.tagged(below, "support_override_cactus_flower") || b.IsFaceSturdy(below, Up)
	case survVine:
		for _, d := range [5]int{Up, North, South, West, East} {
			if v, _ := b.GetProperty(state, DirNames[d]); v == "true" && s.CanAttachTo(l, x, y, z, d) {
				return true
			}
		}
		return false
	case survSupportTag:
		return s.supportTags[int(b.BlockOf[state])][b.BlockOf[l.GetState(x, y-1, z)]]
	}
	return true
}

func (s *Survival) seagrassSupport(below int) bool {
	return s.b.IsFaceSturdy(below, Up) && !s.tagged(below, "cannot_support_seagrass")
}

func (s *Survival) topFaceNotEmpty(state int) bool {
	return s.b.HasCollision(state) && s.b.CollisionMaxY(state) >= 1.0
}

// CanAttachTo is MultifaceBlock.canAttachTo(): the neighbour face towards the block is full.
func (s *Survival) CanAttachTo(l *Region, x, y, z, d int) bool {
	n := l.GetState(x+StepX[d], y+StepY[d], z+StepZ[d])
	return s.b.IsFaceSturdy(n, Opposite[d])
}

// IsSolidRender approximates BlockState.isSolidRender(): a full cube that occludes (not leaves, glass or ice).
func (s *Survival) IsSolidRender(state int) bool {
	b := s.b
	if !b.IsFullCube(state) || s.tagged(state, "leaves") || s.tagged(state, "ice") {
		return false
	}
	name := b.BlockName(state)
	return !strings.Contains(name, "glass") && !strings.HasSuffix(name, "_ice") && name != "minecraft:ice" && !strings.Contains(name, "slime") && !strings.Contains(name, "honey_block")
}

// speleothemWithDirection is SpeleothemRules::isWithDirection().
func speleothemWithDirection(b *Blocks, state, direction int) bool {
	v, _ := b.GetProperty(state, "vertical_direction")
	want := "down"
	if direction == Up {
		want = "up"
	}
	return b.IsTagged(state, "speleothems") && v == want
}

// speleothemValidPlacement is SpeleothemRules::isValidPlacement() (block < 0: any speleothem block).
func speleothemValidPlacement(l *Region, b *Blocks, x, y, z, tipDirection, block int) bool {
	by := y + 1
	if tipDirection == Up {
		by = y - 1
	}
	behind := l.GetState(x, by, z)
	if b.IsFaceSturdy(behind, tipDirection) {
		return true
	}
	return speleothemWithDirection(b, behind, tipDirection) && (block < 0 || int(b.BlockOf[behind]) == block)
}
