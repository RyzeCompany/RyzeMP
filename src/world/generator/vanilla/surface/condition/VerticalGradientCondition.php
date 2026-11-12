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

use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;

/**
 * "minecraft:vertical_gradient": always true at and below one level, never at and above another, and with a linearly
 * decreasing positional random chance in between (bedrock floor, deepslate transition).
 */
final class VerticalGradientCondition extends MaterialCondition{

	public function __construct(
		private readonly PositionalRandomFactory $random,
		public readonly int $trueAtAndBelow,
		public readonly int $falseAtAndAbove
	){}

	public function test(MaterialContext $c) : bool{
		$y = $c->blockY;
		if($y <= $this->trueAtAndBelow){
			return true;
		}
		if($y >= $this->falseAtAndAbove){
			return false;
		}
		$probability = 1.0 - ($y - $this->trueAtAndBelow) / ($this->falseAtAndAbove - $this->trueAtAndBelow);
		return $this->random->at($c->blockX, $y, $c->blockZ)->nextFloat() < $probability;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$t = $this->trueAtAndBelow;
		$f = $this->falseAtAndAbove;
		return "(\$y <= $t || (\$y < $f && " . $compiler->bind($this->random) . "->at(\$x, \$y, \$z)->nextFloat() < 1.0 - (\$y - $t) / " . ($f - $t) . "))";
	}
}
