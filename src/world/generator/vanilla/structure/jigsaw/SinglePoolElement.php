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

namespace pocketmine\world\generator\vanilla\structure\jigsaw;

use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\processor\StructureProcessor;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\JigsawInfo;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\template\TemplatePlacer;

/**
 * single_pool_element and legacy_single_pool_element: a structure template with a processor list. Legacy elements
 * do not place the template's air blocks.
 */
final class SinglePoolElement extends PoolElement{

	/**
	 * @param StructureProcessor[] $processors
	 * @phpstan-param list<StructureProcessor> $processors
	 */
	public function __construct(
		public readonly string $location,
		private array $processors,
		int $projection,
		public readonly bool $legacy,
		private ?bool $liquidOverride = null
	){
		parent::__construct($projection);
	}

	/**
	 * The jigsaws and the bounding box of the template at the origin, per rotation: the assembler asks for them for
	 * every candidate of every jigsaw of every piece (hundreds of thousands of times for a trial chambers start), and
	 * they only depend on the template and the rotation. The jigsaws are immutable, so the cached list is shared.
	 *
	 * @var JigsawInfo[][] rotation => jigsaws
	 * @phpstan-var array<int, list<JigsawInfo>>
	 */
	private array $originJigsaws = [];
	/**
	 * @var BoundingBox[] rotation => box at the origin
	 * @phpstan-var array<int, BoundingBox>
	 */
	private array $originBoxes = [];

	public function getJigsaws(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : array{
		$origin = $this->originJigsaws[$rotation] ??= $this->computeOriginJigsaws($templates, $rotation);
		if($x === 0 && $y === 0 && $z === 0){
			return $origin;
		}
		$result = [];
		foreach($origin as $jigsaw){
			$result[] = $jigsaw->moved($x, $y, $z);
		}
		return $result;
	}

	/**
	 * @return JigsawInfo[]
	 * @phpstan-return list<JigsawInfo>
	 */
	private function computeOriginJigsaws(StructureTemplates $templates, int $rotation) : array{
		$result = [];
		foreach($templates->get($this->location)->jigsaws as $jigsaw){
			[$tx, $tz] = Rotations::transform($jigsaw->x, $jigsaw->z, Rotations::MIRROR_NONE, $rotation, 0, 0);
			$result[] = $jigsaw->transformed($tx, $jigsaw->y, $tz, $rotation);
		}
		return $result;
	}

	public function getBoundingBox(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : BoundingBox{
		$origin = $this->originBoxes[$rotation] ??= (function() use ($templates, $rotation) : BoundingBox{
			[$sx, $sy, $sz] = $templates->size($this->location);
			[$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = Rotations::boundingBox(0, 0, 0, $sx, $sy, $sz, $rotation);
			return new BoundingBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
		})();
		//Rotations::boundingBox() with a position is the origin box moved by it (pivot 0,0)
		return $origin->moved($x, $y, $z);
	}

	public function getLiquidOverride() : ?bool{
		return $this->liquidOverride;
	}

	/**
	 * SinglePoolElement.getSettings() (and LegacySinglePoolElement's)
	 */
	private function settings(StructurePlaceContext $c, int $rotation, BoundingBox $box, bool $applyWaterlogging, bool $keepJigsaws) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($rotation)->setBox($box);
		$settings->knownShape = true;
		$settings->applyWaterlogging = $this->liquidOverride ?? $applyWaterlogging;
		if(!$this->legacy){
			$settings->addProcessor(Processors::ignoreStructureBlocks($c->blocks));
		}
		if(!$keepJigsaws){
			$settings->addProcessor(Processors::jigsawReplacement($c->blocks));
		}
		foreach($this->processors as $processor){
			$settings->addProcessor($processor);
		}
		if($this->projection === self::PROJECTION_TERRAIN_MATCHING){
			$settings->addProcessor(Processors::gravity(FeatureLevel::WORLD_SURFACE_WG, -1));
		}
		if($this->legacy){
			$settings->addProcessor(Processors::ignoreStructureBlocksAndAir($c->blocks));
		}
		return $settings;
	}

	public function place(StructurePlaceContext $c, int $x, int $y, int $z, array $pivot, int $rotation, BoundingBox $box, bool $applyWaterlogging, bool $keepJigsaws) : bool{
		$template = $c->templates->get($this->location);
		return TemplatePlacer::place($c, $template, $x, $y, $z, $pivot, $this->settings($c, $rotation, $box, $applyWaterlogging, $keepJigsaws));
	}

	public function describe() : string{
		return ($this->legacy ? "LegacySingle" : "Single") . "[" . $this->location . "]";
	}
}
