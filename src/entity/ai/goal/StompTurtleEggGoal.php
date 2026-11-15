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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\TurtleEgg;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Mob;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\particle\ItemBreakParticle;
use pocketmine\world\sound\MobSound;
use pocketmine\world\World;
use function abs;
use function floor;
use function max;
use function min;
use const PHP_INT_MAX;

/**
 * Walks to a turtle egg nearby and stomps on it until it breaks (vanilla behavior.stomp_turtle_egg of zombies, husks,
 * zombie villagers and drowned; Java ZombieAttackTurtleEggGoal, a RemoveBlockGoal): once within the goal radius of the
 * eggs the mob hops up and down on them with the stomping sound for 3 seconds, then the eggs are gone. Needs
 * mobGriefing.
 *
 * The search runs once every $interval ticks (Bedrock: a 1 in interval chance per tick), and first skips every subchunk of the
 * searched box whose palette has no turtle egg: where there are no eggs it costs a few palette checks.
 */
class StompTurtleEggGoal extends Goal{
	/** Ticks of stomping on the eggs before they break (Java RemoveBlockGoal: more than 60). */
	public const STOMP_TICKS = 60;
	/** Longest run of the goal (Java MoveToBlockGoal: 1200 ticks of trying, counted from a 600 tick give-up). */
	private const MAX_TICKS = 600;
	private const REPATH_TICKS = 20;

	/**
	 * @var bool[] state ID => whether it is a turtle egg
	 * @phpstan-var array<int, bool>
	 */
	private static array $eggStates = [];

	private ?Vector3 $target = null;
	private int $ticks = 0;
	/** Ticks spent on the eggs, -1 while walking there. */
	private int $stompTicks = -1;
	/** AI tick of the next search. */
	private int $nextSearchTick = 0;

	public function __construct(
		protected Mob $mob,
		protected float $speedMultiplier = 1.0,
		protected int $searchRange = 10,
		protected int $searchHeight = 2,
		protected float $goalRadius = 1.14,
		protected int $interval = 20
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if($mob->getAiTick() < $this->nextSearchTick){
			return false;
		}
		$this->nextSearchTick = $mob->getAiTick() + $this->interval;
		if(!GameRules::allowsMobGriefing($mob->getWorld())){
			return false;
		}
		$loc = $mob->getLiveLocation();
		$this->target = self::findNearestEgg($mob->getWorld(), (int) floor($loc->x), (int) floor($loc->y + 0.1), (int) floor($loc->z), $this->searchRange, $this->searchHeight);
		return $this->target !== null;
	}

	public function canContinue() : bool{
		$target = $this->target;
		return $target !== null && $this->ticks < self::MAX_TICKS && GameRules::allowsMobGriefing($this->mob->getWorld()) &&
			$this->mob->getWorld()->getBlockAt((int) $target->x, (int) $target->y, (int) $target->z) instanceof TurtleEgg;
	}

	public function start() : void{
		$this->ticks = 0;
		$this->stompTicks = -1;
	}

	public function stop() : void{
		$this->target = null;
		$this->mob->getNavigation()->stop();
	}

