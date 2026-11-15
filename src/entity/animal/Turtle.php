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
use pocketmine\block\TurtleEgg;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\GoHomeGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LayEggGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MoveToLandGoal;
use pocketmine\entity\ai\goal\MoveToLiquidGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function count;
use function in_array;
use function max;
use function min;
use function mt_rand;

/**
 * Bedrock values (entities/turtle.json): 30 health, adults 1.2x0.4, babies scale 0.16, walks at 0.1, swims at 0.12
 * (babies 0.06), amphibious, tempted and bred with seagrass. Breeding makes the turtle pregnant: it goes back to its
 * home (where it hatched or first spawned; behavior.go_home with a 1 in 700 chance per tick, from any distance, until
 * within 4 blocks of it), then lays 1-4 eggs on the nearest sand within 16 blocks (behavior.lay_egg). A baby growing
 * up drops a turtle scute. Hatchlings head for the nearest water within 15 blocks; adults go back to water within 16
 * blocks when ashore and ashore within 16 blocks when in water (move_to_water / move_to_land, every 10-20 seconds).
 * Loot (loot_tables/entities/sea_turtle.json): 0-2 seagrass (+0-1 per Looting level). Lightning kills it, and it
 * then drops a bowl.
 */
class Turtle extends Animal{
	public const TAG_PREGNANT = "IsPregnant"; //TAG_Byte
	public const TAG_WANTS_TO_LAY_EGG = "WantsToLayEgg"; //TAG_Byte (PocketMine: back home, looking for sand)

	/** behavior.go_home of pregnant turtles */
	private const GO_HOME_INTERVAL = 700;
	private const GO_HOME_RADIUS = 4.0;
	/** behavior.lay_egg search_range and search_height */
	private const NEST_SEARCH_RANGE = 16;
	private const NEST_SEARCH_HEIGHT = 4;
	/** Ticks before searching for sand again, plus up to as many at random (Java MoveToBlockGoal: 10-20 seconds). */
	private const FAILED_NEST_SEARCH_DELAY = 200;
	/** Longest wait between searches after many searches found no sand (one minute, plus the random part). */
	private const MAX_NEST_SEARCH_DELAY = 1200;

	private static ?int $sandStateId = null;

