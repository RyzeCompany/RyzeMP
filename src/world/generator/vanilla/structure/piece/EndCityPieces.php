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

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;

/**
 * vanilla's EndCityPieces for one start: a house tower, then towers, fat towers, bridges and at most one ship,
 * generated recursively up to depth 8 by four section generators; a section whose pieces collide with pieces already
 * there (other than those of its parent's section) is dropped. The tower bridge generator remembers whether the ship
 * was created (vanilla resets it in init() for each start).
 */
final class EndCityPieces{
	private const MAX_GEN_DEPTH = 8;

	private const HOUSE_TOWER = 0;
	private const TOWER = 1;
	private const TOWER_BRIDGE = 2;
	private const FAT_TOWER = 3;

	/** @phpstan-var list<array{int, array{int, int, int}}> */
	private const TOWER_BRIDGES = [
		[Rotations::NONE, [1, -1, 0]],
		[Rotations::CLOCKWISE_90, [6, -1, 1]],
		[Rotations::COUNTERCLOCKWISE_90, [0, -1, 5]],
		[Rotations::CLOCKWISE_180, [5, -1, 6]],
	];
	/** @phpstan-var list<array{int, array{int, int, int}}> */
	private const FAT_TOWER_BRIDGES = [
		[Rotations::NONE, [4, -1, 0]],
		[Rotations::CLOCKWISE_90, [12, -1, 4]],
		[Rotations::COUNTERCLOCKWISE_90, [0, -1, 8]],
		[Rotations::CLOCKWISE_180, [8, -1, 12]],
	];

	private bool $shipCreated = false;

	public function __construct(
		private StructureTemplates $templates,
		private LegacyRandom $random
	){}

	/**
	 * EndCityPieces.startHouseTower()
	 * @phpstan-return list<EndCityPiece>
	 */
	public function startHouseTower(int $x, int $y, int $z, int $rotation) : array{
		$pieces = [];
		$last = self::add($pieces, new EndCityPiece($this->templates, "base_floor", $x, $y, $z, $rotation, true));
		$last = self::add($pieces, $this->addPiece($last, [-1, 0, -1], "second_floor_1", $rotation, false));
		$last = self::add($pieces, $this->addPiece($last, [-1, 4, -1], "third_floor_1", $rotation, false));
		$last = self::add($pieces, $this->addPiece($last, [-1, 8, -1], "third_roof", $rotation, true));
		$this->recursiveChildren(self::TOWER, 1, $last, null, $pieces);
		return $pieces;
	}

	/**
	 * @phpstan-param list<EndCityPiece> $pieces
	 * @phpstan-param-out list<EndCityPiece> $pieces
	 */
	private static function add(array &$pieces, EndCityPiece $piece) : EndCityPiece{
		$pieces[] = $piece;
		return $piece;
	}

	/**
	 * EndCityPieces.addPiece(): a child at the parent's template position, moved by the offset rotated like the parent
	 * (calculateConnectedPosition(parent settings, offset, child settings, ZERO); pivots are 0).
	 * @phpstan-param array{int, int, int} $offset
	 */
	private function addPiece(EndCityPiece $parent, array $offset, string $templateName, int $rotation, bool $overwrite) : EndCityPiece{
		$child = new EndCityPiece($this->templates, $templateName, $parent->x, $parent->y, $parent->z, $rotation, $overwrite);
		[$dx, $dz] = Rotations::transform($offset[0], $offset[2], Rotations::MIRROR_NONE, $parent->rotation, 0, 0);
		$child->move($dx, $offset[1], $dz);
		return $child;
	}

