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

/** "minecraft:steep": the terrain height changes by 4+ blocks across the column (south or west facing slopes). */
final class SteepCondition extends MaterialCondition{

	public function test(MaterialContext $c) : bool{
		return $c->steep();
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		return $compiler->lazy("\$c->steep()");
	}
}
