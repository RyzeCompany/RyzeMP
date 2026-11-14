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

namespace pocketmine\world\mobs\spawn;

use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * A set of spawn rules (see {@link SpawnCondition}), indexed by biome. The vanilla set is read from Mojang's files
 * (see {@link VanillaSpawnRules}); {@link SpawnRules::builtin()} is a small fallback used until they are available.
 */
final class SpawnRules{
	/** Biomes where snow falls (is_snow_covered): base temperature below this. */
	public const SNOW_TEMPERATURE = 0.15;

	/**
	 * Populations of the structure mobs (vanilla rule files without conditions), so that the built-in rules count them
	 * towards their caps too.
	 */
	private const BUILTIN_POPULATIONS = [
		EntityIds::WITCH => SpawnCondition::POPULATION_MONSTER,
		EntityIds::PILLAGER => SpawnCondition::POPULATION_PILLAGER,
		EntityIds::GUARDIAN => SpawnCondition::POPULATION_MONSTER,
		EntityIds::BLAZE => SpawnCondition::POPULATION_MONSTER,
		EntityIds::ZOMBIE_PIGMAN => SpawnCondition::POPULATION_MONSTER,
		EntityIds::WITHER_SKELETON => SpawnCondition::POPULATION_MONSTER,
		EntityIds::MAGMA_CUBE => SpawnCondition::POPULATION_MONSTER,
		EntityIds::CAT => SpawnCondition::POPULATION_CAT,
	];

	/**
	 * @var SpawnCondition[][] biome ID => conditions allowed there
	 * @phpstan-var array<int, list<SpawnCondition>>
	 */
	private array $byBiome = [];

	/**
	 * @param SpawnCondition[] $conditions
	 * @param string[]         $populations entity identifier => population control, including mobs without
	 *                                      conditions (e.g. "minecraft:cat" => "cat")
	 *
	 * @phpstan-param list<SpawnCondition> $conditions
	 * @phpstan-param array<string, string> $populations
	 */
	public function __construct(
		private array $conditions,
		private array $populations,
		private string $source
	){
		foreach($conditions as $condition){
			$this->populations[$condition->identifier] ??= $condition->population;
			foreach($condition->permuteTypes as [$type, ]){
				if($type !== null){
					$this->populations[$type] ??= $condition->population;
				}
			}
		}
	}

	/**
	 * Where the rules come from (for logs), e.g. "Mojang/bedrock-samples v1.26.50.4".
	 */
	public function getSource() : string{
		return $this->source;
	}

	/**
	 * @return SpawnCondition[]
	 * @phpstan-return list<SpawnCondition>
	 */
	public function getConditions() : array{
		return $this->conditions;
	}

	/**
	 * Returns the population control of the mob ("monster", "animal", "water_animal", "ambient"...), or null if it has
	 * no spawn rule (it then counts towards no population cap).
	 */
	public function getPopulation(string $identifier) : ?string{
		return $this->populations[$identifier] ?? null;
	}

	/**
	 * @return string[]
	 * @phpstan-return array<string, string>
	 */
	public function getPopulations() : array{
		return $this->populations;
	}

	/**
	 * Returns the conditions whose biome filter accepts the biome (cached per biome).
	 *
	 * @return SpawnCondition[]
	 * @phpstan-return list<SpawnCondition>
	 */
	public function forBiome(int $biomeId) : array{
		if(isset($this->byBiome[$biomeId])){
			return $this->byBiome[$biomeId];
		}
		$tags = [];
		foreach(MobBiomeInfo::getTagsById($biomeId) as $tag){
			$tags[$tag] = true;
		}
		return $this->byBiome[$biomeId] = $this->forBiomeTags($tags, MobBiomeInfo::getTemperatureById($biomeId) < self::SNOW_TEMPERATURE);
	}

	/**
	 * @param array<string, true> $tags
	 *
	 * @return SpawnCondition[]
	 * @phpstan-return list<SpawnCondition>
	 */
	public function forBiomeTags(array $tags, bool $snowCovered) : array{
		$result = [];
		foreach($this->conditions as $condition){
			if(!$condition->timed && $condition->weight > 0 && $condition->matchesBiome($tags, $snowCovered)){
				$result[] = $condition;
			}
		}
		return $result;
	}

	/**
	 * Fallback rules used while Mojang's spawn rules are not available (no network, or still downloading): the
	 * common Overworld mobs with their vanilla Bedrock values (zombie, skeleton, creeper, spider, cow, pig, sheep,
	 * chicken).
	 */
	public static function builtin() : self{
		$animal = fn(string $id, int $weight, int $min, int $max) => new SpawnCondition(
			identifier: $id,
			population: SpawnCondition::POPULATION_ANIMAL,
			onSurface: true,
			underground: false,
			underwater: false,
			lava: false,
			weight: $weight,
			herds: [new SpawnHerd($min, $max)],
			brightnessMin: 7,
			brightnessMax: 15,
			biomeFilter: ["test" => "has_biome_tag", "value" => "animal"],
			onBlocks: ["minecraft:grass_block" => true]
		);
		return new self([
			self::builtinMonster(EntityIds::ZOMBIE, 100, 2, 4, null, [[null, 95], [EntityIds::ZOMBIE_VILLAGER_V2, 5]]),
			self::builtinMonster(EntityIds::SKELETON, 80, 1, 2),
			self::builtinMonster(EntityIds::CREEPER, 100, 1, 1, 5),
			self::builtinMonster(EntityIds::SPIDER, 100, 1, 1),
			$animal(EntityIds::SHEEP, 12, 2, 3),
			$animal(EntityIds::PIG, 10, 1, 3),
			$animal(EntityIds::CHICKEN, 10, 2, 4),
			$animal(EntityIds::COW, 8, 2, 3),
		], self::BUILTIN_POPULATIONS, "built-in");
	}

	/**
	 * @phpstan-param list<array{?string, int}> $permute
	 */
	private static function builtinMonster(string $id, int $weight, int $min, int $max, ?int $density = null, array $permute = []) : SpawnCondition{
		return new SpawnCondition(
			identifier: $id,
			population: SpawnCondition::POPULATION_MONSTER,
			onSurface: true,
			underground: true,
			underwater: false,
			lava: false,
			weight: $weight,
			herds: [new SpawnHerd($min, $max)],
			brightnessMin: 0,
			brightnessMax: 7,
			adjustForWeather: true,
			difficultyMin: 1,
			densitySurface: $density,
			biomeFilter: ["test" => "has_biome_tag", "value" => "monster"],
			permuteTypes: $permute
		);
	}
}
