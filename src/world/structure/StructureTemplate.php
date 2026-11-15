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

use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\NbtException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Utils;
use function array_fill;
use function array_values;
use function count;
use function ctype_digit;

/**
 * A Bedrock structure template: the format of .mcstructure files, also stored in the world database under the key
 * "structuretemplate_<name>" (verified with BDS 1.26.52, which writes format_version 2).
 *
 * Blocks are stored in two layers (the second one holds the water of waterlogged blocks) of palette indexes, ordered
 * by x, then y, then z (see {@link StructureTemplate::index()}). An index outside the palette (-1, which reads back as
 * 0xffffffff from an int array) is a structure void: the block in the world is left as it is. Block entity data is
 * keyed by block index; block entities and entities keep the world coordinates they had when saved, relative to
 * {@link StructureTemplate::getWorldOrigin()}.
 */
final class StructureTemplate{
	public const FORMAT_VERSION = 2;

	public const TAG_FORMAT_VERSION = "format_version";
	public const TAG_SIZE = "size";
	public const TAG_STRUCTURE = "structure";
	public const TAG_WORLD_ORIGIN = "structure_world_origin";
	public const TAG_BLOCK_INDICES = "block_indices";
	public const TAG_ENTITIES = "entities";
	public const TAG_PALETTE = "palette";
	public const TAG_DEFAULT_PALETTE = "default";
	public const TAG_BLOCK_PALETTE = "block_palette";
	public const TAG_BLOCK_POSITION_DATA = "block_position_data";
	public const TAG_BLOCK_ENTITY_DATA = "block_entity_data";

	public const VOID = -1;

	/** Largest number of blocks accepted from saved data (four times the biggest structure block area) */
	public const MAX_VOLUME = 64 * 384 * 64 * 4;

	/**
	 * @param CompoundTag[] $palette       block states (name, states, version)
	 * @param int[]         $blocks        palette index of each block (layer 0)
	 * @param int[]|null    $liquids       palette index of the liquid of each block (layer 1), null if there is none
	 * @param CompoundTag[] $blockEntities block entity data by block index
	 * @param CompoundTag[] $entities      entity data
	 *
	 * @phpstan-param list<CompoundTag>       $palette
	 * @phpstan-param array<int, int>         $blocks
	 * @phpstan-param array<int, int>|null    $liquids
	 * @phpstan-param array<int, CompoundTag> $blockEntities
	 * @phpstan-param list<CompoundTag>       $entities
	 */
	public function __construct(
		private int $sizeX,
		private int $sizeY,
		private int $sizeZ,
		private array $palette,
		private array $blocks,
		private ?array $liquids = null,
		private array $blockEntities = [],
		private array $entities = [],
		private int $originX = 0,
		private int $originY = 0,
		private int $originZ = 0
	){
		if($sizeX < 0 || $sizeY < 0 || $sizeZ < 0){
			throw new \InvalidArgumentException("Structure size cannot be negative");
		}
		$volume = $sizeX * $sizeY * $sizeZ;
		if(count($blocks) !== $volume || ($liquids !== null && count($liquids) !== $volume)){
			throw new \InvalidArgumentException("Expected $volume block indexes per layer");
		}
	}

	/**
	 * Creates a template where every block is a structure void.
	 */
	public static function empty(int $sizeX, int $sizeY, int $sizeZ) : self{
		$volume = $sizeX * $sizeY * $sizeZ;
		return new self($sizeX, $sizeY, $sizeZ, [], $volume > 0 ? array_fill(0, $volume, self::VOID) : []);
	}

	/**
	 * Index of the block at x/y/z (relative to the template) in the block layers.
	 */
	public static function index(int $x, int $y, int $z, int $sizeY, int $sizeZ) : int{
		return ($x * $sizeY + $y) * $sizeZ + $z;
	}

	public function getSizeX() : int{ return $this->sizeX; }

	public function getSizeY() : int{ return $this->sizeY; }

	public function getSizeZ() : int{ return $this->sizeZ; }

	public function getVolume() : int{
		return $this->sizeX * $this->sizeY * $this->sizeZ;
	}

