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

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use function is_float;
use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * A lean reader for structure template files (big-endian NBT): the "blocks" list, tens of thousands of compounds in
 * big templates, is read straight into ints without building tag objects (a full NBT tree of a bastion template takes
 * about 50 MB, more than a generator worker can spare); everything else becomes ordinary tags.
 */
final class TemplateReader{
	private int $offset = 0;

	/** @var int[] */
	public array $size = [];
	/**
	 * Palettes: entries are CompoundTag ({"Name", "Properties"} or {"id", "properties"}) or StringTag.
	 * @var Tag[][]
	 * @phpstan-var list<list<Tag>>
	 */
	public array $palettes = [];
	/** @phpstan-var list<array{int, int, int, int, ?CompoundTag}> [x, y, z, palette entry, block entity data] */
	public array $blocks = [];
	/**
	 * @var CompoundTag[]
	 * @phpstan-var list<CompoundTag>
	 */
	public array $entities = [];

	public function __construct(
		private string $buffer
	){}

	private function need(int $bytes) : void{
		if($this->offset + $bytes > strlen($this->buffer)){
			throw new \RuntimeException("Unexpected end of structure template data");
		}
	}

	private function byte() : int{
		$this->need(1);
		$v = ord($this->buffer[$this->offset++]);
		return $v >= 0x80 ? $v - 0x100 : $v;
	}

	private function unsignedShort() : int{
		$this->need(2);
		$v = (ord($this->buffer[$this->offset]) << 8) | ord($this->buffer[$this->offset + 1]);
		$this->offset += 2;
		return $v;
	}

	private function int() : int{
		$this->need(4);
		$v = (ord($this->buffer[$this->offset]) << 24) | (ord($this->buffer[$this->offset + 1]) << 16) | (ord($this->buffer[$this->offset + 2]) << 8) | ord($this->buffer[$this->offset + 3]);
		$this->offset += 4;
		return $v >= 0x80000000 ? $v - 0x100000000 : $v;
	}

	/**
	 * @phpstan-return array<int|string, mixed>
	 */
	private function unpack(string $format, int $bytes) : array{
		$this->need($bytes);
		$values = unpack($format, $this->buffer, $this->offset);
		if($values === false){
			throw new \RuntimeException("Corrupted structure template data");
		}
		$this->offset += $bytes;
		return $values;
	}

	private function string() : string{
		$length = $this->unsignedShort();
		$this->need($length);
		$s = substr($this->buffer, $this->offset, $length);
		$this->offset += $length;
		return $s;
	}

	private function tag(int $type) : Tag{
		switch($type){
			case NBT::TAG_Byte:
				return new ByteTag($this->byte());
			case NBT::TAG_Short:
				$v = $this->unsignedShort();
				return new ShortTag($v >= 0x8000 ? $v - 0x10000 : $v);
			case NBT::TAG_Int:
				return new IntTag($this->int());
			case NBT::TAG_Long:
				$hi = $this->int();
				$lo = $this->int();
				return new LongTag(($hi << 32) | ($lo & 0xffffffff));
			case NBT::TAG_Float:
				$v = $this->unpack("G", 4)[1];
				return new FloatTag(is_float($v) ? $v : 0.0);
			case NBT::TAG_Double:
				$v = $this->unpack("E", 8)[1];
				return new DoubleTag(is_float($v) ? $v : 0.0);
			case NBT::TAG_ByteArray:
				$length = $this->int();
				$this->need($length);
				$s = substr($this->buffer, $this->offset, $length);
				$this->offset += $length;
				return new ByteArrayTag($s);
			case NBT::TAG_String:
				return new StringTag($this->string());
			case NBT::TAG_List:
				$elementType = $this->byte();
				$count = $this->int();
				$values = [];
				for($i = 0; $i < $count; ++$i){
					$values[] = $this->tag($elementType);
				}
				return new ListTag($values, $count > 0 ? $elementType : NBT::TAG_End);
			case NBT::TAG_Compound:
				$compound = CompoundTag::create();
				while(($childType = $this->byte()) !== NBT::TAG_End){
					$name = $this->string();
					$compound->setTag($name, $this->tag($childType));
				}
				return $compound;
			case NBT::TAG_IntArray:
				$count = $this->int();
				$values = [];
				for($i = 0; $i < $count; ++$i){
					$values[] = $this->int();
				}
				return new IntArrayTag($values);
			case 12: //long array: not used by templates, skipped
				$count = $this->int();
				$this->need($count * 8);
				$this->offset += $count * 8;
				return new IntArrayTag([]);
			default:
				throw new \RuntimeException("Unknown NBT tag type $type in structure template");
		}
	}

	/**
	 * One element of the "blocks" list, read into [x, y, z, state, nbt].
	 * @phpstan-return array{int, int, int, int, ?CompoundTag}
	 */
	private function block() : array{
		$pos = [0, 0, 0];
		$state = 0;
		$nbt = null;
		while(($type = $this->byte()) !== NBT::TAG_End){
			$name = $this->string();
			if($name === "pos" && $type === NBT::TAG_List){
				$elementType = $this->byte();
				$count = $this->int();
				if($elementType === NBT::TAG_Int && $count === 3){
					$pos = [$this->int(), $this->int(), $this->int()];
				}else{
					for($i = 0; $i < $count; ++$i){
						$this->tag($elementType);
					}
				}
			}elseif($name === "state" && $type === NBT::TAG_Int){
				$state = $this->int();
			}else{
				$tag = $this->tag($type);
				if($name === "nbt" && $tag instanceof CompoundTag){
					$nbt = $tag;
				}
			}
		}
		return [$pos[0], $pos[1], $pos[2], $state, $nbt];
	}

	public function read() : void{
		if($this->byte() !== NBT::TAG_Compound){
			throw new \RuntimeException("Structure template root is not a compound");
		}
		$this->string();
		while(($type = $this->byte()) !== NBT::TAG_End){
			$name = $this->string();
			if($name === "blocks" && $type === NBT::TAG_List){
				$elementType = $this->byte();
				$count = $this->int();
				for($i = 0; $i < $count; ++$i){
					if($elementType === NBT::TAG_Compound){
						$this->blocks[] = $this->block();
					}else{
						$this->tag($elementType);
					}
				}
				continue;
			}
			$tag = $this->tag($type);
			if($name === "size" && $tag instanceof ListTag){
				foreach($tag as $value){
					if($value instanceof IntTag){
						$this->size[] = $value->getValue();
					}
				}
			}elseif($name === "palette" && $tag instanceof ListTag){
				$this->palettes = [self::listValues($tag)];
			}elseif($name === "palettes" && $tag instanceof ListTag){
				$this->palettes = [];
				foreach($tag as $palette){
					if($palette instanceof ListTag){
						$this->palettes[] = self::listValues($palette);
					}
				}
			}elseif($name === "entities" && $tag instanceof ListTag){
				foreach($tag as $entity){
					if($entity instanceof CompoundTag){
						$this->entities[] = $entity;
					}
				}
			}
		}
	}

	/**
	 * @return Tag[]
	 * @phpstan-return list<Tag>
	 */
	private static function listValues(ListTag $list) : array{
		$values = [];
		foreach($list as $value){
			$values[] = $value;
		}
		return $values;
	}
}
