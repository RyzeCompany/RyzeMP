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

namespace pocketmine\entity\ai\goal;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityBlockChangeEvent;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function abs;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;

/**
 * Walks to a crop nearby and eats it (vanilla behavior.raid_garden: rabbits and carrots, foxes and sweet berry bushes
 * or cave vines with berries; Java RabbitRaidCarrotGoal and FoxEatBerriesGoal). Once next to the block the mob waits
 * $initialEatDelay ticks, then $eat changes the block (lowers the crop's age, takes the berries). After eating the next
 * search waits $eatDelay ticks, or $fullDelay ticks once the mob ate $maxToEat blocks (0: no limit). Needs mobGriefing.
 *
 * The search runs at most once every 10-20 seconds while it finds nothing (Java MoveToBlockGoal), and first skips
 * every subchunk of the searched box whose palette has no wanted block state: where there are no crops it costs a few
 * palette checks. A block hanging above air (cave vines) is eaten from the block below it.
 */
class RaidGardenGoal extends Goal{
	/** Ticks between two searches which found nothing, plus up to as many at random. */
	public const SEARCH_INTERVAL = 200;
	/** Longest run of the goal. */
	private const MAX_TICKS = 600;
	private const REPATH_TICKS = 20;
	/** Horizontal distance to the block from which the mob walks straight there once its path ended. */
	private const FINAL_APPROACH_DISTANCE = 2.5;
	/** Ticks of walking straight to the block after which the mob gives up walking straight. */
	private const MAX_FINAL_APPROACH_TICKS = 60;

	private ?Vector3 $target = null;
	/** Y of the mob's feet next to the target block. */
	private int $standY = 0;
	private int $ticks = 0;
	/** Ticks spent next to the block, -1 while walking there. */
	private int $eatTicks = -1;
	private int $finalApproachTicks = 0;
	private int $nextSearchTick = 0;
	private int $eaten = 0;

