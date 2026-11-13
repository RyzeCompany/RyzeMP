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
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\template\TemplatePlacer;

/**
 * A piece made of one structure template (vanilla's TemplateStructurePiece): the template is placed with its settings,
 * then the data markers are handled and the jigsaw blocks replaced by their final state.
 */
abstract class TemplatePiece extends StructurePiece{

	public function __construct(
		StructureTemplates $templates,
		public readonly string $templateId,
		public int $x,
		public int $y,
		public int $z
	){
		parent::__construct(new BoundingBox(0, 0, 0, 0, 0, 0), 0);
		$this->box = $this->computeBox($templates);
	}

	/**
	 * The placement settings of the piece (rotation, mirror, pivot, processors); without blocks, only the transform
	 * matters (bounding box computation).
	 */
	abstract protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings;

	protected function computeBox(StructureTemplates $templates) : BoundingBox{
		$settings = $this->makeSettings(null);
		[$sx, $sy, $sz] = $templates->size($this->templateId);
		[$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = Rotations::boundingBox($this->x, $this->y, $this->z, $sx, $sy, $sz, $settings->rotation, $settings->mirror, $settings->pivotX, $settings->pivotZ);
		return new BoundingBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
	}

	/**
	 * Moves the template (and its box) to another height.
	 */
	public function setTemplateY(StructureTemplates $templates, int $y) : void{
		$this->y = $y;
		$this->box = $this->computeBox($templates);
	}

	public function move(int $dx, int $dy, int $dz) : void{
		parent::move($dx, $dy, $dz);
		$this->x += $dx;
		$this->y += $dy;
		$this->z += $dz;
	}

	/**
	 * A data marker of the template (a structure block in data mode with the given metadata) at a world position
	 * inside the chunk box.
	 */
	protected function handleDataMarker(StructurePlaceContext $c, string $metadata, int $x, int $y, int $z, BoundingBox $box) : void{
		//NOOP
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		$this->placeTemplate($c, $chunkBox, $pivot);
	}

	/**
	 * TemplateStructurePiece.postProcess()
	 * @phpstan-param array{int, int, int} $pivot
	 */
	protected function placeTemplate(StructurePlaceContext $c, BoundingBox $chunkBox, array $pivot) : bool{
		$template = $c->templates->get($this->templateId);
		$settings = $this->makeSettings($c->blocks)->setBox($chunkBox);
		$x = $this->x;
		$y = $this->y;
		$z = $this->z;
		if(!TemplatePlacer::place($c, $template, $x, $y, $z, $pivot, $settings)){
			return false;
		}
		foreach(TemplatePlacer::dataMarkers($template, $x, $y, $z, $settings) as [$mx, $my, $mz, $metadata]){
			if($chunkBox->isInside($mx, $my, $mz)){
				$this->handleDataMarker($c, $metadata, $mx, $my, $mz, $chunkBox);
			}
		}
		foreach($template->jigsaws as $jigsaw){
			[$tx, $tz] = Rotations::transform($jigsaw->x, $jigsaw->z, $settings->mirror, $settings->rotation, $settings->pivotX, $settings->pivotZ);
			$wx = $tx + $x;
			$wy = $jigsaw->y + $y;
			$wz = $tz + $z;
			if($chunkBox->isInside($wx, $wy, $wz)){
				try{
					$state = $c->blocks->parseState($jigsaw->finalState);
				}catch(\RuntimeException){
					$state = $c->blocks->air;
				}
				$c->level->setState($wx, $wy, $wz, $state);
			}
		}
		return true;
	}
}
