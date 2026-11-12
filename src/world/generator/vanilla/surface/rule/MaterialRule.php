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

use pocketmine\world\generator\vanilla\surface\MaterialContext;
use pocketmine\world\generator\vanilla\surface\MaterialRuleCompiler;

/**
 * Material rule (worldgen/material_rule): picks the block for the solid block in the context, or nothing.
 */
abstract class MaterialRule{
	public const NONE = -1;

	/**
	 * Returns a MaterialPalette id, or NONE.
	 */
	abstract public function apply(MaterialContext $c) : int;

	/**
	 * PHP statements that return the result of apply() if there is one and fall through otherwise, for
	 * MaterialRuleCompiler ($c, $x, $y, $z and $b are in scope).
	 */
	abstract public function compile(MaterialRuleCompiler $compiler) : string;
}
