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

use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MountPathingGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Parched;
use pocketmine\entity\monster\Undead;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\player\Player;
use function count;
use function mt_rand;

/**
 * Camel husk (Bedrock values, camel_husk.json): an undead camel of the desert, 32 health, 1.7x2.375 (0.945 tall when
 * sitting), movement 0.09 (strolls at 2x), takes 4 less fall damage. Tempted by rabbit's feet, which also heal it (2
 * health). Never attacks. Sits down from time to time (1 in 1000 per tick). Loot: 2-3 rotten flesh (+0-1 per Looting
 * level), and its saddle. Experience 1-3.
 *
 * Riding (see {@link CamelTrait}): two seats, for players, parched and camel husk riders ({@link Husk::isCamelHuskRider()});
 * a saddle (saddle screen by sneaking and using it; shears take it off) lets the front player steer it and dash. Naturally spawned camel husks come with a
 * husk rider holding an iron spear in front and a parched behind (spawn_with_rider, see
 * {@link \pocketmine\entity\riding\Jockeys::spawnCamelHuskRiders()}). While a hostile rider is on it, it can't be
 * saddled nor tempted, and it carries its riders to their target at 4x (behavior.mount_pathing).
 */
class CamelHusk extends Mob implements Undead, MountInventoryHolder{
	use CamelTrait;

	public static function getNetworkTypeId() : string{ return EntityIds::CAMEL_HUSK; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.375, 1.7, 2.275);
	}

	public function getName() : string{
		return "Camel Husk";
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
		$this->goalSelector->addGoal(3, new MountPathingGoal($this, 4.0));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 2.5, fn(Item $item) => $item->getTypeId() === ItemTypeIds::RABBIT_FOOT && !$this->hasHostileRider()));
		$this->goalSelector->addGoal(5, $this->createSitGoal());
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 2.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
	}

	/**
	 * Whether a mob (a husk or parched rider) rides it: vanilla camel_husk_with_hostile_rider, which takes away its
	 * saddle slot and tempting until all riders got off.
	 */
	public function hasHostileRider() : bool{
		foreach($this->passengers as $passenger){
			if(!$passenger instanceof Player){
				return true;
			}
		}
		return false;
	}

	public function canBeLeashed() : bool{
		//leashable with the camel_husk_with_no_hostile_rider filter
		return !$this->hasHostileRider();
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->hasHostileRider()){
			if($this->openInventoryBySneaking($player)){
				return true;
			}
			if(!$this->isSaddled() && $item->getTypeId() === ItemTypeIds::SADDLE && !$player->isSneaking()){
				$this->consumeHeldItem($player);
				$this->setSaddled(true); //the inventory listener plays the sound
				return true;
			}
			if($item->getTypeId() === ItemTypeIds::SHEARS && !$player->isSneaking() && $this->shearSaddle($player)){
				return true;
			}
			if($item->getTypeId() === ItemTypeIds::RABBIT_FOOT && $this->getHealth() < $this->getMaxHealth()){
				$this->consumeHeldItem($player);
				$this->setHealth($this->getHealth() + 2);
				return true;
			}
		}
		return $this->tryRide($player);
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($this->hasHostileRider()){
			return null;
		}
		if($player->isSneaking()){
			return count($this->passengers) === 0 && $player->getVehicle() !== $this && $this->canOpenInventory($player) ? "action.interact.opencontainer" : null;
		}
		if($item->getTypeId() === ItemTypeIds::SADDLE && !$this->isSaddled()){
			return "action.interact.saddle";
		}
		if($item->getTypeId() === ItemTypeIds::SHEARS && $this->isSaddled() && count($this->passengers) === 0){
			return "action.interact.removesaddle";
		}
		return null;
	}

	public function getRideableSeats() : array{
		return $this->getCamelSeats();
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Player || $entity instanceof Parched || ($entity instanceof Husk && $entity->isCamelHuskRider());
	}

	protected function hasSaddleSlot() : bool{
		return !$this->hasHostileRider();
	}

	protected function onPassengerAdded(Entity $passenger) : void{
		parent::onPassengerAdded($passenger);
		$this->setSitting(false);
		$this->networkPropertiesDirty = true; //the saddle slot goes away with a hostile rider
	}

	protected function onPassengerRemoved(Entity $passenger) : void{
		parent::onPassengerRemoved($passenger);
		$this->networkPropertiesDirty = true;
	}

	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		parent::handleRiderInput($rider, $input);
		$this->handleDashInput($rider, $input);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled()){
			$this->setSitting(false);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->tickCamel($tickDiff);
		return $hasUpdate;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function getDrops() : array{
		$drops = [VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(2, 3) + $this->lootingBonus())];
		if($this->isSaddled()){
			$drops[] = VanillaItems::SADDLE();
		}
		return $drops;
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::CAMEL_HUSK_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncCamelData($properties);
	}
}
