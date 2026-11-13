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

namespace pocketmine\world\generator\vanilla\surface\rule;

use pocketmine\world\generator\vanilla\surface\ClayBands;
use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;

/** "minecraft:bandlands": the terracotta band at the block's height, shifted by a noise per column. */
final class BandlandsRule extends MaterialRule{

	public function __construct(
		private readonly ClayBands $bands
	){}

	public function apply(MaterialContext $c) : int{
		return $this->bands->get($c);
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		return "return " . $compiler->bind($this) . "->apply(\$c);\n";
	}
}
