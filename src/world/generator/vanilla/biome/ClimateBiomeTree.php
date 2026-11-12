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

namespace pocketmine\world\generator\vanilla\biome;

use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function array_keys;
use function array_map;
use function count;
use function floor;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function log;
use function max;
use function min;
use function round;
use function usort;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Multi-noise biome lookup: nearest climate parameter point in vanilla's R-tree (7 dimensions: temperature,
 * humidity, continentalness, erosion, depth, weirdness, offset), with targets quantized to 1/10000 like vanilla.
 *
 * The tree is built from the biome parameter points with vanilla's algorithm (same node layout), so ties are resolved
 * in the same order. Nodes are stored in flat int arrays for the search loop.
 */
final class ClimateBiomeTree{
	private const DIMENSIONS = 7;
	private const CHILDREN_PER_NODE = 19;

	/**
	 * Per node: 7 x (min, max), 14 ints.
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $bounds = [];
	/**
	 * Children node indexes (empty for leaves).
	 * @var int[][]
	 * @phpstan-var list<list<int>>
	 */
	private array $children = [];
	/**
	 * Biome index for leaves, -1 for branches.
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	private array $leafBiome = [];
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $biomeNames = [];
	private int $lastLeaf = -1;

	/**
	 * @param mixed[] $tree
	 */
	public function __construct(array $tree){
		$names = [];
		$this->add($tree, $names);
	}

	/**
	 * Uses the tree saved by VanillaDataFetcher when there is one, otherwise builds it from the biome parameters.
	 */
	public static function fromData(WorldgenData $data, string $dimension = "overworld") : self{
		$saved = $data->biomeTree();
		if($saved !== null){
			$tree = $saved[$dimension] ?? null;
			if(!is_array($tree)){
				throw new \RuntimeException("No biome tree for $dimension");
			}
			return new self($tree);
		}
		return new self(self::buildTree(self::readParameterPoints($data->biomeParameters($dimension))));
	}

	/**
	 * A tree with a single biome (every search returns it), for dimensions without a multi-noise biome source.
	 */
	public static function single(string $biome) : self{
		$parameters = [];
		for($d = 0; $d < self::DIMENSIONS; ++$d){
			$parameters[] = ["min" => 0, "max" => 0];
		}
		return new self(["parameters" => $parameters, "_type" => "leaf", "biome" => $biome]);
	}

	/**
	 * Reads the biome parameter points from the data generator's biome_parameters report:
	 * {"biomes": [{"biome": id, "parameters": {temperature, humidity, continentalness, erosion, depth, weirdness, offset}}]}
	 * where each parameter is a number or a [min, max] pair (unquantized). Order is preserved: it decides ties.
	 *
	 * @param mixed[] $report
	 * @return mixed[][]
	 * @phpstan-return list<array{biome: string, parameters: list<array{min: int, max: int}>}>
	 */
	public static function readParameterPoints(array $report) : array{
		$points = [];
		foreach(WorldgenData::array($report, "biomes") as $entry){
			if(!is_array($entry)){
				throw new \RuntimeException("Invalid biome parameter entry");
			}
			$parameters = WorldgenData::array($entry, "parameters");
			$space = [];
			foreach(["temperature", "humidity", "continentalness", "erosion", "depth", "weirdness", "offset"] as $name){
				$value = $parameters[$name] ?? null;
				if(is_array($value) && count($value) === 2 && self::isNumber($value[0] ?? null) && self::isNumber($value[1] ?? null)){
					$space[] = ["min" => self::quantizeReportValue($value[0]), "max" => self::quantizeReportValue($value[1])];
				}elseif(self::isNumber($value)){
					$q = self::quantizeReportValue($value);
					$space[] = ["min" => $q, "max" => $q];
				}else{
					throw new \RuntimeException("Invalid biome parameter \"$name\"");
				}
			}
			$points[] = ["biome" => WorldgenData::string($entry, "biome"), "parameters" => $space];
		}
		if(count($points) === 0){
			throw new \RuntimeException("No biome parameter points");
		}
		return $points;
	}

