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

namespace pocketmine\entity\aquatic;

use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function cos;
use function count;
use function deg2rad;
use function mt_rand;
use function sin;

/**
 * Taming equipment and riding shared by the nautilus and the zombie nautilus (nautilus.json, zombie_nautilus.json):
 *
 * - Inventory: a {@link HorseInventory} ("horse" container) with the saddle in slot 0 and nautilus armor in slot 1,
 *   opened by sneaking and using a tamed adult or with the inventory button of its rider (vanilla). Saddles and armor
 *   are also put on by using them on a tamed adult; shears take the armor off first, then the saddle (one use each,
 *   only while nobody rides it). Nautilus armor protects like horse armor (copper 4, iron 5, gold 7, diamond 11,
 *   netherite 19) and is shown to viewers in the body slot of MobArmorEquipmentPacket. Saved as "ChestItems".
 * - Riding: one player seat 0.925 up (third person camera 7 blocks away). Once saddled, the rider steers it: in water
 *   with free camera controls (WASD_FREE_CAMERA_CONTROLLED: forwards follows the camera pitch, strafing at 0.7x,
 *   backwards at 0.5x, up to 6.5 blocks per second), out of water like a ground mount (WASD_CONTROLLED, 0.88 blocks
 *   per second). Holding then releasing jump dashes (CAN_DASH, HAS_DASH_COOLDOWN, 2 s cooldown) in the rider's look
 *   direction: vanilla dash_action momentum 154 in water and 42 on land times the player-controlled movement (0.015),
 *   0.1 up. A rider in water breathes (vanilla underwater_mount_breathing) and gets Breath of the Nautilus.
 * - Home (minecraft:home, random_movement): a tamed one wanders within 32 blocks of its home, 16 when saddled. The home
 *   is set where it is tamed, saddled or unsaddled, left by its rider or unleashed (and, for the nautilus, where a
 *   player stops tempting it).
 * - Pufferfish hunt of wild ones, see {@link NautilusTrait::huntPufferfish()}.
 *
 * The using class implements {@link \pocketmine\entity\riding\MountInventoryHolder}, provides isTamed(),
 * {@link NautilusTrait::isInWaterForRiding()}, {@link NautilusTrait::swimTowards()},
 * {@link NautilusTrait::canHuntPufferfish()} and {@link NautilusTrait::onPreyFound()}, and calls
 * {@link NautilusTrait::initNautilusInventory()}, {@link NautilusTrait::saveNautilusInventory()},
 * {@link NautilusTrait::tickNautilus()} and {@link NautilusTrait::huntPufferfish()} (entityBaseTick),
 * {@link NautilusTrait::handleDashInput()}
 * (handleRiderInput), {@link NautilusTrait::interactWithEquipment()} and {@link NautilusTrait::syncNautilusData()}.
 */
trait NautilusTrait{
	public const TAG_CHEST_ITEMS = "ChestItems"; //TAG_List<TAG_Compound>

	/** 6.5 blocks per second (minecraft.wiki). */
	public const RIDDEN_SWIM_SPEED = 0.325;
	/** 0.88 blocks per second (minecraft.wiki). */
	public const RIDDEN_GROUND_SPEED = 0.044;
	public const DASH_COOLDOWN_TICKS = 40;
	/** minecraft:home restriction_radius of tamed ones without and with a saddle. */
	public const HOME_RADIUS_UNSADDLED = 32;
	public const HOME_RADIUS_SADDLED = 16;
	/** Vanilla player-controlled movement, which dash_action momentum is multiplied with. */
	private const PLAYER_CONTROLLED_MOVEMENT = 0.015;
	private const MAX_VIEWER_DISTANCE = 8.0;
	/** nearest_attackable_target attack_interval of the pufferfish hunt: 120 to 180 seconds. */
	public const PREY_SEARCH_MIN_TICKS = 2400;
	public const PREY_SEARCH_MAX_TICKS = 3600;
	/** nearest_attackable_target within_radius of the pufferfish hunt. */
	public const PREY_RANGE = 25.0;