	/**
	 * EndCityPieces.recursiveChildren(): note that the collision check only sees $pieces, the list of the calling
	 * section (vanilla passes each section its own child list).
	 * @phpstan-param array{int, int, int}|null $offset
	 * @phpstan-param list<EndCityPiece> $pieces
	 * @phpstan-param-out list<EndCityPiece> $pieces
	 */
	private function recursiveChildren(int $generator, int $genDepth, EndCityPiece $parent, ?array $offset, array &$pieces) : bool{
		if($genDepth > self::MAX_GEN_DEPTH){
			return false;
		}
		$childPieces = [];
		$generated = match($generator){
			self::HOUSE_TOWER => $this->houseTower($genDepth, $parent, $offset ?? [0, 0, 0], $childPieces),
			self::TOWER => $this->tower($genDepth, $parent, $childPieces),
			self::TOWER_BRIDGE => $this->towerBridge($genDepth, $parent, $childPieces),
			default => $this->fatTower($genDepth, $parent, $childPieces)
		};
		if(!$generated){
			return false;
		}
		$childTag = $this->random->nextInt();
		foreach($childPieces as $child){
			$child->genDepth = $childTag;
			//StructurePiece.findCollisionPiece(): the first piece whose box intersects
			foreach($pieces as $piece){
				if($piece->box->intersects($child->box)){
					if($piece->genDepth !== $parent->genDepth){
						return false;
					}
					break;
				}
			}
		}
		foreach($childPieces as $child){
			$pieces[] = $child;
		}
		return true;
	}

	/**
	 * HOUSE_TOWER_GENERATOR
	 * @phpstan-param array{int, int, int} $offset
	 * @phpstan-param list<EndCityPiece> $pieces
	 * @phpstan-param-out list<EndCityPiece> $pieces
	 */
	private function houseTower(int $genDepth, EndCityPiece $parent, array $offset, array &$pieces) : bool{
		if($genDepth > self::MAX_GEN_DEPTH){
			return false;
		}
		$rotation = $parent->rotation;
		$last = self::add($pieces, $this->addPiece($parent, $offset, "base_floor", $rotation, true));
		$numFloors = $this->random->nextBoundedInt(3);
		if($numFloors === 0){
			self::add($pieces, $this->addPiece($last, [-1, 4, -1], "base_roof", $rotation, true));
		}elseif($numFloors === 1){
			$last = self::add($pieces, $this->addPiece($last, [-1, 0, -1], "second_floor_2", $rotation, false));
			$last = self::add($pieces, $this->addPiece($last, [-1, 8, -1], "second_roof", $rotation, false));
			$this->recursiveChildren(self::TOWER, $genDepth + 1, $last, null, $pieces);
		}else{
			$last = self::add($pieces, $this->addPiece($last, [-1, 0, -1], "second_floor_2", $rotation, false));
			$last = self::add($pieces, $this->addPiece($last, [-1, 4, -1], "third_floor_2", $rotation, false));
			$last = self::add($pieces, $this->addPiece($last, [-1, 8, -1], "third_roof", $rotation, true));
			$this->recursiveChildren(self::TOWER, $genDepth + 1, $last, null, $pieces);
		}
		return true;
	}

	/**
	 * TOWER_GENERATOR
	 * @phpstan-param list<EndCityPiece> $pieces
	 * @phpstan-param-out list<EndCityPiece> $pieces
	 */
	private function tower(int $genDepth, EndCityPiece $parent, array &$pieces) : bool{
		$rotation = $parent->rotation;
		$random = $this->random;
		$offsetX = 3 + $random->nextBoundedInt(2);
		$offsetZ = 3 + $random->nextBoundedInt(2);
		$last = self::add($pieces, $this->addPiece($parent, [$offsetX, -3, $offsetZ], "tower_base", $rotation, true));
		$last = self::add($pieces, $this->addPiece($last, [0, 7, 0], "tower_piece", $rotation, true));
		$bridgePiece = $random->nextBoundedInt(3) === 0 ? $last : null;
		$towerHeight = 1 + $random->nextBoundedInt(3);
		for($i = 0; $i < $towerHeight; ++$i){
			$last = self::add($pieces, $this->addPiece($last, [0, 4, 0], "tower_piece", $rotation, true));
			if($i < $towerHeight - 1 && $random->nextBoolean()){
				$bridgePiece = $last;
			}
		}
		if($bridgePiece !== null){
			foreach(self::TOWER_BRIDGES as [$bridgeRotation, $bridgeOffset]){
				if($random->nextBoolean()){
					$bridgeStart = self::add($pieces, $this->addPiece($bridgePiece, $bridgeOffset, "bridge_end", Rotations::compose($rotation, $bridgeRotation), true));
					$this->recursiveChildren(self::TOWER_BRIDGE, $genDepth + 1, $bridgeStart, null, $pieces);
				}
			}
			self::add($pieces, $this->addPiece($last, [-1, 4, -1], "tower_top", $rotation, true));
		}elseif($genDepth === 7){
			self::add($pieces, $this->addPiece($last, [-1, 4, -1], "tower_top", $rotation, true));
		}else{
			return $this->recursiveChildren(self::FAT_TOWER, $genDepth + 1, $last, null, $pieces);
		}
		return true;
	}

