<?php
/*
 * VanillaMine 26.3 Go/compiled-PHP reference parity.
 * Run only with an authentic data cache and a pinned vanillagen binary.
 * Unlike synthetic mocks this compares every material cell and biome across
 * dimensions and seeds, using the same algorithms as core-v6.
 */
declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use pocketmine\world\generator\vanilla\CompiledTerrainFiller;
use pocketmine\world\generator\vanilla\NoiseRouter;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\native\NativeTerrainClient;
use pocketmine\world\generator\vanilla\native\NativeTerrainFiller;
use pocketmine\world\generator\vanilla\native\VanillaNativeBackend;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;

$binary = $argv[1] ?? "";
$dataDir = $argv[2] ?? "";
if(!is_file($binary) || !is_file($dataDir . "/version.json")){
	throw new RuntimeException("Usage: php tests/native-worldgen-parity.php /absolute/vanillagen /absolute/Java26.3-cache");
}
$backend = VanillaNativeBackend::start($binary, sys_get_temp_dir() . "/ryze-native-parity.log");
$backend->publish();
try{
	$data = new WorldgenData($dataDir);
	$cases = 0;
	foreach(["overworld", "nether", "end"] as $dimension){
		foreach([12345, -987654321] as $seed){
			$router = new NoiseRouter($data, $seed, $dimension);
			$tree = $dimension === "end" ? ClimateBiomeTree::single("minecraft:the_end") : ClimateBiomeTree::fromData($data, $dimension);
			$fallbackFactory = static fn() => new CompiledTerrainFiller($router, $tree, $dataDir . "/compiled");
			$native = NativeTerrainFiller::connect($dataDir, $seed, $tree->getBiomeNames(), $router->minY, $router->height,
				$fallbackFactory, NativeTerrainClient::FLAG_MATERIALS | NativeTerrainClient::FLAG_CARVERS, $dimension);
			if($native === null || $native->getClient() === null){
				throw new RuntimeException("Native handshake or biome verification failed for $dimension seed $seed");
			}
			$phpFiller = $fallbackFactory();
			$phpMaterials = new MaterialSystem($data, $router, $tree, $seed, null, null, $dimension);
			$phpCarvers = CarverSystem::create($data, $router, $tree, $seed, $phpMaterials, null);
			$phpNames = $phpMaterials->palette->getNames();
			foreach([[0, 0], [-2, 3]] as [$chunkX, $chunkZ]){
				$reference = $phpFiller->fill($chunkX, $chunkZ);
				$phpMaterials->apply($reference);
				$phpCarvers?->apply($reference);
				$result = $native->fill($chunkX, $chunkZ);
				if($native->getClient() === null){
					throw new RuntimeException("Native helper unexpectedly fell back during $dimension chunk $chunkX,$chunkZ");
				}
				if($result->stage !== \pocketmine\world\generator\vanilla\TerrainChunk::STAGE_CARVED || $result->palette === null){
					throw new RuntimeException("Go full-stage response is not material+carver-complete");
				}
				if($result->biomes !== $reference->biomes){
					throw new RuntimeException("Go/PHP 3D biome mismatch for $dimension seed $seed chunk $chunkX,$chunkZ");
				}
				if(count($result->materials) !== count($reference->materials)){
					throw new RuntimeException("Go/PHP material array lengths differ");
				}
				$goNames = $result->palette;
				foreach($reference->materials as $i => $id){
					$a = $phpNames[$id] ?? null;
					$b = $goNames[$result->materials[$i]] ?? null;
					if($a !== $b){
						throw new RuntimeException("Go/PHP terrain mismatch for $dimension seed=$seed chunk=$chunkX,$chunkZ cell=$i PHP=" . ($a ?? "NULL") . " Go=" . ($b ?? "NULL"));
					}
				}
				if($result->getCarvingMask() !== $reference->getCarvingMask()){
					throw new RuntimeException("Go/PHP carver mask mismatch for $dimension seed=$seed chunk $chunkX,$chunkZ");
				}
				++$cases;
				echo "GO_PHP_PARITY_OK dimension=$dimension seed=$seed chunk=$chunkX,$chunkZ\n";
			}
		}
	}
	echo "GO_PHP_PARITY_COMPLETE chunks=$cases dimensions=3 seeds=2 stages=terrain+material+carvers\n";
}finally{
	$backend->stop();
}
