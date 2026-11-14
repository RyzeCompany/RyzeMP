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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Utils;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\vanilla\BiomeMapping;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\placement\BedrockStructurePlacements;
use pocketmine\world\generator\vanilla\structure\placement\StructurePlacement;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\generator\vanilla\WorldgenData;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\mobs\spawn\StructureSpawnArea;
use pocketmine\world\mobs\spawn\StructureSpawnProvider;
use pocketmine\world\mobs\spawn\StructureSpawns;
use pocketmine\world\World;
use function array_key_exists;
use function count;
use function explode;
use function in_array;
use function trim;

/**
 * Structure spawn areas of vanilla worlds for the natural mob spawner: witches in swamp huts and pillagers around
 * pillager outposts (Bedrock's hard-coded spawn spots).
 *
 * Runs on the main thread, so it does not generate structure starts: it uses the structure placement (cheap) and the
 * biome of the already generated chunk at the structure's generation point. Swamp hut boxes are exact; outpost areas
 * are approximated by a 48 x 48 square around the start chunk.
 *
 * The mobs despawn like other monsters (Bedrock witch.json and pillager.json: despawn_from_distance) and count towards
 * the population of their spawn rule (witches "monster", pillagers "pillager"), so the caps stop the spawning.
 */
final class VanillaStructureSpawnProvider implements StructureSpawnProvider{
	private const MONUMENT_SET = "minecraft:ocean_monuments";
	private const FORTRESS_SET = "minecraft:nether_complexes";
	/**
	 * Java's fortress spawn_overrides (monster, bounding_box piece), Bedrock identifiers. Wither skeletons and skeletons
	 * use the monster light rule (brightness 7 or less, Monster.checkMonsterSpawnRules()); blazes, zombified piglins and
	 * magma cubes spawn at any light.
	 */
	private const FORTRESS_MOBS = [
		["minecraft:blaze", 10, 2, 3],
		["minecraft:zombie_pigman", 5, 4, 4],
		["minecraft:wither_skeleton", 8, 5, 5, self::MONSTER_MAX_BRIGHTNESS],
		["minecraft:skeleton", 2, 5, 5, self::MONSTER_MAX_BRIGHTNESS],
		["minecraft:magma_cube", 3, 4, 4]
	];
	private const MONSTER_MAX_BRIGHTNESS = 7;

	private static bool $registered = false;

	/** @phpstan-var array<string, StructureStart|null> "world:x:z" => fortress start (bounded) */
	private array $fortresses = [];

	/**
	 * Per world id: null for worlds without these structures, else [placements and biomes by kind, seed].
	 * @phpstan-var array<int, array{array<string, array{StructurePlacement, array<int, true>}>, int, \Closure(string, int, int, int) : bool}|null>
	 */
	private array $worlds = [];

	public static function register() : void{
		if(!self::$registered){
			self::$registered = true;
			StructureSpawns::register(new self());
		}
	}

