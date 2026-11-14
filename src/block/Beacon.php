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

namespace pocketmine\block;

use pocketmine\block\inventory\BeaconInventory;
use pocketmine\block\tile\Beacon as TileBeacon;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\BeaconActivateSound;
use pocketmine\world\sound\BeaconAmbientSound;
use pocketmine\world\sound\BeaconDeactivateSound;
use pocketmine\world\World;
use function count;
use function min;

/**
 * Beacon. Built on a pyramid of iron, gold, emerald, diamond or netherite blocks (1-4 levels) with nothing opaque
 * above it, it gives the selected powers to the players around it.
 *
 * Behaviour (Minecraft Wiki "Beacon", Bedrock values; dragonfly and PowerNukkitX for the details):
 * - every 80 ticks the pyramid and the sky access are checked and the powers are applied (scheduled block update,
 *   nothing runs on the other ticks);
 * - range 10 + 10 * level blocks horizontally, from range blocks below the beacon up to the top of the world;
 * - duration 10/12/14/16 seconds for levels 1-4 (Bedrock table of the wiki); effects are ambient (faint particles);
 * - only players are affected;
 * - the beam goes through any block that is not fully opaque (glass, leaves...) and through bedrock.
 *
 * The powers are chosen in the beacon window (one payment item); see
 * {@link \pocketmine\inventory\transaction\BeaconPaymentTransaction}.
 */
final class Beacon extends Transparent{
	public const MAX_PYRAMID_LEVEL = 4;
	public const UPDATE_INTERVAL_TICKS = 80;

