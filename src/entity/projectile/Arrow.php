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
use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\entity\animation\ArrowShakeAnimation;
use pocketmine\entity\effect\InstantEffect;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\TippedArrow;
use pocketmine\item\VanillaItems;
use pocketmine\math\RayTraceResult;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\sound\ArrowHitSound;
use function ceil;
use function count;
use function mt_rand;
use function sqrt;

class Arrow extends Projectile{

	public static function getNetworkTypeId() : string{ return EntityIds::ARROW; }

	public const PICKUP_NONE = 0;
	public const PICKUP_ANY = 1;
	public const PICKUP_CREATIVE = 2;

	private const TAG_PICKUP = "pickup"; //TAG_Byte
	public const TAG_CRIT = "crit"; //TAG_Byte
	private const TAG_LIFE = "life"; //TAG_Short
	/** Potion of a tipped arrow: potion ID + 1, or 0 for a normal arrow (same as the item meta) */
	private const TAG_AUX_VALUE = "auxValue"; //TAG_Byte

	protected float $damage = 2.0;
	protected int $pickupMode = self::PICKUP_ANY;
	protected float $punchKnockback = 0.0;
	protected int $collideTicks = 0;
	protected bool $critical = false;
	protected ?PotionType $potionType = null;
	/** Piercing level: the arrow goes through this many mobs and stops in the next one. */
	private int $pierceLevel = 0;
	/** @var true[] IDs of the mobs the arrow already went through */
	private array $piercedIds = [];

	public function __construct(Location $location, ?Entity $shootingEntity, bool $critical, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setCritical($critical);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.01; }

	protected function getInitialGravity() : float{ return 0.05; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->pickupMode = $nbt->getByte(self::TAG_PICKUP, self::PICKUP_ANY);
		$this->critical = $nbt->getByte(self::TAG_CRIT, 0) === 1;
		$this->collideTicks = $nbt->getShort(self::TAG_LIFE, $this->collideTicks);
		$auxTag = $nbt->getTag(self::TAG_AUX_VALUE);
		if($auxTag instanceof ByteTag || $auxTag instanceof ShortTag || $auxTag instanceof IntTag){
			$aux = $auxTag->getValue();
			$this->potionType = $aux > 0 ? PotionTypeIdMap::getInstance()->fromId($aux - 1) : null;
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_PICKUP, $this->pickupMode);
		$nbt->setByte(self::TAG_CRIT, $this->critical ? 1 : 0);
		$nbt->setShort(self::TAG_LIFE, $this->collideTicks);
		$nbt->setByte(self::TAG_AUX_VALUE, $this->getAuxValue());
		return $nbt;
	}

	public function isCritical() : bool{
		return $this->critical;
	}

	public function setCritical(bool $value = true) : void{
		$this->critical = $value;
		$this->networkPropertiesDirty = true;
	}

	public function getResultDamage() : int{
		$base = (int) ceil($this->motion->length() * parent::getResultDamage());
		if($this->isCritical()){
			return ($base + mt_rand(0, (int) ($base / 2) + 1));
		}else{
			return $base;
		}
	}

	/**
	 * Bedrock (arrow.json impact_damage: speed x 2 rounded up before the critical part): a critical arrow (a full bow
	 * shot, every crossbow bolt) adds a random amount up to half that plus 2, not a whole number. BDS 1.26.60 at 6
	 * blocks: bow 6.3-10.9, crossbow 8.3-12.1.
	 */
	protected function getHitDamage() : float{
		$base = ceil($this->motion->length() * parent::getResultDamage());
		if($this->isCritical()){
			return $base + Utils::getRandomFloat() * ($base / 2 + 2);
		}
		return $base;
	}

	/**
	 * Returns the potion of a tipped arrow, or null for a normal arrow.
	 */
	public function getPotionType() : ?PotionType{
		return $this->potionType;
	}

