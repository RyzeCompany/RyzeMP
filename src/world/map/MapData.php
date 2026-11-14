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

namespace pocketmine\world\map;

use pocketmine\nbt\NBT;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use function chr;
use function floor;
use function intdiv;
use function max;
use function min;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

/**
 * The pixels and settings of one map. Pixels are kept as 4 bytes per pixel (R, G, B, A), row by row, like the
 * "colors" array of vanilla Bedrock map data and like the pixels of ClientboundMapItemDataPacket.
 *
 * Saved with the vanilla Bedrock map data tags (mapId, parentMapId, dimension, fullyExplored, mapLocked, scale,
 * unlimitedTracking, height, width, xCenter, zCenter, colors, decorations; Minecraft Wiki "Map item format"), plus the
 * name of the PocketMine world it shows.
 *
 * Decorations are fixed markers at a world position, such as the structure of an explorer map; player markers are not
 * kept here (see {@link MapManager}).
 */
final class MapData{
	public const SIZE = 128;
	public const MAX_SCALE = 4;

	private const TAG_MAP_ID = "mapId"; //TAG_Long
	private const TAG_PARENT_MAP_ID = "parentMapId"; //TAG_Long
	private const TAG_DIMENSION = "dimension"; //TAG_Byte
	private const TAG_FULLY_EXPLORED = "fullyExplored"; //TAG_Byte
	private const TAG_LOCKED = "mapLocked"; //TAG_Byte
	private const TAG_SCALE = "scale"; //TAG_Byte
	private const TAG_UNLIMITED_TRACKING = "unlimitedTracking"; //TAG_Byte
	private const TAG_HEIGHT = "height"; //TAG_Short
	private const TAG_WIDTH = "width"; //TAG_Short
	private const TAG_X_CENTER = "xCenter"; //TAG_Int
	private const TAG_Z_CENTER = "zCenter"; //TAG_Int
	private const TAG_COLORS = "colors"; //TAG_ByteArray
	private const TAG_DECORATIONS = "decorations"; //TAG_List<TAG_Compound> {data: {type, rot, x, y}, key: {type, blockX, blockY, blockZ}}
	private const TAG_WORLD = "pmWorld"; //TAG_String, PocketMine extension

	private string $pixels;

	/**
	 * Fixed markers: [MapDecorationType, block x, block z]
	 * @var int[][]
	 * @phpstan-var list<array{int, int, int}>
	 */
	private array $decorations = [];

	/** Bounds of the pixels changed since the last call to takeDirtyArea(), or null if none */
	private ?int $dirtyMinX = null;
	private int $dirtyMinY = 0;
	private int $dirtyMaxX = 0;
	private int $dirtyMaxY = 0;

	/** Whether the data changed since it was last saved */
	private bool $changed = true;

	public function __construct(
		private int $id,
		private string $worldName,
		private int $centerX,
		private int $centerZ,
		private int $scale = 0,
		private bool $locked = false,
		private int $parentMapId = -1,
		?string $pixels = null,
		private int $dimension = DimensionIds::OVERWORLD
	){
		if($scale < 0 || $scale > self::MAX_SCALE){
			throw new \InvalidArgumentException("Map scale must be between 0 and " . self::MAX_SCALE . ", got $scale");
		}
		if($pixels !== null && strlen($pixels) !== self::SIZE * self::SIZE * 4){
			throw new \InvalidArgumentException("Expected " . (self::SIZE * self::SIZE * 4) . " bytes of pixels, got " . strlen($pixels));
		}
		$this->pixels = $pixels ?? str_repeat("\x00", self::SIZE * self::SIZE * 4);
	}

