<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\VanillaBlocks;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use pocketmine\world\generator\vanilla\carver\CarverSystem;
use pocketmine\world\generator\vanilla\surface\MaterialSystem;
use Symfony\Component\Filesystem\Path;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function max;
use function min;
use const JSON_THROW_ON_ERROR;

/**
 * Adapter for VanillaMine's real multi-noise / density / aquifer terrain stage.
 *
 * IMPORTANT: MaterialSystem, carvers and feature/structure decoration are NOT yet adapted.
 * This adapter is intentionally a distinct, version-pinned preset on the existing opt-in
 * generator. The empty preset continues to use the legacy experimental terrain unchanged.
 */
final class VanillaDensityChunkWriter{
	public const PRESET_PREFIX = "core-v1;data=";
	public const MINECRAFT_DATA_VERSION = "26.3";
	public const DATA_FORMAT = 5;

	private InterpretedTerrainFiller $terrain;
	private MaterialSystem $materials;
	private ?CarverSystem $carvers;
	/** @var list<int> */
	private array $biomes;

	private int $air;
	private int $bedrock;

	public function __construct(int $seed, string $dataDir){
		$data = new WorldgenData($dataDir);
		$router = new NoiseRouter($data, $seed, "overworld");
		if($router->minY !== -64 || $router->height !== 384){
			throw new InvalidGeneratorOptionsException("Vanilla worldgen core-v1 requires Overworld height -64..319, got $router->minY / $router->height");
		}
		$tree = ClimateBiomeTree::fromData($data);
		$this->biomes = array_map(static fn(string $name) : int => BiomeMapping::toBedrock($name), $tree->getBiomeNames());
		$this->terrain = new InterpretedTerrainFiller($router, $tree);
		$this->materials = new MaterialSystem($data, $router, $tree, $seed);
		$this->carvers = CarverSystem::create($data, $router, $tree, $seed, $this->materials, null);
		$this->air = VanillaBlocks::AIR()->getStateId();
		$this->bedrock = VanillaBlocks::BEDROCK()->getStateId();
	}

	/** Returns the directory only after validating the entire preset and its data stamp. */
	public static function validatePreset(string $preset) : string{
		if(!str_starts_with($preset, self::PRESET_PREFIX)){
			throw new InvalidGeneratorOptionsException("Expected core-v1;data=<absolute path> (or empty legacy preset)");
		}
		$dir = substr($preset, strlen(self::PRESET_PREFIX));
		if($dir === "" || str_contains($dir, ";") || str_contains($dir, "\0") || !Path::isAbsolute($dir)){
			throw new InvalidGeneratorOptionsException("core-v1 requires a single absolute data directory");
		}
		$stampFile = $dir . "/version.json";
		if(!is_file($stampFile)){
			throw new InvalidGeneratorOptionsException("Missing VanillaMine version.json in $dir");
		}
		try{
			$raw = file_get_contents($stampFile);
			if($raw === false){
				throw new InvalidGeneratorOptionsException("Could not read VanillaMine data stamp");
			}
			$stamp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new InvalidGeneratorOptionsException("Invalid VanillaMine data stamp", 0, $e);
		}
		if(!is_array($stamp) || ($stamp["minecraft"] ?? null) !== self::MINECRAFT_DATA_VERSION || ($stamp["format"] ?? null) !== self::DATA_FORMAT){
			throw new InvalidGeneratorOptionsException("VanillaMine worldgen cache must be Minecraft 26.3, format 5");
		}
		if(!is_file($dir . "/data/minecraft/worldgen/noise_settings/overworld.json") ||
			(!is_file($dir . "/multi_noise_biome_tree.json") && !is_file($dir . "/reports/biome_parameters/minecraft/overworld.json"))){
			throw new InvalidGeneratorOptionsException("VanillaMine worldgen cache is incomplete");
		}
		return $dir;
	}

	/**
	 * Applies the upstream VanillaMine density and climate stages to a RyzeMP chunk.
	 * This is NOT a complete vanilla chunk: the material surface/carver/features stages
	 * still need to be ported, so this cannot be used for production worlds.
	 */
	public function fill(Chunk $chunk, int $chunkX, int $chunkZ) : void{
		if($chunk->isPopulated()){
			return;
		}
		$terrain = $this->terrain->fill($chunkX, $chunkZ);
		$terrain->unpackBiomes();
		$this->materials->apply($terrain);
		$this->carvers?->apply($terrain);
		$height = $terrain->height;
		$quartsY = $height >> 2;
		$minY = $terrain->minY;
		$maxY = $minY + $height;
		$biomeCount = count($this->biomes);

		// Each 4x4x4 VanillaMine noise biome is expanded into RyzeMP's
		// per-subchunk Bedrock biomes. This preserves vertical cave biomes.
		for($subY = Chunk::MIN_SUBCHUNK_INDEX; $subY <= Chunk::MAX_SUBCHUNK_INDEX; ++$subY){
			$old = $chunk->getSubChunk($subY);
			$palette = new PalettedBlockArray($this->biomes[0]);
			for($qx = 0; $qx < 4; ++$qx){
				for($qz = 0; $qz < 4; ++$qz){
					for($qy = 0; $qy < 4; ++$qy){
						$index = (($qx << 2) | $qz) * $quartsY + (($subY << 2) - ($minY >> 2)) + $qy;
						$id = $terrain->biomes[$index] ?? throw new \UnexpectedValueException("Missing 3D biome cell $index");
						if($id < 0 || $id >= $biomeCount){
							throw new \UnexpectedValueException("Unknown VanillaMine biome index $id");
						}
						$bedrockBiome = $this->biomes[$id];
						for($dx = 0; $dx < 4; ++$dx){
							for($dz = 0; $dz < 4; ++$dz){
								for($dy = 0; $dy < 4; ++$dy){
									$palette->set(($qx << 2) | $dx, ($qy << 2) | $dy, ($qz << 2) | $dz, $bedrockBiome);
								}
							}
						}
					}
				}
			}
			$chunk->setSubChunk($subY, new SubChunk($old->getEmptyBlockId(), $old->getBlockLayers(), $palette));
		}

		$states = [];
		foreach($this->materials->palette->getNames() as $material => $javaId){
			$states[$material] = JavaBlockMapping::toBlock($javaId)->getStateId();
		}
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$base = (($x << 4) | $z) * $height;
				$highest = $minY;
				for($y = $minY; $y < $maxY; ++$y){
					$m = $terrain->materials[$base + $y - $minY] ?? throw new \UnexpectedValueException("Missing VanillaMine terrain material");
					$state = $states[$m] ?? throw new \UnexpectedValueException("Unknown VanillaMine terrain material $m");
					if($y === $minY){
						$state = $this->bedrock;
					}
					if($state !== $this->air){
						$chunk->setBlockStateId($x, $y, $z, $state);
						$highest = $y + 1;
					}
				}
				$chunk->setHeightMap($x, $z, $highest);
			}
		}
	}
}
