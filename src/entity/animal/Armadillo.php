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

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Brush;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\vibration\GameEvent;
use function max;
use function mt_rand;

/**
 * Bedrock values (entities/armadillo.json): 12 health, 0.7x0.65, movement 0.14 (0 when rolled up), babies scale 0.6.
 * Rolls up (actor property minecraft:armadillo_state) when a mob or player hurts it or when a threat is within 7 blocks
 * (2 up or down): any undead mob, or a player who is sprinting, riding or recently hurt it (entity_sensor). It only
 * rolls up on the ground and when not burning, in water, panicking, leashed or riding, and unrolls right away when one
 * of these happens. Rolled up with threats around, it peeks out for 2.5 s every 5-20 s (rolled_up_peeking); without
 * threats it relaxes (rolled_up_relaxing) and unrolls 4 s later (rolled_up_unrolling for the last 1.5 s). Rolled up it
 * takes (damage - 1) / 2 (Java). Panic ignores damage from mobs and players. Brushing an adult drops a scute (brush
 * loses 16 durability); adults also drop one every 5-10 minutes.
 * Tempted (1.25x) and bred with spider eyes. No loot; XP 1-3.
 */
class Armadillo extends Animal{
	public const TAG_SCUTE_TIME = "ScuteTime"; //TAG_Int
	public const TAG_STATE = "ArmadilloState"; //TAG_Int

	public const STATE_UNROLLED = "unrolled";
	public const STATE_ROLLED_UP = "rolled_up";
	public const STATE_PEEKING = "rolled_up_peeking";
	public const STATE_RELAXING = "rolled_up_relaxing";
	public const STATE_UNROLLING = "rolled_up_unrolling";

	/** Damage modifier key of the rolled up damage reduction. */
	public const MODIFIER_ROLLED_UP = 1000;
	private const MOVEMENT_SPEED = 0.14;

	private const STATES = [self::STATE_UNROLLED, self::STATE_ROLLED_UP, self::STATE_PEEKING, self::STATE_RELAXING, self::STATE_UNROLLING];

	/** Ticks between two threat scans (Bedrock entity_sensor cooldown 0.2 s; scanned less often to stay cheap). */
	private const THREAT_SCAN_INTERVAL = 10;
	/** Peeking time, and the delay before the first peeking cycle (timer_flag_1 cooldown_range 2.5 s). */
	private const PEEK_TICKS = 50;
	/** rolled_up_relaxing lasts 2.5 s, then rolled_up_unrolling 1.5 s (minecraft:timer 4 s). */
	private const RELAX_TICKS = 50;
	private const UNROLL_TICKS = 30;
	/** How long a player who hurt the armadillo stays a threat (was_last_hurt_by, Java's 100 ticks of lastHurtByMob). */
	private const LAST_HURT_BY_TICKS = 100;

