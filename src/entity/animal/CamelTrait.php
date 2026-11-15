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

use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\vibration\GameEvent;
use function cos;
use function count;
use function deg2rad;
use function max;
use function mt_rand;
use function sin;

/**
 * What camels and camel husks share (camel.json, camel_husk.json): 1.7x2.375 (0.945 tall when sitting), sitting down
 * from time to time (SITTING flag), two seats (1.905 up, 0.5 forwards and backwards), a saddle (no taming needed) that
 * lets the front rider steer (WASD_CONTROLLED; clients predict it like horses, otherwise the server moves it at the
 * movement attribute x 2.1 blocks per tick, +0.1 movement when sprinting) and dash by releasing the jump button
 * (CAN_DASH, vanilla dash_action: 2.75 s cooldown shown with HAS_DASH_COOLDOWN, momentum 20 x movement forwards and
 * 0.6 up, scaled by how long jump was held). Both walk up 1.5 blocks without jumping and take 4 less fall damage.
 * The saddle is kept in a one-slot {@link HorseInventory} (vanilla inventory container_type horse) opened with the horse
 * screen by sneaking and using it, or with the inventory button of its rider. Riders are thrown off after 2 seconds
 * with it under water.
 *
 * The using class implements {@link \pocketmine\entity\riding\MountInventoryHolder} and
 * {@link CamelTrait::hasSaddleSlot()}, and calls {@link CamelTrait::loadCamel()} from initEntity(),
 * {@link CamelTrait::saveCamel()}, {@link CamelTrait::handleDashInput()} from handleRiderInput(),
 * {@link CamelTrait::tickCamel()} from entityBaseTick() and {@link CamelTrait::syncCamelData()} from syncNetworkData().
 */
trait CamelTrait{
	public const TAG_SADDLED = "Saddled"; //TAG_Byte
	public const TAG_CHEST_ITEMS = "ChestItems"; //TAG_List<TAG_Compound>
	public const TAG_SITTING = "Sitting"; //TAG_Byte
	/** random_look_around_and_sit probability: chance per tick to sit down. */
	public const SIT_CHANCE_PER_TICK = 0.001;
	private const DASH_COOLDOWN_TICKS = 55;
	/** variable_max_auto_step: walks up 1.5 blocks without jumping */
	private const STEP_HEIGHT = 1.5625;
	private const MAX_VIEWER_DISTANCE = 8.0;
	/** behavior.float time_under_water_to_dismount_passengers: 2 seconds. */
	private const UNDERWATER_DISMOUNT_TICKS = 40;

	/** The saddle slot (vanilla inventory container_type horse, equippable slot 0). */
	protected HorseInventory $inventory;
	private bool $silentInventoryChange = false;
	private bool $sitting = false;
	/** Ticks the rider has held the jump button, -1 when not charging a dash. */
	private int $dashChargeTicks = -1;
	private int $dashCooldown = 0;
	/** Ticks it has been under water with riders on it. */
	private int $underwaterRideTicks = 0;

	/**
	 * Called from initEntity(), after the parent: reads the camel NBT (the saddle, saved like Bedrock's container
	 * component, or the older "Saddled" byte; sitting, continue_sitting_on_reload) and sets the step height.
	 */
	protected function loadCamel(CompoundTag $nbt) : void{
		$this->inventory = new HorseInventory($this, 1);
		$this->inventory->getListeners()->add(new CallbackInventoryListener(
			function(Inventory $unused, int $slot, Item $before) : void{
				$this->networkPropertiesDirty = true;
				$item = $this->inventory->getItem($slot);
				if(!$this->silentInventoryChange && !$item->isNull() && !$item->equals($before)){
					$this->playMobSound(LevelSoundEvent::SADDLE);
				}
			},
			function(Inventory $unused, array $before) : void{
				$this->networkPropertiesDirty = true;
			}
		));
		$saddled = $nbt->getByte(self::TAG_SADDLED, 0) !== 0;
		$itemsTag = $nbt->getTag(self::TAG_CHEST_ITEMS);
		if($itemsTag instanceof ListTag && $itemsTag->getTagType() === NBT::TAG_Compound){
			$saddled = false;
			/** @var CompoundTag $itemTag */
			foreach($itemsTag as $itemTag){
				if($itemTag->getByte(SavedItemStackData::TAG_SLOT, -1) === HorseInventory::SLOT_EQUIP &&
					Item::safeNbtDeserialize($itemTag, "Camel ChestItems")->getTypeId() === ItemTypeIds::SADDLE
				){
					$saddled = true;
				}
			}
		}
		$this->silentInventoryChange = true;
		try{
			$this->setSaddled($saddled);
		}finally{
			$this->silentInventoryChange = false;
		}
		$this->setStepHeight(self::STEP_HEIGHT);
		if($nbt->getByte(self::TAG_SITTING, 0) !== 0){
			$this->setSitting(true);
		}
	}

