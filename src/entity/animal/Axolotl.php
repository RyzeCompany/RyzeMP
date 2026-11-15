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

use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MoveToLiquidGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\aquatic\AbstractFish;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\BucketableMobTrait;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\Squid;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function count;
use function floor;
use function mt_rand;

/**
 * Bedrock values (entities/axolotl.json): 14 health, 0.75x0.42, walks at 0.1 and swims at 0.2 (amphibious), attacks
 * (2 damage) fish, squids and tadpoles that are in water, but not for 2 minutes after each hit, and drowned, guardians
 * and elder guardians in water at any time; whoever kills its target gets Regeneration I for 5 seconds and loses
 * Mining Fatigue (combat_regeneration). Bred and tempted with a bucket of tropical fish (gives back a water bucket).
 * Variants (VARIANT): 0 lucy, 1 cyan, 2 gold, 3 wild, 4 blue (only bred, 1/1200). When hurt in water by an attack,
 * explosion, projectile, magic, thorns or wither, it plays dead for 10 seconds with Regeneration (always below 8
 * health or for more than 2 damage, else a 33% chance). Dries out after 5 minutes on land, unless it rains on it, and walks back to water within
 * 16 blocks. Caught with a water bucket.
 */
class Axolotl extends Animal implements BucketableMob{
	use BucketableMobTrait {
		writeBucketData as private writeBaseBucketData;
		applyBucketData as private applyBaseBucketData;
	}

	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_MOISTNESS = "Moistness"; //TAG_Int

	public const VARIANT_LUCY = 0;
	public const VARIANT_CYAN = 1;
	public const VARIANT_GOLD = 2;
	public const VARIANT_WILD = 3;
	public const VARIANT_BLUE = 4;

	private const MAX_MOISTNESS = 6000;
	private const ATTACK_COOLDOWN_TICKS = 2400;
	/** behavior.play_dead duration (also the length of its Regeneration) and force_below_health */
	private const PLAY_DEAD_TICKS = 200;
	private const PLAY_DEAD_HEALTH = 8;
	/** behavior.play_dead random_damage_range max */
	private const PLAY_DEAD_MAX_RANDOM_DAMAGE = 2;
	/** combat_regeneration regeneration_duration */
	private const COMBAT_REGENERATION_TICKS = 100;

	/**
	 * Chunks with axolotls in them: world ID => chunk hash => axolotls (see isAnyNear()).
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private static array $byChunk = [];
	/** World ID and chunk hash this axolotl is counted in (see $byChunk), null if not counted. */
	private ?int $countedWorldId = null;
	private int $countedChunkHash = 0;

