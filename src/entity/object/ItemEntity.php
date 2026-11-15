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

use pocketmine\block\utils\ItemTransfer;
use pocketmine\entity\animation\ItemEntityStackSizeChangeAnimation;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\ItemDespawnEvent;
use pocketmine\event\entity\ItemMergeEvent;
use pocketmine\event\entity\ItemSpawnEvent;
use pocketmine\item\Bundle;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddItemActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\timings\Timings;
use function abs;
use function floatval;
use function floor;
use function max;
use const PHP_INT_MIN;

class ItemEntity extends Entity{

	private const TAG_HEALTH = "Health"; //TAG_Short
	private const TAG_AGE = "Age"; //TAG_Short
	private const TAG_PICKUP_DELAY = "PickupDelay"; //TAG_Short
	private const TAG_OWNER = "Owner"; //TAG_String
	private const TAG_THROWER = "Thrower"; //TAG_String
	public const TAG_ITEM = "Item"; //TAG_Compound

	public static function getNetworkTypeId() : string{ return EntityIds::ITEM; }

	public const MERGE_CHECK_PERIOD = 2; //0.1 seconds
	public const DEFAULT_DESPAWN_DELAY = 6000; //5 minutes
	public const NEVER_DESPAWN = -1;
	public const MAX_DESPAWN_DELAY = 32767 + self::DEFAULT_DESPAWN_DELAY; //max value storable by mojang NBT :(

	protected string $owner = "";
	protected string $thrower = "";
	protected int $pickupDelay = 0;
	protected int $despawnDelay = self::DEFAULT_DESPAWN_DELAY;
	protected Item $item;
	/** Block of the last position checked for a hopper below (no block until the first check) */
	private int $lastBlockX = 0;
	private int $lastBlockY = PHP_INT_MIN;
	private int $lastBlockZ = 0;

	public function __construct(Location $location, Item $item, ?CompoundTag $nbt = null){
		if($item->isNull()){
			throw new \InvalidArgumentException("Item entity must have a non-air item with a count of at least 1");
		}
		$this->item = clone $item;
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->setMaxHealth(5);
		$this->setHealth($nbt->getShort(self::TAG_HEALTH, (int) $this->getHealth()));

		$age = $nbt->getShort(self::TAG_AGE, 0);
		if($age === -32768){
			$this->despawnDelay = self::NEVER_DESPAWN;
		}else{
			$this->despawnDelay = max(0, self::DEFAULT_DESPAWN_DELAY - $age);
		}
		$this->pickupDelay = $nbt->getShort(self::TAG_PICKUP_DELAY, $this->pickupDelay);
		$this->owner = $nbt->getString(self::TAG_OWNER, $this->owner);
		$this->thrower = $nbt->getString(self::TAG_THROWER, $this->thrower);
	}

	/**
	 * Hoppers don't look for items on their own: wake up a hopper when the item enters its bowl or the block above it.
	 */
	private function checkHopperBelow() : void{
		if($this->isFlaggedForDespawn()){
			return;
		}
		//every tick for every item, mostly lying still: compare block coordinates, no block hash (which doesn't exist
		//either for positions far below the world, where items fall into the void)
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		if($y === $this->lastBlockY && $x === $this->lastBlockX && $z === $this->lastBlockZ){
			return;
		}
		$this->lastBlockX = $x;
		$this->lastBlockY = $y;
		$this->lastBlockZ = $z;
		//positions outside the world have no hopper: wakeHopperAt() checks that first
		$world = $this->getWorld();
		ItemTransfer::wakeHopperAt($world, $x, $y, $z);
		ItemTransfer::wakeHopperAt($world, $x, $y - 1, $z);
	}

