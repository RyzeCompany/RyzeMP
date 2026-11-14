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

use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenSpawnTracker;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\particle\SculkShriekParticle;
use pocketmine\world\sound\MobSound;
use pocketmine\world\sound\SculkShriekerShriekSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationManager;
use pocketmine\world\World;
use function abs;
use function count;
use function floor;
use function mt_rand;

/**
 * Sculk shrieker (https://minecraft.wiki/w/Sculk_Shrieker), ported from Java's SculkShriekerBlock(Entity).
 *
 * It shrieks for 90 ticks ("active" state) when a player steps on it or when a sculk sensor within 8 blocks detects a
 * vibration caused by a player. Shriekers which can summon wardens (natural ones; never those placed by players or
 * grown by a catalyst) raise the warning level of the players around (see {@link WardenSpawnTracker}); they don't
 * shriek while the players are on warning cooldown. When the shriek ends, such a shrieker gives Darkness to the
 * players within 40 blocks and either summons a warden (warning level 4) or plays a warden sound nearby.
 */
class SculkShrieker extends Transparent{
	public const SHRIEKING_TICKS = 90;
	public const DARKNESS_RADIUS = 40;
	public const DARKNESS_DURATION_TICKS = 260;
	/** Darkness is renewed when the current one has less than this left (Java displayEffectLimit) */
	public const DARKNESS_RENEW_TICKS = 200;
	public const WARDEN_SPAWN_ATTEMPTS = 20;
	public const WARDEN_SPAWN_RANGE_XZ = 5;
	public const WARDEN_SPAWN_RANGE_Y = 6;
	public const WARNING_SOUND_RADIUS = 10;

