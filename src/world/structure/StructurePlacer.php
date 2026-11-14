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
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\ShulkerBox as TileShulkerBox;
use pocketmine\block\tile\Tile;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\entity\Entity;
use pocketmine\entity\object\Painting;
use pocketmine\entity\object\PaintingMotive;
use pocketmine\math\Facing;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\utils\Random;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_search;
use function count;
use function mt_rand;

/**
 * Plans the placement of a structure template at a position (the template's smallest corner after the transform):
 * resolves and transforms the palette, and lists the blocks to place, layer by layer from the bottom, with their
 * block entity data moved to their new position, and the entities. Applying them to a world is done by
 * {@link StructurePlacementTask}.
 *
 * @internal
 */
final class StructurePlacer{
	private const TAG_SKULL_ROTATION = "Rotation";
	private const TAG_ENTITY_UNIQUE_ID = "UniqueID";

	private StructureTransform $transform;
	/**
	 * @var Block[]
	 * @phpstan-var array<int, Block>
	 */
	private array $blocksByPaletteIndex = [];

	public function __construct(
		private StructureTemplate $template,
		private int $x,
		private int $y,
		private int $z,
		private StructurePlaceSettings $settings
	){
		$this->transform = new StructureTransform($template->getSizeX(), $template->getSizeZ(), $settings->mirror, $settings->rotation);
	}

	public function getTemplate() : StructureTemplate{ return $this->template; }

	public function getSettings() : StructurePlaceSettings{ return $this->settings; }

	public function getTransform() : StructureTransform{ return $this->transform; }

	public function getMinX() : int{ return $this->x; }

	public function getMinY() : int{ return $this->y; }

	public function getMinZ() : int{ return $this->z; }

	/** Size along X in the world (after rotation) */
	public function getSizeX() : int{ return $this->transform->getTransformedSizeX(); }

	public function getSizeY() : int{ return $this->template->getSizeY(); }

	/** Size along Z in the world (after rotation) */
	public function getSizeZ() : int{ return $this->transform->getTransformedSizeZ(); }