	/**
	 * @return int[] world position of the template's smallest corner when it was saved
	 * @phpstan-return array{int, int, int}
	 */
	public function getWorldOrigin() : array{
		return [$this->originX, $this->originY, $this->originZ];
	}

	/**
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	public function getPalette() : array{ return $this->palette; }

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public function getBlocks() : array{ return $this->blocks; }

	/**
	 * @return int[]|null
	 * @phpstan-return array<int, int>|null
	 */
	public function getLiquids() : ?array{ return $this->liquids; }

	/**
	 * Palette index of the block at the given block index, or null for a structure void.
	 */
	public function getPaletteIndex(int $index) : ?int{
		$entry = $this->blocks[$index] ?? self::VOID;
		return $entry >= 0 && $entry < count($this->palette) ? $entry : null;
	}

	/**
	 * Palette index of the liquid (layer 1) at the given block index, or null if there is none.
	 */
	public function getLiquidPaletteIndex(int $index) : ?int{
		if($this->liquids === null){
			return null;
		}
		$entry = $this->liquids[$index] ?? self::VOID;
		return $entry >= 0 && $entry < count($this->palette) ? $entry : null;
	}

	/**
	 * @return CompoundTag[]
	 * @phpstan-return array<int, CompoundTag>
	 */
	public function getBlockEntities() : array{ return $this->blockEntities; }

	public function getBlockEntity(int $index) : ?CompoundTag{
		return $this->blockEntities[$index] ?? null;
	}

	/**
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	public function getEntities() : array{ return $this->entities; }

	/**
	 * Whether the template has neither blocks (all structure voids) nor entities: vanilla refuses to save or load such
	 * a structure.
	 */
	public function isEmpty() : bool{
		if(count($this->entities) > 0){
			return false;
		}
		$paletteSize = count($this->palette);
		foreach($this->blocks as $entry){
			if($entry >= 0 && $entry < $paletteSize){
				return false;
			}
		}
		return true;
	}

	/**
	 * @throws StructureTemplateException
	 */
	public static function fromNbt(CompoundTag $root) : self{
		try{
			$size = self::readIntTriple($root->getListTag(self::TAG_SIZE), "size");
			[$sizeX, $sizeY, $sizeZ] = $size;
			if($sizeX < 0 || $sizeY < 0 || $sizeZ < 0){
				throw new StructureTemplateException("Negative structure size");
			}
			$volume = $sizeX * $sizeY * $sizeZ;
			if($volume > self::MAX_VOLUME){
				throw new StructureTemplateException("Structure too big ($sizeX x $sizeY x $sizeZ)");
			}
			$originTag = $root->getListTag(self::TAG_WORLD_ORIGIN);
			[$originX, $originY, $originZ] = $originTag !== null ? self::readIntTriple($originTag, "world origin") : [0, 0, 0];

			$structure = $root->getCompoundTag(self::TAG_STRUCTURE) ?? throw new StructureTemplateException("Missing structure data");
			$layers = self::readLayers($structure->getListTag(self::TAG_BLOCK_INDICES), $volume);
			$blocks = $layers[0] ?? ($volume > 0 ? array_fill(0, $volume, self::VOID) : []);
			$liquids = $layers[1] ?? null;

			$palette = [];
			$blockEntities = [];
			$defaultPalette = $structure->getCompoundTag(self::TAG_PALETTE)?->getCompoundTag(self::TAG_DEFAULT_PALETTE);
			if($defaultPalette !== null){
				foreach($defaultPalette->getListTag(self::TAG_BLOCK_PALETTE, CompoundTag::class) ?? [] as $entry){
					$palette[] = $entry;
				}
				foreach(Utils::promoteKeys($defaultPalette->getCompoundTag(self::TAG_BLOCK_POSITION_DATA)?->getValue() ?? []) as $key => $data){
					$key = (string) $key;
					if(!ctype_digit($key) || !$data instanceof CompoundTag){
						continue;
					}
					$index = (int) $key;
					$blockEntity = $data->getCompoundTag(self::TAG_BLOCK_ENTITY_DATA);
					if($index < $volume && $blockEntity !== null){
						$blockEntities[$index] = $blockEntity;
					}
				}
			}

			$entities = [];
			foreach($structure->getListTag(self::TAG_ENTITIES, CompoundTag::class) ?? [] as $entity){
				$entities[] = $entity;
			}
		}catch(NbtException $e){
			throw new StructureTemplateException($e->getMessage(), 0, $e);
		}

		return new self($sizeX, $sizeY, $sizeZ, $palette, $blocks, $liquids, $blockEntities, $entities, $originX, $originY, $originZ);
	}

