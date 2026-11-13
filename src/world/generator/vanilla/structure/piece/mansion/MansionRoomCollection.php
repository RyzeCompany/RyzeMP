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

namespace pocketmine\world\generator\vanilla\structure\piece\mansion;

use pocketmine\world\generator\vanilla\random\LegacyRandom;

/**
 * vanilla's WoodlandMansionPieces.FloorRoomCollection and its subclasses: the room templates of a floor
 * (FirstFloorRoomCollection for the first floor, SecondFloorRoomCollection for the second, ThirdFloorRoomCollection =
 * the second floor's rooms for the third).
 */
final class MansionRoomCollection{

	public function __construct(
		private bool $firstFloor
	){}

	public function get1x1(LegacyRandom $random) : string{
		return ($this->firstFloor ? "1x1_a" : "1x1_b") . ($random->nextBoundedInt(5) + 1);
	}

	public function get1x1Secret(LegacyRandom $random) : string{
		return "1x1_as" . ($random->nextBoundedInt(4) + 1);
	}

	public function get1x2SideEntrance(LegacyRandom $random, bool $isStairsRoom) : string{
		if($this->firstFloor){
			return "1x2_a" . ($random->nextBoundedInt(9) + 1);
		}
		return $isStairsRoom ? "1x2_c_stairs" : "1x2_c" . ($random->nextBoundedInt(4) + 1);
	}

	public function get1x2FrontEntrance(LegacyRandom $random, bool $isStairsRoom) : string{
		if($this->firstFloor){
			return "1x2_b" . ($random->nextBoundedInt(5) + 1);
		}
		return $isStairsRoom ? "1x2_d_stairs" : "1x2_d" . ($random->nextBoundedInt(5) + 1);
	}

	public function get1x2Secret(LegacyRandom $random) : string{
		return $this->firstFloor ? "1x2_s" . ($random->nextBoundedInt(2) + 1) : "1x2_se" . ($random->nextBoundedInt(1) + 1);
	}

	public function get2x2(LegacyRandom $random) : string{
		return ($this->firstFloor ? "2x2_a" . ($random->nextBoundedInt(4) + 1) : "2x2_b" . ($random->nextBoundedInt(5) + 1));
	}

	public function get2x2Secret(LegacyRandom $random) : string{
		return "2x2_s1";
	}
}
