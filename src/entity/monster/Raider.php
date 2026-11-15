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

use pocketmine\entity\Living;
use pocketmine\math\Vector3;

/**
 * A mob that takes part in raids and patrols (illagers, witches and ravagers; vanilla family "raider"). The state is
 * kept by {@link RaiderTrait}.
 *
 * @phpstan-require-extends Monster
 */
interface Raider{

	/**
	 * Whether it is the captain of a patrol: it wears the ominous banner and drops it (and an ominous bottle) when killed.
	 */
	public function isPatrolLeader() : bool;

	public function setPatrolLeader(bool $leader) : void;

	/**
	 * Whether it may be a captain (illagers, not witches or ravagers).
	 */
	public function canBePatrolLeader() : bool;

	/**
	 * Whether it belongs to a patrol: it walks with the group towards the captain's patrol target.
	 */
	public function isPatrolling() : bool;

	public function setPatrolling(bool $patrolling) : void;

	public function getPatrolTarget() : ?Vector3;

	public function setPatrolTarget(?Vector3 $target) : void;

	/**
	 * ID of the raid it takes part in (unique in its world), or null.
	 */
	public function getRaidId() : ?int;

	/**
	 * Wave of its raid it was spawned with (or joined).
	 */
	public function getRaidWave() : int;

	/**
	 * Centre of the village of its raid, which it walks to when it has nothing to attack.
	 */
	public function getRaidCenter() : ?Vector3;

	public function joinRaid(int $raidId, int $wave, Vector3 $center) : void;

	public function leaveRaid() : void;

	public function isCelebrating() : bool;

	/**
	 * Raiders celebrate (hands up, jumping, celebration sounds) when they win a raid.
	 */
	public function setCelebrating(bool $celebrating) : void;

	/**
	 * Whether it attacks this mob while raiding or patrolling (villagers, wandering traders, iron golems...).
	 */
	public function isRaidVictim(Living $entity) : bool;
}
