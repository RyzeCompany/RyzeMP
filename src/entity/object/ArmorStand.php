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

namespace pocketmine\entity\object;

use pocketmine\block\BlockTypeIds;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Armor;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\sound\ArmorStandBreakSound;
use pocketmine\world\sound\ArmorStandPlaceSound;
use function array_merge;
use function floor;
use function max;
use function min;

/**
 * Armor stand (https://minecraft.wiki/w/Armor_Stand). Holds four armor pieces and an item in its hand:
 * - using an armor piece on it swaps it with the matching slot (carved pumpkins and mob heads go to the head slot), any
 *   other item swaps with the hand;
 * - using an empty hand takes the item at the height that was clicked (or the hand item);
 * - sneaking with an empty hand cycles through the 13 poses, a redstone signal in its block picks one;
 * - a hit from a creative player removes it, otherwise it breaks after 6 damage and drops itself and its items.
 */
class ArmorStand extends Living{
	public const MAX_POSE = 12;

	private const TAG_MAINHAND = "Mainhand"; //TAG_List<TAG_Compound>
	private const TAG_POSE = "Pose"; //TAG_Compound
	private const TAG_POSE_INDEX = "PoseIndex"; //TAG_Int

	public static function getNetworkTypeId() : string{ return EntityIds::ARMOR_STAND; }

