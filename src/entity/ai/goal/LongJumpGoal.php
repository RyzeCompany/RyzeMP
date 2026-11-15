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
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\entity\ai\navigation\BlockSearch;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use function atan2;
use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function shuffle;
use function sqrt;
use const M_PI;

/**
 * Jumps onto a block nearby (vanilla behavior.jump_to_block). With the defaults (goats): every 30-60 seconds an idle
 * mob on the ground looks for a standable block 2 to 10 blocks higher within 10 blocks sideways which it couldn't
 * simply walk up to, and leaps there in one ballistic jump (JUMP_GOAL_JUMP flag while in the air). The jump is computed
 * with the server's physics (gravity 0.08, drag 0.02 per tick) and only made if nothing is in the way. Never onto
 * water. Frogs jump up or down, and prefer lily pads and big dripleaves (see the constructor).
 *
 * Simplification: Bedrock's minimum_path_length (the block must be at least 8 path nodes away) is approximated by
 * requiring a height difference of at least 2 blocks, which a walking mob can't climb in one step (or a horizontal
 * distance, see $minDistance).
 */
class LongJumpGoal extends Goal{
	public const SEARCH_WIDTH = 10;
	public const SEARCH_HEIGHT = 10;
	public const MIN_HEIGHT = 2;
	/** Candidate positions tried per search. */
	public const ATTEMPTS = 24;
	public const MAX_VELOCITY = 1.5;
	public const MAX_FLIGHT_TICKS = 60;
	private const LANDING_MARGIN = 0.05;

	private int $readyTick;
	private ?Vector3 $launch = null;
	private int $flightTicks = 0;
	private bool $jumping = false;

	/**
	 * @var bool[] state ID => whether $isPreferred accepts it
	 * @phpstan-var array<int, bool>
	 */
	private array $preferredStates = [];

