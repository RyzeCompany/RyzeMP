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

namespace pocketmine\block\tile;

use pocketmine\data\bedrock\LegacyEntityIdToStringIdMap;
use pocketmine\entity\Location;
use pocketmine\entity\SpawnerEntityRegistry;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\convert\TypeConverter;

/** Native PM5 mob spawner tile. Uses registered entity creators (not save/load factories). */
class MonsterSpawner extends Spawnable{

	private const TAG_LEGACY_ENTITY_TYPE_ID = "EntityId"; //TAG_Int
	private const TAG_ENTITY_TYPE_ID = "EntityIdentifier"; //TAG_String
	private const TAG_SPAWN_DELAY = "Delay"; //TAG_Short
	private const TAG_SPAWN_POTENTIALS = "SpawnPotentials"; //TAG_List<TAG_Compound>
	private const TAG_SPAWN_DATA = "SpawnData"; //TAG_Compound
	private const TAG_MIN_SPAWN_DELAY = "MinSpawnDelay"; //TAG_Short
	private const TAG_MAX_SPAWN_DELAY = "MaxSpawnDelay"; //TAG_Short
	private const TAG_SPAWN_PER_ATTEMPT = "SpawnCount"; //TAG_Short
	private const TAG_MAX_NEARBY_ENTITIES = "MaxNearbyEntities"; //TAG_Short
	private const TAG_REQUIRED_PLAYER_RANGE = "RequiredPlayerRange"; //TAG_Short
	private const TAG_SPAWN_RANGE = "SpawnRange"; //TAG_Short
	private const TAG_ENTITY_WIDTH = "DisplayEntityWidth"; //TAG_Float
	private const TAG_ENTITY_HEIGHT = "DisplayEntityHeight"; //TAG_Float
	private const TAG_ENTITY_SCALE = "DisplayEntityScale"; //TAG_Float

	public const TICK_INTERVAL = 20;
	public const DEFAULT_MIN_SPAWN_DELAY = 200; //ticks
	public const DEFAULT_MAX_SPAWN_DELAY = 800;

	public const DEFAULT_MAX_NEARBY_ENTITIES = 6;
	public const DEFAULT_SPAWN_RANGE = 4; //blocks
	public const DEFAULT_REQUIRED_PLAYER_RANGE = 16;

	/** TODO: replace this with a cached entity or something of that nature */
	private string $entityTypeId = ":";
	/** TODO: deserialize this properly and drop the NBT (PC and PE formats are different, just for fun) */
	private ?ListTag $spawnPotentials = null;
	/** TODO: deserialize this properly and drop the NBT (PC and PE formats are different, just for fun) */
	private ?CompoundTag $spawnData = null;

	private float $displayEntityWidth = 1.0;
	private float $displayEntityHeight = 1.0;
	private float $displayEntityScale = 1.0;

