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

use pocketmine\block\tile\MonsterSpawner as TileMonsterSpawner;
use pocketmine\block\utils\SupportType;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\SpawnReason;
use pocketmine\item\Item;
use pocketmine\item\SpawnEgg;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\particle\MobSpawnParticle;
use pocketmine\world\World;
use function count;
use function floor;
use function mt_getrandmax;
use function mt_rand;

/**
 * Monster spawner. While a player (not a spectator) is within RequiredPlayerRange (16) blocks, the delay counts down;
 * then SpawnCount (4) attempts each pick a spot within SpawnRange (4) blocks horizontally and one block up or down,
 * and spawn the mob there if its box is free of blocks and liquids, monsters are allowed (not Peaceful) and it is dark
 * enough for monsters (light 7 or less, 11 in the Nether). Nothing spawns while MaxNearbyEntities (6) mobs of the
 * type are within SpawnRange of the spawner. After a spawn the delay is drawn again between MinSpawnDelay (200) and
 * MaxSpawnDelay (800) ticks (vanilla BaseSpawner.serverTick). The mobs despawn like natural ones and get the random
 * spawn variants (baby zombies, jockeys: {@link Jockeys::onMobSpawned()}).
 */
class MonsterSpawner extends Transparent{
	/** Ticks between two checks when no player is near. */
	private const IDLE_CHECK_TICKS = 20;
	public const MONSTER_LIGHT_LIMIT = 7;
	public const NETHER_MONSTER_LIGHT_LIMIT = 11;

	/**
	 * Using a spawn egg on a spawner sets the mob it shows (and would spawn). The egg is consumed outside creative.
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$entityTypeId = $item instanceof SpawnEgg ? $item->getEntityTypeId() : null;
		if($entityTypeId === null){
			return false;
		}
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileMonsterSpawner){
			return false;
		}
		if($tile->getEntityTypeId() !== $entityTypeId){
			$tile->setEntityTypeId($entityTypeId);
			$world->setBlock($this->position, $this); //resend the tile to viewers
		}
		$item->pop();
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	protected function getXpDropAmount() : int{
		return mt_rand(15, 43);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileMonsterSpawner){
			return;
		}
		//several update chains may be started (tile loaded, block updates...): keep a single one
		$now = $world->getServer()->getTick();
		$last = $tile->getLastUpdateTick();
		if($last === $now){
			return;
		}
		$tile->setLastUpdateTick($now);
		if(!$this->isPlayerNear($tile)){
			$world->scheduleDelayedBlockUpdate($this->position, self::IDLE_CHECK_TICKS);
			return;
		}
		$world->scheduleDelayedBlockUpdate($this->position, 1);
		$this->tickSpawner($tile);
	}

	private function isPlayerNear(TileMonsterSpawner $tile) : bool{
		$center = $this->position->add(0.5, 0.5, 0.5);
		$rangeSquared = $tile->getRequiredPlayerRange() ** 2;
		foreach($this->position->getWorld()->getPlayers() as $player){
			if(!$player->isSpectator() && $player->isAlive() && $player->getPosition()->distanceSquared($center) < $rangeSquared){
				return true;
			}
		}
		return false;
	}

	private static function resetDelay(TileMonsterSpawner $tile) : void{
		$min = $tile->getMinSpawnDelay();
		$max = $tile->getMaxSpawnDelay();
		$tile->setSpawnDelay($max <= $min ? $min : $min + mt_rand(0, $max - $min - 1));
	}

	/**
	 * Runs the spawner for the elapsed ticks while a player is near. Returns the number of mobs spawned.
	 */
	public function tickSpawner(TileMonsterSpawner $tile, int $elapsed = 1) : int{
		$typeId = $tile->getEntityTypeId();
		if($typeId === ":" || !EntitySpawnRegistry::getInstance()->isRegistered($typeId)){
			return 0;
		}
		if($tile->getSpawnDelay() === -1){
			self::resetDelay($tile);
		}
		if($tile->getSpawnDelay() > 0){
			$tile->setSpawnDelay($tile->getSpawnDelay() - $elapsed > 0 ? $tile->getSpawnDelay() - $elapsed : 0);
			return 0;
		}

		$world = $this->position->getWorld();
		$range = $tile->getSpawnRange();
		$x = $this->position->x;
		$y = $this->position->y;
		$z = $this->position->z;
		$spawned = 0;
		for($i = 0, $count = $tile->getSpawnPerAttempt(); $i < $count; ++$i){
			$sx = $x + (self::randomFloat() - self::randomFloat()) * $range + 0.5;
			$sy = $y + mt_rand(0, 2) - 1;
			$sz = $z + (self::randomFloat() - self::randomFloat()) * $range + 0.5;
			$entity = EntitySpawnRegistry::getInstance()->create($typeId, new Location($sx, $sy, $sz, $world, self::randomFloat() * 360, 0));
			if($entity === null){
				break;
			}
			if(!$this->canSpawnHere($world, $entity)){
				$entity->close();
				continue;
			}
			$nearby = 0;
			$area = new AxisAlignedBB($x - $range, $y - $range, $z - $range, $x + 1 + $range, $y + 1 + $range, $z + 1 + $range);
			foreach($world->getNearbyEntities($area, $entity) as $other){
				if($other::class === $entity::class && !$other->isClosed()){
					++$nearby;
				}
			}
			if($nearby >= $tile->getMaxNearbyEntities()){
				$entity->close();
				self::resetDelay($tile);
				return $spawned;
			}
			if($entity instanceof Mob){
				//spawner mobs despawn like natural ones (vanilla)
				$entity->setPersistent(false);
			}
			if($entity instanceof Living){
				$entity->setSpawnReason(SpawnReason::SPAWNER);
			}
			$entity->spawnToAll();
			//vanilla spawned event: baby zombies, chicken and spider jockeys
			Jockeys::onMobSpawned($entity);
			$size = $entity->getSize();
			$world->addParticle($entity->getPosition(), new MobSpawnParticle((int) ($size->getWidth() * 8), (int) ($size->getHeight() * 8)));
			++$spawned;
		}
		if($spawned > 0){
			self::resetDelay($tile);
		}
		return $spawned;
	}

	private static function randomFloat() : float{
		return mt_rand() / mt_getrandmax();
	}

	private function canSpawnHere(World $world, Entity $entity) : bool{
		$bb = $entity->getBoundingBox();
		if($bb->minY < $world->getMinY() || $bb->maxY >= $world->getMaxY()){
			return false;
		}
		if(count($world->getCollisionBlocks($bb)) > 0){
			return false;
		}
		for($bx = (int) floor($bb->minX); $bx <= (int) floor($bb->maxX); ++$bx){
			for($by = (int) floor($bb->minY); $by <= (int) floor($bb->maxY); ++$by){
				for($bz = (int) floor($bb->minZ); $bz <= (int) floor($bb->maxZ); ++$bz){
					if($world->getBlockAt($bx, $by, $bz) instanceof Liquid){
						return false;
					}
				}
			}
		}
		if($entity instanceof Monster){
			if($world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
				return false;
			}
			$pos = $entity->getPosition();
			$limit = $world->getDimension() === Dimension::NETHER ? self::NETHER_MONSTER_LIGHT_LIMIT : self::MONSTER_LIGHT_LIMIT;
			if($world->getFullLightAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z)) > $limit){
				return false;
			}
		}
		return true;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
