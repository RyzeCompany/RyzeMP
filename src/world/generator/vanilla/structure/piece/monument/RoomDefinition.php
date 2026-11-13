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

namespace pocketmine\world\generator\vanilla\structure\piece\monument;

use pocketmine\world\generator\vanilla\feature\block\Direction;

/**
 * OceanMonumentPieces.RoomDefinition: a cell of the monument's 5x3x5 room grid (or one of the special rooms: wings
 * and penthouse), with its connections to the neighbouring cells (indexed by Java 3D data value).
 */
final class RoomDefinition{
	/** @phpstan-var array<int, RoomDefinition|null> */
	public array $connections = [null, null, null, null, null, null];
	/** @phpstan-var array<int, bool> */
	public array $hasOpening = [false, false, false, false, false, false];
	public bool $claimed = false;
	public bool $isSource = false;
	public int $scanIndex = 0;

	public function __construct(
		public readonly int $index
	){}

	public function setConnection(int $direction, RoomDefinition $definition) : void{
		$this->connections[$direction] = $definition;
		$definition->connections[Direction::OPPOSITE[$direction]] = $this;
	}

	public function updateOpenings() : void{
		for($i = 0; $i < 6; ++$i){
			$this->hasOpening[$i] = $this->connections[$i] !== null;
		}
	}

	public function findSource(int $scanIndex) : bool{
		if($this->isSource){
			return true;
		}
		$this->scanIndex = $scanIndex;
		for($i = 0; $i < 6; ++$i){
			$connection = $this->connections[$i];
			if($connection !== null && $this->hasOpening[$i] && $connection->scanIndex !== $scanIndex && $connection->findSource($scanIndex)){
				return true;
			}
		}
		return false;
	}

	public function isSpecial() : bool{
		return $this->index >= 75;
	}

	public function countOpenings() : int{
		$count = 0;
		for($i = 0; $i < 6; ++$i){
			if($this->hasOpening[$i]){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The connection in a direction, which the caller knows exists.
	 */
	public function get(int $direction) : RoomDefinition{
		return $this->connections[$direction] ?? throw new \LogicException("Monument room $this->index has no connection towards $direction");
	}
}
