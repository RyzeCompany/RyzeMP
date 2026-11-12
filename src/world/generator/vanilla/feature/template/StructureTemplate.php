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

namespace pocketmine\world\generator\vanilla\feature\template;

use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function file_exists;
use function in_array;
use function max;
use function min;
use function usort;
use function zlib_decode;

/**
 * A structure template (data/minecraft/structure/*.nbt) with the palettes in vanilla's placement order
 * (StructureTemplate.buildInfoList(): full blocks, then other blocks, then block entities, each sorted by y, x, z),
 * and its placement (StructureTemplate.placeInWorld()) without entities and shape updates.
 */
final class StructureTemplate{
	public const ROTATION_NONE = 0;
	public const ROTATION_CLOCKWISE_90 = 1;
	public const ROTATION_CLOCKWISE_180 = 2;
	public const ROTATION_COUNTERCLOCKWISE_90 = 3;

	public const ROTATION_NAMES = ["none", "clockwise_90", "180", "counterclockwise_90"];

	/** Horizontal directions rotated clockwise: NORTH -> EAST -> SOUTH -> WEST. */
	private const CLOCKWISE = [Direction::NORTH => Direction::EAST, Direction::EAST => Direction::SOUTH, Direction::SOUTH => Direction::WEST, Direction::WEST => Direction::NORTH];

	/**
	 * @var int[][][]
	 * @phpstan-var list<list<array{int, int, int, int, ?CompoundTag}>> palettes of [x, y, z, state, block entity data]
	 */
	public array $palettes = [];

	/** @phpstan-var array<int, array<int, int>> rotated state cache: rotation => state => state */
	private array $rotated = [];

	public function __construct(
		public readonly int $sizeX,
		public readonly int $sizeY,
		public readonly int $sizeZ
	){}

	/** @phpstan-var array<string, array<string, StructureTemplate>> */
	private static array $cache = [];

	public static function load(WorldgenData $data, JavaBlocks $blocks, string $id) : self{
		$root = $data->getRoot();
		$id = JavaBlocks::namespaced($id);
		if(isset(self::$cache[$root][$id])){
			return self::$cache[$root][$id];
		}
		$path = $root . "/data/minecraft/structure/" . WorldgenData::stripNamespace($id) . ".nbt";
		if(!file_exists($path)){
			throw new \RuntimeException("Missing structure template $path (run the vanilla data fetcher again)");
		}
		$raw = zlib_decode(Filesystem::fileGetContents($path));
		if($raw === false){
			throw new \RuntimeException("Corrupted structure template $path");
		}
		$nbt = (new BigEndianNbtSerializer())->read($raw)->mustGetCompoundTag();
		$size = self::intList($nbt->getListTag("size"));
		if(count($size) !== 3){
			throw new \RuntimeException("Invalid structure template size in $path");
		}
		$template = new self($size[0], $size[1], $size[2]);

		$paletteLists = [];
		$palettes = $nbt->getListTag("palettes");
		if($palettes !== null){
			foreach($palettes as $list){
				if($list instanceof ListTag){
					$paletteLists[] = $list;
				}
			}
		}else{
			$palette = $nbt->getListTag("palette");
			if($palette !== null){
				$paletteLists[] = $palette;
			}
		}
		$blockList = $nbt->getListTag("blocks");
		foreach($paletteLists as $paletteTag){
			$states = [];
			foreach($paletteTag as $entry){
				$states[] = match(true){
					$entry instanceof CompoundTag => self::readState($blocks, $entry),
					$entry instanceof StringTag => self::readStateString($blocks, $entry->getValue()),
					default => $blocks->air
				};
			}
			$full = [];
			$other = [];
			$withNbt = [];
			foreach($blockList ?? [] as $blockTag){
				if(!$blockTag instanceof CompoundTag){
					continue;
				}
				$pos = self::intList($blockTag->getListTag("pos"));
				$state = $states[$blockTag->getInt("state", 0)] ?? $blocks->air;
				$blockNbt = $blockTag->getCompoundTag("nbt");
				$info = [$pos[0] ?? 0, $pos[1] ?? 0, $pos[2] ?? 0, $state, $blockNbt];
				if($blockNbt !== null){
					$withNbt[] = $info;
				}elseif($blocks->isFullCube($state)){
					$full[] = $info;
				}else{
					$other[] = $info;
				}
			}
			$template->palettes[] = [...self::sorted($full), ...self::sorted($other), ...self::sorted($withNbt)];
		}
		return self::$cache[$root][$id] = $template;
	}

