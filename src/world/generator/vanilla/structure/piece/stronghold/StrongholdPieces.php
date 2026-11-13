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
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use function abs;
use function array_search;
use function array_splice;

/**
 * The piece selection of vanilla's StrongholdPieces. Vanilla keeps the piece weights, the imposed piece and the total
 * weight in static fields reset by resetPieces(); here they are the state of one generation (one instance per
 * attempt, reachable from the start piece), so concurrent generations cannot interfere.
 */
final class StrongholdPieces{
	private const MAX_DEPTH = 50;

	/**
	 * @var StrongholdPieceWeight[]
	 * @phpstan-var list<StrongholdPieceWeight>
	 */
	private array $currentPieces;
	/** @phpstan-var class-string<StrongholdPiece>|null */
	public ?string $imposedPiece = null;
	private int $totalWeight = 0;

	/**
	 * StrongholdPieces.resetPieces() (STRONGHOLD_PIECE_WEIGHTS, fresh place counts)
	 */
	public function __construct(){
		$this->currentPieces = [
			new StrongholdPieceWeight(Straight::class, 40, 0),
			new StrongholdPieceWeight(PrisonHall::class, 5, 5),
			new StrongholdPieceWeight(LeftTurn::class, 20, 0),
			new StrongholdPieceWeight(RightTurn::class, 20, 0),
			new StrongholdPieceWeight(RoomCrossing::class, 10, 6),
			new StrongholdPieceWeight(StraightStairsDown::class, 5, 5),
			new StrongholdPieceWeight(StairsDown::class, 5, 5),
			new StrongholdPieceWeight(FiveCrossing::class, 5, 4),
			new StrongholdPieceWeight(ChestCorridor::class, 5, 4),
			new StrongholdPieceWeight(Library::class, 10, 2, 4),
			new StrongholdPieceWeight(PortalRoom::class, 20, 1, 5)
		];
	}

	/**
	 * StrongholdPieces.updatePieceWeight()
	 */
	private function updatePieceWeight() : bool{
		$hasAnyPieces = false;
		$this->totalWeight = 0;
		foreach($this->currentPieces as $piece){
			if($piece->maxPlaceCount > 0 && $piece->placeCount < $piece->maxPlaceCount){
				$hasAnyPieces = true;
			}
			$this->totalWeight += $piece->weight;
		}
		return $hasAnyPieces;
	}

	/**
	 * StrongholdPieces.findAndCreatePieceFactory()
	 * @phpstan-param class-string<StrongholdPiece> $pieceClass
	 */
	private function findAndCreatePieceFactory(string $pieceClass, StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $depth) : ?StrongholdPiece{
		return match($pieceClass){
			Straight::class => Straight::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			PrisonHall::class => PrisonHall::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			LeftTurn::class => LeftTurn::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			RightTurn::class => RightTurn::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			RoomCrossing::class => RoomCrossing::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			StraightStairsDown::class => StraightStairsDown::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			StairsDown::class => StairsDown::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			FiveCrossing::class => FiveCrossing::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			ChestCorridor::class => ChestCorridor::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			Library::class => Library::createPiece($builder, $random, $x, $y, $z, $direction, $depth),
			PortalRoom::class => PortalRoom::createPiece($builder, $x, $y, $z, $direction, $depth),
			default => null
		};
	}

	/**
	 * StrongholdPieces.generatePieceFromSmallDoor()
	 */
	private function generatePieceFromSmallDoor(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $depth) : ?StrongholdPiece{
		if(!$this->updatePieceWeight()){
			return null;
		}
		if($this->imposedPiece !== null){
			$piece = $this->findAndCreatePieceFactory($this->imposedPiece, $builder, $random, $x, $y, $z, $direction, $depth);
			$this->imposedPiece = null;
			if($piece !== null){
				return $piece;
			}
		}
		for($attempt = 0; $attempt < 5; ++$attempt){
			$selection = $random->nextBoundedInt($this->totalWeight);
			foreach($this->currentPieces as $weight){
				$selection -= $weight->weight;
				if($selection >= 0){
					continue;
				}
				if(!$weight->doPlace($depth) || $weight === $start->previousPiece){
					break;
				}
				$piece = $this->findAndCreatePieceFactory($weight->pieceClass, $builder, $random, $x, $y, $z, $direction, $depth);
				if($piece !== null){
					++$weight->placeCount;
					$start->previousPiece = $weight;
					if(!$weight->isValid()){
						$index = array_search($weight, $this->currentPieces, true);
						if($index !== false){
							array_splice($this->currentPieces, $index, 1);
						}
					}
					return $piece;
				}
			}
		}
		$box = FillerCorridor::findPieceBox($builder, $x, $y, $z, $direction);
		if($box !== null && $box->minY > 1){
			return new FillerCorridor($depth, $box, $direction);
		}
		return null;
	}

	/**
	 * StrongholdPieces.generateAndAddPiece()
	 */
	public function generateAndAddPiece(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $x, int $y, int $z, int $direction, int $depth) : ?StrongholdPiece{
		if($depth > self::MAX_DEPTH){
			return null;
		}
		if(abs($x - $start->box->minX) > 112 || abs($z - $start->box->minZ) > 112){
			return null;
		}
		$piece = $this->generatePieceFromSmallDoor($start, $builder, $random, $x, $y, $z, $direction, $depth + 1);
		if($piece !== null){
			$builder->addPiece($piece);
			$start->pendingChildren[] = $piece;
		}
		return $piece;
	}
}
