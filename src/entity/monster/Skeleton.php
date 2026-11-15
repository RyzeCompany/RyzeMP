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

use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FleeSunGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\RangedAttackGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\item\Bow;
use pocketmine\item\Crossbow;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function mt_rand;

/**
 * Bedrock values: 20 health, movement 0.25, shoots an arrow every 3 seconds (2 on hard) within 15 blocks, strafing
 * around its target, burns in daylight unless it wears a helmet.
 * Spawn equipment (skeleton_gear.json, also stray, bogged and parched): a bow (10% enchanted x regional difficulty;
 * Power, Punch and Flame apply to its arrows) and, on Normal and Hard, maybe armor. Equipment drops with an 8.5% chance
 * (+1% per Looting level) when killed by a player; XP 5 + 1-3 per equipped item.
 * Like zombies, some pick up items within 3 blocks (see {@link MobShareables::skeleton()}): anything but spears and the
 * ominous banner, a sword in place of their bow, better armor.
 * Melee mode (environment_sensor, also stray, bogged and parched): underwater or without a bow or crossbow it fights
 * with melee_box_attack (speed 1.25, 2 damage; stray 3 and Slowness, bogged 3), back to shooting once out of the water
 * with a ranged weapon.
 * Targets players, iron golems and baby turtles out of water, and runs from wolves within 6 blocks.
 * Freezing immune; 20 seconds in powder snow (shaking) turn it into a stray with its equipment (not the stray, bogged
 * and parched).
 * Loot: 0-2 arrows, 0-2 bones, +0-1 each per Looting level.
 */
class Skeleton extends Monster{
	use HeldItemsTrait;

	public const ATTACK_RANGE = 15.0;
	/** Ticks in powder snow before a skeleton turns into a stray (skeleton.json in_powder_snow timer: 20 seconds). */
	public const STRAY_CONVERSION_TICKS = 400;

	/** Ticks between two checks of the attack mode (environment sensor). */
	private const ATTACK_MODE_CHECK_TICKS = 5;

