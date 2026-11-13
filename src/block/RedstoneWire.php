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

namespace pocketmine\block;

use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\RedstonePowerSource;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use function count;
use function max;

class RedstoneWire extends Flowable implements AnalogRedstoneSignalEmitter, RedstonePowerSource{
	use AnalogRedstoneSignalEmitterTrait;
	use StaticSupportTrait;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		return $this;
	}

	/** @return array<int, RedstoneWire> Horizontal side => connected wire, including steps. */
	public function getConnectedWires() : array{
		$pos = $this->position;
		$world = $pos->getWorld();
		$above = RedstoneSignal::getBlockAt($world, $pos->getFloorX(), $pos->getFloorY() + 1, $pos->getFloorZ());
		$result = [];
		foreach(Facing::HORIZONTAL as $side){
			$next = $pos->getSide($side);
			$block = RedstoneSignal::getBlockAt($world, $next->getFloorX(), $next->getFloorY(), $next->getFloorZ());
			if($block === null){
				continue;
			}
			if($block instanceof self){
				$result[$side] = $block;
			}elseif($block->isSolid()){
				if($above !== null && !$above->isSolid()){
					$up = RedstoneSignal::getBlockAt($world, $next->getFloorX(), $next->getFloorY() + 1, $next->getFloorZ());
					if($up instanceof self){
						$result[$side] = $up;
					}
				}
			}else{
				$down = RedstoneSignal::getBlockAt($world, $next->getFloorX(), $next->getFloorY() - 1, $next->getFloorZ());
				if($down instanceof self){
					$result[$side] = $down;
				}
			}
		}
		return $result;
	}

	public function getWeakPower(int $face) : int{
		if($this->signalStrength === 0 || $face === Facing::UP){
			return 0;
		}
		if($face === Facing::DOWN){
			return $this->signalStrength;
		}
		$connections = $this->getConnectedWires();
		foreach(Facing::HORIZONTAL as $side){
			$next = $this->position->getSide($side);
			$block = RedstoneSignal::getBlockAt($this->position->getWorld(), $next->getFloorX(), $next->getFloorY(), $next->getFloorZ());
			if($block instanceof RedstonePowerSource && !($block instanceof self)){
				$connections[$side] = $block;
			}
		}
		if(count($connections) === 0){
			return $this->signalStrength;
		}
		if(count($connections) === 1){
			foreach($connections as $side => $_){
				return $face === $side || $face === Facing::opposite($side) ? $this->signalStrength : 0;
			}
		}
		return isset($connections[$face]) ? $this->signalStrength : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->getWeakPower($face);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		// Exclude every wire from external power queries, including wires powering the support block.
		// Otherwise a circuit could keep itself powered after its last source is removed.
		$power = RedstoneSignal::getReceivedPower($this, true);
		foreach($this->getConnectedWires() as $wire){
			$power = max($power, $wire->getOutputSignalStrength() - 1);
		}
		$power = RedstoneSignal::filterChange($this, $this->signalStrength, $power);
		if($power !== $this->signalStrength){
			$this->position->getWorld()->setBlock($this->position, $this->setOutputSignalStrength($power));
		}
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN)->hasCenterSupport();
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}
}

