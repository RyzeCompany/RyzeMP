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

use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

/**
 * A turtle egg cracking (LevelEvent PARTICLE_TURTLE_EGG_GROW, 2034). Like BDS, the data is the network ID of the
 * cracked egg's block state, and the client plays the crack sound with it.
 */
class TurtleEggCrackParticle implements Particle{

	public function __construct(private Block $egg){}

	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::create(LevelEvent::PARTICLE_TURTLE_EGG_GROW, TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->egg->getStateId()), $pos)];
	}
}