	/**
	 * Returns the map centre for a new map made at the given position. Scale 0 maps are centred on the player (as in
	 * Bedrock); bigger maps snap to the vanilla grid, where (0, 0) is in the top-left square of the map at that scale.
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public static function alignCenter(int $x, int $z, int $scale) : array{
		if($scale === 0){
			return [$x, $z];
		}
		$size = self::SIZE << $scale;
		return [
			(int) (floor(($x + 64) / $size) * $size + intdiv($size, 2) - 64),
			(int) (floor(($z + 64) / $size) * $size + intdiv($size, 2) - 64)
		];
	}

	public function getId() : int{ return $this->id; }

	public function getWorldName() : string{ return $this->worldName; }

	public function getCenterX() : int{ return $this->centerX; }

	public function getCenterZ() : int{ return $this->centerZ; }

	public function getScale() : int{ return $this->scale; }

	/** Returns the ID of the map this one was made from (zooming out or locking), or -1 */
	public function getParentMapId() : int{ return $this->parentMapId; }

	/**
	 * Network ID of the dimension the map was made in ({@link DimensionIds}): the client only shows the player marker
	 * when the holder is in that dimension.
	 */
	public function getDimension() : int{ return $this->dimension; }

	public function isLocked() : bool{ return $this->locked; }

	public function setLocked(bool $locked) : void{
		$this->locked = $locked;
		$this->changed = true;
	}

	/**
	 * Returns all pixels: 4 bytes per pixel (R, G, B, A), row by row.
	 */
	public function getPixels() : string{ return $this->pixels; }

	/**
	 * Returns the colour (0xRRGGBB) of a pixel, or -1 if it was never drawn (transparent).
	 */
	public function getPixel(int $x, int $y) : int{
		$offset = ($y * self::SIZE + $x) * 4;
		if(ord($this->pixels[$offset + 3]) === 0){
			return -1;
		}
		return (ord($this->pixels[$offset]) << 16) | (ord($this->pixels[$offset + 1]) << 8) | ord($this->pixels[$offset + 2]);
	}

	/**
	 * Sets an opaque pixel to the given 0xRRGGBB colour. Returns whether it changed.
	 */
	public function setPixel(int $x, int $y, int $color) : bool{
		$offset = ($y * self::SIZE + $x) * 4;
		$r = chr(($color >> 16) & 0xff);
		$g = chr(($color >> 8) & 0xff);
		$b = chr($color & 0xff);
		$pixels = &$this->pixels;
		if($pixels[$offset] === $r && $pixels[$offset + 1] === $g && $pixels[$offset + 2] === $b && $pixels[$offset + 3] === "\xff"){
			return false;
		}
		$pixels[$offset] = $r;
		$pixels[$offset + 1] = $g;
		$pixels[$offset + 2] = $b;
		$pixels[$offset + 3] = "\xff";

		if($this->dirtyMinX === null){
			$this->dirtyMinX = $this->dirtyMaxX = $x;
			$this->dirtyMinY = $this->dirtyMaxY = $y;
		}else{
			$this->dirtyMinX = min($this->dirtyMinX, $x);
			$this->dirtyMaxX = max($this->dirtyMaxX, $x);
			$this->dirtyMinY = min($this->dirtyMinY, $y);
			$this->dirtyMaxY = max($this->dirtyMaxY, $y);
		}
		$this->changed = true;
		return true;
	}

	/**
	 * Returns the area changed since the last call as [x, y, width, height] and forgets it, or null if nothing
	 * changed.
	 *
	 * @return int[]|null
	 * @phpstan-return array{int, int, int, int}|null
	 */
	public function takeDirtyArea() : ?array{
		if($this->dirtyMinX === null){
			return null;
		}
		$result = [$this->dirtyMinX, $this->dirtyMinY, $this->dirtyMaxX - $this->dirtyMinX + 1, $this->dirtyMaxY - $this->dirtyMinY + 1];
		$this->dirtyMinX = null;
		return $result;
	}

