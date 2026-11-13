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

namespace pocketmine\world\generator\vanilla\structure\piece;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use function count;
use function min;
use function usort;

/**
 * The desert pyramid (vanilla's DesertPyramidPiece): sandstone pyramid with two towers, the TNT trap room and its four
 * chests, and the archaeology cellar with suspicious sand (placed by DesertPyramidStructure.afterPlace()).
 *
 * Ported from vanilla's bytecode. WorldGenRegion.getRandom() (cellar stairs variant, collapsed roof) is the region
 * random of the chunk being decorated (FeatureLevel::$regionRandom).
 */
final class DesertPyramidPiece extends OrientedPiece{
	private const WIDTH = 21;
	private const DEPTH = 21;

	/** @phpstan-var array<string, array{int, int, int}> world positions recorded by placeSand() */
	private array $sandPositions = [];
	/** @phpstan-var array{int, int, int}|null */
	private ?array $collapsedRoofPos = null;

	public function __construct(LegacyRandom $random, int $x, int $z){
		$direction = self::randomHorizontalDirection($random);
		parent::__construct(self::makeBoundingBox($x, 64, $z, $direction, self::WIDTH, 15, self::DEPTH));
		$this->setOrientation($direction);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$random = $c->random;
		//updateHeightPositionToLowestGroundHeight(level, -random.nextInt(3)): the argument is always evaluated; the
		//height itself was set at start time (DesertPyramidStructure)
		$random->nextBoundedInt(3);
		$air = $c->blocks->air;
		$sandstone = $this->state($c, "minecraft:sandstone");
		$cut = $this->state($c, "minecraft:cut_sandstone");
		$chiseled = $this->state($c, "minecraft:chiseled_sandstone");
		$orange = $this->state($c, "minecraft:orange_terracotta");
		$w = self::WIDTH;
		$d = self::DEPTH;
		$this->generateBox($c, $box, 0, -4, 0, $w - 1, 0, $d - 1, $sandstone, $sandstone, false);
		for($i = 1; $i <= 9; ++$i){
			$this->generateBox($c, $box, $i, $i, $i, $w - 1 - $i, $i, $d - 1 - $i, $sandstone, $sandstone, false);
			$this->generateBox($c, $box, $i + 1, $i, $i + 1, $w - 2 - $i, $i, $d - 2 - $i, $air, $air, false);
		}
		for($x = 0; $x < $w; ++$x){
			for($z = 0; $z < $d; ++$z){
				$this->fillColumnDown($c, $sandstone, $x, -5, $z, $box);
			}
		}
		$north = $this->state($c, "minecraft:sandstone_stairs[facing=north]");
		$south = $this->state($c, "minecraft:sandstone_stairs[facing=south]");
		$east = $this->state($c, "minecraft:sandstone_stairs[facing=east]");
		$west = $this->state($c, "minecraft:sandstone_stairs[facing=west]");
		$this->generateBox($c, $box, 0, 0, 0, 4, 9, 4, $sandstone, $air, false);
		$this->generateBox($c, $box, 1, 10, 1, 3, 10, 3, $sandstone, $sandstone, false);
		$this->placeBlock($c, $north, 2, 10, 0, $box);
		$this->placeBlock($c, $south, 2, 10, 4, $box);
		$this->placeBlock($c, $east, 0, 10, 2, $box);
		$this->placeBlock($c, $west, 4, 10, 2, $box);
		$this->generateBox($c, $box, $w - 5, 0, 0, $w - 1, 9, 4, $sandstone, $air, false);
		$this->generateBox($c, $box, $w - 4, 10, 1, $w - 2, 10, 3, $sandstone, $sandstone, false);
		$this->placeBlock($c, $north, $w - 3, 10, 0, $box);
		$this->placeBlock($c, $south, $w - 3, 10, 4, $box);
		$this->placeBlock($c, $east, $w - 5, 10, 2, $box);
		$this->placeBlock($c, $west, $w - 1, 10, 2, $box);
		$this->generateBox($c, $box, 8, 0, 0, 12, 4, 4, $sandstone, $air, false);
		$this->generateBox($c, $box, 9, 1, 0, 11, 3, 4, $air, $air, false);
		foreach([[9, 1], [9, 2], [9, 3], [10, 3], [11, 3], [11, 2], [11, 1]] as [$x, $y]){
			$this->placeBlock($c, $cut, $x, $y, 1, $box);
		}
		$this->generateBox($c, $box, 4, 1, 1, 8, 3, 3, $sandstone, $air, false);
		$this->generateBox($c, $box, 4, 1, 2, 8, 2, 2, $air, $air, false);
		$this->generateBox($c, $box, 12, 1, 1, 16, 3, 3, $sandstone, $air, false);
		$this->generateBox($c, $box, 12, 1, 2, 16, 2, 2, $air, $air, false);
		$this->generateBox($c, $box, 5, 4, 5, $w - 6, 4, $d - 6, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, 9, 4, 9, 11, 4, 11, $air, $air, false);
		$this->generateBox($c, $box, 8, 1, 8, 8, 3, 8, $cut, $cut, false);
		$this->generateBox($c, $box, 12, 1, 8, 12, 3, 8, $cut, $cut, false);
		$this->generateBox($c, $box, 8, 1, 12, 8, 3, 12, $cut, $cut, false);
		$this->generateBox($c, $box, 12, 1, 12, 12, 3, 12, $cut, $cut, false);
		$this->generateBox($c, $box, 1, 1, 5, 4, 4, 11, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, $w - 5, 1, 5, $w - 2, 4, 11, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, 6, 7, 9, 6, 7, 11, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, $w - 7, 7, 9, $w - 7, 7, 11, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, 5, 5, 9, 5, 7, 11, $cut, $cut, false);
		$this->generateBox($c, $box, $w - 6, 5, 9, $w - 6, 7, 11, $cut, $cut, false);
		$this->placeBlock($c, $air, 5, 5, 10, $box);
		$this->placeBlock($c, $air, 5, 6, 10, $box);
		$this->placeBlock($c, $air, 6, 6, 10, $box);
		$this->placeBlock($c, $air, $w - 6, 5, 10, $box);
		$this->placeBlock($c, $air, $w - 6, 6, 10, $box);
		$this->placeBlock($c, $air, $w - 7, 6, 10, $box);
		$this->generateBox($c, $box, 2, 4, 4, 2, 6, 4, $air, $air, false);
		$this->generateBox($c, $box, $w - 3, 4, 4, $w - 3, 6, 4, $air, $air, false);
		$this->placeBlock($c, $north, 2, 4, 5, $box);
		$this->placeBlock($c, $north, 2, 3, 4, $box);
		$this->placeBlock($c, $north, $w - 3, 4, 5, $box);
		$this->placeBlock($c, $north, $w - 3, 3, 4, $box);
		$this->generateBox($c, $box, 1, 1, 3, 2, 2, 3, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, $w - 3, 1, 3, $w - 2, 2, 3, $sandstone, $sandstone, false);
		$this->placeBlock($c, $sandstone, 1, 1, 2, $box);
		$this->placeBlock($c, $sandstone, $w - 2, 1, 2, $box);
		$slab = $this->state($c, "minecraft:sandstone_slab");
		$this->placeBlock($c, $slab, 1, 2, 2, $box);
		$this->placeBlock($c, $slab, $w - 2, 2, 2, $box);
		$this->placeBlock($c, $west, 2, 1, 2, $box);
		$this->placeBlock($c, $east, $w - 3, 1, 2, $box);
		$this->generateBox($c, $box, 4, 3, 5, 4, 3, 17, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, $w - 5, 3, 5, $w - 5, 3, 17, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, 3, 1, 5, 4, 2, 16, $air, $air, false);
		$this->generateBox($c, $box, $w - 6, 1, 5, $w - 5, 2, 16, $air, $air, false);
		for($z = 5; $z <= 17; $z += 2){
			$this->placeBlock($c, $cut, 4, 1, $z, $box);
			$this->placeBlock($c, $chiseled, 4, 2, $z, $box);
			$this->placeBlock($c, $cut, $w - 5, 1, $z, $box);
			$this->placeBlock($c, $chiseled, $w - 5, 2, $z, $box);
		}
		foreach([[10, 7], [10, 8], [9, 9], [11, 9], [8, 10], [12, 10], [7, 10], [13, 10], [9, 11], [11, 11], [10, 12], [10, 13]] as [$x, $z]){
			$this->placeBlock($c, $orange, $x, 0, $z, $box);
		}
		$this->placeBlock($c, $this->state($c, "minecraft:blue_terracotta"), 10, 0, 10, $box);
		for($x = 0; $x <= $w - 1; $x += $w - 1){
			$column = [
				[2, 1, $cut], [2, 2, $orange], [2, 3, $cut], [3, 1, $cut], [3, 2, $orange], [3, 3, $cut],
				[4, 1, $orange], [4, 2, $chiseled], [4, 3, $orange], [5, 1, $cut], [5, 2, $orange], [5, 3, $cut],
				[6, 1, $orange], [6, 2, $chiseled], [6, 3, $orange], [7, 1, $orange], [7, 2, $orange], [7, 3, $orange],
				[8, 1, $cut], [8, 2, $cut], [8, 3, $cut]
			];
			foreach($column as [$y, $z, $state]){
				$this->placeBlock($c, $state, $x, $y, $z, $box);
			}
		}
		for($x = 2; $x <= $w - 3; $x += $w - 3 - 2){
			$facade = [
				[-1, 2, $cut], [0, 2, $orange], [1, 2, $cut], [-1, 3, $cut], [0, 3, $orange], [1, 3, $cut],
				[-1, 4, $orange], [0, 4, $chiseled], [1, 4, $orange], [-1, 5, $cut], [0, 5, $orange], [1, 5, $cut],
				[-1, 6, $orange], [0, 6, $chiseled], [1, 6, $orange], [-1, 7, $orange], [0, 7, $orange], [1, 7, $orange],
				[-1, 8, $cut], [0, 8, $cut], [1, 8, $cut]
			];
			foreach($facade as [$dx, $y, $state]){
				$this->placeBlock($c, $state, $x + $dx, $y, 0, $box);
			}
		}
		$this->generateBox($c, $box, 8, 4, 0, 12, 6, 0, $cut, $cut, false);
		$this->placeBlock($c, $air, 8, 6, 0, $box);
		$this->placeBlock($c, $air, 12, 6, 0, $box);
		$this->placeBlock($c, $orange, 9, 5, 0, $box);
		$this->placeBlock($c, $chiseled, 10, 5, 0, $box);
		$this->placeBlock($c, $orange, 11, 5, 0, $box);
		//treasure room
		$this->generateBox($c, $box, 8, -14, 8, 12, -11, 12, $cut, $cut, false);
		$this->generateBox($c, $box, 8, -10, 8, 12, -10, 12, $chiseled, $chiseled, false);
		$this->generateBox($c, $box, 8, -9, 8, 12, -9, 12, $cut, $cut, false);
		$this->generateBox($c, $box, 8, -8, 8, 12, -1, 12, $sandstone, $sandstone, false);
		$this->generateBox($c, $box, 9, -11, 9, 11, -1, 11, $air, $air, false);
		$this->placeBlock($c, $this->state($c, "minecraft:stone_pressure_plate"), 10, -11, 10, $box);
		$this->generateBox($c, $box, 9, -13, 9, 11, -13, 11, $this->state($c, "minecraft:tnt"), $air, false);
		$this->placeBlock($c, $air, 8, -11, 10, $box);
		$this->placeBlock($c, $air, 8, -10, 10, $box);
		$this->placeBlock($c, $chiseled, 7, -10, 10, $box);
		$this->placeBlock($c, $cut, 7, -11, 10, $box);
		$this->placeBlock($c, $air, 12, -11, 10, $box);
		$this->placeBlock($c, $air, 12, -10, 10, $box);
		$this->placeBlock($c, $chiseled, 13, -10, 10, $box);
		$this->placeBlock($c, $cut, 13, -11, 10, $box);
		$this->placeBlock($c, $air, 10, -11, 8, $box);
		$this->placeBlock($c, $air, 10, -10, 8, $box);
		$this->placeBlock($c, $chiseled, 10, -10, 7, $box);
		$this->placeBlock($c, $cut, 10, -11, 7, $box);
		$this->placeBlock($c, $air, 10, -11, 12, $box);
		$this->placeBlock($c, $air, 10, -10, 12, $box);
		$this->placeBlock($c, $chiseled, 10, -10, 13, $box);
		$this->placeBlock($c, $cut, 10, -11, 13, $box);
		foreach(Direction::PLANE_HORIZONTAL as $direction){
			$this->createChest($c, $box, $random, 10 + Direction::STEP_X[$direction] * 2, -11, 10 + Direction::STEP_Z[$direction] * 2, "minecraft:chests/desert_pyramid");
		}
		$this->addCellar($c, $box);
	}

