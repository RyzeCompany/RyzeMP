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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\ai\hostile\goal\FleeBlockGoal;
use pocketmine\entity\ai\hostile\goal\FleeEntityGoal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function count;
use function mt_rand;

/**
 * Bedrock values (hoglin.json): 40 health, 1.4x1.4, movement 0.3 (baby 0.36), knockback resistance 0.6. Attacks
 * players within 16 blocks: adults for 3-9 damage every 2 seconds, tossing them up, babies for 1 every 0.75 seconds
 * (babies also panic when hurt). Hurting one angers the hoglins within 16 blocks for 10 seconds. Bred with crimson
 * fungus. Turns into a zoglin after 15 seconds outside the Nether.
 * - Avoids warped fungus, nether portals and respawn anchors within 8 blocks (4 up or down): it walks away from them
 *   (retreat sound) and doesn't look for players to attack while one is near (avoid_block). Once it got away
 *   (on_escape), it calms down and neither targets players nor falls in love for 10-15 seconds (attack_cooldown).
 * - Runs away (1.2x, retreat sound) from piglins within 10 blocks that have a target when they outnumber the hoglins
 *   there (avoid_mob_type check_if_outnumbered).
 * - Hunted by groups of adult piglins (see {@link Piglin}); when piglins kill one, the piglins around celebrate.
 * - Babies carry up to 3 baby piglins (piglin jockeys, see {@link \pocketmine\entity\riding\Jockeys}).
 * Loot (adult): 2-4 porkchops (cooked when burning), 0-1 leather (+0-1 each per Looting level). Experience 5.
 */
class Hoglin extends Animal implements Angerable{
	use AngerTrait;
	use ZombifiesOutsideNetherTrait;

	public const ANGER_TICKS = 200;
	/**
	 * Ticks between two hits of an adult (melee_box_attack cooldown_time 2 s).
	 * @internal
	 */
	public const ATTACK_INTERVAL = 40;
	/**
	 * Ticks between two hits of a baby (cooldown_time 0.75 s).
	 * @internal
	 */
	public const BABY_ATTACK_INTERVAL = 15;
	public const REPELLENT_RANGE = 8;
	public const REPELLENT_HEIGHT = 4;
	/**
	 * attack_cooldown after escaping a repellent: 10-15 s.
	 * @internal
	 */
	public const ATTACK_COOLDOWN_MIN_TICKS = 200;
	/** @internal */
	public const ATTACK_COOLDOWN_MAX_TICKS = 300;
	/**
	 * avoid_mob_type max_dist for piglins.
	 * @internal
	 */
	public const PIGLIN_AVOID_RANGE = 10.0;

	private ?Vector3 $repellent = null;
	private int $repellentCheckTicks = 0;
	/** Whether it walked away from a repellent and hasn't escaped it yet. */
	private bool $fleeingRepellent = false;
	private int $attackCooldown = 0;
	private ?Entity $piglinThreat = null;
	private int $piglinCheckTicks = 0;
	/**
	 * @var RideableSeat[]|null
	 * @phpstan-var list<RideableSeat>|null
	 */
	private static ?array $babySeats = null;

