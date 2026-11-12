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

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\type\FeatureTypes;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\noise\SimplexNoise;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_keys;
use function array_reverse;
use function count;
use function file_exists;
use function is_array;
use function is_string;
use function ksort;
use function str_starts_with;
use function usort;

/**
 * Feature data of a world: placed and configured features (parsed lazily from the vanilla data), the biome feature
 * lists, and the global feature order per decoration step (vanilla's FeatureSorter.buildFeaturesPerStep()), which
 * gives every feature the index that seeds its random.
 */
final class FeatureRegistry{
	/** Number of decoration steps (GenerationStep.Decoration). */
	public const STEPS = 11;

	/** @phpstan-var array<string, PlacedFeature> */
	private array $placed = [];
	/** @phpstan-var array<string, Feature> */
	private array $configured = [];

	/**
	 * Biome names in vanilla's possible-biomes order of the dimension's biome source (overworld: first appearance in
	 * the parameter list).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $biomeNames = [];
	/** @phpstan-var array<string, int> */
	public array $biomeIndex = [];
	/**
	 * Placed feature ids per biome index and step.
	 * @phpstan-var array<int, list<list<string>>>
	 */
	private array $biomeSteps = [];
	/** @phpstan-var array<int, array<string, true>> */
	private array $biomeFeatureSet = [];
	/**
	 * Features of each step in global order (FeatureSorter).
	 * @phpstan-var list<list<string>>
	 */
	public array $stepFeatures = [];
	/**
	 * Indexes (into stepFeatures) of the features of each biome and step, sorted.
	 * @phpstan-var array<int, list<list<int>>>
	 */
	public array $biomeStepIndexes = [];
	/** @phpstan-var array<int, mixed[]> raw biome JSON per biome index */
	private array $biomeJson = [];

	private SimplexNoise $biomeInfoNoise;
	private ?BiomeClimate $climate = null;
	/** @phpstan-var array<string, bool> */
	private array $warned = [];
	/** @phpstan-var \Closure(string) : void */
	private \Closure $logger;

	/**
	 * @param string[]|null $possibleBiomes biomes of the dimension in the biome source's order (default: the overworld's)
	 * @phpstan-param (\Closure(string) : void)|null $logger
	 * @phpstan-param list<string>|null $possibleBiomes
	 */
	public function __construct(
		public readonly WorldgenData $data,
		public readonly JavaBlocks $blocks,
		?\Closure $logger = null,
		?array $possibleBiomes = null
	){
		$this->logger = $logger ?? function(string $message) : void{};
		$this->biomeInfoNoise = new SimplexNoise(new LegacyRandom(2345));

		foreach($possibleBiomes ?? self::multiNoiseBiomes($data, "overworld") as $biome){
			if(!isset($this->biomeIndex[$biome])){
				$this->biomeIndex[$biome] = count($this->biomeNames);
				$this->biomeNames[] = $biome;
			}
		}
		foreach($this->biomeNames as $index => $name){
			$json = $data->biome($name);
			$this->biomeJson[$index] = $json;
			$steps = [];
			$set = [];
			foreach(is_array($json["features"] ?? null) ? $json["features"] : [] as $step){
				$list = [];
				foreach(is_array($step) ? $step : [$step] as $id){
					if(is_string($id)){
						$id = JavaBlocks::namespaced($id);
						$list[] = $id;
						$set[$id] = true;
					}
				}
				$steps[] = $list;
			}
			$this->biomeSteps[$index] = $steps;
			$this->biomeFeatureSet[$index] = $set;
		}
		$this->sortFeatures();
	}

	/**
	 * Biomes of a multi-noise biome source in its possible-biomes order: first appearance in the parameter list.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function multiNoiseBiomes(WorldgenData $data, string $dimension) : array{
		return $data->multiNoiseBiomes($dimension);
	}

	/**
	 * Port of FeatureSorter.buildFeaturesPerStep(): a topological order of all features consistent with the order of
	 * every biome's feature list, ties broken by (step, first appearance).
	 */
	private function sortFeatures() : void{
		$globalIds = [];
		$keys = []; //key => [step, featureIndex, id]
		$edges = []; //key => set of keys
		$maxSteps = 0;
		foreach($this->biomeNames as $index => $_){
			$steps = $this->biomeSteps[$index];
			$maxSteps = $steps !== [] && count($steps) > $maxSteps ? count($steps) : $maxSteps;
			$list = [];
			foreach($steps as $step => $ids){
				foreach($ids as $id){
					$globalIds[$id] ??= count($globalIds);
					$key = $step * 1000000 + $globalIds[$id];
					$keys[$key] = [$step, $globalIds[$id], $id];
					$list[] = $key;
				}
			}
			for($i = 0, $n = count($list); $i < $n; ++$i){
				$edges[$list[$i]] ??= [];
				if($i < $n - 1){
					$edges[$list[$i]][$list[$i + 1]] = true;
				}
			}
		}
		ksort($edges);
		foreach($edges as $key => $targets){
			ksort($targets);
			$edges[$key] = $targets;
		}
		$visited = [];
		$inProgress = [];
		$sorted = [];
		$dfs = function(int $key) use (&$dfs, &$visited, &$inProgress, &$sorted, $edges) : bool{
			if(isset($inProgress[$key])){
				return true; //cycle
			}
			if(isset($visited[$key])){
				return false;
			}
			$inProgress[$key] = true;
			foreach($edges[$key] ?? [] as $next => $_){
				if($dfs($next)){
					return true;
				}
			}
			unset($inProgress[$key]);
			$visited[$key] = true;
			$sorted[] = $key;
			return false;
		};
		foreach(array_keys($edges) as $key){
			if(isset($visited[$key])){
				continue;
			}
			if($dfs($key)){
				throw new \RuntimeException("Feature order cycle found");
			}
		}
		$sorted = array_reverse($sorted);

		$this->stepFeatures = [];
		$stepIndex = [];
		for($step = 0; $step < $maxSteps; ++$step){
			$list = [];
			foreach($sorted as $key){
				if($keys[$key][0] === $step){
					$stepIndex[$step][$keys[$key][2]] = count($list);
					$list[] = $keys[$key][2];
				}
			}
			$this->stepFeatures[] = $list;
		}
		foreach($this->biomeSteps as $biome => $steps){
			$indexes = [];
			for($step = 0; $step < $maxSteps; ++$step){
				$set = [];
				foreach($steps[$step] ?? [] as $id){
					$set[$stepIndex[$step][$id]] = true;
				}
				$list = array_keys($set);
				usort($list, fn(int $a, int $b) : int => $a <=> $b);
				$indexes[] = $list;
			}
			$this->biomeStepIndexes[$biome] = $indexes;
		}
	}

