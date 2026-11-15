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

namespace pocketmine\block\tile;

use pocketmine\block\Furnace as BlockFurnace;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\crafting\FurnaceRecipe;
use pocketmine\crafting\FurnaceType;
use pocketmine\event\inventory\FurnaceBurnEvent;
use pocketmine\event\inventory\FurnaceSmeltEvent;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\mcpe\protocol\ContainerSetDataPacket;
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_map;
use function floor;
use function intdiv;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function round;

abstract class Furnace extends Spawnable implements ComparatorReadableContainer, Nameable{
	use NameableTrait;
	use ComparatorReadableContainerTrait;
	use ContainerTrait;

	public const TAG_BURN_TIME = "BurnTime";
	public const TAG_COOK_TIME = "CookTime";
	public const TAG_MAX_TIME = "MaxTime";
	/** Whole experience points stored, paid out when a player takes the result (Bedrock, short like Dragonfly) */
	private const TAG_STORED_XP_INT = "StoredXPInt";
	/** Fractional experience stored (not in Bedrock, which only keeps whole points) */
	private const TAG_STORED_XP_FRACTION = "StoredXPFraction";

	protected FurnaceInventory $inventory;
	private int $remainingFuelTime = 0;
	private int $cookTime = 0;
	private int $maxFuelTime = 0;
	private float $storedXp = 0.0;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new FurnaceInventory($this->position, $this->getFurnaceType());
		$this->addComparatorInventoryListener();
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(
			static function(Inventory $unused) use ($world, $pos) : void{
				$world->scheduleDelayedBlockUpdate($pos, 1);
			})
		);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->remainingFuelTime = max(0, $nbt->getShort(self::TAG_BURN_TIME, $this->remainingFuelTime));

		$this->cookTime = $nbt->getShort(self::TAG_COOK_TIME, $this->cookTime);
		if($this->remainingFuelTime === 0){
			$this->cookTime = 0;
		}

		$this->maxFuelTime = $nbt->getShort(self::TAG_MAX_TIME, $this->maxFuelTime);
		if($this->maxFuelTime === 0){
			$this->maxFuelTime = $this->remainingFuelTime;
		}

		$storedXpTag = $nbt->getTag(self::TAG_STORED_XP_INT);
		$storedXp = $storedXpTag instanceof ShortTag || $storedXpTag instanceof IntTag ? $storedXpTag->getValue() : 0;
		$fractionTag = $nbt->getTag(self::TAG_STORED_XP_FRACTION);
		$fraction = $fractionTag instanceof FloatTag ? $fractionTag->getValue() : 0.0;
		$this->storedXp = max(0.0, $storedXp + min(max($fraction, 0.0), 1.0));

		$this->loadName($nbt);
		$this->loadItems($nbt);

		if($this->remainingFuelTime > 0){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setShort(self::TAG_BURN_TIME, $this->remainingFuelTime);
		$nbt->setShort(self::TAG_COOK_TIME, $this->cookTime);
		$nbt->setShort(self::TAG_MAX_TIME, $this->maxFuelTime);
		$wholeXp = (int) floor($this->storedXp);
		$nbt->setShort(self::TAG_STORED_XP_INT, min($wholeXp, 0x7fff));
		$nbt->setFloat(self::TAG_STORED_XP_FRACTION, $this->storedXp - $wholeXp);
		$this->saveName($nbt);
		$this->saveItems($nbt);
	}

	public function getDefaultName() : string{
		return "Furnace";
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();

			parent::close();
		}
	}

	public function getInventory() : FurnaceInventory{
		return $this->inventory;
	}

	public function getRealInventory() : FurnaceInventory{
		return $this->getInventory();
	}

	protected function checkFuel(Item $fuel) : void{
		$ev = new FurnaceBurnEvent($this, $fuel, $this->getBurnTime($fuel));
		$ev->call();
		if($ev->isCancelled()){
			return;
		}

		$this->maxFuelTime = $this->remainingFuelTime = $ev->getBurnTime();
		$this->onStartSmelting();

		if($this->remainingFuelTime > 0 && $ev->isBurning()){
			$this->inventory->setFuel($fuel->getFuelResidue());
		}
	}

	/**
	 * Blast furnaces and smokers burn fuel twice as fast as furnaces (they smelt twice as fast too, so a fuel item
	 * smelts the same number of items in all of them).
	 */
	private function getBurnTime(Item $fuel) : int{
		$fuelTime = $fuel->getFuelTime();
		return $this->getFurnaceType() === FurnaceType::FURNACE || $fuelTime <= 1 ? $fuelTime : intdiv($fuelTime, 2);
	}

	protected function onStartSmelting() : void{
		$block = $this->getBlock();
		if($block instanceof BlockFurnace && !$block->isLit()){
			$block->setLit(true);
			$this->position->getWorld()->setBlock($block->getPosition(), $block);
		}
	}

	protected function onStopSmelting() : void{
		$block = $this->getBlock();
		if($block instanceof BlockFurnace && $block->isLit()){
			$block->setLit(false);
			$this->position->getWorld()->setBlock($block->getPosition(), $block);
		}
	}

	abstract public function getFurnaceType() : FurnaceType;

	/**
	 * Returns the experience stored by smelting, paid out as orbs when a player takes the result, or dropped when the
	 * furnace is broken. Hoppers taking the result leave it stored.
	 */
	public function getStoredExperience() : float{
		return $this->storedXp;
	}

	public function setStoredExperience(float $experience) : void{
		$this->storedXp = max(0.0, $experience);
	}

	/**
	 * Returns the stored experience as a whole number of points, without removing it: the fractional part gives one
	 * more point with that chance.
	 */
	public function rollStoredExperience() : int{
		//rounded first so that e.g. 10 x 0.7 doesn't give 6.999...
		$total = round($this->storedXp, 6);
		$points = (int) floor($total);
		$fraction = $total - $points;
		if($fraction > 0 && mt_rand() / mt_getrandmax() < $fraction){
			++$points;
		}
		return $points;
	}

	/**
	 * Removes the stored experience and returns it as a whole number of points (see {@link Furnace::rollStoredExperience()}).
	 */
	public function takeStoredExperience() : int{
		$points = $this->rollStoredExperience();
		$this->storedXp = 0.0;
		return $points;
	}

	public function onUpdate() : bool{
		//TODO: move this to Block
		if($this->closed){
			return false;
		}

		$this->timings->startTiming();

		$prevCookTime = $this->cookTime;
		$prevRemainingFuelTime = $this->remainingFuelTime;
		$prevMaxFuelTime = $this->maxFuelTime;

		$ret = false;

		$fuel = $this->inventory->getFuel();
		$raw = $this->inventory->getSmelting();
		$product = $this->inventory->getResult();

		$furnaceType = $this->getFurnaceType();
		$smelt = $this->position->getWorld()->getServer()->getCraftingManager()->getFurnaceRecipeManager($furnaceType)->match($raw);
		$canSmelt = ($smelt instanceof FurnaceRecipe && $raw->getCount() > 0 && (($smelt->getResult()->canStackWith($product) && $product->getCount() < $product->getMaxStackSize()) || $product->isNull()));

		if($this->remainingFuelTime <= 0 && $canSmelt && $fuel->getFuelTime() > 0 && $fuel->getCount() > 0){
			$this->checkFuel($fuel);
		}

		if($this->remainingFuelTime > 0){
			--$this->remainingFuelTime;

			if($smelt instanceof FurnaceRecipe && $canSmelt){
				++$this->cookTime;

				if($this->cookTime >= $furnaceType->getCookDurationTicks()){
					$product = $smelt->getResult()->setCount($product->getCount() + 1);

					$ev = new FurnaceSmeltEvent($this, $raw, $product);
					$ev->call();

					if(!$ev->isCancelled()){
						$this->inventory->setResult($ev->getResult());
						$this->storedXp += $smelt->getExperience();
						$raw->pop();
						$this->inventory->setSmelting($raw);
					}

					$this->cookTime -= $furnaceType->getCookDurationTicks();
				}
			}elseif($this->remainingFuelTime <= 0){
				$this->remainingFuelTime = $this->cookTime = $this->maxFuelTime = 0;
			}else{
				$this->cookTime = 0;
			}
			$ret = true;
		}else{
			$this->onStopSmelting();
			$this->remainingFuelTime = $this->cookTime = $this->maxFuelTime = 0;
		}

		$viewers = array_map(fn(Player $p) => $p->getNetworkSession()->getInvManager(), $this->inventory->getViewers());
		foreach($viewers as $v){
			if($v === null){
				continue;
			}
			if($prevCookTime !== $this->cookTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_FURNACE_SMELT_PROGRESS, $this->cookTime);
			}
			if($prevRemainingFuelTime !== $this->remainingFuelTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_FURNACE_REMAINING_FUEL_TIME, $this->remainingFuelTime);
			}
			if($prevMaxFuelTime !== $this->maxFuelTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_FURNACE_MAX_FUEL_TIME, $this->maxFuelTime);
			}
		}

		$this->timings->stopTiming();

		return $ret;
	}
}