	/**
	 * @phpstan-assert-if-true int|float $value
	 */
	private static function isNumber(mixed $value) : bool{
		return is_int($value) || is_float($value);
	}

	/**
	 * The report prints the stored quantized values divided by 10000 as floats; rounding recovers them exactly.
	 */
	private static function quantizeReportValue(int|float $value) : int{
		return (int) round($value * 10000.0);
	}

	/**
	 * Builds the search tree exactly as vanilla's Climate.RTree does (same buckets and child order, so that equal
	 * distances resolve to the same biome): nodes are returned in the layout accepted by the constructor.
	 *
	 * @phpstan-param list<array{biome: string, parameters: list<array{min: int, max: int}>}> $points
	 * @return mixed[]
	 */
	public static function buildTree(array $points) : array{
		$leaves = [];
		foreach($points as $point){
			$leaves[] = ["parameters" => $point["parameters"], "_type" => "leaf", "biome" => $point["biome"]];
		}
		return self::buildNode($leaves);
	}

	/**
	 * @phpstan-param list<array{parameters: list<array{min: int, max: int}>, _type: string, biome?: string, subTree?: list<mixed>}> $children
	 * @phpstan-return array{parameters: list<array{min: int, max: int}>, _type: string, biome?: string, subTree?: list<mixed>}
	 */
	private static function buildNode(array $children) : array{
		if(count($children) === 1){
			return $children[0];
		}
		if(count($children) <= self::CHILDREN_PER_NODE){
			$keys = [];
			foreach($children as $i => $child){
				$sum = 0;
				foreach($child["parameters"] as $p){
					$sum += abs(intdiv($p["min"] + $p["max"], 2));
				}
				$keys[$i] = $sum;
			}
			$order = array_keys($children);
			usort($order, fn(int $a, int $b) : int => $keys[$a] <=> $keys[$b]);
			return self::subTree(array_map(fn(int $i) => $children[$i], $order));
		}
		$minCost = PHP_INT_MAX;
		$minDimension = -1;
		$minBuckets = [];
		for($d = 0; $d < self::DIMENSIONS; ++$d){
			$children = self::sortNodes($children, $d, false);
			$buckets = self::bucketize($children);
			$cost = 0;
			foreach($buckets as $bucket){
				foreach($bucket["parameters"] as $p){
					$cost += abs($p["max"] - $p["min"]);
				}
			}
			if($minCost > $cost){
				$minCost = $cost;
				$minDimension = $d;
				$minBuckets = $buckets;
			}
		}
		$minBuckets = self::sortNodes($minBuckets, $minDimension, true);
		$result = [];
		foreach($minBuckets as $bucket){
			/** @phpstan-var list<array{parameters: list<array{min: int, max: int}>, _type: string, biome?: string, subTree?: list<mixed>}> $bucketChildren */
			$bucketChildren = $bucket["subTree"];
			$result[] = self::buildNode($bucketChildren);
		}
		return self::subTree($result);
	}

	/**
	 * Stable sort by the parameter centers, starting at $dimension and breaking ties with the following dimensions.
	 *
	 * @phpstan-template TNode of array{parameters: list<array{min: int, max: int}>}
	 * @phpstan-param list<TNode> $nodes
	 * @phpstan-return list<TNode>
	 */
	private static function sortNodes(array $nodes, int $dimension, bool $absolute) : array{
		$keys = [];
		foreach($nodes as $i => $node){
			$k = [];
			for($d = 0; $d < self::DIMENSIONS; ++$d){
				$p = $node["parameters"][($dimension + $d) % self::DIMENSIONS];
				$center = intdiv($p["min"] + $p["max"], 2);
				$k[] = $absolute ? abs($center) : $center;
			}
			$keys[$i] = $k;
		}
		$order = array_keys($nodes);
		usort($order, fn(int $a, int $b) : int => $keys[$a] <=> $keys[$b]);
		return array_map(fn(int $i) => $nodes[$i], $order);
	}

