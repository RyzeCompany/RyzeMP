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

namespace pocketmine\entity\monster;

use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\goal\AquaticChargeAttackGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\ai\hostile\goal\FreeWanderGoal;
use pocketmine\entity\aquatic\NautilusTrait;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\riding\MountInventoryHolder;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\MobBucket;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function floor;
use function in_array;
use function mt_rand;

/**
 * Bedrock values (zombie_nautilus.json): 15 health, 0.875x0.95, swims (movement 0.15), attack 3, knockback resistance
 * 0.3, undead, burns in daylight out of water unless it wears nautilus armor (protection_slot slot.armor.body).
 * Neutral: when hurt it gets angry for 20 seconds and charges its attacker (behavior.aquatic_charge_attack, 4 seconds
 * between charges). Without a rider and while calm, a wild one also hunts pufferfish (see
 * {@link NautilusTrait::huntPufferfish()}), which makes it angry at them. Spawned in warm (not deep) oceans it is the
 * coral variant (actor property minecraft:variant).
 * Loot: 0-3 rotten flesh (+0-1 per Looting level), and its equipment. Experience 1-3. Despawns 32 to 40 blocks away.
 *
 * Wild ones carry a drowned (seat 0.925 up, 0.2 back; drowned riders spawn with it, see
 * {@link \pocketmine\entity\riding\Jockeys::spawnDrownedRider()}) and swim it to its target (behavior.mount_pathing,
 * 0.15 blocks per tick). Without a rider and while calm, pufferfish (or their buckets) tame it with a 1/3 chance and
 * tempt it (zombie_nautilus_tameable), and it can be led (zombie_nautilus_leashable, also once tamed); a tamed one
 * never gets angry, is tempted and healed by fish, takes a saddle and nautilus armor and is ridden by players like a
 * nautilus (see {@link NautilusTrait}).
 */
class ZombieNautilus extends AquaticMonster implements Angerable, MountInventoryHolder, Undead{
	use AngerTrait;
	use NautilusTrait;

	public const TAG_OWNER_UUID = "OwnerUUID"; //TAG_String (PocketMine, like tamed animals)
	public const TAG_TAMED = "IsTamed"; //TAG_Byte
	public const TAG_VARIANT = "Variant"; //TAG_Int (0 default, 1 coral)

	public const ATTACK_DAMAGE = 3.0;
	public const ANGER_TICKS = 400;
	public const CHARGE_COOLDOWN = 80;
	public const TAME_CHANCE = 1 / 3;
	/** Swimming speed with a drowned rider, in blocks per tick (zombie_nautilus_wild_mounted). */
	public const MOUNTED_SPEED = 0.15;
	/** aquatic_charge_attack charge_speed 0.96, in blocks per tick. */
	public const CHARGE_SPEED = 0.3;
	/** Knockback of the charge: knockback_force 2.0 (default) x the charge speed factor 0.5 of Java's ChargeAttack, plus the normal 0.4. */
	public const CHARGE_KNOCKBACK = 1.4;
	/** underwater_movement of zombie_nautilus_ai_controlled, in blocks per tick. */
	public const SWIM_SPEED = 0.1;
	/** behavior.tempt speed_multiplier. */
	public const TEMPT_SPEED_MULTIPLIER = 1.3;

	public const VARIANT_DEFAULT = "default";
	public const VARIANT_CORAL = "coral";

	private const TAME_ITEMS = [ItemTypeIds::PUFFERFISH, ItemTypeIds::PUFFERFISH_BUCKET];
	/** Health given back by each food to a tamed zombie nautilus (vanilla healable). */
	private const HEAL_AMOUNTS = [
		ItemTypeIds::PUFFERFISH_BUCKET => 2, ItemTypeIds::COD_BUCKET => 4, ItemTypeIds::SALMON_BUCKET => 4, ItemTypeIds::TROPICAL_FISH_BUCKET => 2,
		ItemTypeIds::RAW_FISH => 4, ItemTypeIds::RAW_SALMON => 4, ItemTypeIds::CLOWNFISH => 2, ItemTypeIds::PUFFERFISH => 2,
		ItemTypeIds::COOKED_FISH => 10, ItemTypeIds::COOKED_SALMON => 12
	];

