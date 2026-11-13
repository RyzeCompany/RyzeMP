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

namespace pocketmine\world\generator\vanilla\feature\block;

use pocketmine\block\Block;
use pocketmine\block\BrushableBlock;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\TrialSpawner;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Vault;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\dimension\portal\block\EndGateway;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_reverse;
use function array_search;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function max;
use function scandir;
use function str_ends_with;
use function str_starts_with;
use function strpbrk;
use function strpos;
use function substr;
use function zlib_decode;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

/**
 * Java Edition block states (ids, properties, tags and the physical properties the features use), and their mapping
 * to PocketMine block states through GeyserMC's Java -> Bedrock mappings.
 *
 * Java state ids are the ones of the Java registry (blocks report), so they are stable for a given Minecraft version
 * and can be used as plain ints everywhere in the feature engine.
 *
 * One instance per data directory and thread (see {@link get()}): the worker threads each build their own.
 */
final class JavaBlocks{
	public const FLUID_NONE = 0;
	public const FLUID_WATER = 1;
	public const FLUID_LAVA = 2;

	/** @phpstan-var array<string, self> */
	private static array $instances = [];

	/**
	 * Block name (with namespace) per block index.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $names = [];
	/** @phpstan-var array<string, int> */
	private array $blockByName = [];
	/** @phpstan-var list<int> first state id per block index */
	public array $firstState = [];
	/** @phpstan-var list<int> default state id per block index */
	public array $defaultState = [];
	/**
	 * Property names per block index, in state order.
	 * @phpstan-var list<list<string>>
	 */
	private array $propertyNames = [];
	/**
	 * Property values per block index and property.
	 * @phpstan-var list<list<list<string>>>
	 */
	private array $propertyValues = [];
	/**
	 * State id stride of each property per block index (product of the value counts of the following properties).
	 * @phpstan-var list<list<int>>
	 */
	private array $strides = [];
	/**
	 * Explicit property value indexes of blocks whose states are not in plain cartesian order.
	 * @phpstan-var array<int, array<int, list<int>>>
	 */
	private array $explicitStates = [];
	/**
	 * Block index per state id.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	public array $blockOf = [];

	/**
	 * Resolved block tags: tag name (with namespace, without #) => block index => true.
	 * @phpstan-var array<string, array<int, true>>
	 */
	private array $tags = [];
	/** @phpstan-var array<string, list<string>> raw tag values, resolved on first use */
	private array $rawTags = [];
	/** @phpstan-var array<string, array<string, true>> */
	private array $fluidTags = [];

	/**
	 * Geyser Bedrock state per Java state id, see {@link JavaBlocks::bedrockState()}. Each distinct Bedrock state is kept
	 * once (about 16k for 36k Java states) with its states encoded in a string: the Tags of every Java state took about
	 * 36 MB per thread.
	 * @phpstan-var list<int> Java state id => index of its distinct Bedrock state
	 */
	private array $bedrockStateOf = [];
	/** @phpstan-var list<int> distinct Bedrock state => index in {@link JavaBlocks::$bedrockNames} */
	private array $bedrockStateName = [];
	/**
	 * Distinct Bedrock state => its states, encoded by {@link JavaBlocks::encodeBedrockStates()} (or the Tags themselves
	 * when they can't be encoded).
	 * @phpstan-var list<string|array<string, Tag>>
	 */
	private array $bedrockStateData = [];
	/** @phpstan-var list<string> distinct Bedrock block names */
	private array $bedrockNames = [];
	/** @phpstan-var array<int, int> Java state id => PocketMine state id */
	private array $toPm = [];
	/** @phpstan-var array<int, bool> Java state id => needs water in the liquid layer */
	private array $pmWater = [];
	/** @phpstan-var array<int, int> PocketMine state id => Java state id (dry) */
	private array $fromPm = [];
	/** @phpstan-var array<int, int> PocketMine state id => Java state id (with water in the liquid layer) */
	private array $fromPmWet = [];
	/**
	 * Bedrock state key => Java state ids mapping to it, built on first reverse lookup.
	 * @phpstan-var array<string, list<int>>|null
	 */
	private ?array $bedrockIndex = null;

	/** @var int[] collision shape index per state id */
	private array $collisionIndex = [];
	/**
	 * Collision boxes per shape index: list of [minX, minY, minZ, maxX, maxY, maxZ].
	 * @phpstan-var list<list<array{float, float, float, float, float, float}>>
	 */
	private array $shapes = [];

