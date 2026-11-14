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

use pocketmine\block\Beehive;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Crops;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Beehive as TileBeehive;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\entity\ai\FlyingMobTrait;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function floor;
use function mt_rand;

/**
 * Bedrock values (entities/bee.json): 10 health, 0.55x0.5, flies (flying speed 0.15, 1.4x when angry), babies scale
 * 0.5. Hurt by a player, it gets angry (ANGRY, 25 seconds, refreshed by every hit) and so do the bees within 20 blocks
 * that haven't stung yet; it stings once: 2 damage plus poison (10 s on normal, 18 s on hard, none on easy), then loses
 * its stinger (MARK_VARIANT 1), panics and dies 10-60 seconds later. Bees leaving a disturbed hive (honey harvested
 * without a campfire below, hive broken) go for the nearest player within 10 blocks for 5 seconds.
 *
 * Without nectar it looks for flowers within 6 blocks and pollinates one for a second (actor property
 * minecraft:has_nectar); with nectar it grows the crops it flies over (up to 10) and goes back to its hive, as it does
 * at night and in the rain in the Overworld; it finds a free hive or nest within 16 blocks if it has none, and wanders
 * within 22 blocks of it (minecraft:home). Tempted (1.25x) and bred with flowers; feeding it an open eyeblossom gives it
 * Poison for 25 seconds, a wither rose Wither for 40 seconds. No loot; XP 1-3.
 */
class Bee extends Animal{
	use FlyingMobTrait;

	public const TAG_HAS_NECTAR = "HasNectar"; //TAG_Byte
	public const TAG_HAS_STUNG = "HasStung"; //TAG_Byte
	public const TAG_ANGER_TIME = "AngerTime"; //TAG_Int
	public const TAG_HIVE_X = "HivePosX"; //TAG_Int
	public const TAG_HIVE_Y = "HivePosY"; //TAG_Int
	public const TAG_HIVE_Z = "HivePosZ"; //TAG_Int
	public const TAG_PERISH_TIME = "PerishTime"; //TAG_Int

	/** minecraft:angry duration (25 s). */
	public const ANGER_TICKS = 500;
	/** minecraft:angry broadcastRange. */
	public const ANGER_BROADCAST_RANGE = 20.0;
	/** countdown_to_perish timer (10-60 s). */
	public const PERISH_MIN_TICKS = 200;
	public const PERISH_MAX_TICKS = 1200;
	/** minecraft:home restriction_radius. */
	public const HOME_RADIUS = 22;
	/** take_nearest_target: nearest player within 10 blocks, looked for during 5 seconds. */
	public const NEAREST_TARGET_RANGE = 10.0;
	public const NEAREST_TARGET_TICKS = 100;

	/**
	 * Block types bees breed with, are tempted by and pollinate (bee.json breed_items / tempt items / target_blocks).
	 * Open eyeblossoms are only tempting and pollinated (see {@link Bee::isPollinatable()}).
	 */
	private const FLOWERS = [
		BlockTypeIds::POPPY => true,
		BlockTypeIds::BLUE_ORCHID => true,
		BlockTypeIds::ALLIUM => true,
		BlockTypeIds::AZURE_BLUET => true,
		BlockTypeIds::RED_TULIP => true,
		BlockTypeIds::ORANGE_TULIP => true,
		BlockTypeIds::WHITE_TULIP => true,
		BlockTypeIds::PINK_TULIP => true,
		BlockTypeIds::OXEYE_DAISY => true,
		BlockTypeIds::CORNFLOWER => true,
		BlockTypeIds::LILY_OF_THE_VALLEY => true,
		BlockTypeIds::DANDELION => true,
		BlockTypeIds::WITHER_ROSE => true,
		BlockTypeIds::SUNFLOWER => true,
		BlockTypeIds::LILAC => true,
		BlockTypeIds::ROSE_BUSH => true,
		BlockTypeIds::PEONY => true,
		BlockTypeIds::FLOWERING_AZALEA => true,
		BlockTypeIds::FLOWERING_AZALEA_LEAVES => true,
		BlockTypeIds::MANGROVE_PROPAGULE => true,
		BlockTypeIds::PITCHER_PLANT => true,
		BlockTypeIds::TORCHFLOWER => true,
		BlockTypeIds::CHERRY_LEAVES => true,
		BlockTypeIds::PINK_PETALS => true,
		BlockTypeIds::WILDFLOWERS => true,
		BlockTypeIds::CACTUS_FLOWER => true,
		BlockTypeIds::CHORUS_FLOWER => true,
		BlockTypeIds::SPORE_BLOSSOM => true
	];