	private string $state = self::STATE_UNROLLED;
	/** Ticks left in the current rolled up, peeking, relaxing or unrolling phase. */
	private int $stateTimer = 0;
	private int $scuteTime = 0;
	private int $threatCheck = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::ARMADILLO; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.65, 0.7, 0.4);
	}

	public function getName() : string{
		return "Armadillo";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function getBabyScale() : float{
		return 0.6;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(12);
		$this->setMovementSpeed(self::MOVEMENT_SPEED);
		parent::initEntity($nbt);
		$this->scuteTime = $nbt->getInt(self::TAG_SCUTE_TIME, mt_rand(6000, 12000));
		$this->setState(self::STATES[$nbt->getInt(self::TAG_STATE, 0)] ?? self::STATE_UNROLLED);
		$this->stateTimer = self::PEEK_TICKS;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_SCUTE_TIME, $this->scuteTime);
		$nbt->setInt(self::TAG_STATE, $this->getActorProperty("minecraft:armadillo_state"));
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(0, new class($this) extends Goal{
			public function __construct(private Armadillo $armadillo){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP; }

			public function canStart() : bool{ return $this->armadillo->isRolledUp(); }

			public function start() : void{ $this->armadillo->getNavigation()->stop(); }
		});
		//ignore_mob_damage: hits make it roll up instead
		$this->goalSelector->addGoal(1, new PanicGoal($this, 2.0, ignoreMobDamage: true));
		$this->goalSelector->addGoal(2, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 1.25));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
	}

	public function getState() : string{
		return $this->state;
	}

	public function setState(string $state) : void{
		$wasRolledUp = $this->isRolledUp();
		$this->state = $state;
		$this->setActorProperty("minecraft:armadillo_state", $state);
		if($wasRolledUp !== $this->isRolledUp()){
			//BDS: movement 0 while rolled up, and the timer and blocked body rotation flags
			$this->setMovementSpeed($this->isRolledUp() ? 0.0 : self::MOVEMENT_SPEED);
			$this->networkPropertiesDirty = true;
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$rolledUp = $this->isRolledUp();
		$properties->setGenericFlag(EntityMetadataFlags::TIMER_FLAG_1, $rolledUp);
		$properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_BLOCKED, $rolledUp);
	}

	public function isRolledUp() : bool{
		return $this->state !== self::STATE_UNROLLED;
	}

	/**
	 * Undead mobs (family undead) and sprinting or riding players scare armadillos. A player who recently hurt the
	 * armadillo scares it too, see {@link Armadillo::isThreat()}.
	 */
	public static function isScaryEntity(Entity $entity) : bool{
		if($entity instanceof Player){
			return !$entity->isSpectator() && ($entity->isSprinting() || $entity->isRiding());
		}
		return Wither::isUndead($entity);
	}

	private function isThreat(Entity $entity) : bool{
		if(!$entity->isAlive()){
			return false;
		}
		if(self::isScaryEntity($entity)){
			return true;
		}
		//was_last_hurt_by
		return $entity instanceof Player && !$entity->isSpectator() && $entity === $this->getLastAttacker() &&
			$this->server->getTick() - $this->getLastAttackedTick() <= self::LAST_HURT_BY_TICKS;
	}

	private function isThreatened() : bool{
		$loc = $this->location;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - 7, $loc->y - 2, $loc->z - 7, $loc->x + 7, $loc->y + 2, $loc->z + 7), $this) as $entity){
			if($this->isThreat($entity)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the armadillo would panic: burning, or hurt by something else than a mob or player in the last 5
	 * seconds (behavior.panic with ignore_mob_damage, see {@link PanicGoal}).
	 */
	private function isPanicking() : bool{
		return $this->isOnFire() || ($this->getAiTick() - $this->getLastHurtTick() < 100 && !$this->wasLastHurtByMob());
	}

	/**
	 * Whether the armadillo can't stay rolled up: burning, in water, panicking, leashed or riding (environment_sensor of
	 * the rolled_up group).
	 */
	private function mustUnroll() : bool{
		return $this->isOnFire() || $this->isInWater() || $this->isPanicking() || $this->isLeashed() || $this->isRiding();
	}

	/**
	 * minecraft:threat_detected: an unrolled armadillo on the ground rolls up; a relaxing or unrolling one curls up
	 * again.
	 */
	private function onThreatDetected() : void{
		if($this->state === self::STATE_UNROLLED){
			if($this->isOnGround() && !$this->mustUnroll()){
				$this->setState(self::STATE_ROLLED_UP);
				$this->stateTimer = self::PEEK_TICKS + mt_rand(100, 400);
				$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_ACTION, $this);
			}
		}elseif($this->state === self::STATE_RELAXING || $this->state === self::STATE_UNROLLING){
			$this->setState(self::STATE_ROLLED_UP);
			$this->stateTimer = self::PEEK_TICKS + mt_rand(100, 400);
		}
	}

	/**
	 * minecraft:no_threat_detected: a rolled up or peeking armadillo starts relaxing.
	 */
	private function onNoThreatDetected() : void{
		if(($this->state === self::STATE_ROLLED_UP || $this->state === self::STATE_PEEKING) && !$this->mustUnroll()){
			$this->setState(self::STATE_RELAXING);
			$this->stateTimer = self::RELAX_TICKS;
		}
	}

	private function unroll() : void{
		$this->setState(self::STATE_UNROLLED);
		$this->stateTimer = 0;
		$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_ACTION, $this);
	}

	/**
	 * Advances the timers of the rolled up states: peeking every 5-20 s while threatened, and unrolling 4 s after
	 * relaxing.
	 */
	private function tickRolledUp(int $tickDiff) : void{
		if(($this->stateTimer -= $tickDiff) > 0){
			return;
		}
		switch($this->state){
			case self::STATE_ROLLED_UP:
				$this->setState(self::STATE_PEEKING);
				$this->stateTimer = self::PEEK_TICKS;
				break;
			case self::STATE_PEEKING:
				$this->setState(self::STATE_ROLLED_UP);
				$this->stateTimer = mt_rand(100, 400);
				break;
			case self::STATE_RELAXING:
				$this->setState(self::STATE_UNROLLING);
				$this->stateTimer = self::UNROLL_TICKS;
				break;
			default:
				$this->unroll();
		}
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::SPIDER_EYE;
	}

	protected function canFallInLove(Player $player) : bool{
		return !$this->isRolledUp();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Armadillo($location);
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->isRolledUp() && $source->getCause() !== EntityDamageEvent::CAUSE_SUICIDE && $source->getCause() !== EntityDamageEvent::CAUSE_VOID){
			$source->setModifier(-$source->getBaseDamage() + max(0.0, ($source->getBaseDamage() - 1) / 2), self::MODIFIER_ROLLED_UP);
		}
		parent::attack($source);
		//damage_sensor: damage from a mob or player is a threat
		if(!$source->isCancelled() && $this->isAlive() && $source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Living){
			$this->onThreatDetected();
		}
	}

	/**
	 * Drops a scute (brushing or the natural drop).
	 */
	public function dropScute() : void{
		$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), VanillaItems::ARMADILLO_SCUTE());
		$this->playMobSound(LevelSoundEvent::MOB_ARMADILLO_SCUTE_DROP);
	}

	/**
	 * Brushes a scute off an adult (players and dispensers; the brush loses 16 durability). Returns false for babies.
	 */
	public function brush() : bool{
		if($this->isBaby() || !$this->isAlive()){
			return false;
		}
		$this->dropScute();
		$this->playMobSound(LevelSoundEvent::MOB_ARMADILLO_BRUSH);
		$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_INTERACT, $this);
		return true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Brush && $this->brush()){
			if($player->hasFiniteResources()){
				$item->applyDamage(16);
				if($item->isBroken()){
					$player->broadcastSound(new ItemBreakSound());
				}
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}
		if($this->isRolledUp()){
			return false;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $hasUpdate;
		}
		if($this->state !== self::STATE_UNROLLED && $this->mustUnroll()){
			$this->unroll();
		}
		if(($this->threatCheck += $tickDiff) >= self::THREAT_SCAN_INTERVAL){
			$this->threatCheck = 0;
			if($this->isThreatened()){
				$this->onThreatDetected();
			}else{
				$this->onNoThreatDetected();
			}
		}
		if($this->state !== self::STATE_UNROLLED){
			$this->tickRolledUp($tickDiff);
		}
		if(!$this->isBaby() && ($this->scuteTime -= $tickDiff) <= 0){
			$this->scuteTime = mt_rand(6000, 12000);
			$this->dropScute();
		}
		return $hasUpdate;
	}
}
