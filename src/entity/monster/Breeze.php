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

use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\ai\hostile\goal\BreezeAttackGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\BreezeWindCharge;
use pocketmine\entity\projectile\DragonFireball;
use pocketmine\entity\projectile\Egg;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\projectile\ExperienceBottle;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\entity\projectile\LargeFireball;
use pocketmine\entity\projectile\LlamaSpit;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\ShulkerBullet;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\entity\projectile\Trident;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\entity\projectile\WitherSkull;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function cos;
use function deg2rad;
use function max;
use function mt_getrandmax;
use function mt_rand;
use function sin;

/**
 * Bedrock values (breeze.json): 30 health, 0.6x1.77, movement 0.4, follow range 32, no fall damage, persistent. Only
 * wind charges hurt it among projectiles, and it sends most others back (minecraft:reflect_projectiles, see
 * {@link Breeze::reflectProjectile()}). Attacks players and iron golems it sees within 24 blocks. Leaps around its
 * target and shoots wind charges at it (see {@link BreezeAttackGoal} and {@link BreezeWindCharge}).
 * Clients animate the shot from the FACING_TARGET_TO_RANGE_ATTACK flag (set from the wind-up to a few ticks after the
 * shot) and the leap from the JUMP_GOAL_JUMP flag (set while preparing the leap and until the landing), like BDS 1.26.52
 * sends them; the jump and land sounds are sent like BDS does.
 * Loot: 1-2 breeze rods (+1-2 per Looting level) when killed by a player. Experience 10.
 */
class Breeze extends Monster{
	public const FOLLOW_RANGE = 32.0;
	public const WIND_CHARGE_SPEED = 0.7;
	/** Reflected projectiles keep this fraction of their speed (reflect_projectiles reflection_scale). */
	public const REFLECTION_SCALE = 0.5;
	/** Reflected projectiles turn by 180 degrees plus or minus this (reflect_projectiles azimuth_angle). */
	public const REFLECTION_SPREAD_DEGREES = 20.0;

