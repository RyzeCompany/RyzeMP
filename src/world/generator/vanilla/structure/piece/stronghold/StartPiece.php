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

namespace pocketmine\world\generator\vanilla\structure\piece\stronghold;

use pocketmine\world\generator\vanilla\random\LegacyRandom;

/**
 * StrongholdPieces.StartPiece: the first staircase, holding the state of the generation (pending children, last
 * piece type placed, portal room, piece weights).
 */
final class StartPiece extends StairsDown{
	public ?StrongholdPieceWeight $previousPiece = null;
	public ?PortalRoom $portalRoomPiece = null;
	/**
	 * @var StrongholdPiece[]
	 * @phpstan-var list<StrongholdPiece>
	 */
	public array $pendingChildren = [];

	public function __construct(public readonly StrongholdPieces $generation, LegacyRandom $random, int $x, int $z){
		$direction = self::randomHorizontalDirection($random);
		parent::__construct(0, self::makeBoundingBox($x, 64, $z, $direction, 5, 11, 5), $direction, true);
	}
}
