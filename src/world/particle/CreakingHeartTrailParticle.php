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
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\LevelEventGenericPacket;

/**
 * Orange and grey particles flowing between a creaking and its creaking heart when the creaking is hit (LevelEvent
 * ParticleCreakingHeartTrail, 9816). The position passed to {@link World::addParticle()} is the creaking's; the tags
 * are the ones GeyserMC sends (the heart end is the block's corner, the client offsets it).
 */
class CreakingHeartTrailParticle implements Particle{
	/** LevelEvent::ParticleCreakingHeartTrail (Mojang protocol docs, changelog 766), missing from BedrockProtocol */
	public const LEVEL_EVENT_ID = 9816;

	public function __construct(
		private Vector3 $heartPos,
		private int $amount = 20
	){}

	public function encode(Vector3 $pos) : array{
		return [LevelEventGenericPacket::create(self::LEVEL_EVENT_ID, CompoundTag::create()
			->setInt("CreakingAmount", $this->amount)
			->setFloat("CreakingX", (float) $pos->x)
			->setFloat("CreakingY", (float) $pos->y)
			->setFloat("CreakingZ", (float) $pos->z)
			->setInt("HeartAmount", $this->amount)
			->setFloat("HeartX", (float) $this->heartPos->getFloorX())
			->setFloat("HeartY", (float) $this->heartPos->getFloorY())
			->setFloat("HeartZ", (float) $this->heartPos->getFloorZ())
		)];
	}
}
