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

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function abs;
use function count;
use function max;
use function min;

/**
 * A village, worked out from the points of interest players can build (Bedrock village mechanics, simplified):
 *
 * - POIs are beds (houses), bells (gathering sites) and job site blocks. Villagers claim beds and job sites (see
 *   {@link PoiManager} and {@link \pocketmine\entity\npc\VillagerV2}); the claimed ones are known to the village
 *   ({@link Village::getClaimedBedCount()}, {@link Village::getFreeBeds()}).
 * - A bed starts a village whose boundary is the bed ±32 blocks horizontally and ±12 vertically (Bedrock's initial
 *   64x24x64 box). POIs within the expansion range of a village (32 more blocks horizontally, 52 vertically) join it
 *   and the boundary grows to the box around all its POIs, again ±32/±12. Unclaimed POIs join too (Bedrock only
 *   counts claimed ones), so that the fallback below has a boundary.
 * - It is a village when a villager claimed one of its beds (Bedrock). Fallback for villages without (loaded)
 *   villagers that claimed a bed: at least one bed and either a villager inside the boundary or a bell. It lets players
 *   build a village, and raids happen in it, before villagers move in or while they are unloaded.
 * - The centre is the middle of the box around its POIs (Bedrock uses the first bed while the village hasn't grown).
 */
final class Village{
	public const BORDER_HORIZONTAL = 32;
	public const BORDER_VERTICAL = 12;
	public const EXPANSION_HORIZONTAL = 32;
	public const EXPANSION_VERTICAL = 52;
	/** Half sizes of the region around the centre where Bad Omen turns into Raid Omen (Bedrock: 64x23x64). */
	public const OMEN_RANGE_HORIZONTAL = 32.0;
	public const OMEN_RANGE_VERTICAL = 11.5;

	/**
	 * @var Vector3[]
	 * @phpstan-var list<Vector3>
	 */
	private array $freeBeds;

	/**
	 * @param Vector3[] $beds
	 * @param Vector3[] $bells
	 * @param Vector3[] $jobSites
	 * @phpstan-param list<Vector3> $beds
	 * @phpstan-param list<Vector3> $bells
	 * @phpstan-param list<Vector3> $jobSites
	 * @param Vector3[]|null $freeBeds beds no villager claimed (all of them if null)
	 * @phpstan-param list<Vector3>|null $freeBeds
	 */
	public function __construct(
		private array $beds,
		private array $bells,
		private array $jobSites,
		private int $villagers,
		private AxisAlignedBB $poiBox,
		?array $freeBeds = null
	){
		$this->freeBeds = $freeBeds ?? $beds;
	}

	/**
	 * Groups POIs into villages. Villager positions decide which groups are villages (see the class doc).
	 *
	 * @param array[]   $pois      POI type and block position
	 * @param Vector3[] $villagers positions of the villagers around
	 * @param true[]    $claimed   block hashes ({@link World::blockHash()}) of the claimed beds and job sites
	 * @phpstan-param list<array{PoiType, Vector3}> $pois
	 * @phpstan-param list<Vector3> $villagers
	 * @phpstan-param array<int, true> $claimed
	 *
	 * @return Village[]
	 * @phpstan-return list<Village>
	 */
	public static function findAll(array $pois, array $villagers, array $claimed = []) : array{
		/** @phpstan-var list<array{AxisAlignedBB, list<array{PoiType, Vector3}>}> $clusters */
		$clusters = [];
		$pending = [];
		//beds found the villages, in a stable order
		foreach($pois as $poi){
			if($poi[0] === PoiType::BED){
				$pending[] = $poi;
			}
		}
		foreach($pois as $poi){
			if($poi[0] !== PoiType::BED){
				$pending[] = $poi;
			}
		}
		$changed = true;
		while($changed){
			$changed = false;
			foreach($pending as $k => [$type, $pos]){
				$joined = false;
				foreach($clusters as $i => [$box, $members]){
					if(self::withinExpansion($box, $pos)){
						$members[] = [$type, $pos];
						$clusters[$i] = [self::extend($box, $pos), $members];
						$joined = true;
						break;
					}
				}
				if(!$joined && $type === PoiType::BED){
					$clusters[] = [new AxisAlignedBB($pos->x, $pos->y, $pos->z, $pos->x, $pos->y, $pos->z), [[$type, $pos]]];
					$joined = true;
				}
				if($joined){
					unset($pending[$k]);
					$changed = true;
				}
			}
		}

		$result = [];
		foreach($clusters as [$box, $members]){
			$beds = $bells = $jobSites = $freeBeds = [];
			foreach($members as [$type, $pos]){
				match($type){
					PoiType::BED => $beds[] = $pos,
					PoiType::BELL => $bells[] = $pos,
					PoiType::JOB_SITE => $jobSites[] = $pos,
				};
				if($type === PoiType::BED && !isset($claimed[World::blockHash((int) $pos->x, (int) $pos->y, (int) $pos->z)])){
					$freeBeds[] = $pos;
				}
			}
			$village = new Village($beds, $bells, $jobSites, 0, $box);
			$bounds = $village->getBounds();
			$count = 0;
			foreach($villagers as $v){
				if(self::inBox($bounds, $v)){
					++$count;
				}
			}
			$village = new Village($beds, $bells, $jobSites, $count, $box, $freeBeds);
			if($village->isValid()){
				$result[] = $village;
			}
		}
		return $result;
	}