	protected function onFirstUpdate(int $currentTick) : void{
		(new ItemSpawnEvent($this))->call(); //this must be called before EntitySpawnEvent, to maintain backwards compatibility
		parent::onFirstUpdate($currentTick);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		Timings::$itemEntityBaseTick->startTiming();
		try{

			$hasUpdate = parent::entityBaseTick($tickDiff);
			$this->updateFloatDepth();

			if($this->isFlaggedForDespawn()){
				return $hasUpdate;
			}

			if($this->pickupDelay !== self::NEVER_DESPAWN && $this->pickupDelay > 0){ //Infinite delay
				$hasUpdate = true;
				$this->pickupDelay -= $tickDiff;
				if($this->pickupDelay < 0){
					$this->pickupDelay = 0;
				}
			}

			if($this->hasMovementUpdate() && $this->isMergeCandidate() && $this->despawnDelay % self::MERGE_CHECK_PERIOD === 0){
				$mergeable = [$this]; //in case the merge target ends up not being this
				$mergeTarget = $this;
				foreach($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy(0.5, 0.5, 0.5), $this) as $entity){
					if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn()){
						continue;
					}

					if($entity->isMergeable($this)){
						$mergeable[] = $entity;
						if($entity->item->getCount() > $mergeTarget->item->getCount()){
							$mergeTarget = $entity;
						}
					}
				}
				foreach($mergeable as $itemEntity){
					if($itemEntity !== $mergeTarget){
						$itemEntity->tryMergeInto($mergeTarget);
					}
				}
			}

			$this->checkHopperBelow();

			if(!$this->isFlaggedForDespawn() && $this->despawnDelay !== self::NEVER_DESPAWN){
				$hasUpdate = true;
				$this->despawnDelay -= $tickDiff;
				if($this->despawnDelay <= 0){
					$ev = new ItemDespawnEvent($this);
					$ev->call();
					if($ev->isCancelled()){
						$this->despawnDelay = self::DEFAULT_DESPAWN_DELAY;
					}else{
						$this->flagForDespawn();
					}
				}
			}

