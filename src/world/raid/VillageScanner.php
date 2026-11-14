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

namespace pocketmine\world\raid;

use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\npc\JobSites;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function array_map;
use function floor;
use function max;
use function min;

/**
 * Finds the villages around a position (see {@link Village}) from the blocks of the loaded chunks. Sub-chunks whose
 * palette has no POI block are skipped without reading their blocks, so a scan costs little unless there are many
 * POIs around. Villages are built from the POIs cached by the world's {@link PoiManager}, with the beds and job sites
 * claimed by villagers marked as such.
 */
final class VillageScanner{
	/** Horizontal and vertical reach of a scan around the position. */
	public const SCAN_HORIZONTAL = Village::BORDER_HORIZONTAL + Village::EXPANSION_HORIZONTAL;
	public const SCAN_VERTICAL = 32;

	/**
	 * @var PoiType[]|null block type ID => POI type
	 * @phpstan-var array<int, PoiType>|null
	 */
	private static ?array $poiTypes = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @return PoiType[]
	 * @phpstan-return array<int, PoiType>
	 */
	public static function getPoiTypes() : array{
		if(self::$poiTypes === null){
			$types = [
				VanillaBlocks::BED()->getTypeId() => PoiType::BED,
				VanillaBlocks::BELL()->getTypeId() => PoiType::BELL,
			];
			foreach(JobSites::getAll() as $typeId => $_){
				$types[$typeId] = PoiType::JOB_SITE;
			}
			self::$poiTypes = $types;
		}
		return self::$poiTypes;
	}

