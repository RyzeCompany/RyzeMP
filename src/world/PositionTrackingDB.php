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

namespace pocketmine\world;

use pocketmine\Server;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use function file_exists;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Server-wide equivalent of the vanilla position tracking database: maps the tracking handles stored in lodestones and
 * lodestone compasses ("trackingHandle") to the lodestone position. Vanilla keeps this per level in LevelDB; PocketMine
 * keeps one table for all worlds in position_tracking.json in the server data folder, so a lodestone compass keeps
 * working across worlds, but handles are not carried over when a world is copied to another server.
 */
final class PositionTrackingDB{
	use SingletonTrait;

	private const FILE_NAME = "position_tracking.json";

	private string $path;
	private bool $loaded = false;
	/** First handle that has never been assigned. Handle 0 means "not tracking" for the client. */
	private int $nextHandle = 1;
	/**
	 * @var int[][]|string[][]
	 * @phpstan-var array<int, array{world: string, x: int, y: int, z: int}>
	 */
	private array $entries = [];

	public function __construct(?string $path = null){
		$this->path = $path ?? Server::getInstance()->getDataPath() . self::FILE_NAME;
	}

	private function load() : void{
		if($this->loaded){
			return;
		}
		$this->loaded = true;
		if(!file_exists($this->path)){
			return;
		}
		try{
			$data = json_decode(Filesystem::fileGetContents($this->path), true, 4, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			\GlobalLogger::get()->error("Ignoring corrupted position tracking file " . $this->path . ": " . $e->getMessage());
			return;
		}
		if(!is_array($data)){
			return;
		}
		if(isset($data["nextHandle"]) && is_int($data["nextHandle"])){
			$this->nextHandle = $data["nextHandle"];
		}
		$entries = $data["entries"] ?? null;
		if(!is_array($entries)){
			return;
		}
		foreach(Utils::promoteKeys($entries) as $handle => $entry){
			$handle = (int) $handle;
			if(
				$handle > 0 && is_array($entry) && isset($entry["world"], $entry["x"], $entry["y"], $entry["z"]) &&
				is_string($entry["world"]) && is_int($entry["x"]) && is_int($entry["y"]) && is_int($entry["z"])
			){
				$this->entries[$handle] = ["world" => $entry["world"], "x" => $entry["x"], "y" => $entry["y"], "z" => $entry["z"]];
				if($this->nextHandle <= $handle){
					$this->nextHandle = $handle + 1;
				}
			}
		}
	}

	private function save() : void{
		Filesystem::safeFilePutContents($this->path, json_encode([
			"nextHandle" => $this->nextHandle,
			"entries" => (object) $this->entries
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}

	/**
	 * Registers the given block position (world folder name and coordinates) and returns its new tracking handle.
	 */
	public function register(string $worldFolderName, int $x, int $y, int $z) : int{
		$this->load();
		$handle = $this->nextHandle++;
		$this->entries[$handle] = ["world" => $worldFolderName, "x" => $x, "y" => $y, "z" => $z];
		$this->save();
		return $handle;
	}

	/**
	 * Returns the position registered for the handle, or null if it is unknown or was removed.
	 *
	 * @phpstan-return array{world: string, x: int, y: int, z: int}|null
	 */
	public function get(int $handle) : ?array{
		$this->load();
		return $this->entries[$handle] ?? null;
	}

	/**
	 * Returns whether the handle was assigned at some point (it may have been removed since).
	 */
	public function wasAssigned(int $handle) : bool{
		$this->load();
		return $handle > 0 && $handle < $this->nextHandle;
	}

	public function remove(int $handle) : void{
		$this->load();
		if(isset($this->entries[$handle])){
			unset($this->entries[$handle]);
			$this->save();
		}
	}
}
