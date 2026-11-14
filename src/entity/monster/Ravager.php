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

use pocketmine\block\Azalea;
use pocketmine\block\Bamboo;
use pocketmine\block\BambooSapling;
use pocketmine\block\BaseBigDripleaf;
use pocketmine\block\Block;
use pocketmine\block\Bush;
use pocketmine\block\CarvedPumpkin;
use pocketmine\block\CaveVines;
use pocketmine\block\ChorusFlower;
use pocketmine\block\ChorusPlant;
use pocketmine\block\Crops;
use pocketmine\block\DeadBush;
use pocketmine\block\DoublePitcherCrop;
use pocketmine\block\DoublePlant;
use pocketmine\block\Eyeblossom;
use pocketmine\block\FireflyBush;
use pocketmine\block\Flower;
use pocketmine\block\HangingRoots;
use pocketmine\block\Leaves;
use pocketmine\block\Melon;
use pocketmine\block\NetherFungus;
use pocketmine\block\PaleHangingMoss;
use pocketmine\block\PitcherPlant;
use pocketmine\block\Pumpkin;
use pocketmine\block\RedMushroom;
use pocketmine\block\Sapling;
use pocketmine\block\SmallDripleaf;
use pocketmine\block\SnowLayer;
use pocketmine\block\SporeBlossom;
use pocketmine\block\Sugarcane;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\TallGrass;
use pocketmine\block\TorchFlower;
use pocketmine\block\TurtleEgg;
use pocketmine\block\Vine;
use pocketmine\block\WaterLily;
use pocketmine\entity\ai\goal\DelayedMeleeAttackGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use function floor;
use function mt_rand;
use function sqrt;

/**
 * Bedrock values (ravager.json): 100 health, movement 0.4, 1.95x2.2, attack 12 (reach x1.5), knockback resistance
 * 0.75. It bites with a wind-up (behavior.delayed_attack, attack_duration 0.75 s, hit_delay_pct 0.5): clients play the
 * bite animation while the DELAYED_ATTACKING flag is set (BDS 1.26.52: flag and "attack.strong" sound at the start, the
 * hit 7 ticks later, the flag cleared after 15 ticks, the next bite 31 ticks after the previous one). When its attack is blocked by a shield it is stunned for 2 seconds (50%, ravager_blocked;
 * otherwise the blocking player is knocked back), then roars: entities within 4 blocks are
 * knocked back and non-illagers take 6 damage (behavior.knockback_roar).
 * Loot: a saddle when killed by a player. Experience 20.
 * One pillager, vindicator or evoker can ride it (raids put riders on some ravagers).
 * It breaks the leaves, crops and small plants it walks into (minecraft:break_blocks, see {@link Ravager::canBreak()}),
 * with drops, unless the mobgriefing game rule is off.
 */
class Ravager extends Monster implements Raider{
	use RaiderTrait;
	public const ATTACK_DAMAGE = 12.0;
	public const STUN_TICKS = 40;
	public const ROAR_TICKS = 20;
	public const ROAR_ATTACK_TICK = 10;
	public const ROAR_RANGE = 4.0;
	public const ROAR_DAMAGE = 6.0;
	public const ATTACK_DURATION = 15;
	public const ATTACK_HIT_TICK = 7;
	public const ATTACK_INTERVAL = 31;
	/** ravager_blocked knockback_strength */
	public const BLOCKED_KNOCKBACK = 3.0;

	private int $stunTicks = 0;
	private int $roarTicks = 0;
	private bool $delayedAttacking = false;

