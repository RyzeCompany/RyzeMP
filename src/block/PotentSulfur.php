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

namespace pocketmine\block;

use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\PotentSulfurState;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use pocketmine\world\sound\GeyserEruptionActiveSound;
use pocketmine\world\sound\GeyserEruptionStartSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function count;
use function floor;

/**
 * Potent sulfur (Bedrock 1.26.x): the floor of sulfur springs. Under a water source it gives off noxious gas, and with
 * magma or lava below it is a geyser. Port of Java 26.3 PotentSulfurBlock and PotentSulfurBlockEntity; Bedrock keeps
 * no block entity for it, so everything lives in the block state and its scheduled updates.
 *
 * The state follows the blocks around it (placement and neighbour changes): DRY without a water source right above,
 * CONTINUOUS over a lava source, DORMANT over a magma block (an ERUPTING one stays ERUPTING), else WET. Up to 4 water
 * blocks above count; the gas comes out of the first open block above them.
 *
 * - WET and DORMANT: every 10 ticks, living entities with their eyes in the water surface within 3 blocks of the gas
 *   source, in its line of sight, get 4 s of Nausea.
 * - DORMANT erupts after 10 x (water blocks - 1) + 15..30 steps of 20 ticks, and an eruption lasts water blocks - 1 +
 *   1..2 steps (positional random from the world seed, like Java). The countdown isn't saved: after a restart it
 *   starts again. Like the Java block entity, the block only updates in ticking chunks, and its countdown waits
 *   meanwhile.
 * - ERUPTING and CONTINUOUS: every tick, entities in the column above (6 blocks per water block, up to the first
 *   solid block) are pushed up by 0.2 blocks/tick while slower than 0.3 + 0.1 x water blocks. Players move themselves
 *   (like in bubble columns), so they are only spared the fall damage. Without entities in reach the geyser checks
 *   every 4 ticks only.
 */
class PotentSulfur extends Opaque{
	/** Water blocks above the sulfur that count for the gas source and the geyser strength. */
	public const MAX_WATER_BLOCKS = 4;
	/** Ticks between two puffs of noxious gas (Nausea). */
	public const GAS_INTERVAL_TICKS = 10;
	public const NAUSEA_DURATION_TICKS = 80;
	/** Distance from the centre of the gas source within which eyes are reached by the gas. */
	public const GAS_RANGE = 3.0;
	/** Ticks per step of the eruption countdown. */
	public const COUNTDOWN_STEP_TICKS = 20;
	public const LAUNCH_HEIGHT_PER_WATER_BLOCK = 6;
	public const LAUNCH_BASE_SPEED = 0.3;
	public const LAUNCH_SPEED_PER_WATER_BLOCK = 0.1;
	public const LAUNCH_FORCE = 0.2;
	/**
	 * Ticks between two checks of an active geyser while no entity is in reach of its column (Java checks every tick;
	 * the column is checked every tick while entities are near).
	 */
	public const IDLE_GEYSER_INTERVAL_TICKS = 4;
	/** Ticks between two sounds of an active geyser. */
	public const ACTIVE_SOUND_INTERVAL_TICKS = 40;
	/** Salt of the positional random deciding the countdowns (Java PotentSulfurBlockEntity.GEYSER_SALT). */
	public const GEYSER_SALT = -904011478;

	/**
	 * An update that comes more than this many ticks after the previous one of the same countdown waited outside the
	 * ticking chunks (or the block was replaced meanwhile): the countdown is moved forward by the gap.
	 */
	private const COUNTDOWN_PAUSE_GAP_TICKS = 2 * self::GAS_INTERVAL_TICKS;
	/** Countdowns not updated for this long are dropped (they start again) when the store is pruned. */
	private const COUNTDOWN_EXPIRY_TICKS = 6000;
	/** The store of a world is pruned on every insert that makes its size a multiple of this. */
	private const COUNTDOWN_PRUNE_STEP = 64;

