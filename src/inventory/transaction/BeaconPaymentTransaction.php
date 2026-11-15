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

namespace pocketmine\inventory\transaction;

use pocketmine\block\Beacon;
use pocketmine\block\inventory\BeaconInventory;
use pocketmine\entity\effect\Effect;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\player\Player;
use pocketmine\world\sound\BeaconPowerSound;
use function count;

/**
 * Choosing the powers of a beacon in its window. Exactly one payment item (iron ingot, gold ingot, emerald, diamond
 * or netherite ingot) must be taken out of the payment slot and destroyed, and the powers must be allowed by the
 * pyramid under the beacon, which is checked again when the transaction is validated.
 */
class BeaconPaymentTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly BeaconInventory $beaconInventory,
		private readonly Effect $primaryEffect,
		private readonly ?Effect $secondaryEffect,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	public function getBeaconInventory() : BeaconInventory{ return $this->beaconInventory; }

	public function getPrimaryEffect() : Effect{ return $this->primaryEffect; }

	public function getSecondaryEffect() : ?Effect{ return $this->secondaryEffect; }

	public function validate() : void{
		$this->squashDuplicateSlotChanges();
		if(count($this->actions) === 0){
			throw new TransactionValidationException("Inventory transaction must have at least one action to be executable");
		}
		$haveItems = [];
		$needItems = [];
		$this->matchItems($needItems, $haveItems);
		if(count($needItems) > 0){
			throw new TransactionValidationException("Transaction does not balance (tried to create some items)");
		}
		//the only item allowed to disappear is the payment
		if(count($haveItems) !== 1 || $haveItems[0]->getCount() !== 1 || !Beacon::isPaymentItem($haveItems[0])){
			throw new TransactionValidationException("Exactly one payment item must be consumed");
		}

		$paid = false;
		foreach($this->actions as $action){
			if($action instanceof SlotChangeAction && $action->getInventory() === $this->beaconInventory){
				$source = $action->getSourceItem();
				$target = $action->getTargetItem();
				if(!Beacon::isPaymentItem($source)){
					throw new TransactionValidationException("The beacon payment slot does not hold a payment item");
				}
				if(!$target->isNull() && (!$target->canStackWith($source) || $target->getCount() !== $source->getCount() - 1)){
					throw new TransactionValidationException("Exactly one payment item must be consumed");
				}
				if($target->isNull() && $source->getCount() !== 1){
					throw new TransactionValidationException("Exactly one payment item must be consumed");
				}
				$paid = true;
			}
		}
		if(!$paid){
			throw new TransactionValidationException("No payment item was consumed");
		}

		$block = $this->beaconInventory->getHolder()->getWorld()->getBlock($this->beaconInventory->getHolder());
		if(!$block instanceof Beacon){
			throw new TransactionValidationException("The beacon is gone");
		}
		$holder = $block->getPosition();
		$level = Beacon::calculatePyramidLevel($holder->getWorld(), $holder->getFloorX(), $holder->getFloorY(), $holder->getFloorZ());
		if(!Beacon::isValidSelection($this->primaryEffect, $this->secondaryEffect, $level)){
			throw new TransactionValidationException("These powers are not available with a pyramid of level $level");
		}
	}

	public function execute() : void{
		parent::execute();

		$holder = $this->beaconInventory->getHolder();
		$world = $holder->getWorld();
		$block = $world->getBlock($holder);
		if($block instanceof Beacon){
			$block->setPrimaryEffect($this->primaryEffect);
			$block->setSecondaryEffect($this->secondaryEffect);
			$world->setBlock($holder, $block);
			$world->addSound($holder->add(0.5, 0.5, 0.5), new BeaconPowerSound());
		}
	}
}
