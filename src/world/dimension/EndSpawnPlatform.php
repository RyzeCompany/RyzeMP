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

namespace pocketmine\world\dimension;

use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * The 5x5 obsidian platform entities arrive on in the End. Like vanilla it is rebuilt (and the air above it cleared)
 * every time something arrives.
 */
final class EndSpawnPlatform{
	public const X = 100;
	public const Y = 49; //Y entities stand at; the obsidian is one block below
	public const Z = 0;

	private function __construct(){
		//NOOP
	}

	public static function build(World $world) : void{
		$obsidian = VanillaBlocks::OBSIDIAN();
		$air = VanillaBlocks::AIR();
		for($dx = -2; $dx <= 2; ++$dx){
			for($dz = -2; $dz <= 2; ++$dz){
				$world->setBlockAt(self::X + $dx, self::Y - 1, self::Z + $dz, $obsidian);
				for($dy = 0; $dy < 3; ++$dy){
					$world->setBlockAt(self::X + $dx, self::Y + $dy, self::Z + $dz, $air);
				}
			}
		}
	}

	public static function getArrivalPosition() : Vector3{
		return new Vector3(self::X + 0.5, self::Y, self::Z + 0.5);
	}
}
