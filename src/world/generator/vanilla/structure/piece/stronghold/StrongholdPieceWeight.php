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

/**
 * StrongholdPieces.PieceWeight (and its anonymous subclasses for the library and the portal room, which need a
 * minimum depth). One instance per generation: placeCount is generation state.
 */
final class StrongholdPieceWeight{
	public int $placeCount = 0;

	/**
	 * @phpstan-param class-string<StrongholdPiece> $pieceClass
	 * @param int $minDepth doPlace() also needs depth > $minDepth (-1: no minimum)
	 */
	public function __construct(
		public readonly string $pieceClass,
		public readonly int $weight,
		public readonly int $maxPlaceCount,
		private readonly int $minDepth = -1
	){}

	public function doPlace(int $depth) : bool{
		return ($this->maxPlaceCount === 0 || $this->placeCount < $this->maxPlaceCount) && ($this->minDepth < 0 || $depth > $this->minDepth);
	}

	public function isValid() : bool{
		return $this->maxPlaceCount === 0 || $this->placeCount < $this->maxPlaceCount;
	}
}
