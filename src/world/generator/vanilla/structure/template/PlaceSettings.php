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

use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\StructureProcessor;

/**
 * Vanilla's StructurePlaceSettings: transform, clipping box, processors and options of a template placement.
 */
final class PlaceSettings{
	public int $rotation = Rotations::NONE;
	public int $mirror = Rotations::MIRROR_NONE;
	public int $pivotX = 0;
	public int $pivotZ = 0;
	public ?BoundingBox $box = null;
	/**
	 * @var StructureProcessor[]
	 * @phpstan-var list<StructureProcessor>
	 */
	public array $processors = [];
	/** StructurePlaceSettings.knownShape: no shape updates after placement (jigsaw elements) */
	public bool $knownShape = false;
	/** LiquidSettings.APPLY_WATERLOGGING (default) or IGNORE_WATERLOGGING */
	public bool $applyWaterlogging = true;
	/** A fixed random for the processors; null: a random seeded from each block position (vanilla's default). */
	public LegacyRandom|FeatureRandom|null $random = null;

	public function setRotation(int $rotation) : self{
		$this->rotation = $rotation;
		return $this;
	}

	public function setMirror(int $mirror) : self{
		$this->mirror = $mirror;
		return $this;
	}

	public function setPivot(int $x, int $z) : self{
		$this->pivotX = $x;
		$this->pivotZ = $z;
		return $this;
	}

	public function setBox(?BoundingBox $box) : self{
		$this->box = $box;
		return $this;
	}

	public function addProcessor(StructureProcessor $processor) : self{
		$this->processors[] = $processor;
		return $this;
	}

	/**
	 * StructurePlaceSettings.getRandom(pos)
	 */
	public function getRandom(int $x, int $y, int $z) : LegacyRandom|FeatureRandom{
		return $this->random ?? new LegacyRandom(Int64::positionSeed($x, $y, $z));
	}
}
