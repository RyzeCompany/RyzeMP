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

namespace pocketmine\entity\ai\navigation;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * What a walking mob's paths may go through: the vanilla Bedrock navigation component of the mob (navigation.walk,
 * navigation.climb, navigation.generic...). {@link NavigationOptions::forEntity()} has the values of the vanilla mobs
 * (behavior pack 1.26.50); {@link PathNavigator::setOptions()} applies them. Spiders' cobweb immunity
 * (block_movement_slowdown_immunity) needs nothing here: no mob's paths avoid cobwebs.
 *
 * How the path finder uses them, as observed on BDS 1.26.52 (a mob chasing its target across a line of blocks with a
 * gap in it):
 * - every mob avoids lava, magma, lit campfires, cactus and sweet berry bushes with berries;
 * - fire is a cost, not a wall: undead mobs without avoid_damage_blocks walk straight through it (zombies, husks,
 *   drowned, zombie villagers and wither skeletons), other mobs go round it through a gap but still walk through it
 *   when there is no way round (vindicators, BDS 1.26.60; vanilla Java DAMAGE_FIRE malus 16), see
 *   {@link NavigationOptions::setWalksThroughFire()} and {@link NavigationOptions::FIRE_COST};
 * - mobs with avoid_damage_blocks also avoid the small sweet berry bushes, and the blocks next to fire, lit campfires,
 *   cactus and sweet berry bushes (not next to lava or magma: a wolf walks between two magma or lava blocks, not
 *   between two fires);
 * - wither roses, powder snow and cobwebs are avoided by no mob (wolves walk into wither roses and die);
 * - every mob gets a cost for nodes next to lava, magma, fire or anything else it avoids (vanilla Java
 *   FIRE_IN_NEIGHBOR / DAMAGING_IN_NEIGHBOR malus 8), so its paths keep clear of them when they can;
 * - avoid_water makes water cost 8 instead of 2: a fox or an ocelot takes a detour of 9 blocks over a bridge rather
 *   than swim 3 blocks, and swims when there is no way round; a wolf swims straight across;
 * - striders walk on the surface of lava, mobs with can_path_over_lava wade through lava with a floor below.
 */
final class NavigationOptions{
	/** Extra cost of a path node next to something the mob avoids (vanilla Java malus of FIRE_IN_NEIGHBOR). */
	public const DANGER_MARGIN_COST = 8.0;
	/** Cost of a node in lava for mobs which path over lava (vanilla Java malus of LAVA for wither skeletons). */
	public const LAVA_COST = 8.0;
	/**
	 * Cost of a node in fire for mobs which don't walk through fire (vanilla Java malus of DAMAGE_FIRE): with the
	 * danger margin on both sides of a line of fire, a way round costs less unless it is a long detour.
	 */
	public const FIRE_COST = 16.0;
	/** Weight of the distance to the target in the path search (vanilla Java PathFinder.FUDGING). */
	public const HEURISTIC_WEIGHT = 1.5;

	private bool $avoidWater = false;
	private bool $canPathOverWater = true;
	private bool $amphibious = false;
	private bool $canFloat = false;
	private bool $avoidDamageBlocks = false;
	private bool $walksThroughFire = false;
	private bool $canPathOverLava = false;
	private bool $canWalkInLava = false;
	private bool $avoidSun = false;
	private int $blocksToAvoid = 0;

