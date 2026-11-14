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

use pocketmine\network\mcpe\protocol\types\DimensionIds;
use function count;
use function mt_rand;

/**
 * One entry of the "conditions" list of a Bedrock spawn rule (behavior_pack/spawn_rules/<mob>.json): where, when and
 * how a mob may spawn. A mob spawns if any of its conditions matches; each condition has its own weight and herd size.
 *
 * Supported components: spawns_on_surface, spawns_underground, spawns_underwater, spawns_lava, brightness_filter
 * (with adjust_for_weather), difficulty_filter, height_filter, distance_filter, density_limit, biome_filter,
 * spawns_on_block_filter, spawns_on_block_prevented_filter, disallow_spawns_in_bubble, weight, herd, permute_type,
 * spawn_event. Conditions with delay_filter, world_age_filter, mob_event_filter or player_in_village_filter (pillager
 * patrols) are "timed": they are spawned by their own spawner, not by the cluster spawning around players.
 */
final class SpawnCondition{
	public const DEFAULT_MIN_DISTANCE = 24;
	public const DEFAULT_MAX_DISTANCE = 44;

	public const POPULATION_MONSTER = "monster";
	public const POPULATION_ANIMAL = "animal";
	public const POPULATION_WATER_ANIMAL = "water_animal";
	public const POPULATION_AMBIENT = "ambient";
	public const POPULATION_CAT = "cat";
	public const POPULATION_PILLAGER = "pillager";

	/**
	 * @param SpawnHerd[]              $herds           one is picked at random for each spawn (vanilla horses list one per
	 *                                                  coat colour); empty means a single mob
	 * @param array<string, true>|null $onBlocks        Bedrock block names the mob must spawn on (null: any)
	 * @param array<string, true>      $preventedBlocks Bedrock block names the mob may not spawn on
	 * @param array[]                  $permuteTypes    [entity type or null for the rule's own, weight]
	 *
	 * @phpstan-param list<SpawnHerd> $herds
	 * @phpstan-param list<array{?string, int}> $permuteTypes
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly string $population,
		public readonly bool $onSurface,
		public readonly bool $underground,
		public readonly bool $underwater,
		public readonly bool $lava,
		public readonly int $weight,
		public readonly array $herds = [],
		public readonly ?int $brightnessMin = null,
		public readonly ?int $brightnessMax = null,
		public readonly bool $adjustForWeather = false,
		public readonly int $difficultyMin = 0,
		public readonly int $difficultyMax = 3,
		public readonly ?int $heightMin = null,
		public readonly ?int $heightMax = null,
		public readonly ?int $distanceMin = null,
		public readonly ?int $distanceMax = null,
		public readonly ?int $densitySurface = null,
		public readonly ?int $densityUnderground = null,
		public readonly mixed $biomeFilter = null,
		public readonly ?array $onBlocks = null,
		public readonly array $preventedBlocks = [],
		public readonly array $permuteTypes = [],
		public readonly ?string $spawnEvent = null,
		public readonly bool $disallowBubble = false,
		public readonly bool $timed = false
	){}

	/**
	 * Whether the rule allows a spot of this kind: in water only with spawns_underwater, above lava only with
	 * spawns_lava, then surface spots need spawns_on_surface and the others spawns_underground.
	 */
	public function matchesPlacement(SpawnSpot $spot) : bool{
		$ok = match($spot->medium){
			SpawnSpot::MEDIUM_WATER => $this->underwater,
			SpawnSpot::MEDIUM_LAVA => $this->lava,
			default => !$this->underwater && !$this->lava
		};
		return $ok && ($spot->surface ? $this->onSurface : $this->underground);
	}

	/**
	 * @param array<string, true> $tags biome tags => true
	 */
	public function matchesBiome(array $tags, bool $snowCovered) : bool{
		return BiomeFilter::test($this->biomeFilter, $tags, $snowCovered);
	}

	/**
	 * Checks everything except the biome (see {@link SpawnCondition::matchesBiome()}) and the population caps.
	 */
	public function matchesSpot(SpawnSpot $spot, SpawnContext $context) : bool{
		if($this->timed || !$this->matchesPlacement($spot)){
			return false;
		}
		if($context->difficulty < $this->difficultyMin || $context->difficulty > $this->difficultyMax){
			return false;
		}
		if(($this->heightMin !== null && $spot->y < $this->heightMin) || ($this->heightMax !== null && $spot->y > $this->heightMax)){
			return false;
		}
		$minDistance = $this->distanceMin ?? $context->minDistance;
		$maxDistance = $this->distanceMax ?? $context->maxDistance;
		if($spot->playerDistance < $minDistance || $spot->playerDistance > $maxDistance){
			return false;
		}
		if($this->getDensityLimit($spot->surface) === 0){
			return false;
		}
		if($this->brightnessMin !== null || $this->brightnessMax !== null){
			$brightness = $spot->getBrightness($this->adjustForWeather ? $context->skyReductionWeather : $context->skyReductionTime);
			if(($this->brightnessMin !== null && $brightness < $this->brightnessMin) || ($this->brightnessMax !== null && $brightness > $this->brightnessMax)){
				return false;
			}
			//Bedrock 1.18+: Overworld monsters only spawn where the block light is 0
			if($this->population === self::POPULATION_MONSTER && $context->dimension === DimensionIds::OVERWORLD &&
				$this->brightnessMax !== null && $this->brightnessMax <= 7 && $spot->blockLight > 0
			){
				return false;
			}
		}
		if($this->onBlocks !== null && !isset($this->onBlocks[$spot->blockBelow]) && !isset($this->onBlocks[$spot->blockAtFeet])){
			return false;
		}
		if(isset($this->preventedBlocks[$spot->blockBelow])){
			return false;
		}
		if($this->disallowBubble && $spot->blockAtFeet === "minecraft:bubble_column"){
			return false;
		}
		return true;
	}

	/**
	 * Density limit (maximum number of this mob in the area) for surface or underground spawns, or null if none.
	 */
	public function getDensityLimit(bool $surface) : ?int{
		return $surface ? $this->densitySurface : $this->densityUnderground;
	}

	public function pickHerd() : SpawnHerd{
		$count = count($this->herds);
		if($count === 0){
			return new SpawnHerd(1, 1);
		}
		return $this->herds[$count === 1 ? 0 : mt_rand(0, $count - 1)];
	}

	/**
	 * Picks the entity type of one spawned mob ("minecraft:permute_type": e.g. 5% of zombies are zombie villagers).
	 */
	public function pickEntityType() : string{
		if(count($this->permuteTypes) === 0){
			return $this->identifier;
		}
		$total = 0;
		foreach($this->permuteTypes as [, $weight]){
			$total += $weight;
		}
		if($total <= 0){
			return $this->identifier;
		}
		$roll = mt_rand(0, $total - 1);
		foreach($this->permuteTypes as [$type, $weight]){
			$roll -= $weight;
			if($roll < 0){
				return $type ?? $this->identifier;
			}
		}
		return $this->identifier;
	}
}