	/**
	 * DesertPyramidPiece.addCellar(): stairs down from the side tunnel and the archaeology room. The sand inside is
	 * only recorded (placeSand()); DesertPyramidStructure.afterPlace() turns it into sand or suspicious sand.
	 */
	private function addCellar(StructurePlaceContext $c, BoundingBox $box) : void{
		$this->sandPositions = [];
		[$x, $y, $z] = [16, -4, 13];
		//addCellarStairs()
		$stairs = Rotations::rotateState($c->blocks, $this->state($c, "minecraft:sandstone_stairs"), Rotations::COUNTERCLOCKWISE_90);
		$this->placeBlock($c, $stairs, 13, -1, 17, $box);
		$this->placeBlock($c, $stairs, 14, -2, 17, $box);
		$this->placeBlock($c, $stairs, 15, -3, 17, $box);
		$sand = $this->state($c, "minecraft:sand");
		$sandstone = $this->state($c, "minecraft:sandstone");
		$variant = self::regionBoolean($c);
		$this->placeBlock($c, $sand, $x - 4, $y + 4, $z + 4, $box);
		$this->placeBlock($c, $sand, $x - 3, $y + 4, $z + 4, $box);
		$this->placeBlock($c, $sand, $x - 2, $y + 4, $z + 4, $box);
		$this->placeBlock($c, $sand, $x - 1, $y + 4, $z + 4, $box);
		$this->placeBlock($c, $sand, $x, $y + 4, $z + 4, $box);
		$this->placeBlock($c, $sand, $x - 2, $y + 3, $z + 4, $box);
		$this->placeBlock($c, $variant ? $sand : $sandstone, $x - 1, $y + 3, $z + 4, $box);
		$this->placeBlock($c, !$variant ? $sand : $sandstone, $x, $y + 3, $z + 4, $box);
		$this->placeBlock($c, $sand, $x - 1, $y + 2, $z + 4, $box);
		$this->placeBlock($c, $sandstone, $x, $y + 2, $z + 4, $box);
		$this->placeBlock($c, $sand, $x, $y + 1, $z + 4, $box);
		//addCellarRoom()
		$cut = $this->state($c, "minecraft:cut_sandstone");
		$chiseled = $this->state($c, "minecraft:chiseled_sandstone");
		foreach([[$y + 1, $cut], [$y + 2, $chiseled], [-1, $cut]] as [$wy, $wall]){
			$this->generateBox($c, $box, $x - 3, $wy, $z - 3, $x - 3, $wy, $z + 2, $wall, $wall, true);
			$this->generateBox($c, $box, $x + 3, $wy, $z - 3, $x + 3, $wy, $z + 2, $wall, $wall, true);
			$this->generateBox($c, $box, $x - 3, $wy, $z - 3, $x + 3, $wy, $z - 2, $wall, $wall, true);
			$this->generateBox($c, $box, $x - 3, $wy, $z + 3, $x + 3, $wy, $z + 3, $wall, $wall, true);
		}
		for($sy = $y + 1; $sy <= $y + 3; ++$sy){
			for($sx = $x - 2; $sx <= $x + 2; ++$sx){
				for($sz = $z - 2; $sz <= $z + 2; ++$sz){
					$this->placeSand($sx, $sy, $sz);
				}
			}
		}
		//placeCollapsedRoof()
		for($rx = $x - 2; $rx <= $x + 2; ++$rx){
			for($rz = $z - 2; $rz <= $z + 2; ++$rz){
				$roll = $c->level->regionRandom?->nextFloat() ?? 1.0;
				$this->placeBlock($c, $roll < 0.33000001311302185 ? $sandstone : $sand, $rx, $y + 4, $rz, $box);
			}
		}
		[$ox, $oy, $oz] = $this->getWorldPos($x - 2, $y + 4, $z - 2);
		$positional = (new LegacyRandom($c->seed))->forkPositional()->at($ox, $oy, $oz);
		$roofX = $positional->nextBoundedInt(5) + $x - 2;
		$roofZ = $positional->nextBoundedInt(5) + $z - 2;
		$this->collapsedRoofPos = $this->getWorldPos($roofX, $y + 4, $roofZ);
		$orange = $this->state($c, "minecraft:orange_terracotta");
		$this->placeBlock($c, $this->state($c, "minecraft:blue_terracotta"), $x, $y, $z, $box);
		foreach([[1, -1], [1, 1], [-1, -1], [-1, 1], [2, 0], [-2, 0], [0, 2], [0, -2]] as [$dx, $dz]){
			$this->placeBlock($c, $orange, $x + $dx, $y, $z + $dz, $box);
		}
		$this->placeBlock($c, $orange, $x + 3, $y, $z, $box);
		$this->placeSand($x + 3, $y + 1, $z);
		$this->placeSand($x + 3, $y + 2, $z);
		$this->placeBlock($c, $cut, $x + 4, $y + 1, $z, $box);
		$this->placeBlock($c, $chiseled, $x + 4, $y + 2, $z, $box);
		$this->placeBlock($c, $orange, $x - 3, $y, $z, $box);
		$this->placeSand($x - 3, $y + 1, $z);
		$this->placeSand($x - 3, $y + 2, $z);
		$this->placeBlock($c, $cut, $x - 4, $y + 1, $z, $box);
		$this->placeBlock($c, $chiseled, $x - 4, $y + 2, $z, $box);
		$this->placeBlock($c, $orange, $x, $y, $z + 3, $box);
		$this->placeSand($x, $y + 1, $z + 3);
		$this->placeSand($x, $y + 2, $z + 3);
		$this->placeBlock($c, $orange, $x, $y, $z - 3, $box);
		$this->placeSand($x, $y + 1, $z - 3);
		$this->placeSand($x, $y + 2, $z - 3);
		$this->placeBlock($c, $cut, $x, $y + 1, $z - 4, $box);
		$this->placeBlock($c, $chiseled, $x, -2, $z - 4, $box);
	}

