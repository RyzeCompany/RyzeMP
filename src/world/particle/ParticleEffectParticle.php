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
use pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket;
use pocketmine\world\dimension\Dimension;

/**
 * A particle effect from the client's resource packs by identifier (e.g. minecraft:heart_particle), like Bedrock's
 * /particle command.
 */
class ParticleEffectParticle implements Particle{

	public function __construct(
		private string $identifier,
		private Dimension $dimension = Dimension::OVERWORLD
	){}

	public function getIdentifier() : string{ return $this->identifier; }

	public function encode(Vector3 $pos) : array{
		return [SpawnParticleEffectPacket::create($this->dimension->getNetworkId(), -1, $pos, $this->identifier, null)];
	}
}