	private int $spawnDelay = self::DEFAULT_MIN_SPAWN_DELAY;
	private int $minSpawnDelay = self::DEFAULT_MIN_SPAWN_DELAY;
	private int $maxSpawnDelay = self::DEFAULT_MAX_SPAWN_DELAY;
	private int $spawnPerAttempt = 1;
	private int $maxNearbyEntities = self::DEFAULT_MAX_NEARBY_ENTITIES;
	private int $spawnRange = self::DEFAULT_SPAWN_RANGE;
	private int $requiredPlayerRange = self::DEFAULT_REQUIRED_PLAYER_RANGE;


	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$world->scheduleDelayedBlockUpdate($pos, self::TICK_INTERVAL);
	}

	/**
	 * Reads old PM4 and Bedrock fields, including IntTags used in old worlds.
	 */
	private static function intTag(CompoundTag $nbt, string $name, int $fallback) : int{
		$tag = $nbt->getTag($name);
		return $tag instanceof IntTag || $tag instanceof ShortTag ? $tag->getValue() : $fallback;
	}

	public function getEntityTypeId() : string{ return $this->entityTypeId; }

	public function setEntityTypeId(string $identifier) : void{
		if($identifier === ""){
			throw new \InvalidArgumentException("Mob spawner entity type must be non-empty");
		}
		$this->entityTypeId = $identifier;
		$this->clearSpawnCompoundCache();
	}

	public function getSpawnDelay() : int{ return $this->spawnDelay; }

	public function getSpawnRange() : int{ return $this->spawnRange; }

	public function tickSpawner() : void{
		if($this->closed || $this->entityTypeId === ":" || !SpawnerEntityRegistry::getInstance()->isRegistered($this->entityTypeId)){
			return;
		}

		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();

		// No active player = no mob generation or countdown.
		$hasPlayer = false;
		$playerRangeSquared = $this->requiredPlayerRange ** 2;
		foreach($world->getPlayers() as $player){
			if($player->getPosition()->distanceSquared($this->position) <= $playerRangeSquared){
				$hasPlayer = true;
				break;
			}
		}
		if(!$hasPlayer){
			return;
		}

		if($this->spawnDelay > 0){
			$this->spawnDelay = max(0, $this->spawnDelay - self::TICK_INTERVAL);
			return;
		}
		// Preserve darkness restrictions of the original TeaSpoon spawner.
		if($world->getBlockLightAt($x, $y, $z) > 7){
			return;
		}

		$range = max(1, $this->spawnRange);
		$maxNearby = max(1, $this->maxNearbyEntities);
		$area = new AxisAlignedBB($x - $range - 2, $y - $range - 2, $z - $range - 2,
			$x + $range + 3, $y + $range + 3, $z + $range + 3);
		$nearby = 0;
		foreach($world->getNearbyEntities($area) as $entity){
			if(!$entity->isFlaggedForDespawn() && $entity::getNetworkTypeId() === $this->entityTypeId && ++$nearby >= $maxNearby){
				$this->randomizeDelay();
				return;
			}
		}

		// Bound work to four attempts per activation to protect TPS.
		for($i = 0, $attempts = min(4, max(1, $this->spawnPerAttempt)); $i < $attempts && $nearby < $maxNearby; ++$i){
			$sx = $x + (mt_rand(-$range * 10, $range * 10) / 10) + 0.5;
			$sy = $y + mt_rand(-1, 1);
			$sz = $z + (mt_rand(-$range * 10, $range * 10) / 10) + 0.5;
			$bx = (int) floor($sx);
			$by = (int) floor($sy);
			$bz = (int) floor($sz);
			if(!$world->isChunkLoaded($bx >> 4, $bz >> 4)){
				continue;
			}
			// Endermen need adequate vertical clearance; do not spawn inside blocks.
			if($world->getBlockAt($bx, $by, $bz)->isSolid() ||
				$world->getBlockAt($bx, $by + 1, $bz)->isSolid() ||
				$world->getBlockAt($bx, $by + 2, $bz)->isSolid()){
				continue;
			}
			$location = Location::fromObject(new Vector3($sx, $sy, $sz), $world, (float) mt_rand(0, 359), 0.0);
			$entity = SpawnerEntityRegistry::getInstance()->spawn($this->entityTypeId, $location, $this->spawnData !== null ? clone $this->spawnData : null);
			if($entity === null){
				continue;
			}
			$entity->spawnToAll();
			++$nearby;
		}
		$this->randomizeDelay();
	}

	private function randomizeDelay() : void{
		$this->spawnDelay = mt_rand($this->minSpawnDelay, $this->maxSpawnDelay);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		if(($legacyIdTag = $nbt->getTag(self::TAG_LEGACY_ENTITY_TYPE_ID)) instanceof IntTag){
			//TODO: this will cause unexpected results when there's no mapping for the entity
			$this->entityTypeId = LegacyEntityIdToStringIdMap::getInstance()->legacyToString($legacyIdTag->getValue()) ?? ":";
		}elseif(($idTag = $nbt->getTag(self::TAG_ENTITY_TYPE_ID)) instanceof StringTag){
			$this->entityTypeId = $idTag->getValue();
		}else{
			$this->entityTypeId = ":"; //default - TODO: replace this with a constant
		}

		$this->spawnData = $nbt->getCompoundTag(self::TAG_SPAWN_DATA);
		$this->spawnPotentials = $nbt->getListTag(self::TAG_SPAWN_POTENTIALS);

		$this->spawnDelay = max(0, min(32767, self::intTag($nbt, self::TAG_SPAWN_DELAY, self::DEFAULT_MIN_SPAWN_DELAY)));
		$this->minSpawnDelay = max(1, min(32767, self::intTag($nbt, self::TAG_MIN_SPAWN_DELAY, self::DEFAULT_MIN_SPAWN_DELAY)));
		$this->maxSpawnDelay = max($this->minSpawnDelay, min(32767, self::intTag($nbt, self::TAG_MAX_SPAWN_DELAY, self::DEFAULT_MAX_SPAWN_DELAY)));
		$this->spawnPerAttempt = max(1, min(4, self::intTag($nbt, self::TAG_SPAWN_PER_ATTEMPT, 1)));
		$this->maxNearbyEntities = max(1, min(32, self::intTag($nbt, self::TAG_MAX_NEARBY_ENTITIES, self::DEFAULT_MAX_NEARBY_ENTITIES)));
		$this->requiredPlayerRange = max(1, min(32, self::intTag($nbt, self::TAG_REQUIRED_PLAYER_RANGE, self::DEFAULT_REQUIRED_PLAYER_RANGE)));
		$this->spawnRange = max(1, min(8, self::intTag($nbt, self::TAG_SPAWN_RANGE, self::DEFAULT_SPAWN_RANGE)));

		$this->displayEntityWidth = $nbt->getFloat(self::TAG_ENTITY_WIDTH, 1.0);
		$this->displayEntityHeight = $nbt->getFloat(self::TAG_ENTITY_HEIGHT, 1.0);
		$this->displayEntityScale = $nbt->getFloat(self::TAG_ENTITY_SCALE, 1.0);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setString(self::TAG_ENTITY_TYPE_ID, $this->entityTypeId);
		if($this->spawnData !== null){
			$nbt->setTag(self::TAG_SPAWN_DATA, clone $this->spawnData);
		}
		if($this->spawnPotentials !== null){
			$nbt->setTag(self::TAG_SPAWN_POTENTIALS, clone $this->spawnPotentials);
		}

		$nbt->setShort(self::TAG_SPAWN_DELAY, $this->spawnDelay);
		$nbt->setShort(self::TAG_MIN_SPAWN_DELAY, $this->minSpawnDelay);
		$nbt->setShort(self::TAG_MAX_SPAWN_DELAY, $this->maxSpawnDelay);
		$nbt->setShort(self::TAG_SPAWN_PER_ATTEMPT, $this->spawnPerAttempt);
		$nbt->setShort(self::TAG_MAX_NEARBY_ENTITIES, $this->maxNearbyEntities);
		$nbt->setShort(self::TAG_REQUIRED_PLAYER_RANGE, $this->requiredPlayerRange);
		$nbt->setShort(self::TAG_SPAWN_RANGE, $this->spawnRange);

		$nbt->setFloat(self::TAG_ENTITY_WIDTH, $this->displayEntityWidth);
		$nbt->setFloat(self::TAG_ENTITY_HEIGHT, $this->displayEntityHeight);
		$nbt->setFloat(self::TAG_ENTITY_SCALE, $this->displayEntityScale);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt, TypeConverter $typeConverter) : void{
		$nbt->setString(self::TAG_ENTITY_TYPE_ID, $this->entityTypeId);

		//TODO: we can't set SpawnData here because it might crash the client if it's from a PC world (we need to implement full deserialization)

		$nbt->setFloat(self::TAG_ENTITY_SCALE, $this->displayEntityScale);
	}
}
