<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\World;
use function array_key_first;
use function count;
use function intdiv;
use function pack;
use function unpack;

/**
 * Evaluate each source chunk on the same undecorated terrain on every worker.
 * Only the target chunk receives the result. Sort the sources by absolute X/Z
 * so a population request cannot overwrite an already published neighbour.
 *
 * @phpstan-type Changes array{string, list<CompoundTag>}
 */
final class DeterministicDecoration{
	private const TERRAIN_CACHE_SIZE = 64;
	private const DECORATION_CACHE_SIZE = 36;

	/** @var array<int, string> */
	private array $terrain = [];
	/** @var array<int, array<int, Changes>> */
	private array $changes = [];

	/** @phpstan-param \Closure(Chunk, int, int) : void $generateTerrain */
	public function __construct(
		private readonly FeatureDecorator $decorator,
		private readonly \Closure $generateTerrain
	){}

	public function decorate(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ);
		if($chunk === null || $chunk->isPopulated()){
			return;
		}

		$operations = [];
		$targetHash = World::chunkHash($chunkX, $chunkZ);
		for($x = $chunkX - 1; $x <= $chunkX + 1; ++$x){
			for($z = $chunkZ - 1; $z <= $chunkZ + 1; ++$z){
				$changes = $this->sourceChanges($x, $z)[$targetHash] ?? null;
				if($changes !== null){
					$operations[] = $changes;
				}
			}
		}

		$level = $this->decorator->level;
		$level->begin($world, $chunkX, $chunkZ);
		try{
			foreach($operations as [$writes, $tiles]){
				/** @var int[] $decoded */
				$decoded = $writes !== "" ? unpack("V*", $writes) : [];
				for($i = 1, $length = count($decoded); $i <= $length; $i += 2){
					$pos = $decoded[$i];
					$state = $decoded[$i + 1];
					$x = ($chunkX << 4) + ($pos & 15);
					$y = $level->minY + ($pos >> 8);
					$z = ($chunkZ << 4) + (($pos >> 4) & 15);
					if(($state & 0x80000000) !== 0){
						$level->setPmState($x, $y, $z, $state & 0x7fffffff);
					}else{
						$level->setState($x, $y, $z, $state);
					}
				}
				foreach($tiles as $tag){
					$level->setBlockEntity($tag->getInt("x"), $tag->getInt("y"), $tag->getInt("z"), $tag);
				}
			}
		}finally{
			$level->finish();
		}
	}

	/** @return array<int, Changes> */
	private function sourceChanges(int $chunkX, int $chunkZ) : array{
		$hash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->changes[$hash])){
			return $this->changes[$hash];
		}

		$level = $this->decorator->level;
		$scratch = new SimpleChunkManager($level->minY, $level->maxY);
		for($x = $chunkX - 1; $x <= $chunkX + 1; ++$x){
			for($z = $chunkZ - 1; $z <= $chunkZ + 1; ++$z){
				$scratch->setChunk($x, $z, $this->baseChunk($x, $z));
			}
		}
		$level->logWrites = true;
		try{
			$this->decorator->decorate($scratch, $chunkX, $chunkZ);
			$writes = $level->writeLog;
		}finally{
			$level->logWrites = false;
			$level->writeLog = [];
		}

		/** @var array<int, array<int, int>> $blocks */
		$blocks = [];
		$size = FeatureLevel::SIZE;
		for($i = 0, $length = count($writes); $i < $length; $i += 3){
			$key = $writes[$i] & 0x7fffffff;
			$x = $key % $size;
			$z = intdiv($key, $size) % $size;
			$y = intdiv($key, $size * $size);
			$destination = World::chunkHash($chunkX - 1 + ($x >> 4), $chunkZ - 1 + ($z >> 4));
			$pos = ($y << 8) | (($z & 15) << 4) | ($x & 15);
			$blocks[$destination][$pos] = ($writes[$i] & 0x80000000) !== 0 ? $writes[$i + 2] | 0x80000000 : $writes[$i + 1];
		}

		$result = [];
		for($x = $chunkX - 1; $x <= $chunkX + 1; ++$x){
			for($z = $chunkZ - 1; $z <= $chunkZ + 1; ++$z){
				$destination = World::chunkHash($x, $z);
				$tiles = $scratch->getChunk($x, $z)?->getPendingTileNBT() ?? [];
				$encoded = [];
				foreach($blocks[$destination] ?? [] as $pos => $state){
					$encoded[] = $pos;
					$encoded[] = $state;
				}
				if($encoded !== [] || $tiles !== []){
					$result[$destination] = [pack("V*", ...$encoded), array_values($tiles)];
				}
			}
		}
		if(count($this->changes) >= self::DECORATION_CACHE_SIZE){
			unset($this->changes[array_key_first($this->changes)]);
		}
		return $this->changes[$hash] = $result;
	}

	private function baseChunk(int $x, int $z) : Chunk{
		$hash = World::chunkHash($x, $z);
		if(isset($this->terrain[$hash])){
			return FastChunkSerializer::deserializeTerrain($this->terrain[$hash]);
		}
		$chunk = new Chunk([], false);
		($this->generateTerrain)($chunk, $x, $z);
		$this->rememberTerrain($chunk, $x, $z);
		return $chunk;
	}

	public function rememberTerrain(Chunk $chunk, int $x, int $z) : void{
		$hash = World::chunkHash($x, $z);
		if(isset($this->terrain[$hash])){
			return;
		}
		if(count($this->terrain) >= self::TERRAIN_CACHE_SIZE){
			unset($this->terrain[array_key_first($this->terrain)]);
		}
		$this->terrain[$hash] = FastChunkSerializer::serializeTerrain($chunk);
	}
}
