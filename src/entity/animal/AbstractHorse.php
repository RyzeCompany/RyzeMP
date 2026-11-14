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
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MountPathingGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Attribute;
use pocketmine\entity\AttributeFactory;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\vibration\GameEvent;
use function abs;
use function ceil;
use function cos;
use function count;
use function deg2rad;
use function in_array;
use function max;
use function min;
use function mt_rand;
use function round;
use function sin;

/**
 * Horses, donkeys, mules, llamas, skeleton and zombie horses.
 *
 * Health, speed and jump strength are random per animal (Bedrock "range_min"/"range_max"); babies get them blended from
 * their parents' (parent_centric_attribute_blending, Java createOffspringAttribute: health for all, movement and jump
 * for horses). Food heals and makes babies grow by vanilla amounts.
 *
 * Riding: using an adult with an empty hand (or any non-food item once tamed) mounts it (seat 1.1 up and 0.2 back for
 * horses). An untamed one is broken in by riding it (vanilla tamemount, Java RunAroundLikeCrazyGoal): it runs around,
 * and every tick has a 1 in 50 chance to either get tamed (a random number below its max temper, 100 or 30 for
 * llamas, under its temper) or throw the rider off and raise its temper by 5; food raises the temper too. A saddle can be put on a tamed animal (SADDLED flag,
 * dropped on death); saddled, it is steered by its rider (WASD_CONTROLLED, CAN_POWER_JUMP: the client shows the jump
 * bar). Clients predict the movement of these vehicles in server-authoritative movement and send it with
 * PlayerAuthInputPacket; without that the server moves it (speed: movement attribute x 2.1 blocks per tick, jumps
 * charged by holding jump for up to 10 ticks, Java formula). Fall damage is halved minus 3 (Java). A wild one may carry
 * a baby zombie jockey and walks to its target (vanilla behavior.mount_pathing). Its riders are thrown off after 2
 * seconds with it under water (behavior.float time_under_water_to_dismount_passengers; not llamas nor skeleton horses).
 *
 * Inventory ({@link HorseInventory}, vanilla "horse" container): the saddle (or a llama's carpet), the horse armor of
 * the animals that wear it and the chest of donkeys, mules and llamas. A tamed adult's inventory opens when a player
 * sneaks and uses it, or when its rider presses the inventory button (InteractPacket OPEN_INVENTORY); viewers farther
 * than 8 blocks get it closed (Java). Saddles, horse armor, carpets and chests are also put on by using them on a tamed
 * adult; shears take the horse armor off first, then the saddle or carpet (nobody riding it; dropped 1.1 blocks up). A
 * chest can't be taken off (only dropped on death, with everything in the inventory). The contents are saved
 * like Bedrock's container component ("ChestItems", "Chested"). Horse armor protects like armor (vanilla protection
 * points) and, like a llama's carpet, is shown to viewers in the body slot of MobArmorEquipmentPacket.
 */
abstract class AbstractHorse extends TameableAnimal implements MountInventoryHolder{
	public const TAG_TEMPER = "Temper"; //TAG_Int
	public const TAG_SADDLED = "Saddled"; //TAG_Byte
	public const TAG_JUMP_STRENGTH = "JumpStrength"; //TAG_Float
	public const TAG_MAX_HEALTH = "HorseMaxHealth"; //TAG_Int
	public const TAG_SPEED = "HorseSpeed"; //TAG_Float
	public const TAG_CHESTED = "Chested"; //TAG_Byte
	public const TAG_CHEST_ITEMS = "ChestItems"; //TAG_List<TAG_Compound>

	public const MAX_TEMPER = 100;

	/** Chest slots of a chested donkey or mule (vanilla inventory_size 16, minus the saddle slot). */
	public const CHEST_SIZE = 15;
	/** Players farther than this from the animal get its inventory closed (Java). */
	private const MAX_VIEWER_DISTANCE = 8.0;
	/** behavior.float time_under_water_to_dismount_passengers: 2 seconds. */
	public const UNDERWATER_DISMOUNT_TICKS = 40;

	/** Java ranges of the stats, used to blend the parents' stats into a baby's (see {@link AbstractHorse::blendOffspringAttribute()}). */
	private const MIN_HEALTH = 15.0;
	private const MAX_HEALTH = 30.0;
	private const MIN_SPEED = 0.1125;
	private const MAX_SPEED = 0.3375;
	private const MIN_JUMP = 0.4;
	private const MAX_JUMP = 1.0;