	/**
	 * @phpstan-param \Closure(int) : bool          $isTargetState whether a block state ID is a block to eat (cheap, cached by the caller)
	 * @phpstan-param \Closure(Block) : bool         $eat           eats the block, returns whether it did
	 * @phpstan-param (\Closure() : bool)|null      $condition     extra condition for the goal to start and keep running
	 */
	public function __construct(
		protected Mob $mob,
		protected \Closure $isTargetState,
		protected \Closure $eat,
		protected float $speedMultiplier = 1.0,
		protected int $searchRange = 16,
		protected int $searchHeight = 1,
		protected float $goalRadius = 0.5,
		protected int $initialEatDelay = 0,
		protected int $eatDelay = 40,
		protected int $maxToEat = 6,
		protected int $fullDelay = 2000,
		protected ?\Closure $condition = null
	){
		//the first search is spread over the first search interval, so mobs loaded together don't search together
		//(by entity ID: no random number is drawn)
		$this->nextSearchTick = $mob->getWorld()->getServer()->getTick() + $mob->getId() % self::SEARCH_INTERVAL;
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if($mob->getAiTick() < $this->nextSearchTick || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		$this->nextSearchTick = $mob->getAiTick() + self::SEARCH_INTERVAL + mt_rand(0, self::SEARCH_INTERVAL - 1);
		if(!GameRules::allowsMobGriefing($mob->getWorld())){
			return false;
		}
		$loc = $mob->getLiveLocation();
		$found = $this->findNearest($mob->getWorld(), (int) floor($loc->x), (int) floor($loc->y + 0.1), (int) floor($loc->z));
		if($found === null){
			return false;
		}
		[$this->target, $this->standY] = $found;
		return true;
	}

	public function canContinue() : bool{
		$target = $this->target;
		return $target !== null && $this->ticks < self::MAX_TICKS && ($this->condition === null || ($this->condition)()) &&
			GameRules::allowsMobGriefing($this->mob->getWorld()) && $this->isTargetAt($this->mob->getWorld(), $target);
	}

	public function start() : void{
		$this->ticks = 0;
		$this->eatTicks = -1;
		$this->finalApproachTicks = 0;
	}

	public function stop() : void{
		$this->target = null;
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Makes the next search happen right away (tests, plugins).
	 */
	public function resetSearchDelay() : void{
		$this->nextSearchTick = 0;
	}

	/**
	 * Returns the block the mob is going to or eating, null when the goal isn't running.
	 */
	public function getTarget() : ?Vector3{
		return $this->target;
	}

	private function isTargetAt(World $world, Vector3 $pos) : bool{
		return ($this->isTargetState)($world->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z)->getStateId());
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){
			return;
		}
		++$this->ticks;
		$mob = $this->mob;
		$loc = $mob->getLiveLocation();
		$dx = $target->x + 0.5 - $loc->x;
		$dz = $target->z + 0.5 - $loc->z;
		//the block itself may be in the way (berry bushes are avoided by paths): next to it is close enough
		$reach = $this->goalRadius + $mob->getSize()->getWidth() / 2;
		if($dx * $dx + $dz * $dz > $reach * $reach || abs($this->standY - $loc->y) > 1.0){
			$this->eatTicks = -1;
			$navigation = $mob->getNavigation();
			if($navigation->isDone() && $dx * $dx + $dz * $dz < self::FINAL_APPROACH_DISTANCE ** 2 && $this->finalApproachTicks < self::MAX_FINAL_APPROACH_TICKS){
				//paths end short of blocks that hurt (berry bushes) and of the blocks next to them: straight there
				++$this->finalApproachTicks;
				$mob->getMoveControl()->setWantedPosition($target->x + 0.5, $this->standY, $target->z + 0.5, $this->speedMultiplier);
				return;
			}
			if($this->ticks % self::REPATH_TICKS === 1 || $navigation->isDone()){
				if(!$navigation->moveTo($target->x + 0.5, $this->standY, $target->z + 0.5, $this->speedMultiplier, $reach) && $this->ticks > self::REPATH_TICKS * 3){
					$this->target = null; //no way there
				}
			}
			return;
		}
		$mob->getNavigation()->stop();
		$mob->getLookControl()->setLookAt($target->x + 0.5, $target->y + 0.5, $target->z + 0.5);
		if(++$this->eatTicks < $this->initialEatDelay){
			return;
		}
		$world = $mob->getWorld();
		$block = $world->getBlockAt((int) $target->x, (int) $target->y, (int) $target->z);
		if(($this->isTargetState)($block->getStateId()) && ($this->eat)($block)){
			++$this->eaten;
			if($this->maxToEat > 0 && $this->eaten >= $this->maxToEat){
				$this->eaten = 0;
				$this->nextSearchTick = $mob->getAiTick() + $this->fullDelay;
			}else{
				$this->nextSearchTick = $mob->getAiTick() + $this->eatDelay;
			}
		}
		$this->target = null;
	}

	/**
	 * Replaces a block the mob changes (eats), unless a plugin cancels {@link EntityBlockChangeEvent}. Returns whether
	 * the block was changed.
	 */
	public static function changeBlock(Mob $mob, Block $from, Block $to) : bool{
		$ev = new EntityBlockChangeEvent($mob, $from, $to);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$mob->getWorld()->setBlock($from->getPosition(), $ev->getTo());
		return true;
	}

