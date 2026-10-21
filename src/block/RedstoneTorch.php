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
use pocketmine\block\utils\RedstonePowerSource;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\block\utils\RedstoneTickingComponent;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\math\Facing;

class RedstoneTorch extends Torch implements Lightable, RedstonePowerSource, RedstoneTickingComponent{
	use LightableTrait;

	public function getWeakPower(int $face) : int{
		return $this->lit && $face !== Facing::opposite($this->facing) ? 15 : 0;
	}

	public function getStrongPower(int $face) : int{
		return $this->lit && $face === Facing::UP ? 15 : 0;
	}

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

	private function hasSupport() : bool{
		$type = $this->getAdjacentSupportType(Facing::opposite($this->facing));
		return $this->facing === Facing::UP ? $type->hasCenterSupport() : $type === SupportType::FULL;
	}

	private function shouldBeLit() : bool{
		return RedstoneSignal::getPowerFromSide($this, Facing::opposite($this->facing)) === 0;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->hasSupport()){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$circuit = $this->position->getWorld()->getRedstoneCircuit();
		if($this->lit !== $this->shouldBeLit()){
			$circuit->schedule($this, 2, $this->lit ? 0 : 15);
		}
	}

	public function onRedstoneUpdate(int $targetPower) : void{
		if(!$this->hasSupport()){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$world = $this->position->getWorld();
		$lit = $this->shouldBeLit();
		$lit = RedstoneSignal::filterChange($this, $this->lit ? 15 : 0, $lit ? 15 : 0) > 0;
		if($this->lit !== $lit){
			$this->lit = $lit;
			$world->setBlock($this->position, $this);
		}
	}
}

