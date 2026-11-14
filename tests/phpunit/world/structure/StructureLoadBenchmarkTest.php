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

use pocketmine\block\tile\StructureBlock as TileStructureBlock;
use pocketmine\block\utils\StructureBlockType;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use function count;
use function fwrite;
use function gc_collect_cycles;
use function getenv;
use function hrtime;
use function max;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function sort;
use function sprintf;
use const STDERR;

/**
 * Tick times and memory of structure loads (STRUCTURE_BENCH=1): a load covering its own structure block, a load
 * animated by layer, four loads at once, and the memory of a big load.
 */
final class StructureLoadBenchmarkTest extends RedstoneTestCase{

	protected function setUp() : void{
		if(getenv("STRUCTURE_BENCH") !== "1"){
			self::markTestSkipped("Set STRUCTURE_BENCH=1 to run the structure load benchmarks");
		}
		parent::setUp();
		//the first load of the process sets up the block state upgrader and other singletons
		$this->saveTemplate("bench:warmup", 1, 1, 1);
		self::assertNull($this->loadBlock(-47, 0, -47, "bench:warmup", new Vector3(0, 1, 0))->loadStructure());
		//the loads measured start in a tick of their own
		$this->runTicks(1);
	}

	protected function getChunkRadius() : int{
		return 3;
	}

	private function saveTemplate(string $name, int $sizeX, int $sizeY, int $sizeZ) : void{
		$builder = new StructureTemplateBuilder($sizeX, $sizeY, $sizeZ);
		$states = [VanillaBlocks::STONE()->getStateId(), VanillaBlocks::OAK_PLANKS()->getStateId(), VanillaBlocks::GLASS()->getStateId(), VanillaBlocks::AIR()->getStateId()];
		for($x = 0; $x < $sizeX; ++$x){
			for($y = 0; $y < $sizeY; ++$y){
				for($z = 0; $z < $sizeZ; ++$z){
					$builder->setBlock($x, $y, $z, $states[($x + $y * 3 + $z * 7) & 3]);
				}
			}
		}
		StructureTemplateStorage::get($this->world)->save($name, $builder->build(), false);
	}

	private function loadBlock(int $x, int $y, int $z, string $name, Vector3 $offset) : TileStructureBlock{
		$this->set($x, $y, $z, VanillaBlocks::STRUCTURE_BLOCK()->setMode(StructureBlockType::LOAD));
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(TileStructureBlock::class, $tile);
		$tile->setStructureName($name);
		$tile->setOffset($offset->getFloorX(), $offset->getFloorY(), $offset->getFloorZ());
		return $tile;
	}

	/**
	 * Starts the loads (in one tick, like a redstone line) and ticks until they are done. Prints the time of the
	 * synchronous start, the longest tick and the number of ticks.
	 */
	private function measure(string $label, TileStructureBlock ...$tiles) : void{
		gc_collect_cycles();
		$memory = memory_get_usage();
		memory_reset_peak_usage();
		$start = hrtime(true);
		foreach($tiles as $tile){
			self::assertNull($tile->loadStructure());
		}
		$first = hrtime(true) - $start;
		$maxTick = 0;
		$ticks = 0;
		$total = $first;
		$tickTimes = [];
		while($ticks < 100_000){
			$loading = false;
			foreach($tiles as $tile){
				$loading = $loading || $tile->isLoadingStructure();
			}
			if(!$loading){
				break;
			}
			$tickStart = hrtime(true);
			$this->runTicks(1);
			$tickTime = hrtime(true) - $tickStart;
			$maxTick = max($maxTick, $tickTime);
			$tickTimes[] = $tickTime;
			$total += $tickTime;
			++$ticks;
		}
		//the neighbour updates queued by the last tick
		$this->runTicks(1);
		sort($tickTimes);
		$p99 = $tickTimes[(int) (count($tickTimes) * 0.99)] ?? 0;
		fwrite(STDERR, sprintf("%-28s start %6.1f ms, max tick %6.1f ms, p99 %5.1f ms, %5d ticks, total %8.1f ms, peak memory +%6.1f MB\n",
			$label, $first / 1e6, $maxTick / 1e6, $p99 / 1e6, $ticks, $total / 1e6, (memory_get_peak_usage() - $memory) / 1e6));
	}

	public function testLoadCoveringItsBlock() : void{
		$this->saveTemplate("bench:cover", 32, 32, 32);
		$this->measure("32x32x32 covering its block", $this->loadBlock(0, 10, 0, "bench:cover", new Vector3(0, -1, 0)));
	}

	public function testAnimationByLayer() : void{
		$this->saveTemplate("bench:layers", 64, 96, 64);
		$tile = $this->loadBlock(-32, 0, -32, "bench:layers", new Vector3(0, 1, 0));
		$tile->setAnimationMode(StructurePlaceSettings::ANIMATION_BY_LAYER);
		$tile->setAnimationSeconds(0.25);
		$this->measure("64x96x64 by layer in 0.25 s", $tile);
	}

	public function testFourLoadsAtOnce() : void{
		$this->saveTemplate("bench:four", 16, 32, 16);
		$this->measure("4 x 16x32x16 at once",
			$this->loadBlock(-40, 0, -40, "bench:four", new Vector3(0, 1, 0)),
			$this->loadBlock(-20, 0, -40, "bench:four", new Vector3(0, 1, 0)),
			$this->loadBlock(0, 0, -40, "bench:four", new Vector3(0, 1, 0)),
			$this->loadBlock(20, 0, -40, "bench:four", new Vector3(0, 1, 0))
		);
	}

	public function testBigLoadMemory() : void{
		$this->saveTemplate("bench:big", 64, 96, 64);
		$this->measure("64x96x64", $this->loadBlock(-32, 0, -32, "bench:big", new Vector3(0, 1, 0)));
	}
}
