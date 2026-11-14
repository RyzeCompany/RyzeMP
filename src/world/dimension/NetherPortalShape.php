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

namespace pocketmine\world\dimension;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\NetherPortal;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function intdiv;
use function max;

/**
 * The inside of a rectangular obsidian frame, standing on the X or Z axis. The corners of the frame are not needed.
 */
final class NetherPortalShape{
	public const MIN_WIDTH = 2;
	public const MIN_HEIGHT = 3;
	public const MAX_WIDTH = 21;
	public const MAX_HEIGHT = 21;

	/**
	 * @param int $axis Axis::X or Axis::Z: the horizontal direction the portal spans (the portal block axis)
	 */
	public function __construct(
		public readonly Vector3 $origin, //lowest interior block on the negative side of the axis
		public readonly int $axis,
		public readonly int $width,
		public readonly int $height
	){}

	private static function step(int $axis, int $distance) : Vector3{
		return $axis === Axis::X ? new Vector3($distance, 0, 0) : new Vector3(0, 0, $distance);
	}

	public static function isFrame(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::OBSIDIAN;
	}

	/**
	 * Blocks that may fill an unlit frame: fire is replaced too, since lighting the frame places fire in it first.
	 */
	public static function isIgnitableInterior(Block $block) : bool{
		$id = $block->getTypeId();
		return $id === BlockTypeIds::AIR || $id === BlockTypeIds::FIRE || $id === BlockTypeIds::SOUL_FIRE;
	}

	/**
	 * Finds an empty frame containing the given position, preferring the X axis like vanilla.
	 */
	public static function findIgnitable(World $world, Vector3 $pos) : ?self{
		foreach([Axis::X, Axis::Z] as $axis){
			$shape = self::scan($world, $pos, $axis, self::isIgnitableInterior(...));
			if($shape !== null){
				return $shape;
			}
		}
		return null;
	}

	/**
	 * Lights the empty obsidian frame around the given position, if there is one. Portals can't be lit in the End.
	 */
	public static function tryLight(World $world, Vector3 $pos) : bool{
		if($world->getDimension() === Dimension::END){
			return false;
		}
		$shape = self::findIgnitable($world, $pos);
		if($shape === null){
			return false;
		}
		$shape->light($world);
		NetherPortalRegistry::getInstance()->add($world, $shape);
		return true;
	}

	/**
	 * Called when fire or soul fire appears where there was none (vanilla BaseFireBlock.onPlace()): fire inside an
	 * empty obsidian frame lights the portal, whatever put it there (flint and steel, fire charges, spreading fire, lava,
	 * lightning, fireballs, dispensers...). Not in the End.
	 *
	 * @internal
	 */
	public static function onFirePlaced(World $world, int $x, int $y, int $z) : void{
		if($world->getDimension() === Dimension::END){
			return;
		}
		//most fires stand on the ground: the bottom of a frame must be under the column of air and fire they are in
		$minY = max($world->getMinY(), $y - self::MAX_HEIGHT);
		$bottom = $y - 1;
		while($bottom >= $minY && self::isIgnitableInterior($world->getBlockAt($x, $bottom, $z))){
			--$bottom;
		}
		if($bottom < $minY || !self::isFrame($world->getBlockAt($x, $bottom, $z))){
			return;
		}
		self::tryLight($world, new Vector3($x, $y, $z));
	}

	/**
	 * Finds the lit portal containing the given portal block.
	 */
	public static function findLit(World $world, Vector3 $pos) : ?self{
		$block = $world->getBlock($pos);
		if(!$block instanceof NetherPortal){
			return null;
		}
		$axis = $block->getAxis();
		return self::scan($world, $pos, $axis, fn(Block $b) => $b instanceof NetherPortal && $b->getAxis() === $axis);
	}

	/**
	 * @phpstan-param \Closure(Block) : bool $isInterior
	 */
	private static function scan(World $world, Vector3 $pos, int $axis, \Closure $isInterior) : ?self{
		$pos = $pos->floor();
		if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) || !$isInterior($world->getBlock($pos))){
			return null;
		}
		$negative = self::step($axis, -1);
		$positive = self::step($axis, 1);

		$origin = $pos;
		for($i = 0; $isInterior($world->getBlock($origin->down())); ++$i){
			if($i >= self::MAX_HEIGHT){
				return null;
			}
			$origin = $origin->down();
		}
		for($i = 0; $isInterior($world->getBlock($origin->addVector($negative))); ++$i){
			if($i >= self::MAX_WIDTH){
				return null;
			}
			$origin = $origin->addVector($negative);
		}

		$width = 0;
		for($p = $origin; $isInterior($world->getBlock($p)); $p = $p->addVector($positive)){
			if(++$width > self::MAX_WIDTH){
				return null;
			}
		}
		$height = 0;
		for($p = $origin; $isInterior($world->getBlock($p)); $p = $p->up()){
			if(++$height > self::MAX_HEIGHT){
				return null;
			}
		}
		if($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT){
			return null;
		}

		for($y = 0; $y < $height; ++$y){
			$row = $origin->add(0, $y, 0);
			if(!self::isFrame($world->getBlock($row->addVector($negative))) || !self::isFrame($world->getBlock($row->addVector(self::step($axis, $width))))){
				return null;
			}
			for($w = 0; $w < $width; ++$w){
				if(!$isInterior($world->getBlock($row->addVector(self::step($axis, $w))))){
					return null;
				}
			}
		}
		for($w = 0; $w < $width; ++$w){
			$column = $origin->addVector(self::step($axis, $w));
			if(!self::isFrame($world->getBlock($column->down())) || !self::isFrame($world->getBlock($column->add(0, $height, 0)))){
				return null;
			}
		}

		return new self($origin, $axis, $width, $height);
	}

	/**
	 * @return Vector3[]
	 */
	public function getInteriorPositions() : array{
		$positions = [];
		for($y = 0; $y < $this->height; ++$y){
			for($w = 0; $w < $this->width; ++$w){
				$positions[] = $this->origin->add(0, $y, 0)->addVector(self::step($this->axis, $w));
			}
		}
		return $positions;
	}

	/**
	 * Fills the frame with portal blocks.
	 */
	public function light(World $world) : void{
		$portal = VanillaBlocks::NETHER_PORTAL()->setAxis($this->axis);
		foreach($this->getInteriorPositions() as $pos){
			$world->setBlock($pos, $portal);
		}
	}

	/**
	 * Where an entity coming out of this portal appears: the middle of the bottom row.
	 */
	public function getArrivalPosition() : Vector3{
		$middle = $this->origin->addVector(self::step($this->axis, intdiv($this->width - 1, 2)));
		return $middle->add(0.5, 0, 0.5);
	}
}