	/**
	 * WorldGenRegion.getRandom().nextBoolean() (legacy: next(1), Xoroshiro: nextLong() & 1).
	 */
	private static function regionBoolean(StructurePlaceContext $c) : bool{
		$random = $c->level->regionRandom;
		if($random === null){
			return false;
		}
		return $random instanceof LegacyRandom ? $random->nextBoolean() : ($random->nextLong() & 1) !== 0;
	}

	private function placeSand(int $x, int $y, int $z) : void{
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		$this->sandPositions["$wx:$wy:$wz"] = [$wx, $wy, $wz];
	}

	/**
	 * World positions where the cellar may get suspicious sand (getPotentialSuspiciousSandWorldPositions(), as the
	 * SortedArraySet of DesertPyramidStructure.afterPlace(): Vec3i order y, z, x).
	 * @phpstan-return list<array{int, int, int}>
	 */
	public function getPotentialSuspiciousSand() : array{
		$positions = [...$this->sandPositions];
		usort($positions, fn(array $a, array $b) : int => [$a[1], $a[2], $a[0]] <=> [$b[1], $b[2], $b[0]]);
		return $positions;
	}

	/**
	 * DesertPyramidStructure.afterPlace(): the collapsed roof block and 5 to 7 of the cellar's sand blocks become
	 * suspicious sand, the other ones sand.
	 */
	public function placeSuspiciousSand(StructurePlaceContext $c, BoundingBox $chunkBox, StructureStart $start) : void{
		if($this->collapsedRoofPos !== null){
			self::suspiciousSandAt($c, $chunkBox, ...$this->collapsedRoofPos);
		}
		$positions = $this->getPotentialSuspiciousSand();
		[$cx, $cy, $cz] = $start->getPiecesBoundingBox()->getCenter();
		$random = (new LegacyRandom($c->seed))->forkPositional()->at($cx, $cy, $cz);
		if(!$random instanceof LegacyRandom){
			return;
		}
		$positions = StructureRandom::shuffled($positions, $random);
		$count = min(count($positions), $random->nextBoundedInt(3) + 5);
		$sand = $c->blocks->defaultOf("minecraft:sand");
		foreach($positions as [$x, $y, $z]){
			if($count > 0){
				--$count;
				self::suspiciousSandAt($c, $chunkBox, $x, $y, $z);
			}elseif($chunkBox->isInside($x, $y, $z)){
				$c->level->setState($x, $y, $z, $sand);
			}
		}
	}

	private static function suspiciousSandAt(StructurePlaceContext $c, BoundingBox $chunkBox, int $x, int $y, int $z) : void{
		if(!$chunkBox->isInside($x, $y, $z)){
			return;
		}
		$c->level->setState($x, $y, $z, $c->blocks->defaultOf("minecraft:suspicious_sand"));
		$c->level->setBlockEntity($x, $y, $z, BlockEntityData::brushable("minecraft:archaeology/desert_pyramid", self::blockPosLong($x, $y, $z))->setString("type", "minecraft:suspicious_sand"));
	}

	/**
	 * BlockPos.asLong()
	 */
	private static function blockPosLong(int $x, int $y, int $z) : int{
		return (($x & 0x3ffffff) << 38) | (($z & 0x3ffffff) << 12) | ($y & 0xfff);
	}
}