	/**
	 * @phpstan-param list<array{parameters: list<array{min: int, max: int}>, _type: string, biome?: string, subTree?: list<mixed>}> $nodes
	 * @phpstan-return list<array{parameters: list<array{min: int, max: int}>, _type: string, subTree: list<mixed>}>
	 */
	private static function bucketize(array $nodes) : array{
		$buckets = [];
		$children = [];
		$expected = (int) (self::CHILDREN_PER_NODE ** floor(log(count($nodes) - 0.01) / log(self::CHILDREN_PER_NODE)));
		foreach($nodes as $node){
			$children[] = $node;
			if(count($children) >= $expected){
				$buckets[] = self::subTree($children);
				$children = [];
			}
		}
		if(count($children) !== 0){
			$buckets[] = self::subTree($children);
		}
		return $buckets;
	}

	/**
	 * @phpstan-param list<array{parameters: list<array{min: int, max: int}>}> $children
	 * @phpstan-return array{parameters: list<array{min: int, max: int}>, _type: string, subTree: list<mixed>}
	 */
	private static function subTree(array $children) : array{
		$space = [];
		for($d = 0; $d < self::DIMENSIONS; ++$d){
			$min = PHP_INT_MAX;
			$max = PHP_INT_MIN;
			foreach($children as $child){
				$p = $child["parameters"][$d];
				$min = min($min, $p["min"]);
				$max = max($max, $p["max"]);
			}
			$space[] = ["min" => $min, "max" => $max];
		}
		return ["parameters" => $space, "_type" => "branch", "subTree" => $children];
	}

	/**
	 * @param mixed[]            $node
	 * @param array<string, int> $names
	 */
	private function add(array $node, array &$names) : int{
		$index = count($this->leafBiome);
		$params = WorldgenData::array($node, "parameters");
		if(count($params) !== 7){
			throw new \RuntimeException("Climate nodes must have 7 parameters");
		}
		foreach($params as $param){
			if(!is_array($param)){
				throw new \RuntimeException("Invalid climate parameter");
			}
			$this->bounds[] = WorldgenData::int($param, "min");
			$this->bounds[] = WorldgenData::int($param, "max");
		}
		$this->children[] = [];
		if(WorldgenData::string($node, "_type") === "leaf"){
			$biome = WorldgenData::string($node, "biome");
			if(!isset($names[$biome])){
				$names[$biome] = count($this->biomeNames);
				$this->biomeNames[] = $biome;
			}
			$this->leafBiome[] = $names[$biome];
			return $index;
		}
		$this->leafBiome[] = -1;
		$children = [];
		foreach(WorldgenData::array($node, "subTree") as $child){
			if(!is_array($child)){
				throw new \RuntimeException("Invalid climate tree node");
			}
			$children[] = $this->add($child, $names);
		}
		$this->children[$index] = $children;
		return $index;
	}

