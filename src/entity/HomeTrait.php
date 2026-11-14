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

namespace pocketmine\entity;

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;

/**
 * Home of a mob (Bedrock minecraft:home): a block position and an optional restriction radius around it.
 *
 * - restriction_type "random_movement": only the random targets the mob picks for itself (wandering, panicking; see
 *   {@link \pocketmine\entity\ai\navigation\RandomPos}, {@link \pocketmine\entity\ai\hostile\goal\FreeWanderGoal})
 *   stay within the radius. Tempting, following its owner or chasing a target can take it farther.
 * - restriction_type "all_movement": the mob doesn't go beyond the radius at all, not even to chase a target (see
 *   {@link \pocketmine\entity\ai\goal\MeleeAttackGoal}).
 *
 * The position is set by the mob (Bedrock event action set_home_position: at spawn, or when tamed, saddled,
 * dismounted...; see {@link HomeTrait::setHomeHere()}) and saved; the radius comes from the mob's current state and is
 * not saved: a mob whose radius changes with its state (harnessed, saddled, baby) overrides
 * {@link HomeTrait::getHomeRadius()}. Goals using the home: {@link \pocketmine\entity\ai\goal\GoHomeGoal},
 * {@link \pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal}.
 *
 * All checks are a distance comparison against the stored position: nothing runs per tick.
 *
 * @internal used by {@link Mob}
 */
trait HomeTrait{
	private ?Vector3 $homePos = null;
	private int $homeRadius = 0;
	private bool $homeAllMovement = false;

	/**
	 * Returns the home block position, or null if the mob has none.
	 */
	public function getHomePos() : ?Vector3{
		return $this->homePos;
	}

	/**
	 * Sets the home block position (floored), or removes it (null).
	 */
	public function setHomePos(?Vector3 $pos) : void{
		$this->homePos = $pos?->floor();
	}

	/**
	 * Bedrock set_home_position: the home becomes the block the mob is in.
	 */
	public function setHomeHere() : void{
		$this->homePos = $this->location->floor();
	}

	/**
	 * Returns the restriction radius around the home in blocks, 0 when the mob isn't restricted.
	 */
	public function getHomeRadius() : int{
		return $this->homeRadius;
	}

	/**
	 * Returns whether the restriction applies to all movement (Bedrock restriction_type all_movement), not only to the
	 * random targets the mob picks.
	 */
	public function isHomeAllMovement() : bool{
		return $this->homeAllMovement;
	}

	/**
	 * Sets the restriction radius (0 for none) and type. A mob without a home position gets one where it is now
	 * (Bedrock: the home component remembers where the mob was added).
	 */
	public function setHomeRestriction(int $radius, bool $allMovement = false) : void{
		$this->homeRadius = $radius;
		$this->homeAllMovement = $allMovement;
		if($radius > 0 && $this->homePos === null){
			$this->setHomeHere();
		}
	}

	/**
	 * Returns whether the mob has a home and a restriction radius around it.
	 */
	public function hasHomeRestriction() : bool{
		return $this->homePos !== null && $this->getHomeRadius() > 0;
	}

	/**
	 * Returns whether the point is within the restriction radius of the home (always true for a mob without one).
	 */
	public function isWithinHome(float $x, float $y, float $z) : bool{
		$home = $this->homePos;
		if($home === null){
			return true;
		}
		$radius = $this->getHomeRadius();
		if($radius <= 0){
			return true;
		}
		$dx = $x - $home->x - 0.5;
		$dy = $y - $home->y;
		$dz = $z - $home->z - 0.5;
		return $dx * $dx + $dy * $dy + $dz * $dz < $radius * $radius;
	}

	/**
	 * Returns the squared distance from the point to the middle of the home block, or null without a home.
	 */
	public function getHomeDistanceSquared(float $x, float $y, float $z) : ?float{
		$home = $this->homePos;
		if($home === null){
			return null;
		}
		$dx = $x - $home->x - 0.5;
		$dy = $y - $home->y;
		$dz = $z - $home->z - 0.5;
		return $dx * $dx + $dy * $dy + $dz * $dz;
	}

	protected function loadHome(CompoundTag $nbt) : void{
		$x = $nbt->getTag(Mob::TAG_HOME_X);
		$y = $nbt->getTag(Mob::TAG_HOME_Y);
		$z = $nbt->getTag(Mob::TAG_HOME_Z);
		if($x instanceof IntTag && $y instanceof IntTag && $z instanceof IntTag){
			$this->homePos = new Vector3($x->getValue(), $y->getValue(), $z->getValue());
		}
	}

	protected function saveHome(CompoundTag $nbt) : void{
		if($this->homePos !== null){
			$nbt->setInt(Mob::TAG_HOME_X, $this->homePos->getFloorX());
			$nbt->setInt(Mob::TAG_HOME_Y, $this->homePos->getFloorY());
			$nbt->setInt(Mob::TAG_HOME_Z, $this->homePos->getFloorZ());
		}
	}
}