	protected bool $active = false;
	protected bool $canSummon = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->active);
		$w->bool($this->canSummon);
	}

	public function isActive() : bool{ return $this->active; }

	/** @return $this */
	public function setActive(bool $active) : self{
		$this->active = $active;
		return $this;
	}

	public function canSummon() : bool{ return $this->canSummon; }

	/** @return $this */
	public function setCanSummon(bool $canSummon) : self{
		$this->canSummon = $canSummon;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 0.5)];
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::DOWN ? SupportType::FULL : SupportType::NONE;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getXpDropAmount() : int{
		return 5;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		//stepping on it (the top is half a block high)
		if(!$this->active && $entity->isOnGround() && abs($entity->getBoundingBox()->minY - ($this->position->y + 0.5)) < 0.01){
			$player = VibrationManager::getResponsiblePlayer($entity);
			if($player !== null){
				$this->tryShriek($player);
			}
		}
		return true;
	}

	/**
	 * Returns whether this shrieker can warn players and summon wardens: it must be allowed to (natural shriekers),
	 * the difficulty must not be peaceful and mobs must be enabled.
	 */
	public function canRespond() : bool{
		return $this->canSummon && $this->position->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL && $this->position->getWorld()->areMobsEnabled();
	}

	/**
	 * Java SculkShriekerBlockEntity.tryShriek(): the player set the shrieker off. Returns whether it shrieked.
	 */
	public function tryShriek(Player $player) : bool{
		if($this->active){
			return false;
		}
		return $this->tryShriekWithTrackers($this->canRespond() ? WardenSpawnTracker::getTrackersAround($this->position->getWorld(), $this->position, $player) : [], $player);
	}

	/**
	 * Same as {@link SculkShrieker::tryShriek()} with the warning trackers of the players concerned (the trigger
	 * player and the players around).
	 *
	 * @param WardenSpawnTracker[] $trackers
	 * @param Player|null          $player   the player who set it off, whom wardens nearby get angry at
	 */
	public function tryShriekWithTrackers(array $trackers, ?Player $player = null) : bool{
		if($this->active){
			return false;
		}
		$world = $this->position->getWorld();
		$warningLevel = 0;
		if($this->canRespond()){
			$level = WardenSpawnTracker::tryWarnTrackers($world, $this->position, $trackers);
			if($level === null){
				return false;
			}
			$warningLevel = $level;
		}
		$this->shriek($warningLevel, $player);
		return true;
	}

	private function shriek(int $warningLevel, ?Player $player) : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$world->getVibrations()->setShriekerState($x, $y, $z, $world->getServer()->getTick() + self::SHRIEKING_TICKS, $warningLevel);
		$this->active = true;
		$world->setBlock($this->position, $this);
		$world->scheduleDelayedBlockUpdate($this->position, self::SHRIEKING_TICKS);
		$center = $this->position->add(0.5, 0.5, 0.5);
		$world->addSound($center, new SculkShriekerShriekSound());
		$world->addParticle($center, new SculkShriekParticle());
		//wardens hear the shriek and get angry at the player (Java SculkShriekerBlockEntity.shriek())
		$world->addGameEvent($center, GameEvent::SHRIEK, $player);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$vibrations = $world->getVibrations();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(!$this->active){
			$player = $vibrations->takeArrivedShriek($x, $y, $z);
			if($player !== null){
				$this->tryShriek($player);
			}
			return;
		}
		$state = $vibrations->getShriekerState($x, $y, $z);
		$now = $world->getServer()->getTick();
		if($state !== null && $state[0] > $now){
			//early update (e.g. a sensor click arriving): the shriek isn't over yet
			$world->scheduleDelayedBlockUpdate($this->position, $state[0] - $now);
			return;
		}
		$vibrations->clearShriekerState($x, $y, $z);
		$this->active = false;
		$world->setBlock($this->position, $this);
		$this->tryRespond($state[1] ?? 0);
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		//scheduled updates are not saved: a shrieker reloaded while shrieking must still stop
		if($this->active){
			$world = $this->position->getWorld();
			if(!$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
				$world->scheduleDelayedBlockUpdate($this->position, 1);
			}
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$state = $this->active ? $this->position->getWorld()->getVibrations()->getShriekerState($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()) : null;
		$result = parent::onBreak($item, $player, $returnedItems);
		if($state !== null){
			//Java: a shrieker broken while shrieking still responds
			$this->tryRespond($state[1]);
		}
		return $result;
	}

	/**
	 * Java SculkShriekerBlockEntity.tryRespond(), at the end of a shriek which raised the warning level: summons a
	 * warden at level 4 (or plays a warden sound nearby), and gives Darkness to the players around.
	 */
	private function tryRespond(int $warningLevel) : void{
		if(!$this->canRespond() || $warningLevel <= 0){
			return;
		}
		if($warningLevel < WardenSpawnTracker::MAX_WARNING_LEVEL || $this->trySummonWarden() === null){
			$this->playWardenReplySound($warningLevel);
		}
		$this->applyDarknessAround();
	}

	private function playWardenReplySound(int $warningLevel) : void{
		$sound = match($warningLevel){
			1 => LevelSoundEvent::NEARBY_CLOSE,
			2 => LevelSoundEvent::NEARBY_CLOSER,
			3 => LevelSoundEvent::NEARBY_CLOSEST,
			4 => LevelSoundEvent::LISTENING_ANGRY,
			default => null
		};
		if($sound === null){
			return;
		}
		$r = self::WARNING_SOUND_RADIUS;
		$pos = $this->position->add(mt_rand(-$r, $r), mt_rand(-$r, $r), mt_rand(-$r, $r));
		$this->position->getWorld()->addSound($pos, new MobSound($sound, Warden::getNetworkTypeId()));
	}

	/**
	 * Gives Darkness to the players in survival or adventure mode within 40 blocks.
	 */
	public function applyDarknessAround() : void{
		$center = $this->position->add(0.5, 0.5, 0.5);
		foreach($this->position->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || !$player->isSurvival() || $player->getPosition()->distanceSquared($center) >= self::DARKNESS_RADIUS ** 2){
				continue;
			}
			$current = $player->getEffects()->get(VanillaEffects::DARKNESS());
			if($current === null || $current->getDuration() < self::DARKNESS_RENEW_TICKS){
				$player->getEffects()->add(new EffectInstance(VanillaEffects::DARKNESS(), self::DARKNESS_DURATION_TICKS, 0, false));
			}
		}
	}

	/**
	 * Summons an emerging warden on a block around the shrieker (Java SpawnUtil.trySpawnMob() with the
	 * ON_TOP_OF_COLLIDER strategy: 20 attempts within 5 blocks horizontally, from 6 blocks above to 6 below).
	 */
	public function trySummonWarden() : ?Warden{
		$pos = $this->findWardenSpawnPosition();
		if($pos === null){
			return null;
		}
		$warden = new Warden(Location::fromObject($pos, $this->position->getWorld(), mt_rand(0, 359), 0));
		$warden->startEmerging();
		$warden->spawnToAll();
		return $warden;
	}

	private function findWardenSpawnPosition() : ?Vector3{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($attempt = 0; $attempt < self::WARDEN_SPAWN_ATTEMPTS; ++$attempt){
			$sx = $x + mt_rand(-self::WARDEN_SPAWN_RANGE_XZ, self::WARDEN_SPAWN_RANGE_XZ);
			$sz = $z + mt_rand(-self::WARDEN_SPAWN_RANGE_XZ, self::WARDEN_SPAWN_RANGE_XZ);
			if(!$world->isChunkLoaded($sx >> 4, $sz >> 4)){
				continue;
			}
			//moveToPossibleSpawnPosition(): from 6 blocks above, look down for a full top face with no collision above
			$above = $world->isInWorld($sx, $y + self::WARDEN_SPAWN_RANGE_Y, $sz) ? $world->getBlockAt($sx, $y + self::WARDEN_SPAWN_RANGE_Y, $sz) : null;
			for($sy = $y + self::WARDEN_SPAWN_RANGE_Y - 1; $sy >= $y - self::WARDEN_SPAWN_RANGE_Y - 1; --$sy){
				if(!$world->isInWorld($sx, $sy, $sz)){
					$above = null;
					continue;
				}
				$block = $world->getBlockAt($sx, $sy, $sz);
				if($above !== null && count($above->getCollisionBoxes()) === 0 && $block->getSupportType(Facing::UP) === SupportType::FULL){
					$pos = new Vector3($sx + 0.5, $sy + 1, $sz + 0.5);
					if(self::hasRoomForWarden($world, $pos)){
						return $pos;
					}
					break; //Java tries another random column
				}
				$above = $block;
			}
		}
		return null;
	}

	/**
	 * Returns whether a warden fits at the given position: no block collision in its whole 0.9x2.9 box and no liquid
	 * (Java Warden.checkSpawnObstruction()), so that it doesn't emerge inside a low ceiling or under water.
	 */
	public static function hasRoomForWarden(World $world, Vector3 $pos) : bool{
		$box = new AxisAlignedBB($pos->x - 0.45, $pos->y, $pos->z - 0.45, $pos->x + 0.45, $pos->y + 2.9, $pos->z + 0.45);
		if(count($world->getBlockCollisionBoxes($box)) > 0){
			return false;
		}
		for($x = (int) floor($box->minX), $maxX = (int) floor($box->maxX); $x <= $maxX; ++$x){
			for($z = (int) floor($box->minZ), $maxZ = (int) floor($box->maxZ); $z <= $maxZ; ++$z){
				for($y = (int) floor($box->minY), $maxY = (int) floor($box->maxY); $y <= $maxY; ++$y){
					if(!$world->isInWorld($x, $y, $z)){
						return false;
					}
					$block = $world->getBlockAt($x, $y, $z);
					if($block instanceof Liquid || ($block instanceof Waterlogged && $block->isWaterlogged())){
						return false;
					}
				}
			}
		}
		return true;
	}
}
