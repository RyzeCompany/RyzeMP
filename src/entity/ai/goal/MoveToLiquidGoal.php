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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\ai\navigation\BlockSearch;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;

/**
 * Goes back to water (vanilla behavior.move_to_water: axolotls, turtles, stranded dolphins, pregnant frogs) or lava
 * (behavior.move_to_liquid with material_type Lava: striders) when out of it. Lava is looked for at its surface (the
 * mob walks onto it), and the mob counts as being in it when $isInLiquid says so (default: its feet in water, its box
 * in lava).
 */
class MoveToLiquidGoal extends MoveToBlockGoal{
	public const WATER = BlockSearch::WATER;
	public const LAVA = BlockSearch::LAVA;

	/**
	 * @param int $liquid WATER or LAVA
	 * @phpstan-param (\Closure() : bool)|null $condition
	 * @phpstan-param (\Closure() : bool)|null $isInLiquid
	 */
	public function __construct(
		Mob $mob,
		protected int $liquid = self::WATER,
		float $speedMultiplier = 1.0,
		int $searchRange = 16,
		int $searchHeight = 5,
		float $goalRadius = 0.5,
		?\Closure $condition = null,
		protected ?\Closure $isInLiquid = null
	){
		parent::__construct($mob, $speedMultiplier, $searchRange, $searchHeight, $goalRadius, $condition);
	}

	protected function wantsToMove() : bool{
		if($this->isInLiquid !== null){
			return !($this->isInLiquid)();
		}
		return $this->liquid === self::WATER ? !$this->mob->isInWater() : !$this->mob->isInLava();
	}

	protected function findTarget(int $x, int $y, int $z) : ?Vector3{
		if($this->liquid === self::LAVA){
			//the surface: the mob stands in the block above it
			return BlockSearch::findNearestLiquid($this->mob->getWorld(), $x, $y, $z, $this->searchRange, $this->searchHeight, BlockSearch::LAVA, true)?->add(0, 1, 0);
		}
		return BlockSearch::findNearestLiquid($this->mob->getWorld(), $x, $y, $z, $this->searchRange, $this->searchHeight, BlockSearch::WATER);
	}
}