	private bool $chargingShot = false;
	private bool $preparingJump = false;
	/** Ticks since the leap started, -1 when not leaping. */
	private int $leapTicks = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::BREEZE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.77, 0.6, 1.3);
	}

	public function getName() : string{
		return "Breeze";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(30);
		$this->setMovementSpeed(0.4);
		$this->setFollowRange(self::FOLLOW_RANGE);
		parent::initEntity($nbt);
		//minecraft:persistent: breezes never despawn
		$this->setPersistent(true);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(2, new BreezeAttackGoal($this));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 16.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));

		//(only players and iron golems are looked at, and nothing while the world has none: the 48-block box holds the
		//whole trial chamber)
		$this->targetSelector->addGoal(1, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Player || $e instanceof IronGolem, 24.0, true, 10, verticalRange: 24.0, candidateClasses: [Player::class, IronGolem::class]));
		$this->targetSelector->addGoal(4, new class($this, self::FOLLOW_RANGE) extends HurtByTargetGoal{
			public function canStart() : bool{
				$attacker = $this->mob->getLastAttacker();
				return ($attacker === null || !Breeze::ignoresAttacker($attacker)) && parent::canStart();
			}
		});
	}

	/**
	 * Returns whether breezes don't retaliate against this attacker (hurt_by_target filters: the skeleton, stray,
	 * zombie, husk, spider, cave spider and slime families, their trial chamber neighbours).
	 *
	 * @internal
	 */
	public static function ignoresAttacker(Living $attacker) : bool{
		return $attacker instanceof Skeleton || $attacker instanceof WitherSkeleton || $attacker instanceof Spider ||
			($attacker instanceof Zombie && !$attacker instanceof ZombifiedPiglin) ||
			($attacker instanceof Slime && !$attacker instanceof MagmaCube);
	}

	/**
	 * Returns whether breezes send this projectile back (reflect_projectiles reflected_projectiles: not wind charges).
	 */
	public static function reflects(Projectile $projectile) : bool{
		return $projectile instanceof Arrow || $projectile instanceof Trident || $projectile instanceof Snowball ||
			$projectile instanceof Egg || $projectile instanceof SplashPotion || $projectile instanceof EnderPearl ||
			$projectile instanceof ExperienceBottle || $projectile instanceof LargeFireball || $projectile instanceof SmallFireball ||
			$projectile instanceof DragonFireball || $projectile instanceof WitherSkull || $projectile instanceof ShulkerBullet ||
			$projectile instanceof LlamaSpit || $projectile instanceof FishingHook;
	}

	/**
	 * Sends the projectile back (minecraft:reflect_projectiles): it turns by 180 +- 20 degrees around the vertical axis
	 * and keeps half of its speed, without hitting the breeze.
	 *
	 * @internal
	 */
	public function reflectProjectile(Projectile $projectile) : bool{
		if(!self::reflects($projectile) || !$this->isAlive()){
			return false;
		}
		$angle = deg2rad(180.0 + (mt_rand() / mt_getrandmax() * 2 - 1) * self::REFLECTION_SPREAD_DEGREES);
		if(!$projectile->setMotion(self::reflectedMotion($projectile->getMotion(), $angle))){
			return false;
		}
		$this->playMobSound(LevelSoundEvent::REFLECT);
		return true;
	}

	/**
	 * Returns the motion of a projectile reflected with the given horizontal turn (radians).
	 */
	public static function reflectedMotion(Vector3 $motion, float $angle) : Vector3{
		$cos = cos($angle);
		$sin = sin($angle);
		return new Vector3(
			($motion->x * $cos - $motion->z * $sin) * self::REFLECTION_SCALE,
			$motion->y * self::REFLECTION_SCALE,
			($motion->x * $sin + $motion->z * $cos) * self::REFLECTION_SCALE
		);
	}

	/**
	 * Returns the per-axis aim spread of wind charges (breeze_wind_charge_projectile.json: uncertainty_base 5 minus
	 * uncertainty_multiplier 4 times the difficulty, applied like the shooter's 0.0075 gaussian): a little on easy,
	 * perfect aim on normal and hard.
	 */
	public static function windChargeInaccuracy(int $difficulty) : float{
		return max(0.0, 5.0 - 4.0 * $difficulty) * 0.0075;
	}

	public function shootWindCharge(Living $target) : void{
		$world = $this->getWorld();
		$origin = $this->location->add(0, $this->size->getHeight() - 0.3, 0);
		$motion = Aim::motionTowards($origin, $target->getLocation()->add(0, 0.5, 0), self::WIND_CHARGE_SPEED, self::windChargeInaccuracy($world->getDifficulty()));
		if($motion === null){
			return;
		}
		$charge = new BreezeWindCharge(Aim::facing($origin, $world, $motion), $this);
		$charge->setMotion($motion);
		$charge->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
	}

	public function onJump() : void{
		$this->playMobSound(LevelSoundEvent::JUMP);
		//airborne from the take-off (no ground friction on the leap, like vanilla's discarded friction)
		$this->onGround = false;
		if($this->leapTicks < 0){
			$this->networkPropertiesDirty = true;
		}
		$this->leapTicks = 0;
	}

	/**
	 * Returns whether the breeze is leaping (from the take-off to the landing).
	 */
	public function isLeaping() : bool{
		return $this->leapTicks >= 0;
	}

	/**
	 * Returns whether the breeze is getting ready to leap (jump_around_target prepare_jump_duration).
	 */
	public function isPreparingJump() : bool{
		return $this->preparingJump;
	}

	public function setPreparingJump(bool $preparing) : void{
		if($preparing !== $this->preparingJump){
			$this->preparingJump = $preparing;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Returns whether the breeze is winding up or finishing a shot (the client plays the shooting animation).
	 */
	public function isChargingShot() : bool{
		return $this->chargingShot;
	}

	public function setChargingShot(bool $charging) : void{
		if($charging !== $this->chargingShot){
			$this->chargingShot = $charging;
			$this->networkPropertiesDirty = true;
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->leapTicks >= 0){
			$this->leapTicks += $tickDiff;
			if($this->leapTicks > 2 && ($this->onGround || ($this->leapTicks > 4 && $this->isInWater()))){
				$this->leapTicks = -1;
				$this->networkPropertiesDirty = true;
				$this->playMobSound(LevelSoundEvent::LAND);
			}
		}
		return $hasUpdate;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::FACING_TARGET_TO_RANGE_ATTACK, $this->chargingShot);
		$properties->setGenericFlag(EntityMetadataFlags::JUMP_GOAL_JUMP, $this->preparingJump || $this->leapTicks >= 0);
	}

	public function attack(EntityDamageEvent $source) : void{
		if(EntityDamageEvent::isProjectileCause($source->getCause()) && !($source instanceof EntityDamageByChildEntityEvent && $source->getChild() instanceof WindCharge)){
			//projectiles other than wind charges don't hurt breezes
			$source->cancel();
		}
		parent::attack($source);
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0;
	}

	public function getDrops() : array{
		if(!$this->wasRecentlyHitByPlayer()){
			return [];
		}
		$looting = $this->getLootingLevel();
		return [VanillaItems::BREEZE_ROD()->setCount(mt_rand(1, 2) + ($looting > 0 ? mt_rand($looting, 2 * $looting) : 0))];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 10 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::BREEZE_SPAWN_EGG();
	}
}
