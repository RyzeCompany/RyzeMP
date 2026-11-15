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

namespace pocketmine\world\structure;

use pocketmine\block\Block;
use pocketmine\block\Crafter;
use pocketmine\block\Door;
use pocketmine\block\FloorCoralFan;
use pocketmine\block\Hopper;
use pocketmine\block\Jigsaw;
use pocketmine\block\Lever;
use pocketmine\block\MobHead;
use pocketmine\block\NetherPortal;
use pocketmine\block\Rail;
use pocketmine\block\Stair;
use pocketmine\block\Stem;
use pocketmine\block\StraightOnlyRail;
use pocketmine\block\Torch;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\CrafterOrientation;
use pocketmine\block\utils\HorizontalConnections;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\LeverFacing;
use pocketmine\block\utils\MultiAnyFacing;
use pocketmine\block\utils\PillarRotation;
use pocketmine\block\utils\RailConnectionInfo;
use pocketmine\block\utils\SignLikeRotation;
use pocketmine\block\utils\StairShape;
use pocketmine\block\Vine;
use pocketmine\block\Wall;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use function array_search;
use function fmod;
use function in_array;

/**
 * Mirror and rotation of a structure template of a given size, as Bedrock applies them when loading a structure
 * (verified against BDS 1.26.52 /structure load): the blocks are mirrored first, then rotated clockwise, and the result
 * is moved back so that its smallest corner stays at the load position.
 *
 * @internal
 */
final class StructureTransform{

	public function __construct(
		public readonly int $sizeX,
		public readonly int $sizeZ,
		public readonly StructureMirror $mirror = StructureMirror::NONE,
		public readonly StructureRotation $rotation = StructureRotation::NONE
	){}

	public function isIdentity() : bool{
		return $this->mirror === StructureMirror::NONE && $this->rotation === StructureRotation::NONE;
	}

	private function swapsAxes() : bool{
		return $this->rotation === StructureRotation::CLOCKWISE_90 || $this->rotation === StructureRotation::CLOCKWISE_270;
	}

	public function getTransformedSizeX() : int{
		return $this->swapsAxes() ? $this->sizeZ : $this->sizeX;
	}

	public function getTransformedSizeZ() : int{
		return $this->swapsAxes() ? $this->sizeX : $this->sizeZ;
	}

	/**
	 * Position of a block of the template after the transform, relative to the load position.
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public function transformBlockPosition(int $x, int $z) : array{
		if($this->mirror->flipsZ()){
			$z = $this->sizeZ - 1 - $z;
		}
		if($this->mirror->flipsX()){
			$x = $this->sizeX - 1 - $x;
		}
		return match($this->rotation){
			StructureRotation::NONE => [$x, $z],
			StructureRotation::CLOCKWISE_90 => [$this->sizeZ - 1 - $z, $x],
			StructureRotation::CLOCKWISE_180 => [$this->sizeX - 1 - $x, $this->sizeZ - 1 - $z],
			StructureRotation::CLOCKWISE_270 => [$z, $this->sizeX - 1 - $x],
		};
	}

	/**
	 * Exact position (entities) after the transform, relative to the load position.
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	public function transformExactPosition(float $x, float $z) : array{
		if($this->mirror->flipsZ()){
			$z = $this->sizeZ - $z;
		}
		if($this->mirror->flipsX()){
			$x = $this->sizeX - $x;
		}
		return match($this->rotation){
			StructureRotation::NONE => [$x, $z],
			StructureRotation::CLOCKWISE_90 => [$this->sizeZ - $z, $x],
			StructureRotation::CLOCKWISE_180 => [$this->sizeX - $x, $this->sizeZ - $z],
			StructureRotation::CLOCKWISE_270 => [$z, $this->sizeX - $x],
		};
	}

	/**
	 * A direction (Facing::*) after the transform. Up and down never change.
	 */
	public function transformFacing(int $facing) : int{
		$axis = Facing::axis($facing);
		if($axis === Axis::Y){
			return $facing;
		}
		if(($axis === Axis::Z && $this->mirror->flipsZ()) || ($axis === Axis::X && $this->mirror->flipsX())){
			$facing = Facing::opposite($facing);
		}
		for($i = $this->rotation->getQuarterTurns(); $i > 0; --$i){
			$facing = Facing::rotateY($facing, true);
		}
		return $facing;
	}

	/**
	 * A yaw in degrees (0 = south, 90 = west) after the transform.
	 */
	public function transformYaw(float $yaw) : float{
		if($this->mirror->flipsZ()){
			$yaw = 180 - $yaw;
		}
		if($this->mirror->flipsX()){
			$yaw = -$yaw;
		}
		$yaw = fmod($yaw + 90 * $this->rotation->getQuarterTurns(), 360);
		return $yaw < 0 ? $yaw + 360 : $yaw;
	}

	/**
	 * A sign-like rotation (0-15, sixteenths of a turn from south, clockwise) after the transform.
	 */
	public function transformSignRotation(int $rotation) : int{
		if($this->mirror->flipsZ()){
			$rotation = 8 - $rotation;
		}
		if($this->mirror->flipsX()){
			$rotation = 16 - $rotation;
		}
		return ($rotation + 4 * $this->rotation->getQuarterTurns()) & 0xf;
	}

	private function transformHorizontalAxis(int $axis) : int{
		if($axis !== Axis::Y && $this->swapsAxes()){
			return $axis === Axis::X ? Axis::Z : Axis::X;
		}
		return $axis;
	}

