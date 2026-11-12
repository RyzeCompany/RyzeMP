<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\biome\TheEndBiomeSource;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use function array_map;
use function getenv;
use function implode;
use function is_dir;
use function json_decode;
use function md5;
use const JSON_THROW_ON_ERROR;

/**
 * Independent upstream dimension reference hashes from Java 26.3 / VanillaMine.
 * Requires a genuinely prepared full data cache; deliberately skips on normal
 * synthetic-only CI. See ci-worldgen-realdata.yml for an opt-in runner.
 */
final class VanillaRealDataReferenceTest extends TestCase{

	public function testNetherAndEndMaterialAndBiomeHashesAgainstVanillaMine() : void{
		$directory = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($directory === false || !is_dir($directory . "/data/minecraft/worldgen/feature")){
			self::markTestSkipped("Authentic Minecraft 26.3 VanillaMine worldgen data is not installed");
		}
		$preset = "core-v2;data=" . $directory . ";sha256=" . VanillaDataIdentity::fingerprint($directory);
		VanillaDensityChunkWriter::validatePreset($preset, "nether");
		VanillaDensityChunkWriter::validatePreset($preset, "end");

		/** @phpstan-var array<string, mixed> $fixtures */
		$fixtures = json_decode(Filesystem::fileGetContents(__DIR__ . "/dimension-fixtures.json"), true, 512, JSON_THROW_ON_ERROR);
		$data = new WorldgenData($directory);
		foreach(["nether", "end"] as $dimension){
			/** @phpstan-var array<string, list<array{cx: int, cz: int, blocks: string, biomes: string}>> $seeds */
			$seeds = $fixtures[$dimension];
			foreach(Utils::stringifyKeys($seeds) as $seedString => $chunks){
				$seed = (int) $seedString;
				$router = new NoiseRouter($data, $seed, $dimension);
				$tree = $dimension === "end" ?
					ClimateBiomeTree::single("minecraft:the_end") :
					ClimateBiomeTree::fromData($data, $dimension);
				$filler = new InterpretedTerrainFiller($router, $tree);
				$materials = new MaterialSystem($data, $router, $tree, $seed, null, null, $dimension);
				$carvers = CarverSystem::create($data, $router, $tree, $seed, $materials, null);
				$names = $materials->palette->getNames();
				$end = $dimension === "end" ? new TheEndBiomeSource($router->erosion) : null;
				foreach($chunks as $fixture){
					$terrain = $filler->fill($fixture["cx"], $fixture["cz"]);
					$terrain->unpackBiomes();
					if($end !== null){
						$terrain->biomes = $end->fillChunk($fixture["cx"], $fixture["cz"], $terrain->height >> 2);
					}
					$materials->apply($terrain);
					$carvers?->apply($terrain);
					$where = "$dimension chunk {$fixture["cx"]},{$fixture["cz"]} seed $seed";
					self::assertSame(
						$fixture["blocks"],
						md5(implode(",", array_map(static fn(int $m) : string => $names[$m], $terrain->materials))),
						"Material blocks differ from independent VanillaMine Java 26.3 fixture: $where"
					);
					$biomes = $terrain->biomes;
					$biomeNames = $end !== null ? TheEndBiomeSource::NAMES : $tree->getBiomeNames();
					self::assertSame(
						$fixture["biomes"],
						md5(implode(",", array_map(static fn(int $b) : string => $biomeNames[$b], $biomes))),
						"Biomes differ from independent VanillaMine fixture: $where"
					);
				}
			}
		}
	}
}