	/**
	 * Protection points of horse armor (vanilla items: leather 3, copper 4, iron 5, gold 7, diamond 11, netherite 19).
	 */
	private const HORSE_ARMOR_POINTS = [
		ItemTypeIds::LEATHER_HORSE_ARMOR => 3,
		ItemTypeIds::COPPER_HORSE_ARMOR => 4,
		ItemTypeIds::IRON_HORSE_ARMOR => 5,
		ItemTypeIds::GOLDEN_HORSE_ARMOR => 7,
		ItemTypeIds::DIAMOND_HORSE_ARMOR => 11,
		ItemTypeIds::NETHERITE_HORSE_ARMOR => 19,
	];

	/**
	 * Healing and temper per food: [heal, growth as a fraction of the 20 minutes, temper] (Bedrock healable and
	 * ageable components, Java temper values).
	 */
	private const FOOD = [
		ItemTypeIds::WHEAT => [2, 0.016667, 3],
		ItemTypeIds::SUGAR => [1, 0.025, 3],
		ItemTypeIds::APPLE => [3, 0.05, 3],
		ItemTypeIds::CARROT => [3, 0.05, 3],
		ItemTypeIds::GOLDEN_CARROT => [4, 0.05, 5],
		ItemTypeIds::GOLDEN_APPLE => [10, 0.2, 10],
		ItemTypeIds::ENCHANTED_GOLDEN_APPLE => [10, 0.2, 10],
	];

	protected int $temper = 0;
	protected float $jumpStrength = 0.5;
	protected HorseInventory $inventory;
	private bool $chested = false;
	/** Set while the inventory is filled without the equip sounds (loading, resizing). */
	private bool $silentInventoryChange = false;
	/** Ticks the rider has held the jump button, -1 when not charging a jump. */
	private int $jumpChargeTicks = -1;
	/** Ticks it has been under water with riders on it. */
	private int $underwaterRideTicks = 0;

	protected function addAttributes() : void{
		parent::addAttributes();
		$this->attributeMap->add(AttributeFactory::getInstance()->mustGet(Attribute::HORSE_JUMP_STRENGTH));
	}

	/**
	 * Random stats of a new animal: [max health, movement speed, jump strength].
	 *
	 * @return float[]
	 * @phpstan-return array{int, float, float}
	 */
	abstract protected function randomStats() : array;

	protected function initEntity(CompoundTag $nbt) : void{
		[$health, $speed, $jump] = $this->randomStats();
		$health = $nbt->getInt(self::TAG_MAX_HEALTH, $health);
		$speed = $nbt->getFloat(self::TAG_SPEED, $speed);
		$this->setMaxHealth($health);
		$this->setMovementSpeed($speed);
		parent::initEntity($nbt);
		$this->setJumpStrength($nbt->getFloat(self::TAG_JUMP_STRENGTH, $jump));
		$this->temper = $nbt->getInt(self::TAG_TEMPER, 0);
		$this->chested = $this->canHaveChest() && $nbt->getByte(self::TAG_CHESTED, 0) !== 0;

		$this->inventory = $this->createInventory();
		$this->silentInventoryChange = true;
		try{
			$contents = [];
			$itemsTag = $nbt->getTag(self::TAG_CHEST_ITEMS);
			if($itemsTag instanceof ListTag && $itemsTag->getTagType() === NBT::TAG_Compound){
				/** @var CompoundTag $itemTag */
				foreach($itemsTag as $itemTag){
					$slot = $itemTag->getByte(SavedItemStackData::TAG_SLOT, -1);
					$item = Item::safeNbtDeserialize($itemTag, "AbstractHorse ChestItems slot $slot");
					if($this->inventory->slotExists($slot) && !$item->isNull()){
						$contents[$slot] = $item;
					}
				}
			}elseif($nbt->getByte(self::TAG_SADDLED, 0) !== 0 && $this->canWearSaddle()){
				//saved before the inventory existed
				$contents[HorseInventory::SLOT_EQUIP] = VanillaItems::SADDLE();
			}
			$this->inventory->setContents($contents);
		}finally{
			$this->silentInventoryChange = false;
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_TEMPER, $this->temper);
		$nbt->setByte(self::TAG_SADDLED, $this->isSaddled() ? 1 : 0);
		$nbt->setFloat(self::TAG_JUMP_STRENGTH, $this->jumpStrength);
		$nbt->setInt(self::TAG_MAX_HEALTH, $this->getMaxHealth());
		$nbt->setFloat(self::TAG_SPEED, $this->getBaseMovementSpeed());
		$nbt->setByte(self::TAG_CHESTED, $this->chested ? 1 : 0);
		$items = [];
		foreach($this->inventory->getContents() as $slot => $item){
			$items[] = $item->nbtSerialize($slot);
		}
		$nbt->setTag(self::TAG_CHEST_ITEMS, new ListTag($items, NBT::TAG_Compound));
		return $nbt;
	}