			return $hasUpdate;
		}finally{
			Timings::$itemEntityBaseTick->stopTiming();
		}
	}

	private function isMergeCandidate() : bool{
		return $this->pickupDelay !== self::NEVER_DESPAWN && $this->item->getCount() < $this->item->getMaxStackSize();
	}

	/**
	 * Returns whether this item entity can merge with the given one.
	 */
	public function isMergeable(ItemEntity $entity) : bool{
		if(!$this->isMergeCandidate() || !$entity->isMergeCandidate()){
			return false;
		}
		$item = $entity->item;
		return $entity !== $this && $item->canStackWith($this->item) && $item->getCount() + $this->item->getCount() <= $item->getMaxStackSize();
	}

	/**
	 * Attempts to merge this item entity into the given item entity. Returns true if it was successful.
	 */
	public function tryMergeInto(ItemEntity $consumer) : bool{
		if(!$this->isMergeable($consumer)){
			return false;
		}

		$ev = new ItemMergeEvent($this, $consumer);
		$ev->call();

		if($ev->isCancelled()){
			return false;
		}

		$consumer->setStackSize($consumer->item->getCount() + $this->item->getCount());
		$this->flagForDespawn();
		$consumer->pickupDelay = max($consumer->pickupDelay, $this->pickupDelay);
		$consumer->despawnDelay = max($consumer->despawnDelay, $this->despawnDelay);

		return true;
	}

	/**
	 * How deep the item is in the liquid it floats in (water, or lava for a fire-proof item) and whether that is lava,
	 * worked out at the end of each tick (see updateFloatDepth()).
	 */
	private float $floatDepth = 0.0;
	private bool $floatsInLava = false;
	/**
	 * The item stopped at the surface of the liquid (see restsOnLiquidSurface()) and nothing changed since: its float
	 * depth stays the same until its next movement update (a push, a current, a block change nearby, a teleport).
	 */
	private bool $restingOnLiquid = false;

	/**
	 * Called each tick after the block intersection check: works out how the item floats during the next tick.
	 */
	private function updateFloatDepth() : void{
		if($this->restingOnLiquid){
			return;
		}
		if($this->blocksAround === []){
			//nothing with entity actions around (the usual case): no liquid
			$this->floatDepth = 0.0;
			return;
		}
		//vanilla ItemEntity.tick(): items float up in water, and fire-proof ones in lava (the others burn there)
		$depth = $this->getBoundingBoxLiquidDepth();
		$inLava = false;
		if($depth <= self::FLOAT_MIN_LIQUID_DEPTH){
			$lavaDepth = $this->getBoundingBoxLiquidDepth(true);
			if($lavaDepth > $depth && $this->isFireProof()){
				$depth = $lavaDepth;
				$inLava = true;
			}
		}
		$this->floatDepth = $depth;
		$this->floatsInLava = $inLava;
		if($depth > self::FLOAT_MIN_LIQUID_DEPTH){
			//an item lying at the bottom of a pool floats up (a movement update here instead of an override of
			//hasMovementUpdate(), which is called several times a tick for every item)
			$this->forceMovementUpdate = true;
		}elseif($depth > 0.0 && $this->onGround && floatval($this->motion->x) === 0.0 && floatval($this->motion->y) === 0.0 && floatval($this->motion->z) === 0.0){
			$this->restingOnLiquid = true;
		}
	}

	protected function tryChangeMovement() : void{
		$this->restingOnLiquid = false;
		$this->checkObstruction($this->location->x, $this->location->y, $this->location->z);

		$depth = $this->floatDepth;
		if($depth <= self::FLOAT_MIN_LIQUID_DEPTH){
			if($depth > 0.0 && $this->restsOnLiquidSurface()){
				return;
			}
			parent::tryChangeMovement();
			return;
		}

		$horizontal = $this->floatsInLava ? self::LAVA_FLOAT_HORIZONTAL_KEPT : self::WATER_FLOAT_HORIZONTAL_KEPT;
		$friction = 1 - $this->drag;
		$mY = $this->motion->y;
		$this->motion = new Vector3(
			$this->motion->x * $horizontal * $friction,
			($mY < self::FLOAT_MAX_RISE_SPEED ? $mY + self::FLOAT_RISE_ACCELERATION : $mY) * $friction,
			$this->motion->z * $horizontal * $friction
		);
	}

	/** Items float when their bottom is deeper than this in the liquid (vanilla ItemEntity.tick()). */
	private const FLOAT_MIN_LIQUID_DEPTH = 0.1;
	/** Vertical speed gained each tick while floating up, up to FLOAT_MAX_RISE_SPEED (vanilla setFluidMovement()). */
	private const FLOAT_RISE_ACCELERATION = 0.0005;
	private const FLOAT_MAX_RISE_SPEED = 0.06;
	/** Horizontal speed kept each tick while floating in water and in lava (vanilla setUnderwaterMovement(), setUnderLavaMovement()). */
	private const WATER_FLOAT_HORIZONTAL_KEPT = 0.99;
	private const LAVA_FLOAT_HORIZONTAL_KEPT = 0.95;
	/** Below this speed an item that reached the surface of still water stops there, see restsOnLiquidSurface(). */
	private const FLOAT_REST_MAX_SPEED = 0.05;

	/**
	 * Called when the item touches water at the surface (not deep enough to float up any more): an item that floated up
	 * slowly to the surface of still water stops there, like an item lying on the ground, instead of bobbing up and down
	 * every tick (vanilla bobs; this saves moving every floating item each tick). A current, a block change nearby or
	 * a push wakes it up again.
	 */
	private function restsOnLiquidSurface() : bool{
		$motion = $this->motion;
		if(abs($motion->y) >= self::FLOAT_REST_MAX_SPEED || $motion->x * $motion->x + $motion->z * $motion->z > 1.0e-4){
			return false;
		}
		$this->motion = Vector3::zero();
		$this->onGround = true;
		return true;
	}

	protected function applyDragBeforeGravity() : bool{
		return true;
	}

	public function canSaveWithChunk() : bool{
		return !$this->item->isNull() && parent::canSaveWithChunk();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		$nbt->setShort(self::TAG_HEALTH, (int) $this->getHealth());
		if($this->despawnDelay === self::NEVER_DESPAWN){
			$age = -32768;
		}else{
			$age = self::DEFAULT_DESPAWN_DELAY - $this->despawnDelay;
		}
		$nbt->setShort(self::TAG_AGE, $age);
		$nbt->setShort(self::TAG_PICKUP_DELAY, $this->pickupDelay);
		$nbt->setString(self::TAG_OWNER, $this->owner);
		$nbt->setString(self::TAG_THROWER, $this->thrower);

		return $nbt;
	}

	public function getItem() : Item{
		return $this->item;
	}

	protected function onDeath() : void{
		parent::onDeath();
		//a destroyed bundle (fire, lava, cactus...) spills its contents, like a shulker box in Java
		if($this->item instanceof Bundle){
			$world = $this->getWorld();
			$position = $this->location->asVector3();
			foreach($this->item->getContents() as $content){
				$world->dropItem($position, $content);
			}
		}
	}

	public function isFireProof() : bool{
		return $this->item->isFireProof();
	}

	public function attack(EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		if(($cause === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION || $cause === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION) && $this->item->getTypeId() === ItemTypeIds::NETHER_STAR){
			$source->cancel(); //vanilla: a nether star survives explosions
		}
		parent::attack($source);
	}

	public function canCollideWith(Entity $entity) : bool{
		return false;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function getPickupDelay() : int{
		return $this->pickupDelay;
	}

	public function setPickupDelay(int $delay) : void{
		$this->pickupDelay = $delay;
	}

	/**
	 * Returns the number of ticks left before this item will despawn. If -1, the item will never despawn.
	 */
	public function getDespawnDelay() : int{
		return $this->despawnDelay;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function setDespawnDelay(int $despawnDelay) : void{
		if(($despawnDelay < 0 || $despawnDelay > self::MAX_DESPAWN_DELAY) && $despawnDelay !== self::NEVER_DESPAWN){
			throw new \InvalidArgumentException("Despawn ticker must be in range 0 ... " . self::MAX_DESPAWN_DELAY . " or " . self::NEVER_DESPAWN . ", got $despawnDelay");
		}
		$this->despawnDelay = $despawnDelay;
	}

	public function getOwner() : string{
		return $this->owner;
	}

	public function setOwner(string $owner) : void{
		$this->owner = $owner;
	}

	public function getThrower() : string{
		return $this->thrower;
	}

	public function setThrower(string $thrower) : void{
		$this->thrower = $thrower;
	}

	protected function sendSpawnPacket(Player $player) : void{
		$networkSession = $player->getNetworkSession();
		$networkSession->sendDataPacket(AddItemActorPacket::create(
			$this->getId(), //TODO: entity unique ID
			$this->getId(),
			ItemStackWrapper::legacy($networkSession->getTypeConverter()->coreItemStackToNet($this->getItem())),
			$this->location->asVector3(),
			$this->getMotion(),
			$this->getAllNetworkData(),
			false //TODO: I have no idea what this is needed for, but right now we don't support fishing anyway
		));
	}

	public function setStackSize(int $newCount) : void{
		if($newCount <= 0){
			throw new \InvalidArgumentException("Stack size must be at least 1");
		}
		$this->item->setCount($newCount);
		$this->broadcastAnimation(new ItemEntityStackSizeChangeAnimation($this, $newCount));
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, 0.125, 0);
	}

	public function onCollideWithPlayer(Player $player) : void{
		if($this->getPickupDelay() !== 0){
			return;
		}

		$item = $this->getItem();
		$playerInventory = match(true){
			$player->getOffHandInventory()->getItem(0)->canStackWith($item) && $player->getOffHandInventory()->getAddableItemQuantity($item) > 0 => $player->getOffHandInventory(),
			$player->getInventory()->getAddableItemQuantity($item) > 0 => $player->getInventory(),
			default => null
		};

		$ev = new EntityItemPickupEvent($player, $this, $item, $playerInventory);
		if($player->hasFiniteResources() && $playerInventory === null){
			$ev->cancel();
		}

		$ev->call();
		if($ev->isCancelled()){
			return;
		}

		NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $player, $this)
		);

		$inventory = $ev->getInventory();
		if($inventory !== null){
			foreach($inventory->addItem($ev->getItem()) as $remains){
				$this->getWorld()->dropItem($this->location, $remains, new Vector3(0, 0, 0));
			}
		}
		$this->flagForDespawn();
	}
}
