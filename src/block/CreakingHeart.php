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

use pocketmine\block\tile\CreakingHeart as TileCreakingHeart;
use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\CreakingHeartState;
use pocketmine\block\utils\PillarRotation;
use pocketmine\block\utils\PillarRotationTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\WoodType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creaking;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\particle\CreakingHeartTrailParticle;
use pocketmine\world\sound\BlockPlaceSound;
use pocketmine\world\sound\CreakingHeartSpawnSound;
use pocketmine\world\sound\CreakingHeartTrailSound;
use pocketmine\world\World;
use function array_shift;
use function count;
use function floor;
use function min;
use function mt_rand;
use function shuffle;

/**
 * Creaking heart (https://minecraft.wiki/w/Creaking_Heart, vanilla Bedrock 1.26 behaviour).
 * - Between two pale oak logs (or wood, stripped or not) along its axis it is dormant by day and awake at night
 *   (moon visible: time of day 12600-23400, Overworld only); otherwise uprooted. While it has a creaking it stays
 *   rooted even if a log is removed.
 * - An awake heart without a creaking summons one every ~20 ticks if a player is within 32 blocks and the difficulty
 *   isn't peaceful: 5 attempts at a random column within 16 blocks horizontally and 8 vertically, standing on a full
 *   top face that isn't leaves, in a 2 block tall space without collisions, entities or liquid. Hearts placed by
 *   players summon creakings too (vanilla); "natural" only decides whether breaking it drops experience.
 * - The heart and its creaking remember each other ({@link TileCreakingHeart}, {@link Creaking::getHeartPosition()}).
 *   The creaking is invulnerable; when a player hits it the heart shows a particle trail and, at night, grows 2-3
 *   resin clumps on the pale oak logs around it (at most once every 5 seconds).
 * - Breaking the heart makes its creaking twitch and crumble. A comparator reads 15 - floor(distance / 32 * 15) from
 *   the creaking's distance (0 without creaking).
 * - Drops 1-3 resin clumps (itself with silk touch); natural hearts drop 20-24 experience.
 */
class CreakingHeart extends Opaque implements PillarRotation, ComparatorReadable{
	use PillarRotationTrait;

	/** Moon visible (creaking.json "hourly_clock_time" filters, Dimension::isMoonVisible) */
	public const NIGHT_START = 12600;
	public const NIGHT_END = 23400;

	public const UPDATE_INTERVAL = 20;
	public const UPDATE_VARIANCE = 5;
	public const SPAWN_ATTEMPTS = 5;
	public const SPAWN_RANGE_XZ = 16;
	public const SPAWN_RANGE_Y = 8;
	public const SPAWN_PLAYER_RANGE = 32;
	public const HURT_REACTION_COOLDOWN = 100;
	public const RESIN_SPREAD_DEPTH = 2;
	public const RESIN_SPREAD_MAX_BLOCKS = 64;
	public const COMPARATOR_RANGE = 32.0;