	private const NAUTILUS_ARMOR_POINTS = [
		ItemTypeIds::COPPER_NAUTILUS_ARMOR => 4,
		ItemTypeIds::IRON_NAUTILUS_ARMOR => 5,
		ItemTypeIds::GOLDEN_NAUTILUS_ARMOR => 7,
		ItemTypeIds::DIAMOND_NAUTILUS_ARMOR => 11,
		ItemTypeIds::NETHERITE_NAUTILUS_ARMOR => 19
	];

	protected HorseInventory $inventory;
	private bool $silentInventoryChange = false;
	/** Ticks the rider has held the jump button, -1 when not charging a dash. */
	private int $dashChargeTicks = -1;
	private int $dashCooldown = 0;
	private bool $lastInWaterForRiding = false;
	/** Server tick of the next pufferfish search, -1 until the first one is scheduled. */
	private int $nextPreySearchTick = -1;

	/**
	 * Whether the mob swims for riding purposes (vanilla in_water filter of the saddled states).
	 */
	abstract public function isInWaterForRiding() : bool;

	/**
	 * Swims towards the position at the given speed in blocks per tick (ridden in water).
	 */
	abstract protected function swimTowards(float $x, float $y, float $z, float $speed) : void;

	/**
	 * Stops swimming towards a position (ridden in water without input).
	 */
	abstract protected function stopSwimming() : void;

	abstract public function isTamed() : bool;

	public static function isNautilusArmor(Item $item) : bool{
		return isset(self::NAUTILUS_ARMOR_POINTS[$item->getTypeId()]);
	}

	protected function initNautilusInventory(CompoundTag $nbt) : void{
		$this->inventory = new HorseInventory($this, 2);
		$this->inventory->getListeners()->add(new CallbackInventoryListener(
			function(Inventory $unused, int $slot, Item $before) : void{
				$this->onEquipmentChange($slot, $before);
			},
			function(Inventory $unused, array $before) : void{
				$this->networkPropertiesDirty = true;
				$this->broadcastBodyArmor();
			}
		));
		$this->silentInventoryChange = true;
		try{
			$contents = [];
			$itemsTag = $nbt->getTag(self::TAG_CHEST_ITEMS);
			if($itemsTag instanceof ListTag && $itemsTag->getTagType() === NBT::TAG_Compound){
				/** @var CompoundTag $itemTag */
				foreach($itemsTag as $itemTag){
					$slot = $itemTag->getByte(SavedItemStackData::TAG_SLOT, -1);
					$item = Item::safeNbtDeserialize($itemTag, "Nautilus ChestItems slot $slot");
					if($this->inventory->slotExists($slot) && !$item->isNull() && $this->canHoldInSlot($slot, $item)){
						$contents[$slot] = $item;
					}
				}
			}
			$this->inventory->setContents($contents);
		}finally{
			$this->silentInventoryChange = false;
		}
	}

	protected function saveNautilusInventory(CompoundTag $nbt) : void{
		$items = [];
		foreach($this->inventory->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}
		$nbt->setTag(self::TAG_CHEST_ITEMS, new ListTag($items, NBT::TAG_Compound));
	}

	public function getInventory() : HorseInventory{
		return $this->inventory;
	}

	public function canHoldInSlot(int $slot, Item $item) : bool{
		if($item->isNull()){
			return true;
		}
		return $item->getCount() === 1 && match($slot){
			HorseInventory::SLOT_EQUIP => $item->getTypeId() === ItemTypeIds::SADDLE,
			HorseInventory::SLOT_ARMOR => self::isNautilusArmor($item),
			default => false
		};
	}

	public function canWearSaddle() : bool{
		return true;
	}

	public function hasArmorSlot() : bool{
		return true;
	}

	public function getArmorSlotIcon() : string{
		return "minecraft:iron_nautilus_armor";
	}