	/**
	 * Returns the nearest wanted block within the search range around the mob's feet, with the Y of the mob's feet
	 * next to it, or null. The block must be reachable: standing on the ground (a crop, a bush), or hanging with air
	 * below it and the ground under that (cave vines at head height).
	 *
	 * Like Java MoveToBlockGoal.findNearestBlock, the layers are searched by Y offset (0, +1, -1, +2...) and each layer
	 * outwards in square rings, stopping at the first reachable block. Only the subchunks whose palette has a wanted
	 * state are read, and a block is checked with a lookup of its state ID in those palettes' wanted states.
	 *
	 * @phpstan-return array{Vector3, int}|null
	 */
	public function findNearest(World $world, int $x, int $y, int $z) : ?array{
		$range = $this->searchRange;
		$minY = max($y - $this->searchHeight, $world->getMinY() + 2);
		$maxY = min($y + $this->searchHeight + 1, $world->getMaxY() - 1);
		if($minY > $maxY){
			return null;
		}
		$isTargetState = $this->isTargetState;
		$minChunkX = ($x - $range) >> Chunk::COORD_BIT_SIZE;
		$minChunkZ = ($z - $range) >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ($z + $range) >> Chunk::COORD_BIT_SIZE;
		$chunksZ = $maxChunkZ - $minChunkZ + 1;
		$minSubY = $minY >> SubChunk::COORD_BIT_SIZE;
		$maxSubY = $maxY >> SubChunk::COORD_BIT_SIZE;

		//block arrays of the subchunks which have a wanted state: [chunk index][subchunk Y]
		$candidates = [];
		//chunk X => subchunk Y => whether a chunk of that X has a candidate subchunk at that Y
		$candidateColumns = [];
		/** @phpstan-var array<int, true> $targetStates */
		$targetStates = [];
		for($chunkX = $minChunkX, $maxChunkX = ($x + $range) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = $minChunkZ; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				for($subY = $minSubY; $subY <= $maxSubY; ++$subY){
					$layers = $chunk->getSubChunk($subY)->getBlockLayers();
					if(!isset($layers[0])){
						continue;
					}
					$candidate = false;
					foreach($layers[0]->getPalette() as $stateId){
						if($isTargetState($stateId)){
							$targetStates[$stateId] = true;
							$candidate = true;
						}
					}
					if($candidate){
						$candidates[($chunkX - $minChunkX) * $chunksZ + $chunkZ - $minChunkZ][$subY] = $layers[0];
						$candidateColumns[$chunkX][$subY] = true;
					}
				}
			}
		}
		if(count($candidates) === 0){
			return null; //the usual case: no wanted block near
		}

		for($dy = 0, $maxDy = $this->searchHeight + 1; abs($dy) <= $maxDy; $dy = $dy > 0 ? -$dy : 1 - $dy){
			$by = $y + $dy;
			if($by < $minY || $by > $maxY){
				continue;
			}
			$subY = $by >> SubChunk::COORD_BIT_SIZE;
			$localY = $by & SubChunk::COORD_MASK;
			for($r = 0; $r <= $range; ++$r){
				for($dx = -$r; $dx <= $r; ++$dx){
					$bx = $x + $dx;
					if(!isset($candidateColumns[$bx >> Chunk::COORD_BIT_SIZE][$subY])){
						continue;
					}
					$chunkIndex = (($bx >> Chunk::COORD_BIT_SIZE) - $minChunkX) * $chunksZ - $minChunkZ;
					$localX = $bx & SubChunk::COORD_MASK;
					//the whole row on the ring's sides, its two ends elsewhere
					$step = $dx === -$r || $dx === $r ? 1 : max(1, 2 * $r);
					for($dz = -$r; $dz <= $r; $dz += $step){
						$bz = $z + $dz;
						$blocks = $candidates[$chunkIndex + ($bz >> Chunk::COORD_BIT_SIZE)][$subY] ?? null;
						if($blocks === null || !isset($targetStates[$blocks->get($localX, $localY, $bz & SubChunk::COORD_MASK)])){
							continue;
						}
						$standY = self::standY($world, $bx, $by, $bz);
						if($standY !== null){
							return [new Vector3($bx, $by, $bz), $standY];
						}
					}
				}
			}
		}
		return null;
	}

	/**
	 * Y of the feet of a mob eating the block: the block's own Y when it stands on something, the Y below it when it
	 * hangs over one block of air above the ground, else null.
	 */
	private static function standY(World $world, int $x, int $y, int $z) : ?int{
		if($world->getBlockAt($x, $y - 1, $z)->getTypeId() !== BlockTypeIds::AIR){
			return $y;
		}
		return $world->getBlockAt($x, $y - 2, $z)->isSolid() ? $y - 1 : null;
	}
}
