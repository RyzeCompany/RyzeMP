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

namespace pocketmine\world\raid;

use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function file_exists;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * Saved raids of every world, in raids.json in the server data folder (like dragon_fights.json): world folder name =>
 * {nextId, raids: [{@link Raid::toArray()}...]}.
 */
final class RaidStore{
	public const FILE_NAME = "raids.json";

	/**
	 * @var RaidStore[]
	 * @phpstan-var array<string, RaidStore>
	 */
	private static array $byDataPath = [];

	private ?string $path;
	private bool $loaded = false;
	/**
	 * @var mixed[][]
	 * @phpstan-var array<string, array{nextId: int, raids: list<array<string, mixed>>}>
	 */
	private array $worlds = [];

	/**
	 * @param string|null $path JSON file the raids are saved to, or null to keep them in memory only
	 */
	public function __construct(?string $path){
		$this->path = $path;
	}

	/**
	 * Returns the store shared by the worlds of the server with this data folder ("" keeps raids in memory only).
	 */
	public static function forDataPath(string $dataPath) : self{
		return self::$byDataPath[$dataPath] ??= new self($dataPath !== "" ? $dataPath . self::FILE_NAME : null);
	}

	private function load() : void{
		if($this->loaded){
			return;
		}
		$this->loaded = true;
		if($this->path === null || !file_exists($this->path)){
			return;
		}
		try{
			$data = json_decode(Filesystem::fileGetContents($this->path), true, 16, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			\GlobalLogger::get()->error("Ignoring corrupted raid file " . $this->path . ": " . $e->getMessage());
			return;
		}
		if(!is_array($data)){
			return;
		}
		foreach(Utils::promoteKeys($data) as $world => $entry){
			if(!is_array($entry)){
				continue;
			}
			$raids = [];
			foreach((is_array($entry["raids"] ?? null) ? $entry["raids"] : []) as $raid){
				if(is_array($raid)){
					/** @phpstan-var array<string, mixed> $raid */
					$raids[] = $raid;
				}
			}
			$this->worlds[(string) $world] = [
				"nextId" => is_int($entry["nextId"] ?? null) ? $entry["nextId"] : 1,
				"raids" => $raids
			];
		}
	}

	/**
	 * @phpstan-return array{nextId: int, raids: list<array<string, mixed>>}
	 */
	public function get(string $worldName) : array{
		$this->load();
		return $this->worlds[$worldName] ?? ["nextId" => 1, "raids" => []];
	}

	/**
	 * @phpstan-param list<array<string, mixed>> $raids
	 */
	public function set(string $worldName, int $nextId, array $raids) : void{
		$this->load();
		if($raids === [] && !isset($this->worlds[$worldName]) && $nextId <= 1){
			return;
		}
		$this->worlds[$worldName] = ["nextId" => $nextId, "raids" => $raids];
		if($this->path !== null){
			Filesystem::safeFilePutContents($this->path, json_encode((object) $this->worlds, JSON_THROW_ON_ERROR));
		}
	}
}