	protected SimpleInventory $mainHand;
	protected int $pose = 0;
	/** Signal of the redstone component in the stand's block when it was last checked (not saved) */
	private int $redstonePower = 0;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.975, 0.5);
	}

	public function getName() : string{
		return "Armor Stand";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(6);
		$this->maxDeadTicks = 0;
		parent::initEntity($nbt);

		$this->mainHand = new SimpleInventory(1);
		$this->mainHand->getListeners()->add(CallbackInventoryListener::onAnyChange(function() : void{
			TypeConverter::broadcastByTypeConverter($this->getViewers(), fn(TypeConverter $converter) : array => [$this->createMainHandPacket($converter)]);
		}));

		//the armor is loaded and saved by Living ("Armor", helmet to boots)
		$handTag = $nbt->getListTag(self::TAG_MAINHAND, CompoundTag::class);
		if($handTag !== null && ($itemTag = $handTag->first()) instanceof CompoundTag){
			$this->mainHand->setItem(0, self::loadItem($itemTag));
		}
		$this->pose = $nbt->getCompoundTag(self::TAG_POSE)?->getInt(self::TAG_POSE_INDEX, 0) ?? 0;
		if($this->pose < 0 || $this->pose > self::MAX_POSE){
			$this->pose = 0;
		}
	}

	private static function loadItem(CompoundTag $tag) : Item{
		try{
			return Item::nbtDeserialize($tag);
		}catch(SavedDataLoadingException){
			return VanillaItems::AIR();
		}
	}

	/**
	 * Empty slots are saved like BDS does: an item with no name and a count of 0.
	 */
	private static function saveItem(Item $item) : CompoundTag{
		return $item->isNull() ? CompoundTag::create()->setString("Name", "")->setByte("Count", 0) : $item->nbtSerialize();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_MAINHAND, new ListTag([self::saveItem($this->mainHand->getItem(0))], NBT::TAG_Compound));
		$nbt->setTag(self::TAG_POSE, CompoundTag::create()->setInt(self::TAG_POSE_INDEX, $this->pose));
		return $nbt;
	}

	public function getMainHandItem() : Item{
		return $this->mainHand->getItem(0);
	}

	public function setMainHandItem(Item $item) : void{
		$this->mainHand->setItem(0, $item);
	}

	public function getPose() : int{ return $this->pose; }

	public function setPose(int $pose) : void{
		if($pose < 0 || $pose > self::MAX_POSE){
			throw new \InvalidArgumentException("Pose must be in range 0 ... " . self::MAX_POSE);
		}
		$this->pose = $pose;
		$this->networkPropertiesDirty = true;
	}

	private function createMainHandPacket(TypeConverter $converter) : MobEquipmentPacket{
		return MobEquipmentPacket::create(
			$this->getId(),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($this->mainHand->getItem(0))),
			0,
			0,
			ContainerIds::INVENTORY
		);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		$player->getNetworkSession()->sendDataPacket($this->createMainHandPacket($player->getNetworkSession()->getTypeConverter()));
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::ARMOR_STAND_POSE_INDEX, $this->pose);
	}

	/**
	 * Returns the armor slot, or null for the hand, that a click at the given height (relative to the feet) reaches.
	 * Like vanilla, an empty slot at that height falls back to the hand.
	 */
	public function getSlotAtHeight(float $y) : ?int{
		$armor = $this->armorInventory;
		return match(true){
			$y >= 0.1 && $y < 0.55 && !$armor->getBoots()->isNull() => ArmorInventory::SLOT_FEET,
			$y >= 0.9 && $y < 1.6 && !$armor->getChestplate()->isNull() => ArmorInventory::SLOT_CHEST,
			$y >= 0.4 && $y < 1.2 && !$armor->getLeggings()->isNull() => ArmorInventory::SLOT_LEGS,
			$y >= 1.6 && !$armor->getHelmet()->isNull() => ArmorInventory::SLOT_HEAD,
			default => null
		};
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSpectator()){
			return false;
		}
		$inventory = $player->getInventory();
		$held = $inventory->getItemInHand();
		if($held->isNull()){
			if($player->isSneaking()){
				$this->setPose(($this->pose + 1) % (self::MAX_POSE + 1));
				return true;
			}
			$slot = $this->getSlotAtHeight($clickPos->y);
			$taken = $slot !== null ? $this->armorInventory->getItem($slot) : $this->mainHand->getItem(0);
			if($taken->isNull()){
				return false;
			}
			if($slot !== null){
				$this->armorInventory->setItem($slot, VanillaItems::AIR());
			}else{
				$this->mainHand->setItem(0, VanillaItems::AIR());
			}
			$inventory->setItemInHand($taken);
			return true;
		}

		$slot = $held instanceof Armor ? $held->getArmorSlot() : (self::isWornOnHead($held) ? ArmorInventory::SLOT_HEAD : null);
		$old = $slot !== null ? $this->armorInventory->getItem($slot) : $this->mainHand->getItem(0);
		$placed = (clone $held)->setCount(1);
		if($slot !== null){
			$this->armorInventory->setItem($slot, $placed);
		}else{
			$this->mainHand->setItem(0, $placed);
		}

		if($player->hasFiniteResources()){
			$held->pop();
			$inventory->setItemInHand($held);
		}
		if(!$old->isNull()){
			if($inventory->getItemInHand()->isNull()){
				$inventory->setItemInHand($old);
			}else{
				$leftover = $inventory->addItem($old);
				foreach($leftover as $item){
					$player->dropItem($item);
				}
			}
		}
		return true;
	}

	/**
	 * Carved pumpkins and mob heads go to the head slot like helmets (the armor inventory accepts them there).
	 */
	private static function isWornOnHead(Item $item) : bool{
		if(!$item instanceof ItemBlock){
			return false;
		}
		$typeId = $item->getBlock()->getTypeId();
		return $typeId === BlockTypeIds::CARVED_PUMPKIN || $typeId === BlockTypeIds::MOB_HEAD;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source instanceof EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Player && $damager->isCreative()){
			//creative players remove it at once, without drops
			$source->call();
			if(!$source->isCancelled()){
				$this->broadcastSound(new ArmorStandBreakSound());
				$this->flagForDespawn();
			}
			return;
		}
		parent::attack($source);
	}

	protected function onFirstUpdate(int $currentTick) : void{
		parent::onFirstUpdate($currentTick);
		$this->updateRedstonePose();
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		$this->updateRedstonePose();
	}

	/**
	 * Bedrock redstone poses (checked against BDS 1.26.52): when the redstone component in the stand's own block (wire,
	 * torch, redstone block...) changes to a non-zero signal, the stand takes pose min(signal, 13) - 1. Losing the
	 * signal keeps the pose. Only called from block updates of the stand's block and its first tick, never polled.
	 */
	private function updateRedstonePose() : void{
		$pos = $this->location;
		$block = $this->getWorld()->getBlockAt((int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
		$power = 0;
		if($block->isRedstoneSource()){
			foreach(Facing::ALL as $face){
				$power = max($power, $block->getWeakPower($face));
			}
		}
		if($power === $this->redstonePower){
			return;
		}
		$this->redstonePower = $power;
		if($power > 0){
			$pose = min($power, self::MAX_POSE + 1) - 1;
			if($pose !== $this->pose){
				$this->setPose($pose);
			}
		}
	}

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		//armor stands don't get knocked back
	}

	protected function startDeathAnimation() : void{
		$this->broadcastSound(new ArmorStandBreakSound());
	}

	public function getDrops() : array{
		return array_merge(
			GameRules::allowsEntityDrops($this->getWorld()) ? [VanillaItems::ARMOR_STAND()] : [], //its equipment drops in any case
			$this->armorInventory->getContents(),
			$this->mainHand->getContents()
		);
	}

	public function getXpDropAmount() : int{
		return 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ARMOR_STAND();
	}

	/**
	 * Plays the sound of an armor stand being placed.
	 */
	public function playPlaceSound() : void{
		$this->broadcastSound(new ArmorStandPlaceSound());
	}

	protected function onDispose() : void{
		$this->mainHand->removeAllViewers();
		parent::onDispose();
	}
}
