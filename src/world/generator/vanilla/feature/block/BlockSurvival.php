<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\feature\block;

use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Java's BlockState.canSurvive() for the blocks placed by features (Java 26.3 rules, mostly block tags), used by
 * would_survive predicates and by the features that check it (simple_block, bamboo, root_system...).
 * During worldgen the light level is 0 (the light engine has not run yet), which matters for mushrooms.
 * Blocks without a rule (plain blocks) always survive.
 */
final class BlockSurvival{
	private const VEGETATION = 1;
	private const DRY = 2;
	private const AZALEA = 3;
	private const WITHER_ROSE = 4;
	private const MUSHROOM = 5;
	private const DOUBLE_PLANT = 6;
	private const SUGAR_CANE = 7;
	private const CACTUS = 8;
	private const SEAGRASS = 9;
	private const TALL_SEAGRASS = 10;
	private const GROWS_UP = 11; //kelp, twisting vines: attached below
	private const GROWS_DOWN = 12; //cave vines, weeping vines: attached above
	private const SEA_PICKLE = 13;
	private const LILY_PAD = 14;
	private const STURDY_BELOW = 15; //leaf litter, corals
	private const MANGROVE_PROPAGULE = 16;
	private const SPORE_BLOSSOM = 17;
	private const STURDY_ABOVE = 18; //hanging roots
	private const MULTIFACE = 19;
	private const SNOW_LAYER = 20;
	private const BAMBOO = 21;
	private const COCOA = 22;
	private const SMALL_DRIPLEAF = 23;
	private const BIG_DRIPLEAF = 24;
	private const BIG_DRIPLEAF_STEM = 25;
	private const MOSSY_CARPET = 26;
	private const CARPET = 27;
	private const FACING_SUPPORT = 28; //wall corals, shelf mushrooms, amethyst buds: the block behind is sturdy
	private const SPELEOTHEM = 29;
	private const CACTUS_FLOWER = 30;
	private const VINE = 31;
	private const SUPPORT_TAG = 32; //Nether plants: the block below is in the "supports_<block>" tag

	/** @phpstan-var array<int, int> block index => rule */
	private array $rules = [];
	/** @phpstan-var array<int, string> block index => support tag (SUPPORT_TAG rule) */
	private array $supportTags = [];

	private int $sugarCane;
	private int $cactus;
	private int $bigDripleaf;
	private int $bigDripleafStem;
	private int $snow;

	public function __construct(private JavaBlocks $blocks){
		$this->sugarCane = $blocks->blockIndex("sugar_cane");
		$this->cactus = $blocks->blockIndex("cactus");
		$this->bigDripleaf = $blocks->blockIndex("big_dripleaf");
		$this->bigDripleafStem = $blocks->blockIndex("big_dripleaf_stem");
		$this->snow = $blocks->blockIndex("snow");
		foreach($blocks->names as $index => $fullName){
			$name = substr($fullName, 10);
			$rule = $this->ruleFor($name);
			if($rule === 0 && ($name === "crimson_roots" || $name === "warped_roots" || $name === "nether_sprouts" || $name === "crimson_fungus" || $name === "warped_fungus") && $blocks->hasTag("supports_" . $name)){
				$rule = self::SUPPORT_TAG;
				$this->supportTags[$index] = "supports_" . $name;
			}
			if($rule !== 0){
				$this->rules[$index] = $rule;
			}
		}
	}