	/**
	 * Returns the navigation of a vanilla mob (behavior pack 1.26.50, components and their default component groups),
	 * or the defaults for other entity types.
	 */
	public static function forEntity(string $networkTypeId) : self{
		$o = new self();
		switch($networkTypeId){
			//avoid_water, avoid_damage_blocks, can_path_over_water
			case EntityIds::ARMADILLO:
			case EntityIds::BLAZE:
			case EntityIds::COW:
			case EntityIds::DONKEY:
			case EntityIds::FOX:
			case EntityIds::GOAT:
			case EntityIds::HOGLIN:
			case EntityIds::HORSE:
			case EntityIds::MOOSHROOM:
			case EntityIds::MULE:
			case EntityIds::OCELOT:
			case EntityIds::PIG:
			case EntityIds::SNIFFER:
			case EntityIds::ZOMBIE_HORSE:
				$o->avoidWater = true;
				$o->avoidDamageBlocks = true;
				if($networkTypeId === EntityIds::GOAT){
					$o->blocksToAvoid = PathBlockTypes::POWDER_SNOW;
				}
				break;
			//avoid_damage_blocks, can_path_over_water
			case EntityIds::CAMEL:
			case EntityIds::CAMEL_HUSK:
			case EntityIds::CHICKEN:
			case EntityIds::LLAMA:
			case EntityIds::POLAR_BEAR:
			case EntityIds::RAVAGER:
			case EntityIds::TRADER_LLAMA:
			case EntityIds::WOLF:
				$o->avoidDamageBlocks = true;
				break;
			//avoid_water, can_path_over_water
			case EntityIds::EVOCATION_ILLAGER:
			case EntityIds::MAGMA_CUBE:
			case EntityIds::RABBIT:
			case EntityIds::SHEEP:
			case EntityIds::SLIME:
			case EntityIds::SULFUR_CUBE:
			case EntityIds::VILLAGER:
			case EntityIds::VILLAGER_V2:
			case EntityIds::WANDERING_TRADER:
			case EntityIds::WITHER:
				$o->avoidWater = true;
				//the old villager is also is_amphibious
				$o->amphibious = $networkTypeId === EntityIds::VILLAGER;
				break;
			case EntityIds::CAT:
			case EntityIds::PANDA:
				//can_float instead of can_path_over_water
				$o->avoidWater = true;
				$o->avoidDamageBlocks = true;
				$o->canFloat = true;
				$o->canPathOverWater = false;
				break;
			case EntityIds::COPPER_GOLEM:
				$o->avoidWater = true;
				$o->avoidDamageBlocks = true;
				$o->canPathOverWater = false;
				break;
			case EntityIds::IRON_GOLEM:
			case EntityIds::ZOGLIN:
				$o->avoidWater = true;
				$o->avoidDamageBlocks = true;
				$o->canPathOverWater = false;
				$o->amphibious = $networkTypeId === EntityIds::ZOGLIN;
				break;
			case EntityIds::SKELETON_HORSE:
				$o->avoidWater = true;
				$o->avoidDamageBlocks = true;
				$o->canPathOverWater = false;
				$o->amphibious = true;
				break;
			case EntityIds::ENDERMAN:
			case EntityIds::SNOW_GOLEM:
				$o->avoidWater = true;
				$o->canPathOverWater = false;
				break;
			case EntityIds::SKELETON:
			case EntityIds::STRAY:
			case EntityIds::BOGGED:
			case EntityIds::PARCHED:
				$o->avoidWater = true;
				$o->canPathOverWater = false;
				$o->amphibious = true;
				$o->avoidSun = true;
				$o->walksThroughFire = true;
				break;
			case EntityIds::WITHER_SKELETON:
				$o->avoidWater = true;
				$o->canPathOverWater = false;
				$o->amphibious = true;
				$o->avoidSun = true;
				$o->walksThroughFire = true;
				$o->canPathOverLava = true;
				break;
			case EntityIds::ZOMBIE_PIGMAN:
				$o->avoidWater = true;
				$o->canPathOverWater = false;
				$o->amphibious = true;
				$o->walksThroughFire = true;
				$o->canPathOverLava = true;
				break;
			case EntityIds::ZOMBIE:
			case EntityIds::HUSK:
			case EntityIds::ZOMBIE_VILLAGER:
			case EntityIds::ZOMBIE_VILLAGER_V2:
				//zombie villagers of the from_abandoned_village group (spawned with abandoned villages, which aren't
				//generated) also have avoid_water and avoid_sun
				$o->canPathOverWater = false;
				$o->amphibious = true;
				$o->walksThroughFire = true;
				break;
			case EntityIds::DROWNED:
				$o->canPathOverWater = false;
				$o->amphibious = true;
				$o->avoidSun = true;
				$o->walksThroughFire = true;
				break;
			case EntityIds::STRIDER:
				$o->avoidWater = true;
				$o->canPathOverWater = false;
				$o->canPathOverLava = true;
				$o->canWalkInLava = true;
				break;
			case EntityIds::WARDEN:
				$o->avoidDamageBlocks = true;
				$o->canPathOverLava = true;
				break;
			case EntityIds::BREEZE:
				$o->canPathOverWater = false;
				$o->blocksToAvoid = PathBlockTypes::TRAPDOOR;
				break;
			case EntityIds::TURTLE:
			case EntityIds::FROG:
			case EntityIds::AXOLOTL:
				$o->avoidDamageBlocks = true;
				$o->amphibious = true;
				$o->canPathOverWater = $networkTypeId !== EntityIds::TURTLE;
				break;
			case EntityIds::CREAKING:
				//spawned by a player; a creaking of a creaking heart walks over lava and into damage blocks (see Creaking)
				$o->avoidDamageBlocks = true;
				break;
			default:
				//can_path_over_water and nothing else (creeper, spider, witch, pillager, vindicator, piglin, endermite...)
				break;
		}
		return $o;
	}

	/** Paths go through water only when there is no other way (vanilla avoid_water: a water node costs 8, not 2). */
	public function avoidsWater() : bool{ return $this->avoidWater; }

	public function setAvoidWater(bool $avoidWater) : self{
		$this->avoidWater = $avoidWater;
		return $this;
	}

	/** Paths may swim across water (vanilla can_path_over_water, true unless set otherwise). */
	public function canPathOverWater() : bool{ return $this->canPathOverWater; }

