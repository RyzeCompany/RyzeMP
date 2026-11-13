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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\JigsawInfo;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function count;
use function implode;

/**
 * The other pool element types: empty_pool_element, feature_pool_element and list_pool_element.
 */
final class OtherPoolElements{

	private function __construct(){
		//NOOP
	}

	public static function emptyElement() : PoolElement{
		return new class extends PoolElement{
			public function __construct(){
				parent::__construct(self::PROJECTION_TERRAIN_MATCHING);
			}

			public function getJigsaws(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : array{
				return [];
			}

			public function getBoundingBox(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : BoundingBox{
				throw new \LogicException("Invalid call to EmptyPoolElement.getBoundingBox, filter me!");
			}

			public function place(StructurePlaceContext $c, int $x, int $y, int $z, array $pivot, int $rotation, BoundingBox $box, bool $applyWaterlogging, bool $keepJigsaws) : bool{
				return true;
			}

			public function isEmpty() : bool{
				return true;
			}

			public function describe() : string{
				return "Empty";
			}
		};
	}

	/**
	 * feature_pool_element: a placed feature at the element's single (jigsaw) block.
	 */
	public static function feature(string $feature, int $projection) : PoolElement{
		return new class($feature, $projection) extends PoolElement{
			public function __construct(private string $feature, int $projection){
				parent::__construct($projection);
			}

			public function getJigsaws(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : array{
				//FeaturePoolElement.defaultJigsawNBT: facing down, top south, name "minecraft:bottom"
				return [new JigsawInfo($x, $y, $z, Direction::DOWN, Direction::SOUTH, "minecraft:bottom", "minecraft:empty", "minecraft:empty", "minecraft:air", true, 0, 0)];
			}

			public function getBoundingBox(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : BoundingBox{
				return new BoundingBox($x, $y, $z, $x, $y, $z);
			}

			public function place(StructurePlaceContext $c, int $x, int $y, int $z, array $pivot, int $rotation, BoundingBox $box, bool $applyWaterlogging, bool $keepJigsaws) : bool{
				$registry = $c->feature->registry;
				return $registry->placed($this->feature)->place($c->feature, $x, $y, $z);
			}

			public function describe() : string{
				return "Feature[" . $this->feature . "]";
			}
		};
	}

	/**
	 * list_pool_element: several elements placed at the same position (jigsaws of the first one).
	 * @phpstan-param list<PoolElement> $elements
	 */
	public static function list(array $elements, int $projection) : PoolElement{
		if(count($elements) === 0){
			throw new \RuntimeException("Elements are empty");
		}
		return new class($elements, $projection) extends PoolElement{
			/** @phpstan-param list<PoolElement> $elements */
			public function __construct(private array $elements, int $projection){
				parent::__construct($projection);
			}

			public function getJigsaws(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : array{
				return $this->elements[0]->getJigsaws($templates, $x, $y, $z, $rotation);
			}

			public function getBoundingBox(StructureTemplates $templates, int $x, int $y, int $z, int $rotation) : BoundingBox{
				$box = null;
				foreach($this->elements as $element){
					$elementBox = $element->getBoundingBox($templates, $x, $y, $z, $rotation);
					$box = $box === null ? $elementBox : $box->encapsulate($elementBox);
				}
				return $box ?? throw new \LogicException("Empty list element");
			}

			public function place(StructurePlaceContext $c, int $x, int $y, int $z, array $pivot, int $rotation, BoundingBox $box, bool $applyWaterlogging, bool $keepJigsaws) : bool{
				foreach($this->elements as $element){
					if(!$element->place($c, $x, $y, $z, $pivot, $rotation, $box, $applyWaterlogging, $keepJigsaws)){
						return false;
					}
				}
				return true;
			}

			public function describe() : string{
				$parts = [];
				foreach($this->elements as $element){
					$parts[] = $element->describe();
				}
				return "List[" . implode(", ", $parts) . "]";
			}
		};
	}
}