	private function ruleFor(string $name) : int{
		switch($name){
			case "short_grass": case "fern": case "bush": case "firefly_bush": case "sweet_berry_bush": case "pink_petals":
			case "wildflowers": case "dandelion": case "golden_dandelion": case "poppy": case "blue_orchid": case "allium": case "azure_bluet":
			case "red_tulip": case "orange_tulip": case "white_tulip": case "pink_tulip": case "oxeye_daisy": case "cornflower":
			case "lily_of_the_valley": case "torchflower": case "open_eyeblossom": case "closed_eyeblossom": case "red_shrub":
			case "mangrove_propagule":
				return $name === "mangrove_propagule" ? self::MANGROVE_PROPAGULE : self::VEGETATION;
			case "dead_bush": case "short_dry_grass": case "tall_dry_grass":
				return self::DRY;
			case "azalea": case "flowering_azalea":
				return self::AZALEA;
			case "wither_rose":
				return self::WITHER_ROSE;
			case "brown_mushroom": case "red_mushroom":
				return self::MUSHROOM;
			case "tall_grass": case "large_fern": case "sunflower": case "lilac": case "rose_bush": case "peony": case "pitcher_plant":
				return self::DOUBLE_PLANT;
			case "sugar_cane":
				return self::SUGAR_CANE;
			case "cactus":
				return self::CACTUS;
			case "seagrass":
				return self::SEAGRASS;
			case "tall_seagrass":
				return self::TALL_SEAGRASS;
			case "kelp": case "kelp_plant": case "twisting_vines": case "twisting_vines_plant":
				return self::GROWS_UP;
			case "cave_vines": case "cave_vines_plant": case "weeping_vines": case "weeping_vines_plant":
				return self::GROWS_DOWN;
			case "sea_pickle":
				return self::SEA_PICKLE;
			case "lily_pad":
				return self::LILY_PAD;
			case "leaf_litter":
				return self::STURDY_BELOW;
			case "spore_blossom":
				return self::SPORE_BLOSSOM;
			case "hanging_roots":
				return self::STURDY_ABOVE;
			case "glow_lichen": case "sculk_vein": case "resin_clump":
				return self::MULTIFACE;
			case "snow":
				return self::SNOW_LAYER;
			case "bamboo": case "bamboo_sapling":
				return self::BAMBOO;
			case "cocoa":
				return self::COCOA;
			case "small_dripleaf":
				return self::SMALL_DRIPLEAF;
			case "big_dripleaf":
				return self::BIG_DRIPLEAF;
			case "big_dripleaf_stem":
				return self::BIG_DRIPLEAF_STEM;
			case "pale_moss_carpet":
				return self::MOSSY_CARPET;
			case "moss_carpet":
				return self::CARPET;
			case "shelf_mushroom": case "small_amethyst_bud": case "medium_amethyst_bud": case "large_amethyst_bud": case "amethyst_cluster":
				return self::FACING_SUPPORT;
			case "pointed_dripstone": case "sulfur_spike":
				return self::SPELEOTHEM;
			case "cactus_flower":
				return self::CACTUS_FLOWER;
			case "vine":
				return self::VINE;
		}
		if(str_ends_with($name, "_sapling")){
			return self::VEGETATION;
		}
		if(str_ends_with($name, "_carpet")){
			return self::CARPET;
		}
		if(str_contains($name, "coral")){
			if(str_ends_with($name, "_wall_fan")){
				return self::FACING_SUPPORT;
			}
			if(str_ends_with($name, "_coral") || str_ends_with($name, "_coral_fan")){
				return self::STURDY_BELOW;
			}
		}
		if(str_starts_with($name, "potted_")){
			return 0;
		}
		return 0;
	}

