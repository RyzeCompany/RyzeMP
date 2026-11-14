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

namespace pocketmine\entity\projectile;

use pocketmine\block\Block;
use pocketmine\block\LightningRod;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\MobSound;
use pocketmine\world\sound\TridentHitBlockSound;
use pocketmine\world\sound\TridentHitEntitySound;

class Trident extends Projectile{

	public const TAG_ITEM = "Trident"; //TAG_Compound
	protected const TAG_SPAWNED_IN_CREATIVE = "isCreative"; //TAG_Byte

	public static function getNetworkTypeId() : string{ return EntityIds::THROWN_TRIDENT; }

	protected Item $item;

	protected float $damage = 8.0;

	protected bool $canCollide = true;

	protected bool $spawnedInCreative = false;

	private bool $returning = false;
	private int $ticksInBlock = 0;

	public function __construct(
		Location $location,
		Item $item,
		?Entity $shootingEntity,
		?CompoundTag $nbt = null
	){
		if($item->isNull()){
			throw new \InvalidArgumentException("Trident must have a count of at least 1");
		}
		$this->item = clone $item;
		parent::__construct($location, $shootingEntity, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.35, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.01; }

	protected function getInitialGravity() : float{ return 0.1; }

	/** Bedrock thrown_trident.json liquid_inertia: tridents stay fast underwater. */
	protected function getLiquidInertia() : float{ return 0.99; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->spawnedInCreative = $nbt->getByte(self::TAG_SPAWNED_IN_CREATIVE, 0) === 1;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_ITEM, $this->item->nbtSerialize());
		$nbt->setByte(self::TAG_SPAWNED_IN_CREATIVE, $this->spawnedInCreative ? 1 : 0);
		return $nbt;
	}

