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

namespace pocketmine\entity\ai;

use pocketmine\entity\Mob;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;
use pocketmine\world\World;
use function abs;
use function cos;
use function count;
use function floor;
use function max;
use function sin;
use function sqrt;
use const M_PI;

/**
 * @internal
 * Pushes overlapping mobs apart, and mobs away from the players walking into them: Bedrock
 * minecraft:pushable_by_entity, which almost every mob has (Java LivingEntity.pushEntities() and Entity.push(Entity)).
 * There is no entity cramming damage in Bedrock.
 *
 * The mobs taking part (running their AI or steered by a rider) are kept in a grid of 2x2 block cells, one grid per
 * world. A mob updates its place at the end of its tick only when its collision box changed (it moved: every movement
 * replaces the box object) or something touched it on this tick; it is then "dirty" (see {@link Mob::entityBaseTick()}).
 * Once per tick, before the first mob moves (see {@link Mob::onUpdate()}), each dirty mob is checked against the mobs of
 * the cells its box touches, and each player against the mobs of its cells: every pair whose collision boxes overlap is
 * pushed once. Two mobs that didn't move and touched nothing can't have come to overlap, so a crowd standing still (a
 * pen at rest, a trading hall) costs almost nothing, and there is no world query per mob.
 *
 * Push of a pair (Java Entity.push(Entity)): away from each other along the line between their centres, 0.05 per tick
 * scaled by sqrt(d) up to a distance d of 1 block (d is the larger horizontal offset), 0.05 / sqrt(d) beyond. Java runs
 * it from both entities of a pair on every tick, so an entity that can be pushed gets it twice, once if the other can't
 * be pushed: the same total is queued here (see {@link Mob::queueEntityPush()}). Players push mobs but are never pushed
 * by the server: their clients move them, and push them out of mobs themselves.
 */
final class MobPushing{
	/** 1 / grid cell size (2 blocks): most mobs are in one or two cells. */
	private const CELL_SCALE = 0.5;
	/** Collision boxes over more cells than this (huge plugin entities) don't take part. */
	private const MAX_CELLS_PER_ENTITY = 64;
	/** Java Entity.push(Entity): push per tick of a pair, for one of its two entities. */
	private const PUSH = 0.05;
	/**
	 * Below this larger horizontal offset Java doesn't push at all (entities spawned at the same point would stay there
	 * until something else moves them): here they are pushed as if they were this far apart, in a direction taken from
	 * their IDs.
	 */
	private const MIN_OFFSET = 0.01;

	/** @phpstan-var \WeakMap<World, MobPushing>|null */
	private static ?\WeakMap $byWorld = null;

	/** @internal Tick of the last push pass (see {@link MobPushing::beforeMobUpdate()}). */
	public int $passTick = -1;

	/**
	 * Grid cell key => entity ID => mob.
	 * @var Mob[][]
	 * @phpstan-var array<int, array<int, Mob>>
	 */
	private array $cells = [];
	/**
	 * Members: entity ID => collision box when it last updated its place, and the range of cells of that box.
	 * @var AxisAlignedBB[]
	 * @phpstan-var array<int, AxisAlignedBB>
	 */
	private array $boxes = [];
	/**
	 * Members: entity ID => range of cells of the box, [minimum X, minimum Z, maximum X, maximum Z] (one lookup for the
	 * four of them: every moving mob compares them on every tick).
	 * @var int[][]
	 * @phpstan-var array<int, array{int, int, int, int}>
	 */
	private array $cellRanges = [];
	/**
	 * Members: entity ID => whether the class of the mob keeps Entity::getBoundingBox(), so that its live box is its
	 * boundingBox property (what {@link Mob::getLiveBoundingBoxOf()} returns, worked out once per member instead of for
	 * every pair); otherwise the box it had when it last updated its place is used, as before.
	 * @var bool[]
	 * @phpstan-var array<int, bool>
	 */
	private array $plainBoxes = [];

	/** Tick of the dirty mobs (they updated at the end of it, they are checked on the next one). */
	private int $dirtyTick = -1;
	/**
	 * @var Mob[]
	 * @phpstan-var array<int, Mob|null>
	 */
	private array $dirty = [];
	/**
	 * Entity IDs of the dirty mobs, same keys as {@link MobPushing::$dirty}.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $dirtyIds = [];
	private int $dirtyCount = 0;
	/**
	 * Entity ID => tick on which the mob was last dirty.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $dirtySince = [];

	private function __construct(
		public readonly int $worldId
	){}

	/**
	 * Returns the push state of the world (forgotten with the world: it holds no reference to it, and the mobs leave it
	 * when they are closed).
	 */
	public static function get(World $world) : self{
		$byWorld = self::$byWorld ??= new \WeakMap();
		if(!isset($byWorld[$world])){
			$byWorld[$world] = new self($world->getId());
		}
		return $byWorld[$world];
	}

