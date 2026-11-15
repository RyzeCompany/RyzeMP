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

use pocketmine\block\Door;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Mob;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\sound\DoorBumpSound;
use pocketmine\world\sound\DoorCrashSound;
use pocketmine\world\World;
use function floor;
use function min;
use function mt_rand;

/**
 * Breaks a closed wooden door on the mob's path (vanilla minecraft:annotation.break_door with navigation
 * can_break_doors; Java BreakDoorGoal): when the mob bumps into a wall and a closed door other than an iron door is in
 * the next nodes of its path within 1.5 blocks (or in its own block), it hits the door (door bump sound and an arm swing
 * 1 tick in 20) while the block cracks, and after the break time the door is gone, with the door crash sound and its
 * break particles, and drops nothing. It gives up when the door opens, the mob gets 2 blocks away from it or the
 * difficulty falls below the minimum. Needs the mobGriefing game rule.
 *
 * The mob's paths must go through closed doors for it to walk into them (see
 * {@link \pocketmine\entity\ai\navigation\PathFinder::setCanBreakDoors()}). The goal holds no control flag: the mob
 * keeps chasing its target into the door while it breaks it.
 */
final class BreakDoorGoal extends Goal{
	/** Vanilla annotation.break_door break_time (12 seconds) and min_difficulty (hard) defaults */
	public const DEFAULT_BREAK_TICKS = 240;
	public const DEFAULT_MIN_DIFFICULTY = World::DIFFICULTY_HARD;

	/** Squared horizontal distance within which a door on the path is reached (vanilla Java DoorInteractGoal: 1.5) */
	private const DOOR_REACH_SQUARED = 2.25;
	/** Distance from the middle of the top half of the door the mob must stay within (vanilla Java BreakDoorGoal: 2) */
	private const STAY_DISTANCE = 2.0;

	/** Lower half of the door being broken */
	private ?Vector3 $door = null;
	private int $breakTicks = 0;

	/**
	 * @param \Closure|null $condition whether the mob breaks doors now (vindicators: only in raids)
	 * @phpstan-param (\Closure() : bool)|null $condition
	 */
	public function __construct(
		private Mob $mob,
		private int $breakTime = self::DEFAULT_BREAK_TICKS,
		private int $minDifficulty = self::DEFAULT_MIN_DIFFICULTY,
		private ?\Closure $condition = null
	){}

	public function getBreakTime() : int{
		return $this->breakTime;
	}

	/**
	 * Returns the lower half of the door being broken, or null.
	 */
	public function getDoor() : ?Vector3{
		return $this->door;
	}

	public function getBreakTicks() : int{
		return $this->breakTicks;
	}

	public function canStart() : bool{
		$mob = $this->mob;
		if(!$mob->isCollidedHorizontally || ($this->condition !== null && !($this->condition)())){
			return false;
		}
		$world = $mob->getWorld();
		if($world->getDifficulty() < $this->minDifficulty || !GameRules::allowsMobGriefing($world)){
			return false;
		}
		$door = $this->findDoor();
		if($door === null){
			return false;
		}
		$this->door = $door;
		return true;
	}

	/**
	 * Returns the lower half of the closed door the mob is at: in the next nodes of its path, or in its own block.
	 */
	private function findDoor() : ?Vector3{
		$mob = $this->mob;
		$world = $mob->getWorld();
		$loc = $mob->getLiveLocation();
		$path = $mob->getNavigation()->getPath();
		if($path !== null && !$path->isDone()){
			for($i = 0, $last = min($path->getIndex() + 2, $path->getLength()); $i < $last; ++$i){
				$node = $path->getNode($i);
				if($node === null){
					continue;
				}
				$dx = $node[0] + 0.5 - $loc->x;
				$dz = $node[2] + 0.5 - $loc->z;
				if($dx * $dx + $dz * $dz <= self::DOOR_REACH_SQUARED && ($door = self::closedDoorAt($world, $node[0], $node[1], $node[2])) !== null){
					return $door;
				}
			}
		}
		return self::closedDoorAt($world, (int) floor($loc->x), (int) floor($loc->y + 0.01), (int) floor($loc->z));
	}