	/**
	 * Returns the biome index if the tree has a single biome (see single()), null otherwise.
	 */
	public function getSingleBiome() : ?int{
		return $this->leafBiome[0] >= 0 ? $this->leafBiome[0] : null;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getBiomeNames() : array{
		return $this->biomeNames;
	}

	/** Vanilla quantization: (long) ((float) value * 10000.0F) */
	public static function quantize(float $value) : int{
		$t = $value * 536870913.0;
		$f = $t - ($t - $value);
		$p = $f * 10000.0;
		$t = $p * 536870913.0;
		return (int) ($t - ($t - $p));
	}

	/**
	 * Returns the biome index (see getBiomeNames()) closest to the quantized target.
	 */
	public function search(int $temperature, int $humidity, int $continentalness, int $erosion, int $depth, int $weirdness) : int{
		if($this->leafBiome[0] >= 0){
			return $this->leafBiome[0];
		}
		$last = $this->lastLeaf;
		$best = $last < 0 ? PHP_INT_MAX : $this->distance($last, $temperature, $humidity, $continentalness, $erosion, $depth, $weirdness);
		$leaf = $this->searchNode(0, $temperature, $humidity, $continentalness, $erosion, $depth, $weirdness, $last, $best);
		$this->lastLeaf = $leaf;
		return $this->leafBiome[$leaf];
	}

	/**
	 * Like search(), but without the last-result hint and without changing it: the result never depends on earlier
	 * searches (vanilla's hint only changes which of several equally close biomes wins).
	 */
	public function searchIsolated(int $temperature, int $humidity, int $continentalness, int $erosion, int $depth, int $weirdness) : int{
		if($this->leafBiome[0] >= 0){
			return $this->leafBiome[0];
		}
		return $this->leafBiome[$this->searchNode(0, $temperature, $humidity, $continentalness, $erosion, $depth, $weirdness, -1, PHP_INT_MAX)];
	}

	private function distance(int $node, int $t0, int $t1, int $t2, int $t3, int $t4, int $t5) : int{
		$b = $this->bounds;
		$o = $node * 14;
		$d = $t0 - $b[$o + 1];
		$sum = $d > 0 ? $d * $d : (($d = $b[$o] - $t0) > 0 ? $d * $d : 0);
		$d = $t1 - $b[$o + 3];
		$sum += $d > 0 ? $d * $d : (($d = $b[$o + 2] - $t1) > 0 ? $d * $d : 0);
		$d = $t2 - $b[$o + 5];
		$sum += $d > 0 ? $d * $d : (($d = $b[$o + 4] - $t2) > 0 ? $d * $d : 0);
		$d = $t3 - $b[$o + 7];
		$sum += $d > 0 ? $d * $d : (($d = $b[$o + 6] - $t3) > 0 ? $d * $d : 0);
		$d = $t4 - $b[$o + 9];
		$sum += $d > 0 ? $d * $d : (($d = $b[$o + 8] - $t4) > 0 ? $d * $d : 0);
		$d = $t5 - $b[$o + 11];
		$sum += $d > 0 ? $d * $d : (($d = $b[$o + 10] - $t5) > 0 ? $d * $d : 0);
		$d = -$b[$o + 13];
		$sum += $d > 0 ? $d * $d : (($d = $b[$o + 12]) > 0 ? $d * $d : 0);
		return $sum;
	}

	/**
	 * Vanilla's RTree branch search: visit children closer than the best leaf found so far.
	 */
	private function searchNode(int $node, int $t0, int $t1, int $t2, int $t3, int $t4, int $t5, int $leaf, int $best) : int{
		$b = $this->bounds;
		$leafBiome = $this->leafBiome;
		foreach($this->children[$node] as $child){
			$o = $child * 14;
			$d = $t0 - $b[$o + 1];
			$sum = $d > 0 ? $d * $d : (($d = $b[$o] - $t0) > 0 ? $d * $d : 0);
			if($sum >= $best){
				continue;
			}
			$d = $t1 - $b[$o + 3];
			$sum += $d > 0 ? $d * $d : (($d = $b[$o + 2] - $t1) > 0 ? $d * $d : 0);
			$d = $t2 - $b[$o + 5];
			$sum += $d > 0 ? $d * $d : (($d = $b[$o + 4] - $t2) > 0 ? $d * $d : 0);
			$d = $t3 - $b[$o + 7];
			$sum += $d > 0 ? $d * $d : (($d = $b[$o + 6] - $t3) > 0 ? $d * $d : 0);
			if($sum >= $best){
				continue;
			}
			$d = $t4 - $b[$o + 9];
			$sum += $d > 0 ? $d * $d : (($d = $b[$o + 8] - $t4) > 0 ? $d * $d : 0);
			$d = $t5 - $b[$o + 11];
			$sum += $d > 0 ? $d * $d : (($d = $b[$o + 10] - $t5) > 0 ? $d * $d : 0);
			$d = -$b[$o + 13];
			$sum += $d > 0 ? $d * $d : (($d = $b[$o + 12]) > 0 ? $d * $d : 0);
			if($sum >= $best){
				continue;
			}
			if($leafBiome[$child] >= 0){
				$best = $sum;
				$leaf = $child;
			}else{
				$found = $this->searchNode($child, $t0, $t1, $t2, $t3, $t4, $t5, $leaf, $best);
				if($found !== $leaf){
					$best = $this->distance($found, $t0, $t1, $t2, $t3, $t4, $t5);
					$leaf = $found;
				}
			}
		}
		return $leaf;
	}
}