	/**
	 * Returns the grid cell coordinate of a block coordinate (floor(v / 2)).
	 */
	public static function cellCoordinate(float $v) : int{
		$v *= self::CELL_SCALE;
		$cell = (int) $v;
		return $v < $cell ? $cell - 1 : $cell;
	}

	/**
	 * Called at the end of the tick of a mob taking part whose collision box changed (or that was touched on this tick):
	 * puts it in the cells of its box and has it checked on the next tick. Returns false if its box is too big to take
	 * part. $id is the mob's entity ID.
	 */
	public function update(Mob $mob, int $id, AxisAlignedBB $box, World $world, int $currentTick) : bool{
		if($this->dirtyTick !== $currentTick){
			//the mobs dirty on the previous tick are checked before the list is filled again (here only when no mob of the
			//grid has been updated yet on this tick)
			$this->beforeMobUpdate($world, $currentTick);
			$this->dirtyTick = $currentTick;
		}
		//cellCoordinate() inlined: this runs for every mob that moved
		$x0 = (int) floor($box->minX * self::CELL_SCALE);
		$x1 = (int) floor($box->maxX * self::CELL_SCALE);
		$z0 = (int) floor($box->minZ * self::CELL_SCALE);
		$z1 = (int) floor($box->maxZ * self::CELL_SCALE);
		//a mob has a cell range exactly while it has a box here (both are set and removed together)
		$range = $this->cellRanges[$id] ?? null;
		if($range !== null){
			if($x0 !== $range[0] || $z0 !== $range[1] || $x1 !== $range[2] || $z1 !== $range[3]){
				$this->removeFromCells($id);
				if(!$this->addToCells($mob, $id, $x0, $z0, $x1, $z1)){
					return false;
				}
			}
		}elseif(!$this->addToCells($mob, $id, $x0, $z0, $x1, $z1)){
			return false;
		}
		$this->boxes[$id] = $box;
		if(($this->dirtySince[$id] ?? -1) !== $currentTick){
			$this->dirtySince[$id] = $currentTick;
			$this->dirtyIds[$this->dirtyCount] = $id;
			$this->dirty[$this->dirtyCount++] = $mob;
		}
		return true;
	}

	/**
	 * Takes a mob out of the grid (closed, far from players, riding, moved to another world...).
	 */
	public function leave(Mob $mob) : void{
		$id = $mob->getId();
		if(isset($this->boxes[$id])){
			$this->removeFromCells($id);
			if(count($this->boxes) === 0){
				$this->releaseMemory();
			}
		}
		unset($this->dirtySince[$id]);
	}

	/**
	 * The last member left: PHP arrays keep their size when emptied, so a world that once had many mobs would keep
	 * hundreds of KB here (and the closed mobs in the dirty list) until a mob takes part again. Nothing that changes
	 * what the grid does is dropped: the closed mobs of the dirty list are skipped by the next pass anyway, and only
	 * the entries of {@link MobPushing::$dirtySince} on the dirty tick are ever compared.
	 */
	private function releaseMemory() : void{
		$this->cells = [];
		$this->boxes = [];
		$this->cellRanges = [];
		$this->plainBoxes = [];
		$dirty = [];
		$dirtyIds = [];
		for($i = 0, $count = $this->dirtyCount; $i < $count; ++$i){
			$mob = $this->dirty[$i];
			if($mob !== null && !$mob->isClosed()){
				$dirty[] = $mob;
				$dirtyIds[] = $this->dirtyIds[$i];
			}
		}
		$this->dirty = $dirty;
		$this->dirtyIds = $dirtyIds;
		$this->dirtyCount = count($dirty);
		$dirtySince = [];
		foreach($this->dirtySince as $id => $tick){
			if($tick === $this->dirtyTick){
				$dirtySince[$id] = $tick;
			}
		}
		$this->dirtySince = $dirtySince;
	}

	private function addToCells(Mob $mob, int $id, int $x0, int $z0, int $x1, int $z1) : bool{
		if(($x1 - $x0 + 1) * ($z1 - $z0 + 1) > self::MAX_CELLS_PER_ENTITY){
			return false;
		}
		for($cx = $x0; $cx <= $x1; ++$cx){
			for($cz = $z0; $cz <= $z1; ++$cz){
				$this->cells[($cx << 32) | ($cz & 0xffffffff)][$id] = $mob;
			}
		}
		$this->cellRanges[$id] = [$x0, $z0, $x1, $z1];
		$this->plainBoxes[$id] = Mob::getLiveBoundingBoxOf($mob) !== null;
		return true;
	}