	/**
	 * Returns the blocks of the given types in the loaded chunks of the box, reading only the sub-chunks whose
	 * palette has one of them.
	 *
	 * @param mixed[] $types block type ID => anything
	 * @phpstan-param array<int, mixed> $types
	 *
	 * @return int[][] x, y, z and block state ID of each block found
	 * @phpstan-return list<array{int, int, int, int}>
	 */
	public static function findBlocks(World $world, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, array $types) : array{
		$minY = max($minY, $world->getMinY());
		$maxY = min($maxY, $world->getMaxY() - 1);
		$result = [];
		for($cx = $minX >> Chunk::COORD_BIT_SIZE; $cx <= $maxX >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$cz){
				$chunk = $world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				for($sy = $minY >> SubChunk::COORD_BIT_SIZE; $sy <= $maxY >> SubChunk::COORD_BIT_SIZE; ++$sy){
					if($sy < Chunk::MIN_SUBCHUNK_INDEX || $sy > Chunk::MAX_SUBCHUNK_INDEX){
						continue;
					}
					$subChunk = $chunk->getSubChunk($sy);
					if($subChunk->isEmptyFast()){
						continue;
					}
					$layer = $subChunk->getBlockLayers()[0] ?? null;
					if($layer === null){
						continue;
					}
					$found = false;
					foreach($layer->getPalette() as $stateId){
						if(isset($types[$stateId >> Block::INTERNAL_STATE_DATA_BITS])){
							$found = true;
							break;
						}
					}
					if(!$found){
						continue;
					}
					$baseX = $cx << Chunk::COORD_BIT_SIZE;
					$baseY = $sy << SubChunk::COORD_BIT_SIZE;
					$baseZ = $cz << Chunk::COORD_BIT_SIZE;
					$fromX = max(0, $minX - $baseX);
					$toX = min(SubChunk::EDGE_LENGTH - 1, $maxX - $baseX);
					$fromY = max(0, $minY - $baseY);
					$toY = min(SubChunk::EDGE_LENGTH - 1, $maxY - $baseY);
					$fromZ = max(0, $minZ - $baseZ);
					$toZ = min(SubChunk::EDGE_LENGTH - 1, $maxZ - $baseZ);
					for($x = $fromX; $x <= $toX; ++$x){
						for($z = $fromZ; $z <= $toZ; ++$z){
							for($y = $fromY; $y <= $toY; ++$y){
								$stateId = $layer->get($x, $y, $z);
								if(isset($types[$stateId >> Block::INTERNAL_STATE_DATA_BITS])){
									$result[] = [$baseX + $x, $baseY + $y, $baseZ + $z, $stateId];
								}
							}
						}
					}
				}
			}
		}
		return $result;
	}

	/**
	 * Returns the POIs in the loaded chunks of the box with their block type IDs. Beds count once, at their head.
	 *
	 * @phpstan-return list<array{PoiType, Vector3, int}>
	 */
	public static function findPoiBlocks(World $world, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : array{
		$types = self::getPoiTypes();
		$result = [];
		foreach(self::findBlocks($world, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $types) as [$x, $y, $z, $stateId]){
			$typeId = $stateId >> Block::INTERNAL_STATE_DATA_BITS;
			$type = $types[$typeId];
			if($type === PoiType::BED){
				$block = $world->getBlockAt($x, $y, $z);
				if(!$block instanceof Bed || !$block->isHeadPart()){
					continue;
				}
			}
			$result[] = [$type, new Vector3($x, $y, $z), $typeId];
		}
		return $result;
	}

	/**
	 * Returns the POIs in the loaded chunks of the box. Beds count once, at their head.
	 *
	 * @phpstan-return list<array{PoiType, Vector3}>
	 */
	public static function findPois(World $world, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ) : array{
		return array_map(fn(array $poi) => [$poi[0], $poi[1]], self::findPoiBlocks($world, $minX, $minY, $minZ, $maxX, $maxY, $maxZ));
	}

	/**
	 * Returns the villages found around the position (scanning {@link VillageScanner::SCAN_HORIZONTAL} blocks around
	 * horizontally and {@link VillageScanner::SCAN_VERTICAL} vertically).
	 *
	 * @return Village[]
	 * @phpstan-return list<Village>
	 */
	public static function findVillages(World $world, Vector3 $pos, int $horizontal = self::SCAN_HORIZONTAL, int $vertical = self::SCAN_VERTICAL) : array{
		$x = (int) floor($pos->x);
		$y = (int) floor($pos->y);
		$z = (int) floor($pos->z);
		$manager = $world->getPoiManager();
		$found = $manager->getPois($x - $horizontal, $y - $vertical, $z - $horizontal, $x + $horizontal, $y + $vertical, $z + $horizontal);
		if($found === []){
			return [];
		}
		$pois = [];
		$claimed = [];
		foreach($found as [$type, $poiPos]){
			$pois[] = [$type, $poiPos];
			if($type !== PoiType::BELL && $manager->isClaimed($poiPos)){
				$claimed[World::blockHash((int) $poiPos->x, (int) $poiPos->y, (int) $poiPos->z)] = true;
			}
		}
		$h = $horizontal + Village::BORDER_HORIZONTAL;
		$v = $vertical + Village::BORDER_VERTICAL;
		$villagers = [];
		foreach($world->getNearbyEntities(new AxisAlignedBB($x - $h, $y - $v, $z - $h, $x + $h, $y + $v, $z + $h)) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive()){
				$villagers[] = $entity->getPosition()->asVector3();
			}
		}
		return Village::findAll($pois, $villagers, $claimed);
	}

	/**
	 * Returns the village whose Raid Omen region (see {@link Village::isInOmenRange()}) contains the position, if any.
	 */
	public static function findVillageForOmen(World $world, Vector3 $pos) : ?Village{
		foreach(self::findVillages($world, $pos) as $village){
			if($village->isInOmenRange($pos)){
				return $village;
			}
		}
		return null;
	}

	/**
	 * Returns the village nearest to the position whose boundary contains it, if any.
	 */
	public static function findVillageAt(World $world, Vector3 $pos) : ?Village{
		$best = null;
		$bestDist = 0.0;
		foreach(self::findVillages($world, $pos) as $village){
			if(!$village->contains($pos)){
				continue;
			}
			$d = $village->getCenter()->distanceSquared($pos);
			if($best === null || $d < $bestDist){
				$best = $village;
				$bestDist = $d;
			}
		}
		return $best;
	}
}
