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

namespace pocketmine\world\generator\vanilla\structure\processor;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;

/**
 * Vanilla's StructureProcessor. Block infos are arrays [x, y, z, Java state, block entity data or null]: the original
 * info is template-relative, the current one is in the world.
 *
 * @phpstan-type BlockInfo array{int, int, int, int, ?CompoundTag}
 */
abstract class StructureProcessor{

	/**
	 * Whether the processor needs every block of the template (finalizeProcessing() over the whole list), which
	 * prevents clipping the template to the chunk before processing.
	 */
	public function needsAllBlocks() : bool{
		return false;
	}

	/**
	 * @param int[]   $origin   template position
	 * @param int[]   $pivot    structure reference position (StructureStart: first piece's box center at its min y)
	 * @param mixed[] $original
	 * @param mixed[] $current
	 * @phpstan-param array{int, int, int} $origin
	 * @phpstan-param array{int, int, int} $pivot
	 * @phpstan-param BlockInfo $original
	 * @phpstan-param BlockInfo $current
	 * @phpstan-return BlockInfo|null
	 */
	abstract public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : ?array;

	/**
	 * @param mixed[][] $originals
	 * @param mixed[][] $processed
	 * @param int[]     $origin
	 * @param int[]     $pivot
	 * @phpstan-param array{int, int, int} $origin
	 * @phpstan-param array{int, int, int} $pivot
	 * @phpstan-param list<BlockInfo> $originals
	 * @phpstan-param list<BlockInfo> $processed
	 * @phpstan-return list<BlockInfo>
	 */
	public function finalize(StructurePlaceContext $c, array $origin, array $pivot, array $originals, array $processed, PlaceSettings $settings) : array{
		return $processed;
	}
}
