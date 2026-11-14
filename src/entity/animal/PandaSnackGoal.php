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

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\world\format\Chunk;
use function floor;
use function intdiv;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock behavior.snacking of pandas: an adult panda walks to bamboo or cake lying within 8 blocks (looked for once a
 * second, Java PandaSitGoal), takes one, sits down and eats it (see {@link Panda::tickEating()}); an item handed over
 * by a player (minecraft:giveable) is eaten the same way. It may get up before finishing (snacking_stop_chance per
 * tick: 0.001334, lazy pandas 0.0011), dropping what is left, and snacks again after snacking_cooldown_min to
 * snacking_cooldown seconds (20-22.5 s, lazy pandas 10-17.5 s).
 *
 * @internal
 */
final class PandaSnackGoal extends Goal{
	/** Ticks between two searches for snacks lying around. */
	private const SEARCH_INTERVAL = 20;
	/** How far snacks lying around are noticed (Java PandaSitGoal: the box grown by 6, walked to from 8). */
	private const SEARCH_RANGE = 8.0;
	/** The panda gives up on a snack it couldn't reach in this many ticks. */
	private const GIVE_UP_TICKS = 200;

	private ?ItemEntity $target = null;
	private int $searchCooldown = 0;
	private int $cooldownUntil = 0;
	private int $ticksRunning = 0;
	private bool $stopping = false;

	public function __construct(private Panda $panda){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		$panda = $this->panda;
		if($panda->isBaby() || $panda->isInWater()){
			return false;
		}
		if($panda->hasSnack()){
			//handed over by a player: eaten right away
			return !$panda->isScared() && !$panda->isLyingDown() && !$panda->isRolling();
		}
		if($panda->getAiTick() < $this->cooldownUntil || !$panda->canPerformAction() || --$this->searchCooldown > 0){
			return false;
		}
		$this->searchCooldown = intdiv(self::SEARCH_INTERVAL, Mob::GOAL_EVALUATION_INTERVAL);
		$this->target = $this->findSnack();
		return $this->target !== null;
	}

	/**
	 * Returns the nearest snack lying within range: the chunk entity lists around the panda are walked directly.
	 */
	private function findSnack() : ?ItemEntity{
		$loc = $this->panda->getLiveLocation();
		$world = $this->panda->getWorld();
		$r = self::SEARCH_RANGE;
		$best = null;
		$bestDistance = $r * $r;
		$minChunkX = ((int) floor($loc->x - $r)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkX = ((int) floor($loc->x + $r)) >> Chunk::COORD_BIT_SIZE;
		$minChunkZ = ((int) floor($loc->z - $r)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ((int) floor($loc->z + $r)) >> Chunk::COORD_BIT_SIZE;
		for($chunkX = $minChunkX; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				foreach($world->getChunkEntities($chunkX, $chunkZ) as $entity){
					if(!$entity instanceof ItemEntity){
						continue;
					}
					$bb = $entity->boundingBox;
					$dx = ($bb->minX + $bb->maxX) * 0.5 - $loc->x;
					$dy = $bb->minY - $loc->y;
					$dz = ($bb->minZ + $bb->maxZ) * 0.5 - $loc->z;
					$distance = $dx * $dx + $dy * $dy + $dz * $dz;
					if($distance <= $bestDistance && Panda::isSnackItem($entity->getItem()) && PickupItemsGoal::isAvailable($entity)){
						$best = $entity;
						$bestDistance = $distance;
					}
				}
			}
		}
		return $best;
	}

	public function canContinue() : bool{
		$panda = $this->panda;
		if($this->stopping || $panda->isInWater() || $panda->isScared()){
			return false;
		}
		if($panda->hasSnack()){
			return true;
		}
		$target = $this->target;
		return $target !== null && $this->ticksRunning < self::GIVE_UP_TICKS && PickupItemsGoal::isAvailable($target) && $target->getWorld() === $panda->getWorld();
	}

	public function start() : void{
		$this->ticksRunning = 0;
		$this->stopping = false;
		if($this->panda->hasSnack()){
			$this->target = null;
			$this->panda->sitDown();
		}elseif($this->target !== null){
			$this->panda->getNavigation()->moveToEntity($this->target, 1.2, 0.5);
		}
	}

	public function stop() : void{
		$panda = $this->panda;
		$this->target = null;
		$panda->getNavigation()->stop();
		$panda->dropSnack();
		$panda->setSitting(false);
		$lazy = $panda->getPersonality() === Panda::GENE_LAZY;
		//snacking_cooldown_min to snacking_cooldown seconds
		$this->cooldownUntil = $panda->getAiTick() + ($lazy ? mt_rand(200, 350) : mt_rand(400, 450));
	}

	public function tick() : void{
		$panda = $this->panda;
		++$this->ticksRunning;
		if($panda->hasSnack()){
			if(!$panda->isSitting()){
				$panda->sitDown();
			}
			$stopChance = $panda->getPersonality() === Panda::GENE_LAZY ? 0.0011 : 0.001334;
			if(mt_rand() / mt_getrandmax() < $stopChance){
				$this->stopping = true;
			}
			return;
		}
		$target = $this->target;
		if($target === null){
			return;
		}
		//touching: the item's box grown by 1 block sideways and half a block up and down meets the panda's
		$item = $target->boundingBox;
		$box = $panda->boundingBox;
		if(
			$item->minX - 1.0 < $box->maxX && $item->maxX + 1.0 > $box->minX &&
			$item->minY - 0.5 < $box->maxY && $item->maxY + 0.5 > $box->minY &&
			$item->minZ - 1.0 < $box->maxZ && $item->maxZ + 1.0 > $box->minZ
		){
			$this->target = null;
			if($panda->takeSnack($target)){
				$panda->getNavigation()->stop();
				$panda->sitDown();
			}
		}elseif($this->ticksRunning % 10 === 0){
			$panda->getNavigation()->moveToEntity($target, 1.2, 0.5);
		}
	}
}