	public function getArmorSlotItemNames() : array{
		return [
			"minecraft:copper_nautilus_armor",
			"minecraft:iron_nautilus_armor",
			"minecraft:golden_nautilus_armor",
			"minecraft:diamond_nautilus_armor",
			"minecraft:netherite_nautilus_armor"
		];
	}

	public function isSaddled() : bool{
		return $this->inventory->getItem(HorseInventory::SLOT_EQUIP)->getTypeId() === ItemTypeIds::SADDLE;
	}

	public function setSaddled(bool $saddled) : void{
		$this->inventory->setItem(HorseInventory::SLOT_EQUIP, $saddled ? VanillaItems::SADDLE() : VanillaItems::AIR());
	}

	/**
	 * Returns the nautilus armor worn (air if none).
	 */
	public function getNautilusArmor() : Item{
		return $this->inventory->getItem(HorseInventory::SLOT_ARMOR);
	}

	public function setNautilusArmor(Item $armor) : void{
		$this->inventory->setItem(HorseInventory::SLOT_ARMOR, $armor);
	}

	public function getArmorPoints() : int{
		return parent::getArmorPoints() + (self::NAUTILUS_ARMOR_POINTS[$this->getNautilusArmor()->getTypeId()] ?? 0);
	}

	/**
	 * Returns the equipment dropped on death (saddle and armor).
	 *
	 * @return Item[]
	 */
	protected function getEquipmentDrops() : array{
		$drops = [];
		foreach($this->inventory->getContents() as $item){
			$drops[] = clone $item;
		}
		return $drops;
	}

	private function onEquipmentChange(int $slot, Item $before) : void{
		$this->networkPropertiesDirty = true;
		$this->broadcastBodyArmor();
		$item = $this->inventory->getItem($slot);
		if(!$this->silentInventoryChange && $slot === HorseInventory::SLOT_EQUIP && ($before->getTypeId() === ItemTypeIds::SADDLE) !== ($item->getTypeId() === ItemTypeIds::SADDLE)){
			//on_saddled / on_unsaddled: set_home_position
			$this->setHomeHere();
		}
		if(!$this->silentInventoryChange && !$item->isNull() && !$item->equals($before)){
			$this->playMobSound($slot === HorseInventory::SLOT_EQUIP ?
				($this->isInWaterForRiding() ? LevelSoundEvent::SADDLE_IN_WATER : LevelSoundEvent::SADDLE) :
				LevelSoundEvent::ARMOR_EQUIP_GENERIC
			);
		}
	}

	private function broadcastBodyArmor() : void{
		foreach($this->hasSpawned as $player){
			$this->sendBodyArmor($player);
		}
	}

