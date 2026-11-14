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

namespace pocketmine\entity\boss;

use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\MobHead;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\World;

/**
 * The structure that summons a wither: a T of four soul sand or soul soil blocks with three wither skeleton skulls on
 * top of its arms, both bottom corners empty. Like Bedrock it may be built upright, upside down or lying on its side
 * (any "up" direction), facing any way. It is checked when a wither skeleton skull is placed (any of the three).
 *
 * In pattern coordinates (r along the arms, u towards the skulls), with the stem at the origin:
 * <pre>
 *   u=2  S S S      S = wither skeleton skull
 *   u=1  # # #      # = soul sand / soul soil
 *   u=0  . # .      . = air
 *       -1 0 1  r
 * </pre>
 */
final class WitherSummonPattern{

	/**
	 * @param Vector3[] $skulls
	 * @param Vector3[] $body
	 * @phpstan-param list<Vector3> $skulls
	 * @phpstan-param list<Vector3> $body
	 */
	private function __construct(
		private array $skulls,
		private array $body,
		private Vector3 $spawnPosition
	){}

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getSkulls() : array{ return $this->skulls; }

	/**
	 * @return Vector3[]
	 * @phpstan-return list<Vector3>
	 */
	public function getBody() : array{ return $this->body; }

	/**
	 * Where the wither appears: on the stem for an upright T, otherwise in the middle of the arms.
	 */
	public function getSpawnPosition() : Vector3{ return $this->spawnPosition; }

	public static function isSkull(Block $block) : bool{
		return $block instanceof MobHead && $block->getMobHeadType() === MobHeadType::WITHER_SKELETON;
	}

	public static function isBodyBlock(Block $block) : bool{
		$id = $block->getTypeId();
		return $id === BlockTypeIds::SOUL_SAND || $id === BlockTypeIds::SOUL_SOIL;
	}

	/**
	 * Looks for a complete pattern that includes the skull at the given position.
	 */
	public static function find(World $world, Vector3 $skullPos) : ?self{
		$skullPos = $skullPos->floor();
		if(!self::isSkull($world->getBlock($skullPos))){
			return null;
		}
		return self::findAround($world, $skullPos, null);
	}

	/**
	 * Looks for a complete pattern that a wither skeleton skull placed at the given (empty) position would complete,
	 * without placing it (vanilla WitherSkullBlock.canSpawnMob(), used by dispensers).
	 *
	 * @internal
	 */
	public static function findIfPlaced(World $world, Vector3 $skullPos) : ?self{
		return self::findAround($world, $skullPos->floor(), $skullPos->floor());
	}

	/**
	 * @param Vector3|null $assumedSkull position counted as a skull whatever is there
	 */
	private static function findAround(World $world, Vector3 $skullPos, ?Vector3 $assumedSkull) : ?self{
		foreach(Facing::ALL as $up){
			foreach(Facing::ALL as $right){
				if(Facing::axis($right) === Facing::axis($up) || !Facing::isPositive($right)){
					continue; //the T is symmetric: one direction per axis is enough
				}
				for($k = -1; $k <= 1; ++$k){
					//the placed skull is the one at r=k: find the stem from it
					$stem = $skullPos->getSide($up, -2)->getSide($right, -$k);
					$pattern = self::match($world, $stem, $up, $right, $assumedSkull);
					if($pattern !== null){
						return $pattern;
					}
				}
			}
		}
		return null;
	}

	private static function at(Vector3 $stem, int $up, int $right, int $u, int $r) : Vector3{
		return $stem->getSide($up, $u)->getSide($right, $r);
	}

	private static function match(World $world, Vector3 $stem, int $up, int $right, ?Vector3 $assumedSkull) : ?self{
		$skulls = [];
		$body = [];
		for($r = -1; $r <= 1; ++$r){
			$pos = self::at($stem, $up, $right, 2, $r);
			if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) || (($assumedSkull === null || !$pos->equals($assumedSkull)) && !self::isSkull($world->getBlock($pos)))){
				return null;
			}
			$skulls[] = $pos;
		}
		for($r = -1; $r <= 1; ++$r){
			$pos = self::at($stem, $up, $right, 1, $r);
			if(!self::isBodyBlock($world->getBlock($pos))){
				return null;
			}
			$body[] = $pos;
		}
		if(!self::isBodyBlock($world->getBlock($stem))){
			return null;
		}
		$body[] = $stem;
		foreach([-1, 1] as $r){
			if(!$world->getBlock(self::at($stem, $up, $right, 0, $r)) instanceof Air){
				return null;
			}
		}
		$spawnBlock = match($up){
			Facing::UP => $stem,
			//upside down: the wither (3 blocks tall) takes the place of the whole T, from the skulls up
			Facing::DOWN => self::at($stem, $up, $right, 2, 0),
			default => self::at($stem, $up, $right, 1, 0)
		};
		return new self($skulls, $body, $spawnBlock->add(0.5, 0, 0.5));
	}

	/**
	 * Removes the structure (with break particles) and summons a wither in its place. Returns the wither, or null if
	 * there is no complete pattern including the given skull or the difficulty is peaceful.
	 */
	public static function trySummon(World $world, Vector3 $skullPos) : ?Wither{
		if($world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return null;
		}
		$pattern = self::find($world, $skullPos);
		if($pattern === null){
			return null;
		}
		$air = VanillaBlocks::AIR();
		foreach([...$pattern->skulls, ...$pattern->body] as $pos){
			$world->addParticle($pos->add(0.5, 0.5, 0.5), new BlockBreakParticle($world->getBlock($pos)));
			$world->setBlock($pos, $air);
		}
		$wither = new Wither(Location::fromObject($pattern->spawnPosition, $world, 0.0, 0.0));
		$wither->spawnToAll();
		return $wither;
	}
}