	protected function saveCamel(CompoundTag $nbt) : void{
		$nbt->setByte(self::TAG_SADDLED, $this->isSaddled() ? 1 : 0);
		$items = [];
		foreach($this->inventory->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}
		$nbt->setTag(self::TAG_CHEST_ITEMS, new ListTag($items, NBT::TAG_Compound));
		$nbt->setByte(self::TAG_SITTING, $this->sitting ? 1 : 0);
	}

	/**
	 * The goal sitting the camel down from time to time while nobody rides it (vanilla random_look_around_and_sit,
	 * {@link CamelTrait::SIT_CHANCE_PER_TICK}), for 2 to 5 looks around of 80 to 100 ticks. Goals are evaluated every
	 * {@link Mob::GOAL_EVALUATION_INTERVAL} ticks, so the chance per evaluation is that much larger. A camel that was
	 * sitting when it was saved keeps sitting (continue_sitting_on_reload).
	 */
	protected function createSitGoal() : Goal{
		return new class($this) extends Goal{
			private int $ticks = 0;

			public function __construct(private Camel|CamelHusk $camel){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_JUMP; }

			public function canStart() : bool{
				if(count($this->camel->getPassengers()) > 0){
					return false;
				}
				if($this->camel->isSitting()){
					return true; //sitting since it was loaded
				}
				return $this->camel->isOnGround() && !$this->camel->isInWater() &&
					Utils::getRandomFloat() < $this->camel::SIT_CHANCE_PER_TICK * Mob::GOAL_EVALUATION_INTERVAL;
			}

			public function canContinue() : bool{
				return $this->ticks > 0 && $this->camel->isSitting() && count($this->camel->getPassengers()) === 0;
			}

			public function start() : void{
				$this->ticks = 0;
				for($looks = mt_rand(2, 5); $looks > 0; --$looks){
					$this->ticks += mt_rand(80, 100);
				}
				$this->camel->getNavigation()->stop();
				$this->camel->setSitting(true);
			}

			public function tick() : void{
				--$this->ticks;
			}

			public function stop() : void{
				$this->camel->setSitting(false);
			}
		};
	}

	public function isSitting() : bool{
		return $this->sitting;
	}

