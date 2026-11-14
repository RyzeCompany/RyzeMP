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

namespace pocketmine\world\vibration;

use pocketmine\block\Block;
use pocketmine\block\Carpet;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\SculkCatalyst;
use pocketmine\block\SculkSensor;
use pocketmine\block\SculkShrieker;
use pocketmine\block\utils\SculkSpreader;
use pocketmine\block\Wool;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function count;
use function floor;
use function max;
use function spl_object_id;
use function sqrt;
use function usort;

/**
 * Delivers vibrations (game events) to the sculk sensors around them, and the other sculk game events: sensors
 * setting off sculk shriekers, and mob deaths feeding sculk catalysts.
 *
 * Sensors, shriekers and catalysts are found through a per-chunk index of their positions, so an event only looks at
 * the blocks in the chunks within range: there is no scan of the world. A chunk is indexed the first time an event
 * needs it (only subchunks whose palette contains one of these blocks are scanned) and then kept up to date by
 * {@link World::setBlockAt()} until the chunk is unloaded or replaced.
 */
final class VibrationManager{
	/** Longest detection range of any sensor (calibrated sculk sensor). */
	public const MAX_RANGE = 16;
	/** Range in which sculk shriekers hear sculk sensors (Java: tendrils clicking listener radius). */
	public const SHRIEKER_RANGE = 8;
	/** Range in which sculk catalysts react to mob deaths. */
	public const CATALYST_RANGE = 8;

	private const KIND_NONE = 0;
	private const KIND_SENSOR = 1;
	private const KIND_SHRIEKER = 2;
	private const KIND_CATALYST = 3;

	/**
	 * @var int[][] chunk hash => world block hash => KIND_* constant
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $blockIndex = [];

	/**
	 * Vibrations travelling to a sensor.
	 *
	 * @var int[][]|null[][] world block hash => [arrival tick, distance squared, power, frequency, emission tick, source entity ID]
	 * @phpstan-var array<int, array{int, int, int, int, int, int|null}>
	 */
	private array $pending = [];

	/**
	 * Output of active sensors (Bedrock has no block state for it).
	 *
	 * @var int[][] world block hash => [power, frequency, phase end tick]
	 * @phpstan-var array<int, array{int, int, int}>
	 */
	private array $sensorOutput = [];

	/**
	 * Sensor clicks travelling to a shrieker.
	 *
	 * @var int[][] world block hash => [arrival tick, player entity ID]
	 * @phpstan-var array<int, array{int, int}>
	 */
	private array $pendingShrieks = [];

	/**
	 * Runtime state of shrieking shriekers.
	 *
	 * @var int[][] world block hash => [shriek end tick, warning level]
	 * @phpstan-var array<int, array{int, int}>
	 */
	private array $shriekerStates = [];

	/**
	 * Sculk being spread by catalysts (not saved: a bloom only lasts a few seconds).
	 *
	 * @var SculkSpreader[] world block hash => spreader
	 * @phpstan-var array<int, SculkSpreader>
	 */
	private array $catalystSpreaders = [];

	/**
	 * @var int[] world block hash => tick at which the bloom of the catalyst ends
	 * @phpstan-var array<int, int>
	 */
	private array $catalystBloomEnds = [];

	/**
	 * @var int[] state ID => KIND_* constant
	 * @phpstan-var array<int, int>
	 */
	private static array $stateKinds = [];

	/**
	 * @var bool[] state ID => whether the state blocks vibrations (wool)
	 * @phpstan-var array<int, bool>
	 */
	private static array $occludingStates = [];

	/**
	 * @var VibrationListener[]
	 * @phpstan-var array<int, VibrationListener>
	 */
	private array $listeners = [];

	public function __construct(
		private World $world
	){}

	private static function getStateKind(int $stateId) : int{
		if(!isset(self::$stateKinds[$stateId])){
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
			self::$stateKinds[$stateId] = match(true){
				$block instanceof SculkSensor => self::KIND_SENSOR,
				$block instanceof SculkShrieker => self::KIND_SHRIEKER,
				$block instanceof SculkCatalyst => self::KIND_CATALYST,
				default => self::KIND_NONE
			};
		}
		return self::$stateKinds[$stateId];
	}

