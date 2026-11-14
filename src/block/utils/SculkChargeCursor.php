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

namespace pocketmine\block\utils;

/**
 * A charge of sculk travelling from a sculk catalyst (Java SculkSpreader.ChargeCursor).
 * @internal
 */
final class SculkChargeCursor{
	public int $updateDelay = 0;
	public int $decayDelay = 1;
	/**
	 * Faces of the sculk vein the charge sits on (Facing values), null if never on sculk yet.
	 * @var int[]|null
	 * @phpstan-var array<int, int>|null
	 */
	public ?array $facings = null;

	public function __construct(
		public int $x,
		public int $y,
		public int $z,
		public int $charge
	){}
}
