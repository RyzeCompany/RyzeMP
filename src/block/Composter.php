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

use pocketmine\block\utils\ComparatorReadable;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\event\block\ComposterEmptyEvent;
use pocketmine\event\block\ComposterFillEvent;
use pocketmine\event\block\ComposterReadyEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\particle\BoneMealUseParticle;
use pocketmine\world\sound\ComposterEmptySound;
use pocketmine\world\sound\ComposterFillSound;
use pocketmine\world\sound\ComposterFillSuccessSound;
use pocketmine\world\sound\ComposterReadySound;
use pocketmine\world\vibration\GameEvent;
use function max;
use function mt_rand;

class Composter extends Transparent implements ComparatorReadable{
	public const MIN_COMPOST_LAYERS = 0;
	public const MAX_COMPOST_LAYERS = 8;

	protected int $layers = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_COMPOST_LAYERS, $this->layers);
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, (max(1, 15 - (2 * $this->layers)) - (int) ($this->layers === 0)) / 16)
		];

		foreach(Facing::HORIZONTAL as $f){
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getLayers() : int{ return $this->layers; }

	/** @return $this */
	public function setLayers(int $layers) : self{
		if($layers < 0 || $layers > self::MAX_COMPOST_LAYERS){
			throw new \InvalidArgumentException("Compost layers must be in range 0 ... " . self::MAX_COMPOST_LAYERS);
		}
		$this->layers = $layers;
		return $this;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->layers >= self::MAX_COMPOST_LAYERS){
			$event = new ComposterEmptyEvent($this, self::MIN_COMPOST_LAYERS, [VanillaItems::BONE_MEAL()]);
			$event->call();

			if($event->isCancelled()){
				return true;
			}

			$this->setLayers($event->getLayer());
			$this->position->getWorld()->setBlock($this->position, $this);
			$this->position->getWorld()->addSound($this->position, new ComposterEmptySound());
			$this->position->getWorld()->addGameEvent($this->position, GameEvent::BLOCK_CHANGE, $player);

			foreach($event->getDrops() as $drop){
				$this->position->getWorld()->dropItem($this->position->add(0.5, 1.0, 0.5), $drop);
			}
			return true;
		}

		if($this->layers === self::MAX_COMPOST_LAYERS - 1){
			//full, waiting to become ready: vanilla ignores input. Reschedule in case the pending update was lost
			//(scheduled updates are not saved, so a chunk unload during that second would leave it stuck here)
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 20);
			return true;
		}

		if(!$item->isNull() && $item->isCompostable()){
			$event = new ComposterFillEvent(
				$this,
				$item,
				$this->layers,
				mt_rand(1, 100) <= $item->getCompostabilityChance() ? $this->layers + 1 : $this->layers
			);
			$event->call();

			if($event->isCancelled()){
				return true;
			}

			//the item is used up whether or not the layer rises, as in vanilla
			$item->pop();
			$world = $this->position->getWorld();
			$world->addParticle($this->position->add(0.5, 0.5, 0.5), new BoneMealUseParticle());
			if($event->getOldFillLayer() < $event->getNewFillLayer()){
				$this->setLayers($event->getNewFillLayer());
				$world->setBlock($this->position, $this);
				$world->addSound($this->position, new ComposterFillSuccessSound());
				$world->addGameEvent($this->position, GameEvent::BLOCK_CHANGE, $player);

				if($this->layers === self::MAX_COMPOST_LAYERS - 1){
					$world->scheduleDelayedBlockUpdate($this->position, 20);
				}
			}else{
				$world->addSound($this->position, new ComposterFillSound());
			}
		}

		return true;
	}

	public function onScheduledUpdate() : void{
		if($this->layers !== self::MAX_COMPOST_LAYERS - 1){
			return;
		}
		$event = new ComposterReadyEvent($this);
		$event->call();

		if($event->isCancelled()){
			return;
		}

		$this->layers = self::MAX_COMPOST_LAYERS;
		$this->position->getWorld()->setBlock($this->position, $this);
		$this->position->getWorld()->addSound($this->position, new ComposterReadySound());
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$drops = parent::getDropsForCompatibleTool($item);
		if($this->layers === self::MAX_COMPOST_LAYERS){
			$drops[] = VanillaItems::BONE_MEAL();
		}
		return $drops;
	}

	public function getFlameEncouragement() : int{ return 5; }

	public function getFlammability() : int{ return 20; }

	public function getFuelTime() : int{ return 300; }

	public function getComparatorOutput() : int{
		return $this->layers;
	}
}