	/**
	 * The block of a palette entry, transformed. Unknown block states become "info update" blocks, like unknown blocks
	 * of world data.
	 */
	public function getBlock(int $paletteIndex) : Block{
		if(!isset($this->blocksByPaletteIndex[$paletteIndex])){
			$entry = $this->template->getPalette()[$paletteIndex] ?? null;
			try{
				if($entry === null){
					throw new BlockStateDeserializeException("Missing palette entry");
				}
				$stateId = GlobalBlockStateHandlers::getDeserializer()->deserialize(GlobalBlockStateHandlers::getUpgrader()->upgradeBlockStateNbt($entry));
			}catch(BlockStateDeserializeException){
				$stateId = GlobalBlockStateHandlers::getDeserializer()->deserialize(GlobalBlockStateHandlers::getUnknownBlockStateData());
			}
			$this->blocksByPaletteIndex[$paletteIndex] = $this->transform->transformBlock(RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId));
		}
		return $this->blocksByPaletteIndex[$paletteIndex];
	}

	/**
	 * Number of blocks the template has (structure voids excluded), an upper bound of the blocks placed.
	 */
	public function countBlocks() : int{
		if(!$this->settings->includeBlocks){
			return 0;
		}
		$paletteSize = count($this->template->getPalette());
		$count = 0;
		foreach($this->template->getBlocks() as $entry){
			if($entry >= 0 && $entry < $paletteSize){
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The blocks to place, from the bottom layer up: world x, y, z, palette index of the block, palette index of its
	 * liquid (layer 1) or null, block entity data or null. The keys are the layers (template y).
	 *
	 * @return \Generator<int, array{int, int, int, int, int|null, CompoundTag|null}, void, void>
	 */
	public function blocks() : \Generator{
		if(!$this->settings->includeBlocks){
			return;
		}
		$template = $this->template;
		$sizeX = $template->getSizeX();
		$sizeY = $template->getSizeY();
		$sizeZ = $template->getSizeZ();
		$integrity = $this->settings->integrity;
		$random = $integrity < 100 ? new Random($this->settings->seed !== 0 ? $this->settings->seed : mt_rand()) : null;
		for($y = 0; $y < $sizeY; ++$y){
			$wy = $this->y + $y;
			for($x = 0; $x < $sizeX; ++$x){
				for($z = 0; $z < $sizeZ; ++$z){
					$index = StructureTemplate::index($x, $y, $z, $sizeY, $sizeZ);
					$paletteIndex = $template->getPaletteIndex($index);
					if($paletteIndex === null){
						continue;
					}
					if($random !== null && $random->nextFloat() * 100 >= $integrity){
						continue;
					}
					[$tx, $tz] = $this->transform->transformBlockPosition($x, $z);
					$wx = $this->x + $tx;
					$wz = $this->z + $tz;
					$blockEntity = $template->getBlockEntity($index);
					yield $y => [$wx, $wy, $wz, $paletteIndex, $template->getLiquidPaletteIndex($index), $blockEntity !== null ? $this->moveBlockEntity($blockEntity, $wx, $wy, $wz) : null];
				}
			}
		}
	}

	/**
	 * Block entity data moved to its new position: coordinates, the position of a paired chest, the rotation of a mob
	 * head, the facing of a shulker box (which only its block entity keeps).
	 */
	private function moveBlockEntity(CompoundTag $nbt, int $x, int $y, int $z) : CompoundTag{
		$nbt = clone $nbt;
		$nbt->setInt(Tile::TAG_X, $x)->setInt(Tile::TAG_Y, $y)->setInt(Tile::TAG_Z, $z);
		$pairX = $nbt->getTag(Chest::TAG_PAIRX);
		$pairZ = $nbt->getTag(Chest::TAG_PAIRZ);
		if($pairX instanceof IntTag && $pairZ instanceof IntTag){
			[$originX, , $originZ] = $this->template->getWorldOrigin();
			[$tx, $tz] = $this->transform->transformBlockPosition($pairX->getValue() - $originX, $pairZ->getValue() - $originZ);
			$nbt->setInt(Chest::TAG_PAIRX, $this->x + $tx)->setInt(Chest::TAG_PAIRZ, $this->z + $tz);
		}
		$id = $nbt->getString(Tile::TAG_ID, "");
		$rotation = $nbt->getTag(self::TAG_SKULL_ROTATION);
		if($rotation instanceof FloatTag && $id === "Skull"){
			$nbt->setFloat(self::TAG_SKULL_ROTATION, $this->transform->transformYaw($rotation->getValue()));
		}
		$facing = $nbt->getTag(TileShulkerBox::TAG_FACING);
		if($facing instanceof ByteTag && $id === "ShulkerBox" && $facing->getValue() >= Facing::DOWN && $facing->getValue() <= Facing::EAST){
			$nbt->setByte(TileShulkerBox::TAG_FACING, $this->transform->transformFacing($facing->getValue()));
		}
		return $nbt;
	}

	/**
	 * Moves the block a painting hangs on (the painting is drawn from it, not from the entity position) and turns its
	 * direction. A mirror along one axis swaps the sides of a painting, so the block of a painting of even width moves
	 * by one to keep the painting on the same blocks.
	 */
	private function movePainting(CompoundTag $nbt, int $originX, int $originY, int $originZ) : void{
		$tileX = $nbt->getTag(Painting::TAG_TILE_X);
		$tileY = $nbt->getTag(Painting::TAG_TILE_Y);
		$tileZ = $nbt->getTag(Painting::TAG_TILE_Z);
		if(!$tileX instanceof IntTag || !$tileY instanceof IntTag || !$tileZ instanceof IntTag){
			return;
		}
		[$tx, $tz] = $this->transform->transformBlockPosition($tileX->getValue() - $originX, $tileZ->getValue() - $originZ);
		$x = $this->x + $tx;
		$z = $this->z + $tz;
		$shifted = false;
		foreach([Painting::TAG_DIRECTION_BE, Painting::TAG_FACING_JE] as $tagName){
			$direction = $nbt->getTag($tagName);
			if(!$direction instanceof ByteTag || !isset(Painting::DATA_TO_FACING[$direction->getValue()])){
				continue;
			}
			$facing = $this->transform->transformFacing(Painting::DATA_TO_FACING[$direction->getValue()]);
			$nbt->setByte($tagName, (int) array_search($facing, Painting::DATA_TO_FACING, true));
			if(!$shifted && $this->settings->mirror->swapsHandedness() && (PaintingMotive::getMotiveByName($nbt->getString(Painting::TAG_MOTIVE, ""))?->getWidth() ?? 1) % 2 === 0){
				$shifted = true;
				[$dx, , $dz] = Facing::OFFSET[Facing::rotateY($facing, true)];
				$x += $dx;
				$z += $dz;
			}
		}
		$nbt->setInt(Painting::TAG_TILE_X, $x);
		$nbt->setInt(Painting::TAG_TILE_Y, $this->y + $tileY->getValue() - $originY);
		$nbt->setInt(Painting::TAG_TILE_Z, $z);
	}

	/**
	 * The entities of the template, moved to their new position (and turned), without their unique IDs.
	 *
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	public function entities() : array{
		if(!$this->settings->includeEntities){
			return [];
		}
		[$originX, $originY, $originZ] = $this->template->getWorldOrigin();
		$result = [];
		foreach($this->template->getEntities() as $entity){
			$pos = [];
			foreach($entity->getListTag(Entity::TAG_POS) ?? [] as $value){
				if($value instanceof DoubleTag || $value instanceof FloatTag){
					$pos[] = $value->getValue();
				}
			}
			if(count($pos) !== 3){
				continue;
			}
			[$tx, $tz] = $this->transform->transformExactPosition($pos[0] - $originX, $pos[2] - $originZ);
			$nbt = clone $entity;
			$nbt->removeTag(self::TAG_ENTITY_UNIQUE_ID);
			$nbt->setTag(Entity::TAG_POS, new ListTag([
				new DoubleTag($this->x + $tx),
				new DoubleTag($this->y + $pos[1] - $originY),
				new DoubleTag($this->z + $tz)
			], NBT::TAG_Double));
			$rotation = $entity->getListTag(Entity::TAG_ROTATION);
			if($rotation !== null && count($rotation) === 2 && ($yaw = $rotation->get(0)) instanceof FloatTag && ($pitch = $rotation->get(1)) instanceof FloatTag){
				$nbt->setTag(Entity::TAG_ROTATION, new ListTag([
					new FloatTag($this->transform->transformYaw($yaw->getValue())),
					new FloatTag($pitch->getValue())
				], NBT::TAG_Float));
			}
			$this->movePainting($nbt, $originX, $originY, $originZ);
			$result[] = $nbt;
		}
		return $result;
	}
}
