<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\format\io\FastChunkSerializer;

/**
 * Guard the optimized direct-subchunk write path against regressions at
 * negative Y, subchunk boundaries, and the original dirty-flag semantics.
 */
final class VanillaWorldgenPerformanceTest extends TestCase{
    public function testDirectSubchunkWritesAreBitwiseEquivalentToChunkWrites() : void{
        $original = new Chunk([], false);
        $optimized = new Chunk([], false);
        $original->clearTerrainDirtyFlags();
        $optimized->clearTerrainDirtyFlags();
        $states = [
            VanillaBlocks::STONE()->getStateId(),
            VanillaBlocks::GRASS()->getStateId(),
            VanillaBlocks::DIRT()->getStateId()
        ];
        $subChunks = $optimized->getSubChunks();
        foreach([-64, -63, -48, -17, -16, -1, 0, 15, 16, 31, 80, 319] as $y){
            foreach([[0, 0], [15, 15], [7, 8], [8, 7]] as [$x, $z]){
                $state = $states[($x + $z + $y + 640) % count($states)];
                $original->setBlockStateId($x, $y, $z, $state);
                $subChunks[$y >> SubChunk::COORD_BIT_SIZE]->setBlockStateId(
                    $x, $y & SubChunk::COORD_MASK, $z, $state
                );
            }
        }
        $optimized->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
        self::assertSame($original->getTerrainDirtyFlags(), $optimized->getTerrainDirtyFlags());
        self::assertSame(FastChunkSerializer::serializeTerrain($original),
            FastChunkSerializer::serializeTerrain($optimized));
    }

    public function testPopulationTaskRemainsConstructibleInPhpZts() : void{
        // Detect forbidden non-thread-safe property defaults before shipping a
        // PHAR: this regression previously crashed the real server at startup.
        $task = new \pocketmine\world\generator\PopulationTask(
            1, 0, 0, null, [],
            static function(Chunk $chunk, array $adjacentChunks) : void{}
        );
        self::assertInstanceOf(\pocketmine\world\generator\PopulationTask::class, $task);
    }

    public function testProfilerDisabledAndResetSafely() : void{
        $key = "RYZEMP_WORLDGEN_PROFILE";
        $was = getenv($key);
        try{
            putenv($key . "=false");
            VanillaWorldgenProfiler::begin();
            VanillaWorldgenProfiler::add("terrain", 14.0);
            self::assertSame([], VanillaWorldgenProfiler::finish());

            putenv($key . "=true");
            VanillaWorldgenProfiler::begin();
            self::assertTrue(VanillaWorldgenProfiler::active());
            VanillaWorldgenProfiler::add("terrain", 12.5);
            VanillaWorldgenProfiler::add("terrain", 7.5);
            VanillaWorldgenProfiler::add("blocks", 3.25);
            self::assertSame(["terrain" => 20.0, "blocks" => 3.25], VanillaWorldgenProfiler::finish());
            self::assertFalse(VanillaWorldgenProfiler::active());
        }finally{
            putenv($was === false ? $key : $key . "=" . $was);
        }
    }
}
