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

use pocketmine\entity\ai\goal\FleeSunGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\RangedAttackGoal;
use pocketmine\entity\ai\goal\StompTurtleEggGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\ai\hostile\FreeMoveControl;
use pocketmine\entity\ai\hostile\goal\FreeMeleeAttackGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\projectile\MobTrident;
use pocketmine\entity\Villager;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\World;
use function max;
use function mt_rand;
use function sqrt;

/**
 * Bedrock values (drowned.json): 20 health, movement 0.23, attack 3, swims in water, burns in daylight, breathes
 * underwater. 6.25% spawn with a trident (25% enchanted x regional difficulty) and throw it (every 1-3 seconds, within
 * 10 blocks), switching to melee when the target comes within 3 blocks and back beyond 5; the others hold a fishing
 * rod 3.75% of the time; 8% have a nautilus shell in the off hand (always dropped). Drowned riding a zombie nautilus
 * hold a plain trident and always throw it.
 * Targets players, snow and iron golems, axolotls, villagers and wandering traders (villagers even unseen) in water or
 * at night within 20 blocks, and baby turtles out of the water. Its hits push targets in water down instead of up
 * (apply_knockback_rules: 0.6 sideways, -0.4 vertically). Stomps on turtle eggs like zombies.
 * Picks up items within 3 blocks like zombies (see {@link MobShareables::drowned()}): a trident first, then a nautilus
 * shell, swords and armor; never glow ink sacs nor spears.
 * Loot: 0-2 rotten flesh (+0-1 per Looting level); 11% (+2% per Looting level) copper ingot when killed by a player.
 */
class Drowned extends Zombie{
	public const TRIDENT_RANGE = 10.0;
	/** underwater_movement (blocks per tick in water), adult and baby (drowned_baby). */
	public const SWIM_SPEED = 0.06;
	public const BABY_SWIM_SPEED = 0.08;
	public const MELEE_MODE_RANGE = 3.0;
	public const RANGED_MODE_RANGE = 5.0;
	public const TARGET_RANGE = 20.0;

	/** apply_knockback_rules on targets immersed in water: horizontal_power, vertical_power, vertical_velocity_cap */
	private const WATER_KNOCKBACK_HORIZONTAL = 0.6;
	private const WATER_KNOCKBACK_VERTICAL = -0.6;
	private const WATER_KNOCKBACK_VERTICAL_CAP = -0.4;

	private FreeMoveControl $swim;
	private ?FreeMeleeAttackGoal $swimAttack = null;
	private bool $rangedMode = true;

	public static function getNetworkTypeId() : string{ return EntityIds::DROWNED; }

	public function getName() : string{
		return "Drowned";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->swim = new FreeMoveControl($this, 0.15);
		parent::initEntity($nbt);
	}

	/**
	 * Vanilla entity_spawned: 25 in 400 are ranged drowned (drowned_ranged_equipment: a trident, maybe enchanted, and
	 * 8% a nautilus shell), the others melee drowned (drowned_equipment: 3.75% a fishing rod, 8% a nautilus shell).
	 */
	protected function pickSpawnEquipment() : void{
		$this->equipFromTable(mt_rand(0, 399) < 25 ? "drowned_ranged_equipment" : "drowned_equipment");
	}

	/**
	 * Vanilla as_rider (drowned_rider): the melee or ranged equipment is replaced by a plain trident
	 * (drowned_rider_equipment), and it always throws it.
	 */
	public function equipAsRider() : void{
		$this->equipFromTable("drowned_rider_equipment");
	}

	public function getSwimControl() : FreeMoveControl{
		return $this->swim;
	}

	/**
	 * Blocks per tick when it swims after its target (underwater_movement: 0.06, babies 0.08).
	 */
	public function getSwimSpeed() : float{
		return $this->isBaby() ? self::BABY_SWIM_SPEED : self::SWIM_SPEED;
	}

	public function setBaby(bool $baby) : void{
		parent::setBaby($baby);
		$this->swimAttack?->setSpeed($this->getSwimSpeed());
	}

	/**
	 * Whether a drowned with a trident throws it rather than fighting in melee (vanilla mode_switcher: melee once the
	 * target is within 3 blocks, ranged again beyond 5). A riding drowned always throws (as_rider removes the mode
	 * switcher and the melee mode).
	 */
	public function isInRangedMode(Living $target) : bool{
		if($this->getVehicle() !== null){
			return true;
		}
		$distSq = $target->getLocation()->distanceSquared($this->location);
		if($distSq < self::MELEE_MODE_RANGE * self::MELEE_MODE_RANGE){
			$this->rangedMode = false;
		}elseif($distSq > self::RANGED_MODE_RANGE * self::RANGED_MODE_RANGE){
			$this->rangedMode = true;
		}
		return $this->rangedMode;
	}