	protected function onFirstUpdate(int $currentTick) : void{
		$owner = $this->getOwningEntity();
		//a trident loaded from disk keeps what it saved (its owner may be offline or in another game mode by now)
		$this->spawnedInCreative = $this->spawnedInCreative || ($owner instanceof Player && $owner->isCreative());

		parent::onFirstUpdate($currentTick);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isFlaggedForDespawn() && $this->getLoyaltyLevel() > 0){
			$this->tickLoyalty($tickDiff);
			$hasUpdate = true;
		}
		return $hasUpdate;
	}

	private function getLoyaltyLevel() : int{
		return $this->item->getEnchantmentLevel(VanillaEnchantments::LOYALTY());
	}

	public function isReturning() : bool{
		return $this->returning;
	}

	/**
	 * Loyalty (vanilla ThrownTrident.tick): once the trident hit a mob, has been stuck in a block for 4 ticks or fell
	 * into the void, it flies back through blocks to the thrower, faster with higher levels, and goes into their
	 * inventory when it reaches them. It drops if the thrower is gone.
	 */
	private function tickLoyalty(int $tickDiff) : void{
		$owner = $this->getOwningEntity();
		if(!$this->returning){
			if($this->blockHit !== null){
				$this->ticksInBlock += $tickDiff;
			}
			if(!$this->canCollide || $this->ticksInBlock > 4 || $this->location->y < $this->getWorld()->getMinY()){
				if(!$owner instanceof Player){
					return;
				}
				$this->returning = true;
				$this->blockHit = null;
				$this->canCollide = false;
				$this->gravity = 0.0;
			}else{
				return;
			}
		}
		if(!$owner instanceof Player || !$owner->isAlive() || $owner->isClosed() || $owner->getWorld() !== $this->getWorld()){
			if(!$this->spawnedInCreative){
				$this->getWorld()->dropItem($this->location, $this->getItem());
			}
			$this->flagForDespawn();
			return;
		}
		$loyalty = $this->getLoyaltyLevel();
		$target = $owner->getEyePos()->subtractVector($this->location);
		if($target->lengthSquared() < 2.25){
			$this->pickup($owner);
			return;
		}
		$this->motion = $this->motion->multiply(0.95)->addVector($target->normalize()->multiply(0.05 * $loyalty));
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		if(!$this->returning){
			parent::move($dx, $dy, $dz);
			return;
		}
		//returning tridents fly through blocks
		$this->location = Location::fromObject($this->location->add($dx, $dy, $dz), $this->location->world, $this->location->yaw, $this->location->pitch);
		$this->recalculateBoundingBox();
		$this->isCollided = $this->onGround = false;
		$this->getWorld()->onEntityMoved($this);
	}

	public function hasMovementUpdate() : bool{
		return $this->returning || parent::hasMovementUpdate();
	}

	protected function despawnsOnEntityHit() : bool{
		return false;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$impaling = VanillaEnchantments::IMPALING();
		$level = $this->item->getEnchantmentLevel($impaling);
		$baseDamage = $this->damage;
		if($level > 0 && $impaling->isApplicableTo($entityHit)){
			$this->damage += $impaling->getDamageBonus($level);
		}
		parent::onHitEntity($entityHit, $hitResult);
		$this->damage = $baseDamage;

		$this->canCollide = false;
		$pos = $entityHit->getPosition();
		if($this->channel($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			$this->broadcastSound(new MobSound(LevelSoundEvent::ITEM_TRIDENT_THUNDER, ":"));
		}else{
			$this->broadcastSound(new TridentHitEntitySound());
		}
		$this->setMotion(new Vector3($this->motion->x * -0.01, $this->motion->y * -0.1, $this->motion->z * -0.01));
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		parent::onHitBlock($blockHit, $hitResult);
		$this->canCollide = true;
		$pos = $blockHit->getPosition();
		if($blockHit instanceof LightningRod && $this->channel($pos->getFloorX(), $pos->getFloorY() + 1, $pos->getFloorZ())){
			$this->broadcastSound(new MobSound(LevelSoundEvent::ITEM_TRIDENT_THUNDER, ":"));
		}else{
			$this->broadcastSound(new TridentHitBlockSound());
		}
	}

	/**
	 * Channeling: in a thunderstorm, a trident hitting a mob under the open sky (or a lightning rod) calls a lightning
	 * bolt down on it. Returns whether it did.
	 */
	private function channel(int $x, int $y, int $z) : bool{
		if($this->item->getEnchantmentLevel(VanillaEnchantments::CHANNELING()) <= 0){
			return false;
		}
		$world = $this->getWorld();
		$weather = $world->getWeather();
		if(!$weather->isThundering() || !$weather->isExposedToSky($x, $y, $z)){
			return false;
		}
		return LightningBolt::strike(new Location($x + 0.5, $y, $z + 0.5, $world, 0, 0), false, LightningStrikeEvent::CAUSE_TRIDENT) !== null;
	}

	public function getItem() : Item{
		return clone $this->item;
	}

	public function setItem(Item $item) : void{
		if($item->isNull()){
			throw new \InvalidArgumentException("Trident must have a count of at least 1");
		}
		if($this->item->hasEnchantments() !== $item->hasEnchantments()){
			$this->networkPropertiesDirty = true;
		}
		$this->item = clone $item;
	}

	public function canCollideWith(Entity $entity) : bool{
		return $this->canCollide && $entity->getId() !== $this->ownerId && parent::canCollideWith($entity);
	}

	public function onCollideWithPlayer(Player $player) : void{
		if($this->blockHit !== null){
			$this->pickup($player);
		}
	}

	private function pickup(Player $player) : void{
		$shouldDespawn = false;

		$playerInventory = $player->getInventory();
		$ev = new EntityItemPickupEvent($player, $this, $this->getItem(), $playerInventory);
		if($player->hasFiniteResources() && !$playerInventory->canAddItem($ev->getItem())){
			$ev->cancel();
		}
		if($this->spawnedInCreative){
			$ev->cancel();
			$shouldDespawn = true;
		}

		$ev->call();
		if(!$ev->isCancelled()){
			$ev->getInventory()?->addItem($ev->getItem());
			$shouldDespawn = true;
		}

		if($shouldDespawn){
			//even if the item was not actually picked up, the animation must be displayed.
			NetworkBroadcastUtils::broadcastEntityEvent(
				$this->getViewers(),
				fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $player, $this)
			);
			$this->flagForDespawn();
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setGenericFlag(EntityMetadataFlags::ENCHANTED, $this->item->hasEnchantments());
	}
}
