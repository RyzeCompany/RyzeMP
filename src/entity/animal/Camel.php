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

namespace pocketmine\entity\animal;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\player\Player;
use function count;

/**
 * Bedrock values (entities/camel.json): 32 health, 1.7x2.375 (0.945 tall when sitting), movement 0.09 (strolls at 2x,
 * panics at 4x), babies scale 0.45. Tempted (2.5x), bred and healed (2) with cactus. Sits down from time to time
 * (SITTING flag) and stands up when hurt. Takes a saddle (no taming needed; its saddle screen opens by sneaking and
 * using it). No loot; XP 1-3.
 *
 * Riding: two players fit; a sitting camel stands up when mounted. Seats, steering and dashing are described in
 * {@link CamelTrait}.
 */
class Camel extends Animal implements MountInventoryHolder{
	use CamelTrait;

	public static function getNetworkTypeId() : string{ return EntityIds::CAMEL; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.375, 1.7, 2.275);
	}

	public function getName() : string{
		return "Camel";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function getBabyScale() : float{
		return 0.45;
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		//camel_baby_standing 2.11 x 3.11, camel_baby_sitting 2.11 x 0.944 (at scale 0.45)
		return $this->isSitting() ? new EntitySizeInfo(0.944, 2.11) : new EntitySizeInfo(3.11, 2.11, 2.979);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(32);
		$this->setMovementSpeed(0.09);
		parent::initEntity($nbt);
		$this->loadCamel($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveCamel($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 4.0));
		$this->goalSelector->addGoal(2, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new TemptGoal($this, 2.5, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(4, $this->createSitGoal());
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 2.5));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 2.0));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === VanillaBlocks::CACTUS()->asItem()->getTypeId();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Camel($location);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled()){
			$this->setSitting(false);
		}
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->openInventoryBySneaking($player)){
			return true;
		}
		if(!$player->isSneaking() && !$this->isSaddled() && !$this->isBaby() && $item->getTypeId() === ItemTypeIds::SADDLE){
			$this->consumeHeldItem($player);
			$this->setSaddled(true); //the inventory listener plays the sound
			return true;
		}
		if($item->getTypeId() === ItemTypeIds::SHEARS && !$player->isSneaking() && $this->shearSaddle($player)){
			return true;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		if($this->isBreedingItem($item) && $this->getHealth() < $this->getMaxHealth()){
			$this->consumeHeldItem($player);
			$this->heal(new EntityRegainHealthEvent($this, 2, EntityRegainHealthEvent::CAUSE_EATING));
			return true;
		}
		return !$this->isBaby() && $this->tryRide($player);
	}

	public function getRideableSeats() : array{
		return $this->isBaby() ? [] : $this->getCamelSeats();
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return !$this->isBaby() && $entity instanceof Player;
	}

	protected function onPassengerAdded(Entity $passenger) : void{
		parent::onPassengerAdded($passenger);
		$this->setSitting(false);
	}

	protected function hasSaddleSlot() : bool{
		return !$this->isBaby(); //the inventory and equippable components are in camel_adult
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($player->isSneaking()){
			return count($this->passengers) === 0 && $player->getVehicle() !== $this && $this->canOpenInventory($player) ? "action.interact.opencontainer" : null;
		}
		if($this->isBaby()){
			return null;
		}
		if($item->getTypeId() === ItemTypeIds::SADDLE && !$this->isSaddled()){
			return "action.interact.saddle";
		}
		if($item->getTypeId() === ItemTypeIds::SHEARS && $this->isSaddled() && count($this->passengers) === 0){
			return "action.interact.removesaddle";
		}
		return null;
	}

	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		parent::handleRiderInput($rider, $input);
		$this->handleDashInput($rider, $input);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->tickCamel($tickDiff);
		return $hasUpdate;
	}

	public function getDrops() : array{
		return $this->isSaddled() ? [VanillaItems::SADDLE()] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncCamelData($properties);
	}
}