	/**
	 * @phpstan-return array{array<string, array{StructurePlacement, array<int, true>}>, int, \Closure(string, int, int, int) : bool}|null
	 */
	private function load(World $world) : ?array{
		$worldData = $world->getProvider()->getWorldData();
		$entry = GeneratorManager::getInstance()->getGenerator($worldData->getGenerator());
		if($entry === null || $entry->getGeneratorClass() !== VanillaGenerator::class){
			return null;
		}
		try{
			$options = VanillaGenerator::parseOptions($worldData->getGeneratorOptions());
			if(in_array($options["structures"] ?? "true", ["false", "off", "0"], true)){
				return null;
			}
			$dir = VanillaGenerator::dataDirectory($options);
			$data = WorldgenData::shared($dir);
			$registry = StructureRegistry::shared($data, JavaBlocks::get($dir));
		}catch(\RuntimeException){
			return null;
		}
		$mode = ($options["structure-positions"] ?? "") === BedrockStructurePlacements::MODE_JAVA ? BedrockStructurePlacements::MODE_JAVA : BedrockStructurePlacements::MODE_BEDROCK;
		$disabled = [];
		foreach(explode(",", $options["disabled-structures"] ?? "", 256) as $id){
			$disabled[] = JavaBlocks::namespaced(trim($id));
		}
		$sets = $registry->getSets();
		$kinds = [];
		foreach(["swamp_hut" => "minecraft:swamp_huts", "pillager_outpost" => "minecraft:pillager_outposts"] as $structureName => $setId){
			$structure = $registry->getStructure($structureName);
			if($structure === null || !isset($sets[$setId]) || in_array($structure->id, $disabled, true) || in_array($setId, $disabled, true)){
				continue;
			}
			$biomes = [];
			foreach(Utils::stringifyKeys($structure->biomes) as $biome => $_){
				$biomes[BiomeMapping::toBedrock($biome)] = true;
			}
			$kinds[$structureName] = [BedrockStructurePlacements::select($setId, $sets[$setId][0], $mode), $biomes];
		}
		if(count($kinds) === 0){
			return null;
		}
		$villages = isset($sets["minecraft:villages"]) ? BedrockStructurePlacements::select("minecraft:villages", $sets["minecraft:villages"][0], $mode) : null;
		$seed = $worldData->getSeed();
		$hasStructureChunkInRange = function(string $setId, int $chunkX, int $chunkZ, int $range) use ($villages, $seed) : bool{
			if($villages === null || $setId !== "minecraft:villages"){
				return false;
			}
			return count($villages->placementChunksIn($seed, $chunkX - $range, $chunkZ - $range, $chunkX + $range, $chunkZ + $range)) > 0;
		};
		return [$kinds, $seed, $hasStructureChunkInRange];
	}

	/**
	 * Guardians in ocean monuments (Java: monster spawns over the whole structure box) and the Nether fortress mobs
	 * (Java: inside the fortress pieces). These starts are computed on the main thread with the world's structure
	 * manager (VanillaStructureLocator): fortress pieces are pure code; a monument start reads the terrain height at
	 * its chunk centre once (cached by the manager).
	 *
	 * @phpstan-return list<StructureSpawnArea>
	 */
	private function getManagerSpawnAreas(World $world, int $x, int $z) : array{
		$manager = VanillaStructureLocator::getStructureManager($world);
		if($manager === null){
			return [];
		}
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		$areas = [];
		$monuments = $manager->getPlacement(self::MONUMENT_SET);
		if($monuments !== null){
			$seed = $manager->env->seed;
			foreach($monuments->placementChunksIn($seed, $chunkX - 2, $chunkZ - 2, $chunkX + 2, $chunkZ + 2) as [$sx, $sz]){
				$start = $manager->getStart(self::MONUMENT_SET, $sx, $sz);
				$box = $start?->getPiecesBoundingBox();
				if($box !== null && $box->intersectsXZ($x, $z, $x, $z)){
					$areas[] = new StructureSpawnArea($box->minX, $box->minY, $box->minZ, $box->maxX, $box->maxY, $box->maxZ, [["minecraft:guardian", 1, 2, 4]]);
				}
			}
		}
		$fortresses = $manager->getPlacement(self::FORTRESS_SET);
		if($fortresses !== null){
			$seed = $manager->env->seed;
			foreach($fortresses->placementChunksIn($seed, $chunkX - 8, $chunkZ - 8, $chunkX + 8, $chunkZ + 8) as [$sx, $sz]){
				foreach($this->getFortress($world, $manager, $sx, $sz)->pieces ?? [] as $piece){
					$box = $piece->box;
					if($box->intersectsXZ($x, $z, $x, $z)){
						$areas[] = new StructureSpawnArea($box->minX, $box->minY, $box->minZ, $box->maxX, $box->maxY, $box->maxZ, self::FORTRESS_MOBS);
					}
				}
			}
		}
		return $areas;
	}

