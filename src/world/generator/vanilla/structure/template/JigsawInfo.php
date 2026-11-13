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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\world\generator\vanilla\feature\block\Direction;

/**
 * A jigsaw block of a template (StructureTemplate.JigsawBlockInfo): position (template-relative, or in the world once
 * transformed), front and top directions of its orientation, and its jigsaw data.
 */
final class JigsawInfo{

	public function __construct(
		public readonly int $x,
		public readonly int $y,
		public readonly int $z,
		public readonly int $front,
		public readonly int $top,
		public readonly string $name,
		public readonly string $target,
		public readonly string $pool,
		public readonly string $finalState,
		public readonly bool $rollable,
		public readonly int $placementPriority,
		public readonly int $selectionPriority
	){}

	/**
	 * This jigsaw at a new position with its orientation rotated.
	 */
	public function transformed(int $x, int $y, int $z, int $rotation) : self{
		return new self(
			$x, $y, $z,
			Rotations::rotateDirection($this->front, $rotation),
			Rotations::rotateDirection($this->top, $rotation),
			$this->name,
			$this->target,
			$this->pool,
			$this->finalState,
			$this->rollable,
			$this->placementPriority,
			$this->selectionPriority
		);
	}

	/**
	 * JigsawBlock.canAttach(): this jigsaw (of the placed piece) can connect to the other one (of the candidate).
	 */
	/**
	 * The same jigsaw at a position offset by the given amounts (directions unchanged).
	 */
	public function moved(int $dx, int $dy, int $dz) : self{
		return new self(
			$this->x + $dx, $this->y + $dy, $this->z + $dz,
			$this->front,
			$this->top,
			$this->name,
			$this->target,
			$this->pool,
			$this->finalState,
			$this->rollable,
			$this->placementPriority,
			$this->selectionPriority
		);
	}

	public function canAttach(JigsawInfo $other) : bool{
		return $this->front === Direction::OPPOSITE[$other->front] &&
			($this->rollable || $this->top === $other->top) &&
			$this->target === $other->name;
	}
}