	/**
	 * Creates the inventory with the size the animal needs now, listened to for the saddle, armor and carpet.
	 */
	private function createInventory() : HorseInventory{
		$inventory = new HorseInventory($this, $this->getInventorySize());
		$inventory->getListeners()->add(new CallbackInventoryListener(
			function(Inventory $unused, int $slot, Item $before) : void{
				$this->onInventorySlotChange($slot, $before);
			},
			function(Inventory $unused, array $before) : void{
				$this->networkPropertiesDirty = true;
				$this->broadcastBodyArmor();
			}
		));
		return $inventory;
	}

	public function getInventory() : HorseInventory{
		return $this->inventory;
	}

	/**
	 * Number of slots of the inventory: the saddle (or carpet) slot, the armor slot of the animals that wear armor and
	 * the chest slots of a chested one.
	 */
	public function getInventorySize() : int{
		return ($this->hasArmorSlot() ? 2 : 1) + ($this->chested ? $this->getChestSize() : 0);
	}

	/**
	 * Number of slots of the animal's chest (donkeys and mules: 15; llamas: 3 per strength).
	 */
	public function getChestSize() : int{
		return self::CHEST_SIZE;
	}

	/**
	 * Whether a chest can be put on the animal (donkeys, mules and llamas).
	 */
	public function canHaveChest() : bool{
		return false;
	}

	/**
	 * Whether the animal wears horse armor (horses and zombie horses).
	 */
	public function hasArmorSlot() : bool{
		return false;
	}

	public function isChested() : bool{
		return $this->chested;
	}

