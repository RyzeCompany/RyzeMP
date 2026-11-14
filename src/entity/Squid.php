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

namespace pocketmine\entity;

use pocketmine\entity\animal\Animal;
use pocketmine\entity\animation\SquidInkCloudAnimation;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\player\Player;
use pocketmine\world\mobs\MobDespawner;
use function atan2;
use function cos;
use function floor;
use function mt_getrandmax;
use function mt_rand;
use function sin;
use function sqrt;
use const M_PI;

/**
 * Bedrock values (entities/squid.json): 10 health, 0.8x0.8, swims in water with the squid behaviours (as Java Squid): it
 * picks a random swimming direction from time to time (about every 2.5 seconds, Java SquidRandomMovementGoal: 0.2
 * blocks per tick sideways, -0.1 to 0.1 vertically) and pushes itself that way once per tentacle stroke, slowing down
 * in between. Near the floor it swims up (squid_move_away_from_ground), right under the surface it dives (squid_dive),
 * out of water it sticks to the ground (squid_out_of_water), and when hurt by an entity it squirts ink and swims away
 * from it while it is within 10 blocks, for up to 5 seconds (squid_flee, Java SquidFleeGoal).
 *
 * Squids are not {@link Mob}s (Squid and {@link WaterAnimal} are PocketMine API classes) but behave like them where it
 * matters: they can be led ({@link Leashable}), are persistent unless the world spawned them, and the others despawn
 * by themselves like mobs (despawn_from_distance, see {@link MobDespawner}), also after their chunk was saved and
 * loaded again. 5% spawn as babies (half size, grow up in 20 minutes, no experience). Loot
 * (loot_tables/entities/squid.json): 1-3 ink sacs (+0-1 per Looting level). Experience 1-3 when an adult is killed by a
 * player.
 */
class Squid extends WaterAnimal implements Leashable{
	use LeashTrait;

	/** minecraft:entity_spawned: 5 in 100 spawn as babies (squid_baby). */
	public const SPAWN_BABY_CHANCE = 5;
	/** squid_baby minecraft:ageable duration (1200 seconds). */
	public const BABY_GROW_TICKS = 24000;
	public const BABY_SCALE = 0.5;
	/** Ticks a player hit counts for experience and loot (vanilla last_hit_by_player). */
	private const PLAYER_KILL_TICKS = 100;
	/** Java SquidFleeGoal: flees while the attacker is within 10 blocks (and remembered: 5 seconds). */
	private const FLEE_DISTANCE = 10.0;
	private const FLEE_TICKS = 100;
	/** One in this many ticks a new random direction is picked (Java SquidRandomMovementGoal). */
	private const DIRECTION_CHANCE = 50;
	/**
	 * Ticks between the checks of the floor below and the surface above: a squid rising at full speed can't get its
	 * eyes out of the water in between.
	 */
	private const SURROUNDINGS_INTERVAL = 4;

	public static function getNetworkTypeId() : string{ return EntityIds::SQUID; }

	/** Direction of the swimming push (see {@link Squid::$swimSpeed}), null when there is none. */
	public ?Vector3 $swimDirection = null;
	/** Blocks per tick of the push in {@link Squid::$swimDirection}. */
	public float $swimSpeed = 0.1;

	/** Tentacle stroke angle (0 to 2 pi) and how fast it advances (Java Squid). */
	private float $tentacleMovement = 0.0;
	private float $tentacleSpeed = 0.1;

	/** Persistent squids never despawn (spawn eggs, commands, plugins); the natural spawner makes them not persistent. */
	private bool $persistent = true;
	private int $despawnFarTicks = 0;
	private int $despawnCheckTick = -1;
	/** Ticks left before the baby grows up, 0 for adults. */
	private int $growTicks = 0;

