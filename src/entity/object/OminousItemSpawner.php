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

use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\SplashPotion as SplashPotionEntity;
use pocketmine\entity\projectile\WindCharge as WindChargeEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\ArrowAmmo;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\SplashPotion;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\sound\BlazeShootSound;
use pocketmine\world\sound\OminousItemSpawnerAboutToSpawnSound;
use pocketmine\world\sound\OminousItemSpawnerSpawnItemSound;
use pocketmine\world\vibration\GameEvent;
use function mt_getrandmax;
use function mt_rand;

/**
 * Ominous item spawner (minecraft:ominous_item_spawner, vanilla OminousItemSpawner): created by ominous trial spawners
 * above the players and mobs of the fight, it holds one item and releases it after 60 to 120 ticks (with a warning
 * sound 36 ticks before), then vanishes. Arrows, potions and charges are shot straight down, other items dropped.
 * It cannot be hurt and doesn't move: its tick is only a countdown.
 */
class OminousItemSpawner extends Entity{
	public const SPAWN_ITEM_DELAY_MIN = 60;
	public const SPAWN_ITEM_DELAY_MAX = 120;
	public const TICKS_BEFORE_ABOUT_TO_SPAWN_SOUND = 36;

	private const TAG_ITEM = "Item"; //TAG_Compound
	private const TAG_SPAWN_ITEM_AFTER_TICKS = "spawn_item_after_ticks"; //TAG_Long

	public static function getNetworkTypeId() : string{ return EntityIds::OMINOUS_ITEM_SPAWNER; }

	private Item $item;
	private int $spawnItemAfterTicks;
	private bool $warned = false;

	public function __construct(Location $location, Item $item, ?CompoundTag $nbt = null){
		$this->item = clone $item;
		$this->spawnItemAfterTicks = mt_rand(self::SPAWN_ITEM_DELAY_MIN, self::SPAWN_ITEM_DELAY_MAX);
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$itemTag = $nbt->getCompoundTag(self::TAG_ITEM);
		if($itemTag !== null){
			try{
				$this->item = Item::nbtDeserialize($itemTag);
			}catch(SavedDataLoadingException){
				//keep the item given to the constructor
			}
		}
		$ticks = $nbt->getTag(self::TAG_SPAWN_ITEM_AFTER_TICKS);
		if($ticks !== null){
			$this->spawnItemAfterTicks = $nbt->getLong(self::TAG_SPAWN_ITEM_AFTER_TICKS);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if(!$this->item->isNull()){
			$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		}
		$nbt->setLong(self::TAG_SPAWN_ITEM_AFTER_TICKS, $this->spawnItemAfterTicks);
		return $nbt;
	}

	/**
	 * The item it will release (a copy).
	 */
	public function getItem() : Item{
		return clone $this->item;
	}

	/**
	 * Ticks after its creation at which it releases the item.
	 */
	public function getSpawnItemAfterTicks() : int{
		return $this->spawnItemAfterTicks;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function canBeMovedByCurrents() : bool{
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	public function hasMovementUpdate() : bool{
		return false;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		parent::entityBaseTick($tickDiff);
		if($this->isFlaggedForDespawn()){
			return false;
		}
		if(!$this->warned && $this->ticksLived >= $this->spawnItemAfterTicks - self::TICKS_BEFORE_ABOUT_TO_SPAWN_SOUND){
			$this->warned = true;
			$this->getWorld()->addSound($this->location, new OminousItemSpawnerAboutToSpawnSound());
		}
		if($this->ticksLived >= $this->spawnItemAfterTicks){
			$this->spawnItem();
			$this->flagForDespawn();
		}
		return true;
	}

	private function spawnItem() : void{
		if($this->item->isNull()){
			return;
		}
		$world = $this->getWorld();
		$item = $this->item;
		$this->item = VanillaItems::AIR();
		$spawned = $this->spawnProjectile($item);
		if($spawned === null){
			$spawned = $world->dropItem($this->location, $item);
		}
		$world->addSound($this->location, new OminousItemSpawnerSpawnItemSound());
		$world->addGameEvent($this->location->asVector3(), GameEvent::ENTITY_PLACE, $spawned);
	}

	/**
	 * Shoots the item straight down if it is a projectile item, like a dispenser facing down would (vanilla
	 * ProjectileItem dispense configs: power and uncertainty per item). Returns the projectile, or null if the item is
	 * not one (or the launch was cancelled: the item is lost, like a cancelled dispense).
	 */
	private function spawnProjectile(Item $item) : ?Entity{
		$location = Location::fromObject($this->location, $this->getWorld(), 0.0, 90.0);
		$sound = null;
		switch(true){
			case $item->getTypeId() === ItemTypeIds::ARROW || $item->getTypeId() === ItemTypeIds::TIPPED_ARROW:
				$entity = new ArrowEntity($location, null, false);
				ArrowAmmo::applyTip($item, $entity);
				[$power, $uncertainty] = [1.1, 6.0];
				break;
			case $item instanceof SplashPotion:
				$entity = new SplashPotionEntity($location, null, $item->getType());
				$entity->setLinger($item->willLinger());
				[$power, $uncertainty] = [1.375, 3.0];
				break;
			case $item->getTypeId() === ItemTypeIds::WIND_CHARGE:
				$entity = new WindChargeEntity($location, null);
				[$power, $uncertainty] = [1.0, 20 / 3];
				break;
			case $item->getTypeId() === ItemTypeIds::FIRE_CHARGE:
				$entity = new SmallFireball($location, null);
				[$power, $uncertainty] = [1.0, 20 / 3];
				$sound = new BlazeShootSound();
				break;
			default:
				return null;
		}
		//vanilla Projectile.shoot towards (0, -1, 0)
		$spread = 0.0172275 * $uncertainty;
		$entity->setMotion((new Vector3(
			$spread * (self::randomFloat() - self::randomFloat()),
			-1 + $spread * (self::randomFloat() - self::randomFloat()),
			$spread * (self::randomFloat() - self::randomFloat())
		))->multiply($power));
		$ev = new ProjectileLaunchEvent($entity);
		$ev->call();
		if($ev->isCancelled()){
			$entity->flagForDespawn();
			return $entity;
		}
		$entity->spawnToAll();
		if($sound !== null){
			$this->getWorld()->addSound($this->location, $sound);
		}
		return $entity;
	}

	private static function randomFloat() : float{
		return mt_rand() / mt_getrandmax();
	}
}
