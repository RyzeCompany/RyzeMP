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

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function basename;
use function file_exists;
use function glob;
use function is_array;
use function is_dir;
use function is_int;
use function json_decode;
use function json_encode;
use function max;
use function mkdir;
use function preg_match;
use function zlib_decode;
use function zlib_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const ZLIB_ENCODING_GZIP;

/**
 * Saves map data in a folder: one gzip-compressed NBT file per map (map_<id>.dat, vanilla map data tags) and the next
 * free map ID in idcounts.json. Vanilla Bedrock keeps maps in the world LevelDB as map_<id> keys; PocketMine keeps one
 * folder for the whole server (<data>/maps), like PositionTrackingDB, so a map item keeps working in every world.
 */
final class MapDataStore{
	private const ID_COUNTS_FILE = "idcounts.json";
	private const KEY_NEXT_ID = "nextMapId";

	/** First map ID that has never been assigned. 0 is never used: the client treats it as "no map". */
	private ?int $nextId = null;

	public function __construct(
		private string $path
	){}

	public function getPath() : string{ return $this->path; }

	private function ensureDirectory() : void{
		if(!is_dir($this->path)){
			ErrorToExceptionHandler::trapAndRemoveFalse(fn() => mkdir($this->path, 0777, true));
		}
	}

	private function loadNextId() : int{
		if($this->nextId !== null){
			return $this->nextId;
		}
		$file = Path::join($this->path, self::ID_COUNTS_FILE);
		if(file_exists($file)){
			try{
				$data = json_decode(Filesystem::fileGetContents($file), true, 2, JSON_THROW_ON_ERROR);
				if(is_array($data) && is_int($data[self::KEY_NEXT_ID] ?? null) && $data[self::KEY_NEXT_ID] > 0){
					return $this->nextId = $data[self::KEY_NEXT_ID];
				}
			}catch(\JsonException $e){
				\GlobalLogger::get()->error("Corrupted map ID file $file: " . $e->getMessage());
			}
		}
		//no usable counter: continue after the highest saved map so that no map gets overwritten
		$nextId = 1;
		$mapFiles = glob(Path::join($this->path, "map_*.dat"));
		foreach($mapFiles !== false ? $mapFiles : [] as $mapFile){
			if(preg_match('/^map_(\d+)\.dat$/', basename($mapFile), $matches) === 1){
				$nextId = max($nextId, ((int) $matches[1]) + 1);
			}
		}
		return $this->nextId = $nextId;
	}

	/**
	 * Returns a new map ID and saves the counter immediately, so that IDs are never handed out twice even if the
	 * server crashes.
	 */
	public function allocateId() : int{
		$id = $this->loadNextId();
		$this->nextId = $id + 1;
		$this->ensureDirectory();
		Filesystem::safeFilePutContents(Path::join($this->path, self::ID_COUNTS_FILE), json_encode([self::KEY_NEXT_ID => $this->nextId], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
		return $id;
	}

	/**
	 * Returns whether the ID was handed out by allocateId() at some point.
	 */
	public function wasAllocated(int $id) : bool{
		return $id > 0 && $id < $this->loadNextId();
	}

	private function getMapFile(int $id) : string{
		return Path::join($this->path, "map_$id.dat");
	}

	/**
	 * Loads the data of a map, or returns null if there is none (or it is corrupted).
	 */
	public function load(int $id) : ?MapData{
		$file = $this->getMapFile($id);
		if(!file_exists($file)){
			return null;
		}
		try{
			$contents = Filesystem::fileGetContents($file);
			$decompressed = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => zlib_decode($contents));
			$data = MapData::fromNbt((new BigEndianNbtSerializer())->read($decompressed)->mustGetCompoundTag());
		}catch(\ErrorException | NbtDataException | \InvalidArgumentException | \RuntimeException $e){
			\GlobalLogger::get()->error("Failed to load map data $file: " . $e->getMessage());
			return null;
		}
		if($data->getId() !== $id){
			\GlobalLogger::get()->error("Map data $file has the wrong map ID " . $data->getId());
			return null;
		}
		return $data;
	}

	public function save(MapData $data) : void{
		$this->ensureDirectory();
		$contents = Utils::assumeNotFalse(zlib_encode((new BigEndianNbtSerializer())->write(new TreeRoot($data->toNbt())), ZLIB_ENCODING_GZIP), "zlib_encode() failed unexpectedly");
		Filesystem::safeFilePutContents($this->getMapFile($data->getId()), $contents);
		$data->setChanged(false);
	}
}