	private static function inBox(AxisAlignedBB $bb, Vector3 $v) : bool{
		return $v->x >= $bb->minX && $v->x <= $bb->maxX && $v->y >= $bb->minY && $v->y <= $bb->maxY && $v->z >= $bb->minZ && $v->z <= $bb->maxZ;
	}

	private static function withinExpansion(AxisAlignedBB $poiBox, Vector3 $pos) : bool{
		$h = self::BORDER_HORIZONTAL + self::EXPANSION_HORIZONTAL;
		$v = self::BORDER_VERTICAL + self::EXPANSION_VERTICAL;
		return $pos->x >= $poiBox->minX - $h && $pos->x <= $poiBox->maxX + $h &&
			$pos->z >= $poiBox->minZ - $h && $pos->z <= $poiBox->maxZ + $h &&
			$pos->y >= $poiBox->minY - $v && $pos->y <= $poiBox->maxY + $v;
	}

	private static function extend(AxisAlignedBB $bb, Vector3 $pos) : AxisAlignedBB{
		return new AxisAlignedBB(min($bb->minX, $pos->x), min($bb->minY, $pos->y), min($bb->minZ, $pos->z), max($bb->maxX, $pos->x), max($bb->maxY, $pos->y), max($bb->maxZ, $pos->z));
	}

	/**
	 * Whether this counts as a village: a villager claimed one of its beds, or (fallback) it has at least one bed and
	 * a villager or a bell.
	 */
	public function isValid() : bool{
		return $this->getClaimedBedCount() > 0 || (count($this->beds) > 0 && ($this->villagers > 0 || count($this->bells) > 0));
	}

	/**
	 * Number of beds claimed by villagers.
	 */
	public function getClaimedBedCount() : int{
		return count($this->beds) - count($this->freeBeds);
	}

	/**
	 * Beds no villager claimed.
	 *
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getFreeBeds() : array{ return $this->freeBeds; }

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getBeds() : array{ return $this->beds; }

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getBells() : array{ return $this->bells; }

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getJobSites() : array{ return $this->jobSites; }

	public function getVillagerCount() : int{ return $this->villagers; }

	/**
	 * The village boundary: the box around the POIs, ±32 blocks horizontally and ±12 vertically.
	 */
	public function getBounds() : AxisAlignedBB{
		return new AxisAlignedBB(
			$this->poiBox->minX - self::BORDER_HORIZONTAL, $this->poiBox->minY - self::BORDER_VERTICAL, $this->poiBox->minZ - self::BORDER_HORIZONTAL,
			$this->poiBox->maxX + 1 + self::BORDER_HORIZONTAL, $this->poiBox->maxY + 1 + self::BORDER_VERTICAL, $this->poiBox->maxZ + 1 + self::BORDER_HORIZONTAL
		);
	}

	public function getCenter() : Vector3{
		return new Vector3(
			($this->poiBox->minX + $this->poiBox->maxX + 1) / 2,
			($this->poiBox->minY + $this->poiBox->maxY) / 2,
			($this->poiBox->minZ + $this->poiBox->maxZ + 1) / 2
		);
	}

	/**
	 * Whether a player there with Bad Omen gets Raid Omen (Bedrock: within 64x23x64 blocks around the centre).
	 */
	public function isInOmenRange(Vector3 $pos) : bool{
		$c = $this->getCenter();
		return abs($pos->x - $c->x) <= self::OMEN_RANGE_HORIZONTAL && abs($pos->z - $c->z) <= self::OMEN_RANGE_HORIZONTAL && abs($pos->y - $c->y) <= self::OMEN_RANGE_VERTICAL;
	}

	public function contains(Vector3 $pos) : bool{
		return self::inBox($this->getBounds(), $pos);
	}
}