	private function sendBodyArmor(Player $player) : void{
		$session = $player->getNetworkSession();
		$converter = $session->getTypeConverter();
		$armor = $this->armorInventory;
		$body = $this->getNautilusArmor();
		$session->sendDataPacket(MobArmorEquipmentPacket::create(
			$this->getId(),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($armor->getHelmet())),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($armor->getChestplate())),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($armor->getLeggings())),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($armor->getBoots())),
			$body->isNull() ? new ItemStackWrapper(0, ItemStack::null()) : ItemStackWrapper::legacy($converter->coreItemStackToNet($body))
		));
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if(!$this->getNautilusArmor()->isNull()){
			$this->sendBodyArmor($player);
		}
	}

	public function canOpenInventory(Player $player) : bool{
		return $this->isAlive() && !$this->isBaby() && $this->isTamed() &&
			$player->getWorld() === $this->getWorld() &&
			$player->getPosition()->distanceSquared($this->location) <= self::MAX_VIEWER_DISTANCE ** 2;
	}

	public function openInventory(Player $player) : bool{
		return $this->canOpenInventory($player) && $player->setCurrentWindow($this->inventory);
	}

	/**
	 * The equipment interactions of a tamed adult (vanilla nautilus_tame_adult interact): saddle, armor, shears, and
	 * sneaking opens the inventory. Returns whether the item was used.
	 */
	protected function interactWithEquipment(Player $player, Item $item) : bool{
		if(!$this->isTamed() || $this->isBaby()){
			return false;
		}
		if($player->isSneaking()){
			return $this->openInventory($player);
		}
		$single = (clone $item)->setCount(1);
		if($item->getTypeId() === ItemTypeIds::SADDLE && !$this->isSaddled()){
			$this->setSaddled(true);
			$this->consumeHeldItem($player);
			return true;
		}
		if(self::isNautilusArmor($item) && $this->getNautilusArmor()->isNull()){
			$this->setNautilusArmor($single);
			$this->consumeHeldItem($player);
			return true;
		}
		if($item->getTypeId() === ItemTypeIds::SHEARS && count($this->passengers) === 0){
			$armor = $this->getNautilusArmor();
			if(!$armor->isNull()){
				$this->setNautilusArmor(VanillaItems::AIR());
				$this->getWorld()->dropItem($this->location->add(0, 1.1, 0), $armor);
				$this->playMobSound(LevelSoundEvent::ARMOR_UNEQUIP_GENERIC);
				$this->damageHeldItem($player);
				return true;
			}
			if($this->isSaddled()){
				$this->setSaddled(false);
				$this->getWorld()->dropItem($this->location->add(0, 1, 0), VanillaItems::SADDLE());
				$this->playMobSound(LevelSoundEvent::UNSADDLE);
				$this->damageHeldItem($player);
				return true;
			}
		}
		return false;
	}

	/**
	 * The interaction button for the held item on a tamed adult, or null.
	 */
	protected function getEquipmentButtonText(Player $player, Item $item) : ?string{
		if(!$this->isTamed() || $this->isBaby() || $player->isSneaking()){
			return null;
		}
		if($item->getTypeId() === ItemTypeIds::SADDLE && !$this->isSaddled()){
			return "action.interact.saddle";
		}
		if(self::isNautilusArmor($item) && $this->getNautilusArmor()->isNull()){
			return "action.interact.equipnautilusarmor";
		}
		if($item->getTypeId() === ItemTypeIds::SHEARS && count($this->passengers) === 0){
			if(!$this->getNautilusArmor()->isNull()){
				return "action.interact.removenautilusarmor";
			}
			if($this->isSaddled()){
				return "action.interact.removesaddle";
			}
		}
		return null;
	}

	protected function getPlayerSeat() : RideableSeat{
		return new RideableSeat(new Vector3(0, 0.925, 0), null, 7.0);
	}

	public function canBeSteeredBy(Player $rider) : bool{
		return $this->isTamed() && $this->isSaddled();
	}

	protected function isDrivenByRiderClient() : bool{
		return true;
	}

	/**
	 * Home restriction (random_movement): 32 blocks for a tamed one without a saddle, 16 with one (tame_unsaddled,
	 * tame_saddled); none for wild ones. Following its owner or a tempting player isn't restricted.
	 */
	public function getHomeRadius() : int{
		if(!$this->isTamed() || !isset($this->inventory)){
			return 0;
		}
		return $this->isSaddled() ? self::HOME_RADIUS_SADDLED : self::HOME_RADIUS_UNSADDLED;
	}

	protected function onUnleashed() : void{
		parent::onUnleashed();
		$this->setHomeHere(); //on_unleashed: set_home_position
	}

	protected function getLeashElasticDistance() : float{
		return 10.0; //nautilus_leashable / zombie_nautilus_leashable preset hard_distance
	}

	public function getLeashBreakDistance() : float{
		return 14.0; //preset max_distance
	}

	protected function onPassengerRemoved(Entity $passenger) : void{
		parent::onPassengerRemoved($passenger);
		if($passenger instanceof Player){
			$this->setHomeHere(); //on_dismount (on_player_dismount): set_home_position
		}
	}

	public function givesUnderwaterBreathingTo(Entity $passenger) : bool{
		return $passenger instanceof Player && $this->isSaddled() && $this->isInWaterForRiding();
	}

	/**
	 * In water the nautilus goes where the rider looks (free camera controls); on land it is driven like a ground
	 * mount, slowly.
	 */
	protected function steer(Player $rider, RiderInput $input) : void{
		$this->location->yaw = $input->yaw;
		$this->headYaw = $input->yaw;
		if(!$this->isInWaterForRiding()){
			$this->location->pitch = 0.0;
			$this->stopSwimming();
			[$forward, $left] = $this->getRiddenInput($input);
			$this->moveControl->drive($forward, $left, self::RIDDEN_GROUND_SPEED);
			return;
		}
		$forward = $input->forward < 0 ? $input->forward * 0.5 : $input->forward;
		$strafe = $input->strafe * 0.7;
		$yaw = deg2rad($input->yaw);
		$pitch = deg2rad($input->pitch);
		//forwards follows the camera (pitch included), strafing stays horizontal
		$dx = -sin($yaw) * cos($pitch) * $forward + cos($yaw) * $strafe;
		$dy = -sin($pitch) * $forward;
		$dz = cos($yaw) * cos($pitch) * $forward + sin($yaw) * $strafe;
		if($dx * $dx + $dy * $dy + $dz * $dz < 0.0001){
			$this->stopSwimming();
			return;
		}
		$loc = $this->location;
		$this->swimTowards($loc->x + $dx * 4, $loc->y + $dy * 4, $loc->z + $dz * 4, self::RIDDEN_SWIM_SPEED);
	}

	/**
	 * Holding jump charges a dash, releasing it dashes (same power as a camel's dash).
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
			$this->dash($scale >= 0.9 ? 1.0 : 0.4 + 0.4 * $scale / 0.9, $input->yaw, $input->pitch);
		}
	}

	/**
	 * Dashes with the given power (0.4 to 1) in the given direction (the rider's look; horizontal on land) and starts
	 * the cooldown. A client predicting the nautilus moves it itself; the server only does it when it moves it.
	 */
	public function dash(float $power, float $yaw, float $pitch) : void{
		if($this->dashCooldown > 0){
			return;
		}
		$this->dashCooldown = self::DASH_COOLDOWN_TICKS;
		$this->networkPropertiesDirty = true;
		if($this->isMovedByClient()){
			return;
		}
		$inWater = $this->isInWaterForRiding();
		if(!$inWater && !$this->onGround){
			return;
		}
		$speed = ($inWater ? 154 : 42) * self::PLAYER_CONTROLLED_MOVEMENT * $power;
		$yawRad = deg2rad($yaw);
		$pitchRad = $inWater ? deg2rad($pitch) : 0.0;
		$this->motion = new Vector3(
			-sin($yawRad) * cos($pitchRad) * $speed,
			-sin($pitchRad) * $speed + 0.1 * $power,
			cos($yawRad) * cos($pitchRad) * $speed
		);
	}

	public function hasDashCooldown() : bool{
		return $this->dashCooldown > 0;
	}

	protected function tickNautilus(int $tickDiff) : void{
		if($this->dashCooldown > 0){
			$this->dashCooldown -= $tickDiff;
			if($this->dashCooldown <= 0){
				$this->networkPropertiesDirty = true;
				$this->playMobSound(LevelSoundEvent::DASH_READY);
			}
		}
		$inWater = $this->isInWaterForRiding();
		if($inWater !== $this->lastInWaterForRiding){
			//the saddled state switches between free camera and ground controls
			$this->lastInWaterForRiding = $inWater;
			$this->networkPropertiesDirty = true;
		}
		if($inWater && ($this->server->getTick() + $this->id) % 20 === 0 && $this->isSaddled() && $this->isTamed()){
			$this->giveRiderBreathOfTheNautilus();
		}
		foreach($this->inventory->getViewers() as $viewer){
			if($viewer->getCurrentWindow() === $this->inventory && !$this->canOpenInventory($viewer)){
				$viewer->removeCurrentWindow();
			}
		}
	}

	/**
	 * The saddled_in_water mob_effect: the player riding it gets Breath of the Nautilus for 2 seconds (ambient),
	 * refreshed every second so that it doesn't run out in between.
	 */
	private function giveRiderBreathOfTheNautilus() : void{
		foreach($this->getPassengers() as $passenger){
			if($passenger instanceof Player && $passenger->isAlive()){
				$passenger->getEffects()->add(new EffectInstance(VanillaEffects::BREATH_OF_THE_NAUTILUS(), 40, 0, true, true));
			}
		}
	}

	/**
	 * Whether the mob may look for a pufferfish to hunt now (wild, calm, in water...).
	 */
	abstract protected function canHuntPufferfish() : bool;

	/**
	 * Called with the pufferfish the mob found to hunt: it gets angry at it.
	 */
	abstract protected function onPreyFound(Living $prey) : void;

	/**
	 * The pufferfish hunt of wild nautiluses (nautilus_wild_adult_calm, zombie_nautilus_wild_unmounted
	 * nearest_attackable_target): every 120 to 180 seconds (attack_interval) a calm one has a 50% chance
	 * (target_acquisition_probability) to target the nearest pufferfish in water it sees within 25 blocks, which makes
	 * it angry (on_target_acquired). Called every tick: it only compares the tick until a search is due, and the search
	 * is skipped while the world has no pufferfish.
	 */
	protected function huntPufferfish() : void{
		$now = $this->server->getTick();
		if($now < $this->nextPreySearchTick){
			return;
		}
		if($this->nextPreySearchTick === -1){
			$this->nextPreySearchTick = $now + mt_rand(self::PREY_SEARCH_MIN_TICKS, self::PREY_SEARCH_MAX_TICKS);
			return;
		}
		if(!$this->canHuntPufferfish()){
			$this->nextPreySearchTick = $now + Mob::GOAL_EVALUATION_INTERVAL;
			return;
		}
		$this->nextPreySearchTick = $now + mt_rand(self::PREY_SEARCH_MIN_TICKS, self::PREY_SEARCH_MAX_TICKS);
		if(mt_rand(0, 1) === 0){
			return;
		}
		$prey = $this->findPufferfishPrey();
		if($prey !== null){
			$this->onPreyFound($prey);
		}
	}

	/**
	 * Returns the nearest pufferfish in water this mob sees within {@link NautilusTrait::PREY_RANGE} blocks.
	 */
	protected function findPufferfishPrey() : ?Pufferfish{
		$world = $this->getWorld();
		if(!$world->hasEntitiesOfClass(Pufferfish::class)){
			return null;
		}
		$loc = $this->location;
		$r = self::PREY_RANGE;
		$best = null;
		$bestDistance = $r * $r;
		foreach($world->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			if(!$entity instanceof Pufferfish || !$entity->isAlive()){
				continue;
			}
			$d = $entity->getPosition()->distanceSquared($loc);
			if($d < $bestDistance && $entity->isInWater() && $this->getSensing()->canSee($entity)){
				$best = $entity;
				$bestDistance = $d;
			}
		}
		return $best;
	}

	/**
	 * Makes the next {@link NautilusTrait::huntPufferfish()} call look for a pufferfish (if the mob may hunt).
	 * @internal for tests and debugging
	 */
	public function scheduleNextPufferfishSearch(int $ticks) : void{
		$this->nextPreySearchTick = $this->server->getTick() + $ticks;
	}

	protected function closeNautilusInventory() : void{
		if(isset($this->inventory)){
			$this->inventory->removeAllViewers();
		}
	}

	protected function syncNautilusData(EntityMetadataCollection $properties) : void{
		$saddled = $this->isSaddled();
		$inWater = $this->isInWaterForRiding();
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $saddled);
		$properties->setGenericFlag(EntityMetadataFlags::WASD_FREE_CAMERA_CONTROLLED, $saddled && $inWater);
		$properties->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, $saddled && !$inWater);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_DASH, $saddled);
		$properties->setGenericFlag(EntityMetadataFlags::HAS_DASH_COOLDOWN, $this->dashCooldown > 0);
	}
}
