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

use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\hostile\goal\FreeAvoidEntityGoal;
use pocketmine\entity\ai\hostile\goal\FreeWanderGoal;
use pocketmine\entity\ai\hostile\goal\GuardianAttackGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\FishingLoot;
use pocketmine\entity\Squid;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\World;
use function mt_rand;

/**
 * Bedrock values (guardian.json): 30 health, 0.85x0.85, swims (underwater_movement 0.12), follow range 16, attack 5.
 * Targets players, squids and axolotls it can see within 16 blocks and fires its laser at them (see
 * {@link GuardianAttackGoal}): the beam deals 1 magic damage (+2 on hard, +2 for elder guardians) plus its attack
 * damage. When its target comes within 3 blocks it stops charging and, for 1-3 seconds, swims away from the players
 * within 8 blocks (target_nearby_sensor -> guardian_passive: avoid_mob_type and a timer back to guardian_aggressive);
 * elder guardians don't. Melee attackers take 2 thorns damage from its spikes while it is not moving. It wanders
 * within 16 blocks of where it spawned and swims back when pushed or drawn farther (minecraft:home,
 * behavior.move_towards_home_restriction): guardians stay at their monument.
 * Loot: 0-2 prismarine shards (+0-1 per Looting level); raw cod (2/5) or prismarine crystals (2/5) (+0-1 per Looting
 * level); 2.5% (+1% per Looting level) a fish from fishing/fish.json when killed by a player. Experience 10.
 */
class Guardian extends AquaticMonster{
	public const ATTACK_DAMAGE = 5.0;
	public const ATTACK_DURATION = 80;
	public const FOLLOW_RANGE = 16.0;
	/** minecraft:home restriction_radius (random_movement), around where it spawned. */
	public const HOME_RADIUS = 16;
	/** underwater_movement x random_swim speed_multiplier, blocks per tick. */
	public const SWIM_SPEED = 0.12;
	/** target_nearby_sensor inside_range: the target is too close, the guardian turns guardian_passive. */
	public const TOO_CLOSE_DISTANCE = 3.0;
	/** guardian_passive avoid_mob_type max_dist (players). */
	public const AVOID_PLAYER_DISTANCE = 8.0;
	/** guardian_passive timer: 1-3 seconds back to guardian_aggressive. */
	public const PASSIVE_MIN_TICKS = 20;
	public const PASSIVE_MAX_TICKS = 60;

