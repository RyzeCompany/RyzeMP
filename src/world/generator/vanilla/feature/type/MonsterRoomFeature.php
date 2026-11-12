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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use function count;

/**
 * minecraft:monster_room (dungeons): with up to two chests of the simple_dungeon loot table (filled when first opened,
 * from the seed vanilla draws for them) and a spawner of a skeleton, zombie (twice as likely) or spider.
 */
final class MonsterRoomFeature implements Feature{
	private const LOOT_TABLE = "minecraft:chests/simple_dungeon";
	/** MonsterRoomFeature.MOBS */
	private const MOBS = ["minecraft:skeleton", "minecraft:zombie", "minecraft:zombie", "minecraft:spider"];

	/** @phpstan-var array<int, true> */
	private array $cannotReplace;
	private int $cobblestone;
	private int $mossyCobblestone;
	private int $chest;
	private int $chestBlock;
	private int $spawner;
	private int $spawnerBlock;

	public function __construct(FeatureRegistry $registry){
		$blocks = $registry->blocks;
		$this->cannotReplace = $blocks->tag("minecraft:features_cannot_replace");
		$this->cobblestone = $blocks->defaultOf("cobblestone");
		$this->mossyCobblestone = $blocks->defaultOf("mossy_cobblestone");
		$this->chest = $blocks->defaultOf("chest");
		$this->chestBlock = $blocks->blockIndex("chest");
		$this->spawner = $blocks->defaultOf("spawner");
		$this->spawnerBlock = $blocks->blockIndex("spawner");
	}

	private function safeSetBlock(FeatureContext $c, int $x, int $y, int $z, int $state) : bool{
		if(isset($this->cannotReplace[$c->blocks->blockOf[$c->level->getState($x, $y, $z)]])){
			return false;
		}
		$c->level->setState($x, $y, $z, $state);
		return true;
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$level = $c->level;
		$blocks = $c->blocks;
		$random = $c->random;
		$xr = $random->nextBoundedInt(2) + 2;
		$minX = -$xr - 1;
		$maxX = $xr + 1;
		$zr = $random->nextBoundedInt(2) + 2;
		$minZ = -$zr - 1;
		$maxZ = $zr + 1;
		$holes = 0;
		for($dx = $minX; $dx <= $maxX; ++$dx){
			for($dy = -1; $dy <= 4; ++$dy){
				for($dz = $minZ; $dz <= $maxZ; ++$dz){
					$solid = $blocks->isSolid($level->getState($x + $dx, $y + $dy, $z + $dz));
					if(($dy === -1 || $dy === 4) && !$solid){
						return false;
					}
					if(($dx === $minX || $dx === $maxX || $dz === $minZ || $dz === $maxZ) && $dy === 0 &&
						$level->isEmptyBlock($x + $dx, $y, $z + $dz) && $level->isEmptyBlock($x + $dx, $y + 1, $z + $dz)){
						++$holes;
					}
				}
			}
		}
		if($holes < 1 || $holes > 5){
			return false;
		}
		$air = $blocks->caveAir;
		for($dx = $minX; $dx <= $maxX; ++$dx){
			for($dy = 3; $dy >= -1; --$dy){
				for($dz = $minZ; $dz <= $maxZ; ++$dz){
					$px = $x + $dx;
					$py = $y + $dy;
					$pz = $z + $dz;
					$wall = $level->getState($px, $py, $pz);
					if($dx === $minX || $dy === -1 || $dz === $minZ || $dx === $maxX || $dz === $maxZ){
						if($py >= $level->minY && !$blocks->isSolid($level->getState($px, $py - 1, $pz))){
							$level->setState($px, $py, $pz, $air);
						}elseif($blocks->isSolid($wall) && $blocks->blockOf[$wall] !== $this->chestBlock){
							if($dy === -1 && $random->nextBoundedInt(4) !== 0){
								$this->safeSetBlock($c, $px, $py, $pz, $this->mossyCobblestone);
							}else{
								$this->safeSetBlock($c, $px, $py, $pz, $this->cobblestone);
							}
						}
					}elseif($blocks->blockOf[$wall] !== $this->chestBlock && $blocks->blockOf[$wall] !== $this->spawnerBlock){
						$this->safeSetBlock($c, $px, $py, $pz, $air);
					}
				}
			}
		}
		for($cc = 0; $cc < 2; ++$cc){
			for($i = 0; $i < 3; ++$i){
				$xc = $x + $random->nextBoundedInt($xr * 2 + 1) - $xr;
				$zc = $z + $random->nextBoundedInt($zr * 2 + 1) - $zr;
				if(!$level->isEmptyBlock($xc, $y, $zc)){
					continue;
				}
				$walls = 0;
				foreach(Direction::PLANE_HORIZONTAL as $d){
					if($blocks->isSolid($level->getState($xc + Direction::STEP_X[$d], $y, $zc + Direction::STEP_Z[$d]))){
						++$walls;
					}
				}
				if($walls !== 1){
					continue;
				}
				if($this->safeSetBlock($c, $xc, $y, $zc, $this->reorientChest($c, $xc, $y, $zc))){
					$level->setBlockEntity($xc, $y, $zc, BlockEntityData::lootContainer("Chest", self::LOOT_TABLE, $random->nextLong()));
				}
				break;
			}
		}
		if($this->safeSetBlock($c, $x, $y, $z, $this->spawner)){
			$level->setBlockEntity($x, $y, $z, BlockEntityData::spawner(self::MOBS[$random->nextBoundedInt(count(self::MOBS))]));
		}
		return true;
	}

	/**
	 * StructurePiece.reorient() for a chest.
	 */
	private function reorientChest(FeatureContext $c, int $x, int $y, int $z) : int{
		$blocks = $c->blocks;
		$level = $c->level;
		$solidNeighbor = null;
		foreach(Direction::PLANE_HORIZONTAL as $d){
			$state = $level->getState($x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d]);
			if($blocks->blockOf[$state] === $this->chestBlock){
				return $this->chest;
			}
			if($c->survival->isSolidRender($state)){
				if($solidNeighbor !== null){
					$solidNeighbor = null;
					break;
				}
				$solidNeighbor = $d;
			}
		}
		if($solidNeighbor !== null){
			return $blocks->with($this->chest, "facing", Direction::NAMES[Direction::OPPOSITE[$solidNeighbor]]);
		}
		$lockDir = Direction::fromName($blocks->getProperty($this->chest, "facing") ?? "north");
		$solidAt = fn(int $d) : bool => $c->survival->isSolidRender($level->getState($x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d]));
		if($solidAt($lockDir)){
			$lockDir = Direction::OPPOSITE[$lockDir];
		}
		if($solidAt($lockDir)){
			$lockDir = Direction::clockWise($lockDir);
		}
		if($solidAt($lockDir)){
			$lockDir = Direction::OPPOSITE[$lockDir];
		}
		return $blocks->with($this->chest, "facing", Direction::NAMES[$lockDir]);
	}
}
