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

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\StructureEnvironment;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\TemplatePlacer;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use function abs;
use function max;
use function min;

/**
 * vanilla's OceanRuinPieces.OceanRuinPiece. Its height (vanilla: from the blocks of the chunk being decorated) is
 * computed from the noise terrain when the start is made.
 */
final class OceanRuinPiece extends TemplatePiece{

	public function __construct(StructureEnvironment $env, string $location, int $x, int $z, private int $rotation, private float $integrity, private bool $warm, private bool $large){
		parent::__construct($env->templates, $location, $x, 90, $z);
		$terrain = $env->terrain;
		$floor = $terrain->getFirstFreeHeight($x, $z, TerrainSampler::OCEAN_FLOOR_WG);
		[$sizeX, , $sizeZ] = $env->templates->size($location);
		[$cx, $cz] = Rotations::transform($sizeX - 1, $sizeZ - 1, Rotations::MIRROR_NONE, $rotation, 0, 0);
		$this->setTemplateY($env->templates, self::findHeight($env, $x, $floor, $z, $cx + $x, $cz + $z));
	}

	/**
	 * OceanRuinPiece.getHeight(): lower the ruin if most of its area is over a drop.
	 */
	private static function findHeight(StructureEnvironment $env, int $x, int $y, int $z, int $x2, int $z2) : int{
		$terrain = $env->terrain;
		$result = $y;
		$lowest = 512;
		$base = $y - 1;
		$lowColumns = 0;
		for($cx = min($x, $x2), $maxX = max($x, $x2); $cx <= $maxX; ++$cx){
			for($cz = min($z, $z2), $maxZ = max($z, $z2); $cz <= $maxZ; ++$cz){
				$cy = $y - 1;
				while($cy > $env->minY + 1){
					$kind = $terrain->getKind($cx, $cy, $cz);
					if($kind !== TerrainSampler::KIND_AIR && $kind !== TerrainSampler::KIND_WATER){
						break;
					}
					--$cy;
				}
				$lowest = min($lowest, $cy);
				if($cy < $base - 2){
					++$lowColumns;
				}
			}
		}
		$span = abs($x - $x2);
		if($base - $lowest > 2 && $lowColumns > $span - 2){
			$result = $lowest + 1;
		}
		return $result;
	}

	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation);
		if($blocks !== null){
			$settings->addProcessor(Processors::blockRot($this->integrity, null, $blocks));
			$settings->addProcessor(Processors::ignoreStructureBlocksAndAir($blocks));
			[$input, $output, $loot] = $this->warm ?
				["minecraft:sand", "minecraft:suspicious_sand", "minecraft:archaeology/ocean_ruin_warm"] :
				["minecraft:gravel", "minecraft:suspicious_gravel", "minecraft:archaeology/ocean_ruin_cold"];
			$settings->addProcessor(Processors::capped(Processors::archaeology($blocks, $input, $output, $loot), 5, 5));
		}
		return $settings;
	}

	protected function handleDataMarker(StructurePlaceContext $c, string $metadata, int $x, int $y, int $z, BoundingBox $box) : void{
		$blocks = $c->blocks;
		$level = $c->level;
		if($metadata === "chest"){
			$chest = $blocks->defaultOf("minecraft:chest");
			$level->setState($x, $y, $z, $blocks->withBool($chest, "waterlogged", $blocks->isWater($level->getState($x, $y, $z))));
			TemplatePlacer::lootChest($c, $x, $y, $z, $this->large ? "minecraft:chests/underwater_ruin_big" : "minecraft:chests/underwater_ruin_small");
		}elseif($metadata === "drowned"){
			$level->setState($x, $y, $z, $y > $level->seaLevel ? $blocks->air : $blocks->water);
		}
	}
}
