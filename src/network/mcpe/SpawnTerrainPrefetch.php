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

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\player\ChunkSelector;
use pocketmine\player\SpawnChunkBudget;
use pocketmine\world\ChunkLoader;
use pocketmine\world\World;
use function count;
use function hrtime;
use function time;

/**
 * Prepares the terrain a joining player needs before it can spawn while its client is still logging in (encryption
 * handshake, resource packs, StartGamePacket, which take a second or more with a real client): the chunks of the
 * spawn radius around the place where the player will appear are loaded (or generated) and their packets encoded, in
 * the same order and amount the player asks for them later. When the client then asks for its chunks they are ready,
 * and they are sent at once. This is the work the player's own chunk requests would do anyway, only earlier.
 *
 * The chunks are held loaded until the player has spawned, the session closes, or at most {@link self::MAX_AGE}
 * seconds (e.g. a long resource pack download). Each tick it uses what is left of the {@link SpawnChunkBudget} of the
 * joining players (at least one chunk).
 *
 * @internal
 */
final class SpawnTerrainPrefetch implements ChunkLoader{
	private const MAX_AGE = 60;

	/**
	 * @var int[] chunk hashes still to prepare, nearest first
	 * @phpstan-var list<int>
	 */
	private array $queue = [];
	private int $next = 0;
	/**
	 * @var true[] chunk hash => this holds the chunk loaded
	 * @phpstan-var array<int, true>
	 */
	private array $held = [];
	private int $startTime;
	private bool $released = false;

	/**
	 * @param int $count number of chunks to prepare: the player's spawn threshold (see Player::$spawnThreshold)
	 */
	public function __construct(
		private World $world,
		private Compressor $compressor,
		private int $centerChunkX,
		private int $centerChunkZ,
		int $radius,
		int $count
	){
		$this->startTime = time();
		//the same selection and order as the player's chunk requests (the first chunks of any radius are the same)
		foreach((new ChunkSelector())->selectChunks($radius + 1, $centerChunkX, $centerChunkZ) as $hash){
			if(count($this->queue) >= $count){
				break;
			}
			$this->queue[] = $hash;
		}
	}

	/**
	 * Returns whether this prepares the terrain around the given chunk of the given world.
	 */
	public function isAround(World $world, int $chunkX, int $chunkZ) : bool{
		return $world === $this->world && $chunkX === $this->centerChunkX && $chunkZ === $this->centerChunkZ;
	}

	/**
	 * Prepares the next chunks, within the time budget of a tick.
	 */
	public function tick() : void{
		if($this->released){
			return;
		}
		if(!$this->world->isLoaded() || time() - $this->startTime > self::MAX_AGE){
			$this->release();
			return;
		}
		$tick = $this->world->getServer()->getTick();
		$start = hrtime(true);
		$deadline = $start + SpawnChunkBudget::remaining($tick);
		while(isset($this->queue[$this->next])){
			$hash = $this->queue[$this->next++];
			World::getXZ($hash, $chunkX, $chunkZ);
			$this->world->registerChunkLoader($this, $chunkX, $chunkZ, false);
			$this->held[$hash] = true;
			$this->world->requestChunkPopulation($chunkX, $chunkZ, $this)->onCompletion(
				function() use ($chunkX, $chunkZ) : void{
					if(!$this->released && $this->world->isLoaded() && $this->world->isChunkLoaded($chunkX, $chunkZ)){
						//starts encoding the chunk packet (the result stays in the cache while the chunk is unchanged)
						ChunkCache::getInstance($this->world, $this->compressor)->request($chunkX, $chunkZ);
					}
				},
				static function() : void{
					//NOOP: the player requests it again anyway
				}
			);
			if(hrtime(true) >= $deadline){
				break;
			}
		}
		SpawnChunkBudget::use($tick, hrtime(true) - $start);
	}

	/**
	 * Stops preparing chunks and lets the world unload the prepared ones the player doesn't use (the player holds the
	 * ones it uses with its own chunk loader).
	 */
	public function release() : void{
		if($this->released){
			return;
		}
		$this->released = true;
		if($this->world->isLoaded()){
			foreach($this->held as $hash => $_){
				World::getXZ($hash, $chunkX, $chunkZ);
				$this->world->unregisterChunkLoader($this, $chunkX, $chunkZ);
			}
		}
		$this->held = [];
		$this->queue = [];
	}
}
