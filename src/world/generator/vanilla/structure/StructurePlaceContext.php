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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function count;

/**
 * What structure pieces see while they are placed in a chunk (vanilla's WorldGenRegion and the decoration random of
 * the chunk, see StructureStart.placeInChunk()).
 */
final class StructurePlaceContext{
	public readonly FeatureLevel $level;
	public readonly FeatureRandom $random;
	public readonly JavaBlocks $blocks;
	public readonly StructureTemplates $templates;
	public readonly int $seed;
	/**
	 * Positions of shape-dependent blocks placed by code-built pieces (StructurePiece.SHAPE_CHECK_BLOCKS), updated from
	 * their neighbours at the end of the chunk's decoration (vanilla's post-processing when the chunk becomes full).
	 * @phpstan-var list<array{int, int, int}>
	 */
	public array $postProcess = [];
	private ?ShapeUpdates $shapeUpdates = null;

	public function __construct(
		public readonly FeatureContext $feature,
		public readonly StructureEnvironment $env
	){
		$this->level = $feature->level;
		$this->random = $feature->random;
		$this->blocks = $feature->blocks;
		$this->templates = $env->templates;
		$this->seed = $env->seed;
	}

	public function shapeUpdates() : ShapeUpdates{
		return $this->shapeUpdates ??= new ShapeUpdates($this->blocks);
	}

	/**
	 * Applies the pending post-processing (see {@link $postProcess}).
	 */
	public function postProcess() : void{
		if(count($this->postProcess) === 0){
			return;
		}
		$shapes = $this->shapeUpdates();
		foreach($this->postProcess as [$x, $y, $z]){
			$shapes->updateFromNeighbourShapes($this->level, $x, $y, $z);
		}
		$this->postProcess = [];
	}
}
