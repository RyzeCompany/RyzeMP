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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\CarvedPumpkin;
use pocketmine\block\FenceGate;
use pocketmine\block\Leaves;
use pocketmine\block\Melon;
use pocketmine\block\Pumpkin;
use pocketmine\block\ShulkerBox;
use pocketmine\math\Facing;

/**
 * Shared connection rules of fences and walls (Java FenceBlock/WallBlock.connectsTo, verified against BDS 1.26.52).
 *
 * @internal
 */
final class FenceConnectionRules{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns whether a fence or wall connects to the given neighbour through a sturdy face: full faces count, except
	 * those of leaves, barriers, pumpkins (carved and lit too), melons and shulker boxes (Java
	 * Block.isExceptionForConnection; BDS doesn't connect fences to these either).
	 *
	 * @param int $facing the side of the fence or wall the neighbour is on
	 */
	public static function connectsToFace(Block $block, int $facing) : bool{
		return $block->getSupportType(Facing::opposite($facing)) === SupportType::FULL && !self::isExceptionForConnection($block);
	}

	public static function isExceptionForConnection(Block $block) : bool{
		return
			$block instanceof Leaves ||
			$block instanceof Pumpkin ||
			$block instanceof CarvedPumpkin || //includes jack o'lanterns
			$block instanceof Melon ||
			$block instanceof ShulkerBox ||
			$block->getTypeId() === BlockTypeIds::BARRIER;
	}

	/**
	 * Returns whether a fence or wall connects to the given fence gate: only when the gate is in line with the
	 * connection, i.e. it doesn't face along it (Java FenceGateBlock.connectsToDirection).
	 *
	 * @param int $facing the side of the fence or wall the gate is on
	 */
	public static function connectsToGate(FenceGate $gate, int $facing) : bool{
		return Facing::axis($gate->getFacing()) !== Facing::axis($facing);
	}
}
