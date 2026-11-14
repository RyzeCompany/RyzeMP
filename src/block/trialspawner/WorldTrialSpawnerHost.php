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

namespace pocketmine\block\trialspawner;

use pocketmine\block\Liquid;
use pocketmine\block\tile\TrialSpawner as TileTrialSpawner;
use pocketmine\block\TrialSpawner;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\object\OminousItemSpawner;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootTables;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\generator\vanilla\structure\template\EntityConversion;
use pocketmine\world\particle\TrialSpawnerBecomeOminousParticle;
use pocketmine\world\particle\TrialSpawnerDetectionParticle;
use pocketmine\world\particle\TrialSpawnerEjectParticle;
use pocketmine\world\particle\TrialSpawnerSpawnParticle;
use pocketmine\world\sound\OminousItemSpawnerBeginSound;
use pocketmine\world\sound\TrialOmenSound;
use pocketmine\world\sound\TrialSpawnerCloseShutterSound;
use pocketmine\world\sound\TrialSpawnerDetectPlayerSound;
use pocketmine\world\sound\TrialSpawnerEjectItemSound;
use pocketmine\world\sound\TrialSpawnerOminousActivateSound;
use pocketmine\world\sound\TrialSpawnerOpenShutterSound;
use pocketmine\world\sound\TrialSpawnerSpawnMobSound;
use pocketmine\world\World;
use function array_values;
use function count;
use function floor;
use function mt_getrandmax;
use function mt_rand;
use function spl_object_id;

/**
 * Runs a trial spawner in its world (see {@link TrialSpawnerHost}). The spawned mobs are kept by persistent ID; those
 * of a spawner loaded from disk are looked for once around it, a little after loading.
 * @internal
 */
final class WorldTrialSpawnerHost implements TrialSpawnerHost{
	/** Ticks after loading before saved mobs that are still missing are given up (their chunks may load later). */
	public const MOB_LOAD_GRACE_TICKS = 40;
	/** Silverfish don't spawn this close to a player (vanilla Silverfish.checkSilverfishSpawnRules). */
	private const SILVERFISH_PLAYER_DISTANCE = 5;
	/** Trial Omen ticks per level of the Bad Omen it replaces (vanilla TRIAL_OMEN_PER_BAD_OMEN_LEVEL, 15 minutes). */
	public const TRIAL_OMEN_PER_BAD_OMEN_LEVEL = 18000;

	private ?TrialSpawner $block = null;
	private int $scanOffset;
	private int $createdTick;
	private bool $savedMobsResolved = false;
	/**
	 * @var Entity[]
	 * @phpstan-var array<int, Entity>
	 */
	private array $mobs = [];
	/**
	 * Items the ominous item spawners of this spawner hold, with their weights (rolled once, vanilla
	 * TrialSpawnerStateData.getDispensingItems).
	 *
	 * @var array[]|null
	 * @phpstan-var list<array{Item, int}>|null
	 */
	private ?array $dispensing = null;