	/** @phpstan-var array<int, bool> */
	private array $solidCache = [];
	/** @phpstan-var array<int, int> */
	private array $fluidCache = [];
	/** @phpstan-var array<int, bool> (state * 6 + face) => sturdy */
	private array $sturdyCache = [];
	/** @phpstan-var array<int, bool> */
	private array $fullCubeCache = [];
	/** @phpstan-var array<string, array<int, true>> tag sets by the name as written by callers */
	private array $tagsByRawName = [];

	public readonly int $air;
	public readonly int $caveAir;
	public readonly int $voidAir;
	public readonly int $water;
	public readonly int $lava;
	public readonly int $airBlock;
	public readonly int $caveAirBlock;
	public readonly int $voidAirBlock;
	public readonly int $waterBlock;
	public readonly int $lavaBlock;
	private int $pmAir;
	private int $pmWaterState;

	public static function get(string $dataDir, bool $hasStructureContent = false) : self{
		return self::$instances[$dataDir . "\0" . (int) $hasStructureContent] ??= new self($dataDir, $hasStructureContent);
	}

	private function __construct(private string $dataDir, public readonly bool $hasStructureContent){
		$json = json_decode(Filesystem::fileGetContents($dataDir . "/" . VanillaDataFetcher::JAVA_BLOCKS_FILE), true, 16, JSON_THROW_ON_ERROR);
		if(!is_array($json) || !is_array($json["blocks"] ?? null)){
			throw new \RuntimeException("Invalid " . VanillaDataFetcher::JAVA_BLOCKS_FILE);
		}
		$maxState = 0;
		foreach(array_values($json["blocks"]) as $index => $entry){
			if(!is_array($entry) || !is_string($entry[0] ?? null) || !is_int($entry[1] ?? null) || !is_int($entry[2] ?? null) || !is_array($entry[3] ?? null)){
				throw new \RuntimeException("Invalid block entry in " . VanillaDataFetcher::JAVA_BLOCKS_FILE);
			}
			$name = $entry[0];
			$this->names[] = $name;
			$this->blockByName[$name] = $index;
			$this->firstState[] = $entry[1];
			$this->defaultState[] = $entry[2];
			$names = [];
			$values = [];
			foreach($entry[3] as $property){
				if(!is_array($property) || !is_string($property[0] ?? null) || !is_array($property[1] ?? null)){
					throw new \RuntimeException("Invalid property of $name");
				}
				$names[] = $property[0];
				$list = [];
				foreach($property[1] as $value){
					$list[] = is_string($value) ? $value : throw new \RuntimeException("Invalid property value of $name");
				}
				$values[] = $list;
			}
			$reversed = [];
			$stride = 1;
			for($i = count($values) - 1; $i >= 0; --$i){
				$reversed[] = $stride;
				$stride *= count($values[$i]);
			}
			$strides = array_reverse($reversed);
			$this->propertyNames[] = $names;
			$this->propertyValues[] = $values;
			$this->strides[] = $strides;
			if(isset($entry[4]) && is_array($entry[4])){
				$explicit = [];
				foreach(Utils::promoteKeys($entry[4]) as $state){
					if(!is_array($state) || !is_int($state[0] ?? null) || !is_array($state[1] ?? null)){
						throw new \RuntimeException("Invalid explicit state of $name");
					}
					$indexes = [];
					foreach($state[1] as $v){
						$indexes[] = is_int($v) ? $v : 0;
					}
					$explicit[$state[0]] = $indexes;
				}
				$this->explicitStates[$index] = $explicit;
			}
			$maxState = max($maxState, $entry[1] + $stride - 1);
		}
		$blockOf = [];
		for($s = 0; $s <= $maxState; ++$s){
			$blockOf[] = 0;
		}
		foreach($this->firstState as $index => $first){
			$count = 1;
			foreach($this->propertyValues[$index] as $values){
				$count *= count($values);
			}
			for($s = $first, $end = $first + $count; $s < $end; ++$s){
				$blockOf[$s] = $index;
			}
		}
		$this->blockOf = $blockOf;

		$this->airBlock = $this->blockIndex("minecraft:air");
		$this->caveAirBlock = $this->blockIndex("minecraft:cave_air");
		$this->voidAirBlock = $this->blockIndex("minecraft:void_air");
		$this->waterBlock = $this->blockIndex("minecraft:water");
		$this->lavaBlock = $this->blockIndex("minecraft:lava");
		$this->air = $this->defaultState[$this->airBlock];
		$this->caveAir = $this->defaultState[$this->caveAirBlock];
		$this->voidAir = $this->defaultState[$this->voidAirBlock];
		$this->water = $this->defaultState[$this->waterBlock];
		$this->lava = $this->defaultState[$this->lavaBlock];
		$this->pmAir = VanillaBlocks::AIR()->getStateId();
		$this->pmWaterState = VanillaBlocks::WATER()->getStillForm()->getStateId();

		$this->loadTags($dataDir . "/data/minecraft/tags/block", "");
		foreach(["water", "lava"] as $fluidTag){
			$path = $dataDir . "/data/minecraft/tags/fluid/$fluidTag.json";
			if(is_file($path)){
				$fluids = [];
				foreach($this->readTagValues($path) as $value){
					$fluids[$value] = true;
				}
				$this->fluidTags["minecraft:" . $fluidTag] = $fluids;
			}
		}
		$this->loadGeyser($dataDir . "/" . VanillaDataFetcher::GEYSER_DIR);
	}

