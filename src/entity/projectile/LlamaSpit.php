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

namespace pocketmine\entity\projectile;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Spit of a llama: 1 damage (Bedrock entities/llama_spit.json), gone on impact. Not saved with chunks.
 */
class LlamaSpit extends Throwable implements NeverSavedWithChunkEntity{
	public static function getNetworkTypeId() : string{ return EntityIds::LLAMA_SPIT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.31, 0.31); }

	protected function getInitialGravity() : float{ return 0.06; }

	protected function getInitialDragMultiplier() : float{ return 0.01; }
}