	private bool $pregnant = false;
	/** Back home while pregnant (vanilla wants_to_lay_egg group): looks for sand to lay its eggs on. */
	private bool $wantsToLayEgg = false;
	private int $nextNestSearchTick = 0;
	private int $failedNestSearches = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::TURTLE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.4, 1.2, 0.2);
	}

	public function getName() : string{
		return "Turtle";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(1.333333, 4.0, 0.666667); //baby collision box 4 x 1.333 at scale 0.16
	}

	public function getSpawnBabyChance() : int{
		return 10; //entity_spawned: 9 adult / 1 baby
	}

	protected function getBabyScale() : float{
		return 0.16;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(30);
		$this->setMovementSpeed(0.1);
		parent::initEntity($nbt);
		if($this->getHomePos() === null){
			$this->setHomeHere(); //minecraft:home: where it first spawned (hatchlings: their egg)
		}
		$this->pregnant = $nbt->getByte(self::TAG_PREGNANT, 0) !== 0;
		$this->wantsToLayEgg = $this->pregnant && $nbt->getByte(self::TAG_WANTS_TO_LAY_EGG, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_PREGNANT, $this->pregnant ? 1 : 0);
		$nbt->setByte(self::TAG_WANTS_TO_LAY_EGG, $this->wantsToLayEgg ? 1 : 0);
		return $nbt;
	}

	protected function createMoveControl() : MoveControl{
		return new SwimmingMoveControl($this, 0.12);
	}

	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		return $this->isInWater() ? RandomPos::getWaterPos($this, $horizontal, $vertical, $awayFrom) : parent::findWanderPos($horizontal, $vertical, $awayFrom);
	}

	public function canBreathe() : bool{
		return true;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new PanicGoal($this, 1.2));
		//pregnant: go_home, then (on_home) wants_to_lay_egg: lay_egg
		$this->goalSelector->addGoal(1, new GoHomeGoal($this, 1.0, self::GO_HOME_INTERVAL, self::GO_HOME_RADIUS, function() : void{
			$this->wantsToLayEgg = true;
		}, null, fn() => $this->pregnant && !$this->wantsToLayEgg));
		$this->goalSelector->addGoal(1, new LayEggGoal($this, fn() => $this->pregnant && $this->wantsToLayEgg, fn() => $this->findNestPlace(), fn(Vector3 $pos) => $this->layEggs($pos)));
		//babies: move_to_water (hatchlings run to the sea)
		$this->goalSelector->addGoal(1, new MoveToLiquidGoal($this, MoveToLiquidGoal::WATER, 1.0, 15, 5, 0.1, fn() => $this->isBaby()));
		$this->goalSelector->addGoal(2, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new TemptGoal($this, 1.1, fn(Item $item) => $this->isBreedingItem($item)));
		//move_to_water, and move_to_land for adults (not while looking for sand to lay eggs on)
		$this->goalSelector->addGoal(4, $toWater = new MoveToLiquidGoal($this, MoveToLiquidGoal::WATER, 1.0, 16, 5, 1.5, fn() => !$this->wantsToLayEgg));
		$this->goalSelector->addGoal(6, $toLand = new MoveToLandGoal($this, 1.0, 16, 5, 0.5, fn() => !$this->isBaby() && !$this->wantsToLayEgg));
		//swim or stroll a while after getting there instead of heading straight back
		$toWater->linkOpposite($toLand);
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
	}

	/**
	 * Returns the home (where it hatched or first spawned), see {@link Mob::getHomePos()}.
	 */
	public function getHomePosition() : Vector3{
		return $this->getHomePos() ?? $this->location->floor();
	}

	public function setHomePosition(Vector3 $home) : void{
		$this->setHomePos($home);
	}

	public function isPregnant() : bool{
		return $this->pregnant;
	}

	public function setPregnant(bool $pregnant) : void{
		$this->pregnant = $pregnant;
		if(!$pregnant){
			$this->wantsToLayEgg = false;
		}elseif(!$this->wantsToLayEgg){
			//bred on its home beach (the usual case): no need to go home first
			$loc = $this->location;
			$distanceSquared = $this->getHomeDistanceSquared($loc->x, $loc->y, $loc->z);
			$this->wantsToLayEgg = $distanceSquared !== null && $distanceSquared <= self::GO_HOME_RADIUS ** 2;
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Whether the pregnant turtle is back home and looks for sand to lay its eggs on.
	 * @internal
	 */
	public function wantsToLayEgg() : bool{
		return $this->wantsToLayEgg;
	}

	/**
	 * Returns the nearest block with air on sand within 16 blocks horizontally and 4 vertically (behavior.lay_egg
	 * search_range and search_height), going outwards ring by ring from the turtle. Only asked while the turtle wants to
	 * lay its eggs, every 30 ticks at most (see {@link LayEggGoal}), then 10-20 seconds later (Java MoveToBlockGoal),
	 * longer after repeated searches that found nothing.
	 */
	private function findNestPlace() : ?Vector3{
		$now = $this->server->getTick();
		if($now < $this->nextNestSearchTick){
			return null;
		}
		$this->nextNestSearchTick = $now + min(self::FAILED_NEST_SEARCH_DELAY * (1 + $this->failedNestSearches), self::MAX_NEST_SEARCH_DELAY) + mt_rand(0, self::FAILED_NEST_SEARCH_DELAY - 1);
		$pos = $this->searchNestPlace();
		$this->failedNestSearches = $pos === null ? min($this->failedNestSearches + 1, 8) : 0;
		return $pos;
	}

	/**
	 * Reads raw state IDs from the chunks (no Block objects, the world block cache is left alone) and skips chunks whose
	 * searched subchunks have no sand in their palette, so a search in open water or on grass costs a few palette checks.
	 */
	private function searchNestPlace() : ?Vector3{
		$world = $this->getWorld();
		$baseX = $this->location->getFloorX();
		$baseY = $this->location->getFloorY();
		$baseZ = $this->location->getFloorZ();
		//the block below the lowest spot up to the highest spot
		$minY = max($baseY - self::NEST_SEARCH_HEIGHT - 1, $world->getMinY());
		$maxY = min($baseY + self::NEST_SEARCH_HEIGHT, $world->getMaxY() - 1);
		if($minY >= $maxY){
			return null;
		}
		$sand = self::$sandStateId ??= VanillaBlocks::SAND()->getStateId();
		$air = Block::EMPTY_STATE_ID;
		$minSubY = $minY >> SubChunk::COORD_BIT_SIZE;
		$maxSubY = $maxY >> SubChunk::COORD_BIT_SIZE;

		/**
		 * Block layers of the searched subchunks (null: all air) of the chunks with sand in them
		 * @var (PalettedBlockArray|null)[][] $chunks
		 */
		$chunks = [];
		for($chunkX = ($baseX - self::NEST_SEARCH_RANGE) >> Chunk::COORD_BIT_SIZE, $maxChunkX = ($baseX + self::NEST_SEARCH_RANGE) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = ($baseZ - self::NEST_SEARCH_RANGE) >> Chunk::COORD_BIT_SIZE, $maxChunkZ = ($baseZ + self::NEST_SEARCH_RANGE) >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				$subLayers = [];
				$hasSand = false;
				for($subY = $minSubY; $subY <= $maxSubY; ++$subY){
					$layer = $chunk->getSubChunk($subY)->getBlockLayers()[0] ?? null;
					$subLayers[$subY] = $layer;
					$hasSand = $hasSand || ($layer !== null && in_array($sand, $layer->getPalette(), true));
				}
				if($hasSand){
					$chunks[World::chunkHash($chunkX, $chunkZ)] = $subLayers;
				}
			}
		}
		if(count($chunks) === 0){
			return null;
		}

		for($r = 0; $r <= self::NEST_SEARCH_RANGE; ++$r){
			for($dx = -$r; $dx <= $r; ++$dx){
				//the edge of the ring only: whole rows at dz = -r and r, the two ends of the rows in between
				$step = $dx === -$r || $dx === $r || $r === 0 ? 1 : 2 * $r;
				$x = $baseX + $dx;
				for($dz = -$r; $dz <= $r; $dz += $step){
					$z = $baseZ + $dz;
					$subLayers = $chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null;
					if($subLayers === null){
						continue;
					}
					//one read per block of the column, carrying the block below up (subchunks without layers are air)
					$localX = $x & Chunk::COORD_MASK;
					$localZ = $z & Chunk::COORD_MASK;
					$below = $subLayers[$minY >> SubChunk::COORD_BIT_SIZE]?->get($localX, $minY & SubChunk::COORD_MASK, $localZ) ?? $air;
					for($y = $minY + 1; $y <= $maxY; ++$y){
						$stateId = $subLayers[$y >> SubChunk::COORD_BIT_SIZE]?->get($localX, $y & SubChunk::COORD_MASK, $localZ) ?? $air;
						if($below === $sand && $stateId === $air){
							return new Vector3($x, $y, $z);
						}
						$below = $stateId;
					}
				}
			}
		}
		return null;
	}

	private function layEggs(Vector3 $pos) : bool{
		$world = $this->getWorld();
		if($world->getBlock($pos)->getTypeId() !== BlockTypeIds::AIR || $world->getBlock($pos->down())->getTypeId() !== BlockTypeIds::SAND){
			return false;
		}
		$world->setBlock($pos, VanillaBlocks::TURTLE_EGG()->setCount(mt_rand(TurtleEgg::MIN_COUNT, TurtleEgg::MAX_COUNT)));
		$this->playMobSound(LevelSoundEvent::LAY_EGG);
		$this->setPregnant(false);
		return true;
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === VanillaBlocks::SEAGRASS()->asItem()->getTypeId();
	}

	public function canBeLeashed() : bool{
		return false; //turtle.json has no minecraft:leashable
	}

	protected function spawnBaby(Animal $partner) : ?Animal{
		$this->setPregnant(true);
		return null;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Turtle($location);
	}

	protected function onGrowUp() : void{
		$this->getWorld()->dropItem($this->location, VanillaItems::SCUTE());
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$control = $this->moveControl;
		if($control instanceof SwimmingMoveControl){
			$control->setSwimSpeed($this->isBaby() ? 0.06 : 0.12);
		}
		return $hasUpdate;
	}

	protected function getAdultDrops() : array{
		$count = mt_rand(0, 2) + $this->lootingBonus();
		return $count > 0 ? [VanillaBlocks::SEAGRASS()->asItem()->setCount($count)] : [];
	}

	/**
	 * Vanilla: lightning kills a turtle outright, and it drops a bowl.
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		$this->setOnFire(LightningBolt::FIRE_SECONDS);
		$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_LIGHTNING, $this->getMaxHealth() * 1000));
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		if($this->lastDamageCause !== null && $this->lastDamageCause->getCause() === EntityDamageEvent::CAUSE_LIGHTNING){
			$drops[] = VanillaItems::BOWL();
		}
		return $drops;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::PREGNANT, $this->pregnant);
	}
}