	private ?Player $lastPlayerHit = null;
	private int $lastPlayerHitTick = -1;
	/** Entity the squid flees from, until the server tick in $fleeUntil. */
	private ?Entity $fleeFrom = null;
	private int $fleeUntil = -1;
	/** Whether the lead pulled the squid this tick (its own push then waits). */
	private bool $leashPulled = false;

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.8, 0.8); }

	public function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		parent::initEntity($nbt);
		$this->tentacleSpeed = self::newTentacleSpeed();

		if($nbt->getTag(Mob::TAG_PERSISTENT) !== null){
			$this->persistent = $nbt->getByte(Mob::TAG_PERSISTENT) !== 0;
		}else{
			//saved before squids had the tag: spawned by the world (they used to pile up), so they may despawn now
			$this->persistent = $nbt->getTag(self::TAG_POS) === null;
		}
		$this->loadLeash($nbt);

		if($nbt->getByte(Animal::TAG_IS_BABY, 0) !== 0){
			$age = $nbt->getInt(Animal::TAG_AGE, 0);
			$this->setBabyAge($age < 0 ? -$age : self::BABY_GROW_TICKS);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(Mob::TAG_PERSISTENT, $this->persistent ? 1 : 0);
		$this->saveLeash($nbt);
		$nbt->setByte(Animal::TAG_IS_BABY, $this->baby ? 1 : 0);
		$nbt->setInt(Animal::TAG_AGE, -$this->growTicks);
		return $nbt;
	}

	public function getName() : string{
		return "Squid";
	}

	public function isPersistent() : bool{
		return $this->persistent;
	}

	public function setPersistent(bool $persistent) : void{
		$this->persistent = $persistent;
	}

	/**
	 * Returns whether the squid may despawn when far from players: not persistent, not named and not led.
	 */
	public function canDespawn() : bool{
		return !$this->persistent && $this->nameTag === "" && !$this->isLeashed();
	}

	public function canBeLeashed() : bool{
		return true;
	}

	/**
	 * Turns this newly created squid into a baby (5% of the spawned squids, see {@link BabySpawning}).
	 * @internal
	 */
	public function makeSpawnBaby() : bool{
		$this->setBabyAge(self::BABY_GROW_TICKS);
		return true;
	}

	/**
	 * Ticks left before the baby grows up (0 for adults).
	 */
	public function getGrowTicks() : int{
		return $this->growTicks;
	}

	/**
	 * Makes the squid a baby that grows up in $ticks ticks, or an adult for 0.
	 */
	private function setBabyAge(int $ticks) : void{
		$this->growTicks = $ticks > 0 ? $ticks : 0;
		$baby = $this->growTicks > 0;
		if($baby === $this->baby){
			return;
		}
		$this->baby = $baby;
		$this->setScale($baby ? self::BABY_SCALE : 1.0);
		if($baby){
			//squid_baby collision_box 1x1 at scale 0.5
			$this->setSize((new EntitySizeInfo(1.0, 1.0))->scale(self::BABY_SCALE));
		}
		$this->networkPropertiesDirty = true;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled()){
			return;
		}

		if($source instanceof EntityDamageByEntityEvent){
			$e = $source->getDamager();
			if($e !== null){
				$now = $this->server->getTick();
				if($e instanceof Player){
					$this->lastPlayerHit = $e;
					$this->lastPlayerHitTick = $now;
				}
				//squid_flee
				$this->fleeFrom = $e;
				$this->fleeUntil = $now + self::FLEE_TICKS;
				$this->swimSpeed = mt_rand(150, 350) / 2000;
				$this->swimDirection = $this->location->subtractVector($e->location)->normalize();
			}

			$this->broadcastAnimation(new SquidInkCloudAnimation($this));
		}
	}

	protected function onDeath() : void{
		parent::onDeath();
		$this->unleash(true);
	}

	/**
	 * Returns whether a player hit the squid within the last 5 seconds (vanilla last_hit_by_player).
	 */
	private function wasRecentlyHitByPlayer() : bool{
		return $this->lastPlayerHit !== null && $this->server->getTick() - $this->lastPlayerHitTick <= self::PLAYER_KILL_TICKS;
	}

	/**
	 * Vanilla looting_enchant (count 0-1 per level): extra items for the Looting level of the player who killed it.
	 */
	protected function lootingBonus() : int{
		$player = $this->lastPlayerHit;
		if($player === null || !$this->wasRecentlyHitByPlayer() || !$player->isConnected()){
			return 0;
		}
		$level = $player->getInventory()->getItemInHand()->getEnchantmentLevel(VanillaEnchantments::LOOTING());
		return $level > 0 ? mt_rand(0, $level) : 0;
	}

	public function getXpDropAmount() : int{
		return !$this->baby && $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	private static function newTentacleSpeed() : float{
		return 1 / (mt_rand() / mt_getrandmax() + 1) * 0.2;
	}

	private function generateRandomDirection() : Vector3{
		//Java SquidRandomMovementGoal: 0.2 sideways in a random direction, -0.1 to 0.1 vertically
		$angle = mt_rand() / mt_getrandmax() * 2 * M_PI;
		$x = cos($angle) * 0.2;
		$y = -0.1 + mt_rand() / mt_getrandmax() * 0.2;
		$z = sin($angle) * 0.2;
		$this->swimSpeed = sqrt($x * $x + $y * $y + $z * $z);
		return new Vector3($x / $this->swimSpeed, $y / $this->swimSpeed, $z / $this->swimSpeed);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $hasUpdate;
		}

		$now = $this->server->getTick();
		if($now - $this->despawnCheckTick >= MobDespawner::CHECK_INTERVAL){
			$this->despawnCheckTick = $now;
			if($this->canDespawn() && MobDespawner::shouldDespawn($this, $this->despawnFarTicks, MobDespawner::CHECK_INTERVAL)){
				$this->flagForDespawn();
				return false;
			}
		}
		if($this->growTicks > 0){
			$this->setBabyAge($this->growTicks - $tickDiff);
		}
		if($this->isLeashed()){
			$this->tickLeash();
			if($this->closed || !$this->isAlive()){
				return $hasUpdate;
			}
		}

		$this->updateSwimming($now);
		return true;
	}

	/**
	 * The squid behaviours: picks the swimming direction and pushes once per tentacle stroke (Java Squid.aiStep()).
	 */
	private function updateSwimming(int $now) : void{
		$inWater = $this->isUnderwater();
		$this->setHasGravity(!$inWater);
		if(!$inWater){
			//squid_out_of_water: sticks to the ground
			$this->swimDirection = null;
			$this->fleeFrom = null;
			if($this->motion->x !== 0.0 || $this->motion->z !== 0.0){
				$this->motion = new Vector3(0, $this->motion->y, 0);
			}
			return;
		}

		if($this->fleeFrom !== null){
			$this->updateFleeing($now);
		}
		if($this->fleeFrom === null && ($this->swimDirection === null || mt_rand(1, self::DIRECTION_CHANCE) === 1)){
			$this->swimDirection = $this->generateRandomDirection();
		}
		if(($now + $this->id) % self::SURROUNDINGS_INTERVAL === 0){
			$this->avoidFloorAndSurface();
		}

		//one push per tentacle stroke, in the last quarter of its first half; slowing down in the second half
		$this->tentacleMovement += $this->tentacleSpeed;
		if($this->tentacleMovement > 2 * M_PI){
			$this->tentacleMovement -= 2 * M_PI;
			if(mt_rand(0, 9) === 0){
				$this->tentacleSpeed = self::newTentacleSpeed();
			}
		}
		if($this->leashPulled){
			$this->leashPulled = false; //the pull of the lead is kept
		}elseif($this->tentacleMovement < M_PI){
			if($this->tentacleMovement > 0.75 * M_PI && $this->swimDirection !== null){
				$this->motion = $this->swimDirection->multiply($this->swimSpeed);
			}
		}else{
			$this->motion = $this->motion->multiply(0.9);
		}

		$mx = $this->motion->x;
		$my = $this->motion->y;
		$mz = $this->motion->z;
		if($mx * $mx + $my * $my + $mz * $mz > 1.0e-4){
			$f = sqrt($mx * $mx + $mz * $mz);
			$this->setRotation(-atan2($mx, $mz) * 180 / M_PI, -atan2($f, $my) * 180 / M_PI);
		}
	}

	/**
	 * squid_flee (Java SquidFleeGoal): swims away from the attacker, faster when it is close, while it is within 10
	 * blocks.
	 */
	private function updateFleeing(int $now) : void{
		$from = $this->fleeFrom;
		if($from === null || $now > $this->fleeUntil || $from->isClosed() || !$from->isAlive() || $from->getWorld() !== $this->getWorld()){
			$this->fleeFrom = null;
			return;
		}
		$dx = $this->location->x - $from->location->x;
		$dy = $this->location->y - $from->location->y;
		$dz = $this->location->z - $from->location->z;
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($length >= self::FLEE_DISTANCE){
			$this->fleeFrom = null;
			return;
		}
		$flags = self::blockFlagsAt($this->getWorld(), (int) floor($this->location->x + $dx), (int) floor($this->location->y + $dy), (int) floor($this->location->z + $dz));
		$water = ($flags & self::BLOCK_FLAG_WATER) !== 0;
		if(!$water && ($flags & self::BLOCK_FLAG_SOLID_OPAQUE) !== 0){
			return; //Java: only towards water or air
		}
		if($length > 0){
			//3 blocks per second when close, slower from 5 blocks away
			$speed = $length > 5 ? 3 - ($length - 5) / 5 : 3.0;
			$dx = $dx / $length * $speed;
			$dy = $dy / $length * $speed;
			$dz = $dz / $length * $speed;
		}
		if(!$water){
			$dy = 0.0; //not out of the water
		}
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($length > 0){
			$this->swimSpeed = $length / 20;
			$this->swimDirection = new Vector3($dx / $length, $dy / $length, $dz / $length);
		}
	}

	/**
	 * squid_move_away_from_ground and squid_dive: swims up when it would sink into the floor right below, and down when
	 * the surface is right above.
	 */
	private function avoidFloorAndSurface() : void{
		$direction = $this->swimDirection;
		if($direction === null){
			return;
		}
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		if($direction->y < 0){
			if((self::blockFlagsAt($world, $x, (int) floor($this->location->y) - 1, $z) & self::BLOCK_FLAG_SOLID_OPAQUE) !== 0){
				$this->swimDirection = $direction->withComponents(null, -$direction->y, null);
			}
		}elseif((self::blockFlagsAt($world, $x, (int) floor($this->location->y + $this->size->getHeight()) + 1, $z) & self::BLOCK_FLAG_WATER) === 0){
			$this->swimDirection = $direction->withComponents(null, -0.5, null)->normalize();
			if($this->motion->y > 0){
				//dives at once rather than drifting out of the water (its eyes would leave it: no breathing)
				$this->motion = $this->motion->withComponents(null, -$this->motion->y, null);
			}
		}
	}

	public function onFrozenUpdate(int $currentTick) : bool{
		if($this->justCreated){
			return parent::onFrozenUpdate($currentTick);
		}
		parent::onFrozenUpdate($currentTick);
		//beyond the simulation distance of every player: only the instant despawn (like mobs)
		if($currentTick - $this->despawnCheckTick >= MobDespawner::CHECK_INTERVAL){
			$this->despawnCheckTick = $currentTick;
			if($this->canDespawn() && MobDespawner::shouldDespawnWhileFrozen($this)){
				$this->flagForDespawn();
			}
		}
		return true;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$item = $player->getInventory()->getItemInHand();
		return LeashInteractions::interact($this, $player, $item) || $this->interactLeash($player, $item) || parent::onInteract($player, $clickPos);
	}

	/**
	 * Removes items from the stack in the player's hand (not in creative).
	 */
	protected function consumeHeldItem(Player $player, int $count = 1) : void{
		if(!$player->hasFiniteResources()){
			return;
		}
		$item = $player->getInventory()->getItemInHand();
		$item->pop($count);
		$player->getInventory()->setItemInHand($item);
	}

	/**
	 * Swims towards the holder of the lead.
	 */
	private function followLeashHolder(Entity $holder) : void{
		if($this->fleeFrom !== null){
			return;
		}
		$target = $holder->getPosition();
		$dx = $target->x - $this->location->x;
		$dy = $target->y - $this->location->y;
		$dz = $target->z - $this->location->z;
		$length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($length > 0){
			$this->swimSpeed = 0.2;
			$this->swimDirection = new Vector3($dx / $length, $dy / $length, $dz / $length);
		}
	}

	private function stopFollowingLeashHolder() : void{
		$this->leashPulled = true;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncLeashData($properties);
	}

	public function getDrops() : array{
		return [
			VanillaItems::INK_SAC()->setCount(mt_rand(1, 3) + $this->lootingBonus())
		];
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::SQUID_SPAWN_EGG();
	}

	protected function destroyCycles() : void{
		$this->lastPlayerHit = null;
		$this->fleeFrom = null;
		parent::destroyCycles();
	}
}
