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
use function count;

/** "minecraft:biome": the (fuzzy-zoomed) biome of the block is one of the listed ones. */
final class BiomeCondition extends MaterialCondition{
	/**
	 * @param bool[] $matches biome index => whether it matches
	 * @phpstan-param array<int, bool> $matches
	 */
	public function __construct(
		private readonly array $matches
	){}

	public function test(MaterialContext $c) : bool{
		return $this->matches[$c->biome()] ?? false;
	}

	public function compile(MaterialRuleCompiler $compiler) : string{
		return count($this->matches) === 0 ? "false" : "isset(" . $compiler->bind($this->matches) . "[" . $compiler->lazy("\$c->biome()") . "])";
	}
}