	/**
	 * Returns the lower half of the closed wooden door with a half at the position or the block above it, or null.
	 */
	private static function closedDoorAt(World $world, int $x, int $y, int $z) : ?Vector3{
		for($i = 0; $i <= 1; ++$i){
			$flags = PathBlockTypes::at($world, $x, $y + $i, $z);
			if(($flags & (PathBlockTypes::OPENABLE_DOOR | PathBlockTypes::CLOSED_DOOR)) !== (PathBlockTypes::OPENABLE_DOOR | PathBlockTypes::CLOSED_DOOR)){
				continue;
			}
			$block = $world->getBlockAt($x, $y + $i, $z);
			if($block instanceof Door && !$block->isOpen()){
				return new Vector3($x, $block->isTop() ? $y + $i - 1 : $y + $i, $z);
			}
		}
		return null;
	}

	private function isClosedDoor() : bool{
		$door = $this->door;
		if($door === null){
			return false;
		}
		$block = $this->mob->getWorld()->getBlockAt($door->getFloorX(), $door->getFloorY(), $door->getFloorZ());
		return $block instanceof Door && !$block->isTop() && !$block->isOpen() &&
			(PathBlockTypes::at($this->mob->getWorld(), $door->getFloorX(), $door->getFloorY(), $door->getFloorZ()) & PathBlockTypes::OPENABLE_DOOR) !== 0;
	}

	public function canContinue() : bool{
		$door = $this->door;
		if($door === null || $this->breakTicks > $this->breakTime || !$this->isClosedDoor()){
			return false;
		}
		$loc = $this->mob->getLiveLocation();
		$dx = $door->x + 0.5 - $loc->x;
		//vanilla Java measures from the middle of the top half
		$dy = $door->y + 1.5 - $loc->y;
		$dz = $door->z + 0.5 - $loc->z;
		return $dx * $dx + $dy * $dy + $dz * $dz < self::STAY_DISTANCE ** 2 &&
			$this->mob->getWorld()->getDifficulty() >= $this->minDifficulty &&
			($this->condition === null || ($this->condition)());
	}

	public function start() : void{
		$this->breakTicks = 0;
		if($this->door !== null){
			//the client cracks the block at this rate (1/65535ths of the break per tick) until told to stop
			$this->mob->getWorld()->broadcastPacketToViewers($this->door, LevelEventPacket::create(LevelEvent::BLOCK_START_BREAK, (int) (65535 / $this->breakTime), $this->door));
		}
	}

	public function stop() : void{
		if($this->door !== null){
			$this->mob->getWorld()->broadcastPacketToViewers($this->door, LevelEventPacket::create(LevelEvent::BLOCK_STOP_BREAK, 0, $this->door));
		}
		$this->door = null;
		$this->breakTicks = 0;
	}

	public function tick() : void{
		$door = $this->door;
		if($door === null){
			return;
		}
		$mob = $this->mob;
		$world = $mob->getWorld();
		if(mt_rand(0, 19) === 0){
			$world->addSound($door->add(0.5, 0.5, 0.5), new DoorBumpSound());
			$mob->broadcastAnimation(new ArmSwingAnimation($mob));
		}
		if(++$this->breakTicks === $this->breakTime && $world->getDifficulty() >= $this->minDifficulty){
			$this->breakDoor($world, $door);
		}
	}

	/**
	 * Removes both halves of the door without drops (vanilla Java removeBlock() of the door), with the door crash sound
	 * and the break particles of the door.
	 */
	private function breakDoor(World $world, Vector3 $door) : void{
		$x = $door->getFloorX();
		$y = $door->getFloorY();
		$z = $door->getFloorZ();
		$lower = $world->getBlockAt($x, $y, $z);
		if(!$lower instanceof Door){
			return;
		}
		$air = VanillaBlocks::AIR();
		//the top half first and without updates: the lower half would break it with drops otherwise
		if($world->getBlockAt($x, $y + 1, $z) instanceof Door){
			$world->setBlockAt($x, $y + 1, $z, $air, false);
		}
		$world->setBlockAt($x, $y, $z, $air);
		$centre = $door->add(0.5, 0.5, 0.5);
		$world->addSound($centre, new DoorCrashSound());
		$world->addParticle($centre, new BlockBreakParticle($lower));
	}
}