	/** Whether it fights in melee (see the class doc), worked out every few ticks. */
	private bool $meleeMode = false;
	private int $attackModeTick = -100;
	/** Age (ticksLived) at which it got into powder snow, -1 while out of it. */
	private int $powderSnowSince = -1;
	/** Last age (ticksLived) at which it was in powder snow. */
	private int $lastPowderSnowTick = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::SKELETON; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.99, 0.6, 1.74);
	}

	public function getName() : string{
		return "Skeleton";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$this->loadHeldItems($nbt);
		if(MobEquipment::isFreshSpawn($nbt)){
			$this->equipFromTable("skeleton_gear");
		}elseif($nbt->getTag("Mainhand") === null){
			$this->setMainHandItem(VanillaItems::BOW()); //saved before skeletons held real items
		}
		//behavior.pickup_items priority 5 (parched 6)
		$this->initItemPickup($nbt, $this instanceof Bogged || $this instanceof Parched ? MobShareables::bogged() : MobShareables::skeleton(), $this instanceof Parched ? 6 : 5);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveHeldItems($nbt);
		return $nbt;
	}

	/**
	 * Skeletons don't drown (breathable breathes_water): they sink and walk on the bottom (no behavior.float).
	 */
	public function canBreathe() : bool{
		return true;
	}

	/**
	 * Skeletons and strays are freezing_immune; bogged and parched freeze.
	 */
	public function canFreeze() : bool{
		return $this instanceof TippedArrowSkeleton && parent::canFreeze();
	}

	/**
	 * Ticks between two shots (normal, hard).
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	protected function getAttackIntervals() : array{
		return [60, 40];
	}

	protected function registerGoals() : void{
		[$interval, $hardInterval] = $this->getAttackIntervals();
		$melee = $this->isMeleeMode(...);
		$this->goalSelector->addGoal(0, new class($melee, $this, $interval, $hardInterval, $this->shootArrow(...)) extends RangedAttackGoal{
			/**
			 * @phpstan-param \Closure() : bool $melee
			 * @phpstan-param \Closure(Living $target, float $distance) : void $shoot
			 */
			public function __construct(private \Closure $melee, Mob $mob, int $interval, int $hardInterval, \Closure $shoot){
				parent::__construct($mob, 1.0, $interval, $hardInterval, Skeleton::ATTACK_RANGE, $shoot);
			}

			public function canStart() : bool{
				//the attack mode is only worked out with a target (it looks at the blocks around the mob)
				return $this->mob->getAttackTarget() !== null && !($this->melee)() && parent::canStart();
			}
		});
		//flee_sun priority 2 (parched 3)
		$this->goalSelector->addGoal($this instanceof Parched ? 3 : 2, new FleeSunGoal($this, 1.0));
		//avoid_mob_type wolf within 6 blocks, walk and sprint x1.2, priority 4 (parched 5); looked for only while a wolf
		//is within a few blocks more (checked once a second)
		$wolves = new NearbyEntityCheck($this, [Wolf::class], fn(Entity $e) : bool => true, 6.0 + MonsterTargets::THREAT_MARGIN, 3.0 + MonsterTargets::THREAT_MARGIN);
		$this->goalSelector->addGoal($this instanceof Parched ? 5 : 4, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Wolf, 6.0, 1.2, 1.2, $wolves->check(...)));
		//melee_box_attack priority 4, speed 1.25, in melee mode
		$this->goalSelector->addGoal(4, new class($melee, $this, $this->getMeleeDamage()) extends MeleeAttackGoal{
			/**
			 * @phpstan-param \Closure() : bool $melee
			 */
			public function __construct(private \Closure $melee, Mob $mob, float $damage){
				parent::__construct($mob, 1.25, $damage, Skeleton::ATTACK_RANGE + 16);
			}

			public function canStart() : bool{
				return $this->mob->getAttackTarget() !== null && ($this->melee)() && parent::canStart();
			}
		});
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//players, iron golems and baby turtles out of water (must_see, reselect_targets); players only while neither is
		//within range
		$nearby = MonsterTargets::nearbyTargets($this, [IronGolem::class, Turtle::class], MonsterTargets::isSkeletonTarget(...), 16.0);
		$this->targetSelector->addGoal(2, MonsterTargets::extraTargetGoal($this, MonsterTargets::isSkeletonTarget(...), $nearby, null, 16.0, true, 10, true));
		$this->targetSelector->addGoal(2, MonsterTargets::playerTargetGoal($this, $nearby, null, 16.0, true, 10, true));
	}

	/**
	 * Melee damage (minecraft:attack of the melee_attack group): skeleton and parched 2, stray and bogged 3.
	 */
	private function getMeleeDamage() : float{
		return $this instanceof Stray || $this instanceof Bogged ? 3.0 : 2.0;
	}

	/**
	 * Whether it holds a ranged weapon (Bedrock filter has_ranged_weapon).
	 */
	private function hasRangedWeapon() : bool{
		$item = $this->getMainHandItem();
		return $item instanceof Bow || $item instanceof Crossbow;
	}

	/**
	 * Bedrock environment_sensor: melee mode when underwater or without a ranged weapon, ranged mode again once out of
	 * the water with one. Worked out every {@link Skeleton::ATTACK_MODE_CHECK_TICKS} ticks, only by the attack goals.
	 */
	private function isMeleeMode() : bool{
		$tick = $this->ticksLived;
		if($tick - $this->attackModeTick >= self::ATTACK_MODE_CHECK_TICKS || $tick < $this->attackModeTick){
			$this->attackModeTick = $tick;
			$this->meleeMode = !$this->hasRangedWeapon() || $this->isUnderwater() || ($this->meleeMode && $this->isInWater());
		}
		return $this->meleeMode;
	}

	public function burnsInDaylight() : bool{
		return true;
	}

	/**
	 * Shoots an arrow at the target, aiming slightly above it to compensate for gravity (vanilla), with the
	 * enchantments of the bow it holds.
	 */
	public function shootArrow(Living $target, float $distance) : void{
		if(Aim::shootArrow($this, $target, null, 1.6, $this->getMainHandItem()) !== null){
			$this->playMobSound(LevelSoundEvent::BOW);
		}
	}

	/**
	 * Returns whether it is shaking in powder snow, about to turn into a stray.
	 */
	public function isConvertingToStray() : bool{
		return $this->powderSnowSince >= 0;
	}

	public function setInPowderSnow() : void{
		parent::setInPowderSnow();
		if(!$this instanceof TippedArrowSkeleton){
			//called every tick while inside powder snow: the conversion timer only runs then
			$tick = $this->ticksLived;
			$this->lastPowderSnowTick = $tick;
			if($this->powderSnowSince < 0){
				$this->powderSnowSince = $tick;
				$this->networkPropertiesDirty = true;
			}
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->powderSnowSince >= 0 && !$this->closed){
			$tick = $this->ticksLived;
			if(!$this->isAlive() || $this->lastPowderSnowTick < $tick - $tickDiff){
				//out of the powder snow: the timer starts over next time
				$this->powderSnowSince = -1;
				$this->networkPropertiesDirty = true;
			}elseif($tick - $this->powderSnowSince >= self::STRAY_CONVERSION_TICKS){
				$this->convertToStray();
			}
			$hasUpdate = true;
		}
		return $hasUpdate;
	}

	/**
	 * Bedrock become_stray transformation: keep_level, preserve_equipment, sound convert_to_stray.
	 */
	private function convertToStray() : void{
		$this->powderSnowSince = -1;
		$stray = new Stray($this->location->asLocation(), MobEquipment::getConversionData($this));
		$stray->setMainHandItem($this->getMainHandItem());
		$stray->setOffHandItem($this->getOffHandItem());
		$stray->setHealth($this->getHealth());
		MobConversion::copyState($this, $stray);
		$stray->spawnToAll();
		$stray->playMobSound(LevelSoundEvent::CONVERT_TO_STRAY);
		$this->flagForDespawn();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		if(!$this instanceof TippedArrowSkeleton){
			//flag 40 is ActorFlags::SHAKING (skeleton.json is_shaking in powder snow), named VIBRATING in BedrockProtocol
			$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->powderSnowSince >= 0);
		}
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::ARROW()->setCount(mt_rand(0, 2) + $this->lootingBonus()),
			VanillaItems::BONE()->setCount(mt_rand(0, 2) + $this->lootingBonus()),
			...$this->getEquipmentDrops()
		];
		$head = ChargedCreeperHeads::getHeadDrop($this);
		if($head !== null){
			$drops[] = $head;
		}
		return $drops;
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp(5); //vanilla: 5 + equipment count * (1-3)
	}
}
