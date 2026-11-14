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

namespace pocketmine\entity\monster;

use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function floor;
use function implode;
use function intdiv;
use function max;

/**
 * Whether an entity of some classes accepted by a filter is near a mob, worked out once per second (on the mob's AI
 * clock) and only when asked: the mob's goals use it to decide whether looking for those entities (a search of the
 * entities around the mob, with line of sight checks) is worth it at all.
 *
 * Nothing is looked at while the world has none of the classes (cached class counts). Otherwise the check looks at the
 * chunks within range, whose entities of the classes are listed once per second for all the mobs asking (most mobs
 * share chunks with many others), so entities of those classes far away cost next to nothing.
 *
 * @internal
 */
final class NearbyEntityCheck{
	private const REFRESH_TICKS = 20;

	/**
	 * Entity IDs of the classes per chunk, per class list and world, for the current second.
	 *
	 * @var int[][][]
	 * @phpstan-var array<string, array<int, list<int>>>
	 */
	private static array $chunkCache = [];
	private static int $chunkCacheSecond = -1;

	private string $classKey;
	private int $checkedSecond = -1;
	private bool $found = false;

	/**
	 * @param string[] $classes
	 * @phpstan-param list<class-string<Entity>> $classes
	 * @phpstan-param \Closure(Entity) : bool $filter checked on entities of the classes in range
	 */
	public function __construct(
		private Mob $mob,
		private array $classes,
		private \Closure $filter,
		private float $range,
		private float $verticalRange
	){
		$this->classKey = implode(",", $classes);
	}

	/**
	 * @return string[]
	 * @phpstan-return list<class-string<Entity>>
	 */
	public function getClasses() : array{
		return $this->classes;
	}

	public function check() : bool{
		$second = intdiv(max($this->mob->getAiTick(), 0), self::REFRESH_TICKS);
		if($second !== $this->checkedSecond){
			$this->checkedSecond = $second;
			$this->found = $this->scan($second);
		}
		return $this->found;
	}

	private function scan(int $second) : bool{
		$world = $this->mob->getWorld();
		$any = false;
		foreach($this->classes as $class){
			if($world->hasEntitiesOfClass($class)){
				$any = true;
				break;
			}
		}
		if(!$any){
			return false;
		}
		if($second !== self::$chunkCacheSecond){
			self::$chunkCache = [];
			self::$chunkCacheSecond = $second;
		}
		$key = $this->classKey . ":" . $world->getId();
		$loc = $this->mob->getLiveLocation();
		$r = $this->range;
		$v = $this->verticalRange;
		$minX = ((int) floor($loc->x - $r)) >> Chunk::COORD_BIT_SIZE;
		$maxX = ((int) floor($loc->x + $r)) >> Chunk::COORD_BIT_SIZE;
		$minZ = ((int) floor($loc->z - $r)) >> Chunk::COORD_BIT_SIZE;
		$maxZ = ((int) floor($loc->z + $r)) >> Chunk::COORD_BIT_SIZE;
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				foreach(self::$chunkCache[$key][World::chunkHash($x, $z)] ??= $this->listChunk($world, $x, $z) as $id){
					$entity = $world->getEntity($id);
					if($entity === null || $entity === $this->mob || $entity->isClosed() || !$entity->isAlive()){
						continue;
					}
					$pos = $entity->getPosition();
					if(abs($pos->x - $loc->x) <= $r && abs($pos->z - $loc->z) <= $r && abs($pos->y - $loc->y) <= $v && ($this->filter)($entity)){
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private function listChunk(World $world, int $x, int $z) : array{
		$ids = [];
		foreach($world->getChunkEntities($x, $z) as $entity){
			foreach($this->classes as $class){
				if($entity instanceof $class){
					$ids[] = $entity->getId();
					break;
				}
			}
		}
		return $ids;
	}
}