	/**
	 * @param int   $searchWidth     how far sideways targets are looked for (search_width)
	 * @param int   $minHeight       lowest target, relative to the mob's feet (negative: below it)
	 * @param int   $maxHeight       highest target, relative to the mob's feet (search_height)
	 * @param float $maxVelocity     fastest launch (max_velocity)
	 * @param int   $minDistance     shortest jump sideways, in blocks (a nearer block would just be walked to)
	 * @param float $preferredChance chance for a search to look for a block accepted by $isPreferred first
	 *                               (preferred_blocks_chance)
	 *
	 * @phpstan-param \Closure(bool) : void $onJumpingChanged called when the mob takes off (true) and lands (false)
	 * @phpstan-param (\Closure(Block) : bool)|null $isPreferred blocks to land on preferably (preferred_blocks)
	 */
	public function __construct(
		protected Mob $mob,
		protected int $minCooldownTicks = 600,
		protected int $maxCooldownTicks = 1200,
		protected ?\Closure $onJumpingChanged = null,
		protected int $searchWidth = self::SEARCH_WIDTH,
		protected int $minHeight = self::MIN_HEIGHT,
		protected int $maxHeight = self::SEARCH_HEIGHT,
		protected float $maxVelocity = self::MAX_VELOCITY,
		protected int $minDistance = 0,
		protected ?\Closure $isPreferred = null,
		protected float $preferredChance = 0.0
	){
		$this->readyTick = mt_rand($this->minCooldownTicks, $this->maxCooldownTicks);
	}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if($mob->getAiTick() < $this->readyTick || !$mob->isOnGround() || $mob->isInWater() || $mob->getVehicle() !== null || $mob->getAttackTarget() !== null){
			return false;
		}
		$this->readyTick = $mob->getAiTick() + mt_rand($this->minCooldownTicks, $this->maxCooldownTicks);
		$this->launch = $this->findJump();
		return $this->launch !== null;
	}

	/**
	 * Makes the next search happen right away (tests, plugins).
	 */
	public function resetCooldown() : void{
		$this->readyTick = 0;
	}

	private function findJump() : ?Vector3{
		$mob = $this->mob;
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		$bx = (int) floor($loc->x);
		$by = (int) floor($loc->y);
		$bz = (int) floor($loc->z);
		if($this->isPreferred !== null && $this->preferredChance > 0 && Utils::getRandomFloat() < $this->preferredChance){
			$velocity = $this->findPreferredJump($bx, $by, $bz);
			if($velocity !== null){
				return $velocity;
			}
		}
		$headBlocks = max(1, (int) ceil($mob->getSize()->getHeight()));
		$minDistanceSquared = $this->minDistance * $this->minDistance;
		for($i = 0; $i < self::ATTEMPTS; ++$i){
			$dx = mt_rand(-$this->searchWidth, $this->searchWidth);
			$dz = mt_rand(-$this->searchWidth, $this->searchWidth);
			if($dx * $dx + $dz * $dz < $minDistanceSquared){
				continue;
			}
			$x = $bx + $dx;
			$z = $bz + $dz;
			$y = $by + mt_rand($this->minHeight, $this->maxHeight);
			if(!$world->isInWorld($x, $y, $z) || !PathBlockTypes::isStandable($world, $x, $y, $z, $headBlocks, false)){
				continue;
			}
			$velocity = $this->computeJump($loc, new Vector3($x + 0.5, $y, $z + 0.5));
			if($velocity !== null){
				return $velocity;
			}
		}
		return null;
	}

	/**
	 * Looks for the preferred blocks within the search area and returns a jump onto one of them (picked at random among
	 * the ones the mob can reach), or null. Only subchunks with a preferred block in their palette are read.
	 */
	private function findPreferredJump(int $bx, int $by, int $bz) : ?Vector3{
		$world = $this->mob->getWorld();
		$minY = max($by + $this->minHeight - 1, $world->getMinY());
		$maxY = min($by + $this->maxHeight, $world->getMaxY() - 1);
		$minDistanceSquared = $this->minDistance * $this->minDistance;
		$found = [];
		for($chunkX = ($bx - $this->searchWidth) >> Chunk::COORD_BIT_SIZE, $maxChunkX = ($bx + $this->searchWidth) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = ($bz - $this->searchWidth) >> Chunk::COORD_BIT_SIZE, $maxChunkZ = ($bz + $this->searchWidth) >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				for($subY = $minY >> SubChunk::COORD_BIT_SIZE; $subY <= $maxY >> SubChunk::COORD_BIT_SIZE; ++$subY){
					$layer = $chunk->getSubChunk($subY)->getBlockLayers()[0] ?? null;
					if($layer === null || !$this->hasPreferredState($layer->getPalette())){
						continue;
					}
					$fromX = max($bx - $this->searchWidth, $chunkX << Chunk::COORD_BIT_SIZE);
					$toX = min($bx + $this->searchWidth, ($chunkX << Chunk::COORD_BIT_SIZE) + Chunk::COORD_MASK);
					$fromZ = max($bz - $this->searchWidth, $chunkZ << Chunk::COORD_BIT_SIZE);
					$toZ = min($bz + $this->searchWidth, ($chunkZ << Chunk::COORD_BIT_SIZE) + Chunk::COORD_MASK);
					$fromY = max($minY, $subY << SubChunk::COORD_BIT_SIZE);
					$toY = min($maxY, ($subY << SubChunk::COORD_BIT_SIZE) + SubChunk::COORD_MASK);
					for($x = $fromX; $x <= $toX; ++$x){
						for($z = $fromZ; $z <= $toZ; ++$z){
							if(($x - $bx) ** 2 + ($z - $bz) ** 2 < $minDistanceSquared){
								continue;
							}
							for($y = $fromY; $y <= $toY; ++$y){
								if($this->preferredStates[$layer->get($x & Chunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & Chunk::COORD_MASK)] ?? false){
									$found[] = [$x, $y, $z];
								}
							}
						}
					}
				}
			}
		}
		if(count($found) === 0){
			return null;
		}
		shuffle($found);
		$loc = $this->mob->getLiveLocation();
		$tries = 0;
		foreach($found as [$x, $blockY, $z]){
			//on low blocks (lily pads) the feet are in the block, on others above it
			$y = (PathBlockTypes::at($world, $x, $blockY, $z) & PathBlockTypes::LOW) !== 0 ? $blockY : $blockY + 1;
			if($y < $by + $this->minHeight || $y > $by + $this->maxHeight){
				continue;
			}
			if(!BlockSearch::isDrySpot(PathBlockTypes::at($world, $x, $y + 1, $z), PathBlockTypes::at($world, $x, $y, $z), PathBlockTypes::at($world, $x, $y - 1, $z))){
				continue;
			}
			$velocity = $this->computeJump($loc, new Vector3($x + 0.5, $y, $z + 0.5));
			if($velocity !== null){
				return $velocity;
			}
			if(++$tries >= self::ATTEMPTS){
				break;
			}
		}
		return null;
	}

	/**
	 * @param int[] $palette
	 */
	private function hasPreferredState(array $palette) : bool{
		$isPreferred = $this->isPreferred;
		if($isPreferred === null){
			return false;
		}
		$found = false;
		foreach($palette as $stateId){
			if($this->preferredStates[$stateId] ??= $isPreferred(RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId))){
				$found = true;
			}
		}
		return $found;
	}

	/**
	 * Returns the launch velocity of a jump from $from landing on $to, or null if there is none within the maximum
	 * velocity or something is in the way.
	 */
	public function computeJump(Vector3 $from, Vector3 $to) : ?Vector3{
		$gravity = 0.08;
		$friction = 0.98;
		$dx = $to->x - $from->x;
		//aimed a little above the landing surface: a trajectory ending exactly on it may end a rounding error inside
		//it, and the mob would then fall through the block
		$dy = $to->y + self::LANDING_MARGIN - $from->y;
		$dz = $to->z - $from->z;
		$ground = $this->getGroundFriction();
		for($ticks = 8; $ticks <= 40; ++$ticks){
			//displacement after $ticks ticks: v0 * H for x/z (the first tick still has the ground's friction),
			//v0 * S - C for y (C: what gravity alone does)
			$h = 0.0;
			$s = 0.0;
			$c = 0.0;
			$vyFromGravity = 0.0;
			$fh = $ground;
			$f = 1.0;
			for($i = 1; $i <= $ticks; ++$i){
				$fh *= $friction;
				$h += $fh;
				$f *= $friction;
				$s += $f;
				$vyFromGravity = ($vyFromGravity - $gravity) * $friction;
				$c += $vyFromGravity;
			}
			$vx = $dx / $h;
			$vz = $dz / $h;
			$vy = ($dy - $c) / $s;
			if(sqrt($vx * $vx + $vy * $vy + $vz * $vz) > $this->maxVelocity){
				continue;
			}
			$velocity = new Vector3($vx, $vy, $vz);
			if($this->isClear($from, $velocity, $ticks)){
				return $velocity;
			}
		}
		return null;
	}

	/**
	 * Follows the trajectory and checks that the mob's box doesn't hit a block on the way, and that it comes down
	 * onto the target (falling at the end).
	 */
	private function isClear(Vector3 $from, Vector3 $velocity, int $ticks) : bool{
		$mob = $this->mob;
		$world = $mob->getWorld();
		$box = $mob->getBoundingBox()->offsetCopy($from->x - $mob->getLiveLocation()->x, $from->y - $mob->getLiveLocation()->y, $from->z - $mob->getLiveLocation()->z);
		$vx = $velocity->x * $this->getGroundFriction();
		$vy = $velocity->y;
		$vz = $velocity->z * $this->getGroundFriction();
		for($i = 1; $i <= $ticks; ++$i){
			$vy = ($vy - 0.08) * 0.98;
			$vx *= 0.98;
			$vz *= 0.98;
			$box = $box->offsetCopy($vx, $vy, $vz);
			//the last tick lands on the block: leave a little room for the floor
			$check = $i === $ticks ? $box->offsetCopy(0, 0.1, 0) : $box;
			if(count($world->getBlockCollisionBoxes($check->expandedCopy(-0.001, -0.001, -0.001))) > 0){
				return false;
			}
		}
		return $vy < 0;
	}

	/**
	 * Friction factor of the block the mob stands on: it still slows the mob down on the tick it takes off.
	 */
	private function getGroundFriction() : float{
		$loc = $this->mob->getLiveLocation();
		return $this->mob->getWorld()->getBlockAt((int) floor($loc->x), (int) floor($loc->y - 1), (int) floor($loc->z))->getFrictionFactor();
	}

	public function canContinue() : bool{
		return $this->launch !== null && $this->flightTicks < self::MAX_FLIGHT_TICKS && ($this->flightTicks < 3 || !$this->mob->isOnGround());
	}

	public function start() : void{
		$mob = $this->mob;
		$launch = $this->launch;
		if($launch === null){
			return;
		}
		$mob->getNavigation()->stop();
		$mob->getMoveControl()->stop();
		$mob->setRotation(atan2($launch->z, $launch->x) * 180 / M_PI - 90, 0);
		$mob->setMotion($launch);
		$this->flightTicks = 0;
		$this->setJumping(true);
	}

	public function stop() : void{
		$this->launch = null;
		$this->flightTicks = 0;
		$this->setJumping(false);
	}

	public function tick() : void{
		++$this->flightTicks;
	}

	public function isJumping() : bool{
		return $this->jumping;
	}

	private function setJumping(bool $jumping) : void{
		if($jumping !== $this->jumping){
			$this->jumping = $jumping;
			if($this->onJumpingChanged !== null){
				($this->onJumpingChanged)($jumping);
			}
		}
	}
}
