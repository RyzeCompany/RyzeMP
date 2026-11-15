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

namespace pocketmine\command\utils;

use pocketmine\block\Block;
use pocketmine\nbt\tag\Tag;

/**
 * A block named in a command (see {@link CommandBlockParser}): the block, its Bedrock name and the states that were
 * given explicitly (the others have their default values).
 */
final class ParsedBlock{
	/**
	 * @param Tag[] $givenStates
	 * @phpstan-param array<string, Tag> $givenStates
	 */
	public function __construct(
		private Block $block,
		private string $name,
		private array $givenStates
	){}

	public function getBlock() : Block{ return clone $this->block; }

	/** Bedrock name, with "minecraft:". */
	public function getName() : string{ return $this->name; }

	/**
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	public function getGivenStates() : array{ return $this->givenStates; }

	/** Whether a block is this block with the given states (its other states may have any value). */
	public function matches(Block $block) : bool{
		return CommandBlockParser::matches($block, $this->name, $this->givenStates);
	}
}