	/**
	 * Returns the eggs the mob is going to or stomping on, null when the goal isn't running.
	 */
	public function getTarget() : ?Vector3{
		return $this->target;
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
		if($dx * $dx + $dz * $dz > $this->goalRadius * $this->goalRadius || abs($target->y - $loc->y) > 1.5){
			$this->stompTicks = -1;
			if($this->ticks % self::REPATH_TICKS === 1 || $mob->getNavigation()->isDone()){
				if(!$mob->getNavigation()->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, $this->speedMultiplier) && $this->ticks > self::REPATH_TICKS * 3){
					$this->target = null; //no way there
				}
			}
			return;
		}
		$this->stomp($target);
	}

	/**
	 * One tick on the eggs (Java RemoveBlockGoal.tick()): up and down every other tick, the stomping sound every 6
	 * ticks, and the eggs break after {@link StompTurtleEggGoal::STOMP_TICKS} ticks.
	 */
	private function stomp(Vector3 $target) : void{
		$mob = $this->mob;
		$world = $mob->getWorld();
		$mob->getNavigation()->stop();
		$stompTicks = ++$this->stompTicks;
		$motion = $mob->getMotion();
		if($stompTicks % 2 === 0){
			$mob->setMotion(new Vector3($motion->x, -0.3, $motion->z));
			if($stompTicks % 6 === 0){
				$center = $target->add(0.5, 0.7, 0.5);
				$world->addSound($center, new MobSound(LevelSoundEvent::BLOCK_TURTLE_EGG_ATTACK, ""));
				$world->addParticle($center, new ItemBreakParticle(VanillaItems::EGG()));
			}
		}elseif($mob->isOnGround()){
			$mob->setMotion(new Vector3($motion->x, 0.3, $motion->z));
		}
		if($stompTicks <= self::STOMP_TICKS){
			return;
		}
		$block = $world->getBlockAt((int) $target->x, (int) $target->y, (int) $target->z);
		if($block instanceof TurtleEgg){
			//Java removes the whole block, with puffs of smoke and the break sound
			$world->setBlock($target, VanillaBlocks::AIR());
			$center = $target->add(0.5, 0.5, 0.5);
			$world->addParticle($center, new BlockBreakParticle($block));
			$world->addSound($center, new MobSound(LevelSoundEvent::BLOCK_TURTLE_EGG_BREAK, ""));
		}
		$this->target = null;
	}

	private static function isEggState(int $stateId) : bool{
		return self::$eggStates[$stateId] ??= RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId) instanceof TurtleEgg;
	}

	/**
	 * Returns the nearest turtle egg with two free blocks above it (Java RemoveBlockGoal.isValidTarget()) within $range
	 * blocks horizontally and $height blocks vertically, or null.
	 */
	public static function findNearestEgg(World $world, int $x, int $y, int $z, int $range, int $height) : ?Vector3{
		$minY = max($y - $height, $world->getMinY());
		$maxY = min($y + $height, $world->getMaxY() - 3);
		if($minY > $maxY){
			return null;
		}
		$best = null;
		$bestDistance = PHP_INT_MAX;
		for($chunkX = ($x - $range) >> Chunk::COORD_BIT_SIZE, $maxChunkX = ($x + $range) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxChunkX; ++$chunkX){
			for($chunkZ = ($z - $range) >> Chunk::COORD_BIT_SIZE, $maxChunkZ = ($z + $range) >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxChunkZ; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				for($subY = $minY >> SubChunk::COORD_BIT_SIZE, $maxSubY = $maxY >> SubChunk::COORD_BIT_SIZE; $subY <= $maxSubY; ++$subY){
					$subChunk = $chunk->getSubChunk($subY);
					$layers = $subChunk->getBlockLayers();
					if(!isset($layers[0])){
						continue;
					}
					$candidate = false;
					foreach($layers[0]->getPalette() as $stateId){
						if(self::$eggStates[$stateId] ?? self::isEggState($stateId)){
							$candidate = true;
							break;
						}
					}
					if(!$candidate){
						continue;
					}
					//rare: scan the part of the box in this subchunk
					$baseX = $chunkX << Chunk::COORD_BIT_SIZE;
					$baseY = $subY << SubChunk::COORD_BIT_SIZE;
					$baseZ = $chunkZ << Chunk::COORD_BIT_SIZE;
					for($bx = max($x - $range, $baseX), $endX = min($x + $range, $baseX + 15); $bx <= $endX; ++$bx){
						for($bz = max($z - $range, $baseZ), $endZ = min($z + $range, $baseZ + 15); $bz <= $endZ; ++$bz){
							for($by = max($minY, $baseY), $endY = min($maxY, $baseY + 15); $by <= $endY; ++$by){
								if(!self::isEggState($subChunk->getBlockStateId($bx & SubChunk::COORD_MASK, $by & SubChunk::COORD_MASK, $bz & SubChunk::COORD_MASK))){
									continue;
								}
								$distance = ($bx - $x) ** 2 + ($by - $y) ** 2 + ($bz - $z) ** 2;
								if($distance < $bestDistance &&
									$world->getBlockAt($bx, $by + 1, $bz)->getTypeId() === BlockTypeIds::AIR &&
									$world->getBlockAt($bx, $by + 2, $bz)->getTypeId() === BlockTypeIds::AIR
								){
									$best = new Vector3($bx, $by, $bz);
									$bestDistance = $distance;
								}
							}
						}
					}
				}
			}
		}
		return $best;
	}
}