	protected function registerGoals() : void{
		//ranged_mode: attack_interval 1-3 seconds, attack_range 10
		$this->goalSelector->addGoal(1, new class($this, 1.0, 20, 20, self::TRIDENT_RANGE, $this->throwTrident(...), 60) extends RangedAttackGoal{
			public function canStart() : bool{
				$mob = $this->mob;
				$target = $mob->getAttackTarget();
				return $mob instanceof Drowned && $target !== null && $mob->hasTrident() && $mob->isInRangedMode($target) && parent::canStart();
			}
		});
		$this->swimAttack = new FreeMeleeAttackGoal($this, $this->swim, $this->getSwimSpeed(), self::ATTACK_DAMAGE, 0.3, 20, 32.0, fn() => $this->isInWater());
		$this->goalSelector->addGoal(2, $this->swimAttack);
		$this->goalSelector->addGoal(2, new FleeSunGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, self::FOLLOW_RANGE, canSpreadOnFire: true));
		$this->goalSelector->addGoal(4, new StompTurtleEggGoal($this));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//must_see_forget_duration 17 seconds, reselect_targets, persist_time 0.5 seconds (a player leaving the water by day)
		$this->targetSelector->addGoal(2, NearestLivingTargetGoal::players($this, fn(Player $p) => $this->canHunt($p), self::TARGET_RANGE, forgetTicks: 17 * 20, reselect: true, persistTicks: 10));
		//the other entity_types of the same nearest_attackable_target, in one search: golems, axolotls and baby turtles on
		//land must be seen, villagers and wandering traders don't; skipped while the world has none of them
		$this->targetSelector->addGoal(3, new NearestAttackableEntityGoal(
			$this,
			fn(Living $e) => $e instanceof VillagerV2 || $e instanceof Villager || $e instanceof WanderingTrader ?
				$this->canHuntLiving($e) :
				($e instanceof Turtle ? $e->isBaby() && !$e->isInWater() : ($e instanceof IronGolem || $e instanceof SnowGolem || $e instanceof Axolotl) && $this->canHuntLiving($e)) && $this->getSensing()->canSee($e),
			self::TARGET_RANGE,
			false,
			10,
			fn() => self::worldHasEntityTargets($this->getWorld()),
			reselect: true
		));
	}

	/**
	 * Drowned only go after players who are in water, or anyone at night.
	 */
	public function canHunt(Player $player) : bool{
		return $this->canHuntLiving($player);
	}

	/**
	 * Whether the entity is in water or it is night (drowned.json filters in_water or not is_daytime).
	 */
	private function canHuntLiving(Living $entity) : bool{
		return $this->getWorld()->getSkyLightReduction() >= 4 || self::isInWaterForTargeting($entity);
	}

	private static function worldHasEntityTargets(World $world) : bool{
		return $world->hasEntitiesOfClass(VillagerV2::class) || $world->hasEntitiesOfClass(Villager::class) || $world->hasEntitiesOfClass(WanderingTrader::class) ||
			$world->hasEntitiesOfClass(IronGolem::class) || $world->hasEntitiesOfClass(SnowGolem::class) || $world->hasEntitiesOfClass(Turtle::class) ||
			$world->hasEntitiesOfClass(Axolotl::class);
	}

	private static function isInWaterForTargeting(Living $entity) : bool{
		$loc = $entity->getLocation();
		return $entity->isUnderwater() ||
			(PathBlockTypes::at($entity->getWorld(), $loc->getFloorX(), $loc->getFloorY(), $loc->getFloorZ()) & PathBlockTypes::WATER) !== 0;
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$immersed = self::isInWaterForTargeting($target);
		$motion = $target->getMotion();
		$hit = parent::attackMelee($target, $baseDamage);
		if($hit && $immersed && $target->isAlive() && $this->lastMeleeHitHasDefaultKnockback() && !$target->getMotion()->equals($motion)){
			//the hit knocked it back: drowned.json apply_knockback_rules push swimmers down
			$dx = $target->getLocation()->x - $this->location->x;
			$dz = $target->getLocation()->z - $this->location->z;
			$f = sqrt($dx * $dx + $dz * $dz);
			if($f > 0){
				$target->setMotion(new Vector3(
					$motion->x / 2 + $dx / $f * self::WATER_KNOCKBACK_HORIZONTAL,
					max($motion->y / 2 + self::WATER_KNOCKBACK_VERTICAL, self::WATER_KNOCKBACK_VERTICAL_CAP),
					$motion->z / 2 + $dz / $f * self::WATER_KNOCKBACK_HORIZONTAL
				));
			}
		}
		return $hit;
	}

	public function hasTrident() : bool{
		return $this->getMainHandItem()->getTypeId() === ItemTypeIds::TRIDENT;
	}

	public function throwTrident(Living $target, float $distance) : void{
		$world = $this->getWorld();
		$eye = $this->getEyePos()->subtract(0, 0.1, 0);
		$motion = Aim::motionTowards($eye, Aim::targetPoint($target), 1.6, Aim::mobInaccuracy($world), 0.2);
		if($motion === null){
			return;
		}
		$trident = new MobTrident(Aim::facing($eye, $world, $motion), $this->getMainHandItem(), $this);
		$trident->setMotion($motion);
		$trident->spawnToAll();
		$this->playMobSound(LevelSoundEvent::ITEM_TRIDENT_THROW);
	}

	public function burnsInDaylight() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	protected function tryChangeMovement() : void{
		if($this->isAlive() && $this->isInWater() && $this->swim->hasWanted()){
			$this->setHasGravity(false);
			$this->motion = $this->swim->computeMotion($this->motion, 0.9);
			return;
		}
		$this->setHasGravity(true);
		parent::tryChangeMovement();
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 2) + $this->lootingBonus())
		];
		if($this->wasRecentlyHitByPlayer() && mt_rand(0, 999) < 110 + 20 * $this->getLootingLevel()){
			$drops[] = VanillaItems::COPPER_INGOT();
		}
		return [...$drops, ...$this->getEquipmentDrops(true)];
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp($this->isBaby() ? 12 : 5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::DROWNED_SPAWN_EGG();
	}

	protected function destroyCycles() : void{
		unset($this->swim);
		$this->swimAttack = null;
		parent::destroyCycles();
	}
}
