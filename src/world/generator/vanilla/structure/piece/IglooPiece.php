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
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\template\TemplatePlacer;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * vanilla's IglooPieces.IglooPiece. The height comes from the terrain at the igloo's door (computed from the noise
 * terrain when the start is made, so every chunk of the igloo agrees).
 */
final class IglooPiece extends TemplatePiece{
	public const IGLOO = "minecraft:igloo/top";
	public const LADDER = "minecraft:igloo/middle";
	public const LABORATORY = "minecraft:igloo/bottom";

	private const PIVOTS = [self::IGLOO => [3, 5, 5], self::LADDER => [1, 3, 1], self::LABORATORY => [3, 6, 7]];
	private const OFFSETS = [self::IGLOO => [0, 0, 0], self::LADDER => [2, -3, 4], self::LABORATORY => [0, -3, -2]];

	private int $pivotX;
	private int $pivotZ;

	public function __construct(StructureTemplates $templates, TerrainSampler $terrain, string $location, int $startX, int $startZ, private int $rotation, int $down){
		$offset = self::OFFSETS[$location];
		[$this->pivotX, , $this->pivotZ] = self::PIVOTS[$location];
		parent::__construct($templates, $location, $startX + $offset[0], 90 + $offset[1] - $down, $startZ + $offset[2]);
		//IglooPiece.postProcess(): the template goes down to the terrain at the door
		[$dx, $dz] = Rotations::transform(3 - $offset[0], -$offset[2], Rotations::MIRROR_NONE, $rotation, $this->pivotX, $this->pivotZ);
		$height = $terrain->getFirstFreeHeight($this->x + $dx, $this->z + $dz, TerrainSampler::WORLD_SURFACE_WG);
		$this->setTemplateY($templates, $this->y + $height - 90 - 1);
	}

	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation)->setPivot($this->pivotX, $this->pivotZ);
		$settings->applyWaterlogging = false;
		if($blocks !== null){
			$settings->addProcessor(Processors::ignoreStructureBlocks($blocks));
		}
		return $settings;
	}

	protected function handleDataMarker(StructurePlaceContext $c, string $metadata, int $x, int $y, int $z, BoundingBox $box) : void{
		if($metadata === "chest"){
			$c->level->setState($x, $y, $z, $c->blocks->air);
			TemplatePlacer::lootChest($c, $x, $y - 1, $z, "minecraft:chests/igloo_chest");
		}
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		$this->placeTemplate($c, $chunkBox, $pivot);
		if($this->templateId === self::IGLOO){
			[$dx, $dz] = Rotations::transform(3, 5, Rotations::MIRROR_NONE, $this->rotation, $this->pivotX, $this->pivotZ);
			$x = $this->x + $dx;
			$z = $this->z + $dz;
			$y = $this->y;
			if($chunkBox->isInside($x, $y, $z)){
				$below = $c->level->getState($x, $y - 1, $z);
				if(!$c->blocks->isAir($below) && $c->blocks->blockName($below) !== "minecraft:ladder"){
					$c->level->setState($x, $y, $z, $c->blocks->defaultOf("minecraft:snow_block"));
				}
			}
		}
	}
}
