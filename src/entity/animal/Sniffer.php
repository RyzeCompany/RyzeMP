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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function abs;
use function cos;
use function deg2rad;
use function floor;
use function mt_rand;
use function sin;

/**
 * Bedrock values (entities/sniffer.json): 14 health, 1.9x1.75, movement 0.09, babies (snifflets) scale 0.45 and grow
 * up in 40 minutes; snifflets don't follow their parents. Tempted (1.25x), bred and healed (2) with torchflower seeds;
 * breeding makes it pregnant and it drops a sniffer egg item right away (spawn_entity, wait 0, "plop").
 *
 * Search and dig: every 400-500 seconds a sniffer scents for 2 seconds (behavior.timer_flag_1, TIMER_FLAG_1); an adult
 * then looks for dirt, coarse/rooted dirt, grass, podzol, moss, pale moss, mud or muddy mangrove roots within 20 blocks
 * (3 up or down), walks there at 1.25x (SEARCHING) and digs for 8-10 seconds (DIGGING, not pushable). A torchflower
 * seed or a pitcher pod (loot_tables/gameplay/entities/sniffer_seeds.json) pops out 2.25 blocks in front of it after 6
 * seconds and it feels happy for 2-5 seconds (timer_flag_3); after the dig it stands up for 2-5 seconds (timer_flag_2).
 * No loot; XP 1-3.
 */
class Sniffer extends Animal{
	public const TAG_PREGNANT = "IsPregnant"; //TAG_Byte
	public const TAG_DIG_COOLDOWN = "DigCooldown"; //TAG_Int

	public const SNIFFLET_AGE = -48000;

	/** timer_flag_1 cooldown_range 400-500 s, duration 2 s */
	private const SCENT_COOLDOWN_MIN = 8000;
	private const SCENT_COOLDOWN_MAX = 10000;
	private const SCENT_TICKS = 40;
	/** random_search_and_dig: search_range_xz 20, search_range_y 3, find_valid_position_retries 5, goal_radius 2 */
	private const SEARCH_RANGE_XZ = 20;
	private const SEARCH_RANGE_Y = 3;
	private const SEARCH_RETRIES = 5;
	private const SEARCH_COLUMNS_PER_RETRY = 16;
	private const GOAL_RADIUS = 2.0;
	/** Gives up walking to the dig spot after a minute (on_fail_during_searching; a sniffer walks about 20 blocks in 40 s). */
	private const SEARCH_TIMEOUT = 1200;
	/** digging_duration_range 8-10 s, spawn_item_after_seconds 6, spawn_item_pos_offset 2.25 */
	private const DIG_TICKS_MIN = 160;
	private const DIG_TICKS_MAX = 200;
	private const DIG_ITEM_TICKS = 120;
	private const DIG_ITEM_OFFSET = 2.25;
	/** timer_flag_2 (stand up) and timer_flag_3 (feeling happy): 2-5 s */
	private const PHASE_TICKS_MIN = 40;
	private const PHASE_TICKS_MAX = 100;

	/**
	 * random_search_and_dig target_blocks (coarse and rooted dirt are dirt types).
	 */
	private const DIGGABLE = [
		BlockTypeIds::DIRT => true,
		BlockTypeIds::GRASS => true,
		BlockTypeIds::PODZOL => true,
		BlockTypeIds::MOSS_BLOCK => true,
		BlockTypeIds::PALE_MOSS_BLOCK => true,
		BlockTypeIds::MUD => true,
		BlockTypeIds::MUDDY_MANGROVE_ROOTS => true,
	];

