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

use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\leveldb\LevelDB;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;
use function array_key_first;
use function count;
use function file_exists;
use function is_dir;
use function mkdir;
use function rawurlencode;
use function strlen;
use function unlink;

/**
 * The structure templates of a world, like vanilla's StructureManager: templates saved "in memory" only live until the
 * server stops; templates saved "to disk" are written to the world database under the vanilla key
 * "structuretemplate_<name>", so they round-trip with Bedrock Dedicated Server (worlds which are not LevelDB worlds keep
 * them as .mcstructure files in a "structures" folder). Recently loaded templates are cached (the structure block
 * screen queries the template of its name again and again): a few small ones, and the last one too big for them.
 */
final class StructureTemplateStorage{
	public const LEVELDB_KEY_PREFIX = "structuretemplate_";
	private const CACHE_SIZE = 4;
	/** Blocks the cache may hold in all (about 16 bytes of memory each) */
	private const CACHE_MAX_VOLUME = 1 << 20;

	/** @phpstan-var \WeakMap<World, self>|null */
	private static ?\WeakMap $storages = null;

	/**
	 * Templates saved in memory, by name.
	 * @var StructureTemplate[]
	 * @phpstan-var array<string, StructureTemplate>
	 */
	private array $memory = [];
	/**
	 * Recently loaded templates of the disk, least recently used first.
	 * @var StructureTemplate[]
	 * @phpstan-var array<string, StructureTemplate>
	 */
	private array $cache = [];
	/** Name of the last loaded template too big for the cache (reading and encoding the biggest takes about 0.5 s) */
	private ?string $bigName = null;
	private ?StructureTemplate $big = null;

	private function __construct(
		private WritableWorldProvider $provider
	){}

	public static function get(World $world) : self{
		self::$storages ??= new \WeakMap();
		return self::$storages[$world] ??= new self($world->getProvider());
	}

	/**
	 * @internal Storage of a provider without a world (tests, tools).
	 */
	public static function forProvider(WritableWorldProvider $provider) : self{
		return new self($provider);
	}

	/**
	 * Returns the template of the given name (memory first, then disk), or null if there is none.
	 *
	 * @throws StructureTemplateException if the saved data is corrupted
	 */
	public function load(string $name) : ?StructureTemplate{
		if(isset($this->memory[$name])){
			return $this->memory[$name];
		}
		if(isset($this->cache[$name])){
			$template = $this->cache[$name];
			unset($this->cache[$name]);
			return $this->cache[$name] = $template;
		}
		if($this->big !== null && $this->bigName === $name){
			return $this->big;
		}
		$data = $this->readDisk($name);
		if($data === null){
			return null;
		}
		$template = StructureTemplate::fromBinary($data);
		$this->remember($name, $template);
		return $template;
	}

	private function remember(string $name, StructureTemplate $template) : void{
		if($template->getVolume() > self::CACHE_MAX_VOLUME){
			$this->bigName = $name;
			$this->big = $template;
			return;
		}
		$this->cache[$name] = $template;
		$volume = 0;
		foreach($this->cache as $cached){
			$volume += $cached->getVolume();
		}
		while(count($this->cache) > self::CACHE_SIZE || $volume > self::CACHE_MAX_VOLUME){
			$oldest = array_key_first($this->cache);
			if($oldest === null){
				break;
			}
			$volume -= $this->cache[$oldest]->getVolume();
			unset($this->cache[$oldest]);
		}
	}

	/**
	 * Saves a template in memory, or to disk.
	 */
	public function save(string $name, StructureTemplate $template, bool $toDisk) : void{
		$this->forget($name);
		if($toDisk){
			unset($this->memory[$name]);
			$this->writeDisk($name, $template->toBinary());
			$this->remember($name, $template);
		}else{
			$this->memory[$name] = $template;
		}
	}

	/**
	 * Deletes the template of the given name from memory and disk. Returns whether there was one.
	 */
	public function delete(string $name) : bool{
		$existed = isset($this->memory[$name]) || $this->readDisk($name) !== null;
		unset($this->memory[$name]);
		$this->forget($name);
		if($this->provider instanceof LevelDB){
			$this->provider->getDatabase()->delete(self::LEVELDB_KEY_PREFIX . $name);
		}else{
			$path = $this->getFilePath($name);
			if(file_exists($path)){
				@unlink($path);
			}
		}
		return $existed;
	}

	private function forget(string $name) : void{
		unset($this->cache[$name]);
		if($this->bigName === $name){
			$this->bigName = null;
			$this->big = null;
		}
	}

	private function getFilePath(string $name) : string{
		return Path::join($this->provider->getPath(), "structures", rawurlencode($name) . ".mcstructure");
	}

	private function readDisk(string $name) : ?string{
		if($this->provider instanceof LevelDB){
			$data = $this->provider->getDatabase()->get(self::LEVELDB_KEY_PREFIX . $name);
			return $data === false || strlen($data) === 0 ? null : $data;
		}
		$path = $this->getFilePath($name);
		return file_exists($path) ? Filesystem::fileGetContents($path) : null;
	}

	private function writeDisk(string $name, string $data) : void{
		if($this->provider instanceof LevelDB){
			$this->provider->getDatabase()->put(self::LEVELDB_KEY_PREFIX . $name, $data);
			return;
		}
		$path = $this->getFilePath($name);
		$dir = Path::getDirectory($path);
		if(!is_dir($dir)){
			@mkdir($dir, 0777, true);
		}
		Filesystem::safeFilePutContents($path, $data);
	}
}