	/**
	 * Block state ID => whether a block of this state may be a flower to pollinate (see
	 * {@link Bee::isFlowerCandidateState()}). States never change meaning while the server runs.
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private static array $flowerCandidateStates = [];

	private bool $nectar = false;
	private bool $stung = false;
	private int $angerTicks = 0;
	private int $perishTicks = -1;
	private ?Vector3 $hive = null;
	private int $cropsGrown = 0;
	/** Ticks before the bee may go home again after leaving its hive. */
	private int $stayOutTicks = 0;
	/** Ticks left to look for the nearest player to sting (take_nearest_target, after leaving a disturbed hive). */
	private int $nearestTargetTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::BEE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.5, 0.55, 0.3);
	}

	public function getName() : string{
		return "Bee";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function getFlyingSpeed() : float{
		return 0.15;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->initFlying();
		$this->nectar = $nbt->getByte(self::TAG_HAS_NECTAR, 0) !== 0;
		$this->stung = $nbt->getByte(self::TAG_HAS_STUNG, 0) !== 0;
		$this->angerTicks = $nbt->getInt(self::TAG_ANGER_TIME, 0);
		$this->perishTicks = $nbt->getInt(self::TAG_PERISH_TIME, -1);
		if($nbt->getTag(self::TAG_HIVE_X) !== null){
			$this->assignHive(new Vector3($nbt->getInt(self::TAG_HIVE_X), $nbt->getInt(self::TAG_HIVE_Y), $nbt->getInt(self::TAG_HIVE_Z)));
		}
		$this->stayOutTicks = 400;
		$this->setActorProperty("minecraft:has_nectar", $this->nectar);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_HAS_NECTAR, $this->nectar ? 1 : 0);
		$nbt->setByte(self::TAG_HAS_STUNG, $this->stung ? 1 : 0);
		$nbt->setInt(self::TAG_ANGER_TIME, $this->angerTicks);
		$nbt->setInt(self::TAG_PERISH_TIME, $this->perishTicks);
		if($this->hive !== null){
			$nbt->setInt(self::TAG_HIVE_X, $this->hive->getFloorX());
			$nbt->setInt(self::TAG_HIVE_Y, $this->hive->getFloorY());
			$nbt->setInt(self::TAG_HIVE_Z, $this->hive->getFloorZ());
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		//countdown_to_perish: a bee that has stung flies around in panic (force) until it dies
		$this->goalSelector->addGoal(1, new class($this, 1.25) extends PanicGoal{
			public function __construct(private Bee $bee, float $speedMultiplier){
				parent::__construct($bee, $speedMultiplier);
			}

			protected function shouldPanic() : bool{
				return $this->bee->hasStung() || $this->bee->isOnFire();
			}
		});
		$this->goalSelector->addGoal(1, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(2, new MeleeAttackGoal($this, 1.4, 2.0, 32.0));
		$this->goalSelector->addGoal(4, new class($this) extends Goal{
			public function __construct(private Bee $bee){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{ return $this->bee->wantsToGoHome(); }

			public function canContinue() : bool{ return $this->bee->wantsToGoHome() && !$this->bee->getNavigation()->isDone(); }

			public function start() : void{
				$hive = $this->bee->getHivePosition();
				if($hive !== null){
					$this->bee->getNavigation()->moveTo($hive->x + 0.5, $hive->y + 0.5, $hive->z + 0.5, 1.0, 0.7);
				}
			}

			public function stop() : void{
				$this->bee->tryEnterHive();
			}
		});
		$this->goalSelector->addGoal(5, new TemptGoal($this, 1.25, fn(Item $item) => self::isPollinatable($item->getBlock()), 8.0));
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(9, new MoveTowardsHomeRestrictionGoal($this, 1.0, retryCooldown: 100));
		$this->goalSelector->addGoal(10, new class($this) extends Goal{
			private ?Vector3 $flower = null;
			private int $stayTicks = 0;

			public function __construct(private Bee $bee){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{
				if($this->bee->hasNectar() || $this->bee->isAngry() || $this->bee->hasStung() || mt_rand(0, 4) !== 0 || Bee::isShelterTime($this->bee->getWorld())){
					return false;
				}
				$this->flower = $this->bee->findFlower();
				return $this->flower !== null;
			}

			public function canContinue() : bool{
				return $this->flower !== null && !$this->bee->hasNectar() && $this->stayTicks < 600;
			}

			public function start() : void{
				$this->stayTicks = 0;
			}

			public function tick() : void{
				$flower = $this->flower;
				if($flower === null){
					return;
				}
				++$this->stayTicks;
				$loc = $this->bee->getLiveLocation();
				$target = $flower->add(0.5, 0.6, 0.5);
				$distanceSq = $loc->distanceSquared($target);
				if($distanceSq > 0.6 * 0.6){
					$navigation = $this->bee->getNavigation();
					if($this->stayTicks % 10 === 1){
						if(!$navigation->moveTo($target->x, $target->y, $target->z, 1.0)){
							//no way there a moment ago: forget the flower
							$this->flower = null;
						}
					}elseif($distanceSq > 1.5 * 1.5 && $navigation->isDone()){
						//the navigation gave up (behind glass, the end of a partial path): forget the flower
						$this->flower = null;
					}
					return;
				}
				$this->bee->getNavigation()->stop();
				if(++$this->stayTicks % 40 === 0 || mt_rand(0, 29) === 0){
					if(Bee::isPollinatable($this->bee->getWorld()->getBlock($flower))){
						$this->bee->setHasNectar(true);
						$this->bee->playMobSound(LevelSoundEvent::AMBIENT_POLLINATE);
					}
					$this->flower = null;
				}
			}

			public function stop() : void{
				$this->flower = null;
				$this->bee->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(12, new RandomStrollGoal($this, 1.0, 60, 8, 2));
		//broadcast_anger to the bees within 20 blocks, except those that have stung (family "pacified")
		$this->targetSelector->addGoal(1, new HurtByTargetGoal(
			$this,
			32.0,
			alertRange: self::ANGER_BROADCAST_RANGE,
			alertFilter: fn(Mob $mob) => $mob instanceof Bee && !$mob->hasStung(),
			angerTicks: self::ANGER_TICKS
		));
	}

	public function hasNectar() : bool{
		return $this->nectar;
	}

	public function setHasNectar(bool $nectar) : void{
		$this->nectar = $nectar;
		if(!$nectar){
			$this->cropsGrown = 0;
		}
		$this->setActorProperty("minecraft:has_nectar", $nectar);
	}

	public function hasStung() : bool{
		return $this->stung;
	}

	public function isAngry() : bool{
		return $this->angerTicks > 0;
	}

	/**
	 * Returns the ticks left before a bee that has stung dies, -1 if it hasn't stung.
	 */
	public function getPerishTicks() : int{
		return $this->perishTicks;
	}

	public function getHivePosition() : ?Vector3{
		return $this->hive;
	}

	public function setHivePosition(?Vector3 $hive) : void{
		$this->assignHive($hive);
		$this->stayOutTicks = 400;
	}

	/**
	 * The hive is also the bee's home (minecraft:home): it wanders within {@link Bee::HOME_RADIUS} blocks of it.
	 */
	private function assignHive(?Vector3 $hive) : void{
		$this->hive = $hive?->floor();
		$this->setHomePos($this->hive);
	}

	public function getHomeRadius() : int{
		return $this->hive !== null ? self::HOME_RADIUS : 0;
	}

	public function isNight() : bool{
		return self::isNightIn($this->getWorld());
	}

	private static function isNightIn(World $world) : bool{
		$time = $world->getTimeOfDay();
		return $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
	}

	/**
	 * Returns whether bees stay in (or go back to) their hives in the world (Bedrock shelter_detection): at night or
	 * while it rains, in the Overworld only.
	 */
	public static function isShelterTime(World $world) : bool{
		return $world->getDimension() === Dimension::OVERWORLD && (self::isNightIn($world) || $world->getWeather()->isRaining());
	}

	/**
	 * Returns whether bees pollinate the block and are tempted by it as an item (bee.json target_blocks / tempt items).
	 * Waterlogged flowers are left out by {@link Bee::findFlower()}.
	 */
	public static function isPollinatable(Block $block) : bool{
		$typeId = $block->getTypeId();
		return isset(self::FLOWERS[$typeId]) || $typeId === BlockTypeIds::OPEN_EYEBLOSSOM;
	}

	/**
	 * Returns whether World::getBlockAt() may give a block bees pollinate for a position holding this block state: its
	 * type is pollinatable, or its class works out its state from the world (readStateFromWorld(), e.g. banners turning
	 * ominous), which could change the block. States of other blocks never give a flower.
	 */
	private static function isFlowerCandidateState(int $stateId) : bool{
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return self::isPollinatable($block) || (new \ReflectionMethod($block, 'readStateFromWorld'))->getDeclaringClass()->getName() !== Block::class;
	}

	/**
	 * Looks for a flower within 6 blocks horizontally and 4 vertically (a few random tries), not waterlogged.
	 */
	public function findFlower() : ?Vector3{
		$world = $this->getWorld();
		$minY = $world->getMinY();
		$maxY = $world->getMaxY();
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		for($i = 0; $i < 24; ++$i){
			$x = $bx + mt_rand(-6, 6);
			$y = $by + mt_rand(-4, 2);
			$z = $bz + mt_rand(-6, 6);
			//most tries hit air or grass: the block is only made for states that may give a flower (World::getBlockAt()
			//gives air outside the world's height and in unloaded chunks)
			if($y < $minY || $y >= $maxY){
				continue;
			}
			$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk === null){
				continue;
			}
			$stateId = $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
			if(!(self::$flowerCandidateStates[$stateId] ??= self::isFlowerCandidateState($stateId))){
				continue;
			}
			$block = $world->getBlockAt($x, $y, $z);
			if(self::isPollinatable($block) && !LiquidLayer::holdsWater($block)){
				return new Vector3($x, $y, $z);
			}
		}
		return null;
	}

	public function wantsToGoHome() : bool{
		if($this->stayOutTicks > 0 || $this->isAngry() || $this->stung || $this->nearestTargetTicks > 0){
			return false;
		}
		if($this->hive === null){
			return false;
		}
		return $this->nectar || self::isShelterTime($this->getWorld());
	}

	/**
	 * Enters the hive if the bee is next to it. Returns whether it did.
	 */
	public function tryEnterHive() : bool{
		if($this->hive === null || $this->location->distanceSquared($this->hive->add(0.5, 0.5, 0.5)) > 2.5 * 2.5){
			return false;
		}
		$block = $this->getWorld()->getBlock($this->hive);
		if(!$block instanceof Beehive){
			$this->assignHive(null);
			return false;
		}
		if(!$block->addBee($this)){
			$this->stayOutTicks = 400;
			return false;
		}
		return true;
	}

	/**
	 * Looks for a hive or nest with room within 16 blocks horizontally and 10 vertically.
	 */
	private function findHive() : ?Vector3{
		$world = $this->getWorld();
		$loc = $this->location;
		$best = null;
		$bestDist = 16.0 * 16.0 * 3;
		//hives are block entities: look through the tiles of the nearby chunks instead of every block
		for($cx = ((int) floor($loc->x) - 16) >> Chunk::COORD_BIT_SIZE; $cx <= ((int) floor($loc->x) + 16) >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = ((int) floor($loc->z) - 16) >> Chunk::COORD_BIT_SIZE; $cz <= ((int) floor($loc->z) + 16) >> Chunk::COORD_BIT_SIZE; ++$cz){
				$chunk = $world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getTiles() as $tile){
					if(!$tile instanceof TileBeehive || $tile->isFull()){
						continue;
					}
					$pos = $tile->getPosition();
					if(abs($pos->x - $loc->x) > 16 || abs($pos->z - $loc->z) > 16 || abs($pos->y - $loc->y) > 10){
						continue;
					}
					$d = $pos->distanceSquared($loc);
					if($d < $bestDist){
						$best = $pos->asVector3();
						$bestDist = $d;
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Makes the bee angry at the entity (and lets it sting it) for 25 seconds.
	 */
	public function becomeAngryAt(Living $target) : void{
		if($this->stung){
			return;
		}
		$this->angerTicks = self::ANGER_TICKS;
		$this->nearestTargetTicks = 0;
		$this->setAttackTarget($target);
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Bedrock take_nearest_target (bees leaving a disturbed hive, or near a hive broken without Silk Touch): for the
	 * next 5 seconds the bee gets angry at the nearest player within 10 blocks. Bees that have stung are left out.
	 */
	public function takeNearestTarget() : void{
		if($this->stung || $this->isAngry()){
			return;
		}
		$this->nearestTargetTicks = self::NEAREST_TARGET_TICKS;
		$this->tryTakeNearestTarget();
	}

	private function tryTakeNearestTarget() : void{
		$loc = $this->location;
		$best = null;
		$bestDist = self::NEAREST_TARGET_RANGE * self::NEAREST_TARGET_RANGE;
		//the players of the world: a short list, no entity search
		foreach($this->getWorld()->getPlayers() as $player){
			$d = $player->getPosition()->distanceSquared($loc);
			if($d <= $bestDist && $this->getSensing()->isValidTarget($player, self::NEAREST_TARGET_RANGE)){
				$best = $player;
				$bestDist = $d;
			}
		}
		if($best !== null){
			$this->becomeAngryAt($best);
		}
	}

	public function alertTo(Living $target) : void{
		parent::alertTo($target);
		$this->becomeAngryAt($target);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$this->isAlive() || !$source instanceof EntityDamageByEntityEvent || !($damager = $source->getDamager()) instanceof Living){
			return;
		}
		//the bees around join in through the HurtByTargetGoal (broadcast_anger)
		$this->becomeAngryAt($damager);
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		if($this->stung){
			return false;
		}
		$hit = parent::attackMelee($target, $baseDamage);
		if($hit){
			$duration = match($this->getWorld()->getDifficulty()){
				World::DIFFICULTY_NORMAL => 200,
				World::DIFFICULTY_HARD => 360,
				default => 0
			};
			if($duration > 0){
				$target->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), $duration));
			}
			$this->stung = true;
			$this->perishTicks = mt_rand(self::PERISH_MIN_TICKS, self::PERISH_MAX_TICKS);
			$this->angerTicks = 0;
			$this->nearestTargetTicks = 0;
			$this->setAttackTarget(null);
			$this->networkPropertiesDirty = true;
		}
		return $hit;
	}

	public function setAttackTarget(?Living $target) : void{
		parent::setAttackTarget($this->stung || ($target !== null && !$this->isAngry()) ? null : $target);
	}

	public function isBreedingItem(Item $item) : bool{
		return isset(self::FLOWERS[$item->getBlock()->getTypeId()]);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		$typeId = $item->getBlock()->getTypeId();
		if($typeId === BlockTypeIds::OPEN_EYEBLOSSOM){
			//fed_open_eyeblossom
			$this->addFedEffect(new EffectInstance(VanillaEffects::POISON(), 500));
			$this->consumeHeldItem($player);
			return true;
		}
		if($typeId === BlockTypeIds::WITHER_ROSE){
			//fed_wither_rose; the rose is a breeding item too
			$this->addFedEffect(new EffectInstance(VanillaEffects::WITHER(), 800));
			if(!parent::onInteractWithItem($player, $item)){
				$this->consumeHeldItem($player);
			}
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	/**
	 * spell_effects: the effect replaces any effect of the same type.
	 */
	private function addFedEffect(EffectInstance $effect) : void{
		$effects = $this->getEffects();
		$effects->remove($effect->getType());
		$effects->add($effect);
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Bee($location);
		$baby->setHivePosition($this->hive);
		return $baby;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $hasUpdate;
		}
		if($this->stayOutTicks > 0){
			$this->stayOutTicks -= $tickDiff;
		}
		if($this->angerTicks > 0 && ($this->angerTicks -= $tickDiff) <= 0){
			//calmed down: the target is dropped even if no HurtByTargetGoal holds it (hive disturbance)
			$this->setAttackTarget(null);
			$this->networkPropertiesDirty = true;
		}
		if($this->perishTicks > 0 && ($this->perishTicks -= $tickDiff) <= 0){
			$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_MAGIC, 1000));
			return $hasUpdate;
		}
		$phase = ($this->server->getTick() + $this->id) % 40;
		if($this->nearestTargetTicks > 0){
			$this->nearestTargetTicks -= $tickDiff;
			if($phase % 10 === 5 && !$this->isAngry()){
				$this->tryTakeNearestTarget();
			}
		}
		if($phase === 0 && $this->nectar && $this->cropsGrown < 10){
			$this->growCropBelow();
		}elseif($phase === 20 && $this->hive === null && !$this->stung && mt_rand(0, 9) === 0){
			$hive = $this->findHive();
			if($hive !== null){
				$this->assignHive($hive);
			}
		}elseif($phase === 10 && $this->wantsToGoHome()){
			$this->tryEnterHive();
		}
		return $hasUpdate;
	}

	private function growCropBelow() : void{
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		for($dy = 1; $dy <= 2; ++$dy){
			$block = $world->getBlockAt($x, (int) floor($this->location->y) - $dy, $z);
			if($block instanceof Crops && $block->getAge() < Crops::MAX_AGE){
				$world->setBlock($block->getPosition(), $block->setAge($block->getAge() + 1));
				++$this->cropsGrown;
				return;
			}
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry());
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->stung ? 1 : 0);
	}

	protected function makesFlapVibrations() : bool{
		return true;
	}
}
