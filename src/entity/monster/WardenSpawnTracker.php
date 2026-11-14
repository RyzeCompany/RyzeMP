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

namespace pocketmine\entity\monster;

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\World;
use function intdiv;
use function max;
use function min;

/**
 * Warning level of a player towards wardens (Java WardenSpawnTracker): every time a sculk shrieker which can summon
 * wardens shrieks because of the player, the level goes up by one (at most once every 10 seconds); at level 4 a warden
 * is summoned. It goes down by one after 10 minutes without warnings.
 *
 * The level is evaluated lazily from server ticks instead of being ticked.
 */
final class WardenSpawnTracker{
	public const MAX_WARNING_LEVEL = 4;
	public const DECREASE_INTERVAL_TICKS = 12000;
	public const WARNING_COOLDOWN_TICKS = 200;
	public const PLAYER_SEARCH_RADIUS = 16;
	/** Size of the box around the shrieker in which an existing warden prevents warnings */
	public const WARDEN_CHECK_DIAMETER = 48;

	private const TAG_TICKS_SINCE_LAST_WARNING = "ticks_since_last_warning"; //TAG_Int
	private const TAG_WARNING_LEVEL = "warning_level"; //TAG_Int
	private const TAG_COOLDOWN_TICKS = "cooldown_ticks"; //TAG_Int

	private int $warningLevel = 0;
	/** Server tick from which the decrease interval is counted */
	private int $lastWarningTick;
	private int $cooldownUntilTick = 0;

	public function __construct(int $currentTick){
		$this->lastWarningTick = $currentTick;
	}

	private function update(int $currentTick) : void{
		$elapsed = $currentTick - $this->lastWarningTick;
		if($elapsed >= self::DECREASE_INTERVAL_TICKS){
			$steps = intdiv($elapsed, self::DECREASE_INTERVAL_TICKS);
			$this->warningLevel = max(0, $this->warningLevel - $steps);
			$this->lastWarningTick += $steps * self::DECREASE_INTERVAL_TICKS;
		}
	}

	public function getWarningLevel(int $currentTick) : int{
		$this->update($currentTick);
		return $this->warningLevel;
	}

	public function setWarningLevel(int $warningLevel) : void{
		$this->warningLevel = max(0, min(self::MAX_WARNING_LEVEL, $warningLevel));
	}

	public function isOnCooldown(int $currentTick) : bool{
		return $currentTick < $this->cooldownUntilTick;
	}

	/**
	 * Raises the level by one, unless a warning happened less than 10 seconds ago.
	 */
	public function increaseWarningLevel(int $currentTick) : void{
		$this->update($currentTick);
		if(!$this->isOnCooldown($currentTick)){
			$this->lastWarningTick = $currentTick;
			$this->cooldownUntilTick = $currentTick + self::WARNING_COOLDOWN_TICKS;
			$this->setWarningLevel($this->warningLevel + 1);
		}
	}

	public function copyFrom(WardenSpawnTracker $other) : void{
		$this->warningLevel = $other->warningLevel;
		$this->lastWarningTick = $other->lastWarningTick;
		$this->cooldownUntilTick = $other->cooldownUntilTick;
	}

	public function reset(int $currentTick) : void{
		$this->warningLevel = 0;
		$this->lastWarningTick = $currentTick;
		$this->cooldownUntilTick = 0;
	}

	public function load(CompoundTag $nbt, int $currentTick) : void{
		$this->setWarningLevel($nbt->getInt(self::TAG_WARNING_LEVEL, 0));
		$this->lastWarningTick = $currentTick - max(0, $nbt->getInt(self::TAG_TICKS_SINCE_LAST_WARNING, 0));
		$this->cooldownUntilTick = $currentTick + max(0, $nbt->getInt(self::TAG_COOLDOWN_TICKS, 0));
	}

	public function save(int $currentTick) : CompoundTag{
		$this->update($currentTick);
		return CompoundTag::create()
			->setInt(self::TAG_TICKS_SINCE_LAST_WARNING, max(0, $currentTick - $this->lastWarningTick))
			->setInt(self::TAG_WARNING_LEVEL, $this->warningLevel)
			->setInt(self::TAG_COOLDOWN_TICKS, max(0, $this->cooldownUntilTick - $currentTick));
	}

	/**
	 * Java WardenSpawnTracker.tryWarn(): a shrieker at the given position was set off by the player. Unless a warden
	 * is already nearby or one of the players around is on cooldown, the highest warning level among the players
	 * within 16 blocks (and the trigger player) goes up by one and is shared by all of them.
	 *
	 * @return int|null the new warning level, or null if no warning happened
	 */
	public static function tryWarn(World $world, Vector3 $shriekerPos, Player $trigger) : ?int{
		return self::tryWarnTrackers($world, $shriekerPos, self::getTrackersAround($world, $shriekerPos, $trigger));
	}

	/**
	 * Returns the trackers of the trigger player and of the other players within 16 blocks of the shrieker.
	 *
	 * @return WardenSpawnTracker[]
	 * @phpstan-return list<WardenSpawnTracker>
	 */
	public static function getTrackersAround(World $world, Vector3 $shriekerPos, Player $trigger) : array{
		$center = $shriekerPos->add(0.5, 0.5, 0.5);
		$players = [$trigger->getId() => $trigger];
		foreach($world->getPlayers() as $player){
			if(!$player->isSpectator() && $player->isAlive() && $player->getPosition()->distanceSquared($center) < self::PLAYER_SEARCH_RADIUS ** 2){
				$players[$player->getId()] = $player;
			}
		}
		$trackers = [];
		foreach($players as $player){
			$trackers[] = $player->getWardenSpawnTracker();
		}
		return $trackers;
	}

	/**
	 * Second half of {@link WardenSpawnTracker::tryWarn()}, with the trackers of the players concerned.
	 *
	 * @param WardenSpawnTracker[] $trackers
	 */
	public static function tryWarnTrackers(World $world, Vector3 $shriekerPos, array $trackers) : ?int{
		$center = $shriekerPos->add(0.5, 0.5, 0.5);
		$half = self::WARDEN_CHECK_DIAMETER / 2;
		foreach($world->getNearbyEntities(new AxisAlignedBB($center->x - $half, $center->y - $half, $center->z - $half, $center->x + $half, $center->y + $half, $center->z + $half)) as $entity){
			if($entity instanceof Warden && $entity->isAlive()){
				return null;
			}
		}
		$now = $world->getServer()->getTick();
		$highest = null;
		$highestLevel = -1;
		foreach($trackers as $tracker){
			if($tracker->isOnCooldown($now)){
				return null;
			}
			$level = $tracker->getWarningLevel($now);
			if($level > $highestLevel){
				$highest = $tracker;
				$highestLevel = $level;
			}
		}
		if($highest === null){
			return null;
		}
		$highest->increaseWarningLevel($now);
		foreach($trackers as $tracker){
			if($tracker !== $highest){
				$tracker->copyFrom($highest);
			}
		}
		return $highest->getWarningLevel($now);
	}
}
