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

use pocketmine\block\utils\Lightable;
use pocketmine\block\utils\LightableTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\world\redstone\RedstoneEngine;
use pocketmine\world\sound\FizzSound;

class RedstoneTorch extends Torch implements Lightable{
	use LightableTrait;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo){
		$this->lit = true;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		parent::describeBlockOnlyState($w);
		$w->bool($this->lit);
	}

	public function getLightLevel() : int{
		return $this->lit ? 7 : 0;
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		//powers everything around it, except the block it is attached to
		return $this->lit && $face !== Facing::opposite($this->facing) ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->lit && $face === Facing::UP ? 15 : 0;
	}

	/**
	 * Returns whether the block this torch is attached to is powered (by anything other than this torch).
	 */
	private function isInputPowered(RedstoneEngine $redstone) : bool{
		$pos = $this->position;
		return $redstone->getPowerFrom($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), Facing::opposite($this->facing)) > 0;
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null || !$world->getBlock($this->position)->hasSameTypeId($this)){
			return; //disabled, or broken because its support is gone
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if($redstone->isTorchBurnedOut($x, $y, $z)){
			return;
		}
		if($this->lit === $this->isInputPowered($redstone) && !$world->isBlockUpdateScheduled($x, $y, $z)){
			$world->scheduleDelayedBlockUpdate($this->position, RedstoneEngine::REDSTONE_TICK);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$powered = $this->isInputPowered($redstone);
		if($this->lit && $powered){
			$burnedOut = $redstone->recordTorchTurnOff($x, $y, $z);
			$this->setLitAndUpdate(false);
			if($burnedOut){
				$world->addSound($this->position->add(0.5, 0.5, 0.5), new FizzSound());
				//try to relight once the burnout is over
				$world->scheduleDelayedBlockUpdate($this->position, RedstoneEngine::TORCH_BURNOUT_COOLDOWN_TICKS);
			}
		}elseif(!$this->lit && !$powered && !$redstone->isTorchBurnedOut($x, $y, $z)){
			$this->setLitAndUpdate(true);
		}
	}

	private function setLitAndUpdate(bool $lit) : void{
		$this->lit = $lit;
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this);
		$world->getRedstone()?->updateAroundSide($this->position, Facing::UP);
	}

	public function onPostPlace() : void{
		parent::onPostPlace();
		if($this->lit){
			$this->position->getWorld()->getRedstone()?->updateAroundSide($this->position, Facing::UP);
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone !== null){
			$redstone->clearTorchState($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
			if($this->lit){
				$redstone->updateAroundSide($this->position, Facing::UP);
			}
		}
		return $result;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
