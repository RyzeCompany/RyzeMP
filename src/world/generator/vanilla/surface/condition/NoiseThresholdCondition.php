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

use pocketmine\world\generator\vanilla\noise\NoiseStack;
use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;

/**
 * "minecraft:noise_threshold": a noise sampled at (x, 0, z), or at (x, y, z) if is_3d, is within the thresholds.
 */
final class NoiseThresholdCondition extends MaterialCondition{

	public function __construct(
		private readonly NoiseStack $noise,
		private readonly float $minThreshold,
		private readonly float $maxThreshold,
		private readonly bool $is3d
	){}

	public function test(MaterialContext $c) : bool{
		$value = $this->is3d ? $c->noise3d($this->noise) : $c->noise2d($this->noise);
		return $this->minThreshold <= $value && $value <= $this->maxThreshold;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		$noise = $compiler->bind($this->noise);
		$value = $compiler->lazy($this->is3d ? "\$c->noise3d($noise)" : "\$c->noise2d($noise)");
		return "((\$v = $value) >= " . MaterialRuleCompiler::float($this->minThreshold) . " && \$v <= " . MaterialRuleCompiler::float($this->maxThreshold) . ")";
	}
}