	private ?int $beamTargetId = null;
	/** Server tick until which the guardian is guardian_passive (its target came too close). */
	private int $passiveUntil = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::GUARDIAN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.85, 0.85, 0.425);
	}

	public function getName() : string{
		return "Guardian";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(30);
		$this->setMovementSpeed(0.12);
		parent::initEntity($nbt);
		//minecraft:home: wanders within 16 blocks of where it spawned (its monument)
		$this->setHomeRestriction(self::HOME_RADIUS);
	}

	public function isElder() : bool{
		return false;
	}

	/**
	 * Ticks the laser takes to charge once warmed up.
	 */
	public function getAttackDuration() : int{
		return self::ATTACK_DURATION;
	}

	public function getFollowRange() : float{
		return self::FOLLOW_RANGE;
	}

	protected function getSwimSpeed() : float{
		return self::SWIM_SPEED;
	}

	/**
	 * Holds its depth while it has a target (charging its beam), like vanilla (Guardian.travel() sinks only without
	 * a target) and BDS, where a guardian half a block under the surface stays there and hits a player at the pool
	 * edge: sinking to the bottom hid the player behind the edge.
	 */
	protected function sinksAtRest() : bool{
		return $this->getAttackTarget() === null;
	}

	protected function registerGoals() : void{
		if(!$this->isElder()){
			//guardian_passive: avoid_mob_type players within 8 blocks, priority 1
			$this->goalSelector->addGoal(1, new FreeAvoidEntityGoal($this, $this->swim, $this->findPlayerToAvoid(...), self::AVOID_PLAYER_DISTANCE, $this->getSwimSpeed()));
		}
		$this->goalSelector->addGoal(4, new GuardianAttackGoal($this));
		$this->goalSelector->addGoal(5, new MoveTowardsHomeRestrictionGoal($this, 1.0, $this->swim, $this->getSwimSpeed()));
		$this->goalSelector->addGoal(7, new FreeWanderGoal($this, $this->swim, $this->getSwimSpeed(), 80, 8, 4, FreeWanderGoal::MEDIUM_WATER));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 12.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));

		//attack_interval 1 second; families player, squid and axolotl
		$this->targetSelector->addGoal(1, new NearestLivingTargetGoal($this, fn(Living $e) => $e instanceof Player || $e instanceof Squid || $e instanceof Axolotl, self::FOLLOW_RANGE, interval: 20, candidateClasses: [Player::class, Squid::class, Axolotl::class]));
		$this->targetSelector->addGoal(2, new HurtByTargetGoal($this, self::FOLLOW_RANGE));
	}

	/**
	 * Returns whether the guardian is guardian_passive: its target came within 3 blocks less than 1-3 seconds ago, so it
	 * doesn't charge its laser and swims away from nearby players. Never for elder guardians.
	 */
	public function isTooClose() : bool{
		return $this->passiveUntil >= $this->server->getTick();
	}

	protected function tickAi(int $currentTick) : void{
		//target_nearby_sensor (inside_range 3): only while it has a target, one distance per tick
		if($this->passiveUntil < $currentTick && !$this->isElder()){
			$target = $this->getAttackTarget();
			if($target !== null && $target->getLocation()->distanceSquared($this->getLiveLocation()) <= self::TOO_CLOSE_DISTANCE ** 2){
				$this->passiveUntil = $currentTick + mt_rand(self::PASSIVE_MIN_TICKS, self::PASSIVE_MAX_TICKS);
			}
		}
		parent::tickAi($currentTick);
	}

	/**
	 * The player to swim away from while guardian_passive: the nearest targetable one within 8 blocks.
	 */
	private function findPlayerToAvoid() : ?Player{
		if(!$this->isTooClose()){
			return null;
		}
		$pos = $this->getLiveLocation();
		$nearest = null;
		$nearestDist = self::AVOID_PLAYER_DISTANCE ** 2;
		foreach($this->getWorld()->getPlayers() as $player){
			$dist = $player->getLocation()->distanceSquared($pos);
			if($dist <= $nearestDist && Sensing::isTargetablePlayer($player)){
				$nearest = $player;
				$nearestDist = $dist;
			}
		}
		return $nearest;
	}

	public function getBeamTarget() : ?Living{
		if($this->beamTargetId === null){
			return null;
		}
		$entity = $this->getWorld()->getEntity($this->beamTargetId);
		return $entity instanceof Living ? $entity : null;
	}

	/**
	 * Locks the laser on an entity (null: no beam). The client draws the beam to the entity in TARGET_EID.
	 */
	public function setBeamTarget(?Living $target) : void{
		$this->beamTargetId = $target?->getId();
		parent::setTargetEntity($target);
	}

	public function setAttackTarget(?Living $target) : void{
		parent::setAttackTarget($target);
		//only show the beam while the laser is charging, not whenever the guardian has a target
		parent::setTargetEntity($this->getBeamTarget());
	}

	/**
	 * Called when the laser starts charging (after the warm-up): plays the beam sound on the clients.
	 */
	public function onBeamCharging() : void{
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, ActorEvent::GUARDIAN_ATTACK, 0, null)]);
	}

	/**
	 * Returns the magic damage part of the laser: 1, +2 on hard, +2 for elder guardians (Java GuardianAttackGoal).
	 */
	public function getBeamMagicDamage() : float{
		return 1.0 + ($this->getWorld()->getDifficulty() === World::DIFFICULTY_HARD ? 2.0 : 0.0) + ($this->isElder() ? 2.0 : 0.0);
	}

	/**
	 * Fires the charged laser at the target.
	 */
	public function fireBeam(Living $target) : void{
		$target->attack(new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_MAGIC, $this->getBeamMagicDamage(), [], 0.0));
		$target->noDamageTicks = 0;
		$this->attackMelee($target, self::ATTACK_DAMAGE);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$source instanceof EntityDamageByEntityEvent || $source->getCause() !== EntityDamageEvent::CAUSE_ENTITY_ATTACK){
			return;
		}
		$damager = $source->getDamager();
		if($damager instanceof Living && $this->motion->lengthSquared() < 0.0025){
			//spikes: thorns damage to melee attackers while it stands still
			$damager->attack(new EntityDamageByEntityEvent($this, $damager, EntityDamageEvent::CAUSE_THORNS, 2.0, [], 0.0));
		}
	}

	public function getDrops() : array{
		$drops = [VanillaItems::PRISMARINE_SHARD()->setCount(mt_rand(0, 2) + $this->lootingBonus())];
		$roll = mt_rand(0, 4);
		if($roll < 2){
			$drops[] = VanillaItems::RAW_FISH()->setCount(1 + $this->lootingBonus());
		}elseif($roll < 4){
			$drops[] = VanillaItems::PRISMARINE_CRYSTALS()->setCount(1 + $this->lootingBonus());
		}
		$this->addPlayerKillFish($drops);
		return $drops;
	}

	/**
	 * Killed by a player: 2.5% (+1% per Looting level) a fish from loot_tables/gameplay/fishing/fish.json (cod, salmon,
	 * tropical fish or pufferfish).
	 *
	 * @param Item[] $drops
	 */
	protected function addPlayerKillFish(array &$drops) : void{
		if($this->wasRecentlyHitByPlayerDirectly() && mt_rand(0, 999) < 25 + 10 * $this->getLootingLevel()){
			$drops[] = FishingLoot::fish();
		}
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 10 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::GUARDIAN_SPAWN_EGG();
	}
}
