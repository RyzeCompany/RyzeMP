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

use pocketmine\world\ProtocolAwareEffect;
use pocketmine\world\ProtocolAwareEffectTrait;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\ParticleIds;

/**
 * Shown above a baby mob after a golden dandelion paused its growth (paused) or let it grow again (reset).
 */
class MobGrowthParticle implements Particle, ProtocolAwareEffect{
	use ProtocolAwareEffectTrait;


	public function __construct(
		private bool $paused
	){}

	public function isPaused() : bool{
		return $this->paused;
	}

	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::standardParticle($this->paused ? ParticleIds::PAUSE_MOB_GROWTH : ParticleIds::RESET_MOB_GROWTH, 0, $pos, $this->getTypeConverter()->getProtocolId())];
	}
}
