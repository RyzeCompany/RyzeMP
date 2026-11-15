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
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\VersionInfo;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function array_fill;
use function count;

/**
 * Builds a structure template from blocks (vanilla StructureTemplate.fillFromWorld()): every block of the box is
 * kept, including air, except structure voids; the water of waterlogged blocks goes to the second layer; block entity
 * and entity data keep their world coordinates.
 *
 * @internal
 */
final class StructureTemplateBuilder{
	/**
	 * @var CompoundTag[]
	 * @phpstan-var list<CompoundTag>
	 */
	private array $palette = [];
	/**
	 * Palette index by block state ID.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $paletteIndexes = [];
	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $blocks;
	/**
	 * @var int[]|null
	 * @phpstan-var array<int, int>|null
	 */
	private ?array $liquids = null;
	/**
	 * @var CompoundTag[]
	 * @phpstan-var array<int, CompoundTag>
	 */
	private array $blockEntities = [];
	/**
	 * @var CompoundTag[]
	 * @phpstan-var list<CompoundTag>
	 */
	private array $entities = [];

	private int $structureVoidStateId;

	public function __construct(
		private int $sizeX,
		private int $sizeY,
		private int $sizeZ
	){
		$volume = $sizeX * $sizeY * $sizeZ;
		$this->blocks = $volume > 0 ? array_fill(0, $volume, StructureTemplate::VOID) : [];
		$this->structureVoidStateId = VanillaBlocks::STRUCTURE_VOID()->getStateId();
	}

	private function paletteIndex(int $stateId) : int{
		if(!isset($this->paletteIndexes[$stateId])){
			$this->paletteIndexes[$stateId] = count($this->palette);
			$this->palette[] = GlobalBlockStateHandlers::getSerializer()->serialize($stateId)->toVanillaNbt();
		}
		return $this->paletteIndexes[$stateId];
	}

	/**
	 * Sets the block at x/y/z (relative to the template). Structure voids are not saved.
	 */
	public function setBlock(int $x, int $y, int $z, int $stateId, int $liquidStateId = Block::EMPTY_STATE_ID) : void{
		$index = StructureTemplate::index($x, $y, $z, $this->sizeY, $this->sizeZ);
		$this->setBlockAtIndex($index, $stateId, $liquidStateId);
	}

	private function setBlockAtIndex(int $index, int $stateId, int $liquidStateId) : void{
		if($stateId === $this->structureVoidStateId){
			return;
		}
		$this->blocks[$index] = $this->paletteIndex($stateId);
		if($liquidStateId !== Block::EMPTY_STATE_ID){
			if($this->liquids === null){
				$this->liquids = array_fill(0, $this->sizeX * $this->sizeY * $this->sizeZ, StructureTemplate::VOID);
			}
			$this->liquids[$index] = $this->paletteIndex($liquidStateId);
		}
	}

	/**
	 * Sets the block entity data of the block at x/y/z (relative to the template).
	 */
	public function setBlockEntity(int $x, int $y, int $z, CompoundTag $nbt) : void{
		$index = StructureTemplate::index($x, $y, $z, $this->sizeY, $this->sizeZ);
		if($this->blocks[$index] !== StructureTemplate::VOID){
			$this->blockEntities[$index] = $nbt;
		}
	}

	public function addEntity(CompoundTag $nbt) : void{
		$this->entities[] = $nbt;
	}

	public function build(int $originX = 0, int $originY = 0, int $originZ = 0) : StructureTemplate{
		return new StructureTemplate($this->sizeX, $this->sizeY, $this->sizeZ, $this->palette, $this->blocks, $this->liquids, $this->blockEntities, $this->entities, $originX, $originY, $originZ);
	}