	public function setSitting(bool $sitting) : void{
		if($sitting !== $this->sitting){
			$this->sitting = $sitting;
			if($this instanceof Animal && $this->isBaby()){
				$this->setScale($this->getScale()); //baby boxes: see Camel::getBabySizeInfo()
			}else{
				$this->setSize($sitting ? new EntitySizeInfo(0.945 * $this->getScale(), 1.7 * $this->getScale()) : $this->getInitialSizeInfo()->scale($this->getScale()));
			}
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * A sitting camel can't be pushed by other entities (pushable_by_entity is only in the standing component groups).
	 */
	public function canBePushedBy(Entity $entity) : bool{
		return !$this->sitting && parent::canBePushedBy($entity);
	}

	public function isSaddled() : bool{
		return $this->inventory->getItem(HorseInventory::SLOT_EQUIP)->getTypeId() === ItemTypeIds::SADDLE;
	}

	public function setSaddled(bool $saddled) : void{
		$this->inventory->setItem(HorseInventory::SLOT_EQUIP, $saddled ? VanillaItems::SADDLE() : VanillaItems::AIR());
	}

	/**
	 * Whether the saddle slot is usable now (camel: adults; camel husk: not while a hostile mob rides it).
	 */
	abstract protected function hasSaddleSlot() : bool;

	public function getInventory() : HorseInventory{
		return $this->inventory;
	}

	public function canHoldInSlot(int $slot, Item $item) : bool{
		return $item->isNull() || ($slot === HorseInventory::SLOT_EQUIP && $item->getCount() === 1 && $item->getTypeId() === ItemTypeIds::SADDLE);
	}

	public function canWearSaddle() : bool{
		return true;
	}

	public function hasArmorSlot() : bool{
		return false;
	}

	public function getArmorSlotIcon() : string{
		return "";
	}

	public function getArmorSlotItemNames() : array{
		return [];
	}

	public function canOpenInventory(Player $player) : bool{
		return $this->isAlive() && $this->hasSaddleSlot() && $player->getWorld() === $this->getWorld() &&
			$player->getPosition()->distanceSquared($this->location) <= self::MAX_VIEWER_DISTANCE ** 2;
	}

	public function openInventory(Player $player) : bool{
		return $this->canOpenInventory($player) && $player->setCurrentWindow($this->inventory);
	}

	/**
	 * Sneaking and using it from the ground opens the saddle screen (rideable crouching_skip_interact; nobody riding
	 * it). Returns whether it opened.
	 */
	protected function openInventoryBySneaking(Player $player) : bool{
		return $player->isSneaking() && $player->getVehicle() !== $this && count($this->passengers) === 0 && $this->openInventory($player);
	}

	/**
	 * Shears take the saddle off when nobody rides (vanilla removesaddle interaction): it drops above the camel, a bit
	 * lower when sitting; the shears lose 1 durability and sculk sensors hear it. Returns whether it came off.
	 */
	protected function shearSaddle(Player $player) : bool{
		if(!$this->isSaddled() || count($this->passengers) > 0){
			return false;
		}
		$this->setSaddled(false);
		$this->getWorld()->dropItem($this->location->add(0, $this->sitting ? 1 : 2, 0), VanillaItems::SADDLE());
		$this->playMobSound(LevelSoundEvent::UNSADDLE);
		$this->damageHeldItem($player);
		$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
		return true;
	}

	/**
	 * @return RideableSeat[]
	 * @phpstan-return list<RideableSeat>
	 */
	protected function getCamelSeats() : array{
		return [RideableSeat::at(0, 1.905, 0.5), RideableSeat::at(0, 1.905, -0.5)];
	}

	public function canBeSteeredBy(Player $rider) : bool{
		return $this->isSaddled();
	}

	protected function isDrivenByRiderClient() : bool{
		return true;
	}

	protected function getRiddenSpeed(Player $rider) : float{
		$sprinting = $this->riderInput !== null && $this->riderInput->sprinting && $this->dashCooldown <= 0;
		return ($this->getMovementSpeed() + ($sprinting ? 0.1 : 0.0)) * 2.1;
	}

	/**
	 * Holding jump charges a dash, releasing it dashes (Java formula for the power).
	 */
	protected function handleDashInput(Player $rider, RiderInput $input) : void{
		if($this->getControllingPassenger() !== $rider || !$this->canBeSteeredBy($rider)){
			return;
		}
		if($input->jumping){
			if($this->dashChargeTicks >= 0){
				++$this->dashChargeTicks;
			}elseif($this->dashCooldown <= 0){
				$this->dashChargeTicks = 0;
			}
		}elseif($this->dashChargeTicks >= 0){
			$ticks = $this->dashChargeTicks;
			$this->dashChargeTicks = -1;
			$scale = $ticks < 10 ? $ticks * 0.1 : 0.8 + 2.0 / ($ticks - 9) * 0.1;
			$this->dash($scale >= 0.9 ? 1.0 : 0.4 + 0.4 * $scale / 0.9);
		}
	}

	/**
	 * Dashes forwards with the given power (0.4 to 1) and starts the cooldown. A client predicting the camel moves it
	 * itself; the server only does it when it moves the camel.
	 */
	public function dash(float $power) : void{
		if($this->dashCooldown > 0){
			return;
		}
		$this->dashCooldown = self::DASH_COOLDOWN_TICKS;
		$this->networkPropertiesDirty = true;
		if(!$this->isMovedByClient() && $this->onGround){
			$yaw = deg2rad($this->location->yaw);
			$speed = 20 * $this->getMovementSpeed() * $power;
			$this->motion = $this->motion->add(-sin($yaw) * $speed, 0, cos($yaw) * $speed)->withComponents(null, 0.6 * $power, null);
		}
	}

	public function hasDashCooldown() : bool{
		return $this->dashCooldown > 0;
	}

	/**
	 * damage_sensor: falls deal 4 damage less.
	 */
	protected function calculateFallDamage(float $fallDistance) : float{
		return max(0, parent::calculateFallDamage($fallDistance) - 4);
	}

	/**
	 * Called from entityBaseTick(): the dash cooldown, the viewers of the saddle screen that went too far, and riders
	 * thrown off after 2 seconds under water (behavior.float time_under_water_to_dismount_passengers).
	 */
	protected function tickCamel(int $tickDiff) : void{
		if($this->dashCooldown > 0){
			$this->dashCooldown -= $tickDiff;
			if($this->dashCooldown <= 0){
				$this->networkPropertiesDirty = true;
				$this->playMobSound(LevelSoundEvent::DASH_READY);
			}
		}
		foreach($this->inventory->getViewers() as $viewer){
			if($viewer->getCurrentWindow() === $this->inventory && !$this->canOpenInventory($viewer)){
				$viewer->removeCurrentWindow();
			}
		}
		if(count($this->passengers) > 0 && $this->isUnderwater()){
			$this->underwaterRideTicks += $tickDiff;
			if($this->underwaterRideTicks >= self::UNDERWATER_DISMOUNT_TICKS){
				$this->underwaterRideTicks = 0;
				foreach($this->passengers as $passenger){
					$passenger->dismount(true);
				}
			}
		}else{
			$this->underwaterRideTicks = 0;
		}
	}

	protected function onDeath() : void{
		$this->inventory->removeAllViewers();
		parent::onDeath();
	}

	protected function onDispose() : void{
		if(isset($this->inventory)){
			$this->inventory->removeAllViewers();
		}
		parent::onDispose();
	}

	protected function syncCamelData(EntityMetadataCollection $properties) : void{
		$saddled = $this->isSaddled();
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $saddled);
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->sitting);
		$properties->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, $saddled);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_DASH, $saddled);
		//vanilla inventory component (container_type horse): the saddle screen
		$properties->setByte(EntityMetadataProperties::CONTAINER_TYPE, $this->hasSaddleSlot() ? WindowTypes::HORSE : 0);
		$properties->setInt(EntityMetadataProperties::CONTAINER_BASE_SIZE, $this->inventory->getSize());
		$properties->setGenericFlag(EntityMetadataFlags::HAS_DASH_COOLDOWN, $this->dashCooldown > 0);
	}
}
