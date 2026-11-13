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

/**
 * NetherFortressPieces.PieceWeight. Vanilla keeps them in static arrays and resets placeCount in the StartPiece
 * constructor; here every start creates its own (see StartPiece), so concurrent starts cannot interfere.
 */
final class PieceWeight{
	public int $placeCount = 0;

	/**
	 * @phpstan-param class-string<NetherBridgePiece> $pieceClass
	 */
	public function __construct(
		public readonly string $pieceClass,
		public readonly int $weight,
		public readonly int $maxPlaceCount,
		public readonly bool $allowInRow = false
	){}

	/**
	 * NetherFortressPieces.BRIDGE_PIECE_WEIGHTS
	 *
	 * @return PieceWeight[]
	 * @phpstan-return list<PieceWeight>
	 */
	public static function bridgePieces() : array{
		return [
			new self(BridgeStraight::class, 30, 0, true),
			new self(BridgeCrossing::class, 10, 4),
			new self(RoomCrossing::class, 10, 4),
			new self(StairsRoom::class, 10, 3),
			new self(MonsterThrone::class, 5, 2),
			new self(CastleEntrance::class, 5, 1)
		];
	}

	/**
	 * NetherFortressPieces.CASTLE_PIECE_WEIGHTS
	 *
	 * @return PieceWeight[]
	 * @phpstan-return list<PieceWeight>
	 */
	public static function castlePieces() : array{
		return [
			new self(CastleSmallCorridorPiece::class, 25, 0, true),
			new self(CastleSmallCorridorCrossingPiece::class, 15, 5),
			new self(CastleSmallCorridorRightTurnPiece::class, 5, 10),
			new self(CastleSmallCorridorLeftTurnPiece::class, 5, 10),
			new self(CastleCorridorStairsPiece::class, 10, 3, true),
			new self(CastleCorridorTBalconyPiece::class, 7, 2),
			new self(CastleStalkRoom::class, 5, 2)
		];
	}

	/**
	 * PieceWeight.doPlace()
	 */
	public function doPlace() : bool{
		return $this->maxPlaceCount === 0 || $this->placeCount < $this->maxPlaceCount;
	}

	/**
	 * PieceWeight.isValid()
	 */
	public function isValid() : bool{
		return $this->maxPlaceCount === 0 || $this->placeCount < $this->maxPlaceCount;
	}
}
