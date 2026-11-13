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

namespace pocketmine\world\generator\vanilla\structure\piece\mineshaft;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function intdiv;

/**
 * Vanilla's MineshaftPieces.MineShaftCorridor: a 3x3 corridor of 5-block sections with wooden supports, rails,
 * cobwebs, chest minecarts and sometimes a cave spider spawner.
 */
final class MineShaftCorridor extends MineShaftPiece{
	private bool $hasRails;
	private bool $spiderCorridor;
	private int $numSections;

	public function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction, bool $mesa){
		parent::__construct($box, $genDepth, $mesa);
		$this->setOrientation($direction);
		$this->hasRails = $random->nextBoundedInt(3) === 0;
		$this->spiderCorridor = !$this->hasRails && $random->nextBoundedInt(23) === 0;
		$this->numSections = Direction::AXIS[$direction] === 2 ? intdiv($box->getZSpan(), 5) : intdiv($box->getXSpan(), 5);
	}

	/**
	 * MineShaftCorridor.findCorridorSize(): the longest free corridor of 4, 3 or 2 sections (random start), or null.
	 */
	public static function findCorridorSize(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction) : ?BoundingBox{
		for($length = $random->nextBoundedInt(3) + 2; $length > 0; --$length){
			$blockLength = $length * 5;
			$box = match($direction){
				Direction::SOUTH => new BoundingBox(0, 0, 0, 2, 2, $blockLength - 1),
				Direction::WEST => new BoundingBox(-($blockLength - 1), 0, 0, 0, 2, 2),
				Direction::EAST => new BoundingBox(0, 0, 0, $blockLength - 1, 2, 2),
				default => new BoundingBox(0, 0, -($blockLength - 1), 2, 2, 0)
			};
			$box->move($footX, $footY, $footZ);
			if($builder->findCollisionPiece($box) === null){
				return $box;
			}
		}
		return null;
	}

	public function addChildren(MineShaftRoom $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void{
		$depth = $this->genDepth;
		$endSelection = $random->nextBoundedInt(4);
		$orientation = $this->getOrientation();
		$box = $this->box;
		if($orientation !== null){
			switch($orientation){
				case Direction::SOUTH:
					if($endSelection <= 1){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX, $box->minY - 1 + $random->nextBoundedInt(3), $box->maxZ + 1, $orientation, $depth);
					}elseif($endSelection === 2){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY - 1 + $random->nextBoundedInt(3), $box->maxZ - 3, Direction::WEST, $depth);
					}else{
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY - 1 + $random->nextBoundedInt(3), $box->maxZ - 3, Direction::EAST, $depth);
					}
					break;
				case Direction::WEST:
					if($endSelection <= 1){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ, $orientation, $depth);
					}elseif($endSelection === 2){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ - 1, Direction::NORTH, $depth);
					}else{
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX, $box->minY - 1 + $random->nextBoundedInt(3), $box->maxZ + 1, Direction::SOUTH, $depth);
					}
					break;
				case Direction::EAST:
					if($endSelection <= 1){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ, $orientation, $depth);
					}elseif($endSelection === 2){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX - 3, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ - 1, Direction::NORTH, $depth);
					}else{
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX - 3, $box->minY - 1 + $random->nextBoundedInt(3), $box->maxZ + 1, Direction::SOUTH, $depth);
					}
					break;
				default: //NORTH
					if($endSelection <= 1){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ - 1, $orientation, $depth);
					}elseif($endSelection === 2){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ, Direction::WEST, $depth);
					}else{
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY - 1 + $random->nextBoundedInt(3), $box->minZ, Direction::EAST, $depth);
					}
			}
		}

		if($depth < MineshaftPieces::MAX_DEPTH){
			if($orientation === Direction::NORTH || $orientation === Direction::SOUTH){
				for($z = $box->minZ + 3; $z + 3 <= $box->maxZ; $z += 5){
					$selection = $random->nextBoundedInt(5);
					if($selection === 0){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->minX - 1, $box->minY, $z, Direction::WEST, $depth + 1);
					}elseif($selection === 1){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $box->maxX + 1, $box->minY, $z, Direction::EAST, $depth + 1);
					}
				}
			}else{
				for($x = $box->minX + 3; $x + 3 <= $box->maxX; $x += 5){
					$selection = $random->nextBoundedInt(5);
					if($selection === 0){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $x, $box->minY, $box->minZ - 1, Direction::NORTH, $depth + 1);
					}elseif($selection === 1){
						MineshaftPieces::generateAndAddPiece($start, $builder, $random, $x, $box->minY, $box->maxZ + 1, Direction::SOUTH, $depth + 1);
					}
				}
			}
		}
	}

	/**
	 * MineShaftCorridor.createChest(): a rail with a chest minecart on it, on a floor that is not air.
	 */
	private function createMinecartChest(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, int $x, int $y, int $z) : bool{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		$level = $c->level;
		$blocks = $c->blocks;
		if(!$box->isInside($wx, $wy, $wz) || !$blocks->isAir($level->getState($wx, $wy, $wz)) || $blocks->isAir($level->getState($wx, $wy - 1, $wz))){
			return false;
		}
		$rail = $random->nextBoolean() ? "minecraft:rail[shape=north_south]" : "minecraft:rail[shape=east_west]";
		$this->placeBlock($c, $this->state($c, $rail), $x, $y, $z, $box);
		$random->nextLong(); // Preserve the loot seed draw for the deferred chest minecart.
		return true;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		if($this->isInInvalidLocation($c, $chunkBox)){
			return;
		}
		$random = $c->random;
		$blocks = $c->blocks;
		$level = $c->level;
		$caveAir = $blocks->caveAir;
		$length = $this->numSections * 5 - 1;
		$placedSpider = false;
		$planks = $this->state($c, $this->planksName());
		$this->generateBox($c, $chunkBox, 0, 0, 0, 2, 1, $length, $caveAir, $caveAir, false);
		$this->generateMaybeBox($c, $chunkBox, $random, 0.800000011920929, 0, 2, 0, 2, 2, $length, $caveAir, $caveAir, false, false);
		if($this->spiderCorridor){
			$this->generateMaybeBox($c, $chunkBox, $random, 0.6000000238418579, 0, 0, 0, 2, 1, $length, $this->state($c, "minecraft:cobweb"), $caveAir, false, true);
		}

		for($section = 0; $section < $this->numSections; ++$section){
			$z = 2 + $section * 5;
			$this->placeSupport($c, $chunkBox, 0, 0, $z, 2, 2, $random);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.10000000149011612, 0, 2, $z - 1);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.10000000149011612, 2, 2, $z - 1);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.10000000149011612, 0, 2, $z + 1);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.10000000149011612, 2, 2, $z + 1);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.05000000074505806, 0, 2, $z - 2);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.05000000074505806, 2, 2, $z - 2);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.05000000074505806, 0, 2, $z + 2);
			$this->maybePlaceCobWeb($c, $chunkBox, $random, 0.05000000074505806, 2, 2, $z + 2);
			if($random->nextBoundedInt(100) === 0){
				$this->createMinecartChest($c, $chunkBox, $random, 2, 0, $z - 1);
			}
			if($random->nextBoundedInt(100) === 0){
				$this->createMinecartChest($c, $chunkBox, $random, 0, 0, $z + 1);
			}
			if($this->spiderCorridor && !$placedSpider){
				$newZ = $z - 1 + $random->nextBoundedInt(3);
				[$wx, $wy, $wz] = $this->getWorldPos(1, 0, $newZ);
				if($chunkBox->isInside($wx, $wy, $wz) && $this->isInterior($c, 1, 0, $newZ, $chunkBox)){
					$placedSpider = true;
					//level.setBlock() + SpawnerBlockEntity.setEntityId(): the empty spawn potentials consume no randomness
					$level->setState($wx, $wy, $wz, $blocks->defaultOf("minecraft:spawner"));
					$level->setBlockEntity($wx, $wy, $wz, BlockEntityData::spawner("minecraft:cave_spider"));
				}
			}
		}

		for($x = 0; $x <= 2; ++$x){
			for($z = 0; $z <= $length; ++$z){
				$this->setPlanksBlock($c, $chunkBox, $planks, $x, -1, $z);
			}
		}

		$this->placeDoubleLowerOrUpperSupport($c, $chunkBox, 0, -1, 2);
		if($this->numSections > 1){
			$this->placeDoubleLowerOrUpperSupport($c, $chunkBox, 0, -1, $length - 2);
		}

		if($this->hasRails){
			$rail = $this->state($c, "minecraft:rail[shape=north_south]");
			$survival = $c->feature->survival;
			for($z = 0; $z <= $length; ++$z){
				$floor = $this->getBlock($c, 1, -1, $z, $chunkBox);
				if(!$blocks->isAir($floor) && $survival->isSolidRender($floor)){
					$probability = $this->isInterior($c, 1, 0, $z, $chunkBox) ? 0.699999988079071 : 0.8999999761581421;
					$this->maybeGenerateBlock($c, $chunkBox, $random, $probability, 1, 0, $z, $rail);
				}
			}
		}
	}

	/**
	 * MineShaftCorridor.placeDoubleLowerOrUpperSupport(): logs down to the ground (or chains up to the ceiling) under
	 * the planks of the support at both sides.
	 */
	private function placeDoubleLowerOrUpperSupport(StructurePlaceContext $c, BoundingBox $box, int $x, int $y, int $z) : void{
		$wood = $this->state($c, $this->woodName());
		$planksBlock = $c->blocks->blockIndex($this->planksName());
		if($c->blocks->is($this->getBlock($c, $x, $y, $z, $box), $planksBlock)){
			$this->fillPillarDownOrChainUp($c, $wood, $x, $y, $z, $box);
		}
		if($c->blocks->is($this->getBlock($c, $x + 2, $y, $z, $box), $planksBlock)){
			$this->fillPillarDownOrChainUp($c, $wood, $x + 2, $y, $z, $box);
		}
	}

	/**
	 * MineShaftCorridor.fillColumnDown(): a column from the ground up to the position, if the ground is sturdy.
	 */
	protected function fillColumnDown(StructurePlaceContext $c, int $state, int $x, int $y, int $z, BoundingBox $box) : void{
		[$wx, $worldY, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$box->isInside($wx, $worldY, $wz)){
			return;
		}
		$level = $c->level;
		$wy = $worldY;
		while(self::isReplaceableByStructures($c->blocks, $level->getState($wx, $wy, $wz)) && $wy > $level->minY + 1){
			--$wy;
		}
		if(!$c->blocks->isFaceSturdy($level->getState($wx, $wy, $wz), Direction::UP)){
			return;
		}
		while($wy < $worldY){
			++$wy;
			$level->setState($wx, $wy, $wz, $state);
		}
	}

	/**
	 * MineShaftCorridor.fillPillarDownOrChainUp(): a log pillar down to sturdy ground within 20 blocks, or else a
	 * fence and a chain up to a ceiling within 50 blocks, whichever is found first.
	 */
	private function fillPillarDownOrChainUp(StructurePlaceContext $c, int $pillar, int $x, int $y, int $z, BoundingBox $box) : void{
		[$wx, $worldY, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$box->isInside($wx, $worldY, $wz)){
			return;
		}
		$level = $c->level;
		$blocks = $c->blocks;
		$maxY = $level->maxY - 1;
		$distance = 1;
		$checkBelow = true;
		$checkAbove = true;
		while($checkBelow || $checkAbove){
			if($checkBelow){
				$py = $worldY - $distance;
				$below = $level->getState($wx, $py, $wz);
				$emptyBelow = self::isReplaceableByStructures($blocks, $below) && $blocks->blockOf[$below] !== $blocks->lavaBlock;
				if(!$emptyBelow && $blocks->isFaceSturdy($below, Direction::UP)){
					self::fillColumnBetween($c, $pillar, $wx, $wz, $worldY - $distance + 1, $worldY);
					return;
				}
				$checkBelow = $distance <= 20 && $emptyBelow && $py > $level->minY + 1;
			}
			if($checkAbove){
				$py = $worldY + $distance;
				$above = $level->getState($wx, $py, $wz);
				$emptyAbove = self::isReplaceableByStructures($blocks, $above);
				if(!$emptyAbove && self::canHangChainBelow($c, $above)){
					$level->setState($wx, $worldY + 1, $wz, $this->state($c, $this->fenceName()));
					self::fillColumnBetween($c, $this->state($c, "minecraft:iron_chain"), $wx, $wz, $worldY + 2, $worldY + $distance);
					return;
				}
				$checkAbove = $distance <= 50 && $emptyAbove && $py < $maxY;
			}
			++$distance;
		}
	}

	/**
	 * MineShaftCorridor.fillColumnBetween()
	 */
	private static function fillColumnBetween(StructurePlaceContext $c, int $state, int $x, int $z, int $bottomInclusive, int $topExclusive) : void{
		for($y = $bottomInclusive; $y < $topExclusive; ++$y){
			$c->level->setState($x, $y, $z, $state);
		}
	}

	/**
	 * MineShaftCorridor.placeSupport(): two fences with a planks beam (or two planks and maybe torches), if the
	 * ceiling above is not air.
	 */
	private function placeSupport(StructurePlaceContext $c, BoundingBox $box, int $x0, int $y0, int $z, int $y1, int $x1, FeatureRandom $random) : void{
		if(!$this->isSupportingBox($c, $box, $x0, $x1, $y1, $z)){
			return;
		}
		$planks = $this->state($c, $this->planksName());
		$caveAir = $c->blocks->caveAir;
		$this->generateBox($c, $box, $x0, $y0, $z, $x0, $y1 - 1, $z, $this->state($c, $this->fenceName() . "[west=true]"), $caveAir, false);
		$this->generateBox($c, $box, $x1, $y0, $z, $x1, $y1 - 1, $z, $this->state($c, $this->fenceName() . "[east=true]"), $caveAir, false);
		if($random->nextBoundedInt(4) === 0){
			$this->generateBox($c, $box, $x0, $y1, $z, $x0, $y1, $z, $planks, $caveAir, false);
			$this->generateBox($c, $box, $x1, $y1, $z, $x1, $y1, $z, $planks, $caveAir, false);
		}else{
			$this->generateBox($c, $box, $x0, $y1, $z, $x1, $y1, $z, $planks, $caveAir, false);
			$this->maybeGenerateBlock($c, $box, $random, 0.05000000074505806, $x0 + 1, $y1, $z - 1, $this->state($c, "minecraft:wall_torch[facing=south]"));
			$this->maybeGenerateBlock($c, $box, $random, 0.05000000074505806, $x0 + 1, $y1, $z + 1, $this->state($c, "minecraft:wall_torch[facing=north]"));
		}
	}

	/**
	 * MineShaftCorridor.maybePlaceCobWeb(): a cobweb with at least two sturdy neighbours.
	 */
	private function maybePlaceCobWeb(StructurePlaceContext $c, BoundingBox $box, FeatureRandom $random, float $probability, int $x, int $y, int $z) : void{
		if($this->isInterior($c, $x, $y, $z, $box) && $random->nextFloat() < $probability && $this->hasSturdyNeighbours($c, $box, $x, $y, $z, 2)){
			$this->placeBlock($c, $this->state($c, "minecraft:cobweb"), $x, $y, $z, $box);
		}
	}

	/**
	 * MineShaftCorridor.hasSturdyNeighbours(): whether at least count neighbours (inside the chunk box) have a sturdy
	 * face towards the position.
	 */
	private function hasSturdyNeighbours(StructurePlaceContext $c, BoundingBox $box, int $x, int $y, int $z, int $count) : bool{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		$sturdy = 0;
		foreach([Direction::DOWN, Direction::UP, Direction::NORTH, Direction::SOUTH, Direction::WEST, Direction::EAST] as $direction){
			$nx = $wx + Direction::STEP_X[$direction];
			$ny = $wy + Direction::STEP_Y[$direction];
			$nz = $wz + Direction::STEP_Z[$direction];
			if($box->isInside($nx, $ny, $nz) && $c->blocks->isFaceSturdy($c->level->getState($nx, $ny, $nz), Direction::OPPOSITE[$direction])){
				++$sturdy;
				if($sturdy >= $count){
					return true;
				}
			}
		}
		return false;
	}
}