	private ?Effect $primaryEffect = null;
	private ?Effect $secondaryEffect = null;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileBeacon){
			$map = EffectIdMap::getInstance();
			$this->primaryEffect = $tile->getPrimaryEffect() !== 0 ? $map->fromId($tile->getPrimaryEffect()) : null;
			$this->secondaryEffect = $tile->getSecondaryEffect() !== 0 ? $map->fromId($tile->getSecondaryEffect()) : null;
		}
		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileBeacon){
			$map = EffectIdMap::getInstance();
			$tile->setPrimaryEffect($this->primaryEffect !== null ? $map->toId($this->primaryEffect) : 0);
			$tile->setSecondaryEffect($this->secondaryEffect !== null ? $map->toId($this->secondaryEffect) : 0);
		}
	}

	public function getPrimaryEffect() : ?Effect{ return $this->primaryEffect; }

	/** @return $this */
	public function setPrimaryEffect(?Effect $primaryEffect) : self{
		$this->primaryEffect = $primaryEffect;
		return $this;
	}

	public function getSecondaryEffect() : ?Effect{ return $this->secondaryEffect; }

	/** @return $this */
	public function setSecondaryEffect(?Effect $secondaryEffect) : self{
		$this->secondaryEffect = $secondaryEffect;
		return $this;
	}

	public function getLightLevel() : int{
		return 15;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$player->setCurrentWindow(new BeaconInventory($this->position));
		}
		return true;
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileBeacon){
			return;
		}
		//several update chains may be started (tile placed, loaded...): keep a single one every 80 ticks
		$now = $world->getServer()->getTick();
		$last = $tile->getLastUpdateTick();
		if($last >= 0 && $now - $last < self::UPDATE_INTERVAL_TICKS){
			$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL_TICKS - ($now - $last));
			return;
		}
		$tile->setLastUpdateTick($now);
		$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL_TICKS);

		$this->updateBeacon($tile);
	}

	/**
	 * Recomputes the pyramid level and the sky access, plays the beam sounds and gives the powers to the players in
	 * range. Called every 80 ticks.
	 */
	public function updateBeacon(TileBeacon $tile) : void{
		$world = $this->position->getWorld();
		$level = self::calculatePyramidLevel($world, $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		$active = $level > 0 && $this->hasSkyAccess();
		$wasActive = $tile->isActive();
		$tile->setPyramidLevel($level);
		$tile->setActive($active);

		if($active && !$wasActive){
			if(!$tile->isLoadedFromSave()){
				$world->addSound($this->position->add(0.5, 0.5, 0.5), new BeaconActivateSound());
			}
		}elseif(!$active && $wasActive){
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new BeaconDeactivateSound());
		}elseif($active){
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new BeaconAmbientSound());
		}
		$tile->setLoadedFromSave(false);

		if($active){
			$effects = self::createEffects($level, $this->primaryEffect, $this->secondaryEffect);
			if(count($effects) > 0){
				$maxY = $world->getMaxY();
				foreach($world->getPlayers() as $player){
					if($player->isAlive() && self::isInEffectArea($this->position, $level, $player->getPosition(), $maxY)){
						self::applyEffects($player, $effects);
					}
				}
			}
		}
	}

	/**
	 * Returns whether the beam reaches the sky: every block above the beacon must let it through.
	 */
	public function hasSkyAccess() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return false;
		}
		$highest = $world->getHighestBlockAt($x, $z);
		if($highest === null || $highest <= $y){
			return true;
		}
		for($checkY = $y + 1, $top = min($highest, $world->getMaxY() - 1); $checkY <= $top; ++$checkY){
			if(!self::letsBeamThrough($world->getBlockAt($x, $checkY, $z))){
				return false;
			}
		}
		return true;
	}

	/**
	 * The beam stops at fully opaque blocks, except bedrock (wiki); glass, leaves, slabs, water... let it through.
	 */
	public static function letsBeamThrough(Block $block) : bool{
		return $block->getLightFilter() < 15 || $block->getTypeId() === BlockTypeIds::BEDROCK;
	}

	/**
	 * Blocks a beacon pyramid can be built with.
	 */
	public static function isPyramidBlock(Block $block) : bool{
		return match($block->getTypeId()){
			BlockTypeIds::IRON,
			BlockTypeIds::GOLD,
			BlockTypeIds::EMERALD,
			BlockTypeIds::DIAMOND,
			BlockTypeIds::NETHERITE => true,
			default => false
		};
	}

	/**
	 * Items accepted as payment in the beacon window.
	 */
	public static function isPaymentItem(Item $item) : bool{
		return match($item->getTypeId()){
			ItemTypeIds::IRON_INGOT,
			ItemTypeIds::GOLD_INGOT,
			ItemTypeIds::EMERALD,
			ItemTypeIds::DIAMOND,
			ItemTypeIds::NETHERITE_INGOT => true,
			default => false
		};
	}

	/**
	 * Returns the number of complete pyramid layers (0-4) below a beacon at the given position: layer N is the
	 * (2N+1)x(2N+1) square N blocks below the beacon, and every layer above it must be complete. Block types may be
	 * mixed.
	 */
	public static function calculatePyramidLevel(World $world, int $x, int $y, int $z) : int{
		for($level = 1; $level <= self::MAX_PYRAMID_LEVEL; ++$level){
			$layerY = $y - $level;
			if($layerY < $world->getMinY()){
				return $level - 1;
			}
			for($dx = -$level; $dx <= $level; ++$dx){
				for($dz = -$level; $dz <= $level; ++$dz){
					if(!self::isPyramidBlock($world->getBlockAt($x + $dx, $layerY, $z + $dz))){
						return $level - 1;
					}
				}
			}
		}
		return self::MAX_PYRAMID_LEVEL;
	}

	/**
	 * Pyramid level needed to choose this effect as primary power, or null if it can't be chosen.
	 */
	public static function getPrimaryEffectRequiredLevel(Effect $effect) : ?int{
		return match($effect){
			VanillaEffects::SPEED(), VanillaEffects::HASTE() => 1,
			VanillaEffects::RESISTANCE(), VanillaEffects::JUMP_BOOST() => 2,
			VanillaEffects::STRENGTH() => 3,
			default => null
		};
	}

	public static function isPrimaryEffectAllowed(Effect $effect, int $pyramidLevel) : bool{
		$required = self::getPrimaryEffectRequiredLevel($effect);
		return $required !== null && $pyramidLevel >= $required;
	}

	/**
	 * Returns whether the powers can be chosen with a pyramid of the given level. A primary power is required; the
	 * secondary power needs a full pyramid (level 4) and is either regeneration or the primary power again (level II).
	 */
	public static function isValidSelection(?Effect $primary, ?Effect $secondary, int $pyramidLevel) : bool{
		if($primary === null || !self::isPrimaryEffectAllowed($primary, $pyramidLevel)){
			return false;
		}
		if($secondary === null){
			return true;
		}
		return $pyramidLevel >= self::MAX_PYRAMID_LEVEL && ($secondary === $primary || $secondary === VanillaEffects::REGENERATION());
	}

	/**
	 * Horizontal range of the powers, in blocks from the beacon.
	 */
	public static function getEffectRange(int $pyramidLevel) : int{
		return 10 + 10 * $pyramidLevel;
	}

	/**
	 * Duration of the powers in ticks: 10, 12, 14 and 16 seconds for levels 1-4 (Bedrock).
	 */
	public static function getEffectDuration(int $pyramidLevel) : int{
		return (8 + 2 * $pyramidLevel) * 20;
	}

	/**
	 * The area covers the range around the beacon horizontally, and from range blocks below it to the top of the
	 * world.
	 */
	public static function isInEffectArea(Vector3 $beacon, int $pyramidLevel, Vector3 $position, int $worldMaxY) : bool{
		$range = self::getEffectRange($pyramidLevel);
		return
			$position->x >= $beacon->x - $range && $position->x <= $beacon->x + 1 + $range &&
			$position->z >= $beacon->z - $range && $position->z <= $beacon->z + 1 + $range &&
			$position->y >= $beacon->y - $range && $position->y <= $worldMaxY + $range;
	}

	/**
	 * Returns the effects given by a beacon with this pyramid and these powers. Powers that the pyramid is too small
	 * for are left out.
	 *
	 * @return EffectInstance[]
	 * @phpstan-return list<EffectInstance>
	 */
	public static function createEffects(int $pyramidLevel, ?Effect $primary, ?Effect $secondary) : array{
		if($pyramidLevel <= 0 || $primary === null || !self::isPrimaryEffectAllowed($primary, $pyramidLevel)){
			return [];
		}
		$duration = self::getEffectDuration($pyramidLevel);
		$amplifier = 0;
		$effects = [];
		if($pyramidLevel >= self::MAX_PYRAMID_LEVEL && $secondary !== null){
			if($secondary === $primary){
				$amplifier = 1;
			}elseif($secondary === VanillaEffects::REGENERATION()){
				$effects[] = new EffectInstance($secondary, $duration, 0, true, true);
			}
		}
		$effects[] = new EffectInstance($primary, $duration, $amplifier, true, true);
		return $effects;
	}

	/**
	 * @param EffectInstance[] $effects
	 */
	public static function applyEffects(Living $entity, array $effects) : void{
		$manager = $entity->getEffects();
		foreach($effects as $effect){
			$manager->add(clone $effect);
		}
	}
}