	/**
	 * @phpstan-param list<array{int, int, int, int, ?CompoundTag}> $list
	 * @phpstan-return list<array{int, int, int, int, ?CompoundTag}>
	 */
	private static function sorted(array $list) : array{
		//List.sort() is stable; the original order is the order in the file
		$indexed = [];
		foreach($list as $i => $info){
			$indexed[] = [$info, $i];
		}
		usort($indexed, fn(array $a, array $b) : int => [$a[0][1], $a[0][0], $a[0][2], $a[1]] <=> [$b[0][1], $b[0][0], $b[0][2], $b[1]]);
		$out = [];
		foreach($indexed as [$info]){
			$out[] = $info;
		}
		return $out;
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function intList(?ListTag $tag) : array{
		$out = [];
		foreach($tag ?? [] as $value){
			if($value instanceof IntTag){
				$out[] = $value->getValue();
			}
		}
		return $out;
	}

	private static function readStateString(JavaBlocks $blocks, string $string) : int{
		try{
			return $blocks->parseState($string);
		}catch(\RuntimeException){
			return $blocks->air;
		}
	}

	/**
	 * A palette entry: {"Name", "Properties"} (older) or {"id", "properties"} (26.x), or {"": "name[...]"}.
	 */
	private static function readState(JavaBlocks $blocks, CompoundTag $tag) : int{
		$plain = $tag->getTag("");
		if($plain instanceof StringTag){
			return self::readStateString($blocks, $plain->getValue());
		}
		$name = $tag->getString("Name", $tag->getString("id", "minecraft:air"));
		if(!$blocks->hasBlock($name)){
			return $blocks->air;
		}
		$state = $blocks->defaultOf($name);
		$properties = $tag->getCompoundTag("Properties") ?? $tag->getCompoundTag("properties");
		if($properties !== null){
			foreach(Utils::promoteKeys($properties->getValue()) as $key => $value){
				if($value instanceof StringTag && $blocks->hasProperty($state, (string) $key) && in_array($value->getValue(), $blocks->propertyValues($state, (string) $key), true)){
					$state = $blocks->with($state, (string) $key, $value->getValue());
				}
			}
		}
		return $state;
	}

	/**
	 * getSize(rotation)
	 * @phpstan-return array{int, int, int}
	 */
	public function getSize(int $rotation) : array{
		return $rotation === self::ROTATION_CLOCKWISE_90 || $rotation === self::ROTATION_COUNTERCLOCKWISE_90 ? [$this->sizeZ, $this->sizeY, $this->sizeX] : [$this->sizeX, $this->sizeY, $this->sizeZ];
	}

	/**
	 * StructureTemplate.transform() with no mirror and the pivot at the origin.
	 * @phpstan-return array{int, int}
	 */
	public static function transform(int $x, int $z, int $rotation) : array{
		return match($rotation){
			self::ROTATION_COUNTERCLOCKWISE_90 => [$z, -$x],
			self::ROTATION_CLOCKWISE_90 => [-$z, $x],
			self::ROTATION_CLOCKWISE_180 => [-$x, -$z],
			default => [$x, $z]
		};
	}

	/**
	 * getZeroPositionWithTransform(zeroPos, Mirror.NONE, rotation): offset of the zero position.
	 * @phpstan-return array{int, int}
	 */
	public function zeroPositionOffset(int $rotation) : array{
		$sx = $this->sizeX - 1;
		$sz = $this->sizeZ - 1;
		return match($rotation){
			self::ROTATION_CLOCKWISE_90 => [$sz, 0],
			self::ROTATION_CLOCKWISE_180 => [$sx, $sz],
			self::ROTATION_COUNTERCLOCKWISE_90 => [0, $sx],
			default => [0, 0]
		};
	}

	/**
	 * getBoundingBox(settings, position): [minX, minY, minZ, maxX, maxY, maxZ]
	 * @phpstan-return array{int, int, int, int, int, int}
	 */
	public function getBoundingBox(int $x, int $y, int $z, int $rotation) : array{
		[$cx, $cz] = self::transform($this->sizeX - 1, $this->sizeZ - 1, $rotation);
		return [$x + min(0, $cx), $y, $z + min(0, $cz), $x + max(0, $cx), $y + $this->sizeY - 1, $z + max(0, $cz)];
	}

	public static function rotateDirection(int $direction, int $rotation) : int{
		if(!isset(self::CLOCKWISE[$direction])){
			return $direction;
		}
		for($i = 0; $i < $rotation; ++$i){
			$direction = self::CLOCKWISE[$direction];
		}
		return $direction;
	}

	/**
	 * BlockState.rotate() for the common rotatable properties.
	 */
	public function rotateState(JavaBlocks $blocks, int $state, int $rotation) : int{
		if($rotation === self::ROTATION_NONE){
			return $state;
		}
		if(isset($this->rotated[$rotation][$state])){
			return $this->rotated[$rotation][$state];
		}
		$result = $state;
		foreach($blocks->propertyNames($state) as $property){
			$value = $blocks->getProperty($state, $property);
			if($value === null){
				continue;
			}
			if($property === "facing" && $value !== "up" && $value !== "down"){
				$result = $blocks->with($result, $property, Direction::NAMES[self::rotateDirection(Direction::fromName($value), $rotation)]);
			}elseif($property === "axis" && ($rotation === self::ROTATION_CLOCKWISE_90 || $rotation === self::ROTATION_COUNTERCLOCKWISE_90) && $value !== "y"){
				$result = $blocks->with($result, $property, $value === "x" ? "z" : "x");
			}elseif($property === "rotation"){
				$result = $blocks->withInt($result, $property, ((int) $value + $rotation * 4) % 16);
			}
		}
		$sides = ["north", "east", "south", "west"];
		if($blocks->hasProperty($state, "north") && $blocks->hasProperty($state, "east") && $blocks->hasProperty($state, "south") && $blocks->hasProperty($state, "west")){
			foreach($sides as $i => $side){
				$value = $blocks->getProperty($state, $side);
				if($value !== null){
					$result = $blocks->with($result, $sides[($i + $rotation) % 4], $value);
				}
			}
		}
		return $this->rotated[$rotation][$state] = $result;
	}
}
