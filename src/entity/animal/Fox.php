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

use pocketmine\block\Block;
use pocketmine\block\CaveVines;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\SweetBerryBush;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\DefendTrustedTargetGoal;
use pocketmine\entity\ai\goal\EatCarriedItemGoal;
use pocketmine\entity\ai\goal\FindCoverGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LeapAtTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\ai\goal\RaidGardenGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\StrollTowardsVillageGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Salmon;
use pocketmine\entity\aquatic\TropicalFish;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\FoodSource;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\ConsumableItem;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\GameRules;
use pocketmine\world\sound\GlowBerriesPickSound;
use pocketmine\world\sound\SweetBerriesPickSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_map;
use function cos;
use function count;
use function floor;
use function in_array;
use function mt_getrandmax;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Bedrock values (entities/fox.json): 10 health, 0.6x0.7, movement 0.3, attack 2. Red (VARIANT 0) or arctic (1) in
 * biomes tagged spawns_snow_foxes. Runs from players (unless they're trusted or sneaking), wolves and polar bears
 * within 10 blocks; hunts chickens, rabbits, fish and baby turtles (stalk and pounce approximated by a leap, STALKING
 * meanwhile); by day it naps under cover when nothing is around (SLEEPING) and seeks shade, at once in thunderstorms;
 * at night it strolls towards villages nearby. Tempted (0.5x) and bred with sweet or glow berries, and babies trust the
 * players who bred their parents and defend them from whoever hurts them. 20% spawn holding an item (fox_equipment
 * loot); it picks up items in its mouth (food first), eats the food it carries after 28 ticks, and eats the berries of
 * sweet berry bushes and cave vines nearby; the carried item drops on death. Sometimes it sits and looks around
 * (SITTING). XP 1-3.
 */
class Fox extends Animal{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_TRUSTED = "TrustedPlayers"; //TAG_List<TAG_String>, PocketMine-specific (UUIDs)
	public const TAG_HELD_ITEM = "HeldItem"; //TAG_Compound

	public const VARIANT_RED = 0;
	public const VARIANT_ARCTIC = 1;

	private int $variant = self::VARIANT_RED;
	/** @var string[] UUIDs of trusted players */
	private array $trusted = [];
	private ?Item $heldItem = null;
	private bool $sleeping = false;
	/** UUID of the last player who fed this fox for breeding. */
	private ?string $breeder = null;
	/** Defending a trusted player (defending_fox). */
	private bool $defending = false;
	private bool $stalking = false;
	private bool $sitting = false;
	private bool $defendGoalsAdded = false;

	/**
	 * @var bool[] state ID => whether foxes raid it (see {@link Fox::isRaidTargetState()})
	 * @phpstan-var array<int, bool>
	 */
	private static array $raidTargetStates = [];

	public static function getNetworkTypeId() : string{ return EntityIds::FOX; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.7, 0.6, 0.4);
	}

	public function getName() : string{
		return "Fox";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(0.84, 0.72, 0.48); //baby collision box 0.72 x 0.84 at scale 0.5
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function canStandOnPowderSnow() : bool{
		return true; //can_stand_on_powder_snow
	}

	public static function variantForBiome(World $world, Vector3 $pos) : int{
		return MobBiomeInfo::hasTag($world, $pos, "spawns_snow_foxes") ? self::VARIANT_ARCTIC : self::VARIANT_RED;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->variant = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 2 : self::variantForBiome($this->getWorld(), $this->location);
		$trusted = $nbt->getListTag(self::TAG_TRUSTED);
		if($trusted !== null){
			foreach($trusted as $tag){
				if($tag instanceof StringTag && Uuid::isValid($tag->getValue())){
					$this->trusted[] = $tag->getValue();
				}
			}
		}
		if(count($this->trusted) > 0){
			$this->addDefendGoals();
		}
		$held = $nbt->getCompoundTag(self::TAG_HELD_ITEM);
		if($held !== null){
			try{
				$this->heldItem = Item::nbtDeserialize($held);
			}catch(SavedDataLoadingException){
				$this->heldItem = null;
			}
		}elseif($nbt->getTag(self::TAG_VARIANT) === null && Utils::getRandomFloat() < 0.2){
			$this->heldItem = self::randomEquipment();
		}
	}

	/**
	 * An item from loot_tables/entities/fox_equipment.json (by weight).
	 */
	public static function randomEquipment() : Item{
		$r = mt_rand(0, 19);
		return match(true){
			$r < 1 => VanillaItems::EMERALD(),
			$r < 5 => VanillaItems::EGG(),
			$r < 7 => VanillaItems::RABBIT_FOOT(),
			$r < 9 => VanillaItems::RABBIT_HIDE(),
			$r < 13 => VanillaItems::WHEAT(),
			$r < 17 => VanillaItems::LEATHER(),
			default => VanillaItems::FEATHER()
		};
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		$nbt->setTag(self::TAG_TRUSTED, new ListTag(array_map(fn(string $u) => new StringTag($u), $this->trusted)));
		if($this->heldItem !== null){
			$nbt->setTag(self::TAG_HELD_ITEM, $this->heldItem->nbtSerialize());
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		//fox_thunderstorm: find_cover priority 0, no cooldown
		$this->goalSelector->addGoal(0, new FindCoverGoal($this, fn() => !$this->sleeping && $this->isThunderstorm() && $this->getAttackTarget() === null, 1.0, 0));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25, fn() => !$this->defending));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new TemptGoal($this, 0.5, fn(Item $item) => $this->isBreedingItem($item), 16.0, canGetScared: true));
		$this->goalSelector->addGoal(5, new AvoidEntityGoal($this, fn(Entity $e) => $this->isThreat($e), 10.0, 1.0, 1.5));
		//above the melee attack, which holds the movement as long as it has a target. stalk_and_pounce_on_target
		//leap_height 0.9, leap_dist 0.8 (Java FoxPounceGoal too)
		$this->goalSelector->addGoal(6, new LeapAtTargetGoal($this, 0.9, targetDist: 0.8));
		$this->goalSelector->addGoal(7, new MeleeAttackGoal($this, 1.2, 2.0, 12.0));
		$this->goalSelector->addGoal(8, new class($this) extends Goal{
			/** AI tick of the next try to fall asleep. */
			private int $nextTry = 0;
			private int $checks = 0;

			public function __construct(private Fox $fox){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP; }

			public function canStart() : bool{
				$tick = $this->fox->getAiTick();
				if($tick < $this->nextTry){
					return false;
				}
				//behavior.nap cooldown_min 2, cooldown_max 7 (seconds)
				$this->nextTry = $tick + mt_rand(40, 140);
				return $this->fox->canNap();
			}

			public function canContinue() : bool{
				//looking around for threats every 10 ticks is enough; being hurt wakes the fox at once
				return $this->fox->isSleeping() && (++$this->checks % 10 !== 0 || $this->fox->canNap());
			}

			public function start() : void{
				$this->fox->getNavigation()->stop();
				$this->fox->setSleeping(true);
			}

			public function stop() : void{
				$this->fox->setSleeping(false);
				$this->nextTry = $this->fox->getAiTick() + mt_rand(40, 140);
			}
		});
		//fox_day: find_cover priority 9, cooldown 5 s
		$this->goalSelector->addGoal(9, new FindCoverGoal($this, fn() => !$this->sleeping && self::isDaytime($this->getWorld()) && $this->getAttackTarget() === null, 1.0, 100));
		$this->goalSelector->addGoal(9, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(11, new PickupItemsGoal(
			$this,
			$this->wantsToPickUp(...),
			$this->pickUpItemEntity(...),
			fn() : bool => !$this->sleeping && GameRules::allowsMobGriefing($this->getWorld()),
			3.0,
			0.5
		));
		//fox_night: stroll_towards_village
		$this->goalSelector->addGoal(11, new StrollTowardsVillageGoal($this, 1.0, 32, 0.005, 200, 3.0, fn() => !self::isDaytime($this->getWorld()) && !$this->isThunderstorm()));
		$this->goalSelector->addGoal(12, new EatCarriedItemGoal(
			$this,
			fn() : ?Item => $this->heldItem instanceof FoodSource ? $this->heldItem : null,
			$this->eatHeldItem(...),
			fn() : bool => $this->onGround && !$this->sleeping && $this->getAttackTarget() === null
		));
		$this->goalSelector->addGoal(12, new RaidGardenGoal(
			$this,
			self::isRaidTargetState(...),
			$this->raidBlock(...),
			1.2,
			12,
			2,
			0.8,
			40,
			40,
			0,
			0,
			fn() : bool => !$this->sleeping && $this->getAttackTarget() === null
		));
		$this->goalSelector->addGoal(12, new class($this, fn(bool $sitting) => $this->setSittingFlag($sitting)) extends Goal{
			private int $looksLeft = 0;
			private int $lookTicks = 0;

			/** @phpstan-param \Closure(bool) : void $setSitting */
			public function __construct(private Fox $fox, private \Closure $setSitting){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

			public function canStart() : bool{
				//random_look_around_and_sit probability 0.001 per tick; goals are evaluated every GOAL_EVALUATION_INTERVAL ticks
				return mt_rand() / mt_getrandmax() < 0.001 * Mob::GOAL_EVALUATION_INTERVAL && $this->fox->isOnGround() && !$this->fox->isSleeping() && $this->fox->getAttackTarget() === null;
			}

			public function canContinue() : bool{
				return $this->looksLeft > 0 && $this->fox->isOnGround() && !$this->fox->isSleeping() && $this->fox->getAttackTarget() === null;
			}

			public function start() : void{
				//min_look_count 2, max_look_count 5
				$this->looksLeft = mt_rand(2, 5);
				$this->lookTicks = 0;
				$this->fox->getNavigation()->stop();
				($this->setSitting)(true);
			}

			public function stop() : void{
				$this->looksLeft = 0;
				($this->setSitting)(false);
			}

			public function tick() : void{
				if(--$this->lookTicks > 0){
					return;
				}
				//min_look_time 80, max_look_time 100 (ticks)
				$this->lookTicks = mt_rand(80, 100);
				--$this->looksLeft;
				$loc = $this->fox->getLiveLocation();
				$angle = mt_rand(0, 359) * M_PI / 180;
				$this->fox->getLookControl()->setLookAt($loc->x - sin($angle), $loc->y + $this->fox->getEyeHeight(), $loc->z + cos($angle));
			}
		});
		$this->goalSelector->addGoal(13, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(14, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(15, new RandomLookAroundGoal($this));
		$this->targetSelector->addGoal(6, new NearestAttackableEntityGoal($this, fn(Living $e) => self::isPrey($e), 12.0, true, 40, fn() => !$this->isBaby() && !$this->sleeping && !$this->defending && self::worldHasPrey($this->getWorld()), reselect: true));
	}

	/**
	 * Whether the world holds any entity of a class {@link Fox::isPrey()} may accept: the hunt doesn't look around in
	 * worlds without any.
	 */
	private static function worldHasPrey(World $world) : bool{
		return $world->hasEntitiesOfClass(Chicken::class) || $world->hasEntitiesOfClass(Rabbit::class) || $world->hasEntitiesOfClass(Cod::class) ||
			$world->hasEntitiesOfClass(Salmon::class) || $world->hasEntitiesOfClass(TropicalFish::class) || $world->hasEntitiesOfClass(Turtle::class);
	}

	/**
	 * Adds the goals of the trusting_fox and defending_fox component groups, once the fox trusts a player (foxes bred
	 * by players): the other foxes don't pay for them.
	 */
	private function addDefendGoals() : void{
		if($this->defendGoalsAdded){
			return;
		}
		$this->defendGoalsAdded = true;
		//defending_fox: melee priority 1 and panic priority 2, so a fox defending its trusted player doesn't run away
		$this->goalSelector->addGoal(1, new class($this, fn() => $this->defending) extends MeleeAttackGoal{
			/** @phpstan-param \Closure() : bool $defending */
			public function __construct(Fox $fox, private \Closure $defending){
				parent::__construct($fox, 1.2, 2.0, DefendTrustedTargetGoal::DEFAULT_RANGE);
			}

			public function canStart() : bool{
				return ($this->defending)() && parent::canStart();
			}
		});
		//trusting_fox: defend_trusted_target priority 0, within_radius 25, must_see false
		$this->targetSelector->addGoal(0, new DefendTrustedTargetGoal(
			$this,
			$this->getTrustedPlayersOnline(...),
			DefendTrustedTargetGoal::DEFAULT_RANGE,
			fn() => $this->setDefending(true),
			fn() => $this->setDefending(false),
			//Java Fox: never turns on a player it trusts too (a baby fox trusts both breeders)
			fn(Living $attacker) : bool => !($attacker instanceof Player && $this->trusts($attacker))
		));
	}

	/**
	 * Whether it is day for foxes (environment_sensor is_daytime: their day and night component groups).
	 */
	public static function isDaytime(World $world) : bool{
		return $world->getTimeOfDay() < World::TIME_NIGHT - 1000;
	}

	private function isThunderstorm() : bool{
		return $this->getWorld()->getWeather()->isThundering();
	}

	/**
	 * Returns the trusted players who are online in the fox's world (none for most foxes: only bred ones trust players).
	 *
	 * @return Player[]
	 * @phpstan-return list<Player>
	 */
	private function getTrustedPlayersOnline() : array{
		if(count($this->trusted) === 0){
			return [];
		}
		$players = [];
		foreach($this->trusted as $uuid){
			$player = $this->server->getPlayerByUUID(Uuid::fromString($uuid));
			if($player !== null && $player->getWorld() === $this->getWorld()){
				$players[] = $player;
			}
		}
		return $players;
	}

	/**
	 * Starts or stops defending a trusted player (the defending_fox component group): growls (aggro sound mad) and wakes
	 * up when it starts.
	 */
	private function setDefending(bool $defending) : void{
		if($defending === $this->defending){
			return;
		}
		$this->defending = $defending;
		if($defending){
			$this->playMobSound(LevelSoundEvent::MAD);
			if($this->sleeping){
				$this->setSleeping(false);
			}
		}
		$this->updateStalking();
	}

	/**
	 * Whether the fox is defending a trusted player from whoever hurt them.
	 */
	public function isDefending() : bool{
		return $this->defending;
	}

	public function setAttackTarget(?Living $target) : void{
		parent::setAttackTarget($target);
		$this->updateStalking();
	}

	/**
	 * STALKING (crouching, ears down) while the fox goes after prey (stalk_and_pounce_on_target).
	 */
	private function updateStalking() : void{
		$target = $this->getAttackTarget();
		$stalking = $target !== null && !$this->defending && self::isPrey($target);
		if($stalking !== $this->stalking){
			$this->stalking = $stalking;
			$this->networkPropertiesDirty = true;
		}
	}

	public function isStalking() : bool{
		return $this->stalking;
	}

	private function setSittingFlag(bool $sitting) : void{
		if($sitting !== $this->sitting){
			$this->sitting = $sitting;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Whether the fox sits looking around (random_look_around_and_sit).
	 */
	public function isSitting() : bool{
		return $this->sitting;
	}

	/**
	 * Order of preference of the items a fox carries (shareables: any food and glow berries first, then bundles, then
	 * any other item): lower is better.
	 */
	private static function carryPriority(Item $item) : int{
		if($item instanceof FoodSource || $item->getTypeId() === ItemTypeIds::GLOW_BERRIES){
			return 0;
		}
		return $item->getTypeId() === ItemTypeIds::BUNDLE ? 1 : 2;
	}

	/**
	 * Whether the fox wants to pick the item up (shareables, all_items max 1): with nothing in its mouth, or instead of
	 * something it likes less (food before bundles before anything else).
	 */
	private function wantsToPickUp(Item $item) : bool{
		if($item->isNull() || !$this->isAlive()){
			return false;
		}
		return $this->heldItem === null || self::carryPriority($item) < self::carryPriority($this->heldItem);
	}

	/**
	 * Takes one of the item entity's items into the mouth (singular_pickup), unless {@link EntityItemPickupEvent} is
	 * cancelled; the item it carried before is spat out.
	 */
	private function pickUpItemEntity(ItemEntity $entity) : bool{
		if(!PickupItemsGoal::isAvailable($entity)){
			return false;
		}
		$item = $entity->getItem();
		if(!$this->wantsToPickUp($item)){
			return false;
		}
		$ev = new EntityItemPickupEvent($this, $entity, (clone $item)->setCount(1), null);
		$ev->call();
		if($ev->isCancelled() || $ev->getItem()->isNull()){
			return false;
		}
		NetworkBroadcastUtils::broadcastEntityEvent(
			$entity->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $this, $entity)
		);
		if($item->getCount() > 1){
			$entity->setStackSize($item->getCount() - 1);
		}else{
			$entity->flagForDespawn();
		}
		if($this->heldItem !== null){
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $this->heldItem);
		}
		$this->setHeldItem($ev->getItem());
		return true;
	}

	/**
	 * Eats the food in the fox's mouth (eat_carried_item): heals by the food's points, gets its effects, and keeps what
	 * is left of it (a bowl, a bottle).
	 */
	private function eatHeldItem(Item $food) : void{
		if($food instanceof FoodSource){
			$this->heal(new EntityRegainHealthEvent($this, $food->getFoodRestore(), EntityRegainHealthEvent::CAUSE_EATING));
			foreach($food->getAdditionalEffects() as $effect){
				$this->effectManager->add($effect);
			}
		}
		$this->setHeldItem($food instanceof ConsumableItem ? $food->getResidue() : null);
		$this->getWorld()->addGameEvent($this->location, GameEvent::EAT, $this);
	}

	/**
	 * Whether a block state is one foxes raid (raid_garden): sweet berry bushes with berries, cave vines with berries.
	 */
	private static function isRaidTargetState(int $stateId) : bool{
		if(isset(self::$raidTargetStates[$stateId])){
			return self::$raidTargetStates[$stateId];
		}
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return self::$raidTargetStates[$stateId] =
			($block instanceof SweetBerryBush && $block->getAge() >= SweetBerryBush::STAGE_BUSH_SOME_BERRIES) ||
			($block instanceof CaveVines && $block->hasBerries());
	}

	/**
	 * Picks the berries of a bush or of cave vines (Java FoxEatBerriesGoal): the bush goes back to its stage without
	 * berries and drops 1-2 berries (one more when fully grown), cave vines drop their glow berry. One berry goes into
	 * the fox's mouth instead if it is empty (to be eaten by eat_carried_item).
	 */
	private function raidBlock(Block $block) : bool{
		if($block instanceof SweetBerryBush){
			$count = 1 + mt_rand(0, 1) + ($block->getAge() === SweetBerryBush::STAGE_MATURE ? 1 : 0);
			if(!RaidGardenGoal::changeBlock($this, $block, (clone $block)->setAge(SweetBerryBush::STAGE_BUSH_NO_BERRIES))){
				return false;
			}
			$this->getWorld()->addSound($block->getPosition(), new SweetBerriesPickSound());
			$berry = VanillaItems::SWEET_BERRIES();
		}elseif($block instanceof CaveVines){
			$count = 1;
			if(!RaidGardenGoal::changeBlock($this, $block, (clone $block)->setBerries(false))){
				return false;
			}
			$this->getWorld()->addSound($block->getPosition(), new GlowBerriesPickSound());
			$berry = VanillaItems::GLOW_BERRIES();
		}else{
			return false;
		}
		if($this->heldItem === null){
			$this->setHeldItem(clone $berry);
			--$count;
		}
		if($count > 0){
			$this->getWorld()->dropItem($block->getPosition()->add(0.5, 0.5, 0.5), $berry->setCount($count));
		}
		return true;
	}

	public static function isPrey(Living $entity) : bool{
		return $entity instanceof Chicken || $entity instanceof Rabbit || $entity instanceof Cod || $entity instanceof Salmon || $entity instanceof TropicalFish ||
			($entity instanceof Turtle && $entity->isBaby() && !$entity->isInWater());
	}

	private function isThreat(Entity $entity) : bool{
		if($entity instanceof Player){
			return !$entity->isSneaking() && !$this->trusts($entity);
		}
		return $entity instanceof Wolf || $entity instanceof PolarBear;
	}

	/**
	 * Whether the fox may sleep now (fox_day behavior.nap): daytime, on the ground, out of water, under cover (no sky
	 * above, is_underground), no thunderstorm, not hunting, and nothing that wakes it within 12 blocks (6 up and down):
	 * players, wolves, polar bears and monsters, except trusted or sneaking players (wake_mob_exceptions) and spectators.
	 */
	public function canNap() : bool{
		$world = $this->getWorld();
		if(!self::isDaytime($world) || !$this->onGround || $this->isInWater() || $this->getAttackTarget() !== null ||
			$this->server->getTick() - $this->getLastAttackedTick() < 100 || $this->isThunderstorm()){
			return false;
		}
		$loc = $this->location;
		if($world->getWeather()->isExposedToSky((int) floor($loc->x), (int) floor($loc->y + 0.01) + 1, (int) floor($loc->z))){
			return false;
		}
		$bb = new AxisAlignedBB($loc->x - 12, $loc->y - 6, $loc->z - 12, $loc->x + 12, $loc->y + 6, $loc->z + 12);
		foreach($world->getNearbyEntities($bb, $this) as $entity){
			if($entity instanceof Player ? !$entity->isSpectator() && $this->isThreat($entity) : $entity instanceof Monster || $this->isThreat($entity)){
				return false;
			}
		}
		return true;
	}

	public function isSleeping() : bool{
		return $this->sleeping;
	}

	public function setSleeping(bool $sleeping) : void{
		$this->sleeping = $sleeping;
		$this->networkPropertiesDirty = true;
	}

	public function trusts(Player $player) : bool{
		return in_array($player->getUniqueId()->toString(), $this->trusted, true);
	}

	public function addTrustedPlayer(Player $player) : void{
		if(!$this->trusts($player)){
			$this->trusted[] = $player->getUniqueId()->toString();
			$this->addDefendGoals();
		}
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	public function getHeldItem() : ?Item{
		return $this->heldItem !== null ? clone $this->heldItem : null;
	}

	public function setHeldItem(?Item $item) : void{
		$this->heldItem = $item !== null && !$item->isNull() ? clone $item : null;
		$this->sendHeldItem($this->getViewers());
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::SWEET_BERRIES || $item->getTypeId() === ItemTypeIds::GLOW_BERRIES;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(parent::onInteractWithItem($player, $item)){
			if($this->isInLove()){
				$this->breeder = $player->getUniqueId()->toString();
			}
			return true;
		}
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Fox($location);
		$baby->setVariant(mt_rand(0, 1) === 0 || !$partner instanceof Fox ? $this->variant : $partner->getVariant());
		foreach([$this->breeder, $partner instanceof Fox ? $partner->breeder : null] as $uuid){
			if($uuid !== null && !in_array($uuid, $baby->trusted, true)){
				$baby->trusted[] = $uuid;
				$baby->addDefendGoals();
			}
		}
		return $baby;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->sleeping){
			$this->setSleeping(false);
		}
	}

	public function getDrops() : array{
		return $this->heldItem !== null ? [clone $this->heldItem] : [];
	}

	/**
	 * @param Player[] $players
	 */
	private function sendHeldItem(array $players) : void{
		foreach($players as $player){
			$session = $player->getNetworkSession();
			$session->sendDataPacket(MobEquipmentPacket::create(
				$this->getId(),
				ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet($this->heldItem ?? VanillaItems::AIR())),
				0,
				0,
				ContainerIds::INVENTORY
			));
		}
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->heldItem !== null){
			$this->sendHeldItem([$player]);
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		$properties->setGenericFlag(EntityMetadataFlags::SLEEPING, $this->sleeping);
		$properties->setGenericFlag(EntityMetadataFlags::STALKING, $this->stalking);
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->sitting);
	}
}