	public function canSurvive(FeatureLevel $level, int $state, int $x, int $y, int $z) : bool{
		$blocks = $this->blocks;
		$rule = $this->rules[$blocks->blockOf[$state]] ?? 0;
		switch($rule){
			case 0:
				return true;
			case self::VEGETATION:
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_vegetation");
			case self::DRY:
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_dry_vegetation");
			case self::AZALEA:
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_azalea");
			case self::WITHER_ROSE:
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_wither_rose");
			case self::MUSHROOM:
				$below = $level->getState($x, $y - 1, $z);
				if($blocks->isTagged($below, "overrides_mushroom_light_requirement")){
					return true;
				}
				return $this->isSolidRender($below);
			case self::DOUBLE_PLANT:
				if($blocks->getProperty($state, "half") === "upper"){
					$below = $level->getState($x, $y - 1, $z);
					return $blocks->blockOf[$below] === $blocks->blockOf[$state] && $blocks->getProperty($below, "half") === "lower";
				}
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_vegetation");
			case self::SUGAR_CANE:
				$below = $level->getState($x, $y - 1, $z);
				if($blocks->blockOf[$below] === $this->sugarCane){
					return true;
				}
				if(!$blocks->isTagged($below, "supports_sugar_cane")){
					return false;
				}
				foreach(Direction::PLANE_HORIZONTAL as $d){
					$n = $level->getState($x + Direction::STEP_X[$d], $y - 1, $z + Direction::STEP_Z[$d]);
					if($blocks->fluidInTag($n, "supports_sugar_cane_adjacently") || $blocks->isTagged($n, "supports_sugar_cane_adjacently")){
						return true;
					}
				}
				return false;
			case self::CACTUS:
				foreach(Direction::PLANE_HORIZONTAL as $d){
					$n = $level->getState($x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d]);
					if($blocks->isSolid($n) || $blocks->isLava($n)){
						return false;
					}
				}
				$below = $level->getState($x, $y - 1, $z);
				if($blocks->blockOf[$below] !== $this->cactus && !$blocks->isTagged($below, "supports_cactus")){
					return false;
				}
				$above = $level->getState($x, $y + 1, $z);
				return !($blocks->blockOf[$above] === $blocks->waterBlock || $blocks->blockOf[$above] === $blocks->lavaBlock);
			case self::SEAGRASS:
				return $this->seagrassSupport($level->getState($x, $y - 1, $z));
			case self::TALL_SEAGRASS:
				if($blocks->getProperty($state, "half") === "upper"){
					$below = $level->getState($x, $y - 1, $z);
					return $blocks->blockOf[$below] === $blocks->blockOf[$state] && $blocks->getProperty($below, "half") === "lower";
				}
				$here = $level->getState($x, $y, $z);
				return $this->seagrassSupport($level->getState($x, $y - 1, $z)) && $blocks->isWater($here) && $blocks->fluidIsSource($here);
			case self::GROWS_UP:
			case self::GROWS_DOWN:
				$up = $rule === self::GROWS_UP;
				$ay = $up ? $y - 1 : $y + 1;
				$attached = $level->getState($x, $ay, $z);
				$name = $blocks->blockName($state);
				if(str_starts_with($name, "minecraft:kelp") && $blocks->isTagged($attached, "cannot_support_kelp")){
					return false;
				}
				$head = str_ends_with($name, "_plant") ? substr($name, 0, -6) : $name;
				$attachedName = $blocks->blockName($attached);
				if($attachedName === $head || $attachedName === $head . "_plant"){
					return true;
				}
				return $blocks->isFaceSturdy($attached, $up ? Direction::UP : Direction::DOWN);
			case self::SEA_PICKLE:
				$below = $level->getState($x, $y - 1, $z);
				return $blocks->hasCollision($below) && ($this->topFaceNotEmpty($below) || $blocks->isFaceSturdy($below, Direction::UP));
			case self::LILY_PAD:
				$below = $level->getState($x, $y - 1, $z);
				$here = $level->getState($x, $y, $z);
				return ($blocks->fluidInTag($below, "supports_lily_pad") || $blocks->isTagged($below, "supports_lily_pad")) && $blocks->fluid($here) === JavaBlocks::FLUID_NONE;
			case self::STURDY_BELOW:
				return $blocks->isFaceSturdy($level->getState($x, $y - 1, $z), Direction::UP);
			case self::MANGROVE_PROPAGULE:
				if($blocks->getProperty($state, "hanging") === "true"){
					return $blocks->isTagged($level->getState($x, $y + 1, $z), "supports_hanging_mangrove_propagule");
				}
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_mangrove_propagule");
			case self::SPORE_BLOSSOM:
				return $blocks->isFaceSturdy($level->getState($x, $y + 1, $z), Direction::DOWN) && !$level->isWaterAt($x, $y, $z);
			case self::STURDY_ABOVE:
				return $blocks->isFaceSturdy($level->getState($x, $y + 1, $z), Direction::DOWN);
			case self::MULTIFACE:
				$any = false;
				foreach(Direction::NAMES as $d => $face){
					if($blocks->getProperty($state, $face) === "true"){
						if(!$this->canAttachTo($level, $x, $y, $z, $d)){
							return false;
						}
						$any = true;
					}
				}
				return $any;
			case self::SNOW_LAYER:
				$below = $level->getState($x, $y - 1, $z);
				if($blocks->isTagged($below, "cannot_support_snow_layer")){
					return false;
				}
				if($blocks->isTagged($below, "support_override_snow_layer")){
					return true;
				}
				return $blocks->isFaceSturdy($below, Direction::UP) || ($blocks->blockOf[$below] === $this->snow && $blocks->getProperty($below, "layers") === "8");
			case self::BAMBOO:
				return $blocks->isTagged($level->getState($x, $y - 1, $z), "supports_bamboo");
			case self::COCOA:
				$facing = Direction::fromName($blocks->getProperty($state, "facing") ?? "north");
				return $blocks->isTagged($level->getState($x + Direction::STEP_X[$facing], $y, $z + Direction::STEP_Z[$facing]), "supports_cocoa");
			case self::SMALL_DRIPLEAF:
				if($blocks->getProperty($state, "half") === "upper"){
					$below = $level->getState($x, $y - 1, $z);
					return $blocks->blockOf[$below] === $blocks->blockOf[$state] && $blocks->getProperty($below, "half") === "lower";
				}
				$below = $level->getState($x, $y - 1, $z);
				if($blocks->isTagged($below, "supports_small_dripleaf")){
					return true;
				}
				$here = $level->getState($x, $y, $z);
				return $blocks->isWater($here) && $blocks->fluidIsSource($here) && $blocks->isTagged($below, "supports_vegetation");
			case self::BIG_DRIPLEAF:
				$below = $level->getState($x, $y - 1, $z);
				$b = $blocks->blockOf[$below];
				return $b === $this->bigDripleaf || $b === $this->bigDripleafStem || $blocks->isTagged($below, "supports_big_dripleaf");
			case self::BIG_DRIPLEAF_STEM:
				$below = $level->getState($x, $y - 1, $z);
				$above = $level->getState($x, $y + 1, $z);
				$b = $blocks->blockOf[$below];
				$a = $blocks->blockOf[$above];
				return ($b === $this->bigDripleafStem || $blocks->isTagged($below, "supports_big_dripleaf")) && ($a === $this->bigDripleafStem || $a === $this->bigDripleaf);
			case self::MOSSY_CARPET:
				$below = $level->getState($x, $y - 1, $z);
				if($blocks->getProperty($state, "bottom") === "true"){
					return !$blocks->isAir($below);
				}
				return $blocks->blockOf[$below] === $blocks->blockOf[$state] && $blocks->getProperty($below, "bottom") === "true";
			case self::CARPET:
				return !$blocks->isAir($level->getState($x, $y - 1, $z));
			case self::FACING_SUPPORT:
				$facing = Direction::fromName($blocks->getProperty($state, "facing") ?? "up");
				$opposite = Direction::OPPOSITE[$facing];
				return $blocks->isFaceSturdy($level->getState($x + Direction::STEP_X[$opposite], $y + Direction::STEP_Y[$opposite], $z + Direction::STEP_Z[$opposite]), $facing);
			case self::SPELEOTHEM:
				return SpeleothemRules::isValidPlacement($level, $blocks, $x, $y, $z, $blocks->getProperty($state, "vertical_direction") === "up" ? Direction::UP : Direction::DOWN, $blocks->blockOf[$state]);
			case self::CACTUS_FLOWER:
				$below = $level->getState($x, $y - 1, $z);
				return $blocks->isTagged($below, "support_override_cactus_flower") || $blocks->isFaceSturdy($below, Direction::UP);
			case self::VINE:
				foreach([Direction::UP, Direction::NORTH, Direction::SOUTH, Direction::WEST, Direction::EAST] as $d){
					if($blocks->getProperty($state, Direction::NAMES[$d]) === "true" && $this->canAttachTo($level, $x, $y, $z, $d)){
						return true;
					}
				}
				return false;
			case self::SUPPORT_TAG:
				return $blocks->isTagged($level->getState($x, $y - 1, $z), $this->supportTags[$blocks->blockOf[$state]]);
		}
		return true;
	}

	private function seagrassSupport(int $below) : bool{
		return $this->blocks->isFaceSturdy($below, Direction::UP) && !$this->blocks->isTagged($below, "cannot_support_seagrass");
	}

	private function topFaceNotEmpty(int $state) : bool{
		return $this->blocks->hasCollision($state) && $this->blocks->collisionMaxY($state) >= 1.0;
	}

	/**
	 * MultifaceBlock.canAttachTo(): the neighbour face towards the block is full.
	 */
	public function canAttachTo(FeatureLevel $level, int $x, int $y, int $z, int $direction) : bool{
		$neighbour = $level->getState($x + Direction::STEP_X[$direction], $y + Direction::STEP_Y[$direction], $z + Direction::STEP_Z[$direction]);
		return $this->blocks->isFaceSturdy($neighbour, Direction::OPPOSITE[$direction]);
	}

	/**
	 * Approximation of BlockState.isSolidRender(): full cube that occludes (not leaves, glass or ice).
	 */
	public function isSolidRender(int $state) : bool{
		$blocks = $this->blocks;
		if(!$blocks->isFullCube($state) || $blocks->isTagged($state, "leaves") || $blocks->isTagged($state, "ice")){
			return false;
		}
		$name = $blocks->blockName($state);
		return !str_contains($name, "glass") && !str_ends_with($name, "_ice") && $name !== "minecraft:ice" && !str_contains($name, "slime") && !str_contains($name, "honey_block");
	}
}