	public function setCanPathOverWater(bool $canPathOverWater) : self{
		$this->canPathOverWater = $canPathOverWater;
		return $this;
	}

	/**
	 * Paths may go through water along its bottom (vanilla is_amphibious): the mobs without FloatGoal (zombies,
	 * skeletons, the skeleton horse) sink and walk there; for the others this lets their paths into water like
	 * can_path_over_water.
	 */
	public function isAmphibious() : bool{ return $this->amphibious; }

	public function setAmphibious(bool $amphibious) : self{
		$this->amphibious = $amphibious;
		return $this;
	}

	/** Paths may go through water floating on it (vanilla can_float: cats and pandas, which lack can_path_over_water). */
	public function canFloat() : bool{ return $this->canFloat; }

	public function setCanFloat(bool $canFloat) : self{
		$this->canFloat = $canFloat;
		return $this;
	}

	/**
	 * Avoids damage blocks and the blocks next to them (vanilla avoid_damage_blocks), see the class documentation.
	 */
	public function avoidsDamageBlocks() : bool{ return $this->avoidDamageBlocks; }

	public function setAvoidDamageBlocks(bool $avoidDamageBlocks) : self{
		$this->avoidDamageBlocks = $avoidDamageBlocks;
		return $this;
	}

	/**
	 * Paths go through fire and soul fire as through air (BDS: undead mobs without avoid_damage_blocks). Other mobs go
	 * through it at a cost (see {@link NavigationOptions::getCostlyMask()}), mobs which avoid damage blocks never.
	 */
	public function walksThroughFire() : bool{ return $this->walksThroughFire; }

	public function setWalksThroughFire(bool $walksThroughFire) : self{
		$this->walksThroughFire = $walksThroughFire;
		return $this;
	}

	/**
	 * Paths may wade through lava with a floor below, at a cost of {@link NavigationOptions::LAVA_COST} (vanilla
	 * can_path_over_lava: wither skeletons, zombified piglins, wardens).
	 */
	public function canPathOverLava() : bool{ return $this->canPathOverLava; }

	public function setCanPathOverLava(bool $canPathOverLava) : self{
		$this->canPathOverLava = $canPathOverLava;
		return $this;
	}

	/** Paths go over lava as if it were land, on its surface (vanilla can_walk_in_lava: striders). */
	public function canWalkInLava() : bool{ return $this->canWalkInLava; }

	public function setCanWalkInLava(bool $canWalkInLava) : self{
		$this->canWalkInLava = $canWalkInLava;
		return $this;
	}

	/**
	 * Vanilla avoid_sun (the skeletons and drowned): by day, paths of a mob in the shade end before the first node
	 * under the open sky (see {@link PathNavigator}).
	 */
	public function avoidsSun() : bool{ return $this->avoidSun; }

	public function setAvoidSun(bool $avoidSun) : self{
		$this->avoidSun = $avoidSun;
		return $this;
	}

	/**
	 * Flags of {@link PathBlockTypes} paths never go through (vanilla blocks_to_avoid): {@link PathBlockTypes::POWDER_SNOW},
	 * {@link PathBlockTypes::TRAPDOOR}.
	 */
	public function getBlocksToAvoid() : int{ return $this->blocksToAvoid; }

	public function setBlocksToAvoid(int $flags) : self{
		$this->blocksToAvoid = $flags;
		return $this;
	}

	/** Whether paths may have the mob's feet in water. */
	public function canSwim() : bool{
		return $this->canPathOverWater || $this->amphibious || $this->canFloat;
	}

	/**
	 * Flags of the blocks the mob's paths never go into, onto or through (see {@link PathFinder::setDangerMask()}). Mobs
	 * which walk in lava keep LAVA there: they walk on its surface, not through it (lava is their floor, see
	 * {@link PathFinder::setFloorMask()}).
	 */
	public function getDangerMask() : int{
		return
			($this->canPathOverLava && !$this->canWalkInLava ? 0 : PathBlockTypes::LAVA) |
			($this->walksThroughFire || $this->getCostlyMask() !== 0 ? 0 : PathBlockTypes::FIRE) |
			PathBlockTypes::HURTS |
			($this->avoidDamageBlocks ? PathBlockTypes::DAMAGE_BLOCK : 0) |
			$this->blocksToAvoid;
	}

	/**
	 * Flags of the blocks the mob's paths go through only at a cost, when there is no cheaper way: fire for mobs which
	 * neither walk through it nor avoid damage blocks (vindicators..., {@link NavigationOptions::FIRE_COST}). Mobs which
	 * wade through lava keep fire a wall: a search has one cost for the liquids of a mob (see
	 * {@link PathFinder::setLiquid()}), and the vanilla ones walk through fire anyway.
	 */
	public function getCostlyMask() : int{
		return $this->walksThroughFire || $this->avoidDamageBlocks || ($this->canPathOverLava && !$this->canWalkInLava) ? 0 : PathBlockTypes::FIRE;
	}
}