	private static function isOccludingState(int $stateId) : bool{
		return self::$occludingStates[$stateId] ??= RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId) instanceof Wool;
	}

	private function currentTick() : int{
		return $this->world->getServer()->getTick();
	}

	/**
	 * Returns the indexed blocks of the given kind within the range of a position (distance between block
	 * positions), closest first.
	 *
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int, int}> [x, y, z, distance squared]
	 */
	private function findIndexedBlocks(int $x, int $y, int $z, int $range, int $kind) : array{
		$result = [];
		for($cx = ($x - $range) >> Chunk::COORD_BIT_SIZE, $maxCx = ($x + $range) >> Chunk::COORD_BIT_SIZE; $cx <= $maxCx; ++$cx){
			for($cz = ($z - $range) >> Chunk::COORD_BIT_SIZE, $maxCz = ($z + $range) >> Chunk::COORD_BIT_SIZE; $cz <= $maxCz; ++$cz){
				foreach($this->getIndexedBlocksInChunk($cx, $cz) as $hash => $blockKind){
					if($blockKind !== $kind){
						continue;
					}
					World::getBlockXYZ($hash, $bx, $by, $bz);
					$dx = $bx - $x;
					$dy = $by - $y;
					$dz = $bz - $z;
					$distanceSquared = $dx * $dx + $dy * $dy + $dz * $dz;
					if($distanceSquared <= $range * $range){
						$result[] = [$bx, $by, $bz, $distanceSquared];
					}
				}
			}
		}
		if(count($result) > 1){
			usort($result, fn(array $a, array $b) : int => $a[3] <=> $b[3]);
		}
		return $result;
	}

	/**
	 * Emits a vibration at the given position. Sensors in range which can hear it start listening to it; it arrives
	 * after one tick per block of distance.
	 *
	 * @param Entity|null $source   the entity which caused the event, if any (sneaking entities make less noise)
	 * @param Block|null  $affected the block placed or broken, if any (wool and carpets are silent)
	 */
	public function emit(Vector3 $pos, GameEvent $event, ?Entity $source = null, ?Block $affected = null) : void{
		$x = (int) floor($pos->x);
		$y = (int) floor($pos->y);
		$z = (int) floor($pos->z);
		if(!$this->world->isInWorld($x, $y, $z)){
			return;
		}
		if($source instanceof Living && $source->isSneaking() && $event->isIgnoredWhenSneaking()){
			return;
		}
		if($source instanceof Warden){
			//minecraft:vibration_damper (Java Entity.dampensVibrations()): nothing hears what a warden does
			return;
		}
		if($affected instanceof Wool || $affected instanceof Carpet){
			return;
		}

		if(count($this->listeners) > 0){
			$this->notifyListeners($pos, $x, $y, $z, $event, $source, $affected);
		}
		if(!$event->isHeardBySensors()){
			return;
		}

		$candidates = [];
		foreach($this->findIndexedBlocks($x, $y, $z, self::MAX_RANGE, self::KIND_SENSOR) as $candidate){
			if($candidate[3] !== 0){
				$candidates[] = $candidate;
			}
		}
		if(count($candidates) === 0){
			return;
		}

		if($source !== null && $event->isDampenedByWool() && $this->isOnDampeningBlock($source)){
			return;
		}

		$frequency = $event->getFrequency();
		foreach($candidates as [$sx, $sy, $sz, $distanceSquared]){
			$sensor = $this->world->getBlockAt($sx, $sy, $sz);
			if(!$sensor instanceof SculkSensor){
				$this->removeFromIndex($sx, $sy, $sz);
				continue;
			}
			$sensorRange = $sensor->getRange();
			if($distanceSquared > $sensorRange * $sensorRange || !$sensor->canDetect($frequency)){
				continue;
			}
			if($this->isOccluded($x, $y, $z, $sx, $sy, $sz)){
				continue;
			}
			$distance = sqrt($distanceSquared);
			$this->receive($sensor, $sx, $sy, $sz, $distanceSquared, $distance, self::getPowerForDistance($distance, $sensorRange), $frequency, $source?->getId());
		}
	}

	/**
	 * Redstone power output for a vibration detected at the given distance: 15 right next to the sensor, at least 1.
	 */
	public static function getPowerForDistance(float $distance, int $range) : int{
		return max(1, 15 - (int) floor(15 / $range * $distance));
	}

	private function receive(SculkSensor $sensor, int $x, int $y, int $z, int $distanceSquared, float $distance, int $power, int $frequency, ?int $sourceId) : void{
		$hash = World::blockHash($x, $y, $z);
		$now = $this->currentTick();
		if(isset($this->pending[$hash])){
			[, $oldDistanceSquared, , , $emittedAt] = $this->pending[$hash];
			if($emittedAt !== $now || $oldDistanceSquared <= $distanceSquared){
				return; //already listening to an earlier vibration, or to a closer one from this tick
			}
		}
		$travelTicks = max(1, (int) floor($distance));
		$this->pending[$hash] = [$now + $travelTicks, $distanceSquared, $power, $frequency, $now, $sourceId];
		$this->world->scheduleDelayedBlockUpdate($sensor->getPosition(), $travelTicks);
	}

	/**
	 * Called by a sensor from its scheduled update: returns the vibration which reached it by now, if any.
	 *
	 * @return int[]|null[]|null [power, frequency, ID of the entity which caused the vibration]
	 * @phpstan-return array{int, int, int|null}|null
	 */
	public function takeArrivedVibration(int $x, int $y, int $z) : ?array{
		$hash = World::blockHash($x, $y, $z);
		if(!isset($this->pending[$hash])){
			return null;
		}
		[$arrival, , $power, $frequency, , $sourceId] = $this->pending[$hash];
		if($arrival > $this->currentTick()){
			return null;
		}
		unset($this->pending[$hash]);
		return [$power, $frequency, $sourceId];
	}

	/**
	 * Returns whether a vibration is travelling to the sensor at the given position.
	 */
	public function isListening(int $x, int $y, int $z) : bool{
		return isset($this->pending[World::blockHash($x, $y, $z)]);
	}

	/**
	 * Forgets the vibration travelling to the sensor at the given position, if any.
	 */
	public function clearPendingVibration(int $x, int $y, int $z) : void{
		unset($this->pending[World::blockHash($x, $y, $z)]);
	}

	/**
	 * Returns the runtime state of an active or cooling down sensor: its redstone output, the frequency of the
	 * vibration it detected and the tick at which its current phase ends. Null if unknown (inactive sensor, or the
	 * chunk was reloaded).
	 *
	 * @return int[]|null [power, frequency, phase end tick]
	 * @phpstan-return array{int, int, int}|null
	 */
	public function getSensorState(int $x, int $y, int $z) : ?array{
		return $this->sensorOutput[World::blockHash($x, $y, $z)] ?? null;
	}

	public function setSensorState(int $x, int $y, int $z, int $power, int $frequency, int $phaseEndTick) : void{
		$this->sensorOutput[World::blockHash($x, $y, $z)] = [$power, $frequency, $phaseEndTick];
	}

	public function clearSensorState(int $x, int $y, int $z) : void{
		unset($this->sensorOutput[World::blockHash($x, $y, $z)]);
	}

	/**
	 * Returns whether wool lies on the straight line between the centres of the two blocks (the blocks themselves
	 * excluded).
	 */
	public function isOccluded(int $fromX, int $fromY, int $fromZ, int $toX, int $toY, int $toZ) : bool{
		if($fromX === $toX && $fromY === $toY && $fromZ === $toZ){
			return false; //same block (e.g. a listener standing where the vibration happens): nothing in between
		}
		$from = new Vector3($fromX + 0.5, $fromY + 0.5, $fromZ + 0.5);
		$to = new Vector3($toX + 0.5, $toY + 0.5, $toZ + 0.5);
		foreach(VoxelRayTrace::betweenPoints($from, $to) as $pos){
			$bx = (int) $pos->x;
			$by = (int) $pos->y;
			$bz = (int) $pos->z;
			if(($bx === $fromX && $by === $fromY && $bz === $fromZ) || ($bx === $toX && $by === $toY && $bz === $toZ)){
				continue;
			}
			if(!$this->world->isInWorld($bx, $by, $bz)){
				continue;
			}
			$chunk = $this->world->getChunk($bx >> Chunk::COORD_BIT_SIZE, $bz >> Chunk::COORD_BIT_SIZE);
			if($chunk !== null && self::isOccludingState($chunk->getBlockStateId($bx & Chunk::COORD_MASK, $by, $bz & Chunk::COORD_MASK))){
				return true;
			}
		}
		return false;
	}

	private function isOnDampeningBlock(Entity $entity) : bool{
		$pos = $entity->getPosition();
		$x = (int) floor($pos->x);
		$z = (int) floor($pos->z);
		foreach([(int) floor($pos->y), (int) floor($pos->y - 0.2)] as $y){
			if(!$this->world->isInWorld($x, $y, $z)){
				continue;
			}
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block instanceof Wool || $block instanceof Carpet){
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Sculk shriekers
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Returns the player responsible for an entity's vibration (Java SculkShriekerBlockEntity.tryGetPlayer()): the
	 * player itself, the player riding the entity, the player who shot the projectile or dropped the item.
	 */
	public static function getResponsiblePlayer(?Entity $entity) : ?Player{
		if($entity === null){
			return null;
		}
		if($entity instanceof Player){
			return $entity;
		}
		foreach($entity->getPassengers() as $passenger){
			if($passenger instanceof Player){
				return $passenger;
			}
		}
		if($entity instanceof Projectile){
			$owner = $entity->getOwningEntity();
			return $owner instanceof Player ? $owner : null;
		}
		if($entity instanceof ItemEntity){
			$thrower = $entity->getThrower();
			return $thrower !== "" ? $entity->getWorld()->getServer()->getPlayerExact($thrower) : null;
		}
		return null;
	}

	/**
	 * Called when a sculk sensor becomes active (Java game event sculk_sensor_tendrils_clicking): the sculk
	 * shriekers within 8 blocks hear it, if a player caused the sensor's vibration. The click travels one block per
	 * tick and is blocked by wool.
	 */
	public function onSensorActivated(int $x, int $y, int $z, ?Entity $source) : void{
		$player = self::getResponsiblePlayer($source);
		if($player !== null){
			$this->notifyShriekers($x, $y, $z, $player->getId());
		}
	}

	/**
	 * Sends the click of the sensor at the given position, caused by the given player, to the shriekers around.
	 */
	public function notifyShriekers(int $x, int $y, int $z, int $playerEntityId) : void{
		$now = $this->currentTick();
		foreach($this->findIndexedBlocks($x, $y, $z, self::SHRIEKER_RANGE, self::KIND_SHRIEKER) as [$sx, $sy, $sz, $distanceSquared]){
			$shrieker = $this->world->getBlockAt($sx, $sy, $sz);
			if(!$shrieker instanceof SculkShrieker){
				$this->removeFromIndex($sx, $sy, $sz);
				continue;
			}
			$hash = World::blockHash($sx, $sy, $sz);
			if($shrieker->isActive() || isset($this->pendingShrieks[$hash]) || $this->isOccluded($x, $y, $z, $sx, $sy, $sz)){
				continue;
			}
			$travelTicks = max(1, (int) floor(sqrt($distanceSquared)));
			$this->pendingShrieks[$hash] = [$now + $travelTicks, $playerEntityId];
			$this->world->scheduleDelayedBlockUpdate($shrieker->getPosition(), $travelTicks);
		}
	}

	/**
	 * Called by a shrieker from its scheduled update: returns the player whose sensor click reached it by now, if
	 * any.
	 */
	public function takeArrivedShriek(int $x, int $y, int $z) : ?Player{
		$hash = World::blockHash($x, $y, $z);
		if(!isset($this->pendingShrieks[$hash]) || $this->pendingShrieks[$hash][0] > $this->currentTick()){
			return null;
		}
		$playerId = $this->pendingShrieks[$hash][1];
		unset($this->pendingShrieks[$hash]);
		$player = $this->world->getEntity($playerId);
		return $player instanceof Player && $player->isAlive() ? $player : null;
	}

	/**
	 * Returns the state of a shrieking shrieker: the tick at which the shriek ends and the warning level it reached.
	 *
	 * @return int[]|null
	 * @phpstan-return array{int, int}|null
	 */
	public function getShriekerState(int $x, int $y, int $z) : ?array{
		return $this->shriekerStates[World::blockHash($x, $y, $z)] ?? null;
	}

	public function setShriekerState(int $x, int $y, int $z, int $shriekEndTick, int $warningLevel) : void{
		$this->shriekerStates[World::blockHash($x, $y, $z)] = [$shriekEndTick, $warningLevel];
	}

	public function clearShriekerState(int $x, int $y, int $z) : void{
		unset($this->shriekerStates[World::blockHash($x, $y, $z)]);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Sculk catalysts
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Called when a mob dies: the closest sculk catalyst within 8 blocks absorbs the experience it would drop and
	 * spreads sculk with it. Returns whether the experience was absorbed (then none must be dropped).
	 */
	public function absorbDeathExperience(Living $entity, int $xp) : bool{
		if($xp <= 0){
			return false;
		}
		$pos = $entity->getPosition();
		$x = (int) floor($pos->x);
		$y = (int) floor($pos->y);
		$z = (int) floor($pos->z);
		if(!$this->world->isInWorld($x, $y, $z)){
			return false;
		}
		foreach($this->findIndexedBlocks($x, $y, $z, self::CATALYST_RANGE, self::KIND_CATALYST) as [$cx, $cy, $cz, ]){
			$catalyst = $this->world->getBlockAt($cx, $cy, $cz);
			if(!$catalyst instanceof SculkCatalyst){
				$this->removeFromIndex($cx, $cy, $cz);
				continue;
			}
			//the charge starts where the mob died, half a block up
			$catalyst->absorbExperience((int) floor($pos->x), (int) floor($pos->y + 0.5), (int) floor($pos->z), $xp);
			return true;
		}
		return false;
	}

	/**
	 * Returns the sculk spread by the catalyst at the given position.
	 */
	public function getCatalystSpreader(int $x, int $y, int $z) : SculkSpreader{
		return $this->catalystSpreaders[World::blockHash($x, $y, $z)] ??= new SculkSpreader();
	}

	public function hasCatalystSpreader(int $x, int $y, int $z) : bool{
		return isset($this->catalystSpreaders[World::blockHash($x, $y, $z)]);
	}

	public function removeCatalystSpreader(int $x, int $y, int $z) : void{
		unset($this->catalystSpreaders[World::blockHash($x, $y, $z)]);
	}

	public function setCatalystBloomEnd(int $x, int $y, int $z, int $tick) : void{
		$this->catalystBloomEnds[World::blockHash($x, $y, $z)] = $tick;
	}

	/**
	 * Returns the tick at which the bloom of the catalyst ends; the default is recorded if none is known (catalyst
	 * reloaded while blooming).
	 */
	public function getCatalystBloomEnd(int $x, int $y, int $z, int $default) : int{
		return $this->catalystBloomEnds[World::blockHash($x, $y, $z)] ??= $default;
	}

	public function clearCatalystBloomEnd(int $x, int $y, int $z) : void{
		unset($this->catalystBloomEnds[World::blockHash($x, $y, $z)]);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Block index
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * @return int[] world block hash => KIND_* constant
	 * @phpstan-return array<int, int>
	 */
	private function getIndexedBlocksInChunk(int $chunkX, int $chunkZ) : array{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->blockIndex[$chunkHash])){
			return $this->blockIndex[$chunkHash];
		}
		$chunk = $this->world->getChunk($chunkX, $chunkZ);
		if($chunk === null){
			return [];
		}
		$blocks = [];
		$baseX = $chunkX << Chunk::COORD_BIT_SIZE;
		$baseZ = $chunkZ << Chunk::COORD_BIT_SIZE;
		foreach($chunk->getSubChunks() as $subChunkY => $subChunk){
			if($subChunk->isEmptyFast()){
				continue;
			}
			$baseY = $subChunkY << SubChunk::COORD_BIT_SIZE;
			foreach($subChunk->getBlockLayers() as $layer){
				$hasIndexed = false;
				foreach($layer->getPalette() as $stateId){
					if(self::getStateKind($stateId) !== self::KIND_NONE){
						$hasIndexed = true;
						break;
					}
				}
				if(!$hasIndexed){
					continue;
				}
				for($lx = 0; $lx < SubChunk::EDGE_LENGTH; ++$lx){
					for($ly = 0; $ly < SubChunk::EDGE_LENGTH; ++$ly){
						for($lz = 0; $lz < SubChunk::EDGE_LENGTH; ++$lz){
							$kind = self::getStateKind($layer->get($lx, $ly, $lz));
							if($kind !== self::KIND_NONE){
								$blocks[World::blockHash($baseX + $lx, $baseY + $ly, $baseZ + $lz)] = $kind;
							}
						}
					}
				}
			}
		}
		return $this->blockIndex[$chunkHash] = $blocks;
	}

	private function removeFromIndex(int $x, int $y, int $z) : void{
		unset($this->blockIndex[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::blockHash($x, $y, $z)]);
	}

	/**
	 * @internal Called by the world when a block state changes.
	 */
	public function onBlockChanged(int $x, int $y, int $z, int $oldStateId, int $newStateId) : void{
		$oldKind = self::getStateKind($oldStateId);
		$newKind = self::getStateKind($newStateId);
		if($oldKind === $newKind){
			return;
		}
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$hash = World::blockHash($x, $y, $z);
		if($newKind !== self::KIND_NONE){
			if(isset($this->blockIndex[$chunkHash])){
				$this->blockIndex[$chunkHash][$hash] = $newKind;
			}
		}else{
			unset($this->blockIndex[$chunkHash][$hash]);
		}
		unset(
			$this->pending[$hash],
			$this->sensorOutput[$hash],
			$this->pendingShrieks[$hash],
			$this->shriekerStates[$hash],
			$this->catalystSpreaders[$hash],
			$this->catalystBloomEnds[$hash]
		);
	}

	/**
	 * @internal Called by the world when a chunk is unloaded or replaced.
	 */
	public function onChunkUnloaded(int $chunkX, int $chunkZ) : void{
		unset($this->blockIndex[World::chunkHash($chunkX, $chunkZ)]);
		$this->pending = $this->withoutChunk($this->pending, $chunkX, $chunkZ);
		$this->sensorOutput = $this->withoutChunk($this->sensorOutput, $chunkX, $chunkZ);
		$this->pendingShrieks = $this->withoutChunk($this->pendingShrieks, $chunkX, $chunkZ);
		$this->shriekerStates = $this->withoutChunk($this->shriekerStates, $chunkX, $chunkZ);
		$this->catalystSpreaders = $this->withoutChunk($this->catalystSpreaders, $chunkX, $chunkZ);
		$this->catalystBloomEnds = $this->withoutChunk($this->catalystBloomEnds, $chunkX, $chunkZ);
	}

	/**
	 * @phpstan-template TValue
	 * @phpstan-param array<int, TValue> $map world block hash => value
	 * @phpstan-return array<int, TValue>
	 */
	private function withoutChunk(array $map, int $chunkX, int $chunkZ) : array{
		foreach($map as $hash => $_){
			World::getBlockXYZ($hash, $x, $y, $z);
			if(($x >> Chunk::COORD_BIT_SIZE) === $chunkX && ($z >> Chunk::COORD_BIT_SIZE) === $chunkZ){
				unset($map[$hash]);
			}
		}
		return $map;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Entity listeners (wardens)
	// ---------------------------------------------------------------------------------------------------------------

	public function addListener(VibrationListener $listener) : void{
		$this->listeners[spl_object_id($listener)] = $listener;
	}

	public function removeListener(VibrationListener $listener) : void{
		unset($this->listeners[spl_object_id($listener)]);
	}

	/**
	 * @return VibrationListener[]
	 * @phpstan-return array<int, VibrationListener>
	 */
	public function getListeners() : array{
		return $this->listeners;
	}

	private function notifyListeners(Vector3 $pos, int $x, int $y, int $z, GameEvent $event, ?Entity $source, ?Block $affected) : void{
		$dampened = null;
		foreach($this->listeners as $listener){
			if($listener === $source){
				continue;
			}
			$listenerPos = $listener->getListenerPosition();
			$range = $listener->getListenerRange();
			$distanceSquared = $listenerPos->distanceSquared($pos);
			if($distanceSquared > $range * $range){
				continue;
			}
			if($source !== null && $event->isDampenedByWool() && ($dampened ??= $this->isOnDampeningBlock($source))){
				return;
			}
			if($this->isOccluded($x, $y, $z, (int) floor($listenerPos->x), (int) floor($listenerPos->y), (int) floor($listenerPos->z))){
				continue;
			}
			$listener->onVibration($pos, $event, $source, $affected, sqrt($distanceSquared));
		}
	}

	/**
	 * @return int[]
	 * @phpstan-return array{chunks: int, sensors: int, shriekers: int, catalysts: int, pending: int, spreading: int}
	 */
	public function getStatistics() : array{
		$counts = [self::KIND_SENSOR => 0, self::KIND_SHRIEKER => 0, self::KIND_CATALYST => 0];
		foreach($this->blockIndex as $list){
			foreach($list as $kind){
				++$counts[$kind];
			}
		}
		return [
			"chunks" => count($this->blockIndex),
			"sensors" => $counts[self::KIND_SENSOR],
			"shriekers" => $counts[self::KIND_SHRIEKER],
			"catalysts" => $counts[self::KIND_CATALYST],
			"pending" => count($this->pending) + count($this->pendingShrieks),
			"spreading" => count($this->catalystSpreaders)
		];
	}
}
