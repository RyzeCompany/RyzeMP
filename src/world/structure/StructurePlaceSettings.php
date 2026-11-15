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

namespace pocketmine\world\structure;

/**
 * How a structure template is loaded into a world (the load settings of a structure block).
 */
final class StructurePlaceSettings{
	public const ANIMATION_NONE = 0;
	public const ANIMATION_BY_LAYER = 1;
	public const ANIMATION_BY_BLOCK = 2;

	/**
	 * @param float $integrity     percentage (0-100) of the blocks that are placed
	 * @param int   $seed          seed of the random choice of the blocks placed with integrity below 100 (0: random)
	 * @param int   $animationMode ANIMATION_* constant: place the blocks over $animationSeconds
	 */
	public function __construct(
		public readonly StructureMirror $mirror = StructureMirror::NONE,
		public readonly StructureRotation $rotation = StructureRotation::NONE,
		public readonly bool $includeEntities = true,
		public readonly bool $includeBlocks = true,
		public readonly float $integrity = 100.0,
		public readonly int $seed = 0,
		public readonly int $animationMode = self::ANIMATION_NONE,
		public readonly float $animationSeconds = 0.0
	){}

	public function isAnimated() : bool{
		return $this->animationMode !== self::ANIMATION_NONE && $this->animationSeconds > 0;
	}
}