	/**
	 * Saves the blocks (unless $includeBlocks is false: vanilla's "Remove Blocks"), their block entities and, if asked,
	 * the entities (never players) of a box of the world. Blocks out of the world or in ungenerated chunks are saved
	 * as structure voids.
	 */
	public static function fromWorld(World $world, int $minX, int $minY, int $minZ, int $sizeX, int $sizeY, int $sizeZ, bool $includeEntities, bool $includeBlocks = true) : StructureTemplate{
		$builder = new self($sizeX, $sizeY, $sizeZ);
		if($includeBlocks){
			$worldMinY = $world->getMinY();
			$worldMaxY = $world->getMaxY();
			$voidStateId = $builder->structureVoidStateId;
			for($x = 0; $x < $sizeX; ++$x){
				$wx = $minX + $x;
				$lx = $wx & SubChunk::COORD_MASK;
				for($z = 0; $z < $sizeZ; ++$z){
					$wz = $minZ + $z;
					$chunk = $world->loadChunk($wx >> Chunk::COORD_BIT_SIZE, $wz >> Chunk::COORD_BIT_SIZE);
					if($chunk === null){
						continue;
					}
					$lz = $wz & SubChunk::COORD_MASK;
					$subChunkY = null;
					$blockLayer = null;
					$liquidLayer = null;
					$emptyStateId = Block::EMPTY_STATE_ID;
					//this is the hot loop of big saves (up to 64x384x64 blocks): read the paletted arrays directly
					$index = ($x * $sizeY) * $sizeZ + $z;
					for($y = 0; $y < $sizeY; ++$y, $index += $sizeZ){
						$wy = $minY + $y;
						if($wy < $worldMinY || $wy >= $worldMaxY){
							continue;
						}
						if($subChunkY !== $wy >> SubChunk::COORD_BIT_SIZE){
							$subChunkY = $wy >> SubChunk::COORD_BIT_SIZE;
							$subChunk = $chunk->getSubChunk($subChunkY);
							$layers = $subChunk->getBlockLayers();
							$blockLayer = $layers[0] ?? null;
							$liquidLayer = $layers[1] ?? null;
							$emptyStateId = $subChunk->getEmptyBlockId();
						}
						$ly = $wy & SubChunk::COORD_MASK;
						$stateId = $blockLayer !== null ? $blockLayer->get($lx, $ly, $lz) : $emptyStateId;
						if($stateId === $voidStateId){
							continue;
						}
						$builder->blocks[$index] = $builder->paletteIndexes[$stateId] ?? $builder->paletteIndex($stateId);
						if($liquidLayer !== null && ($liquidStateId = $liquidLayer->get($lx, $ly, $lz)) !== $emptyStateId){
							$builder->setBlockAtIndex($index, $stateId, $liquidStateId);
						}
					}
				}
			}

			$maxX = $minX + $sizeX - 1;
			$maxY = $minY + $sizeY - 1;
			$maxZ = $minZ + $sizeZ - 1;
			for($chunkX = $minX >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxX >> Chunk::COORD_BIT_SIZE; ++$chunkX){
				for($chunkZ = $minZ >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$chunkZ){
					foreach($world->getChunk($chunkX, $chunkZ)?->getTiles() ?? [] as $tile){
						$pos = $tile->getPosition();
						$tx = $pos->getFloorX();
						$ty = $pos->getFloorY();
						$tz = $pos->getFloorZ();
						if($tile->isClosed() || $tx < $minX || $tx > $maxX || $ty < $minY || $ty > $maxY || $tz < $minZ || $tz > $maxZ){
							continue;
						}
						$nbt = $tile->saveNBT();
						$nbt->removeTag(VersionInfo::TAG_WORLD_DATA_VERSION);
						$builder->setBlockEntity($tx - $minX, $ty - $minY, $tz - $minZ, $nbt);
					}
				}
			}
		}

		if($includeEntities){
			$box = new AxisAlignedBB($minX, $minY, $minZ, $minX + $sizeX, $minY + $sizeY, $minZ + $sizeZ);
			foreach($world->getNearbyEntities($box) as $entity){
				if(!self::canSaveEntity($entity, $box)){
					continue;
				}
				$nbt = $entity->saveNBT();
				$nbt->removeTag(VersionInfo::TAG_WORLD_DATA_VERSION);
				$builder->addEntity($nbt);
			}
		}

		return $builder->build($minX, $minY, $minZ);
	}

	private static function canSaveEntity(Entity $entity, AxisAlignedBB $box) : bool{
		if($entity instanceof Player || $entity->isClosed() || $entity->isFlaggedForDespawn() || !$entity->canSaveWithChunk()){
			return false;
		}
		//vanilla saves the entities whose position is in the box, not all those touching it
		$pos = $entity->getPosition();
		return $pos->x >= $box->minX && $pos->x < $box->maxX && $pos->y >= $box->minY && $pos->y < $box->maxY && $pos->z >= $box->minZ && $pos->z < $box->maxZ;
	}
}