	/**
	 * Sets the potion of a tipped arrow (null for a normal arrow). Its effects are applied to the mob hit, with 1/8 of
	 * the potion's duration (see {@link TippedArrow::getArrowEffects()}).
	 */
	public function setPotionType(?PotionType $potionType) : void{
		$this->potionType = $potionType;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns the potion ID + 1 of a tipped arrow, or 0 for a normal arrow.
	 */
	private function getAuxValue() : int{
		return $this->potionType !== null ? PotionTypeIdMap::getInstance()->toId($this->potionType) + 1 : 0;
	}

	/**
	 * Returns the item given back when a player picks up this arrow.
	 */
	public function getPickupItem() : Item{
		return $this->potionType !== null ? VanillaItems::TIPPED_ARROW()->setType($this->potionType) : VanillaItems::ARROW();
	}

	public function getPunchKnockback() : float{
		return $this->punchKnockback;
	}

	public function setPunchKnockback(float $punchKnockback) : void{
		$this->punchKnockback = $punchKnockback;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->blockHit !== null){
			$this->collideTicks += $tickDiff;
			if($this->collideTicks > 1200){
				$this->flagForDespawn();
				$hasUpdate = true;
			}
		}else{
			$this->collideTicks = 0;
		}

		return $hasUpdate;
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		$this->broadcastSound(new ArrowHitSound());
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		//only a block hit ends the critical flight: onHit() runs before onHitEntity() works out the damage, so
		//clearing it there made every arrow hit non-critical
		$this->setCritical(false);
		parent::onHitBlock($blockHit, $hitResult);
		$this->broadcastAnimation(new ArrowShakeAnimation($this, 7));
	}

	public function getPierceLevel() : int{
		return $this->pierceLevel;
	}

	/**
	 * Sets the Piercing level (crossbows): the arrow passes through that many mobs, hitting each once.
	 */
	public function setPierceLevel(int $level) : void{
		$this->pierceLevel = $level;
	}

	public function canCollideWith(Entity $entity) : bool{
		return !isset($this->piercedIds[$entity->getId()]) && parent::canCollideWith($entity);
	}

	protected function despawnsOnEntityHit() : bool{
		return count($this->piercedIds) > $this->pierceLevel;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$this->piercedIds[$entityHit->getId()] = true;
		$lastDamageCause = $entityHit->getLastDamageCause();
		parent::onHitEntity($entityHit, $hitResult);
		if($this->potionType !== null && $entityHit instanceof Living && $entityHit->isAlive() && $entityHit->getLastDamageCause() !== $lastDamageCause){
			//the arrow hurt the mob (the damage wasn't cancelled)
			foreach(TippedArrow::getArrowEffects($this->potionType) as $effect){
				$type = $effect->getType();
				if($type instanceof InstantEffect){
					$type->applyEffect($entityHit, $effect, 1.0, $this);
				}else{
					$entityHit->getEffects()->add($effect);
				}
			}
		}
		if($this->punchKnockback > 0){
			$horizontalSpeed = sqrt($this->motion->x ** 2 + $this->motion->z ** 2);
			if($horizontalSpeed > 0){
				$multiplier = $this->punchKnockback * 0.6 / $horizontalSpeed;
				$entityHit->setMotion($entityHit->getMotion()->add($this->motion->x * $multiplier, 0.1, $this->motion->z * $multiplier));
			}
		}
		if(count($this->piercedIds) <= $this->pierceLevel){
			//keep flying through the mob (Projectile::move() stops projectiles whose motion the hit left unchanged)
			$this->motion = $this->motion->multiply(0.99);
		}
	}

	public function getPickupMode() : int{
		return $this->pickupMode;
	}

	public function setPickupMode(int $pickupMode) : void{
		$this->pickupMode = $pickupMode;
	}

	public function onCollideWithPlayer(Player $player) : void{
		if($this->blockHit === null){
			return;
		}

		$item = $this->getPickupItem();
		$playerInventory = match(true){
			!$player->hasFiniteResources() => null, //arrows are not picked up in creative
			$player->getOffHandInventory()->getItem(0)->canStackWith($item) && $player->getOffHandInventory()->canAddItem($item) => $player->getOffHandInventory(),
			$player->getInventory()->canAddItem($item) => $player->getInventory(),
			default => null
		};

		$ev = new EntityItemPickupEvent($player, $this, $item, $playerInventory);
		if($player->hasFiniteResources() && $playerInventory === null){
			$ev->cancel();
		}
		if($this->pickupMode === self::PICKUP_NONE || ($this->pickupMode === self::PICKUP_CREATIVE && !$player->isCreative())){
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

		$ev->getInventory()?->addItem($ev->getItem());
		$this->flagForDespawn();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setGenericFlag(EntityMetadataFlags::CRITICAL, $this->critical);
		//CUSTOM_DISPLAY (18, shared with minecarts): potion ID + 1, gives a tipped arrow its particles (as PowerNukkitX)
		$properties->setByte(EntityMetadataProperties::MINECART_HAS_DISPLAY, $this->getAuxValue());
	}
}
