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

namespace pocketmine\world\generator\vanilla\surface\condition;

use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;
use const PHP_INT_MIN;

/** "minecraft:water": no fluid above the floor, or the block is above the fluid surface plus an offset. */
final class WaterCondition extends MaterialCondition{

	public function __construct(
		private readonly int $offset,
		private readonly int $surfaceDepthMultiplier,
		private readonly bool $addStoneDepth
	){}

	public function test(MaterialContext $c) : bool{
		if($c->waterHeight === PHP_INT_MIN){
			return true;
		}
		$y = $this->addStoneDepth ? $c->blockY + $c->stoneDepthAbove : $c->blockY;
		return $y >= $c->waterHeight + $this->offset + $c->surfaceDepth * $this->surfaceDepthMultiplier;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$y = $this->addStoneDepth ? "\$y + \$c->stoneDepthAbove" : "\$y";
		$level = "\$c->waterHeight + " . $this->offset . ($this->surfaceDepthMultiplier !== 0 ? " + \$c->surfaceDepth * " . $this->surfaceDepthMultiplier : "");
		return "(\$c->waterHeight === PHP_INT_MIN || $y >= $level)";
	}
}