	private function loadTags(string $dir, string $prefix) : void{
		if(!is_dir($dir)){
			throw new \RuntimeException("Missing block tags in $dir");
		}
		$files = scandir($dir);
		foreach($files !== false ? $files : [] as $file){
			if($file === "." || $file === ".."){
				continue;
			}
			$path = $dir . "/" . $file;
			if(is_dir($path)){
				$this->loadTags($path, $prefix . $file . "/");
			}elseif(str_ends_with($file, ".json")){
				$this->rawTags["minecraft:" . $prefix . substr($file, 0, -5)] = $this->readTagValues($path);
			}
		}
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function readTagValues(string $path) : array{
		$json = json_decode(Filesystem::fileGetContents($path), true, 8, JSON_THROW_ON_ERROR);
		$values = [];
		foreach(is_array($json) && is_array($json["values"] ?? null) ? $json["values"] : [] as $value){
			if(is_array($value)){
				$value = $value["id"] ?? null; //{"id": ..., "required": false}
			}
			if(is_string($value)){
				$values[] = $value;
			}
		}
		return $values;
	}

	private function loadGeyser(string $dir) : void{
		$blocks = (new BigEndianNbtSerializer())->read(self::decompress(Filesystem::fileGetContents($dir . "/blocks.nbt")))->mustGetCompoundTag();
		$mappings = $blocks->getListTag("bedrock_mappings") ?? throw new \RuntimeException("Invalid Geyser blocks.nbt");
		$javaId = 0;
		$nameIndexes = [];
		$stateIndexes = [];
		foreach($mappings as $mapping){
			if(!$mapping instanceof CompoundTag){
				throw new \RuntimeException("Invalid Geyser block mapping");
			}
			$block = $this->blockOf[$javaId] ?? throw new \RuntimeException("Geyser blocks.nbt has more states than the Java block list");
			$name = $mapping->getString("bedrock_identifier", "");
			if($name === ""){
				$name = $this->names[$block];
			}elseif(strpos($name, ":") === false){
				$name = "minecraft:" . $name;
			}
			$states = [];
			$stateTag = $mapping->getCompoundTag("state");
			if($stateTag !== null){
				foreach(Utils::stringifyKeys($stateTag->getValue()) as $key => $tag){
					$states[$key] = $tag;
				}
				ksort($states);
			}
			$nameIndex = $nameIndexes[$name] ?? null;
			if($nameIndex === null){
				$nameIndex = $nameIndexes[$name] = count($this->bedrockNames);
				$this->bedrockNames[] = $name;
			}
			$encoded = self::encodeBedrockStates($states);
			$key = $encoded !== null ? $nameIndex . "\x00" . $encoded : null;
			$stateIndex = $key !== null ? ($stateIndexes[$key] ?? null) : null;
			if($stateIndex === null){
				$stateIndex = count($this->bedrockStateData);
				if($key !== null){
					$stateIndexes[$key] = $stateIndex;
				}
				$this->bedrockStateName[] = $nameIndex;
				$this->bedrockStateData[] = $encoded ?? $states;
			}
			$this->bedrockStateOf[] = $stateIndex;
			$javaId++;
		}

		$collisions = (new BigEndianNbtSerializer())->read(self::decompress(Filesystem::fileGetContents($dir . "/collisions.nbt")))->mustGetCompoundTag();
		$indices = $collisions->getIntArray("indices");
		$this->collisionIndex = $indices;
		$shapeList = $collisions->getListTag("shapes") ?? throw new \RuntimeException("Invalid Geyser collisions.nbt");
		foreach($shapeList as $shape){
			$boxes = [];
			if($shape instanceof ListTag){
				foreach($shape as $box){
					if($box instanceof ListTag && $box->count() === 6){
						$v = [];
						foreach($box as $component){
							$value = $component->getValue();
							$v[] = is_float($value) || is_int($value) ? (float) $value : 0.0;
						}
						//center + size
						$boxes[] = [
							$v[0] - $v[3] / 2, $v[1] - $v[4] / 2, $v[2] - $v[5] / 2,
							$v[0] + $v[3] / 2, $v[1] + $v[4] / 2, $v[2] + $v[5] / 2,
						];
					}
				}
			}
			$this->shapes[] = $boxes;
		}
	}

	/**
	 * Encodes Bedrock block states (sorted by name) as "<type><name>\x01<value>" entries joined by "\x00", the type being
	 * b (ByteTag), i (IntTag) or s (StringTag). Returns null for anything else, which is kept as Tags.
	 *
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 */
	private static function encodeBedrockStates(array $states) : ?string{
		$parts = [];
		foreach(Utils::stringifyKeys($states) as $key => $tag){
			if($tag instanceof ByteTag){
				$type = "b";
				$value = (string) $tag->getValue();
			}elseif($tag instanceof IntTag){
				$type = "i";
				$value = (string) $tag->getValue();
			}elseif($tag instanceof StringTag){
				$type = "s";
				$value = $tag->getValue();
			}else{
				return null;
			}
			if($key === "" || strpbrk($key, "\x00\x01") !== false || strpbrk($value, "\x00\x01") !== false){
				return null;
			}
			$parts[] = $type . $key . "\x01" . $value;
		}
		return implode("\x00", $parts);
	}

	/**
	 * Returns the Geyser Bedrock state of a Java state: [Bedrock name, states sorted by name]. Java states without a
	 * mapping are air. The Tags are new objects on every call.
	 *
	 * @return array{string, array<string, Tag>}
	 * @internal
	 */
	public function bedrockState(int $state) : array{
		$index = $this->bedrockStateOf[$state] ?? null;
		if($index === null){
			return ["minecraft:air", []];
		}
		return [$this->bedrockNames[$this->bedrockStateName[$index]], $this->decodeBedrockStates($index)];
	}

	/**
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 */
	private function decodeBedrockStates(int $index) : array{
		$data = $this->bedrockStateData[$index];
		if(!is_string($data)){
			return $data;
		}
		if($data === ""){
			return [];
		}
		$states = [];
		foreach(explode("\x00", $data, PHP_INT_MAX) as $entry){
			$separator = strpos($entry, "\x01");
			if($separator === false){
				throw new AssumptionFailedError("Invalid encoded Bedrock state");
			}
			$value = substr($entry, $separator + 1);
			$states[substr($entry, 1, $separator - 1)] = match($entry[0]){
				"b" => new ByteTag((int) $value),
				"i" => new IntTag((int) $value),
				default => new StringTag($value),
			};
		}
		return $states;
	}

	private static function decompress(string $data) : string{
		$decoded = @zlib_decode($data);
		return $decoded !== false ? $decoded : $data;
	}

	public function getDataDir() : string{
		return $this->dataDir;
	}

	// ---- states ----

	public function hasBlock(string $name) : bool{
		return isset($this->blockByName[self::namespaced($name)]);
	}

	public function blockIndex(string $name) : int{
		return $this->blockByName[self::namespaced($name)] ?? throw new \RuntimeException("Unknown Java block $name");
	}

	public static function namespaced(string $name) : string{
		return strpos($name, ":") === false ? "minecraft:" . $name : $name;
	}

	public function defaultOf(string $name) : int{
		return $this->defaultState[$this->blockIndex($name)];
	}

	public function blockName(int $state) : string{
		return $this->names[$this->blockOf[$state]];
	}

	/**
	 * Parses "minecraft:name[prop=value,...]".
	 */
	public function parseState(string $string) : int{
		$bracket = strpos($string, "[");
		if($bracket === false){
			return $this->defaultOf($string);
		}
		$state = $this->defaultOf(substr($string, 0, $bracket));
		$inner = substr($string, $bracket + 1, -1);
		if($inner !== ""){
			foreach(explode(",", $inner, 64) as $pair){
				[$key, $value] = explode("=", $pair, 2) + [1 => ""];
				$state = $this->with($state, $key, $value);
			}
		}
		return $state;
	}

	/**
	 * Reads a block state in the worldgen JSON format: {"Name": ..., "Properties": {...}} (older) or
	 * {"id": ..., "properties": {...}} / plain "minecraft:name" (26.x).
	 */
	public function stateFromJson(mixed $json) : int{
		if(is_string($json)){
			return $this->parseState($json);
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid block state");
		}
		$name = $json["id"] ?? $json["Name"] ?? null;
		if(!is_string($name)){
			throw new \RuntimeException("Invalid block state (no id)");
		}
		$state = $this->defaultOf($name);
		$properties = $json["properties"] ?? $json["Properties"] ?? [];
		if(is_array($properties)){
			foreach(Utils::promoteKeys($properties) as $key => $value){
				if(is_string($value) || is_int($value)){
					$state = $this->with($state, (string) $key, (string) $value);
				}
			}
		}
		return $state;
	}

	/**
	 * @phpstan-return int<0, max>|null
	 */
	private function propertyIndex(int $block, string $property) : ?int{
		$index = array_search($property, $this->propertyNames[$block], true);
		return $index === false ? null : $index;
	}

	public function hasProperty(int $state, string $property) : bool{
		return $this->propertyIndex($this->blockOf[$state], $property) !== null;
	}

	/**
	 * Returns the value of a property, or null if the block does not have it.
	 */
	public function getProperty(int $state, string $property) : ?string{
		$block = $this->blockOf[$state];
		$p = $this->propertyIndex($block, $property);
		if($p === null){
			return null;
		}
		if(isset($this->explicitStates[$block])){
			return $this->propertyValues[$block][$p][$this->explicitStates[$block][$state][$p]];
		}
		$values = $this->propertyValues[$block][$p];
		return $values[intdiv($state - $this->firstState[$block], $this->strides[$block][$p]) % count($values)];
	}

	public function getBool(int $state, string $property) : bool{
		return $this->getProperty($state, $property) === "true";
	}

	public function getInt(int $state, string $property) : int{
		return (int) $this->getProperty($state, $property);
	}

	/**
	 * Returns the state with a property changed (unchanged if the block does not have the property or the value).
	 */
	public function with(int $state, string $property, string $value) : int{
		$block = $this->blockOf[$state];
		$p = $this->propertyIndex($block, $property);
		if($p === null){
			return $state;
		}
		$valueIndex = array_search($value, $this->propertyValues[$block][$p], true);
		if($valueIndex === false){
			return $state;
		}
		if(isset($this->explicitStates[$block])){
			$indexes = $this->explicitStates[$block][$state];
			$indexes[$p] = $valueIndex;
			foreach($this->explicitStates[$block] as $id => $candidate){
				if($candidate === $indexes){
					return $id;
				}
			}
			return $state;
		}
		$values = $this->propertyValues[$block][$p];
		$stride = $this->strides[$block][$p];
		$current = intdiv($state - $this->firstState[$block], $stride) % count($values);
		return $state + ($valueIndex - $current) * $stride;
	}

	public function withBool(int $state, string $property, bool $value) : int{
		return $this->with($state, $property, $value ? "true" : "false");
	}

	public function withInt(int $state, string $property, int $value) : int{
		return $this->with($state, $property, (string) $value);
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function propertyNames(int $state) : array{
		return $this->propertyNames[$this->blockOf[$state]];
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function propertyValues(int $state, string $property) : array{
		$block = $this->blockOf[$state];
		$p = $this->propertyIndex($block, $property);
		return $p === null ? [] : $this->propertyValues[$block][$p];
	}

	public function is(int $state, int $block) : bool{
		return $this->blockOf[$state] === $block;
	}

	// ---- tags ----

	/**
	 * Returns the set of block indexes in a tag (name with or without "#" and namespace).
	 * @phpstan-return array<int, true>
	 */
	public function tag(string $name) : array{
		if(str_starts_with($name, "#")){
			$name = substr($name, 1);
		}
		$name = self::namespaced($name);
		if(isset($this->tags[$name])){
			return $this->tags[$name];
		}
		$this->tags[$name] = []; //cycle guard
		$set = [];
		foreach($this->rawTags[$name] ?? throw new \RuntimeException("Unknown block tag $name") as $value){
			if(str_starts_with($value, "#")){
				$set += $this->tag($value);
			}elseif($this->hasBlock($value)){
				$set[$this->blockIndex($value)] = true;
			}
		}
		return $this->tags[$name] = $set;
	}

	public function hasTag(string $name) : bool{
		$name = self::namespaced(str_starts_with($name, "#") ? substr($name, 1) : $name);
		return isset($this->rawTags[$name]);
	}

	public function isTagged(int $state, string $tag) : bool{
		return isset(($this->tagsByRawName[$tag] ??= $this->tag($tag))[$this->blockOf[$state]]);
	}

	/**
	 * Resolves a block holder set: a tag ("#minecraft:x"), one block id or a list of block ids.
	 * @phpstan-return array<int, true>
	 */
	public function blockSet(mixed $json) : array{
		if(is_string($json)){
			if(str_starts_with($json, "#")){
				return $this->tag($json);
			}
			return [$this->blockIndex($json) => true];
		}
		$set = [];
		if(is_array($json)){
			foreach($json as $value){
				if(is_string($value)){
					$set += $this->blockSet($value);
				}
			}
		}
		return $set;
	}

	// ---- physical properties ----

	public function isAir(int $state) : bool{
		$block = $this->blockOf[$state];
		return $block === $this->airBlock || $block === $this->caveAirBlock || $block === $this->voidAirBlock;
	}

	/**
	 * Fluid of the state: FLUID_NONE, FLUID_WATER or FLUID_LAVA in the low bits, with bit 4 set for source fluids.
	 */
	public function fluid(int $state) : int{
		if(isset($this->fluidCache[$state])){
			return $this->fluidCache[$state];
		}
		$block = $this->blockOf[$state];
		$fluid = self::FLUID_NONE;
		if($block === $this->waterBlock){
			$fluid = self::FLUID_WATER | ($this->getProperty($state, "level") === "0" ? 16 : 0);
		}elseif($block === $this->lavaBlock){
			$fluid = self::FLUID_LAVA | ($this->getProperty($state, "level") === "0" ? 16 : 0);
		}elseif($this->getProperty($state, "waterlogged") === "true"){
			$fluid = self::FLUID_WATER | 16;
		}else{
			$name = $this->names[$block];
			if($name === "minecraft:seagrass" || $name === "minecraft:tall_seagrass" || $name === "minecraft:kelp" || $name === "minecraft:kelp_plant" || $name === "minecraft:bubble_column"){
				$fluid = self::FLUID_WATER | 16;
			}
		}
		return $this->fluidCache[$state] = $fluid;
	}

	public function isWater(int $state) : bool{
		return ($this->fluid($state) & 15) === self::FLUID_WATER;
	}

	public function isLava(int $state) : bool{
		return ($this->fluid($state) & 15) === self::FLUID_LAVA;
	}

	public function fluidIsSource(int $state) : bool{
		return ($this->fluid($state) & 16) !== 0;
	}

	/**
	 * Whether the fluid of the state is in a fluid tag ("minecraft:water", "minecraft:lava").
	 */
	public function fluidInTag(int $state, string $tag) : bool{
		$fluid = $this->fluid($state) & 15;
		if($fluid === self::FLUID_NONE){
			return false;
		}
		$fluids = $this->fluidTags[self::namespaced(str_starts_with($tag, "#") ? substr($tag, 1) : $tag)] ?? [];
		$source = $this->fluidIsSource($state);
		$name = $fluid === self::FLUID_WATER ? ($source ? "minecraft:water" : "minecraft:flowing_water") : ($source ? "minecraft:lava" : "minecraft:flowing_lava");
		return isset($fluids[$name]);
	}

	/**
	 * Java's legacy "solid" flag (BlockStateBase.isSolid()): forced for some blocks, otherwise derived from the
	 * collision shape (average box size >= 0.7291666666666666 or full height).
	 */
	public function isSolid(int $state) : bool{
		if(isset($this->solidCache[$state])){
			return $this->solidCache[$state];
		}
		$name = substr($this->names[$this->blockOf[$state]], 10);
		$forced = SolidityRules::forced($name);
		if($forced !== null){
			return $this->solidCache[$state] = $forced;
		}
		$boxes = $this->shapes[$this->collisionIndex[$state] ?? 0] ?? [];
		if(count($boxes) === 0){
			return $this->solidCache[$state] = false;
		}
		$minX = $minY = $minZ = 1.0e9;
		$maxX = $maxY = $maxZ = -1.0e9;
		foreach($boxes as [$x0, $y0, $z0, $x1, $y1, $z1]){
			$minX = $x0 < $minX ? $x0 : $minX;
			$minY = $y0 < $minY ? $y0 : $minY;
			$minZ = $z0 < $minZ ? $z0 : $minZ;
			$maxX = $x1 > $maxX ? $x1 : $maxX;
			$maxY = $y1 > $maxY ? $y1 : $maxY;
			$maxZ = $z1 > $maxZ ? $z1 : $maxZ;
		}
		$size = (($maxX - $minX) + ($maxY - $minY) + ($maxZ - $minZ)) / 3.0;
		return $this->solidCache[$state] = $size >= 0.7291666666666666 || ($maxY - $minY) >= 1.0;
	}

	/**
	 * Whether the collision shape covers the whole face (approximation of Java's sturdy face check with the support
	 * shape, exact for full blocks).
	 */
	public function isFaceSturdy(int $state, int $face) : bool{
		return $this->sturdyCache[$state * 6 + $face] ??= $this->computeFaceSturdy($state, $face);
	}

	private function computeFaceSturdy(int $state, int $face) : bool{
		$boxes = $this->shapes[$this->collisionIndex[$state] ?? 0] ?? [];
		foreach($boxes as [$x0, $y0, $z0, $x1, $y1, $z1]){
			$full = match($face){
				Direction::DOWN => $y0 <= 0.0 && $x0 <= 0.0 && $z0 <= 0.0 && $x1 >= 1.0 && $z1 >= 1.0,
				Direction::UP => $y1 >= 1.0 && $x0 <= 0.0 && $z0 <= 0.0 && $x1 >= 1.0 && $z1 >= 1.0,
				Direction::NORTH => $z0 <= 0.0 && $x0 <= 0.0 && $y0 <= 0.0 && $x1 >= 1.0 && $y1 >= 1.0,
				Direction::SOUTH => $z1 >= 1.0 && $x0 <= 0.0 && $y0 <= 0.0 && $x1 >= 1.0 && $y1 >= 1.0,
				Direction::WEST => $x0 <= 0.0 && $y0 <= 0.0 && $z0 <= 0.0 && $y1 >= 1.0 && $z1 >= 1.0,
				default => $x1 >= 1.0 && $y0 <= 0.0 && $z0 <= 0.0 && $y1 >= 1.0 && $z1 >= 1.0,
			};
			if($full){
				return true;
			}
		}
		return false;
	}

	/** Full cube collision (used as "solid render" / full block checks). */
	public function isFullCube(int $state) : bool{
		return $this->fullCubeCache[$state] ??= $this->computeFullCube($state);
	}

	private function computeFullCube(int $state) : bool{
		$boxes = $this->shapes[$this->collisionIndex[$state] ?? 0] ?? [];
		foreach($boxes as [$x0, $y0, $z0, $x1, $y1, $z1]){
			if($x0 <= 0.0 && $y0 <= 0.0 && $z0 <= 0.0 && $x1 >= 1.0 && $y1 >= 1.0 && $z1 >= 1.0){
				return true;
			}
		}
		return false;
	}

	/** Top of the collision shape (0 without collision). */
	public function collisionMaxY(int $state) : float{
		$max = 0.0;
		foreach($this->shapes[$this->collisionIndex[$state] ?? 0] ?? [] as [, , , , $y1]){
			$max = $y1 > $max ? $y1 : $max;
		}
		return $max;
	}

	/**
	 * Boxes of the collision shape (minX, minY, minZ, maxX, maxY, maxZ in block units).
	 * @phpstan-return list<array{float, float, float, float, float, float}>
	 */
	public function collisionBoxes(int $state) : array{
		return $this->shapes[$this->collisionIndex[$state] ?? 0] ?? [];
	}

	public function hasCollision(int $state) : bool{
		return count($this->shapes[$this->collisionIndex[$state] ?? 0] ?? []) > 0;
	}

	// ---- PocketMine mapping ----

	/**
	 * PocketMine state id for a Java state. Blocks PocketMine does not know become air.
	 */
	public function toPm(int $state) : int{
		if(isset($this->toPm[$state])){
			return $this->toPm[$state];
		}
		[$name, $states] = $this->bedrockState($state);
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		try{
			$pm = $deserializer->deserialize(BlockStateData::current($name, $states));
		}catch(\Throwable){
			try{
				$pm = $deserializer->deserialize(BlockStateData::current($name, []));
			}catch(\Throwable){
				$pm = $this->pmAir;
			}
		}
		if(!$this->hasStructureContent && in_array($name, ["minecraft:end_gateway", "minecraft:trial_spawner", "minecraft:vault", "minecraft:suspicious_sand", "minecraft:suspicious_gravel"], true)){
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($pm);
			if($block instanceof EndGateway || $block instanceof TrialSpawner || $block instanceof Vault || $block instanceof BrushableBlock){
				$pm = $this->pmAir;
			}
		}
		$this->pmWater[$state] = ($this->fluid($state) & 15) === self::FLUID_WATER && $this->blockOf[$state] !== $this->waterBlock;
		return $this->toPm[$state] = $pm;
	}

	/**
	 * IMPORTANT: RyzeMP's current ChorusPlant, Fire and RedstoneWire runtime
	 * states do not expose VanillaMine's directional connection setters.
	 * Their shape properties are lossy on this fork. Shape-aware client mapping
	 * must be restored in the protocol translation layer before production.
	 */

	/**
	 * Whether the Bedrock block of this Java state keeps water in the liquid layer (waterlogged blocks, seagrass,
	 * kelp...).
	 */
	public function pmNeedsWater(int $state) : bool{
		if(!isset($this->pmWater[$state])){
			$this->toPm($state);
		}
		return $this->pmWater[$state];
	}

	public function pmWaterState() : int{
		return $this->pmWaterState;
	}

	public function pmAirState() : int{
		return $this->pmAir;
	}

	public function toPmBlock(int $state) : Block{
		return RuntimeBlockStateRegistry::getInstance()->fromStateId($this->toPm($state));
	}

	/**
	 * Java state for a PocketMine state id (and whether the liquid layer has water). Several Java states can map to
	 * the same Bedrock state (e.g. leaf distances, waterlogging): the Java default state of the block is preferred.
	 */
	public function fromPm(int $pmState, bool $wet = false) : int{
		if($wet){
			if(isset($this->fromPmWet[$pmState])){
				return $this->fromPmWet[$pmState];
			}
		}elseif(isset($this->fromPm[$pmState])){
			return $this->fromPm[$pmState];
		}
		$java = $this->resolveFromPm($pmState, $wet);
		if($wet){
			$this->fromPmWet[$pmState] = $java;
		}else{
			$this->fromPm[$pmState] = $java;
		}
		return $java;
	}

	private function resolveFromPm(int $pmState, bool $wet) : int{
		if($this->bedrockIndex === null){
			$index = [];
			$keys = [];
			foreach($this->bedrockStateOf as $javaId => $stateIndex){
				$key = $keys[$stateIndex] ??= self::bedrockKey($this->bedrockNames[$this->bedrockStateName[$stateIndex]], $this->decodeBedrockStates($stateIndex));
				$index[$key][] = $javaId;
			}
			$this->bedrockIndex = $index;
		}
		try{
			$data = GlobalBlockStateHandlers::getSerializer()->serialize($pmState);
		}catch(\Throwable){
			return $this->air;
		}
		$states = [];
		foreach(Utils::stringifyKeys($data->getStates()) as $key => $tag){
			$states[$key] = $tag;
		}
		ksort($states);
		$candidates = $this->bedrockIndex[self::bedrockKey($data->getName(), $states)] ?? null;
		if($candidates === null){
			//same block, any state
			$nameIndex = array_search($data->getName(), $this->bedrockNames, true);
			if($nameIndex !== false){
				foreach($this->bedrockStateOf as $javaId => $stateIndex){
					if($this->bedrockStateName[$stateIndex] === $nameIndex){
						return $this->defaultState[$this->blockOf[$javaId]];
					}
				}
			}
			return $this->air;
		}
		$best = $candidates[0];
		$bestScore = -1;
		foreach($candidates as $candidate){
			$block = $this->blockOf[$candidate];
			$score = 0;
			$waterlogged = $this->getProperty($candidate, "waterlogged");
			if($waterlogged !== null && ($waterlogged === "true") === $wet){
				$score += 4;
			}
			if($candidate === $this->defaultState[$block]){
				$score += 2;
			}
			if($this->getProperty($candidate, "persistent") === "false"){
				$score += 1;
			}
			if($score > $bestScore){
				$bestScore = $score;
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 */
	private static function bedrockKey(string $name, array $states) : string{
		$parts = [$name];
		foreach(Utils::stringifyKeys($states) as $key => $tag){
			$value = $tag instanceof ByteTag || $tag instanceof IntTag ? (string) $tag->getValue() : ($tag instanceof StringTag ? $tag->getValue() : (string) $tag);
			$parts[] = $key . "=" . $value;
		}
		return implode(";", $parts);
	}

	/**
	 * Debug name of a state, e.g. "minecraft:oak_log[axis=y]".
	 */
	public function describe(int $state) : string{
		$properties = [];
		foreach($this->propertyNames($state) as $property){
			$properties[] = $property . "=" . $this->getProperty($state, $property);
		}
		return $this->blockName($state) . (count($properties) > 0 ? "[" . implode(",", $properties) . "]" : "");
	}

	public function stateCount() : int{
		return count($this->blockOf);
	}

	/** @internal for tests */
	public static function reset() : void{
		self::$instances = [];
	}

	/**
	 * @param mixed[] $json
	 */
	public static function dataStateId(self $blocks, array $json, string $key) : int{
		return $blocks->stateFromJson($json[$key] ?? throw new \RuntimeException("Missing block state \"$key\""));
	}

	public static function stripNamespace(string $id) : string{
		return WorldgenData::stripNamespace($id);
	}
}
