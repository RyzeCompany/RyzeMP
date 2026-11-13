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

namespace pocketmine\world\generator\vanilla\structure\piece\fortress;

use pocketmine\world\generator\vanilla\random\LegacyRandom;

/**
 * NetherFortressPieces.StartPiece: the first bridge crossing, which holds the generation state of the whole start
 * (piece weights, the last piece type placed and the pieces whose children are still to be generated).
 */
final class StartPiece extends BridgeCrossing{
	public ?PieceWeight $previousPiece = null;
	/**
	 * @var PieceWeight[]
	 * @phpstan-var list<PieceWeight>
	 */
	public array $availableBridgePieces;
	/**
	 * @var PieceWeight[]
	 * @phpstan-var list<PieceWeight>
	 */
	public array $availableCastlePieces;
	/**
	 * @var NetherBridgePiece[]
	 * @phpstan-var list<NetherBridgePiece>
	 */
	public array $pendingChildren = [];

	public function __construct(LegacyRandom $random, int $west, int $north){
		$direction = self::randomHorizontalDirection($random);
		parent::__construct(0, self::makeBoundingBox($west, 64, $north, $direction, 19, 10, 19), $direction);
		$this->availableBridgePieces = PieceWeight::bridgePieces();
		$this->availableCastlePieces = PieceWeight::castlePieces();
	}
}
