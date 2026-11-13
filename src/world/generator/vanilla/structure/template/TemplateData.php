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

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use function count;
use function explode;
use function in_array;
use function pack;
use function unpack;
use function usort;
use function zlib_decode;

/**
 * A structure template (data/minecraft/structure/*.nbt) in a compact form for structure generation: blocks are packed
 * ints (x | y << 10 | z << 20 | palette entry << 30) in vanilla's placement order (StructureTemplate.buildInfoList():
 * full blocks, then other blocks, then blocks with block entity data, each sorted by y, x, z), with the jigsaw blocks,
 * data markers and entities extracted.
 */
final class TemplateData{
	public const COORD_MASK = 0x3ff;

	/**
	 * Packed blocks per palette, as binary strings of 64-bit little-endian ints (8 bytes per block instead of ~40 for
	 * a PHP array: the templates of big structures are hundreds of thousands of blocks).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $palettes = [];
	/**
	 * Java state of each palette entry, per palette.
	 * @var int[][]
	 * @phpstan-var list<list<int>>
	 */
	public array $states = [];
	/**
	 * Block entity data by packed position (x | y << 10 | z << 20).
	 * @var CompoundTag[]
	 * @phpstan-var array<int, CompoundTag>
	 */
	public array $blockNbt = [];
	/**
	 * Jigsaw blocks of the first palette, in placement order.
	 * @var JigsawInfo[]
	 * @phpstan-var list<JigsawInfo>
	 */
	public array $jigsaws = [];
	/**
	 * Structure blocks in data mode (x, y, z, metadata), first palette.
	 * @phpstan-var list<array{int, int, int, string}>
	 */
	public array $dataMarkers = [];
	/**
	 * Entities: [x, y, z (exact position), block x, y, z, Java entity data].
	 * @phpstan-var list<array{float, float, float, int, int, int, CompoundTag}>
	 */
	public array $entities = [];
	public int $blockCount = 0;

	public function __construct(
		public readonly string $id,
		public readonly int $sizeX,
		public readonly int $sizeY,
		public readonly int $sizeZ
	){}

	public static function pack(int $x, int $y, int $z, int $entry) : int{
		return $x | ($y << 10) | ($z << 20) | ($entry << 30);
	}

	/**
	 * The packed blocks of a palette (see pack()), in placement order.
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public function blocks(int $palette) : array{
		$data = $this->palettes[$palette] ?? "";
		if($data === ""){
			return [];
		}
		$blocks = unpack("P*", $data);
		return $blocks === false ? [] : $blocks;
	}

	public static function load(JavaBlocks $blocks, string $id, string $path) : self{
		$raw = zlib_decode(Filesystem::fileGetContents($path));
		if($raw === false){
			throw new \RuntimeException("Corrupted structure template $path");
		}
		$reader = new TemplateReader($raw);
		$reader->read();
		unset($raw);
		$size = $reader->size;
		if(count($size) !== 3){
			throw new \RuntimeException("Invalid structure template size in $path");
		}
		$template = new self($id, $size[0], $size[1], $size[2]);

		$paletteLists = $reader->palettes;
		$jigsawBlock = $blocks->hasBlock("jigsaw") ? $blocks->blockIndex("jigsaw") : -1;
		$structureBlock = $blocks->hasBlock("structure_block") ? $blocks->blockIndex("structure_block") : -1;
		$rawBlocks = $reader->blocks;
		foreach($paletteLists as $paletteIndex => $paletteTag){
			$states = [];
			foreach($paletteTag as $entry){
				$states[] = match(true){
					$entry instanceof CompoundTag => self::readState($blocks, $entry),
					$entry instanceof StringTag => self::readStateString($blocks, $entry->getValue()),
					default => $blocks->air
				};
			}
			$template->states[] = $states;
			$full = [];
			$other = [];
			$withNbt = [];
			foreach($rawBlocks as $i => [$x, $y, $z, $entry, $blockNbt]){
				if(!isset($states[$entry])){
					continue;
				}
				$state = $states[$entry];
				$info = [$x, $y, $z, $entry, $i];
				if($blockNbt !== null){
					$withNbt[] = $info;
					if($paletteIndex === 0){
						$key = self::pack($x, $y, $z, 0);
						$template->blockNbt[$key] = $blockNbt;
					}
				}elseif($blocks->isFullCube($state)){
					$full[] = $info;
				}else{
					$other[] = $info;
				}
			}
			$packed = [];
			foreach([$full, $other, $withNbt] as $group){
				usort($group, fn(array $a, array $b) : int => [$a[1], $a[0], $a[2], $a[4]] <=> [$b[1], $b[0], $b[2], $b[4]]);
				foreach($group as [$x, $y, $z, $entry]){
					$packed[] = self::pack($x, $y, $z, $entry);
					if($paletteIndex !== 0){
						continue;
					}
					$block = $blocks->blockOf[$states[$entry]];
					if($block === $jigsawBlock){
						$info = self::readJigsaw($blocks, $x, $y, $z, $states[$entry], $template->blockNbt[self::pack($x, $y, $z, 0)] ?? null);
						if($info !== null){
							$template->jigsaws[] = $info;
							//the jigsaw replacement only needs the final state
							$template->blockNbt[self::pack($x, $y, $z, 0)] = CompoundTag::create()->setString("final_state", $info->finalState);
						}
					}elseif($block === $structureBlock){
						$data = $template->blockNbt[self::pack($x, $y, $z, 0)] ?? null;
						if($data !== null && $data->getString("mode", "") === "DATA"){
							$template->dataMarkers[] = [$x, $y, $z, $data->getString("metadata", "")];
						}
					}
				}
			}
			$template->palettes[] = count($packed) > 0 ? pack("P*", ...$packed) : "";
			$template->blockCount += count($packed);
		}

		foreach($reader->entities as $entityTag){
			$pos = [];
			foreach($entityTag->getListTag("pos") ?? [] as $value){
				if($value instanceof DoubleTag){
					$pos[] = $value->getValue();
				}
			}
			$blockPos = self::intList($entityTag->getListTag("blockPos"));
			$data = $entityTag->getCompoundTag("nbt");
			if(count($pos) === 3 && count($blockPos) === 3 && $data !== null){
				$template->entities[] = [$pos[0], $pos[1], $pos[2], $blockPos[0], $blockPos[1], $blockPos[2], $data];
			}
		}
		return $template;
	}

	private static function readJigsaw(JavaBlocks $blocks, int $x, int $y, int $z, int $state, ?CompoundTag $nbt) : ?JigsawInfo{
		if($nbt === null){
			return null;
		}
		$orientation = $blocks->getProperty($state, "orientation") ?? "north_up";
		[$front, $top] = explode("_", $orientation, 2) + [1 => "up"];
		$frontDirection = Direction::fromName($front);
		$joint = $nbt->getString("joint", "");
		return new JigsawInfo(
			$x, $y, $z,
			$frontDirection,
			Direction::fromName($top),
			JavaBlocks::namespaced($nbt->getString("name", "minecraft:empty")),
			JavaBlocks::namespaced($nbt->getString("target", "minecraft:empty")),
			JavaBlocks::namespaced($nbt->getString("pool", "minecraft:empty")),
			$nbt->getString("final_state", "minecraft:air"),
			$joint === "" ? Direction::AXIS[$frontDirection] === 1 : $joint === "rollable",
			$nbt->getInt("placement_priority", 0),
			$nbt->getInt("selection_priority", 0)
		);
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
}