	/**
	 * Puts a chest on the animal or takes it off (the contents of the chest that no longer fit are dropped).
	 */
	public function setChested(bool $chested) : void{
		$chested = $chested && $this->canHaveChest();
		if($chested === $this->chested){
			return;
		}
		$this->chested = $chested;
		$this->resizeInventory();
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Replaces the inventory with one of the size the animal needs now (after a chest is put on, or a llama's strength
	 * changed), closing it for its viewers. Items that don't fit any more are dropped.
	 */
	protected function resizeInventory() : void{
		if(!isset($this->inventory)){
			return; //not initialized yet
		}
		$old = $this->inventory;
		$size = $this->getInventorySize();
		if($old->getSize() === $size){
			return;
		}
		$old->removeAllViewers();
		$contents = [];
		$overflow = [];
		foreach($old->getContents() as $slot => $item){
			if($slot < $size){
				$contents[$slot] = $item;
			}else{
				$overflow[] = $item;
			}
		}
		$this->inventory = $this->createInventory();
		$this->silentInventoryChange = true;
		try{
			$this->inventory->setContents($contents);
		}finally{
			$this->silentInventoryChange = false;
		}
		if(!$this->closed && count($overflow) > 0){
			$world = $this->getWorld();
			foreach($overflow as $item){
				$world->dropItem($this->location, $item);
			}
		}
	}

	/**
	 * Returns whether the item may be put in the given slot of the inventory: a saddle in the saddle slot (a carpet for
	 * llamas, see {@link AbstractHorse::acceptsInEquipSlot()}), horse armor in the armor slot, anything in the chest.
	 */
	public function canHoldInSlot(int $slot, Item $item) : bool{
		if($item->isNull()){
			return true;
		}
		if($slot === HorseInventory::SLOT_EQUIP){
			return $item->getCount() === 1 && $this->acceptsInEquipSlot($item);
		}
		if($slot === HorseInventory::SLOT_ARMOR && $this->hasArmorSlot()){
			return $item->getCount() === 1 && self::isHorseArmor($item);
		}
		return $this->chested && $slot > 0 && $slot < $this->inventory->getSize();
	}

	/**
	 * Whether the item goes in slot 0 of the inventory: a saddle, for the animals that wear one.
	 */
	protected function acceptsInEquipSlot(Item $item) : bool{
		return $this->canWearSaddle() && $item->getTypeId() === ItemTypeIds::SADDLE;
	}

	public static function isHorseArmor(Item $item) : bool{
		return isset(self::HORSE_ARMOR_POINTS[$item->getTypeId()]);
	}

	public function getArmorSlotIcon() : string{
		return "minecraft:horsearmoriron";
	}

	public function getArmorSlotItemNames() : array{
		return [
			"minecraft:horsearmorleather",
			"minecraft:horsearmoriron",
			"minecraft:horsearmorgold",
			"minecraft:horsearmordiamond",
			"minecraft:copper_horse_armor",
			"minecraft:netherite_horse_armor"
		];
	}

	/**
	 * Returns the horse armor worn (air for the animals that don't wear armor).
	 */
	public function getHorseArmor() : Item{
		return $this->hasArmorSlot() ? $this->inventory->getItem(HorseInventory::SLOT_ARMOR) : VanillaItems::AIR();
	}

	public function setHorseArmor(Item $armor) : void{
		if($this->hasArmorSlot()){
			$this->inventory->setItem(HorseInventory::SLOT_ARMOR, $armor);
		}
	}

	public function getArmorPoints() : int{
		return parent::getArmorPoints() + (self::HORSE_ARMOR_POINTS[$this->getHorseArmor()->getTypeId()] ?? 0);
	}

	/**
	 * The item shown on the animal's body (the body slot of MobArmorEquipmentPacket): horse armor, or a llama's carpet.
	 */
	public function getBodyItem() : Item{
		return $this->getHorseArmor();
	}

	/**
	 * Called when a slot of the inventory changed (through the screen, an interaction or a plugin).
	 */
	protected function onInventorySlotChange(int $slot, Item $before) : void{
		$this->networkPropertiesDirty = true;
		$item = $this->inventory->getItem($slot);
		if($slot === HorseInventory::SLOT_EQUIP || ($slot === HorseInventory::SLOT_ARMOR && $this->hasArmorSlot())){
			$this->broadcastBodyArmor();
			if(!$this->silentInventoryChange && !$item->isNull() && !$item->equals($before)){
				$this->playMobSound($this->getEquipSound($slot, $item));
			}
		}
	}

	/**
	 * The sound of putting the item in the saddle or armor slot.
	 */
	protected function getEquipSound(int $slot, Item $item) : string{
		return $slot === HorseInventory::SLOT_EQUIP ? LevelSoundEvent::SADDLE : LevelSoundEvent::ARMOR;
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
		$body = $this->getBodyItem();
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
		if(!$this->getBodyItem()->isNull()){
			$this->sendBodyArmor($player);
		}
	}

	/**
	 * Whether the player may open the inventory (a tamed adult, or one that needs no taming).
	 */
	public function canOpenInventory(Player $player) : bool{
		return $this->isAlive() && !$this->isBaby() && ($this->isTamed() || !$this->isTameable()) &&
			$player->getWorld() === $this->getWorld() &&
			$player->getPosition()->distanceSquared($this->location) <= self::MAX_VIEWER_DISTANCE ** 2;
	}

	/**
	 * Opens the inventory to the player. Returns whether it opened.
	 */
	public function openInventory(Player $player) : bool{
		return $this->canOpenInventory($player) && $player->setCurrentWindow($this->inventory);
	}

	/**
	 * Closes the inventory for viewers that went too far or that can't see it any more.
	 */
	private function checkInventoryViewers() : void{
		foreach($this->inventory->getViewers() as $viewer){
			if($viewer->getCurrentWindow() === $this->inventory && !$this->canOpenInventory($viewer)){
				$viewer->removeCurrentWindow();
			}
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

	/**
	 * Returns whether the horse swims up in water (behavior.float); the skeleton horse walks on the bottom.
	 */
	protected function floatsInWater() : bool{
		return true;
	}

	/**
	 * Returns whether the horse runs around when hurt (behavior.panic); undead horses don't.
	 */
	protected function panicsWhenHurt() : bool{
		return true;
	}

	protected function registerGoals() : void{
		if($this->floatsInWater()){
			$this->goalSelector->addGoal(0, new FloatGoal($this));
		}
		if($this->panicsWhenHurt()){
			$this->goalSelector->addGoal(1, new PanicGoal($this, 1.2));
		}
		$this->goalSelector->addGoal(1, new class($this) extends Goal{
			//an untamed horse runs around while being broken in (Java RunAroundLikeCrazyGoal)
			public function __construct(private AbstractHorse $horse){}

			public function getFlags() : int{
				return self::FLAG_MOVE;
			}

			public function canStart() : bool{
				return $this->horse->isBeingBrokenIn();
			}

			public function canContinue() : bool{
				return $this->horse->isBeingBrokenIn() && !$this->horse->getNavigation()->isDone();
			}

			public function start() : void{
				$pos = $this->horse->findWanderPos(5, 4);
				if($pos !== null){
					$this->horse->getNavigation()->moveTo($pos->x, $pos->y, $pos->z, 1.2);
				}
			}

			public function stop() : void{
				$this->horse->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(2, new MountPathingGoal($this, 1.5));
		$this->goalSelector->addGoal(2, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(5, new TemptGoal($this, 1.2, fn(Item $item) => $this->isTemptItem($item)));
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 1.0));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 0.7));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
	}

	/**
	 * Items that lure the animal (horses: golden apples and carrots).
	 */
	protected function isTemptItem(Item $item) : bool{
		return in_array($item->getTypeId(), [ItemTypeIds::GOLDEN_APPLE, ItemTypeIds::ENCHANTED_GOLDEN_APPLE, ItemTypeIds::GOLDEN_CARROT], true);
	}

	public function isBreedingItem(Item $item) : bool{
		return $this->isTemptItem($item);
	}

	/**
	 * Whether the animal can be tamed at all (skeleton and zombie horses can't).
	 */
	public function isTameable() : bool{
		return true;
	}

	/**
	 * Whether a saddle can be put on it (llamas can't take one).
	 */
	public function canWearSaddle() : bool{
		return true;
	}

	public function getJumpStrength() : float{
		return $this->jumpStrength;
	}

	public function setJumpStrength(float $strength) : void{
		$this->jumpStrength = $strength;
		$this->attributeMap->get(Attribute::HORSE_JUMP_STRENGTH)?->setValue(max(0.0, min(2.0, $strength)), true);
	}

	public function getTemper() : int{
		return $this->temper;
	}

	public function setTemper(int $temper) : void{
		$this->temper = max(0, min($this->getMaxTemper(), $temper));
	}

	/**
	 * The temper at which the animal can't refuse its rider (vanilla tamemount max_temper: 100, llamas 30). A break-in
	 * succeeds when a random number below it is under the temper.
	 */
	protected function getMaxTemper() : int{
		return self::MAX_TEMPER;
	}

	public function isSaddled() : bool{
		return $this->canWearSaddle() && $this->inventory->getItem(HorseInventory::SLOT_EQUIP)->getTypeId() === ItemTypeIds::SADDLE;
	}

	/**
	 * Puts a saddle in the saddle slot, or empties it.
	 */
	public function setSaddled(bool $saddled) : void{
		if($this->canWearSaddle()){
			$this->inventory->setItem(HorseInventory::SLOT_EQUIP, $saddled ? VanillaItems::SADDLE() : VanillaItems::AIR());
		}
	}

	/**
	 * Makes the player ride the animal (breaking it in if it isn't tamed, see the class doc). Returns whether the player
	 * got on.
	 */
	public function tryMount(Player $player) : bool{
		return !$this->isBaby() && $this->tryRide($player);
	}

	/**
	 * Returns whether a player rides the untamed animal to tame it.
	 */
	public function isBeingBrokenIn() : bool{
		return !$this->isTamed() && $this->isTameable() && !$this->isBaby() && $this->getControllingPassenger() instanceof Player;
	}

	/**
	 * One try of breaking the animal in (every tick, 1 in 50): tames it for its rider or throws the rider off.
	 */
	private function tryBreakIn() : void{
		$rider = $this->getControllingPassenger();
		if(!$rider instanceof Player){
			return;
		}
		if(mt_rand(0, $this->getMaxTemper() - 1) < $this->temper){
			$this->setOwner($rider);
			$this->broadcastActorEvent(ActorEvent::TAME_SUCCESS);
			return;
		}
		$this->setTemper($this->temper + 5);
		$rider->dismount(true);
		$this->broadcastActorEvent(ActorEvent::TAME_FAIL);
		$this->playMobSound(LevelSoundEvent::MAD);
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($player->isSneaking() && $this->canOpenInventoryByInteracting($player)){
			return "action.interact.opencontainer";
		}
		$equipText = $this->getEquipInteractText($item);
		if($equipText !== null){
			return $equipText;
		}
		$shearSlot = $this->getShearSlot($player, $item);
		if($shearSlot !== null){
			return match(true){
				$shearSlot === HorseInventory::SLOT_ARMOR => "action.interact.removehorsearmor",
				$this->canWearSaddle() => "action.interact.removesaddle",
				default => "action.interact.removecarpet"
			};
		}
		if($this->getFood($item) !== null){
			return "action.interact.feed";
		}
		if(!$item->isNull() && !$this->isTamed() && $this->isTameable()){
			return ""; //only an empty hand mounts a wild one
		}
		return null;
	}

	protected function getRideInteractText() : string{
		return $this->isTamed() || !$this->isTameable() ? "action.interact.ride.horse" : "action.interact.mount";
	}

	/**
	 * The seat of the rider (vanilla rideable component).
	 */
	protected function getRiderSeat() : RideableSeat{
		return RideableSeat::at(0, 1.1, -0.2);
	}

	public function getRideableSeats() : array{
		return $this->isBaby() ? [] : [$this->getRiderSeat()];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		if($this->isBaby()){
			return false;
		}
		//vanilla family types: players, and a baby undead (jockey) on a wild one
		return $entity instanceof Player || (!$this->isTamed() && $entity instanceof Zombie && $entity->isBaby());
	}

	public function canBeSteeredBy(Player $rider) : bool{
		return $this->isRiderControllable();
	}

	/**
	 * Whether its rider steers it (input_ground_controlled, can_power_jump): saddled, or always for skeleton horses.
	 */
	private function isRiderControllable() : bool{
		return ($this->isSaddled() || $this->isSteeredWithoutSaddle()) && ($this->isTamed() || !$this->isTameable());
	}

	/**
	 * Whether a player rider steers it without a saddle (skeleton horses: input_ground_controlled is not in a saddled
	 * component group).
	 */
	protected function isSteeredWithoutSaddle() : bool{
		return false;
	}

	/**
	 * Whether its riders are thrown off after 2 seconds with it under water (behavior.float
	 * time_under_water_to_dismount_passengers: horses, donkeys, mules, zombie horses).
	 */
	protected function dismountsRidersUnderwater() : bool{
		return $this->floatsInWater();
	}

	protected function isDrivenByRiderClient() : bool{
		return true;
	}

	protected function getRiddenSpeed(Player $rider) : float{
		return $this->getMovementSpeed() * 2.1;
	}

	protected function steer(Player $rider, RiderInput $input) : void{
		parent::steer($rider, $input);
		if($input->jumping){
			if($this->jumpChargeTicks >= 0){
				++$this->jumpChargeTicks;
			}elseif($this->onGround){
				$this->jumpChargeTicks = 0;
			}
		}elseif($this->jumpChargeTicks >= 0){
			//released: the longer it was held (up to 10 ticks), the higher the jump (Java)
			$ticks = $this->jumpChargeTicks;
			$this->jumpChargeTicks = -1;
			$scale = $ticks < 10 ? $ticks * 0.1 : 0.8 + 2.0 / ($ticks - 9) * 0.1;
			$this->onRiderJump($scale >= 0.9 ? 1.0 : 0.4 + 0.4 * $scale / 0.9);
		}
	}

	/**
	 * Jumps with the given power (0.4 to 1) when the rider releases the jump button (server-moved horses only).
	 */
	protected function onRiderJump(float $power) : void{
		if(!$this->onGround){
			return;
		}
		$jumpBoost = $this->effectManager->get(VanillaEffects::JUMP_BOOST());
		$vy = $this->jumpStrength * $power + ($jumpBoost !== null ? $jumpBoost->getEffectLevel() * 0.1 : 0);
		$yaw = deg2rad($this->location->yaw);
		$this->motion = $this->motion->add(-0.4 * sin($yaw) * $power, 0, 0.4 * cos($yaw) * $power)->withComponents(null, $vy, null);
		$this->playMobSound(LevelSoundEvent::JUMP);
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return ceil($fallDistance * 0.5 - 3);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->closed && $this->isAlive() && $this->isBeingBrokenIn() && mt_rand(0, 49) === 0){
			$this->tryBreakIn();
		}
		if(!$this->closed){
			$this->checkInventoryViewers();
			if(count($this->passengers) > 0 && $this->dismountsRidersUnderwater() && $this->isUnderwater()){
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
		return $hasUpdate;
	}

	protected function getFeedGrowth(Item $item) : int{
		$food = $this->getFood($item);
		return $food !== null ? (int) ($food[1] * -self::BABY_AGE) : parent::getFeedGrowth($item);
	}

	/**
	 * Returns [heal, growth, temper] for a food item, or null if the animal doesn't eat it.
	 *
	 * @phpstan-return array{int, float, int}|null
	 */
	protected function getFood(Item $item) : ?array{
		if($item->getTypeId() === VanillaBlocks::HAY_BALE()->asItem()->getTypeId()){
			return [20, 0.15, 0];
		}
		return self::FOOD[$item->getTypeId()] ?? null;
	}

	/**
	 * Whether a player using the animal from the ground may open its inventory (sneaking; not while someone rides it).
	 */
	private function canOpenInventoryByInteracting(Player $player) : bool{
		return $player->getVehicle() !== $this && count($this->getPassengers()) === 0 && $this->canOpenInventory($player);
	}

	/**
	 * Returns the inventory slot that the held item would be put in by using it on the animal (saddle, armor, carpet),
	 * or null.
	 */
	private function getEquipSlotFor(Item $item) : ?int{
		if($item->isNull() || (!$this->isTamed() && $this->isTameable()) || $this->isBaby()){
			return null;
		}
		$single = (clone $item)->setCount(1);
		if($this->inventory->isSlotEmpty(HorseInventory::SLOT_EQUIP) && $this->canHoldInSlot(HorseInventory::SLOT_EQUIP, $single)){
			return HorseInventory::SLOT_EQUIP;
		}
		if($this->hasArmorSlot() && $this->inventory->isSlotEmpty(HorseInventory::SLOT_ARMOR) && $this->canHoldInSlot(HorseInventory::SLOT_ARMOR, $single)){
			return HorseInventory::SLOT_ARMOR;
		}
		return null;
	}

	/**
	 * Returns the inventory slot that shears in the player's hand would empty (vanilla horse_tamed interactions with
	 * drop_item_slot: the horse armor first, then the saddle or a llama's carpet; nobody riding it, not sneaking), or
	 * null.
	 */
	private function getShearSlot(Player $player, Item $item) : ?int{
		if($item->getTypeId() !== ItemTypeIds::SHEARS || $player->isSneaking() || count($this->passengers) > 0 ||
			(!$this->isTamed() && $this->isTameable()) || $this->isBaby()
		){
			return null;
		}
		if($this->hasArmorSlot() && !$this->inventory->isSlotEmpty(HorseInventory::SLOT_ARMOR)){
			return HorseInventory::SLOT_ARMOR;
		}
		$equip = $this->inventory->getItem(HorseInventory::SLOT_EQUIP);
		if(!$equip->isNull() && $this->acceptsInEquipSlot($equip)){
			return HorseInventory::SLOT_EQUIP;
		}
		return null;
	}

	/**
	 * Takes the item out of the slot with shears: it drops 1.1 blocks above the animal, the shears lose 1 durability
	 * (hurt_item) and sculk sensors hear it (vibration shear).
	 */
	private function shearSlot(Player $player, int $slot) : void{
		$item = $this->inventory->getItem($slot);
		$this->inventory->setItem($slot, VanillaItems::AIR());
		$this->getWorld()->dropItem($this->location->add(0, 1.1, 0), $item);
		$this->playMobSound($this->getUnequipSound($slot));
		$this->damageHeldItem($player);
		$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
	}

	/**
	 * The sound of taking the item out of the saddle or armor slot with shears.
	 */
	protected function getUnequipSound(int $slot) : string{
		return $slot === HorseInventory::SLOT_EQUIP ? LevelSoundEvent::UNSADDLE : LevelSoundEvent::ARMOR_UNEQUIP_GENERIC;
	}

	private function canAttachChest(Item $item) : bool{
		return $this->canHaveChest() && !$this->chested && $this->isTamed() && !$this->isBaby() &&
			$item->getTypeId() === VanillaBlocks::CHEST()->asItem()->getTypeId();
	}

	private function getEquipInteractText(Item $item) : ?string{
		if($this->canAttachChest($item)){
			return "action.interact.attachchest";
		}
		return match($this->getEquipSlotFor($item)){
			null => null,
			HorseInventory::SLOT_ARMOR => "action.interact.equiphorsearmor",
			default => $this->canWearSaddle() ? "action.interact.saddle" : "action.interact.equipcarpet"
		};
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($player->isSneaking() && $this->canOpenInventoryByInteracting($player)){
			//Java: a sneaking player opens the inventory of a tamed one whatever it holds
			return $this->openInventory($player);
		}
		if($this->canAttachChest($item)){
			$this->consumeHeldItem($player);
			$this->setChested(true);
			$this->playMobSound(LevelSoundEvent::ADD_CHEST);
			return true;
		}
		$equipSlot = $this->getEquipSlotFor($item);
		if($equipSlot !== null){
			$this->consumeHeldItem($player);
			$this->inventory->setItem($equipSlot, (clone $item)->setCount(1));
			return true;
		}
		$shearSlot = $this->getShearSlot($player, $item);
		if($shearSlot !== null){
			$this->shearSlot($player, $shearSlot);
			return true;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true; //breeding, or feeding a baby
		}
		$food = $this->getFood($item);
		if($food !== null){
			[$heal, , $temper] = $food;
			if($this->isBaby()){
				if($this->isAgeLocked()){
					return false;
				}
				$this->consumeHeldItem($player);
				$this->ageUp($this->getFeedGrowth($item));
				$this->broadcastActorEvent(ActorEvent::BABY_ANIMAL_FEED);
				return true;
			}
			if($this->getHealth() >= $this->getMaxHealth() && ($this->isTamed() || $this->temper >= $this->getMaxTemper())){
				return false;
			}
			$this->consumeHeldItem($player);
			$this->heal(new EntityRegainHealthEvent($this, $heal, EntityRegainHealthEvent::CAUSE_EATING));
			if(!$this->isTamed()){
				$this->setTemper($this->temper + $temper);
			}
			$this->playMobSound(LevelSoundEvent::EAT);
			return true;
		}
		if(!$this->isTamed() && $this->isTameable() && !$item->isNull()){
			return false; //only an empty hand mounts a wild one
		}
		return $this->tryMount($player);
	}

	/**
	 * Gives a baby the stats blended from its parents' (offspring parent_centric_attribute_blending, Java
	 * createOffspringAttribute): health always, movement and jump strength only for the animals that blend them (horses);
	 * the others keep the baby's own random values.
	 */
	protected function inheritStats(AbstractHorse $baby, Animal $partner) : void{
		if(!$partner instanceof AbstractHorse){
			return;
		}
		$baby->setMaxHealth((int) round(self::blendOffspringAttribute($this->getMaxHealth(), $partner->getMaxHealth(), self::MIN_HEALTH, self::MAX_HEALTH)));
		$baby->setHealth($baby->getMaxHealth());
		if($this->blendsMovementAndJump()){
			$baby->setMovementSpeed(self::blendOffspringAttribute($this->getBaseMovementSpeed(), $partner->getBaseMovementSpeed(), self::MIN_SPEED, self::MAX_SPEED));
			$baby->setJumpStrength(self::blendOffspringAttribute($this->jumpStrength, $partner->getJumpStrength(), self::MIN_JUMP, self::MAX_JUMP));
		}
	}

	/**
	 * Whether a baby bred by this animal blends the parents' movement and jump strength too (horse.json; donkeys and
	 * llamas blend only health).
	 */
	protected function blendsMovementAndJump() : bool{
		return false;
	}

	/**
	 * Java AbstractHorse.createOffspringAttribute(): the average of the parents (clamped to the range) plus a random
	 * part as large as their difference plus 30% of the range, centred on 0 (mean of three randoms), reflected back
	 * into the range when it goes past an end.
	 */
	public static function blendOffspringAttribute(float $a, float $b, float $min, float $max) : float{
		$a = max($min, min($max, $a));
		$b = max($min, min($max, $b));
		$spread = abs($a - $b) + 0.3 * ($max - $min);
		$quality = (Utils::getRandomFloat() + Utils::getRandomFloat() + Utils::getRandomFloat()) / 3 - 0.5;
		$value = ($a + $b) / 2 + $spread * $quality;
		if($value > $max){
			return $max - ($value - $max);
		}
		if($value < $min){
			return $min + ($min - $value);
		}
		return $value;
	}

	public function getXpDropAmount() : int{
		return !$this->isBaby() && $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		//everything in the inventory (saddle, armor, carpet, chest contents) and the chest itself
		foreach($this->inventory->getContents() as $item){
			$drops[] = $item;
		}
		if($this->chested){
			$drops[] = VanillaBlocks::CHEST()->asItem();
		}
		return $drops;
	}

	protected function getAdultDrops() : array{
		$count = mt_rand(0, 2) + $this->lootingBonus();
		return $count > 0 ? [VanillaItems::LEATHER()->setCount($count)] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $this->isSaddled());
		$controllable = $this->isRiderControllable();
		$properties->setGenericFlag(EntityMetadataFlags::WASD_CONTROLLED, $controllable);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_POWER_JUMP, $controllable);
		$properties->setGenericFlag(EntityMetadataFlags::CHESTED, $this->chested);
		//vanilla container component (GeyserMC sends the same): the client opens the horse screen of a tamed one
		$properties->setByte(EntityMetadataProperties::CONTAINER_TYPE, $this->isTamed() || !$this->isTameable() ? WindowTypes::HORSE : 0);
		$properties->setInt(EntityMetadataProperties::CONTAINER_BASE_SIZE, $this->getContainerBaseSize());
		$properties->setInt(EntityMetadataProperties::CONTAINER_EXTRA_SLOTS_PER_STRENGTH, $this->getContainerSlotsPerStrength());
	}

	/**
	 * Vanilla inventory_size of the container component (horses 2, chested animals 16).
	 */
	protected function getContainerBaseSize() : int{
		return $this->canHaveChest() ? 1 + self::CHEST_SIZE : 2;
	}

	/**
	 * Vanilla additional_slots_per_strength of the container component (llamas 3).
	 */
	protected function getContainerSlotsPerStrength() : int{
		return 0;
	}

	/**
	 * Random value between $min and $max.
	 */
	protected static function randomRange(float $min, float $max) : float{
		return $min + Utils::getRandomFloat() * ($max - $min);
	}
}
