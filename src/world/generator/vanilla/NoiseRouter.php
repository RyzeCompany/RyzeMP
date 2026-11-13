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

namespace pocketmine\world\generator\vanilla;

use pocketmine\world\generator\vanilla\density\DensityFunction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\random\PositionalRandomFactory;
use pocketmine\world\generator\vanilla\random\XoroshiroRandom;
use function is_array;
use function is_string;
use function min;

/**
 * Seed-bound noise router: the density functions that drive terrain, biomes and aquifers
 * (equivalent of vanilla's RandomState for one noise_settings entry: "overworld", "nether", "end"...).
 */
final class NoiseRouter{
	/** Below this y the global fluid is lava when the sea level is higher (NoiseBasedChunkGenerator). */
	public const LAVA_LEVEL = -54;

	public readonly PositionalRandomFactory $random;

	public readonly DensityFunction $temperature;
	public readonly DensityFunction $vegetation;
	public readonly DensityFunction $continents;
	public readonly DensityFunction $erosion;
	public readonly DensityFunction $depth;
	public readonly DensityFunction $ridges;
	public readonly DensityFunction $finalDensity;
	public readonly DensityFunction $chunkSurfaceLevel;

	public readonly bool $aquifersEnabled;
	public readonly ?DensityFunction $aquiferBarrier;
	public readonly ?DensityFunction $aquiferFloodedness;
	public readonly ?DensityFunction $aquiferSpread;
	public readonly ?DensityFunction $aquiferLava;
	public readonly ?DensityFunction $aquiferExclusion;
	public readonly ?DensityFunction $aquiferSurfaceLevel;

	public readonly int $seaLevel;
	public readonly int $minY;
	public readonly int $height;

	public readonly DensityFunctionParser $parser;

	/** Whether the noise settings use the legacy (java.util.Random) random source: Nether and End. */
	public readonly bool $legacyRandom;
	/** Java block id of the default block (stone, netherrack, end_stone). */
	public readonly string $defaultBlock;
	/** Java block id of the default fluid (water, lava, air). */
	public readonly string $defaultFluid;
	/** Terrain material of the global fluid below the sea level (Aquifer::MATERIAL_*). */
	public readonly int $seaMaterial;

	public function __construct(
		private WorldgenData $data,
		private int $seed,
		public readonly string $settingsId = "overworld"
	){
		$settings = $data->noiseSettings($settingsId);
		$this->legacyRandom = (bool) ($settings["legacy_random_source"] ?? false);
		$this->random = ($this->legacyRandom ? new LegacyRandom($seed) : XoroshiroRandom::create($seed))->forkPositional();
		$this->parser = $parser = $this->createParser();
		$defaultBlock = $settings["default_block"] ?? "minecraft:stone";
		$defaultFluid = $settings["default_fluid"] ?? "minecraft:water";
		//older data has block states ({"Name": id}) instead of ids
		$this->defaultBlock = "minecraft:" . WorldgenData::stripNamespace(is_array($defaultBlock) ? WorldgenData::string($defaultBlock, "Name") : (is_string($defaultBlock) ? $defaultBlock : "stone"));
		$this->defaultFluid = "minecraft:" . WorldgenData::stripNamespace(is_array($defaultFluid) ? WorldgenData::string($defaultFluid, "Name") : (is_string($defaultFluid) ? $defaultFluid : "water"));
		$this->seaMaterial = match($this->defaultFluid){
			"minecraft:water" => Aquifer::MATERIAL_WATER,
			"minecraft:lava" => Aquifer::MATERIAL_LAVA,
			"minecraft:air" => Aquifer::MATERIAL_AIR,
			default => throw new \RuntimeException("Unsupported default fluid " . $this->defaultFluid)
		};

		$noise = WorldgenData::array($settings, "noise");
		$this->minY = WorldgenData::int($noise, "min_y");
		$this->height = WorldgenData::int($noise, "height");
		$this->seaLevel = WorldgenData::int($settings, "sea_level");

		$router = WorldgenData::array($settings, "noise_router");
		$f = fn(string $key) : DensityFunction => $parser->parse($router[$key] ?? 0.0);
		$this->temperature = $f("temperature");
		$this->vegetation = $f("vegetation");
		$this->continents = $f("continents");
		$this->erosion = $f("erosion");
		$this->depth = $f("depth");
		$this->ridges = $f("ridges");
		$this->finalDensity = $f("final_density");
		$this->chunkSurfaceLevel = $f("chunk_surface_level");

		$aquifers = $settings["aquifers"] ?? null;
		if(is_array($aquifers)){
			$a = fn(string $key) : DensityFunction => $parser->parse($aquifers[$key] ?? 0.0);
			$this->aquifersEnabled = true;
			$this->aquiferBarrier = $a("barrier");
			$this->aquiferFloodedness = $a("fluid_level_floodedness");
			$this->aquiferSpread = $a("fluid_level_spread");
			$this->aquiferLava = $a("lava");
			$this->aquiferExclusion = $a("exclusion");
			$this->aquiferSurfaceLevel = $a("surface_level");
		}else{
			$this->aquifersEnabled = false;
			$this->aquiferBarrier = $this->aquiferFloodedness = $this->aquiferSpread = $this->aquiferLava = $this->aquiferExclusion = $this->aquiferSurfaceLevel = null;
		}
	}

	/**
	 * A new parser bound to this router's seed and random source (own noise and named function instances).
	 */
	public function createParser() : DensityFunctionParser{
		return new DensityFunctionParser($this->data, $this->random, $this->legacyRandom ? $this->seed : null);
	}

	/**
	 * The global fluid (NoiseBasedChunkGenerator's fluid picker): lava below y -54 when the sea is higher, the default
	 * fluid below the sea level, air above. Returns an Aquifer::MATERIAL_* id.
	 */
	public function globalFluid(int $y) : int{
		if($y < min(self::LAVA_LEVEL, $this->seaLevel)){
			return Aquifer::MATERIAL_LAVA;
		}
		return $y < $this->seaLevel ? $this->seaMaterial : Aquifer::MATERIAL_AIR;
	}
}