	/**
	 * @throws StructureTemplateException
	 */
	public static function fromBinary(string $data) : self{
		try{
			$root = (new LittleEndianNbtSerializer())->read($data)->mustGetCompoundTag();
		}catch(NbtDataException $e){
			throw new StructureTemplateException($e->getMessage(), 0, $e);
		}
		return self::fromNbt($root);
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int, int}
	 * @throws StructureTemplateException
	 */
	private static function readIntTriple(?ListTag $tag, string $what) : array{
		$values = [];
		foreach($tag ?? [] as $value){
			if(!$value instanceof IntTag){
				throw new StructureTemplateException("Structure $what must be a list of ints");
			}
			$values[] = $value->getValue();
		}
		if(count($values) !== 3){
			throw new StructureTemplateException("Structure $what must have 3 values");
		}
		return [$values[0], $values[1], $values[2]];
	}

	/**
	 * Reads the block layers: a list of int arrays (format 2) or a list of lists of ints (format 1, .mcstructure).
	 *
	 * @return int[][]
	 * @phpstan-return list<list<int>>
	 * @throws StructureTemplateException
	 */
	private static function readLayers(?ListTag $tag, int $volume) : array{
		$layers = [];
		foreach($tag ?? [] as $layer){
			if(count($layers) === 2){
				break;
			}
			if($layer instanceof IntArrayTag){
				$values = $layer->getValue();
			}elseif($layer instanceof ListTag){
				$values = [];
				foreach($layer as $value){
					if(!$value instanceof IntTag){
						throw new StructureTemplateException("Block indices must be ints");
					}
					$values[] = $value->getValue();
				}
			}else{
				throw new StructureTemplateException("Unexpected block indices layer type");
			}
			if(count($values) !== $volume){
				throw new StructureTemplateException("Expected $volume block indices per layer, got " . count($values));
			}
			$layers[] = $values;
		}
		return $layers;
	}

	public function toNbt() : CompoundTag{
		$layers = [new IntArrayTag(array_values($this->blocks))];
		if($this->liquids !== null){
			$layers[] = new IntArrayTag(array_values($this->liquids));
		}
		$positionData = CompoundTag::create();
		foreach($this->blockEntities as $index => $data){
			$positionData->setTag((string) $index, CompoundTag::create()->setTag(self::TAG_BLOCK_ENTITY_DATA, $data));
		}
		return CompoundTag::create()
			->setInt(self::TAG_FORMAT_VERSION, self::FORMAT_VERSION)
			->setTag(self::TAG_SIZE, new ListTag([new IntTag($this->sizeX), new IntTag($this->sizeY), new IntTag($this->sizeZ)], NBT::TAG_Int))
			->setTag(self::TAG_STRUCTURE, CompoundTag::create()
				->setTag(self::TAG_BLOCK_INDICES, new ListTag($layers, NBT::TAG_IntArray))
				->setTag(self::TAG_ENTITIES, new ListTag($this->entities, NBT::TAG_Compound))
				->setTag(self::TAG_PALETTE, CompoundTag::create()
					->setTag(self::TAG_DEFAULT_PALETTE, CompoundTag::create()
						->setTag(self::TAG_BLOCK_PALETTE, new ListTag($this->palette, NBT::TAG_Compound))
						->setTag(self::TAG_BLOCK_POSITION_DATA, $positionData)
					)
				)
			)
			->setTag(self::TAG_WORLD_ORIGIN, new ListTag([new IntTag($this->originX), new IntTag($this->originY), new IntTag($this->originZ)], NBT::TAG_Int));
	}

	public function toBinary() : string{
		return (new LittleEndianNbtSerializer())->write(new TreeRoot($this->toNbt()));
	}
}