	private ?UuidInterface $ownerUuid = null;
	private bool $tamed = false;
	private AquaticChargeAttackGoal $chargeGoal;

	public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE_NAUTILUS; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.95, 0.875, 0.5);
	}

	public function getName() : string{
		return "Zombie Nautilus";
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		return $effect === VanillaEffects::POISON() || parent::isImmuneToEffect($effect); //zombie_nautilus.json mob_effect_immunity
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(15);
		$this->setMovementSpeed(0.15);
		parent::initEntity($nbt);
		$this->knockbackResistanceAttr->setValue(0.3);
		$this->tamed = $nbt->getByte(self::TAG_TAMED, 0) !== 0;
		$uuid = $nbt->getString(self::TAG_OWNER_UUID, "");
		if($uuid !== "" && Uuid::isValid($uuid)){
			$this->ownerUuid = Uuid::fromString($uuid);
		}
		$coral = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) === 1 : self::isCoralSpawn($this->location);
		$this->setActorProperty("minecraft:variant", $coral ? self::VARIANT_CORAL : self::VARIANT_DEFAULT);
		$this->initNautilusInventory($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_TAMED, $this->tamed ? 1 : 0);
		if($this->ownerUuid !== null){
			$nbt->setString(self::TAG_OWNER_UUID, $this->ownerUuid->toString());
		}
		$nbt->setInt(self::TAG_VARIANT, $this->isCoral() ? 1 : 0);
		$this->saveNautilusInventory($nbt);
		return $nbt;
	}

	/**
	 * Whether a zombie nautilus spawned here is the coral variant (vanilla entity_spawned: warm, not deep, ocean).
	 */
	public static function isCoralSpawn(Location $location) : bool{
		return $location->getWorld()->getBiomeId((int) floor($location->x), (int) floor($location->y), (int) floor($location->z)) === BiomeIds::WARM_OCEAN;
	}

	public function isCoral() : bool{
		return $this->getActorProperty("minecraft:variant") === 1;
	}

	public function setCoral(bool $coral) : void{
		$this->setActorProperty("minecraft:variant", $coral ? self::VARIANT_CORAL : self::VARIANT_DEFAULT);
	}

	protected function registerGoals() : void{
		$this->chargeGoal = new AquaticChargeAttackGoal(
			$this,
			$this->swimTowards(...),
			$this->stopSwimming(...),
			self::CHARGE_SPEED,
			self::SWIM_SPEED,
			self::ATTACK_DAMAGE,
			self::CHARGE_KNOCKBACK,
			cooldownTicks: self::CHARGE_COOLDOWN,
			condition: fn() => $this->isInWaterBody() && !$this->tamed
		);
		$this->goalSelector->addGoal(2, $this->chargeGoal);
		//zombie_nautilus_tameable: pufferfish; zombie_nautilus_tame: all fish (behavior.tempt priority 2, speed 1.3)
		$this->goalSelector->addGoal(2, new class($this, self::TEMPT_SPEED_MULTIPLIER, $this->isTemptItem(...), onStopTempting: $this->setHomeHere(...)) extends TemptGoal{
			public function canStart() : bool{
				$nautilus = $this->mob;
				return $nautilus instanceof ZombieNautilus && $nautilus->isInWaterBody() && $nautilus->getSteeringPlayer() === null && parent::canStart();
			}

			protected function moveTowards(Player $player) : void{
				$nautilus = $this->mob;
				if($nautilus instanceof ZombieNautilus){
					$loc = $player->getLocation();
					$nautilus->getSwimControl()->setWantedPosition($loc->x, $loc->y + 0.5, $loc->z, ZombieNautilus::SWIM_SPEED * $this->speedMultiplier);
				}
			}

			protected function stopMoving() : void{
				$nautilus = $this->mob;
				if($nautilus instanceof ZombieNautilus){
					$nautilus->getSwimControl()->stop();
				}
			}
		});
		//behavior.mount_pathing: a drowned rider is swum to its target
		$this->goalSelector->addGoal(3, new class($this) extends Goal{
			public function __construct(private ZombieNautilus $nautilus){}

			public function getFlags() : int{
				return self::FLAG_MOVE;
			}

			private function getTarget() : ?Living{
				$rider = $this->nautilus->getControllingPassenger();
				return $rider instanceof Mob ? $rider->getAttackTarget() : null;
			}

			public function canStart() : bool{
				return $this->getTarget() !== null && $this->nautilus->isInWaterBody();
			}

			public function canContinue() : bool{
				return $this->canStart();
			}

			public function tick() : void{
				$target = $this->getTarget();
				if($target === null){
					return;
				}
				$pos = $target->getPosition();
				if($pos->distanceSquared($this->nautilus->getPosition()) <= 4){
					$this->nautilus->getSwimControl()->stop();
				}else{
					$this->nautilus->getSwimControl()->setWantedPosition($pos->x, $pos->y, $pos->z, ZombieNautilus::MOUNTED_SPEED);
				}
			}

			public function stop() : void{
				$this->nautilus->getSwimControl()->stop();
			}
		});
		$this->goalSelector->addGoal(4, new FreeWanderGoal($this, $this->swim, 0.12, 40, 16, 4, FreeWanderGoal::MEDIUM_WATER));

		$this->targetSelector->addGoal(1, new AngerTargetGoal($this, $this->getAngerTarget(...), 32.0));
	}

	public function isBaby() : bool{
		return false;
	}

	/**
	 * Whether a player holding the item tempts it: pufferfish (or their buckets) while it may be tamed, any fish (or
	 * fish bucket) once tamed.
	 */
	public function isTemptItem(Item $item) : bool{
		if($this->tamed){
			return isset(self::HEAL_AMOUNTS[$item->getTypeId()]);
		}
		return in_array($item->getTypeId(), self::TAME_ITEMS, true) && !$this->isAngry() && !$this->hasDrownedRider();
	}

	/**
	 * Wild ones without a rider can be led while calm, tamed ones always (zombie_nautilus_leashable).
	 */
	public function canBeLeashed() : bool{
		return $this->tamed || (!$this->isAngry() && !$this->hasDrownedRider());
	}

	protected function onAngerChange(bool $angry) : void{
		if($angry && $this->isLeashed()){
			//become_angry removes zombie_nautilus_leashable
			$this->unleash(true);
		}
	}

	protected function canHuntPufferfish() : bool{
		return !$this->tamed && !$this->isAngry() && !$this->hasDrownedRider() && $this->isInWaterBody();
	}

	protected function onPreyFound(Living $prey) : void{
		$this->setAngryAt($prey, self::ANGER_TICKS);
	}

	public function isTamed() : bool{
		return $this->tamed;
	}

	public function getOwnerUuid() : ?UuidInterface{
		return $this->ownerUuid;
	}

	/**
	 * Tames it for the player (no chance roll), or makes it wild again (null). A tamed one forgets its anger.
	 */
	public function setOwner(?Player $owner) : void{
		$this->tamed = $owner !== null;
		$this->ownerUuid = $owner?->getUniqueId();
		if($this->tamed){
			$this->persistent = true;
			$this->setAngryAt(null, 0);
			$this->setHomeHere(); //on_tame: set_home_position
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Whether a drowned rides it (vanilla zombie_nautilus_wild_mounted): it can't be tamed then.
	 */
	public function hasDrownedRider() : bool{
		return $this->getControllingPassenger() instanceof Drowned;
	}

	public function breathesAir() : bool{
		return true;
	}

	public function burnsInDaylight() : bool{
		return $this->getNautilusArmor()->isNull();
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && !$this->tamed && $source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Living && (!$damager instanceof Player || !$damager->isCreative())){
				$this->setAngryAt($damager, self::ANGER_TICKS);
			}
		}
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->tamed){
			if(in_array($item->getTypeId(), self::TAME_ITEMS, true) && !$this->isAngry() && !$this->hasDrownedRider()){
				$this->consumeFish($player, $item);
				if(Utils::getRandomFloat() < self::TAME_CHANCE){
					$this->setOwner($player);
					$this->broadcastActorEvent(ActorEvent::TAME_SUCCESS);
				}else{
					$this->broadcastActorEvent(ActorEvent::TAME_FAIL);
				}
				return true;
			}
			return false;
		}
		if($this->interactWithEquipment($player, $item)){
			return true;
		}
		if(isset(self::HEAL_AMOUNTS[$item->getTypeId()]) && $this->getHealth() < $this->getMaxHealth()){
			$this->consumeFish($player, $item);
			$this->heal(new EntityRegainHealthEvent($this, self::HEAL_AMOUNTS[$item->getTypeId()], EntityRegainHealthEvent::CAUSE_EATING));
			return true;
		}
		return $this->tryRide($player);
	}

	private function consumeFish(Player $player, Item $item) : void{
		if($item instanceof MobBucket){
			$this->exchangeHeldItem($player, VanillaItems::WATER_BUCKET());
		}else{
			$this->consumeHeldItem($player);
		}
	}

	private function broadcastActorEvent(int $event) : void{
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, $event, 0, null)]);
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		return $this->getEquipmentButtonText($player, $item);
	}

	public function getRideableSeats() : array{
		return $this->tamed ? [$this->getPlayerSeat()] : [RideableSeat::at(0, 0.925, -0.2)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $this->tamed ? $entity instanceof Player : $entity instanceof Drowned;
	}

	public function isInWaterForRiding() : bool{
		return $this->isInWaterBody();
	}

	protected function swimTowards(float $x, float $y, float $z, float $speed) : void{
		$this->swim->setWantedPosition($x, $y, $z, $speed);
	}

	protected function stopSwimming() : void{
		$this->swim->stop();
	}

	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		parent::handleRiderInput($rider, $input);
		$this->handleDashInput($rider, $input);
	}

	protected function tryChangeMovement() : void{
		if($this->isMovedByClient()){
			//the client of the rider moves it (see handleClientVehicleMovement())
			$this->motion = Vector3::zero();
			return;
		}
		if($this->isAlive() && !$this->isInWaterBody() && $this->getSteeringPlayer() !== null){
			//driven on land by its rider instead of flopping around
			$this->setHasGravity(true);
			Mob::tryChangeMovement();
			return;
		}
		parent::tryChangeMovement();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			$this->tickAnger($tickDiff);
			$this->tickNautilus($tickDiff);
			$this->huntPufferfish();
			//ridden, it answers the rider's input faster than its own floaty swimming, and it dashes when it charges
			$this->swim->setAcceleration($this->getSteeringPlayer() !== null || $this->chargeGoal->isCharging() ? 0.3 : 0.1);
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		return [VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 3) + $this->lootingBonus()), ...$this->getEquipmentDrops()];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ZOMBIE_NAUTILUS_SPAWN_EGG();
	}

	protected function onDeath() : void{
		$this->closeNautilusInventory();
		parent::onDeath();
	}

	protected function onDispose() : void{
		$this->closeNautilusInventory();
		parent::onDispose();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry());
		$properties->setGenericFlag(EntityMetadataFlags::TAMED, $this->tamed);
		$this->syncNautilusData($properties);
	}
}