	public function __construct(
		private TileTrialSpawner $tile
	){
		$pos = $tile->getPosition();
		$this->scanOffset = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) % TrialSpawnerLogic::DELAY_BETWEEN_PLAYER_SCANS;
		if($this->scanOffset < 0){
			$this->scanOffset += TrialSpawnerLogic::DELAY_BETWEEN_PLAYER_SCANS;
		}
		$this->createdTick = $pos->getWorld()->getServer()->getTick();
	}

	/**
	 * Sets the spawner block being ticked (its state and ominous flag).
	 */
	public function setBlock(TrialSpawner $block) : void{
		$this->block = $block;
	}

	private function getWorld() : World{
		return $this->tile->getPosition()->getWorld();
	}

	public function getGameTime() : int{
		return $this->getWorld()->getServer()->getTick();
	}

	public function getScanOffset() : int{
		return $this->scanOffset;
	}

	public function getActiveConfig() : TrialSpawnerConfig{
		return $this->block !== null && $this->block->isOminous() ? $this->tile->getOminousConfig() : $this->tile->getNormalConfig();
	}

	public function getRequiredPlayerRange() : int{
		return $this->tile->getRequiredPlayerRange();
	}

	public function getTargetCooldownLength() : int{
		return $this->tile->getTargetCooldownLength();
	}

	public function canSpawnInLevel() : bool{
		$world = $this->getWorld();
		return $world->getDifficulty() !== World::DIFFICULTY_PEACEFUL && $world->areMobsEnabled() && $world->getGameRule(GameRules::DO_MOB_SPAWNING) === true;
	}

	public function detectPlayers(int $range, bool $requireLineOfSight) : array{
		$pos = $this->tile->getPosition();
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		$rangeSquared = $range * $range;
		$found = [];
		foreach($this->getWorld()->getPlayers() as $player){
			if($player->isCreative(true) || $player->isSpectator() || !$player->isAlive()){
				continue;
			}
			$playerPos = $player->getPosition();
			//vanilla compares block positions
			$dx = (int) floor($playerPos->x) - $x;
			$dy = (int) floor($playerPos->y) - $y;
			$dz = (int) floor($playerPos->z) - $z;
			if($dx * $dx + $dy * $dy + $dz * $dz >= $rangeSquared){
				continue;
			}
			if($requireLineOfSight && !$this->inLineOfSight($player->getEyePos())){
				continue;
			}
			$found[] = $player->getUniqueId()->toString();
		}
		return $found;
	}

	/**
	 * Returns whether nothing opaque is between the centre of the spawner and the point (vanilla clips with the visual
	 * shapes: glass and other see-through blocks don't hide it).
	 */
	private function inLineOfSight(Vector3 $point) : bool{
		$pos = $this->tile->getPosition();
		$center = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
		if($center->distanceSquared($point) < 0.000001){
			return true;
		}
		$world = $this->getWorld();
		foreach(VoxelRayTrace::betweenPoints($point, $center) as $voxel){
			$vx = (int) $voxel->x;
			$vy = (int) $voxel->y;
			$vz = (int) $voxel->z;
			if($vx === $pos->getFloorX() && $vy === $pos->getFloorY() && $vz === $pos->getFloorZ()){
				return true; //reached the spawner
			}
			if((PathBlockTypes::at($world, $vx, $vy, $vz) & PathBlockTypes::OPAQUE) !== 0){
				return false;
			}
		}
		return true;
	}

	public function spawnMob(TrialSpawnerSpawnData $data, TrialSpawnerConfig $config) : ?int{
		$javaId = $data->getEntityId();
		if($javaId === null){
			return null;
		}
		$id = EntityConversion::bedrockId($javaId);
		$registry = EntitySpawnRegistry::getInstance();
		if(!$registry->isRegistered($id)){
			return null;
		}
		$world = $this->getWorld();
		$pos = $this->tile->getPosition();
		$range = $config->getSpawnRange();
		$location = new Location(
			$pos->getFloorX() + (self::randomFloat() - self::randomFloat()) * $range + 0.5,
			$pos->getFloorY() + mt_rand(0, 2) - 1,
			$pos->getFloorZ() + (self::randomFloat() - self::randomFloat()) * $range + 0.5,
			$world,
			self::randomFloat() * 360,
			0
		);
		if(!$this->inLineOfSight($location)){
			return null;
		}
		$size = $data->getSlimeSize();
		$entity = $size !== null && $id === "minecraft:slime" ? new Slime($location, null, $size) : $registry->create($id, $location);
		if(!$entity instanceof Living){
			$entity?->close();
			return null;
		}
		if($data->isBaby()){
			if($entity instanceof Zombie){
				$entity->setBaby(true);
			}elseif($entity instanceof Animal){
				$entity->setAge(Animal::BABY_AGE);
			}
		}
		if(!$this->canSpawnAt($world, $entity)){
			$entity->close();
			return null;
		}
		if($entity instanceof Mob){
			$entity->setPersistent(true);
		}
		$entity->setSpawnReason(SpawnReason::TRIAL_SPAWNER);
		$entity->spawnToAll();
		if($data->hasOnlyEntityId()){
			//mobs without set data get their random variants (vanilla finalizeSpawn)
			Jockeys::onMobSpawned($entity);
		}
		$equipment = $data->getEquipmentLootTable();
		if($equipment !== null && ($entity instanceof Zombie || $entity instanceof Skeleton)){
			//vanilla Mob.equip (ominous configs: armor and weapons that never drop)
			$items = LootTables::get($equipment)?->generate(LootContext::create()) ?? [];
			$entity->equipLootItems($items, $data->getEquipmentDropChance() <= 0.0);
		}

		$center = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
		$ominous = $this->isOminous();
		$world->addParticle($center, new TrialSpawnerSpawnParticle($ominous));
		$world->addParticle($entity->getPosition(), new TrialSpawnerSpawnParticle($ominous));
		$world->addSound($center, new TrialSpawnerSpawnMobSound());

		$mobId = $entity->getPersistentUniqueId();
		$this->mobs[$mobId] = $entity;
		return $mobId;
	}

	/**
	 * The vanilla checks of a trial spawner spawn: the mob's box is free of blocks and liquids and inside the world;
	 * light doesn't matter.
	 */
	private function canSpawnAt(World $world, Living $entity) : bool{
		$bb = $entity->getBoundingBox();
		if($bb->minY < $world->getMinY() || $bb->maxY >= $world->getMaxY()){
			return false;
		}
		if(count($world->getCollisionBlocks($bb)) > 0){
			return false;
		}
		for($bx = (int) floor($bb->minX); $bx <= (int) floor($bb->maxX); ++$bx){
			for($by = (int) floor($bb->minY); $by <= (int) floor($bb->maxY); ++$by){
				for($bz = (int) floor($bb->minZ); $bz <= (int) floor($bb->maxZ); ++$bz){
					if($world->getBlockAt($bx, $by, $bz) instanceof Liquid){
						return false;
					}
				}
			}
		}
		if($entity instanceof Silverfish){
			$position = $entity->getPosition();
			foreach($world->getPlayers() as $player){
				if(!$player->isSpectator() && $player->getPosition()->distanceSquared($position) < self::SILVERFISH_PLAYER_DISTANCE ** 2){
					return false;
				}
			}
		}
		return true;
	}

	public function shouldMobBeUntracked(int $mobId) : bool{
		$entity = $this->mobs[$mobId] ?? null;
		if($entity === null){
			if(!$this->savedMobsResolved){
				if($this->getGameTime() - $this->createdTick < self::MOB_LOAD_GRACE_TICKS){
					return false;
				}
				$this->resolveSavedMobs();
				$entity = $this->mobs[$mobId] ?? null;
			}
			if($entity === null){
				return true;
			}
		}
		$pos = $this->tile->getPosition();
		if($entity->isClosed() || !$entity->isAlive() || $entity->getWorld() !== $pos->getWorld()){
			unset($this->mobs[$mobId]);
			return true;
		}
		//vanilla compares block positions (the box gives the position without copying it every tick)
		$bb = $entity->getBoundingBox();
		$dx = (int) floor(($bb->minX + $bb->maxX) / 2) - $pos->getFloorX();
		$dy = (int) floor($bb->minY) - $pos->getFloorY();
		$dz = (int) floor(($bb->minZ + $bb->maxZ) / 2) - $pos->getFloorZ();
		if($dx * $dx + $dy * $dy + $dz * $dz > TrialSpawnerLogic::MAX_MOB_TRACKING_DISTANCE ** 2){
			unset($this->mobs[$mobId]);
			return true;
		}
		return false;
	}

	/**
	 * Finds the mobs of a spawner loaded from disk (once): they are within the tracking distance or no longer count.
	 */
	private function resolveSavedMobs() : void{
		$this->savedMobsResolved = true;
		$wanted = $this->tile->getData()->currentMobs;
		if(count($wanted) === 0){
			return;
		}
		$pos = $this->tile->getPosition();
		$r = TrialSpawnerLogic::MAX_MOB_TRACKING_DISTANCE + 1;
		$area = new AxisAlignedBB($pos->x - $r, $pos->y - $r, $pos->z - $r, $pos->x + 1 + $r, $pos->y + 1 + $r, $pos->z + 1 + $r);
		foreach($this->getWorld()->getNearbyEntities($area) as $entity){
			if($entity instanceof Living && !$entity instanceof Player && isset($wanted[$id = $entity->getPersistentUniqueId()])){
				$this->mobs[$id] = $entity;
			}
		}
	}

	public function ejectReward(string $lootTable) : void{
		$table = LootTables::get($lootTable);
		if($table === null){
			return;
		}
		$items = $table->generate(LootContext::create());
		if(count($items) === 0){
			return;
		}
		$world = $this->getWorld();
		$pos = $this->tile->getPosition();
		$source = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 1.2, $pos->getFloorZ() + 0.5);
		foreach($items as $item){
			//vanilla DefaultDispenseItemBehavior.spawnItem upwards with speed 2
			$motion = new Vector3(self::triangle(0.0, 0.0345), self::triangle(0.2, 0.0345), self::triangle(0.0, 0.0345));
			$world->dropItem($source, $item, $motion);
		}
		$center = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
		$world->addParticle($center, new TrialSpawnerEjectParticle());
		$world->addSound($center, new TrialSpawnerEjectItemSound());
	}

	public function onPlayersDetected(int $players) : void{
		$pos = $this->tile->getPosition();
		$center = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
		$world = $this->getWorld();
		$world->addParticle($center, new TrialSpawnerDetectionParticle($players, $this->isOminous()));
		$world->addSound($center, new TrialSpawnerDetectPlayerSound());
	}

	public function onShutterOpened() : void{
		$pos = $this->tile->getPosition();
		$this->getWorld()->addSound(new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5), new TrialSpawnerOpenShutterSound());
	}

	public function onShutterClosed() : void{
		$pos = $this->tile->getPosition();
		$this->getWorld()->addSound(new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5), new TrialSpawnerCloseShutterSound());
	}

	public function onSpawnDataChanged() : void{
		$this->tile->broadcastSpawnData();
	}

	public function isOminous() : bool{
		return $this->block !== null && $this->block->isOminous();
	}

	public function hasPlayerWithOmen() : bool{
		$badOmen = VanillaEffects::BAD_OMEN();
		$trialOmen = VanillaEffects::TRIAL_OMEN();
		foreach($this->getWorld()->getPlayers() as $player){
			$effects = $player->getEffects();
			if($effects->has($trialOmen) || $effects->has($badOmen)){
				return true;
			}
		}
		return false;
	}

	public function tryConsumeOmen(array $players) : bool{
		$wanted = [];
		foreach($players as $uuid){
			$wanted[$uuid] = true;
		}
		$badOmenPlayer = null;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!isset($wanted[$player->getUniqueId()->toString()])){
				continue;
			}
			$effects = $player->getEffects();
			if($effects->has(VanillaEffects::TRIAL_OMEN())){
				return true;
			}
			if($effects->has(VanillaEffects::BAD_OMEN())){
				$badOmenPlayer = $player;
			}
		}
		if($badOmenPlayer === null){
			return false;
		}
		//vanilla transformBadOmenIntoTrialOmen: 15 minutes per Bad Omen level, Trial Omen has no levels
		$effects = $badOmenPlayer->getEffects();
		$level = ($effects->get(VanillaEffects::BAD_OMEN())?->getAmplifier() ?? 0) + 1;
		$effects->remove(VanillaEffects::BAD_OMEN());
		$effects->add(new EffectInstance(VanillaEffects::TRIAL_OMEN(), self::TRIAL_OMEN_PER_BAD_OMEN_LEVEL * $level, 0));
		$this->getWorld()->addSound($badOmenPlayer->getEyePos(), new TrialOmenSound(), [$badOmenPlayer]);
		return true;
	}

	public function applyOminous(array $mobs) : void{
		$this->block?->setOminous(true);
		$world = $this->getWorld();
		$pos = $this->tile->getPosition();
		$center = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
		$world->addParticle($center, new TrialSpawnerBecomeOminousParticle());
		$world->addSound($center, new TrialSpawnerOminousActivateSound());
		foreach($mobs as $mobId){
			if($this->shouldMobBeUntracked($mobId) || !isset($this->mobs[$mobId])){
				continue;
			}
			$entity = $this->mobs[$mobId];
			unset($this->mobs[$mobId]);
			//vanilla: the mobs of the normal fight vanish in flames
			$world->addParticle($entity->getPosition(), new TrialSpawnerSpawnParticle());
			$entity->flagForDespawn();
		}
	}

	public function removeOminous() : void{
		$this->block?->setOminous(false);
	}

	public function spawnOminousItemSpawner(array $players, array $mobs) : bool{
		$item = $this->pickItemToDispense();
		if($item === null){
			return false;
		}
		$target = $this->selectEntityToSpawnItemAbove($players, $mobs);
		$location = $target !== null ? $this->calculatePositionAbove($target) : null;
		if($location === null){
			return false;
		}
		(new OminousItemSpawner($location, $item))->spawnToAll();
		$this->getWorld()->addSound($location, new OminousItemSpawnerBeginSound());
		return true;
	}

	/**
	 * Picks the item of the next ominous item spawner: the loot table of the ominous config
	 * (items_to_drop_when_ominous) is rolled once per spawner, seeded by the world seed and the region of the spawner,
	 * and each item spawner gets one of these items, weighted by their counts.
	 */
	private function pickItemToDispense() : ?Item{
		if($this->dispensing === null){
			$this->dispensing = [];
			$table = LootTables::get($this->tile->getOminousConfig()->getItemsToDropWhenOminous());
			if($table !== null){
				$pos = $this->tile->getPosition();
				$seed = self::addLong($this->getWorld()->getSeed(), self::lowResolutionPosition($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()));
				foreach($table->generate(LootContext::seeded($seed)) as $item){
					if(!$item->isNull()){
						$this->dispensing[] = [(clone $item)->setCount(1), $item->getCount()];
					}
				}
			}
		}
		return self::pickDispensedItem($this->dispensing);
	}

	/**
	 * @param array[] $items
	 * @phpstan-param list<array{Item, int}> $items
	 */
	public static function pickDispensedItem(array $items) : ?Item{
		$total = 0;
		foreach($items as [, $weight]){
			$total += $weight;
		}
		if($total <= 0){
			return null;
		}
		$roll = mt_rand(0, $total - 1);
		foreach($items as [$item, $weight]){
			$roll -= $weight;
			if($roll < 0){
				return clone $item;
			}
		}
		return null;
	}

	/**
	 * Vanilla BlockPos.asLong() of the spawner position divided by 30 x 20 x 30 (part of the seed of the dispensed
	 * items).
	 */
	public static function lowResolutionPosition(int $x, int $y, int $z) : int{
		$lx = (int) floor($x / 30.0);
		$ly = (int) floor($y / 20.0);
		$lz = (int) floor($z / 30.0);
		return (($lx & 0x3ffffff) << 38) | (($lz & 0x3ffffff) << 12) | ($ly & 0xfff);
	}

	/**
	 * Adds two 64-bit integers wrapping around like Java (PHP would turn an overflowing sum into a float).
	 */
	public static function addLong(int $a, int $b) : int{
		$low = ($a & 0xffffffff) + ($b & 0xffffffff);
		$high = (($a >> 32) + ($b >> 32) + ($low >> 32)) & 0xffffffff;
		return ($high << 32) | ($low & 0xffffffff);
	}

	/**
	 * Vanilla TrialSpawnerState.selectEntityToSpawnItemAbove: one of the mobs or one of the players of the fight (a
	 * coin flip between both lists) that is alive and within the player range of the spawner. Nothing if no player of
	 * the fight is near.
	 *
	 * @param string[] $players
	 * @param int[]    $mobs
	 * @phpstan-param list<string> $players
	 * @phpstan-param list<int>    $mobs
	 */
	private function selectEntityToSpawnItemAbove(array $players, array $mobs) : ?Entity{
		$pos = $this->tile->getPosition();
		$center = new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
		$rangeSquared = $this->getRequiredPlayerRange() ** 2;
		$wanted = [];
		foreach($players as $uuid){
			$wanted[$uuid] = true;
		}
		$nearbyPlayers = [];
		foreach($this->getWorld()->getPlayers() as $player){
			if(isset($wanted[$player->getUniqueId()->toString()]) && !$player->isCreative(true) && !$player->isSpectator() && $player->isAlive() &&
				$player->getPosition()->distanceSquared($center) <= $rangeSquared
			){
				$nearbyPlayers[spl_object_id($player)] = $player;
			}
		}
		if(count($nearbyPlayers) === 0){
			return null;
		}
		if(mt_rand(0, 1) === 0){
			$candidates = array_values($nearbyPlayers);
		}else{
			$candidates = [];
			foreach($mobs as $mobId){
				$entity = $this->mobs[$mobId] ?? null;
				if($entity !== null && !$entity->isClosed() && $entity->isAlive() && $entity->getPosition()->distanceSquared($center) <= $rangeSquared){
					$candidates[] = $entity;
				}
			}
		}
		if(count($candidates) === 0){
			return null;
		}
		return $candidates[mt_rand(0, count($candidates) - 1)];
	}

	/**
	 * Vanilla TrialSpawnerState.calculatePositionAbove: 2 to 5 blocks above the head of the entity, or under the first
	 * opaque block between them, in the middle of a block without collision box.
	 */
	private function calculatePositionAbove(Entity $entity) : ?Location{
		$world = $this->getWorld();
		$position = $entity->getPosition();
		$x = (int) floor($position->x);
		$z = (int) floor($position->z);
		$hitY = (int) floor($position->y + $entity->getSize()->getHeight() + 2 + mt_rand(0, 3));
		for($y = (int) floor($position->y), $topY = $hitY; $y < $topY; ++$y){
			if((PathBlockTypes::at($world, $x, $y, $z) & PathBlockTypes::OPAQUE) !== 0){
				$hitY = $y;
				break;
			}
		}
		if($hitY - 1 < $world->getMinY() || $hitY - 1 >= $world->getMaxY() || count($world->getBlockAt($x, $hitY - 1, $z)->getCollisionBoxes()) > 0){
			return null;
		}
		return new Location($x + 0.5, $hitY - 0.5, $z + 0.5, $world, 0.0, 0.0);
	}

	/**
	 * Forgets the mobs spawned so far (the fight was reset).
	 */
	public function clearMobs() : void{
		$this->mobs = [];
	}

	private static function randomFloat() : float{
		return mt_rand() / mt_getrandmax();
	}

	/**
	 * Vanilla RandomSource.triangle(): a value around $mode, at most $deviation away.
	 */
	private static function triangle(float $mode, float $deviation) : float{
		return $mode + $deviation * (self::randomFloat() - self::randomFloat());
	}
}
