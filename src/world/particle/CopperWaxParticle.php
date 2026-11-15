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
 * Particles of copper being waxed (orange), unwaxed (white) or scraped (turquoise): LevelEvent COPPER_WAX_ON,
 * COPPER_WAX_OFF and COPPER_SCRAPE (2030-2032, data 0). The client plays the matching sound with them.
 */
class CopperWaxParticle implements Particle{
	public const WAX_ON = LevelEvent::COPPER_WAX_ON;
	public const WAX_OFF = LevelEvent::COPPER_WAX_OFF;
	public const SCRAPE = LevelEvent::COPPER_SCRAPE;

	/**
	 * @phpstan-param self::WAX_ON|self::WAX_OFF|self::SCRAPE $event
	 */
	public function __construct(private int $event){}

	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::create($this->event, 0, $pos)];
	}
}
