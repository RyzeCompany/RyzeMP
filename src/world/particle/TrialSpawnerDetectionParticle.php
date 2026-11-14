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

namespace pocketmine\world\particle;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

/**
 * Flames rising from a trial spawner that noticed players (more of them for more players), blue for an ominous one.
 */
class TrialSpawnerDetectionParticle implements Particle{
	/** Bedrock LevelEvent ParticlesTrialSpawnerDetectionCharged (protocol 685+). */
	private const PARTICLE_TRIAL_SPAWNER_DETECTION_CHARGED = 3615;

	public function __construct(
		private int $players,
		private bool $ominous = false
	){}

	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::create($this->ominous ? self::PARTICLE_TRIAL_SPAWNER_DETECTION_CHARGED : LevelEvent::PARTICLE_TRIAL_SPAWNER_DETECTION, $this->players, $pos)];
	}
}