	/**
	 * TOWER_BRIDGE_GENERATOR
	 * @phpstan-param list<EndCityPiece> $pieces
	 * @phpstan-param-out list<EndCityPiece> $pieces
	 */
	private function towerBridge(int $genDepth, EndCityPiece $parent, array &$pieces) : bool{
		$rotation = $parent->rotation;
		$random = $this->random;
		$bridgeLength = $random->nextBoundedInt(4) + 1;
		$last = self::add($pieces, $this->addPiece($parent, [0, 0, -4], "bridge_piece", $rotation, true));
		$last->genDepth = -1;
		$nextY = 0;
		for($i = 0; $i < $bridgeLength; ++$i){
			if($random->nextBoolean()){
				$last = self::add($pieces, $this->addPiece($last, [0, $nextY, -4], "bridge_piece", $rotation, true));
				$nextY = 0;
			}else{
				if($random->nextBoolean()){
					$last = self::add($pieces, $this->addPiece($last, [0, $nextY, -4], "bridge_steep_stairs", $rotation, true));
				}else{
					$last = self::add($pieces, $this->addPiece($last, [0, $nextY, -8], "bridge_gentle_stairs", $rotation, true));
				}
				$nextY = 4;
			}
		}
		if(!$this->shipCreated && $random->nextBoundedInt(10 - $genDepth) === 0){
			$shipX = -8 + $random->nextBoundedInt(8);
			$shipZ = -70 + $random->nextBoundedInt(10);
			self::add($pieces, $this->addPiece($last, [$shipX, $nextY, $shipZ], "ship", $rotation, true));
			$this->shipCreated = true;
		}elseif(!$this->recursiveChildren(self::HOUSE_TOWER, $genDepth + 1, $last, [-3, $nextY + 1, -11], $pieces)){
			return false;
		}
		$last = self::add($pieces, $this->addPiece($last, [4, $nextY, 0], "bridge_end", Rotations::compose($rotation, Rotations::CLOCKWISE_180), true));
		$last->genDepth = -1;
		return true;
	}

	/**
	 * FAT_TOWER_GENERATOR
	 * @phpstan-param list<EndCityPiece> $pieces
	 * @phpstan-param-out list<EndCityPiece> $pieces
	 */
	private function fatTower(int $genDepth, EndCityPiece $parent, array &$pieces) : bool{
		$rotation = $parent->rotation;
		$random = $this->random;
		$last = self::add($pieces, $this->addPiece($parent, [-3, 4, -3], "fat_tower_base", $rotation, true));
		$last = self::add($pieces, $this->addPiece($last, [0, 4, 0], "fat_tower_middle", $rotation, true));
		for($i = 0; $i < 2 && $random->nextBoundedInt(3) !== 0; ++$i){
			$last = self::add($pieces, $this->addPiece($last, [0, 8, 0], "fat_tower_middle", $rotation, true));
			foreach(self::FAT_TOWER_BRIDGES as [$bridgeRotation, $bridgeOffset]){
				if($random->nextBoolean()){
					$bridgeStart = self::add($pieces, $this->addPiece($last, $bridgeOffset, "bridge_end", Rotations::compose($rotation, $bridgeRotation), true));
					$this->recursiveChildren(self::TOWER_BRIDGE, $genDepth + 1, $bridgeStart, null, $pieces);
				}
			}
		}
		self::add($pieces, $this->addPiece($last, [-2, 8, -2], "fat_tower_top", $rotation, true));
		return true;
	}
}