	/**
	 * Countdowns of the DORMANT and ERUPTING blocks of each world, by block hash: the world age (ticks) at which it
	 * ends, the world age of its last update, and whether it is the countdown of an eruption.
	 * @var \WeakMap<World, \ArrayObject<int, array{int, int, bool}>>|null
	 */
	private static ?\WeakMap $countdowns = null;

	protected PotentSulfurState $sulfurState = PotentSulfurState::DRY;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->sulfurState);
	}

	public function getSulfurState() : PotentSulfurState{ return $this->sulfurState; }

	/** @return $this */
	public function setSulfurState(PotentSulfurState $state) : self{
		$this->sulfurState = $state;
		return $this;
	}

	/**
	 * Returns the state a potent sulfur block at the given position should have, given its current state (Java
	 * PotentSulfurBlock.validBlockState()).
	 *
	 * @internal
	 */
	public static function calculateState(World $world, int $x, int $y, int $z, PotentSulfurState $current) : PotentSulfurState{
		if(!$world->isInWorld($x, $y + 1, $z) || !self::isWaterSource($world->getBlockAt($x, $y + 1, $z))){
			return PotentSulfurState::DRY;
		}
		if($world->isInWorld($x, $y - 1, $z)){
			$below = $world->getBlockAt($x, $y - 1, $z);
			if($below instanceof Lava && $below->isSource()){
				return PotentSulfurState::CONTINUOUS;
			}
			if($below->getTypeId() === BlockTypeIds::MAGMA){
				return $current === PotentSulfurState::ERUPTING ? PotentSulfurState::ERUPTING : PotentSulfurState::DORMANT;
			}
		}
		return PotentSulfurState::WET;
	}

	private static function isWaterSource(Block $block) : bool{
		return ($block instanceof Water && $block->isSource()) || LiquidLayer::holdsWater($block);
	}

	/**
	 * Gas and geyser plumes go through air, water and blocks without collision boxes.
	 */
	private static function isPassable(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::AIR || $block instanceof Water || count($block->getCollisionBoxes()) === 0;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$pos = $blockReplace->getPosition();
		$this->sulfurState = self::calculateState($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), PotentSulfurState::DRY);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onPostPlace() : void{
		$this->onStateChanged(PotentSulfurState::DRY);
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$state = self::calculateState($world, $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ(), $this->sulfurState);
		if($state !== $this->sulfurState){
			$this->changeState($state);
		}else{
			$this->ensureScheduled();
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		self::clearCountdown($this->position->getWorld(), $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		//the block reschedules itself forever while it isn't dry: like the Java block entity, it only runs in ticking
		//chunks, not in every loaded chunk
		return true;
	}

	public function ticksRandomly() : bool{
		return $this->sulfurState !== PotentSulfurState::DRY;
	}

	/**
	 * Wakes up blocks that have no scheduled update (worlds saved without them, generated springs, which are placed
	 * wet), checking their state first.
	 */
	public function onRandomTick() : void{
		$this->onNearbyBlockChange();
	}

	private function ensureScheduled() : void{
		if($this->sulfurState === PotentSulfurState::DRY){
			return;
		}
		$world = $this->position->getWorld();
		if(!$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
			$world->scheduleDelayedBlockUpdate($this->position, $this->getUpdateInterval());
		}
	}

	private function getUpdateInterval() : int{
		return match($this->sulfurState){
			PotentSulfurState::ERUPTING, PotentSulfurState::CONTINUOUS => 1,
			default => self::GAS_INTERVAL_TICKS
		};
	}

	private function changeState(PotentSulfurState $state) : void{
		$old = $this->sulfurState;
		$this->sulfurState = $state;
		$this->position->getWorld()->setBlock($this->position, $this);
		$this->onStateChanged($old);
	}

	/**
	 * Called when the block got its state: starts the countdown again, plays the eruption sound and schedules the
	 * updates of the new state.
	 */
	private function onStateChanged(PotentSulfurState $old) : void{
		$world = $this->position->getWorld();
		if($old !== $this->sulfurState){
			self::clearCountdown($world, $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		}
		if($this->sulfurState === PotentSulfurState::ERUPTING || $this->sulfurState === PotentSulfurState::CONTINUOUS){
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new GeyserEruptionStartSound($this->sulfurState === PotentSulfurState::CONTINUOUS));
			$world->addGameEvent($this->position, GameEvent::BLOCK_ACTIVATE, null, $this);
		}
		$this->ensureScheduled();
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		if($this->sulfurState === PotentSulfurState::DRY){
			self::clearCountdown($world, $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$sourceY = self::findGasSourceY($world, $x, $y, $z);
		$tick = $world->getWorldAge();
		$delay = $this->getUpdateInterval();
		if($delay === 1){
			//an active geyser with nothing above it checks again a little later, on ticks that are multiples of the idle
			//interval (so the 40-tick sound still plays on time)
			$delay = self::IDLE_GEYSER_INTERVAL_TICKS - ($tick % self::IDLE_GEYSER_INTERVAL_TICKS);
		}
		if($sourceY !== null){
			$waterBlocks = $sourceY - $y - 1;
			switch($this->sulfurState){
				case PotentSulfurState::WET:
					self::releaseGas($world, $x, $sourceY, $z);
					break;
				case PotentSulfurState::DORMANT:
					self::releaseGas($world, $x, $sourceY, $z);
					if($this->isCountdownOver($world, $waterBlocks, $tick)){
						$this->changeState(PotentSulfurState::ERUPTING);
						return;
					}
					break;
				case PotentSulfurState::ERUPTING:
				case PotentSulfurState::CONTINUOUS:
					if(self::launchEntities($world, $x, $y, $z, $waterBlocks)){
						$delay = 1;
					}
					if($tick % self::ACTIVE_SOUND_INTERVAL_TICKS === 0){
						$world->addSound(new Vector3($x + 0.5, $sourceY + 0.5, $z + 0.5), new GeyserEruptionActiveSound($this->sulfurState === PotentSulfurState::CONTINUOUS));
					}
					if($this->sulfurState === PotentSulfurState::ERUPTING && $this->isCountdownOver($world, $waterBlocks, $tick)){
						$this->changeState(PotentSulfurState::DORMANT);
						$world->addGameEvent($this->position, GameEvent::BLOCK_DEACTIVATE, null, $this);
						return;
					}
					break;
				default:
					break;
			}
		}
		$world->scheduleDelayedBlockUpdate($this->position, $delay);
	}

	/**
	 * Returns the Y of the block the gas comes out of: the first open block above the water blocks over the sulfur
	 * (at most 4 of them), or null if a solid block is in the way.
	 *
	 * @internal
	 */
	public static function findGasSourceY(World $world, int $x, int $y, int $z) : ?int{
		for($sourceY = $y + 1, $maxY = $y + self::MAX_WATER_BLOCKS + 1; $sourceY <= $maxY; ++$sourceY){
			if(!$world->isInWorld($x, $sourceY, $z)){
				return null;
			}
			$block = $world->getBlockAt($x, $sourceY, $z);
			if($block->getTypeId() === BlockTypeIds::AIR){
				return $sourceY;
			}
			if(self::isWaterSource($block) && ($block instanceof Water || self::isPassable($block))){
				continue;
			}
			return self::isPassable($block) ? $sourceY : null;
		}
		return null;
	}

	/**
	 * Returns the number of 20-tick steps a countdown of the given state lasts, from the positional random of the
	 * world seed (the same every time for a block).
	 *
	 * @internal
	 */
	public static function getCountdownSteps(int $seed, int $x, int $y, int $z, PotentSulfurState $state, int $waterBlocks) : int{
		$random = XoroshiroRandom::create($seed ^ self::GEYSER_SALT)->forkPositional()->at($x, $y, $z);
		if($state === PotentSulfurState::DORMANT){
			return 10 * ($waterBlocks - 1) + 15 + $random->nextBoundedInt(16);
		}
		$random->nextInt();
		return $waterBlocks - 1 + 1 + $random->nextBoundedInt(2);
	}

	/**
	 * Returns whether the countdown of the current state (DORMANT or ERUPTING) is over, starting it if needed. The
	 * countdown only runs while the block gets its updates: they wait outside the ticking chunks, and the time spent
	 * waiting is added to the countdown, like Java, whose block entity only ticks in ticking chunks.
	 */
	private function isCountdownOver(World $world, int $waterBlocks, int $tick) : bool{
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$store = self::getCountdownStore($world);
		$hash = World::blockHash($x, $y, $z);
		$erupting = $this->sulfurState === PotentSulfurState::ERUPTING;
		$entry = $store[$hash] ?? null;
		if($entry === null || $entry[2] !== $erupting || $entry[1] > $tick){
			//no countdown, or one left by a block of another state at this position
			$end = $tick + self::getCountdownSteps($world->getSeed(), $x, $y, $z, $this->sulfurState, $waterBlocks) * self::COUNTDOWN_STEP_TICKS;
			$store[$hash] = [$end, $tick, $erupting];
			if($entry === null && $store->count() % self::COUNTDOWN_PRUNE_STEP === 0){
				self::pruneCountdowns($store, $tick);
			}
			return false;
		}
		[$end, $last] = $entry;
		if($tick - $last > self::COUNTDOWN_PAUSE_GAP_TICKS){
			$end += $tick - $last;
		}
		$store[$hash] = [$end, $tick, $erupting];
		return $tick >= $end;
	}

	/**
	 * @return \ArrayObject<int, array{int, int, bool}>
	 */
	private static function getCountdownStore(World $world) : \ArrayObject{
		self::$countdowns ??= new \WeakMap();
		return self::$countdowns[$world] ??= new \ArrayObject();
	}

	/**
	 * Drops the countdowns that weren't updated for a long time: blocks that were removed, or that stayed in chunks
	 * which aren't ticked or loaded any more (their countdown starts again, like after a restart).
	 *
	 * @param \ArrayObject<int, array{int, int, bool}> $store
	 */
	private static function pruneCountdowns(\ArrayObject $store, int $tick) : void{
		$expired = [];
		foreach($store as $hash => [, $last, ]){
			if($tick - $last > self::COUNTDOWN_EXPIRY_TICKS){
				$expired[] = $hash;
			}
		}
		foreach($expired as $hash){
			unset($store[$hash]);
		}
	}

	private static function clearCountdown(World $world, int $x, int $y, int $z) : void{
		if(self::$countdowns === null || !isset(self::$countdowns[$world])){
			return;
		}
		$store = self::$countdowns[$world];
		unset($store[World::blockHash($x, $y, $z)]);
	}

	/**
	 * @internal for tests: the number of countdowns kept for the world.
	 */
	public static function getCountdownCount(World $world) : int{
		return self::$countdowns !== null && isset(self::$countdowns[$world]) ? self::$countdowns[$world]->count() : 0;
	}

	/**
	 * Gives Nausea to the living entities reached by the gas coming out at the given position.
	 */
	private static function releaseGas(World $world, int $x, int $sourceY, int $z) : void{
		$bb = new AxisAlignedBB($x - 2.5, $sourceY, $z - 2.5, $x + 3.5, $sourceY + 1, $z + 3.5);
		$center = null;
		foreach($world->getNearbyEntities($bb) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || ($entity instanceof Player && $entity->isSpectator())){
				continue;
			}
			$center ??= new Vector3($x + 0.5, $sourceY + 0.5, $z + 0.5);
			if(self::canBeReachedByGas($world, $center, $entity->getEyePos())){
				$entity->getEffects()->add(new EffectInstance(VanillaEffects::NAUSEA(), self::NAUSEA_DURATION_TICKS, 0, true, true));
			}
		}
	}

	/**
	 * Returns whether eyes at the given position are reached by the gas coming out of the block whose centre is given
	 * (Java canBeReachedByNoxiousGas()): they are in an open block within 3 blocks of it, just above a water source
	 * seen from the top water block.
	 *
	 * @internal
	 */
	public static function canBeReachedByGas(World $world, Vector3 $sourceCenter, Vector3 $eyePos) : bool{
		$eyeX = (int) floor($eyePos->x);
		$eyeY = (int) floor($eyePos->y);
		$eyeZ = (int) floor($eyePos->z);
		if(!$world->isInWorld($eyeX, $eyeY - 1, $eyeZ) || !self::isPassable($world->getBlockAt($eyeX, $eyeY, $eyeZ))){
			return false;
		}
		if($eyePos->distanceSquared($sourceCenter) > self::GAS_RANGE ** 2){
			return false;
		}
		$below = $eyePos->subtract(0, 1, 0);
		if(!self::isWaterSource($world->getBlockAt((int) floor($below->x), (int) floor($below->y), (int) floor($below->z)))){
			return false;
		}
		return self::hasLineOfSight($world, $sourceCenter->subtract(0, 1, 0), $below);
	}

	/**
	 * Returns whether no collision box lies between the two points (Java ClipContext.Block.COLLIDER).
	 */
	private static function hasLineOfSight(World $world, Vector3 $start, Vector3 $end) : bool{
		if($start->distanceSquared($end) < 0.000001){
			return true;
		}
		foreach(VoxelRayTrace::betweenPoints($start, $end) as $pos){
			foreach($world->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z)->getCollisionBoxes() as $box){
				if($box->calculateIntercept($start, $end) !== null){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Returns the height of the geyser column starting at the given position: up to the first block that isn't
	 * passable, at most the given height.
	 *
	 * @internal
	 */
	public static function getColumnHeight(World $world, int $x, int $startY, int $z, int $maxHeight) : int{
		for($i = 0; $i < $maxHeight; ++$i){
			if(!$world->isInWorld($x, $startY + $i, $z) || !self::isPassable($world->getBlockAt($x, $startY + $i, $z))){
				return $i;
			}
		}
		return $maxHeight;
	}

	/**
	 * Pushes up the entities in the column of an erupting geyser. Returns whether there were entities in reach of it.
	 */
	private static function launchEntities(World $world, int $x, int $y, int $z, int $waterBlocks) : bool{
		$maxHeight = self::LAUNCH_HEIGHT_PER_WATER_BLOCK * $waterBlocks;
		if($maxHeight <= 0){
			return false;
		}
		$entities = $world->getNearbyEntities(new AxisAlignedBB($x, $y + 1, $z, $x + 1, $y + 1 + $maxHeight, $z + 1));
		if(count($entities) === 0){
			return false;
		}
		$height = self::getColumnHeight($world, $x, $y + 1, $z, $maxHeight);
		if($height <= 0){
			return true;
		}
		$column = new AxisAlignedBB($x, $y + 1, $z, $x + 1, $y + 1 + $height, $z + 1);
		$maxSpeed = self::LAUNCH_BASE_SPEED + self::LAUNCH_SPEED_PER_WATER_BLOCK * $waterBlocks;
		foreach($entities as $entity){
			if($height < $maxHeight && !$entity->getBoundingBox()->intersectsWith($column)){
				continue;
			}
			self::launchEntity($entity, $maxSpeed);
		}
		return true;
	}

	private static function launchEntity(Entity $entity, float $maxSpeed) : void{
		if($entity->isClosed() || !$entity->isAlive() || $entity->isRiding() || $entity instanceof EnderDragon){
			return;
		}
		if($entity instanceof Player && ($entity->isSpectator() || $entity->isFlying())){
			return;
		}
		$motion = $entity->getMotion();
		//Java checkFallDistanceAccumulation(): rising entities don't keep the fall distance they had
		if($motion->y > -0.5 && $entity->getFallDistance() > 1){
			$entity->setFallDistance(1);
		}
		if($entity instanceof Player){
			return; //the client moves the player
		}
		if($motion->y < $maxSpeed){
			$entity->addMotion(0, self::LAUNCH_FORCE, 0);
		}
	}
}
