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

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;

/**
 * Saved data of {@link Raider}s. Bedrock keeps patrol and raid membership in component groups that PocketMine
 * doesn't have, so the tags follow Java's names.
 */
final class Raiders{
	public const TAG_PATROL_LEADER = "PatrolLeader"; //TAG_Byte
	public const TAG_PATROLLING = "Patrolling"; //TAG_Byte
	public const TAG_PATROL_TARGET = "PatrolTarget"; //TAG_List<TAG_Double>
	public const TAG_RAID = "Raid"; //TAG_Compound
	public const TAG_RAID_ID = "RaidId"; //TAG_Int
	public const TAG_RAID_WAVE = "Wave"; //TAG_Int
	public const TAG_RAID_CENTER = "Center"; //TAG_List<TAG_Double>

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return ListTag<DoubleTag>
	 */
	public static function vectorTag(Vector3 $v) : ListTag{
		return new ListTag([new DoubleTag($v->x), new DoubleTag($v->y), new DoubleTag($v->z)]);
	}

	/**
	 * Reads a list of 3 doubles written by {@link Raiders::vectorTag()}.
	 *
	 * @phpstan-param ListTag<*>|null $tag
	 */
	public static function readVector(?ListTag $tag) : ?Vector3{
		if($tag === null || $tag->count() !== 3){
			return null;
		}
		$v = [];
		foreach($tag as $value){
			if(!$value instanceof DoubleTag){
				return null;
			}
			$v[] = $value->getValue();
		}
		return new Vector3($v[0], $v[1], $v[2]);
	}
}