	private function removeFromCells(int $id) : void{
		[$x0, $z0, $x1, $z1] = $this->cellRanges[$id];
		for($cx = $x0; $cx <= $x1; ++$cx){
			for($cz = $z0; $cz <= $z1; ++$cz){
				$key = ($cx << 32) | ($cz & 0xffffffff);
				unset($this->cells[$key][$id]);
				if(isset($this->cells[$key]) && count($this->cells[$key]) === 0){
					unset($this->cells[$key]);
				}
			}
		}
		unset($this->boxes[$id], $this->cellRanges[$id], $this->plainBoxes[$id]);
	}

	/**
	 * Called by the mobs of the grid at the start of their update (when {@link MobPushing::$passTick} isn't the current
	 * tick), in their world: checks the mobs dirty on the previous tick and the players, before any mob moves.
	 */
	public function beforeMobUpdate(World $world, int $currentTick) : void{
		if($this->passTick === $currentTick){
			return;
		}
		$this->passTick = $currentTick;
		if(count($this->boxes) > 0 && $world->getId() === $this->worldId){
			if($this->dirtyTick === $currentTick - 1){
				$this->checkDirtyMobs($currentTick);
			}
			$this->checkPlayers($world, $currentTick);
		}
		for($i = 0, $count = $this->dirtyCount; $i < $count; ++$i){
			$this->dirty[$i] = null;
		}
		$this->dirtyCount = 0;
		if(count($this->boxes) === 0 && count($this->dirty) > 0){
			$this->dirty = [];
			$this->dirtyIds = [];
		}
		if(count($this->dirtySince) > 2 * count($this->boxes) + 64){
			$this->dirtySince = [];
		}
	}

	private function checkDirtyMobs(int $currentTick) : void{
		$dirtyTick = $this->dirtyTick;
		$boxes = $this->boxes;
		$plainBoxes = $this->plainBoxes;
		$dirtySince = $this->dirtySince;
		for($i = 0, $count = $this->dirtyCount; $i < $count; ++$i){
			$mob = $this->dirty[$i];
			if($mob === null || $mob->isClosed()){
				continue;
			}
			$id = $this->dirtyIds[$i];
			if(!isset($boxes[$id])){
				continue; //it left
			}
			//its box is only needed once a cell holds another mob (most moving mobs are alone in their cells): nothing runs
			//before that which could replace it
			$box = null;
			$minX = $minY = $minZ = $maxX = $maxY = $maxZ = 0.0;
			//read once: the pass only queues pushes and notifies contacts, nothing in it moves the mob in the grid (a mob
			//closed by an event handler meanwhile leaves it, and pushPair() then skips it)
			[$x0, $z0, $x1, $z1] = $this->cellRanges[$id];
			for($cx = $x0; $cx <= $x1; ++$cx){
				for($cz = $z0; $cz <= $z1; ++$cz){
					$cell = $this->cells[($cx << 32) | ($cz & 0xffffffff)] ?? null;
					if($cell === null || count($cell) === 1){
						continue; //only this mob
					}
					if($box === null){
						//the live box (see below)
						$box = $plainBoxes[$id] ? $mob->boundingBox : $boxes[$id];
						$minX = $box->minX;
						$minY = $box->minY;
						$minZ = $box->minZ;
						$maxX = $box->maxX;
						$maxY = $box->maxY;
						$maxZ = $box->maxZ;
					}
					foreach($cell as $otherId => $other){
						//a pair of dirty mobs is checked once, from the one with the lower ID
						if($otherId === $id || ($otherId < $id && ($dirtySince[$otherId] ?? -1) === $dirtyTick)){
							continue;
						}
						//the live box: the one in the grid is stale when something moved the mob after its tick (a teleport)
						//(overlapsInCell() inlined: this runs for every mob near every moving mob)
						$otherBox = $plainBoxes[$otherId] ? $other->boundingBox : $boxes[$otherId];
						if(
							$minX < $otherBox->maxX && $maxX > $otherBox->minX &&
							$minZ < $otherBox->maxZ && $maxZ > $otherBox->minZ &&
							$minY < $otherBox->maxY && $maxY > $otherBox->minY &&
							(int) floor(($minX > $otherBox->minX ? $minX : $otherBox->minX) * self::CELL_SCALE) === $cx &&
							(int) floor(($minZ > $otherBox->minZ ? $minZ : $otherBox->minZ) * self::CELL_SCALE) === $cz
						){
							self::pushPair($mob, $box, $other, $otherBox, $currentTick);
						}
					}
				}
			}
		}
	}

