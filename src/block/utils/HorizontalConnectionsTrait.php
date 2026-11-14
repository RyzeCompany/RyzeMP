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

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\math\Facing;
use function array_values;

/**
 * Shared logic for blocks whose horizontal connections (fences, glass panes, iron bars, trip wire, ...) are part of
 * their block state. Since Bedrock 1.26.50 the client renders these from the minecraft:connection_* states instead of
 * inferring them from the neighbouring blocks, so the server has to track them.
 */
trait HorizontalConnectionsTrait{
	/**
	 * facing => facing, as expected by RuntimeDataDescriber::horizontalFacingFlags()
	 *
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	protected array $connections = [];

	/**
	 * Returns the horizontal faces this block is connected on.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function getConnections() : array{
		return array_values($this->connections);
	}

	public function hasConnection(int $facing) : bool{
		return isset($this->connections[$facing]);
	}

	/** @return $this */
	public function setConnection(int $facing, bool $connected) : self{
		if($facing !== Facing::NORTH && $facing !== Facing::SOUTH && $facing !== Facing::WEST && $facing !== Facing::EAST){
			throw new \InvalidArgumentException("Facing can only be north, east, south or west");
		}
		if($connected){
			$this->connections[$facing] = $facing;
		}else{
			unset($this->connections[$facing]);
		}
		return $this;
	}

	/**
	 * Returns whether this block should connect to the given neighbour on the given side.
	 */
	abstract protected function canConnectTo(Block $block, int $facing) : bool;

	/**
	 * Recomputes the connections from the neighbouring blocks. Returns true if anything changed.
	 */
	protected function recalculateConnections() : bool{
		$changed = false;
		foreach(Facing::HORIZONTAL as $facing){
			$connect = $this->canConnectTo($this->getSide($facing), $facing);
			if($connect !== isset($this->connections[$facing])){
				$changed = true;
				if($connect){
					$this->connections[$facing] = $facing;
				}else{
					unset($this->connections[$facing]);
				}
			}
		}
		if($changed){
			$this->collisionBoxes = null;
		}
		return $changed;
	}
}
