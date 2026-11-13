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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\structure\processor\StructureProcessor;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function count;

/**
 * StructureTemplate.placeInWorld() and processBlockInfos(): places a template with its processors, clipped to the
 * settings' box (the chunk being populated). Every processor random is derived from block positions, so a template
 * placed chunk by chunk gives the same blocks as one placed in one pass; the loot seeds of containers come from the
 * chunk's decoration random, like vanilla.
 *
 * @phpstan-import-type BlockInfo from StructureProcessor
 */
final class TemplatePlacer{

	private function __construct(){
		//NOOP
	}

	/**
	 * The palette used for a placement at a position (StructurePlaceSettings.getRandomPalette()).
	 */
	public static function paletteIndex(TemplateData $template, PlaceSettings $settings, int $x, int $y, int $z) : int{
		$count = count($template->palettes);
		return $count <= 1 ? 0 : $settings->getRandom($x, $y, $z)->nextBoundedInt($count);
	}

	/**
	 * processBlockInfos() for the blocks of a palette (all of them, or only those in the settings' box columns when no
	 * processor needs the whole list).
	 *
	 * @phpstan-param array{int, int, int} $pivot
	 * @phpstan-return list<BlockInfo>
	 */
	public static function processBlocks(StructurePlaceContext $c, TemplateData $template, int $paletteIndex, int $x, int $y, int $z, array $pivot, PlaceSettings $settings) : array{
		$packedBlocks = $template->blocks($paletteIndex);
		$states = $template->states[$paletteIndex] ?? [];
		$processors = $settings->processors;
		$clip = $settings->box;
		foreach($processors as $processor){
			if($processor->needsAllBlocks()){
				$clip = null;
				break;
			}
		}
		$rotation = $settings->rotation;
		$mirror = $settings->mirror;
		$pivotX = $settings->pivotX;
		$pivotZ = $settings->pivotZ;
		$origin = [$x, $y, $z];
		$originals = [];
		$processed = [];
		$nbtByPos = $template->blockNbt;
		foreach($packedBlocks as $packed){
			$rx = $packed & TemplateData::COORD_MASK;
			$ry = ($packed >> 10) & TemplateData::COORD_MASK;
			$rz = ($packed >> 20) & TemplateData::COORD_MASK;
			if($mirror === Rotations::MIRROR_NONE && $rotation === Rotations::NONE){
				$wx = $rx + $x;
				$wz = $rz + $z;
			}else{
				[$tx, $tz] = Rotations::transform($rx, $rz, $mirror, $rotation, $pivotX, $pivotZ);
				$wx = $tx + $x;
				$wz = $tz + $z;
			}
			if($clip !== null && ($wx < $clip->minX || $wx > $clip->maxX || $wz < $clip->minZ || $wz > $clip->maxZ)){
				continue;
			}
			$state = $states[$packed >> 30];
			$nbt = $nbtByPos[$packed & 0x3fffffff] ?? null;
			$original = [$rx, $ry, $rz, $state, $nbt];
			$current = [$wx, $ry + $y, $wz, $state, $nbt !== null ? clone $nbt : null];
			foreach($processors as $processor){
				$current = $processor->process($c, $origin, $pivot, $original, $current, $settings);
				if($current === null){
					continue 2;
				}
			}
			$originals[] = $original;
			$processed[] = $current;
		}
		foreach($processors as $processor){
			$processed = $processor->finalize($c, $origin, $pivot, $originals, $processed, $settings);
		}
		return $processed;
	}