	/**
	 * The fortress start of a nether complex placement chunk, without generating bastions (jigsaw starts need terrain
	 * heights): the weighted pick of ChunkGenerator.tryGenerateStructure(); a picked bastion counts as valid when its
	 * biome allows it (its own placement checks are not run).
	 */
	private function getFortress(World $world, StructureManager $manager, int $chunkX, int $chunkZ) : ?StructureStart{
		$key = $world->getId() . ":$chunkX:$chunkZ";
		if(array_key_exists($key, $this->fortresses)){
			return $this->fortresses[$key];
		}
		if(count($this->fortresses) >= 64){
			$this->fortresses = [];
		}
		$start = null;
		$entries = $manager->getSetStructures(self::FORTRESS_SET);
		if(count($entries) > 0 && $manager->isStructureChunk(self::FORTRESS_SET, $chunkX, $chunkZ)){
			$random = StructureRandom::largeFeature($manager->env->seed, $chunkX, $chunkZ);
			$total = 0;
			foreach($entries as [, $weight]){
				$total += $weight;
			}
			$single = count($entries) === 1;
			while(count($entries) > 0){
				$index = 0;
				if(!$single){
					$roll = $random->nextBoundedInt($total);
					foreach($entries as [, $weight]){
						$roll -= $weight;
						if($roll < 0){
							break;
						}
						++$index;
					}
				}
				[$structure, $weight] = $entries[$index];
				if($structure->id === "minecraft:fortress"){
					$start = $manager->tryGenerate($structure, $chunkX, $chunkZ);
					break;
				}
				if($structure->isValidBiome($manager->env->getBiome($chunkX << 4, 33, $chunkZ << 4))){
					break;
				}
				unset($entries[$index]);
				$entries = [...$entries];
				$total -= $weight;
			}
		}
		return $this->fortresses[$key] = $start;
	}

	public function getSpawnAreas(World $world, int $x, int $z) : array{
		$id = $world->getId();
		if(!array_key_exists($id, $this->worlds)){
			$this->worlds[$id] = $this->load($world);
		}
		$info = $this->worlds[$id];
		$managerAreas = $this->getManagerSpawnAreas($world, $x, $z);
		if($info === null){
			return $managerAreas;
		}
		[$kinds, $seed, $hasStructureChunkInRange] = $info;
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		$areas = [];
		if(isset($kinds["swamp_hut"])){
			[$placement, $biomes] = $kinds["swamp_hut"];
			foreach($placement->placementChunksIn($seed, $chunkX - 1, $chunkZ - 1, $chunkX, $chunkZ) as [$sx, $sz]){
				if(!$placement->passesRestrictions($seed, $sx, $sz, $hasStructureChunkInRange)){
					continue;
				}
				$surface = $this->surfaceAt($world, ($sx << 4) + 8, ($sz << 4) + 8);
				if($surface === null || !isset($biomes[$world->getBiomeId(($sx << 4) + 8, $surface, ($sz << 4) + 8)])){
					continue;
				}
				//SwampHutPiece: random horizontal direction from the start random, 7 x 7 x 9 box at the chunk corner
				$direction = OrientedPiece::randomHorizontalDirection(StructureRandom::largeFeature($seed, $sx, $sz));
				$box = OrientedPiece::makeBoundingBox($sx << 4, $surface, $sz << 4, $direction, 7, 7, 9);
				$areas[] = new StructureSpawnArea($box->minX, $surface - 2, $box->minZ, $box->maxX, $surface + 8, $box->maxZ, [["minecraft:witch", 1, 1, 1]], SpawnCondition::POPULATION_MONSTER, false, self::MONSTER_MAX_BRIGHTNESS);
			}
		}
		if(isset($kinds["pillager_outpost"])){
			[$placement, $biomes] = $kinds["pillager_outpost"];
			foreach($placement->placementChunksIn($seed, $chunkX - 2, $chunkZ - 2, $chunkX + 2, $chunkZ + 2) as [$sx, $sz]){
				if(!$placement->passesRestrictions($seed, $sx, $sz, $hasStructureChunkInRange)){
					continue;
				}
				$surface = $this->surfaceAt($world, ($sx << 4) + 8, ($sz << 4) + 8);
				if($surface === null || !isset($biomes[$world->getBiomeId(($sx << 4) + 8, $surface, ($sz << 4) + 8)])){
					continue;
				}
				$areas[] = new StructureSpawnArea(($sx << 4) - 16, $surface - 5, ($sz << 4) - 16, ($sx << 4) + 31, $surface + 25, ($sz << 4) + 31, [["minecraft:pillager", 1, 1, 1]], SpawnCondition::POPULATION_PILLAGER);
			}
		}
		return [...$areas, ...$managerAreas];
	}

	private function surfaceAt(World $world, int $x, int $z) : ?int{
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return null;
		}
		return $world->getHighestBlockAt($x, $z);
	}
}