	public static function getNetworkTypeId() : string{ return EntityIds::RAVAGER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.2, 1.95, 2.1);
	}

	public function getName() : string{
		return "Ravager";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(100);
		$this->setMovementSpeed(0.4);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
		$this->knockbackResistanceAttr->setValue(0.75);
		$this->loadRaiderData($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveRaiderData($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		//delayed_attack reach_multiplier 1.5
		$this->goalSelector->addGoal(4, new DelayedMeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, 64.0, self::ATTACK_DURATION, self::ATTACK_HIT_TICK, self::ATTACK_INTERVAL, $this->setDelayedAttacking(...), 1.5));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 0.4));
		$this->goalSelector->addGoal(10, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(11, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(2, new class($this) extends HurtByTargetGoal{
			public function canStart() : bool{
				$attacker = $this->mob->getLastAttacker();
				return ($attacker === null || !Illager::isAlly($attacker)) && parent::canStart();
			}
		});
		$this->targetSelector->addGoal(3, new NearestAttackableTargetGoal($this, 16.0, true, 10));
		$this->registerRaiderGoals(5, 4, true);
	}

	/**
	 * Returns whether the ravager is biting: from the start of its delayed attack to its end.
	 */
	public function isDelayedAttacking() : bool{
		return $this->delayedAttacking;
	}

	private function setDelayedAttacking(bool $attacking) : void{
		if($attacking !== $this->delayedAttacking){
			$this->delayedAttacking = $attacking;
			$this->networkPropertiesDirty = true;
			if($attacking){
				$this->playMobSound(LevelSoundEvent::ATTACK_STRONG);
			}
		}
	}

	public function isStunned() : bool{
		return $this->stunTicks > 0;
	}

	public function isRoaring() : bool{
		return $this->roarTicks > 0;
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $baseDamage);
		if(!$hit && $target instanceof Player && $target->isBlocking() && $this->roarTicks <= 0){
			//ravager_blocked: reaction_choices "become_stunned" (weight 1) or nothing (weight 1), in which case the
			//blocking player is knocked back with knockback_strength 3
			if(self::rollBlockedStun()){
				$this->stun();
			}else{
				$this->knockBackBlocker($target);
			}
		}
		return $hit;
	}

	/**
	 * Whether a blocked attack stuns the ravager (Bedrock: 50%).
	 */
	public static function rollBlockedStun() : bool{
		return mt_rand(0, 1) === 0;
	}

	/**
	 * Pushes the entity which blocked the bite away from the ravager (ravager_blocked knockback_strength 3, scaled like
	 * the roar's knockback).
	 */
	private function knockBackBlocker(Living $target) : void{
		$dx = $target->getLocation()->x - $this->location->x;
		$dz = $target->getLocation()->z - $this->location->z;
		$dist = sqrt($dx * $dx + $dz * $dz);
		if($dist > 0.0001){
			$target->setMotion($target->getMotion()->add($dx / $dist * self::BLOCKED_KNOCKBACK * 0.25, 0.2, $dz / $dist * self::BLOCKED_KNOCKBACK * 0.25));
		}
	}

	/**
	 * Stuns the ravager (its attack was blocked by a shield): it stands still for 2 seconds, then roars.
	 */
	public function stun() : void{
		$this->stunTicks = self::STUN_TICKS;
		$this->goalSelector->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$this->navigation->stop();
		$this->networkPropertiesDirty = true;
		$this->playMobSound(LevelSoundEvent::STUN);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if($this->stunTicks > 0){
			$this->stunTicks -= $tickDiff;
			if($this->stunTicks <= 0){
				$this->roarTicks = self::ROAR_TICKS;
				$this->networkPropertiesDirty = true;
				$this->playMobSound(LevelSoundEvent::ROAR);
			}
			return true;
		}
		if($this->roarTicks > 0){
			$before = $this->roarTicks;
			$this->roarTicks -= $tickDiff;
			if($before > self::ROAR_TICKS - self::ROAR_ATTACK_TICK && $this->roarTicks <= self::ROAR_TICKS - self::ROAR_ATTACK_TICK){
				$this->roar();
			}
			if($this->roarTicks <= 0){
				$this->goalSelector->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, false);
				$this->networkPropertiesDirty = true;
			}
			return true;
		}
		$this->breakBlocksInTheWay();
		return $hasUpdate;
	}

	/**
	 * Whether the ravager breaks this block when it walks into it (Bedrock ravager.json minecraft:break_blocks list).
	 */
	public static function canBreak(Block $block) : bool{
		if($block instanceof Flower){
			return !$block instanceof TorchFlower && !$block instanceof Eyeblossom;
		}
		if($block instanceof DoublePlant){
			return !$block instanceof PitcherPlant && !$block instanceof DoublePitcherCrop;
		}
		return $block instanceof Leaves ||
			$block instanceof Crops || //wheat, carrots, potatoes, beetroots, melon and pumpkin stems
			$block instanceof Bamboo ||
			$block instanceof BambooSapling ||
			$block instanceof RedMushroom || //and brown mushrooms
			$block instanceof Pumpkin ||
			$block instanceof CarvedPumpkin || //and jack o'lanterns
			$block instanceof Melon ||
			$block instanceof ChorusFlower ||
			$block instanceof ChorusPlant ||
			$block instanceof DeadBush ||
			$block instanceof NetherFungus ||
			$block instanceof Sugarcane ||
			$block instanceof Sapling ||
			$block instanceof SnowLayer ||
			$block instanceof SweetBerryBush ||
			$block instanceof TallGrass ||
			$block instanceof TurtleEgg ||
			$block instanceof Vine ||
			$block instanceof WaterLily ||
			$block instanceof Azalea ||
			$block instanceof CaveVines ||
			$block instanceof SmallDripleaf ||
			$block instanceof BaseBigDripleaf ||
			$block instanceof SporeBlossom ||
			$block instanceof HangingRoots ||
			$block instanceof PaleHangingMoss ||
			$block instanceof FireflyBush ||
			$block instanceof Bush;
	}

	/**
	 * Breaks the breakable blocks touching the ravager (its box grown by 0.2 blocks sideways, like Java's ravager):
	 * leaves it bumps into and the plants it walks through.
	 *
	 * @return int number of blocks broken
	 */
	public function breakBlocksInTheWay() : int{
		if($this->isStunned() || $this->isRoaring() || !GameRules::allowsMobGriefing($this->getWorld())){
			return 0;
		}
		$box = $this->boundingBox;
		$world = $this->getWorld();
		$broken = 0;
		for($x = (int) floor($box->minX - 0.2), $maxX = (int) floor($box->maxX + 0.2); $x <= $maxX; ++$x){
			for($y = (int) floor($box->minY), $maxY = (int) floor($box->maxY); $y <= $maxY; ++$y){
				for($z = (int) floor($box->minZ - 0.2), $maxZ = (int) floor($box->maxZ + 0.2); $z <= $maxZ; ++$z){
					if(!$world->isInWorld($x, $y, $z)){
						continue;
					}
					$block = $world->getBlockAt($x, $y, $z);
					if(self::canBreak($block) && $world->useBreakOn(new Vector3($x, $y, $z), createParticles: true)){
						++$broken;
					}
				}
			}
		}
		return $broken;
	}

	/**
	 * Knocks back every entity within 4 blocks (except ravagers) and hurts the non-illagers.
	 */
	public function roar() : void{
		$loc = $this->location;
		$r = self::ROAR_RANGE;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			if(!$entity instanceof Living || $entity instanceof Ravager || !$entity->isAlive()){
				continue;
			}
			$dx = $entity->getLocation()->x - $loc->x;
			$dz = $entity->getLocation()->z - $loc->z;
			$dist = sqrt($dx * $dx + $dz * $dz);
			if($dist > $r){
				continue;
			}
			if(!Illager::isAlly($entity)){
				$entity->attack(new EntityDamageByEntityEvent($this, $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, self::ROAR_DAMAGE, [], 0.0));
			}
			if($dist > 0.0001 && $entity->setMotion($entity->getMotion()->add($dx / $dist * 3 * 0.25, 0.4, $dz / $dist * 3 * 0.25)) && $entity instanceof Mob){
				$entity->carryPush();
			}
		}
		$this->getWorld()->broadcastPacketToViewers($loc, LevelEventPacket::create(LevelEvent::PARTICLE_KNOCKBACK_ROAR, 0, $loc->add(0, 1, 0)));
	}

	public function getDrops() : array{
		return $this->wasRecentlyHitByPlayerDirectly() ? [VanillaItems::SADDLE()] : [];
	}

	public function isRaidVictim(Living $entity) : bool{
		return ($entity instanceof VillagerV2 && !$entity->isBaby()) || $entity instanceof WanderingTrader || $entity instanceof IronGolem;
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 20 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::RAVAGER_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::STUNNED, $this->isStunned());
		$properties->setGenericFlag(EntityMetadataFlags::ROARING, $this->isRoaring());
		$properties->setGenericFlag(EntityMetadataFlags::DELAYED_ATTACKING, $this->delayedAttacking);
		$this->syncRaiderData($properties);
	}

	public function getRideableSeats() : array{
		return [RideableSeat::at(0, 2.1, -0.3)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Pillager || $entity instanceof Vindicator || $entity instanceof Evoker;
	}
}