	private function checkPlayers(World $world, int $currentTick) : void{
		foreach($world->getPlayers() as $player){
			if($player->isSpectator() || !$player->isAlive() || $player->getVehicle() !== null){
				continue;
			}
			$box = Mob::getLiveBoundingBoxOf($player);
			if($box === null){
				//a class overriding getBoundingBox() (plugins, test doubles): only connected players
				if(!$player->isConnected()){
					continue;
				}
				$box = $player->getBoundingBox();
			}
			$x0 = self::cellCoordinate($box->minX);
			$x1 = self::cellCoordinate($box->maxX);
			$z0 = self::cellCoordinate($box->minZ);
			$z1 = self::cellCoordinate($box->maxZ);
			if(($x1 - $x0 + 1) * ($z1 - $z0 + 1) > self::MAX_CELLS_PER_ENTITY){
				continue;
			}
			for($cx = $x0; $cx <= $x1; ++$cx){
				for($cz = $z0; $cz <= $z1; ++$cz){
					foreach($this->cells[($cx << 32) | ($cz & 0xffffffff)] ?? [] as $mobId => $mob){
						$mobBox = $this->plainBoxes[$mobId] ? $mob->boundingBox : $this->boxes[$mobId];
						if(self::overlapsInCell($mobBox, $box, $cx, $cz)){
							self::pushPair($mob, $mobBox, $player, $box, $currentTick);
						}
					}
				}
			}
		}
	}

	/**
	 * Returns whether the two boxes overlap, and the corner of the overlap is in the given cell: a pair overlapping in
	 * several cells is pushed only once.
	 */
	private static function overlapsInCell(AxisAlignedBB $a, AxisAlignedBB $b, int $cx, int $cz) : bool{
		return
			$a->minX < $b->maxX && $a->maxX > $b->minX &&
			$a->minZ < $b->maxZ && $a->maxZ > $b->minZ &&
			$a->minY < $b->maxY && $a->maxY > $b->minY &&
			self::cellCoordinate(max($a->minX, $b->minX)) === $cx && self::cellCoordinate(max($a->minZ, $b->minZ)) === $cz;
	}

	/**
	 * Queues the push of two entities whose collision boxes overlap (at least one of them a mob).
	 */
	private static function pushPair(Mob $a, AxisAlignedBB $boxA, Mob|Player $b, AxisAlignedBB $boxB, int $currentTick) : void{
		//a mob no longer updated (frozen outside the simulation distance...) neither pushes nor is pushed
		if(
			$a->isClosed() || !$a->isAlive() || !$a->isPushingUpdated($currentTick) ||
			($b instanceof Mob && ($b->isClosed() || !$b->isAlive() || !$b->isPushingUpdated($currentTick)))
		){
			return;
		}
		$a->notifyEntityContact($b);
		if($b instanceof Mob){
			$b->notifyEntityContact($a);
		}
		$pushedA = $a->canBePushedBy($b) ? $a : null;
		$pushedB = $b instanceof Mob && $b->canBePushedBy($a) ? $b : null;
		if($pushedA === null && $pushedB === null){
			return;
		}
		self::computePush(
			($boxB->minX + $boxB->maxX - $boxA->minX - $boxA->maxX) * 0.5,
			($boxB->minZ + $boxB->maxZ - $boxA->minZ - $boxA->maxZ) * 0.5,
			$a->getId(),
			$b->getId(),
			$dx,
			$dz
		);
		//Java pushes from both sides on every tick: twice, unless the other one can't be pushed (players can)
		if($pushedA !== null){
			$times = $pushedB !== null || $b instanceof Player ? 2 : 1;
			$pushedA->queueEntityPush(-$dx * $times, -$dz * $times);
		}
		if($pushedB !== null){
			$times = $pushedA !== null ? 2 : 1;
			$pushedB->queueEntityPush($dx * $times, $dz * $times);
		}
	}

	/**
	 * Java Entity.push(Entity): sets $pushX and $pushZ to the push on the second entity (the first one gets the
	 * opposite), given the horizontal offset from the first entity to the second one and their IDs.
	 *
	 * @param-out float $pushX
	 * @param-out float $pushZ
	 */
	public static function computePush(float $dx, float $dz, int $idA, int $idB, ?float &$pushX, ?float &$pushZ) : void{
		$offset = max(abs($dx), abs($dz));
		if($offset < self::MIN_OFFSET){
			//stacked: a direction from the IDs, from the lower ID to the higher one, at the push of the smallest offset
			$low = $idA < $idB ? $idA : $idB;
			$high = $idA < $idB ? $idB : $idA;
			$angle = (($low * 31 + $high) % 16) * M_PI / 8;
			$strength = sqrt(self::MIN_OFFSET) * self::PUSH * ($idA < $idB ? 1 : -1);
			$pushX = cos($angle) * $strength;
			$pushZ = sin($angle) * $strength;
			return;
		}
		$distance = sqrt($offset);
		$scale = ($distance < 1 ? self::PUSH : self::PUSH / $distance) / $distance;
		$pushX = $dx * $scale;
		$pushZ = $dz * $scale;
	}
}
