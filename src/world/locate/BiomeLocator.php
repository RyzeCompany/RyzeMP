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

namespace pocketmine\world\locate;

use pocketmine\data\bedrock\BiomeIds;
use pocketmine\math\Vector3;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\BiomeMapping;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\World;
use function array_keys;
use function array_unique;
use function array_values;
use function constant;
use function defined;
use function in_array;
use function is_a;
use function is_int;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Finds the closest biome of a type for /locate biome. Worlds made by the vanilla generator are searched from the
 * climate noise on an async worker without generating chunks (up to {@link BiomeSearch::DEFAULT_RADIUS} blocks);
 * worlds of other generators can only be searched in their loaded chunks.
 *
 * Biome names may be Bedrock ids ("mesa", "roofed_forest") or Java Edition ids ("badlands", "dark_forest"), with or
 * without the "minecraft:" prefix.
 */
final class BiomeLocator{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the Java Edition biome ids and the Bedrock biome ids named by the given name, or null if no biome has
	 * that name.
	 *
	 * @phpstan-return array{list<string>, list<int>}|null
	 */
	public static function resolveBiomeName(string $name) : ?array{
		$name = strtolower($name);
		if(str_starts_with($name, "minecraft:")){
			$name = substr($name, 10);
		}
		if($name === ""){
			return null;
		}
		$bedrockIds = [];
		$javaNames = [];
		$constant = BiomeIds::class . "::" . strtoupper($name);
		if(defined($constant)){
			$value = constant($constant);
			if(is_int($value)){
				//a Bedrock biome: every Java Edition biome the vanilla generator turns into it
				$bedrockIds[] = $value;
				foreach(BiomeMapping::getJavaNames() as $javaName){
					if(BiomeMapping::toBedrock($javaName) === $value && $javaName !== "dappled_forest"){
						$javaNames[] = $javaName;
					}
				}
			}
		}
		if(in_array($name, BiomeMapping::getJavaNames(), true)){
			$javaNames[] = $name;
			$bedrockIds[] = BiomeMapping::toBedrock($name);
		}
		if($bedrockIds === []){
			return null;
		}
		return [array_values(array_unique($javaNames)), array_values(array_unique($bedrockIds))];
	}

	/**
	 * Returns the vanilla generator class generating the world, or null for other generators.
	 * @phpstan-return class-string<VanillaGenerator>|null
	 */
	private static function getVanillaGenerator(World $world) : ?string{
		$entry = GeneratorManager::getInstance()->getGenerator($world->getProvider()->getWorldData()->getGenerator());
		$class = $entry?->getGeneratorClass();
		return $class !== null && is_a($class, VanillaGenerator::class, true) ? $class : null;
	}

	/**
	 * Starts searching the closest biome named $biome from $origin. Returns false if no biome has that name;
	 * otherwise $onCompletion is called with the block position found (null if none), either right away or when the
	 * async search finishes ($failed = true if the search crashed, $timedOut = true if it gave up after
	 * $timeoutSeconds). Vanilla worlds are searched on the {@link LocateWorkerPool}, not on the server's async pool,
	 * which generates chunks.
	 *
	 * @phpstan-param \Closure(?Vector3 $position, bool $failed, bool $timedOut) : void $onCompletion
	 * @param int $timeoutSeconds 0 = no time limit
	 */
	public static function locate(World $world, string $biome, Vector3 $origin, \Closure $onCompletion, int $radius = BiomeSearch::DEFAULT_RADIUS, int $timeoutSeconds = 0) : bool{
		$names = self::resolveBiomeName($biome);
		if($names === null){
			return false;
		}
		[$javaNames, $bedrockIds] = $names;

		$generator = self::getVanillaGenerator($world);
		if($generator !== null){
			if($javaNames === []){
				$onCompletion(null, false, false);
				return true;
			}
			try{
				$dataDir = VanillaGenerator::dataDirectory(VanillaGenerator::parseOptions($world->getProvider()->getWorldData()->getGeneratorOptions()));
			}catch(InvalidGeneratorOptionsException){
				$dataDir = null;
			}
			if($dataDir !== null){
				LocateWorkerPool::getInstance($world->getServer())->submitTask(new LocateVanillaBiomeTask(
					$dataDir,
					$world->getSeed(),
					$generator::DIMENSION,
					$javaNames,
					$origin->getFloorX(),
					$origin->getFloorY(),
					$origin->getFloorZ(),
					$radius,
					$onCompletion,
					$timeoutSeconds,
					$generator,
					$world->getProvider()->getWorldData()->getGeneratorOptions()
				));
				return true;
			}
		}

		$onCompletion(self::searchLoadedChunks($world, $bedrockIds, $origin, $radius), false, false);
		return true;
	}

	/**
	 * Closest position (horizontally) in the world's loaded chunks whose biome is one of $bedrockIds, sampled every 4
	 * blocks at the origin's height and every {@link BiomeSearch::DEFAULT_VERTICAL_STEP} blocks above and below.
	 *
	 * @param int[] $bedrockIds
	 * @phpstan-param list<int> $bedrockIds
	 */
	public static function searchLoadedChunks(World $world, array $bedrockIds, Vector3 $origin, int $radius = BiomeSearch::DEFAULT_RADIUS) : ?Vector3{
		$wanted = [];
		foreach($bedrockIds as $id){
			$wanted[$id] = true;
		}
		$ys = BiomeSearch::sampleHeights($origin->getFloorY(), $world->getMinY(), $world->getMaxY() - 1, BiomeSearch::DEFAULT_VERTICAL_STEP);
		$best = null;
		$bestDistance = (float) $radius * $radius;
		foreach($world->getLoadedChunks() as $hash => $chunk){
			World::getXZ($hash, $chunkX, $chunkZ);
			for($lx = 0; $lx < Chunk::EDGE_LENGTH; $lx += 4){
				for($lz = 0; $lz < Chunk::EDGE_LENGTH; $lz += 4){
					$x = ($chunkX << Chunk::COORD_BIT_SIZE) + $lx;
					$z = ($chunkZ << Chunk::COORD_BIT_SIZE) + $lz;
					$distance = ($x - $origin->x) ** 2 + ($z - $origin->z) ** 2;
					if($distance > $bestDistance){
						continue;
					}
					foreach($ys as $y){
						if(isset($wanted[$chunk->getBiomeId($lx, $y, $lz)])){
							$best = new Vector3($x, $y, $z);
							$bestDistance = $distance;
							break;
						}
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Bedrock biome names (the client suggests them for /locate biome).
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getBedrockBiomeNames() : array{
		$names = [];
		foreach(Utils::stringifyKeys((new \ReflectionClass(BiomeIds::class))->getConstants()) as $constant => $value){
			$names[] = strtolower($constant);
		}
		return $names;
	}

	/**
	 * Every biome name /locate biome understands (Bedrock and Java Edition ids), for command suggestions.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getKnownBiomeNames() : array{
		$names = [];
		foreach(BiomeMapping::getJavaNames() as $name){
			$names[$name] = true;
		}
		foreach(Utils::stringifyKeys((new \ReflectionClass(BiomeIds::class))->getConstants()) as $constant => $value){
			$names[strtolower($constant)] = true;
		}
		return array_keys($names);
	}
}
