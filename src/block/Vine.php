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

namespace pocketmine\block;

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function array_intersect_key;
use function count;
use function max;
use function min;
use function mt_rand;

class Vine extends Flowable{

	/** @var int[] */
	protected array $faces = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacingFlags($this->faces);
	}

	/** @return int[] */
	public function getFaces() : array{ return $this->faces; }

	public function hasFace(int $face) : bool{
		return isset($this->faces[$face]);
	}

	/**
	 * @param int[] $faces
	 * @phpstan-param list<Facing::NORTH|Facing::EAST|Facing::SOUTH|Facing::WEST> $faces
	 * @return $this
	 */
	public function setFaces(array $faces) : self{
		$uniqueFaces = [];
		foreach($faces as $face){
			if($face !== Facing::NORTH && $face !== Facing::SOUTH && $face !== Facing::WEST && $face !== Facing::EAST){
				throw new \InvalidArgumentException("Facing can only be north, east, south or west");
			}
			$uniqueFaces[$face] = $face;
		}
		$this->faces = $uniqueFaces;
		return $this;
	}

	/** @return $this */
	public function setFace(int $face, bool $value) : self{
		if($face !== Facing::NORTH && $face !== Facing::SOUTH && $face !== Facing::WEST && $face !== Facing::EAST){
			throw new \InvalidArgumentException("Facing can only be north, east, south or west");
		}
		if($value){
			$this->faces[$face] = $face;
		}else{
			unset($this->faces[$face]);
		}
		return $this;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function canClimb() : bool{
		return true;
	}

	public function canBeReplaced() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		return true;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$blockReplace->getSide(Facing::opposite($face))->isFullCube() || Facing::axis($face) === Axis::Y){
			return false;
		}

		$this->faces = $blockReplace instanceof Vine ? $blockReplace->faces : [];
		$this->faces[Facing::opposite($face)] = Facing::opposite($face);

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$changed = false;

		$up = $this->getSide(Facing::UP);
		//check which faces have corresponding vines in the block above
		$supportedFaces = $up instanceof Vine ? array_intersect_key($this->faces, $up->faces) : [];

		foreach($this->faces as $face){
			if(!isset($supportedFaces[$face]) && !$this->getSide($face)->isSolid()){
				unset($this->faces[$face]);
				$changed = true;
			}
		}

		if($changed){
			$world = $this->position->getWorld();
			if(count($this->faces) === 0){
				$world->useBreakOn($this->position);
			}else{
				$world->setBlock($this->position, $this);
			}
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	/**
	 * Vine growth (Java VineBlock.randomTick; Bedrock vines grow the same way, without the ceiling face): a 1 in 4
	 * chance per random tick to grow in a random direction. Sideways the vine covers another face of its block, or
	 * spreads into the air next to it, around corners; upwards it spreads into the air above with a random half of
	 * its faces; downwards it copies a random half of its faces into the air or vine below. Sideways and upward growth
	 * stop when there are already 5 vines in the 9x3x9 box around.
	 */
	public function onRandomTick() : void{
		if(mt_rand(0, 3) === 0){
			$this->grow(Facing::ALL[mt_rand(0, 5)]);
		}
	}

	private function grow(int $direction) : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if($direction !== Facing::UP && $direction !== Facing::DOWN && !isset($this->faces[$direction])){
			if($this->canSpread($world, $x, $y, $z)){
				$this->spreadSideways($world, $direction);
			}
			return;
		}
		if($direction === Facing::UP && $y + 1 < $world->getMaxY()){
			$above = $world->getBlockAt($x, $y + 1, $z);
			if(self::canAttachTo($above, Facing::DOWN)){
				//Java covers the block above with the ceiling face, Bedrock vines have none
				return;
			}
			if($above->getTypeId() === BlockTypeIds::AIR){
				if($this->canSpread($world, $x, $y, $z)){
					$grown = VanillaBlocks::VINES();
					foreach(Facing::HORIZONTAL as $face){
						if(mt_rand(0, 1) === 0 && isset($this->faces[$face]) && self::canAttachTo($above->getSide($face), Facing::opposite($face))){
							$grown->setFace($face, true);
						}
					}
					if(count($grown->faces) > 0){
						BlockEventHelper::spread($above, $grown, $this);
					}
				}
				return;
			}
		}
		if($y - 1 >= $world->getMinY()){
			$below = $world->getBlockAt($x, $y - 1, $z);
			if($below instanceof Vine || $below->getTypeId() === BlockTypeIds::AIR){
				$grown = $below instanceof Vine ? clone $below : VanillaBlocks::VINES();
				$changed = false;
				foreach(Facing::HORIZONTAL as $face){
					if(mt_rand(0, 1) === 0 && isset($this->faces[$face]) && !isset($grown->faces[$face])){
						$grown->setFace($face, true);
						$changed = true;
					}
				}
				if($changed){
					BlockEventHelper::spread($below, $grown, $this);
				}
			}
		}
	}

	/**
	 * Growth towards a horizontal face the vine doesn't cover: onto that face if it can hold a vine, else into the air
	 * there on the face of a neighbour that continues one of the vine's faces, or around the corner of that face.
	 */
	private function spreadSideways(World $world, int $direction) : void{
		$target = $this->getSide($direction);
		if($target->getTypeId() !== BlockTypeIds::AIR){
			if(self::canAttachTo($target, Facing::opposite($direction))){
				BlockEventHelper::spread($this, (clone $this)->setFace($direction, true), $this);
			}
			return;
		}
		$targetPos = $target->getPosition();
		if(!$world->isInLoadedTerrain($targetPos)){
			return;
		}
		$clockwise = Facing::rotateY($direction, true);
		$counterClockwise = Facing::opposite($clockwise);
		$hasClockwise = isset($this->faces[$clockwise]);
		$hasCounterClockwise = isset($this->faces[$counterClockwise]);
		if($hasClockwise && self::canAttachTo($target->getSide($clockwise), $counterClockwise)){
			BlockEventHelper::spread($target, VanillaBlocks::VINES()->setFace($clockwise, true), $this);
		}elseif($hasCounterClockwise && self::canAttachTo($target->getSide($counterClockwise), $clockwise)){
			BlockEventHelper::spread($target, VanillaBlocks::VINES()->setFace($counterClockwise, true), $this);
		}else{
			$opposite = Facing::opposite($direction);
			foreach([$clockwise, $counterClockwise] as $side){
				if(!isset($this->faces[$side])){
					continue;
				}
				$corner = $target->getSide($side);
				if(
					$corner->getTypeId() === BlockTypeIds::AIR &&
					$world->isInLoadedTerrain($corner->getPosition()) &&
					self::canAttachTo($this->getSide($side), $direction)
				){
					BlockEventHelper::spread($corner, VanillaBlocks::VINES()->setFace($opposite, true), $this);
					return;
				}
			}
			//Java also grows a ceiling vine there 5% of the time, Bedrock vines have no ceiling face
		}
	}

	/**
	 * Whether the given block can hold a vine on the given face (Java MultifaceBlock.canAttachTo: a full face).
	 */
	private static function canAttachTo(Block $block, int $face) : bool{
		return $block->isFullCube() || $block->getSupportType($face) === SupportType::FULL;
	}

	/**
	 * Whether there are at most 4 vines (this one included) in the 9x3x9 box around (Java VineBlock.canSpread). Reads
	 * the block palettes of the subchunks in the box directly, skips the ones whose palette has no vine, and stops at
	 * the fifth vine; unloaded chunks count as empty.
	 */
	private function canSpread(World $world, int $x, int $y, int $z) : bool{
		$found = 0;
		$minX = $x - 4;
		$maxX = $x + 4;
		$minZ = $z - 4;
		$maxZ = $z + 4;
		$minY = max($world->getMinY(), $y - 1);
		$maxY = min($world->getMaxY() - 1, $y + 1);
		$typeId = $this->getTypeId();
		for($chunkX = $minX >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxX >> Chunk::COORD_BIT_SIZE; ++$chunkX){
			$fromX = max($minX, $chunkX << Chunk::COORD_BIT_SIZE) & Chunk::COORD_MASK;
			$toX = min($maxX, ($chunkX << Chunk::COORD_BIT_SIZE) | Chunk::COORD_MASK) & Chunk::COORD_MASK;
			for($chunkZ = $minZ >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				$fromZ = max($minZ, $chunkZ << Chunk::COORD_BIT_SIZE) & Chunk::COORD_MASK;
				$toZ = min($maxZ, ($chunkZ << Chunk::COORD_BIT_SIZE) | Chunk::COORD_MASK) & Chunk::COORD_MASK;
				for($subY = $minY >> SubChunk::COORD_BIT_SIZE; $subY <= $maxY >> SubChunk::COORD_BIT_SIZE; ++$subY){
					$layers = $chunk->getSubChunk($subY)->getBlockLayers();
					if(count($layers) === 0){
						continue;
					}
					$blocks = $layers[0];
					$hasVine = false;
					foreach($blocks->getPalette() as $stateId){
						if(($stateId >> Block::INTERNAL_STATE_DATA_BITS) === $typeId){
							$hasVine = true;
							break;
						}
					}
					if(!$hasVine){
						continue;
					}
					$fromY = max($minY, $subY << SubChunk::COORD_BIT_SIZE) & SubChunk::COORD_MASK;
					$toY = min($maxY, ($subY << SubChunk::COORD_BIT_SIZE) | SubChunk::COORD_MASK) & SubChunk::COORD_MASK;
					for($bx = $fromX; $bx <= $toX; ++$bx){
						for($bz = $fromZ; $bz <= $toZ; ++$bz){
							for($by = $fromY; $by <= $toY; ++$by){
								if(($blocks->get($bx, $by, $bz) >> Block::INTERNAL_STATE_DATA_BITS) === $typeId && ++$found > 4){
									return false;
								}
							}
						}
					}
				}
			}
		}
		return true;
	}

	public function getDrops(Item $item) : array{
		if(($item->getBlockToolType() & BlockToolType::SHEARS) !== 0){
			return $this->getDropsForCompatibleTool($item);
		}

		return [];
	}

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}

	public function getCompostabilityChance() : int{
		return 50;
	}
}