	protected CreakingHeartState $heartState = CreakingHeartState::UPROOTED;
	protected bool $natural = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->axis($this->axis);
		$w->enum($this->heartState);
		$w->bool($this->natural);
	}

	public function getHeartState() : CreakingHeartState{ return $this->heartState; }

	/** @return $this */
	public function setHeartState(CreakingHeartState $state) : self{
		$this->heartState = $state;
		return $this;
	}

	/**
	 * Returns whether the heart was generated with a pale oak tree (only natural hearts drop experience).
	 */
	public function isNatural() : bool{ return $this->natural; }

	/** @return $this */
	public function setNatural(bool $natural) : self{
		$this->natural = $natural;
		return $this;
	}

	/**
	 * Returns whether it is night for creaking hearts in the world (moon visible, Overworld only).
	 */
	public static function isNaturalNight(World $world) : bool{
		$time = $world->getTimeOfDay();
		return $world->getDimension() === Dimension::OVERWORLD && $time > self::NIGHT_START && $time <= self::NIGHT_END;
	}

	/**
	 * Returns whether the heart is between two pale oak logs along its axis.
	 */
	public function hasRequiredLogs() : bool{
		[$negative, $positive] = match($this->axis){
			Axis::X => [Facing::WEST, Facing::EAST],
			Axis::Z => [Facing::NORTH, Facing::SOUTH],
			default => [Facing::DOWN, Facing::UP],
		};
		return self::isPaleOakLog($this->getSide($negative), $this->axis) && self::isPaleOakLog($this->getSide($positive), $this->axis);
	}

	/**
	 * Returns the state the heart should have, given the blocks around it, its creaking and the time of day.
	 */
	public function computeHeartState() : CreakingHeartState{
		if(!$this->hasRequiredLogs() && $this->getTile()?->hasCreaking() !== true){
			return CreakingHeartState::UPROOTED;
		}
		return self::isNaturalNight($this->position->getWorld()) ? CreakingHeartState::AWAKE : CreakingHeartState::DORMANT;
	}

	private static function isPaleOakLog(Block $block, ?int $axis = null) : bool{
		return $block instanceof Wood && $block->getWoodType() === WoodType::PALE_OAK && ($axis === null || $block->getAxis() === $axis);
	}

	private function getTile() : ?TileCreakingHeart{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileCreakingHeart ? $tile : null;
	}

	/**
	 * Returns the creaking this heart summoned, if it is loaded.
	 */
	public function getCreaking() : ?Creaking{
		return $this->getTile()?->getCreaking();
	}

	private function updateHeartState() : void{
		$state = $this->computeHeartState();
		$world = $this->position->getWorld();
		if($state !== $this->heartState){
			$this->heartState = $state;
			$world->setBlock($this->position, $this);
		}
		if($state !== CreakingHeartState::UPROOTED){
			$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL);
		}
	}

	public function onPostPlace() : void{
		$this->updateHeartState();
	}

	public function onNearbyBlockChange() : void{
		$this->updateHeartState();
	}

	public function ticksRandomly() : bool{
		//keeps a rooted heart ticking if its scheduled update was lost
		return $this->heartState !== CreakingHeartState::UPROOTED;
	}

	public function onRandomTick() : void{
		$this->updateHeartState();
	}

	/**
	 * The heart's tick (vanilla block entity tick, every 20-24 ticks): updates its state and comparator output and
	 * summons a creaking when awake without one.
	 */
	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $this->getTile();
		if($tile === null){
			return;
		}
		$creaking = $tile->getCreaking();
		$state = $this->computeHeartState();
		if($state !== $this->heartState){
			$this->heartState = $state;
			$world->setBlock($this->position, $this);
		}
		$this->updateOutputSignal($tile);
		if($state === CreakingHeartState::UPROOTED){
			return; //a neighbour change roots it again
		}
		if($creaking === null && !$tile->hasCreaking() && $state === CreakingHeartState::AWAKE && $this->canSummon()){
			$this->summonCreaking($tile);
		}
		$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL + mt_rand(0, self::UPDATE_VARIANCE - 1));
	}

	private function canSummon() : bool{
		$world = $this->position->getWorld();
		if($world->getDifficulty() === World::DIFFICULTY_PEACEFUL || !$world->areMobsEnabled()){
			return false;
		}
		$center = $this->position->add(0.5, 0.5, 0.5);
		foreach($world->getPlayers() as $player){
			if(!$player->isSpectator() && $player->isAlive() && $player->getPosition()->distanceSquared($center) <= self::SPAWN_PLAYER_RANGE ** 2){
				return true;
			}
		}
		return false;
	}

	/**
	 * Summons the heart's creaking if a spawn position is found (vanilla SpawnUtil.trySpawnMob with the
	 * ON_TOP_OF_COLLIDER_NO_LEAVES strategy) and returns it. Doesn't check the time, difficulty or nearby players.
	 */
	public function summonCreaking(?TileCreakingHeart $tile = null) : ?Creaking{
		$tile ??= $this->getTile();
		if($tile === null){
			return null;
		}
		$pos = $this->findSpawnPosition();
		if($pos === null){
			return null;
		}
		$world = $this->position->getWorld();
		$creaking = new Creaking(Location::fromObject($pos, $world, mt_rand(0, 359), 0));
		$tile->setCreaking($creaking);
		$creaking->spawnToAll();
		$creaking->playMobSound(LevelSoundEvent::CREAKING_HEART_SPAWN);
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new CreakingHeartSpawnSound($this));
		$this->updateOutputSignal($tile);
		return $creaking;
	}

	private function findSpawnPosition() : ?Vector3{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($attempt = 0; $attempt < self::SPAWN_ATTEMPTS; ++$attempt){
			$sx = $x + mt_rand(-self::SPAWN_RANGE_XZ, self::SPAWN_RANGE_XZ);
			$sz = $z + mt_rand(-self::SPAWN_RANGE_XZ, self::SPAWN_RANGE_XZ);
			if(!$world->isChunkLoaded($sx >> 4, $sz >> 4)){
				continue;
			}
			for($sy = min($y + self::SPAWN_RANGE_Y, $world->getMaxY() - 3); $sy >= $y - self::SPAWN_RANGE_Y && $sy > $world->getMinY(); --$sy){
				if($this->canSpawnAt($world, $sx, $sy, $sz)){
					return new Vector3($sx + 0.5, $sy, $sz + 0.5);
				}
			}
		}
		return null;
	}

	private function canSpawnAt(World $world, int $x, int $y, int $z) : bool{
		$below = $world->getBlockAt($x, $y - 1, $z);
		if($below instanceof Leaves || $below->getSupportType(Facing::UP) !== SupportType::FULL){
			return false;
		}
		//only the feet and head blocks are checked: blocks 2+ above the ground are ignored (vanilla)
		for($i = 0; $i <= 1; ++$i){
			$block = $world->getBlockAt($x, $y + $i, $z);
			if($block instanceof Liquid || count($block->getCollisionBoxes()) > 0){
				return false;
			}
		}
		$box = new AxisAlignedBB($x + 0.05, $y, $z + 0.05, $x + 0.95, $y + 2.7, $z + 0.95);
		return count($world->getCollidingEntities($box)) === 0;
	}

	/**
	 * Called when the heart's creaking is hit by a player: particle trail and sound, and resin clumps on the pale oak
	 * logs around the heart if it is awake (at most once every {@link CreakingHeart::HURT_REACTION_COOLDOWN} ticks).
	 */
	public function onCreakingHurt(Creaking $creaking) : void{
		$world = $this->position->getWorld();
		$tile = $this->getTile();
		if($tile === null || !$tile->isBoundTo($creaking) || !$tile->tryStartHurtReaction($world->getServer()->getTick(), self::HURT_REACTION_COOLDOWN)){
			return;
		}
		$world->addParticle($creaking->getPosition()->add(0, $creaking->getSize()->getHeight() / 2, 0), new CreakingHeartTrailParticle($this->position));
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new CreakingHeartTrailSound());
		if($this->heartState === CreakingHeartState::AWAKE){
			for($i = mt_rand(2, 3); $i > 0; --$i){
				$this->spreadResin();
			}
		}
	}

	/**
	 * Grows a resin clump face on a pale oak log near the heart (breadth-first through the logs touching it, at most
	 * {@link CreakingHeart::RESIN_SPREAD_DEPTH} logs away) and returns where, or null if there was no room.
	 */
	public function spreadResin() : ?Vector3{
		$world = $this->position->getWorld();
		$start = $this->position->asVector3();
		/** @var array{Vector3, int}[] $queue */
		$queue = [[$start, 0]];
		$visited = [World::blockHash($start->getFloorX(), $start->getFloorY(), $start->getFloorZ()) => true];
		$faces = Facing::ALL;
		while(count($queue) > 0){
			[$pos, $depth] = array_shift($queue);
			if($depth > 0){
				shuffle($faces);
				foreach($faces as $face){
					$side = $pos->getSide($face);
					$block = $world->getBlock($side);
					$attachFace = Facing::opposite($face);
					if($block instanceof Air){
						$resin = VanillaBlocks::RESIN_CLUMP();
					}elseif($block instanceof ResinClump && !$block->hasFace($attachFace)){
						$resin = clone $block;
					}else{
						continue;
					}
					$resin->setFace($attachFace, true);
					$world->setBlock($side, $resin);
					$world->addSound($side->add(0.5, 0.5, 0.5), new BlockPlaceSound($resin));
					return $side;
				}
			}
			if($depth >= self::RESIN_SPREAD_DEPTH){
				continue;
			}
			shuffle($faces);
			foreach($faces as $face){
				$side = $pos->getSide($face);
				$hash = World::blockHash($side->getFloorX(), $side->getFloorY(), $side->getFloorZ());
				if(!isset($visited[$hash]) && count($visited) < self::RESIN_SPREAD_MAX_BLOCKS && self::isPaleOakLog($world->getBlock($side))){
					$visited[$hash] = true;
					$queue[] = [$side, $depth + 1];
				}
			}
		}
		return null;
	}

	private function computeOutputSignal(?TileCreakingHeart $tile) : int{
		$creaking = $tile?->getCreaking();
		if($creaking === null){
			return 0;
		}
		$distance = $creaking->getPosition()->distance($this->position->add(0.5, 0.5, 0.5));
		return 15 - (int) floor(min($distance, self::COMPARATOR_RANGE) / self::COMPARATOR_RANGE * 15);
	}

	private function updateOutputSignal(TileCreakingHeart $tile) : void{
		$signal = $this->computeOutputSignal($tile);
		if($signal !== $tile->getOutputSignal()){
			$tile->setOutputSignal($signal);
			$this->position->getWorld()->getRedstone()?->updateComparatorsReading($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		}
	}

	public function getComparatorOutput() : int{
		return $this->computeOutputSignal($this->getTile());
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [VanillaBlocks::RESIN_CLUMP()->asItem()->setCount(mt_rand(1, 3))];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	protected function getXpDropAmount() : int{
		return $this->natural ? mt_rand(20, 24) : 0;
	}
}