	public static function getNetworkTypeId() : string{ return EntityIds::HOGLIN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.4, 1.4, 1.2);
	}

	public function getName() : string{
		return "Hoglin";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(1.7, 1.5, 1.457); //baby collision box 1.5 x 1.7 at scale 0.5
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(40);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->loadZombificationImmunity($nbt);
		$this->knockbackResistanceAttr->setValue(0.6);
		if($nbt->getTag(self::TAG_AGE) === null && mt_rand(0, 99) < 5){
			$this->setAge(self::BABY_AGE);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveZombificationImmunity($nbt);
		return $nbt;
	}

	public function setAge(int $age) : void{
		parent::setAge($age);
		$this->setMovementSpeed($this->isBaby() ? 0.36 : 0.3);
		if(!$this->isBaby() && count($this->getPassengers()) > 0){
			//grown up: no seats any more
			$this->ejectPassengers();
		}
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new class($this, $this->findOutnumberingPiglin(...), self::PIGLIN_AVOID_RANGE, 1.2) extends FleeEntityGoal{
			/** Server tick of the next retreat sound, kept across restarts so the 2-5 s interval holds. */
			private int $nextSoundTick = 0;
			private int $startTick = 0;
			/** Server tick before which the goal doesn't start again after a flee that found no way out. */
			private int $retryTick = 0;

			public function canStart() : bool{
				return $this->mob->getWorld()->getServer()->getTick() >= $this->retryTick && parent::canStart();
			}

			public function start() : void{
				parent::start();
				//remove_target
				$this->mob->setAttackTarget(null);
				$this->startTick = $this->mob->getWorld()->getServer()->getTick();
			}

			public function stop() : void{
				parent::stop();
				$now = $this->mob->getWorld()->getServer()->getTick();
				if($now - $this->startTick < 20){
					//no destination or no path (penned in): don't retry the search every evaluation
					$this->retryTick = $now + 20;
				}
			}

			public function tick() : void{
				//avoid_mob_sound retreat, every 2-5 seconds
				$now = $this->mob->getWorld()->getServer()->getTick();
				if($now >= $this->nextSoundTick){
					$this->nextSoundTick = $now + mt_rand(40, 100);
					$this->mob->playMobSound(LevelSoundEvent::RETREAT);
				}
			}
		});
		$this->goalSelector->addGoal(1, new class($this, 1.0) extends PanicGoal{
			protected function shouldPanic() : bool{
				return $this->mob instanceof Animal && $this->mob->isBaby() && parent::shouldPanic();
			}
		});
		$this->goalSelector->addGoal(2, new FleeBlockGoal($this, $this->findRepellent(...), 1.0, function() : void{
			$this->fleeingRepellent = true;
			$this->playMobSound(LevelSoundEvent::RETREAT);
		}));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 0.6));
		$this->goalSelector->addGoal(4, new class($this, 1.0, 1.0, 16.0) extends MeleeAttackGoal{
			protected function getAttackInterval() : int{
				//cooldown_time 2 s (adult), 0.75 s (baby)
				return $this->mob instanceof Hoglin && $this->mob->isBaby() ? Hoglin::BABY_ATTACK_INTERVAL : Hoglin::ATTACK_INTERVAL;
			}
		});
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 0.4));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new AngerTargetGoal($this, $this->getAngerTarget(...), 32.0));
		//babies too (base nearest_attackable_target), not during the attack cooldown
		$this->targetSelector->addGoal(4, new NearestAttackableTargetGoal($this, 16.0, true, 10, fn() => $this->attackCooldown <= 0 && $this->findRepellent() === null));
	}

	/**
	 * Ticks left of the attack cooldown that follows escaping a repellent (no player targeting, no love mode).
	 */
	public function getAttackCooldown() : int{
		return $this->attackCooldown;
	}

	/**
	 * Called once the hoglin got out of range of the repellent it walked away from (avoid_block on_escape ->
	 * escaped_event): it calms down and gets the attack cooldown.
	 */
	private function onEscapedRepellent() : void{
		$this->attackCooldown = mt_rand(self::ATTACK_COOLDOWN_MIN_TICKS, self::ATTACK_COOLDOWN_MAX_TICKS);
		$this->setAngryAt(null, 0);
		if($this->getAttackTarget() instanceof Player){
			$this->setAttackTarget(null);
		}
	}

	/**
	 * The nearest piglin (or piglin brute) within 10 blocks that has a target, when such piglins outnumber the hoglins
	 * there, this one included (avoid_mob_type check_if_outnumbered). Looked for once a second.
	 */
	public function findOutnumberingPiglin() : ?Entity{
		if(!PiglinAi::hasPiglins()){
			return $this->piglinThreat = null;
		}
		if($this->piglinCheckTicks > 0){
			$threat = $this->piglinThreat;
			return $threat !== null && $threat->isAlive() && !$threat->isClosed() ? $threat : null;
		}
		$this->piglinCheckTicks = 20;
		$this->piglinThreat = null;
		$loc = $this->location;
		$r = self::PIGLIN_AVOID_RANGE;
		$piglins = 0;
		$hoglins = 1;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this) as $entity){
			if(!$entity->isAlive()){
				continue;
			}
			if($entity instanceof Hoglin){
				if($entity->location->distanceSquared($loc) <= $r * $r){
					++$hoglins;
				}
			}elseif(($entity instanceof Piglin || $entity instanceof PiglinBrute) && $entity->getAttackTarget() !== null){
				$d = $entity->location->distanceSquared($loc);
				if($d <= $r * $r){
					++$piglins;
					if($d <= $bestDist){
						$best = $entity;
						$bestDist = $d;
					}
				}
			}
		}
		if($piglins > $hoglins){
			$this->piglinThreat = $best;
		}
		return $this->piglinThreat;
	}

	protected function canFallInLove(Player $player) : bool{
		//love_filters: not during the attack cooldown
		return $this->attackCooldown <= 0 && parent::canFallInLove($player);
	}

	/**
	 * Baby hoglins carry up to 3 piglins (hoglin_baby minecraft:rideable, family piglin), stacked on top of each other.
	 */
	public function getRideableSeats() : array{
		if(!$this->isBaby()){
			return [];
		}
		//the seat positions of hoglin.json, at the baby's scale 0.5
		return self::$babySeats ??= [RideableSeat::at(0, 0.5625, -0.15), RideableSeat::at(0, 1.3125, -0.15), RideableSeat::at(0, 2.0625, -0.15)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $this->isBaby() && $entity instanceof Piglin;
	}

	/**
	 * Nearest warped fungus, nether portal or respawn anchor within 8 blocks (4 up or down), looked for once a second.
	 */
	public function findRepellent() : ?Vector3{
		if($this->repellentCheckTicks <= 0){
			$this->repellentCheckTicks = 20;
			$this->repellent = PiglinAi::findNearestBlock($this->getWorld(), $this->location, self::REPELLENT_RANGE, self::REPELLENT_HEIGHT, PiglinAi::getHoglinRepellents());
			if($this->repellent === null && $this->fleeingRepellent){
				$this->fleeingRepellent = false;
				$this->onEscapedRepellent();
			}
		}
		return $this->repellent;
	}

	/**
	 * Adults hit for 3-9 (random, vanilla attack damage range) and toss their target up; babies for 1.
	 */
	public function attackMelee(Living $target, float $baseDamage) : bool{
		$damage = $this->isBaby() ? 1.0 : (float) mt_rand(3, 9);
		$hit = parent::attackMelee($target, $damage);
		if($hit && !$this->isBaby() && $this->lastMeleeHitHasDefaultKnockback()){
			self::tossUp($target);
		}
		return $hit;
	}

	/**
	 * Throws the target up and away (Java HoglinBase::throwTarget).
	 */
	public static function tossUp(Living $target) : void{
		$motion = $target->getMotion();
		$target->setMotion(new Vector3($motion->x, 0.5 + mt_rand(0, 4) / 10, $motion->z));
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === VanillaBlocks::CRIMSON_FUNGUS()->asItem()->getTypeId();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Hoglin($location);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Living && !$damager instanceof Hoglin && (!$damager instanceof Player || !$damager->isCreative())){
				$this->setAngryAt($damager, self::ANGER_TICKS);
				$this->alertNearby($damager, 16.0, Hoglin::class, self::ANGER_TICKS);
			}
		}
	}

	protected function onAngerChange(bool $angry) : void{
		if($angry){
			$this->playMobSound(LevelSoundEvent::ANGRY);
		}
	}

	protected function isAngryWhenTargeting() : bool{
		//hoglin_adult: on_target_acquired adds angry_hoglin (minecraft:angry)
		return true;
	}

	protected function showsTargetAnger() : bool{
		return !$this->isBaby();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && !$this->closed){
			$this->repellentCheckTicks -= $tickDiff;
			$this->piglinCheckTicks -= $tickDiff;
			if($this->attackCooldown > 0){
				$this->attackCooldown -= $tickDiff;
			}
			$this->tickAnger($tickDiff);
			$this->tickZombification($tickDiff);
		}
		return $hasUpdate;
	}

	protected function onDeath() : void{
		parent::onDeath();
		$cause = $this->getLastDamageCause();
		if($cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Piglin){
			//celebrate_hunt: the piglins around dance
			$loc = $this->location;
			foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - 16, $loc->y - 8, $loc->z - 16, $loc->x + 16, $loc->y + 8, $loc->z + 16), $this) as $entity){
				if($entity instanceof Piglin && $entity->isAlive() && !$entity->isBaby()){
					$entity->startCelebrating();
				}
			}
		}
	}

	protected function zombify() : void{
		$zoglin = new Zoglin($this->location->asLocation());
		$zoglin->setBaby($this->isBaby());
		MobConversion::copyState($this, $zoglin);
		$zoglin->spawnToAll();
		$zoglin->playMobSound(LevelSoundEvent::MOB_HOGLIN_CONVERTED_TO_ZOMBIFIED);
		$this->flagForDespawn();
	}

	protected function getAdultDrops() : array{
		return [
			$this->cookedIfBurning(VanillaItems::RAW_PORKCHOP(), VanillaItems::COOKED_PORKCHOP())->setCount(mt_rand(2, 4) + $this->lootingBonus()),
			VanillaItems::LEATHER()->setCount(mt_rand(0, 1) + $this->lootingBonus())
		];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::HOGLIN_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//flag 40 is ActorFlags::SHAKING (zombification shaking), named VIBRATING in BedrockProtocol
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->isZombifying());
	}
}