	private bool $pregnant = false;
	/** Ticks until the next scenting (timer_flag_1 cooldown). */
	private int $scentCooldown = self::SCENT_COOLDOWN_MAX;
	private int $scentTicks = 0;
	/** The sniffer_search_and_dig component group: scenting succeeded and the sniffer wants to dig somewhere. */
	private bool $wantsToDig = false;
	private bool $searching = false;
	private ?Vector3 $digTarget = null;
	private int $digTicks = 0;
	private int $digElapsed = 0;
	private bool $itemFound = false;
	private int $standUpTicks = 0;
	private int $happyTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::SNIFFER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.75, 1.9, 1.05);
	}

	public function getName() : string{
		return "Sniffer";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function getBabyScale() : float{
		return 0.45;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(14);
		$this->setMovementSpeed(0.09);
		parent::initEntity($nbt);
		$this->pregnant = $nbt->getByte(self::TAG_PREGNANT, 0) !== 0;
		$this->scentCooldown = $nbt->getInt(self::TAG_DIG_COOLDOWN, mt_rand(1200, self::SCENT_COOLDOWN_MAX));
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_PREGNANT, $this->pregnant ? 1 : 0);
		$nbt->setInt(self::TAG_DIG_COOLDOWN, $this->scentCooldown);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 2.0));
		//behavior.timer_flag_2 (stand up after digging): holds the move control
		$this->goalSelector->addGoal(2, new class($this) extends Goal{
			public function __construct(private Sniffer $sniffer){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{ return $this->sniffer->isStandingUp(); }

			public function start() : void{ $this->sniffer->getNavigation()->stop(); }
		});
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBreedingItem($item)));
		//behavior.random_search_and_dig, only while the sniffer_search_and_dig group is present
		$this->goalSelector->addGoal(5, new class($this) extends Goal{
			private int $ticks = 0;

			public function __construct(private Sniffer $sniffer){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP; }

			public function canStart() : bool{ return $this->sniffer->prepareSearch(); }

			//only damage stops a dig (see Sniffer::attack())
			public function isInterruptible() : bool{ return !$this->sniffer->isDigging(); }

			public function canContinue() : bool{
				return $this->sniffer->isDigging() || ($this->sniffer->isSearching() && $this->ticks < Sniffer::searchTimeout());
			}

			public function start() : void{
				$this->ticks = 0;
				$this->sniffer->startSearching();
			}

			public function stop() : void{
				$this->sniffer->getNavigation()->stop();
				$this->sniffer->interruptSearchAndDig();
			}

			public function tick() : void{
				if($this->sniffer->isSearching()){
					++$this->ticks;
					$this->sniffer->tickSearching($this->ticks);
				}
			}
		});
		//behavior.timer_flag_1 (scenting): holds the move and look controls
		$this->goalSelector->addGoal(6, new class($this) extends Goal{
			public function __construct(private Sniffer $sniffer){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

			public function canStart() : bool{ return $this->sniffer->canStartScenting(); }

			public function canContinue() : bool{ return $this->sniffer->isScenting(); }

			public function start() : void{
				$this->sniffer->getNavigation()->stop();
				$this->sniffer->startScenting();
			}

			public function stop() : void{ $this->sniffer->stopScenting(); }
		});
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
	}

	public function isPregnant() : bool{
		return $this->pregnant;
	}

	public function setPregnant(bool $pregnant) : void{
		$this->pregnant = $pregnant;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns whether the sniffer would start scenting now (its timer_flag_1 cooldown is over).
	 */
	public function canDig() : bool{
		return $this->canStartScenting();
	}

	/**
	 * @internal
	 */
	public static function searchTimeout() : int{
		return self::SEARCH_TIMEOUT;
	}

	/**
	 * @internal used by the scenting goal
	 */
	public function canStartScenting() : bool{
		return $this->scentCooldown <= 0 && $this->scentTicks <= 0 && !$this->wantsToDig && $this->digTicks <= 0 &&
			$this->onGround && !$this->isInWater();
	}

	public function isScenting() : bool{
		return $this->scentTicks > 0;
	}

	/**
	 * @internal
	 */
	public function startScenting() : void{
		$this->scentTicks = self::SCENT_TICKS;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * @internal called when the scenting goal stops, finished or interrupted
	 */
	public function stopScenting() : void{
		if($this->scentTicks > 0){
			//interrupted (panic, tempt...): it scents again once nothing else runs
			$this->scentTicks = 0;
			$this->networkPropertiesDirty = true;
		}
	}

	private function onScentingSuccess() : void{
		$this->scentCooldown = mt_rand(self::SCENT_COOLDOWN_MIN, self::SCENT_COOLDOWN_MAX);
		$this->networkPropertiesDirty = true;
		if(!$this->isBaby()){
			$this->wantsToDig = true;
		}
	}

	public function wantsToDig() : bool{
		return $this->wantsToDig;
	}

	public function isSearching() : bool{
		return $this->searching;
	}

	public function isDigging() : bool{
		return $this->digTicks > 0;
	}

	public function isStandingUp() : bool{
		return $this->standUpTicks > 0;
	}

	public function isFeelingHappy() : bool{
		return $this->happyTicks > 0;
	}

	/**
	 * Returns the block the sniffer is walking to or digging, if any.
	 */
	public function getDigTarget() : ?Vector3{
		return $this->digTarget;
	}

	/**
	 * @internal called by the search goal's canStart(): looks for a block to dig once the sniffer wants to; gives up
	 * (on_fail_during_searching) when there is none.
	 */
	public function prepareSearch() : bool{
		if(!$this->wantsToDig || $this->digTicks > 0 || $this->standUpTicks > 0){
			return false;
		}
		if($this->isBaby()){
			$this->wantsToDig = false;
			return false;
		}
		if($this->digTarget !== null){
			return true; //found earlier, the goal is waiting for a higher priority goal to finish
		}
		$this->digTarget = $this->findDigTarget();
		if($this->digTarget === null){
			$this->wantsToDig = false;
			return false;
		}
		return true;
	}

	/**
	 * Picks a random diggable block with room above it within 20 blocks horizontally and 3 vertically. Bounded:
	 * 5 tries of 16 random columns of 8 blocks, once per scenting (every 400-500 seconds).
	 */
	public function findDigTarget() : ?Vector3{
		$world = $this->getWorld();
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		for($retry = 0; $retry < self::SEARCH_RETRIES; ++$retry){
			for($i = 0; $i < self::SEARCH_COLUMNS_PER_RETRY; ++$i){
				$x = $bx + mt_rand(-self::SEARCH_RANGE_XZ, self::SEARCH_RANGE_XZ);
				$z = $bz + mt_rand(-self::SEARCH_RANGE_XZ, self::SEARCH_RANGE_XZ);
				$above = $world->getBlockAt($x, $by + self::SEARCH_RANGE_Y + 1, $z, true, false);
				for($y = $by + self::SEARCH_RANGE_Y; $y >= $by - self::SEARCH_RANGE_Y; --$y){
					$block = $world->getBlockAt($x, $y, $z, true, false);
					if(isset(self::DIGGABLE[$block->getTypeId()]) && self::hasRoomAbove($above)){
						return new Vector3($x, $y, $z);
					}
					$above = $block;
				}
			}
		}
		return null;
	}

	private static function hasRoomAbove(Block $above) : bool{
		return !$above->isSolid() && !($above instanceof Liquid);
	}

	/**
	 * Returns whether the block is one sniffers dig in.
	 */
	public static function isDiggable(Block $block) : bool{
		return isset(self::DIGGABLE[$block->getTypeId()]);
	}

	/**
	 * @internal
	 */
	public function startSearching() : void{
		$this->searching = true;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * @internal walks to the dig target (1.25x) and starts digging once within 2 blocks of it
	 */
	public function tickSearching(int $ticks) : void{
		$target = $this->digTarget;
		if($target === null){
			$this->failSearching();
			return;
		}
		$dx = $target->x + 0.5 - $this->location->x;
		$dz = $target->z + 0.5 - $this->location->z;
		if($dx * $dx + $dz * $dz <= self::GOAL_RADIUS * self::GOAL_RADIUS && abs($target->y + 1 - $this->location->y) < 1.5){
			$this->getNavigation()->stop();
			$this->getLookControl()->setLookAt($target->x + 0.5, $target->y + 0.5, $target->z + 0.5);
			$this->beginDig();
			return;
		}
		if($ticks % 20 === 1 && !$this->getNavigation()->moveTo($target->x + 0.5, $target->y + 1, $target->z + 0.5, 1.25, self::GOAL_RADIUS - 0.5)){
			$this->failSearching();
		}
	}

	private function failSearching() : void{
		$this->searching = false;
		$this->wantsToDig = false;
		$this->digTarget = null;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Starts digging where the sniffer stands (8-10 seconds, the seed pops out after 6).
	 */
	public function startDigging() : void{
		$this->wantsToDig = true;
		$this->beginDig();
	}

	private function beginDig() : void{
		$this->searching = false;
		$this->digTicks = mt_rand(self::DIG_TICKS_MIN, self::DIG_TICKS_MAX);
		$this->digElapsed = 0;
		$this->itemFound = false;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * @internal called when the search goal stops: a search that didn't reach its block fails without standing up,
	 * an unfinished dig fails and the sniffer stands up.
	 */
	public function interruptSearchAndDig() : void{
		if($this->searching){
			$this->failSearching();
		}elseif($this->digTicks > 0){
			$this->finishDigging();
		}
	}

	/**
	 * A digging sniffer can't be pushed by other entities (on_digging_start removes the "pushable" component group).
	 */
	public function canBePushedBy(Entity $entity) : bool{
		return $this->digTicks <= 0 && parent::canBePushedBy($entity);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->digTicks > 0){
			$this->finishDigging(); //on_fail_during_digging
		}
	}

	private function dropSeed() : void{
		$this->itemFound = true;
		$this->happyTicks = mt_rand(self::PHASE_TICKS_MIN, self::PHASE_TICKS_MAX);
		$this->networkPropertiesDirty = true;
		$yaw = deg2rad($this->location->yaw);
		$seed = mt_rand(0, 1) === 0 ? VanillaItems::TORCHFLOWER_SEEDS() : VanillaItems::PITCHER_POD();
		$this->getWorld()->dropItem($this->location->add(-sin($yaw) * self::DIG_ITEM_OFFSET, 0.5, cos($yaw) * self::DIG_ITEM_OFFSET), $seed);
	}

	/**
	 * Ends a dig, finished or interrupted (on_search_and_digging_success / on_fail_during_digging): pushable again and
	 * standing up for 2-5 seconds.
	 */
	private function finishDigging() : void{
		$this->digTicks = 0;
		$this->wantsToDig = false;
		$this->digTarget = null;
		$this->standUpTicks = mt_rand(self::PHASE_TICKS_MIN, self::PHASE_TICKS_MAX);
		$this->networkPropertiesDirty = true;
	}

	/**
	 * spawn_entity (sniffer_pregnant): the egg drops as an item with a plop, then the pregnancy ends (on_egg_spawned).
	 */
	private function layEgg() : void{
		$this->getWorld()->dropItem($this->location, VanillaBlocks::SNIFFER_EGG()->asItem());
		$this->playMobSound(LevelSoundEvent::PLOP);
		$this->setPregnant(false);
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::TORCHFLOWER_SEEDS;
	}

	protected function spawnBaby(Animal $partner) : ?Animal{
		$this->setPregnant(true);
		return null;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Sniffer($location);
	}

	public function getBabyStartAge() : int{
		return self::SNIFFLET_AGE; //ageable duration 2400 s
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		if($this->isBreedingItem($item) && $this->getHealth() < $this->getMaxHealth()){
			$this->consumeHeldItem($player);
			$this->heal(new EntityRegainHealthEvent($this, 2, EntityRegainHealthEvent::CAUSE_EATING));
			return true;
		}
		return false;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->pregnant && $this->isAlive()){
			$this->layEgg();
		}
		if($this->scentCooldown > 0){
			$this->scentCooldown -= $tickDiff;
		}
		if($this->scentTicks > 0 && ($this->scentTicks -= $tickDiff) <= 0){
			$this->scentTicks = 0;
			$this->onScentingSuccess();
		}
		if($this->digTicks > 0){
			$this->digElapsed += $tickDiff;
			if(!$this->itemFound && $this->digElapsed >= self::DIG_ITEM_TICKS){
				$this->dropSeed();
			}
			if(($this->digTicks -= $tickDiff) <= 0){
				$this->finishDigging();
			}
		}
		if($this->standUpTicks > 0 && ($this->standUpTicks -= $tickDiff) <= 0){
			$this->standUpTicks = 0;
			$this->networkPropertiesDirty = true;
		}
		if($this->happyTicks > 0 && ($this->happyTicks -= $tickDiff) <= 0){
			$this->happyTicks = 0;
			$this->networkPropertiesDirty = true;
		}
		return $hasUpdate;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::DIGGING, $this->digTicks > 0);
		$properties->setGenericFlag(EntityMetadataFlags::SEARCHING, $this->searching);
		$properties->setGenericFlag(EntityMetadataFlags::TIMER_FLAG_1, $this->scentTicks > 0);
		$properties->setGenericFlag(EntityMetadataFlags::TIMER_FLAG_2, $this->standUpTicks > 0);
		$properties->setGenericFlag(EntityMetadataFlags::TIMER_FLAG_3, $this->happyTicks > 0);
		$properties->setGenericFlag(EntityMetadataFlags::PREGNANT, $this->pregnant);
	}
}
