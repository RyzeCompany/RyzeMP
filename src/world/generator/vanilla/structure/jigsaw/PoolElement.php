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

use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\template\JigsawInfo;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function usort;

/**
 * An element of a template pool (vanilla's StructurePoolElement).
 */
abstract class PoolElement{
	public const PROJECTION_RIGID = 0;
	public const PROJECTION_TERRAIN_MATCHING = 1;

	public function __construct(
		public readonly int $projection
	){}

	/**
	 * The jigsaw blocks of the element placed at a position with a rotation, in template order.
	 * @phpstan-return list<JigsawInfo>
	 */
	abstract public function getJigsaws(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : array;

	/**
	 * getShuffledJigsawBlocks(): shuffled, then sorted by selection priority (stable, highest first).
	 * @phpstan-return list<JigsawInfo>
	 */
	public function getShuffledJigsaws(StructureTemplates $templates, int $x, int $y, int $z, int $rotation, LegacyRandom|FeatureRandom $random) : array{
		$list = $this->getJigsaws($templates, $x, $y, $z, $rotation);
		StructureRandom::shuffle($list, $random);
		//a stable sort by priority leaves a list of equal priorities (the usual case) as it is: skip it
		$uniform = true;
		$first = $list[0] ?? null;
		if($first !== null){
			foreach($list as $jigsaw){
				if($jigsaw->selectionPriority !== $first->selectionPriority){
					$uniform = false;
					break;
				}
			}
		}
		if($uniform){
			return $list;
		}
		$indexed = [];
		foreach($list as $i => $jigsaw){
			$indexed[] = [$jigsaw, $i];
		}
		usort($indexed, fn(array $a, array $b) : int => [$b[0]->selectionPriority, $a[1]] <=> [$a[0]->selectionPriority, $b[1]]);
		$result = [];
		foreach($indexed as [$jigsaw]){
			$result[] = $jigsaw;
		}
		return $result;
	}

	abstract public function getBoundingBox(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : BoundingBox;

	/**
	 * Places the element (clipped to the box). $applyWaterlogging: the liquid settings (LiquidSettings).
	 * @phpstan-param array{int, int, int} $pivot
	 */
	abstract public function place(StructurePlaceContext $c, int $x, int $y, int $z, array $pivot, int $rotation, BoundingBox $box, bool $applyWaterlogging, bool $keepJigsaws) : bool;

	public function getGroundLevelDelta() : int{
		return 1;
	}

	/**
	 * Whether this is the empty element (EmptyPoolElement.INSTANCE).
	 */
	public function isEmpty() : bool{
		return false;
	}

	/**
	 * The element's own liquid settings, if it overrides the structure's (null: use the structure's).
	 */
	public function getLiquidOverride() : ?bool{
		return null;
	}

	/**
	 * A short description for debugging.
	 */
	abstract public function describe() : string;
}
