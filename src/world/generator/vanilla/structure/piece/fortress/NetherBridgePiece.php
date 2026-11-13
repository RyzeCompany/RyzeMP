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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function abs;
use function array_search;
use function array_splice;
use function is_int;

/**
 * A piece of a nether fortress (vanilla's NetherFortressPieces.NetherBridgePiece): child generation and the weighted
 * choice of the next piece.
 */
abstract class NetherBridgePiece extends OrientedPiece{
	/** NetherFortressPieces.MAX_DEPTH */
	private const MAX_DEPTH = 30;
	/** NetherFortressPieces.LOWEST_Y_POSITION */
	private const LOWEST_Y_POSITION = 10;

	public function __construct(int $genDepth, BoundingBox $box, ?int $direction){
		parent::__construct($box, $genDepth);
		$this->setOrientation($direction);
	}

	/**
	 * The vanilla createPiece() of each piece class: the piece at the given foot, or null if its box is too low or
	 * collides with another piece. The random is only used by the pieces whose vanilla factory takes it.
	 */
	abstract public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece;

	/**
	 * StructurePiece.addChildren()
	 */
	public function addChildren(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		//NOOP
	}

	protected static function isOkBox(BoundingBox $box) : bool{
		return $box->minY > self::LOWEST_Y_POSITION;
	}

	/**
	 * The nether bricks state, used by every piece.
	 */
	protected function bricks(StructurePlaceContext $c) : int{
		return $this->state($c, "minecraft:nether_bricks");
	}

	/**
	 * updatePieceWeight(): the total weight of the list, or -1 if no piece with a placement limit can still be placed.
	 *
	 * @param PieceWeight[] $pieces
	 * @phpstan-param list<PieceWeight> $pieces
	 */
	private static function updatePieceWeight(array $pieces) : int{
		$hasAnyPieces = false;
		$totalWeight = 0;
		foreach($pieces as $piece){
			if($piece->maxPlaceCount > 0 && $piece->placeCount < $piece->maxPlaceCount){
				$hasAnyPieces = true;
			}
			$totalWeight += $piece->weight;
		}
		return $hasAnyPieces ? $totalWeight : -1;
	}

	/**
	 * generatePiece(): a weighted random piece of the list (castle or bridge), or a bridge end filler.
	 *
	 * @param PieceWeight[] $pieces
	 * @phpstan-param list<PieceWeight> $pieces
	 */
	private function generatePiece(StartPiece $start, array &$pieces, StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $depth) : ?NetherBridgePiece{
		$totalWeight = self::updatePieceWeight($pieces);
		$doStuff = $totalWeight > 0 && $depth <= self::MAX_DEPTH;
		for($attempts = 0; $attempts < 5 && $doStuff; ){
			++$attempts;
			$weightSelection = $random->nextBoundedInt($totalWeight);
			foreach($pieces as $piece){
				$weightSelection -= $piece->weight;
				if($weightSelection < 0){
					if(!$piece->doPlace() || ($piece === $start->previousPiece && !$piece->allowInRow)){
						break;
					}
					$structurePiece = $piece->pieceClass::createPiece($builder, $random, $footX, $footY, $footZ, $direction, $depth);
					if($structurePiece !== null){
						++$piece->placeCount;
						$start->previousPiece = $piece;
						if(!$piece->isValid()){
							$index = array_search($piece, $pieces, true);
							if(is_int($index)){
								array_splice($pieces, $index, 1);
							}
						}
						return $structurePiece;
					}
				}
			}
		}
		return BridgeEndFiller::createPiece($builder, $random, $footX, $footY, $footZ, $direction, $depth);
	}

	/**
	 * generateAndAddPiece()
	 */
	private function generateAndAddPiece(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $depth, bool $isCastle) : ?NetherBridgePiece{
		if(abs($footX - $start->box->minX) > 112 || abs($footZ - $start->box->minZ) > 112){
			//vanilla creates (and consumes randomness for) a bridge end filler, but does not add it
			return BridgeEndFiller::createPiece($builder, $random, $footX, $footY, $footZ, $direction, $depth);
		}
		if($isCastle){
			$newPiece = $this->generatePiece($start, $start->availableCastlePieces, $builder, $random, $footX, $footY, $footZ, $direction, $depth + 1);
		}else{
			$newPiece = $this->generatePiece($start, $start->availableBridgePieces, $builder, $random, $footX, $footY, $footZ, $direction, $depth + 1);
		}
		if($newPiece !== null){
			$builder->addPiece($newPiece);
			$start->pendingChildren[] = $newPiece;
		}
		return $newPiece;
	}

	/**
	 * generateChildForward()
	 */
	protected function generateChildForward(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $xOff, int $yOff, bool $isCastle) : ?NetherBridgePiece{
		$orientation = $this->orientation;
		$box = $this->box;
		return match($orientation){
			Direction::NORTH => $this->generateAndAddPiece($start, $builder, $random, $box->minX + $xOff, $box->minY + $yOff, $box->minZ - 1, $orientation, $this->genDepth, $isCastle),
			Direction::SOUTH => $this->generateAndAddPiece($start, $builder, $random, $box->minX + $xOff, $box->minY + $yOff, $box->maxZ + 1, $orientation, $this->genDepth, $isCastle),
			Direction::WEST => $this->generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY + $yOff, $box->minZ + $xOff, $orientation, $this->genDepth, $isCastle),
			Direction::EAST => $this->generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY + $yOff, $box->minZ + $xOff, $orientation, $this->genDepth, $isCastle),
			default => null
		};
	}

	/**
	 * generateChildLeft()
	 */
	protected function generateChildLeft(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $yOff, int $zOff, bool $isCastle) : ?NetherBridgePiece{
		$box = $this->box;
		return match($this->orientation){
			Direction::NORTH, Direction::SOUTH => $this->generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY + $yOff, $box->minZ + $zOff, Direction::WEST, $this->genDepth, $isCastle),
			Direction::WEST, Direction::EAST => $this->generateAndAddPiece($start, $builder, $random, $box->minX + $zOff, $box->minY + $yOff, $box->minZ - 1, Direction::NORTH, $this->genDepth, $isCastle),
			default => null
		};
	}

	/**
	 * generateChildRight()
	 */
	protected function generateChildRight(StartPiece $start, StructurePiecesBuilder $builder, LegacyRandom $random, int $yOff, int $zOff, bool $isCastle) : ?NetherBridgePiece{
		$box = $this->box;
		return match($this->orientation){
			Direction::NORTH, Direction::SOUTH => $this->generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY + $yOff, $box->minZ + $zOff, Direction::EAST, $this->genDepth, $isCastle),
			Direction::WEST, Direction::EAST => $this->generateAndAddPiece($start, $builder, $random, $box->minX + $zOff, $box->minY + $yOff, $box->maxZ + 1, Direction::SOUTH, $this->genDepth, $isCastle),
			default => null
		};
	}
}