	/**
	 * StructureTemplate.placeInWorld(level, pos, pivot, settings, random, flags)
	 * @phpstan-param array{int, int, int} $pivot
	 */
	public static function place(StructurePlaceContext $c, TemplateData $template, int $x, int $y, int $z, array $pivot, PlaceSettings $settings) : bool{
		if(count($template->palettes) === 0){
			return false;
		}
		$paletteIndex = self::paletteIndex($template, $settings, $x, $y, $z);
		if(($template->palettes[$paletteIndex] ?? "") === "" || $template->sizeX < 1 || $template->sizeY < 1 || $template->sizeZ < 1){
			return false;
		}
		$level = $c->level;
		$blocks = $c->blocks;
		$box = $settings->box;
		$rotation = $settings->rotation;
		$mirror = $settings->mirror;
		/** @phpstan-var list<array{int, int, int}> $toFill */
		$toFill = [];
		$locked = [];
		/** @phpstan-var list<array{int, int, int}> $placed */
		$placed = [];
		foreach(self::processBlocks($c, $template, $paletteIndex, $x, $y, $z, $pivot, $settings) as [$wx, $wy, $wz, $state, $nbt]){
			if($box !== null && !$box->isInside($wx, $wy, $wz)){
				continue;
			}
			$previous = $settings->applyWaterlogging ? $blocks->fluid($level->getState($wx, $wy, $wz)) : JavaBlocks::FLUID_NONE;
			$state = Rotations::transformState($blocks, $state, $mirror, $rotation);
			if(!$level->setState($wx, $wy, $wz, $state)){
				continue;
			}
			$placed[] = [$wx, $wy, $wz];
			if($nbt !== null && BlockEntityData::isRandomizableContainer($blocks->blockName($state))){
				$nbt->setLong("LootTableSeed", $c->random->nextLong());
			}
			$tile = StructureBlockEntities::create($blocks, $state, $nbt);
			if($tile !== null){
				$level->setBlockEntity($wx, $wy, $wz, $tile);
			}
			if($previous === JavaBlocks::FLUID_NONE){
				continue;
			}
			if($blocks->fluidIsSource($state)){
				$locked["$wx:$wy:$wz"] = true;
			}elseif($blocks->hasProperty($state, "waterlogged")){
				//SimpleWaterloggedBlock.placeLiquid(): only water sources waterlog
				if(($previous & 15) === JavaBlocks::FLUID_WATER && ($previous & 16) !== 0){
					$level->setState($wx, $wy, $wz, $blocks->withBool($state, "waterlogged", true));
				}else{
					$toFill[] = [$wx, $wy, $wz];
				}
			}
		}
		$directions = [Direction::UP, Direction::NORTH, Direction::EAST, Direction::SOUTH, Direction::WEST];
		$filled = true;
		while($filled && count($toFill) > 0){
			$filled = false;
			foreach($toFill as $i => [$fx, $fy, $fz]){
				foreach($directions as $d){
					$nx = $fx + Direction::STEP_X[$d];
					$ny = $fy + Direction::STEP_Y[$d];
					$nz = $fz + Direction::STEP_Z[$d];
					$neighbour = $level->getState($nx, $ny, $nz);
					if($blocks->isWater($neighbour) && $blocks->fluidIsSource($neighbour) && !isset($locked["$nx:$ny:$nz"])){
						$state = $level->getState($fx, $fy, $fz);
						if($blocks->hasProperty($state, "waterlogged")){
							$level->setState($fx, $fy, $fz, $blocks->withBool($state, "waterlogged", true));
							$filled = true;
						}
						unset($toFill[$i]);
						break;
					}
				}
			}
		}
		if(!$settings->knownShape && count($placed) > 0){
			$c->shapeUpdates()->afterTemplatePlacement($level, $placed);
		}
		return true;
	}

	/**
	 * The data markers of a template (structure blocks in data mode) in the world: [x, y, z, metadata].
	 * @phpstan-return list<array{int, int, int, string}>
	 */
	public static function dataMarkers(TemplateData $template, int $x, int $y, int $z, PlaceSettings $settings) : array{
		$result = [];
		foreach($template->dataMarkers as [$mx, $my, $mz, $metadata]){
			[$tx, $tz] = Rotations::transform($mx, $mz, $settings->mirror, $settings->rotation, $settings->pivotX, $settings->pivotZ);
			$result[] = [$tx + $x, $my + $y, $tz + $z, $metadata];
		}
		return $result;
	}

	/**
	 * Placement of a chest with a loot table at a position, as data markers do (RandomizableContainer.setBlockEntityLootTable()).
	 */
	public static function lootChest(StructurePlaceContext $c, int $x, int $y, int $z, string $lootTable) : void{
		$state = $c->level->getState($x, $y, $z);
		$name = $c->blocks->blockName($state);
		if(!BlockEntityData::isRandomizableContainer($name)){
			return;
		}
		$tile = BlockEntityData::fromJava($name, CompoundTag::create()->setString("LootTable", $lootTable)->setLong("LootTableSeed", $c->random->nextLong()));
		if($tile !== null){
			$c->level->setBlockEntity($x, $y, $z, $tile);
		}
	}
}