	/**
	 * A block of the template after the transform: its facing, axis, rotation, connections, rail shape... Blocks
	 * without directional state are returned unchanged.
	 */
	public function transformBlock(Block $block) : Block{
		if($this->isIdentity()){
			return $block;
		}
		$block = clone $block;
		if($block instanceof HorizontalFacing || $block instanceof AnyFacing){
			$block->setFacing($this->transformFacing($block->getFacing()));
		}elseif($block instanceof Torch || $block instanceof Hopper || $block instanceof Stem){
			$block->setFacing($this->transformFacing($block->getFacing()));
		}elseif($block instanceof MobHead){
			$block->setFacing($this->transformFacing($block->getFacing()));
			$block->setRotation($this->transformSignRotation($block->getRotation()));
		}
		if($block instanceof PillarRotation){
			$block->setAxis($this->transformHorizontalAxis($block->getAxis()));
		}elseif($block instanceof FloorCoralFan || $block instanceof NetherPortal){
			$block->setAxis($this->transformHorizontalAxis($block->getAxis()));
		}
		if($block instanceof SignLikeRotation){
			$block->setRotation($this->transformSignRotation($block->getRotation()));
		}
		if($block instanceof MultiAnyFacing || $block instanceof Vine){
			$faces = [];
			foreach($block->getFaces() as $face){
				$faces[] = $this->transformFacing($face);
			}
			$block->setFaces($faces);
		}
		if($block instanceof HorizontalConnections){
			$connections = $block->getConnections();
			foreach(Facing::HORIZONTAL as $face){
				$block->setConnection($face, false);
			}
			foreach($connections as $face){
				$block->setConnection($this->transformFacing($face), true);
			}
		}elseif($block instanceof Wall){
			$connections = $block->getConnections();
			foreach(Facing::HORIZONTAL as $face){
				$block->setConnection($face, null);
			}
			foreach($connections as $face => $type){
				$block->setConnection($this->transformFacing($face), $type);
			}
		}
		if($this->mirror->swapsHandedness()){
			if($block instanceof Door){
				$block->setHingeRight(!$block->isHingeRight());
			}elseif($block instanceof Stair){
				$block->setShape(match($block->getShape()){
					StairShape::INNER_LEFT => StairShape::INNER_RIGHT,
					StairShape::INNER_RIGHT => StairShape::INNER_LEFT,
					StairShape::OUTER_LEFT => StairShape::OUTER_RIGHT,
					StairShape::OUTER_RIGHT => StairShape::OUTER_LEFT,
					StairShape::STRAIGHT => StairShape::STRAIGHT,
				});
			}
		}
		if($block instanceof Lever){
			$block->setFacing($this->transformLeverFacing($block->getFacing()));
		}elseif($block instanceof Rail || $block instanceof StraightOnlyRail){
			$block->setShape($this->transformRailShape($block->getShape(), $block instanceof Rail));
		}elseif($block instanceof Crafter){
			$orientation = $block->getOrientation();
			$block->setOrientation(CrafterOrientation::fromFrontAndTop(
				$this->transformFacing($orientation->getFront()),
				$this->transformFacing($orientation->getTop())
			));
		}elseif($block instanceof Jigsaw){
			$block->setRotation($this->transformJigsawRotation($block->getFacing(), $block->getRotation()));
		}
		return $block;
	}

	private function transformLeverFacing(LeverFacing $facing) : LeverFacing{
		return match($facing){
			LeverFacing::UP_AXIS_X, LeverFacing::UP_AXIS_Z => $this->transformHorizontalAxis($facing === LeverFacing::UP_AXIS_X ? Axis::X : Axis::Z) === Axis::X ? LeverFacing::UP_AXIS_X : LeverFacing::UP_AXIS_Z,
			LeverFacing::DOWN_AXIS_X, LeverFacing::DOWN_AXIS_Z => $this->transformHorizontalAxis($facing === LeverFacing::DOWN_AXIS_X ? Axis::X : Axis::Z) === Axis::X ? LeverFacing::DOWN_AXIS_X : LeverFacing::DOWN_AXIS_Z,
			default => match($this->transformFacing($facing->getFacing())){
				Facing::NORTH => LeverFacing::NORTH,
				Facing::SOUTH => LeverFacing::SOUTH,
				Facing::EAST => LeverFacing::EAST,
				default => LeverFacing::WEST,
			}
		};
	}

	/**
	 * A rail shape (BaseRail shape, Bedrock rail_direction) after the transform, found from its connections.
	 */
	private function transformRailShape(int $shape, bool $curves) : int{
		$connections = RailConnectionInfo::CONNECTIONS[$shape] ?? RailConnectionInfo::CURVE_CONNECTIONS[$shape] ?? null;
		if($connections === null){
			return $shape;
		}
		$transformed = [];
		foreach($connections as $connection){
			$ascend = $connection & RailConnectionInfo::FLAG_ASCEND;
			$transformed[] = $this->transformFacing($connection & ~RailConnectionInfo::FLAG_ASCEND) | $ascend;
		}
		$candidates = $curves ? RailConnectionInfo::CONNECTIONS + RailConnectionInfo::CURVE_CONNECTIONS : RailConnectionInfo::CONNECTIONS;
		foreach($candidates as $candidate => $candidateConnections){
			if(in_array($transformed[0], $candidateConnections, true) && in_array($transformed[1], $candidateConnections, true)){
				return $candidate;
			}
		}
		return $shape;
	}

	/**
	 * Jigsaw rotation after the transform: for a front facing up or down it is the direction of the top, otherwise a
	 * roll around the front, which only a mirror changes.
	 */
	private function transformJigsawRotation(int $front, int $rotation) : int{
		if(Facing::axis($front) === Axis::Y){
			$top = Jigsaw::TOP_DIRECTIONS[$rotation] ?? Facing::NORTH;
			return (int) array_search($this->transformFacing($top), Jigsaw::TOP_DIRECTIONS, true);
		}
		return $this->mirror->swapsHandedness() ? (4 - $rotation) & 3 : $rotation;
	}
}