	private int $variant = self::VARIANT_LUCY;
	private int $moistness = self::MAX_MOISTNESS;
	private int $playDeadTicks = 0;
	private int $attackCooldownUntil = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::AXOLOTL; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.42, 0.75, 0.25);
	}

	public function getName() : string{
		return "Axolotl";
	}

	/**
	 * @internal Returns whether an axolotl may be within $radius blocks (horizontally) of the given point: whether one
	 * is in a chunk that the square of that radius touches (counted chunks are updated once per tick). Fish flee from
	 * axolotls too; without one near they only look for players (from the world's player list instead of the entities
	 * around them).
	 */
	public static function isAnyNear(World $world, float $x, float $z, float $radius) : bool{
		$chunks = self::$byChunk[$world->getId()] ?? null;
		if($chunks === null){
			return false;
		}
		$maxChunkX = ((int) floor($x + $radius)) >> Chunk::COORD_BIT_SIZE;
		$minChunkZ = ((int) floor($z - $radius)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ((int) floor($z + $radius)) >> Chunk::COORD_BIT_SIZE;
		for($chunkX = ((int) floor($x - $radius)) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				if(isset($chunks[World::chunkHash($chunkX, $chunkZ)])){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Counts the axolotl in the chunk it is in now (see isAnyNear()).
	 */
	private function updateCountedChunk() : void{
		$worldId = $this->location->getWorld()->getId();
		$chunkHash = World::chunkHash(((int) floor($this->location->x)) >> Chunk::COORD_BIT_SIZE, ((int) floor($this->location->z)) >> Chunk::COORD_BIT_SIZE);
		if($worldId === $this->countedWorldId && $chunkHash === $this->countedChunkHash){
			return;
		}
		$this->uncount();
		self::$byChunk[$worldId][$chunkHash] = (self::$byChunk[$worldId][$chunkHash] ?? 0) + 1;
		$this->countedWorldId = $worldId;
		$this->countedChunkHash = $chunkHash;
	}

	private function uncount() : void{
		$worldId = $this->countedWorldId;
		if($worldId === null){
			return;
		}
		$this->countedWorldId = null;
		$chunkHash = $this->countedChunkHash;
		if(!isset(self::$byChunk[$worldId][$chunkHash])){
			return;
		}
		if(--self::$byChunk[$worldId][$chunkHash] <= 0){
			unset(self::$byChunk[$worldId][$chunkHash]);
			if(count(self::$byChunk[$worldId]) === 0){
				unset(self::$byChunk[$worldId]);
			}
		}
	}

	protected function onDispose() : void{
		$this->uncount();
		parent::onDispose();
	}

	protected function setPosition(Vector3 $pos) : bool{
		if(!parent::setPosition($pos)){
			return false;
		}
		//teleported (maybe to another world): counted where it is now at once
		if($this->countedWorldId !== null){
			$this->updateCountedChunk();
		}
		return true;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(14);
		$this->setMovementSpeed(0.1);
		parent::initEntity($nbt);
		$this->variant = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 5 : mt_rand(0, 3);
		$this->moistness = $nbt->getInt(self::TAG_MOISTNESS, self::MAX_MOISTNESS);
		//counted last: an axolotl that fails to load is never counted
		$this->updateCountedChunk();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		$nbt->setInt(self::TAG_MOISTNESS, $this->moistness);
		return $nbt;
	}

	protected function createMoveControl() : MoveControl{
		return new SwimmingMoveControl($this, 0.2);
	}

	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		return $this->isInWater() ? RandomPos::getWaterPos($this, $horizontal, $vertical, $awayFrom) : parent::findWanderPos($horizontal, $vertical, $awayFrom);
	}

	public function canBreathe() : bool{
		return true;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(2, new TemptGoal($this, 1.1, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.0, 2.0, 20.0));
		$this->goalSelector->addGoal(5, new FollowParentGoal($this, 1.1));
		//move_to_water: back to water before drying out
		$this->goalSelector->addGoal(6, new MoveToLiquidGoal($this, MoveToLiquidGoal::WATER, 1.0, 16, 5, 0.1));
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 1.0, 60, 10, 7));
		$this->goalSelector->addGoal(10, new LookAtPlayerGoal($this, 6.0));
		//must_see_forget_duration 17 seconds, reselect_targets; prey (fish, squids, tadpoles) only without attack
		//cooldown, drowned and guardians always; within_radius 20, but both entries have max_dist 8
		$this->targetSelector->addGoal(3, new NearestAttackableEntityGoal($this, fn(Living $e) => (self::isEnemy($e) || (self::isPrey($e) && $this->server->getTick() >= $this->attackCooldownUntil)) && $this->isTargetInWater($e), 8.0, true, 10,
			fn() => !$this->isPlayingDead() && self::worldHasTargets($this->getWorld()), forgetTicks: 17 * 20, reselect: true));
	}

	/**
	 * Whether the world holds any entity of a class {@link Axolotl::isEnemy()} or {@link Axolotl::isPrey()} may accept:
	 * the target search doesn't look around in worlds without any.
	 */
	private static function worldHasTargets(World $world) : bool{
		return $world->hasEntitiesOfClass(AbstractFish::class) || $world->hasEntitiesOfClass(Squid::class) || $world->hasEntitiesOfClass(Tadpole::class) ||
			$world->hasEntitiesOfClass(Drowned::class) || $world->hasEntitiesOfClass(Guardian::class);
	}

	public static function isPrey(Living $entity) : bool{
		return $entity instanceof AbstractFish || $entity instanceof Squid || $entity instanceof Tadpole;
	}

	/**
	 * Whether axolotls attack this mob whenever it is in water, even during their attack cooldown (drowned, guardians
	 * and elder guardians).
	 * @internal
	 */
	public static function isEnemy(Living $entity) : bool{
		return $entity instanceof Drowned || $entity instanceof Guardian;
	}

	private function isTargetInWater(Living $entity) : bool{
		return $entity->isUnderwater() || ($entity instanceof Mob && $entity->isInWater());
	}

	/**
	 * Whether the given damage makes axolotls play dead (vanilla behavior.play_dead damage_sources).
	 */
	private static function isPlayDeadCause(int $cause) : bool{
		return match($cause){
			EntityDamageEvent::CAUSE_CONTACT,
			EntityDamageEvent::CAUSE_ENTITY_ATTACK,
			EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
			EntityDamageEvent::CAUSE_MAGIC,
			EntityDamageEvent::CAUSE_PROJECTILE,
			EntityDamageEvent::CAUSE_THORNS,
			EntityDamageEvent::CAUSE_WITHER => true,
			default => false
		};
	}

	/**
	 * behavior.play_dead random_damage_range {0, 2} and random_start_chance 0.33: damage above the range always starts
	 * it, damage within it by chance, damage below it never.
	 */
	private static function playDeadRoll(float $damage) : bool{
		if($damage > self::PLAY_DEAD_MAX_RANDOM_DAMAGE){
			return true;
		}
		return $damage >= 0 && Utils::getRandomFloat() < 0.33;
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns how many more ticks it can stay out of water before it starts drying out.
	 */
	public function getMoistness() : int{
		return $this->moistness;
	}

	/**
	 * Makes it fully moist again, as water does (splash water bottles).
	 */
	public function rehydrate() : void{
		$this->moistness = self::MAX_MOISTNESS;
	}

	public function isPlayingDead() : bool{
		return $this->playDeadTicks > 0;
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::TROPICAL_FISH_BUCKET;
	}

	protected function consumeFood(Player $player, Item $item) : void{
		$this->exchangeHeldItem($player, VanillaItems::WATER_BUCKET());
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Axolotl($location);
		if(mt_rand(0, 1199) === 0){
			$baby->setVariant(self::VARIANT_BLUE);
		}else{
			$baby->setVariant(mt_rand(0, 1) === 0 || !$partner instanceof Axolotl ? $this->variant : $partner->getVariant());
		}
		return $baby;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		//play_dead: in water, for some kinds of damage only; always below 8 health or for more than 2 damage, a 33% chance
		//for 0-2 damage (random_damage_range)
		if(!$source->isCancelled() && $this->isAlive() && !$this->isPlayingDead() && self::isPlayDeadCause($source->getCause()) && $this->isInWater() &&
			($this->getHealth() < self::PLAY_DEAD_HEALTH || self::playDeadRoll($source->getFinalDamage()))
		){
			$this->playDeadTicks = self::PLAY_DEAD_TICKS;
			$this->effectManager->add(new EffectInstance(VanillaEffects::REGENERATION(), self::PLAY_DEAD_TICKS));
			$this->getNavigation()->stop();
			$this->setAttackTarget(null);
			$this->networkPropertiesDirty = true;
		}
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $baseDamage);
		if($hit){
			//melee_box_attack on_attack: the 2 minute attack cooldown starts with every hit
			$this->attackCooldownUntil = $this->server->getTick() + self::ATTACK_COOLDOWN_TICKS;
		}
		return $hit;
	}

	public function setAttackTarget(?Living $target) : void{
		$previous = $this->attackTarget;
		parent::setAttackTarget($target);
		if($target === null && $previous !== null && !$previous->isAlive()){
			$this->onTargetKilled($previous);
		}
	}

	/**
	 * combat_regeneration: whoever killed the axolotl's target (not the axolotl itself, nor another axolotl) gets
	 * Regeneration I for 5 seconds and loses Mining Fatigue.
	 */
	private function onTargetKilled(Living $target) : void{
		$cause = $target->getLastDamageCause();
		if(!$cause instanceof EntityDamageByEntityEvent){
			return;
		}
		$killer = $cause->getDamager();
		if(!$killer instanceof Living || $killer instanceof Axolotl || !$killer->isAlive()){
			return;
		}
		$killer->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), self::COMBAT_REGENERATION_TICKS));
		$killer->getEffects()->remove(VanillaEffects::MINING_FATIGUE());
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->countedWorldId !== null){
			$this->updateCountedChunk();
		}
		if(!$this->isAlive()){
			return $hasUpdate;
		}
		if($this->playDeadTicks > 0){
			$this->playDeadTicks -= $tickDiff;
			if($this->playDeadTicks <= 0){
				$this->networkPropertiesDirty = true;
			}
		}
		//rain keeps it moist too (checked once a second)
		if($this->isInWater() || (($this->server->getTick() + $this->id) % 20 === 0 && $this->isInRain())){
			$this->moistness = self::MAX_MOISTNESS;
		}else{
			$this->moistness -= $tickDiff;
			if($this->moistness <= 0 && ($this->server->getTick() + $this->id) % 20 === 0){
				$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1));
			}
		}
		return $hasUpdate;
	}

	protected function tickAi(int $currentTick) : void{
		if($this->isPlayingDead()){
			return;
		}
		parent::tickAi($currentTick);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->isBreedingItem($item) && $this->tryPickUpWithBucket($player, $item)){
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function getBucketItem() : Item{
		return VanillaItems::AXOLOTL_BUCKET();
	}

	protected function writeBucketData(CompoundTag $tag) : void{
		$this->writeBaseBucketData($tag);
		$tag->setInt(BucketableMob::BUCKET_TAG_VARIANT, $this->variant);
		$tag->setInt(BucketableMob::BUCKET_TAG_AGE, $this->age);
		$this->saveAgeLock($tag);
	}

	public function applyBucketData(CompoundTag $tag) : void{
		$this->applyBaseBucketData($tag);
		if($tag->getTag(BucketableMob::BUCKET_TAG_VARIANT) !== null){
			$this->setVariant($tag->getInt(BucketableMob::BUCKET_TAG_VARIANT) % 5);
		}
		$this->setAge($tag->getInt(BucketableMob::BUCKET_TAG_AGE, $this->age));
		$this->loadAgeLock($tag);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		$properties->setGenericFlag(EntityMetadataFlags::PLAYING_DEAD, $this->isPlayingDead());
	}
}