	public function biomeHasFeature(int $biome, string $id) : bool{
		return isset($this->biomeFeatureSet[$biome][$id]);
	}

	/**
	 * Biome holder set test (a biome id, a list of ids or a biome tag, which is not supported and never matches).
	 */
	public function biomeInSet(int $biome, mixed $set) : bool{
		$name = $this->biomeNames[$biome] ?? null;
		if(is_string($set)){
			return !str_starts_with($set, "#") && JavaBlocks::namespaced($set) === $name;
		}
		if(is_array($set)){
			foreach($set as $entry){
				if(is_string($entry) && JavaBlocks::namespaced($entry) === $name){
					return true;
				}
			}
		}
		return false;
	}

	/** @return mixed[] */
	public function biomeJson(int $biome) : array{
		return $this->biomeJson[$biome] ?? [];
	}

	/**
	 * Biome.BIOME_INFO_NOISE.get(x, z): a float in vanilla 26.x.
	 */
	public function biomeInfoNoise(float $x, float $z) : float{
		return FloatMath::fround($this->biomeInfoNoise->getValue2D($x, $z));
	}

	public function climate() : BiomeClimate{
		return $this->climate ??= new BiomeClimate($this);
	}

	public function placed(string $id) : PlacedFeature{
		$id = JavaBlocks::namespaced($id);
		if(isset($this->placed[$id])){
			return $this->placed[$id];
		}
		$json = $this->data->readObject("data/minecraft/worldgen/placed_feature/" . WorldgenData::stripNamespace($id) . ".json");
		return $this->placed[$id] = $this->parsePlaced($id, $json);
	}

	/**
	 * A placed feature field: a registry id or an inline {"feature": ..., "placement": [...]}.
	 */
	public function placedRef(mixed $json) : PlacedFeature{
		if(is_string($json)){
			return $this->placed($json);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid placed feature");
		}
		return $this->parsePlaced("inline", $json);
	}

	/**
	 * @param mixed[] $json
	 */
	private function parsePlaced(string $id, array $json) : PlacedFeature{
		$feature = $this->configuredRef($json["feature"] ?? null);
		$modifiers = [];
		foreach(is_array($json["placement"] ?? null) ? $json["placement"] : [] as $modifier){
			$modifiers[] = Placements::parse($this, $modifier);
		}
		return new PlacedFeature($id, $feature, $modifiers);
	}

	/**
	 * A configured feature field: a registry id or an inline {"type": ..., ...}.
	 */
	public function configuredRef(mixed $json) : Feature{
		if(is_string($json)){
			$id = JavaBlocks::namespaced($json);
			if(isset($this->configured[$id])){
				return $this->configured[$id];
			}
			$path = "data/minecraft/worldgen/feature/" . WorldgenData::stripNamespace($id) . ".json";
			if(!file_exists($this->data->getRoot() . "/" . $path)){
				throw new \RuntimeException("Unknown configured feature $id");
			}
			return $this->configured[$id] = $this->parseConfigured($this->data->readObject($path), $id);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid configured feature");
		}
		return $this->parseConfigured($json, "inline");
	}

	/**
	 * @param mixed[] $json
	 */
	private function parseConfigured(array $json, string $id) : Feature{
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		try{
			$feature = FeatureTypes::create($this, $type, $json);
		}catch(\RuntimeException $e){
			$this->warn("feature:$type", "Vanilla feature $id ($type) is not supported: " . $e->getMessage());
			$feature = null;
		}
		if($feature === null){
			$this->warn("type:$type", "Vanilla feature type $type is not implemented yet; features of this type are skipped");
			return new type\NoOpFeature();
		}
		return $feature;
	}

	/**
	 * Block state provider field: a registry id (worldgen/block_state_provider), an inline provider {"type": ...} or a
	 * plain block state.
	 * @phpstan-return \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int
	 */
	public function stateProvider(mixed $json) : \Closure{
		if(is_string($json)){
			$id = JavaBlocks::namespaced($json);
			$path = "data/minecraft/worldgen/block_state_provider/" . WorldgenData::stripNamespace($id) . ".json";
			if(file_exists($this->data->getRoot() . "/" . $path)){
				return StateProviders::parse($this, $this->data->readObject($path));
			}
			$state = $this->blocks->parseState($json);
			return fn(FeatureContext $c, FeatureRandom $r, int $x, int $y, int $z) : int => $state;
		}
		return StateProviders::parse($this, $json);
	}

	public function warn(string $key, string $message) : void{
		if(!isset($this->warned[$key])){
			$this->warned[$key] = true;
			($this->logger)($message);
		}
	}
}
