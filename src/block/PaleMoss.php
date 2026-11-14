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

use pocketmine\player\Player;
use function mt_rand;

class PaleMoss extends Moss{

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}

	protected function getHorizontalRadius() : int{
		return 2;
	}

	/**
	 * Vanilla pale_moss_vegetation: pale moss carpet 25, short grass 25, tall grass 10.
	 */
	protected function generateVegetation(int $x, int $y, int $z, ?Player $player) : void{
		$rand = mt_rand(1, 60);
		if($rand <= 25){
			$this->placeVegetation($x, $y, $z, VanillaBlocks::PALE_MOSS_CARPET(), $player);
		}elseif($rand <= 50){
			$this->placeVegetation($x, $y, $z, VanillaBlocks::TALL_GRASS(), $player);
		}else{
			$this->placeDoubleTallGrass($x, $y, $z, $player);
		}
	}
}