	/**
	 * Returns the pixels of an area: 4 bytes per pixel (R, G, B, A), row by row.
	 */
	public function getPixelArea(int $x, int $y, int $width, int $height) : string{
		if($x === 0 && $y === 0 && $width === self::SIZE && $height === self::SIZE){
			return $this->pixels;
		}
		$result = "";
		for($row = $y; $row < $y + $height; ++$row){
			$result .= substr($this->pixels, ($row * self::SIZE + $x) * 4, $width * 4);
		}
		return $result;
	}

	/**
	 * Adds a fixed marker ({@link \pocketmine\network\mcpe\protocol\types\MapDecorationType}) at a world position.
	 */
	public function addDecoration(int $type, int $x, int $z) : void{
		$this->decorations[] = [$type, $x, $z];
		$this->changed = true;
	}

	/**
	 * Returns the fixed markers as [MapDecorationType, block x, block z].
	 *
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int}>
	 */
	public function getDecorations() : array{
		return $this->decorations;
	}

	public function hasChanged() : bool{ return $this->changed; }

	public function setChanged(bool $changed) : void{ $this->changed = $changed; }

	public function toNbt() : CompoundTag{
		$decorations = new ListTag([], NBT::TAG_Compound);
		foreach($this->decorations as [$type, $x, $z]){
			$decorations->push(CompoundTag::create()
				->setTag("data", CompoundTag::create()->setInt("type", $type)->setInt("rot", 0))
				->setTag("key", CompoundTag::create()->setInt("type", 1)->setInt("blockX", $x)->setInt("blockY", 0)->setInt("blockZ", $z)));
		}
		return CompoundTag::create()
			->setLong(self::TAG_MAP_ID, $this->id)
			->setLong(self::TAG_PARENT_MAP_ID, $this->parentMapId)
			->setByte(self::TAG_DIMENSION, $this->dimension)
			->setByte(self::TAG_FULLY_EXPLORED, 0)
			->setByte(self::TAG_LOCKED, $this->locked ? 1 : 0)
			->setByte(self::TAG_SCALE, $this->scale)
			->setByte(self::TAG_UNLIMITED_TRACKING, 0)
			->setShort(self::TAG_HEIGHT, self::SIZE)
			->setShort(self::TAG_WIDTH, self::SIZE)
			->setInt(self::TAG_X_CENTER, $this->centerX)
			->setInt(self::TAG_Z_CENTER, $this->centerZ)
			->setByteArray(self::TAG_COLORS, $this->pixels)
			->setTag(self::TAG_DECORATIONS, $decorations)
			->setString(self::TAG_WORLD, $this->worldName);
	}

	/**
	 * @throws NbtDataException
	 * @throws \InvalidArgumentException
	 */
	public static function fromNbt(CompoundTag $tag) : self{
		$pixels = $tag->getByteArray(self::TAG_COLORS);
		$result = new self(
			$tag->getLong(self::TAG_MAP_ID),
			$tag->getString(self::TAG_WORLD, ""),
			$tag->getInt(self::TAG_X_CENTER),
			$tag->getInt(self::TAG_Z_CENTER),
			$tag->getByte(self::TAG_SCALE, 0),
			$tag->getByte(self::TAG_LOCKED, 0) !== 0,
			$tag->getLong(self::TAG_PARENT_MAP_ID, -1),
			$pixels,
			match($tag->getByte(self::TAG_DIMENSION, DimensionIds::OVERWORLD)){
				DimensionIds::NETHER => DimensionIds::NETHER,
				DimensionIds::THE_END => DimensionIds::THE_END,
				default => DimensionIds::OVERWORLD
			}
		);
		foreach($tag->getListTag(self::TAG_DECORATIONS) ?? [] as $decoration){
			$data = $decoration instanceof CompoundTag ? $decoration->getCompoundTag("data") : null;
			$key = $decoration instanceof CompoundTag ? $decoration->getCompoundTag("key") : null;
			if($data !== null && $key !== null){
				$result->decorations[] = [$data->getInt("type", 0), $key->getInt("blockX", 0), $key->getInt("blockZ", 0)];
			}
		}
		$result->changed = false;
		return $result;
	}
}
