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

namespace pocketmine\entity\ai\navigation;

use function count;

/**
 * A list of block positions (the block the mob's feet should be in) from the start to the end of a path.
 */
final class Path{
	private int $index = 0;

	/**
	 * @param int[][] $nodes
	 * @phpstan-param list<array{int, int, int}> $nodes
	 */
	public function __construct(
		private array $nodes,
		private bool $reachesTarget,
		private int $targetX,
		private int $targetY,
		private int $targetZ
	){}

	/**
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int}>
	 */
	public function getNodes() : array{
		return $this->nodes;
	}

	public function getLength() : int{
		return count($this->nodes);
	}

	/**
	 * Whether the last node is the target (false: the path only gets as close as the search could).
	 */
	public function reachesTarget() : bool{
		return $this->reachesTarget;
	}

	public function getTargetX() : int{ return $this->targetX; }

	public function getTargetY() : int{ return $this->targetY; }

	public function getTargetZ() : int{ return $this->targetZ; }

	public function getIndex() : int{
		return $this->index;
	}

	public function isDone() : bool{
		return $this->index >= count($this->nodes);
	}

	/**
	 * @phpstan-return array{int, int, int}|null
	 */
	public function getCurrent() : ?array{
		return $this->nodes[$this->index] ?? null;
	}

	/**
	 * @phpstan-return array{int, int, int}|null
	 */
	public function getNode(int $index) : ?array{
		return $this->nodes[$index] ?? null;
	}

	/**
	 * @phpstan-return array{int, int, int}|null
	 */
	public function getEnd() : ?array{
		return $this->nodes[count($this->nodes) - 1] ?? null;
	}

	public function advance() : void{
		++$this->index;
	}

	public function setIndex(int $index) : void{
		$this->index = $index;
	}
}
