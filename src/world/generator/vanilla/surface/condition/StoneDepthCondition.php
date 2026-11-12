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

/**
 * "minecraft:stone_depth": the block is within a depth of the floor (solid blocks above it, up to air or fluid) or
 * of the ceiling (solid blocks below it).
 */
final class StoneDepthCondition extends MaterialCondition{

	public function __construct(
		private readonly int $offset,
		private readonly bool $addSurfaceDepth,
		private readonly int $secondaryDepthRange,
		private readonly bool $ceiling
	){}

	public function test(MaterialContext $c) : bool{
		$stoneDepth = $this->ceiling ? $c->stoneDepthBelow : $c->stoneDepthAbove;
		$limit = 1 + $this->offset + ($this->addSurfaceDepth ? $c->surfaceDepth : 0);
		if($this->secondaryDepthRange !== 0){
			//(int) Mth.map(secondary, -1, 1, 0, range)
			$limit += (int) ((($c->surfaceSecondary() + 1.0) / 2.0) * $this->secondaryDepthRange);
		}
		return $stoneDepth <= $limit;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$limit = (string) (1 + $this->offset);
		if($this->addSurfaceDepth){
			$limit .= " + \$c->surfaceDepth";
		}
		if($this->secondaryDepthRange !== 0){
			$limit .= " + (int) (((" . $compiler->lazy("\$c->surfaceSecondary()") . " + 1.0) / 2.0) * " . $this->secondaryDepthRange . ")";
		}
		return "(" . ($this->ceiling ? "\$c->stoneDepthBelow" : "\$c->stoneDepthAbove") . " <= $limit)";
	}
}
