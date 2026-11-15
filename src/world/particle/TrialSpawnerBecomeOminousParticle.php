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

/**
 * Trial omen particles and soul flames around a trial spawner that became ominous.
 */
final class TrialSpawnerBecomeOminousParticle implements Particle{
	/** Bedrock LevelEvent ParticlesTrialSpawnerBecomeCharged (protocol 685+). */
	private const PARTICLE_TRIAL_SPAWNER_BECOME_CHARGED = 3616;

	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::create(self::PARTICLE_TRIAL_SPAWNER_BECOME_CHARGED, 0, $pos)];
	}
}
