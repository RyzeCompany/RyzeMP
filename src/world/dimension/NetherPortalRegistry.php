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

namespace pocketmine\world\dimension;

use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\utils\Filesystem;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function array_values;
use function count;
use function file_exists;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function max;
use const JSON_THROW_ON_ERROR;

/**
 * Known lit Nether portals of each world, so travelling entities find the portal linked to the one they used without
 * scanning hundreds of chunks. This is what vanilla keeps in the "portals" record of the world's LevelDB; PocketMine
 * keeps one table for all worlds in nether_portals.json in the server data folder. Portals lit before this existed
 * are added the first time an entity travels through them.
 */
final class NetherPortalRegistry{
	use SingletonTrait;

	private const FILE_NAME = "nether_portals.json";

	private string $path;
	private bool $loaded = false;
	/**
	 * @var int[][][] world folder name => list of [x, y, z, axis, width, height]
	 * @phpstan-var array<string, list<array{int, int, int, int, int, int}>>
	 */
	private array $portals = [];

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
			$data = json_decode(Filesystem::fileGetContents($this->path), true, 5, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			\GlobalLogger::get()->error("Ignoring corrupted Nether portal file " . $this->path . ": " . $e->getMessage());
			return;
		}
		if(!is_array($data)){
			return;
		}
		foreach(Utils::promoteKeys($data) as $world => $list){
			if(!is_array($list)){
				continue;
			}
			foreach($list as $entry){
				if(!is_array($entry) || count($entry) !== 6){
					continue;
				}
				[$x, $y, $z, $axis, $width, $height] = array_values($entry);
				if(is_int($x) && is_int($y) && is_int($z) && ($axis === Axis::X || $axis === Axis::Z) && is_int($width) && is_int($height)){
					$this->portals[(string) $world][] = [$x, $y, $z, $axis, $width, $height];
				}
			}
		}
	}

	private function save() : void{
		Filesystem::safeFilePutContents($this->path, json_encode((object) $this->portals, JSON_THROW_ON_ERROR));
	}

	/**
	 * Records a lit portal. A portal already recorded at the same origin is replaced.
	 */
	public function add(World $world, NetherPortalShape $shape) : void{
		$this->load();
		$name = $world->getFolderName();
		$origin = $shape->origin;
		$entry = [$origin->getFloorX(), $origin->getFloorY(), $origin->getFloorZ(), $shape->axis, $shape->width, $shape->height];
		foreach($this->portals[$name] ?? [] as $i => $existing){
			if($existing[0] === $entry[0] && $existing[1] === $entry[1] && $existing[2] === $entry[2]){
				if($existing === $entry){
					return;
				}
				$this->portals[$name][$i] = $entry;
				$this->save();
				return;
			}
		}
		$this->portals[$name][] = $entry;
		$this->save();
	}

	/**
	 * Returns the recorded portal closest to the given position whose origin is within the given horizontal distance
	 * (per axis, like vanilla's square search area). Records whose portal no longer exists are dropped.
	 */
	public function findNearest(World $world, Vector3 $pos, int $radius) : ?NetherPortalShape{
		$this->load();
		$name = $world->getFolderName();
		$best = null;
		$bestDistance = 0.0;
		$changed = false;
		$kept = [];
		foreach($this->portals[$name] ?? [] as $entry){
			[$x, $y, $z] = $entry;
			if(max(abs($x - $pos->x), abs($z - $pos->z)) > $radius || $world->loadChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE) === null){
				$kept[] = $entry;
				continue;
			}
			$shape = NetherPortalShape::findLit($world, new Vector3($x, $y, $z));
			if($shape === null){
				$changed = true; //the portal was broken: forget it
				continue;
			}
			$kept[] = $entry;
			$distance = $shape->getArrivalPosition()->distanceSquared($pos);
			if($best === null || $distance < $bestDistance){
				$best = $shape;
				$bestDistance = $distance;
			}
		}
		if($changed){
			$this->portals[$name] = $kept;
			$this->save();
		}
		return $best;
	}

	/**
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int, int, int, int}>
	 */
	public function getAll(World $world) : array{
		$this->load();
		return $this->portals[$world->getFolderName()] ?? [];
	}
}
