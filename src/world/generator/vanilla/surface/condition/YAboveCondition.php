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

/** "minecraft:y_above": the block (optionally plus its stone depth) is at or above an anchor plus a surface depth multiple. */
final class YAboveCondition extends MaterialCondition{

	public function __construct(
		private readonly int $anchorY,
		private readonly int $surfaceDepthMultiplier,
		private readonly bool $addStoneDepth
	){}

	public function test(MaterialContext $c) : bool{
		$y = $this->addStoneDepth ? $c->blockY + $c->stoneDepthAbove : $c->blockY;
		return $y >= $this->anchorY + $c->surfaceDepth * $this->surfaceDepthMultiplier;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$y = $this->addStoneDepth ? "\$y + \$c->stoneDepthAbove" : "\$y";
		return "($y >= " . $this->anchorY . ($this->surfaceDepthMultiplier !== 0 ? " + \$c->surfaceDepth * " . $this->surfaceDepthMultiplier : "") . ")";
	}
}
