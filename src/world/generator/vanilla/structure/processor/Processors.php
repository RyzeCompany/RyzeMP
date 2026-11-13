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

namespace pocketmine\world\generator\vanilla\structure\processor;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\random\RandomSource;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function range;

/**
 * The structure processors (vanilla's StructureProcessorType): the data-driven ones of processor lists (block_rot,
 * rule, protected_blocks, capped, block_ignore, gravity, nop) and the ones vanilla adds in code (jigsaw replacement,
 * lava submerged blocks).
 *
 * @phpstan-import-type BlockInfo from StructureProcessor
 */
final class Processors{
	/** @phpstan-var array<string, list<StructureProcessor>> parsed processor lists by id */
	private array $lists = [];
	/** @phpstan-var \Closure(string, string) : void */
	private \Closure $warn;

	/**
	 * @phpstan-param (\Closure(string, string) : void)|null $warn
	 */
	public function __construct(
		private WorldgenData $data,
		private JavaBlocks $blocks,
		?\Closure $warn = null
	){
		$this->warn = $warn ?? function(string $key, string $message) : void{};
	}

	/**
	 * A processor list: an id of worldgen/processor_list, {"processors": [...]} or a plain list.
	 * @phpstan-return list<StructureProcessor>
	 */
	public function parseList(mixed $json) : array{
		if(is_string($json)){
			$id = JavaBlocks::namespaced($json);
			return $this->lists[$id] ??= $this->parseList($this->data->read("data/minecraft/worldgen/processor_list/" . WorldgenData::stripNamespace($id) . ".json"));
		}
		if(is_array($json) && isset($json["processors"])){
			$json = $json["processors"];
		}
		if(!is_array($json)){
			throw new \RuntimeException("Invalid processor list");
		}
		$list = [];
		foreach($json as $processor){
			if(!is_array($processor)){
				throw new \RuntimeException("Invalid structure processor");
			}
			$parsed = $this->parse($processor);
			if($parsed !== null){
				$list[] = $parsed;
			}
		}
		return $list;
	}

	/**
	 * @param mixed[] $json
	 */
	public function parse(array $json) : ?StructureProcessor{
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "processor_type"));
		$blocks = $this->blocks;
		switch($type){
			case "block_rot":
				return self::blockRot(FloatMath::fround(WorldgenData::float($json, "integrity")), isset($json["rottable_blocks"]) ? $blocks->blockSet($json["rottable_blocks"]) : null, $blocks);
			case "protected_blocks":
				return self::protectedBlocks($blocks->blockSet($json["value"] ?? null), $blocks);
			case "block_ignore":
				$set = [];
				foreach(WorldgenData::array($json, "blocks") as $state){
					$set[$blocks->blockOf[$blocks->stateFromJson($state)]] = true;
				}
				return self::ignore($set, $blocks);
			case "gravity":
				return self::gravity(match(WorldgenData::string($json, "heightmap", "WORLD_SURFACE_WG")){
					"OCEAN_FLOOR_WG" => FeatureLevel::OCEAN_FLOOR_WG,
					"OCEAN_FLOOR" => FeatureLevel::OCEAN_FLOOR,
					"WORLD_SURFACE" => FeatureLevel::WORLD_SURFACE,
					"MOTION_BLOCKING" => FeatureLevel::MOTION_BLOCKING,
					"MOTION_BLOCKING_NO_LEAVES" => FeatureLevel::MOTION_BLOCKING_NO_LEAVES,
					default => FeatureLevel::WORLD_SURFACE_WG
				}, WorldgenData::int($json, "offset", 0));
			case "rule":
				return $this->rule($json);
			case "capped":
				$delegate = is_array($json["delegate"] ?? null) ? $this->parse($json["delegate"]) : null;
				if($delegate === null){
					return null;
				}
				$limit = $json["limit"] ?? 0;
				if(is_int($limit)){
					return self::capped($delegate, $limit, $limit);
				}
				if(is_array($limit) && WorldgenData::stripNamespace(WorldgenData::string($limit, "type", "constant")) === "uniform"){
					return self::capped($delegate, WorldgenData::int($limit, "min_inclusive"), WorldgenData::int($limit, "max_inclusive"));
				}
				if(is_array($limit) && isset($limit["value"]) && is_int($limit["value"])){
					return self::capped($delegate, $limit["value"], $limit["value"]);
				}
				($this->warn)("capped_limit", "Unsupported capped processor limit (ignored)");
				return null;
			case "nop":
				return null;
			case "jigsaw_replacement":
				return self::jigsawReplacement($blocks);
			case "lava_submerged_block":
				return self::lavaSubmerged($blocks);
			default:
				($this->warn)("processor:$type", "Unsupported structure processor $type (ignored)");
				return null;
		}
	}

	/**
	 * BlockIgnoreProcessor
	 * @phpstan-param array<int, true> $blockSet Java block indexes
	 */
	public static function ignore(array $blockSet, JavaBlocks $blocks) : StructureProcessor{
		return new class($blockSet, $blocks) extends StructureProcessor{
			/** @phpstan-param array<int, true> $set */
			public function __construct(private array $set, private JavaBlocks $blocks){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : ?array{
				return isset($this->set[$this->blocks->blockOf[$current[3]]]) ? null : $current;
			}
		};
	}

	/**
	 * BlockIgnoreProcessor.STRUCTURE_BLOCK
	 */
	public static function ignoreStructureBlocks(JavaBlocks $blocks) : StructureProcessor{
		return self::ignore($blocks->hasBlock("structure_block") ? [$blocks->blockIndex("structure_block") => true] : [], $blocks);
	}

	/**
	 * BlockIgnoreProcessor.STRUCTURE_AND_AIR
	 */
	public static function ignoreStructureBlocksAndAir(JavaBlocks $blocks) : StructureProcessor{
		$set = [$blocks->airBlock => true];
		if($blocks->hasBlock("structure_block")){
			$set[$blocks->blockIndex("structure_block")] = true;
		}
		return self::ignore($set, $blocks);
	}

	/**
	 * BlockIgnoreProcessor.AIR
	 */
	public static function ignoreAir(JavaBlocks $blocks) : StructureProcessor{
		return self::ignore([$blocks->airBlock => true], $blocks);
	}

	/**
	 * JigsawReplacementProcessor: jigsaw blocks become their final state (structure voids are not placed).
	 */
	public static function jigsawReplacement(JavaBlocks $blocks) : StructureProcessor{
		return new class($blocks) extends StructureProcessor{
			private int $jigsaw;
			private int $structureVoid;
			/** @phpstan-var array<string, int> */
			private array $parsed = [];

			public function __construct(private JavaBlocks $blocks){
				$this->jigsaw = $blocks->hasBlock("jigsaw") ? $blocks->blockIndex("jigsaw") : -1;
				$this->structureVoid = $blocks->hasBlock("structure_void") ? $blocks->blockIndex("structure_void") : -1;
			}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : ?array{
				if($this->blocks->blockOf[$current[3]] !== $this->jigsaw){
					return $current;
				}
				$nbt = $current[4];
				if($nbt === null){
					return null;
				}
				$string = $nbt->getString("final_state", "minecraft:air");
				if(!isset($this->parsed[$string])){
					try{
						$this->parsed[$string] = $this->blocks->parseState($string);
					}catch(\RuntimeException){
						$this->parsed[$string] = $this->blocks->air;
					}
				}
				$state = $this->parsed[$string];
				if($this->blocks->blockOf[$state] === $this->structureVoid){
					return null;
				}
				return [$current[0], $current[1], $current[2], $state, null];
			}
		};
	}

	/**
	 * GravityProcessor: the block goes to the heightmap of its column plus its height in the template.
	 */
	public static function gravity(int $heightmap, int $offset) : StructureProcessor{
		return new class($heightmap, $offset) extends StructureProcessor{
			public function __construct(private int $heightmap, private int $offset){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				//the terrain heightmaps come from the noise terrain, not from the region: blocks placed by the
				//structures of neighbouring chunks must not change them (vanilla freezes them after the terrain step)
				$height = match($this->heightmap){
					FeatureLevel::WORLD_SURFACE_WG => $c->env->terrain->getFirstFreeHeight($current[0], $current[2], TerrainSampler::WORLD_SURFACE_WG),
					FeatureLevel::OCEAN_FLOOR_WG => $c->env->terrain->getFirstFreeHeight($current[0], $current[2], TerrainSampler::OCEAN_FLOOR_WG),
					default => $c->level->getHeight($this->heightmap, $current[0], $current[2])
				} + $this->offset;
				return [$current[0], $height + $original[1], $current[2], $current[3], $current[4]];
			}
		};
	}

	/**
	 * BlockRotProcessor
	 * @phpstan-param array<int, true>|null $rottable
	 */
	public static function blockRot(float $integrity, ?array $rottable, JavaBlocks $blocks) : StructureProcessor{
		return new class($integrity, $rottable, $blocks) extends StructureProcessor{
			/** @phpstan-param array<int, true>|null $rottable */
			public function __construct(private float $integrity, private ?array $rottable, private JavaBlocks $blocks){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : ?array{
				$random = $settings->getRandom($current[0], $current[1], $current[2]);
				if(($this->rottable !== null && !isset($this->rottable[$this->blocks->blockOf[$original[3]]])) || $random->nextFloat() <= $this->integrity){
					return $current;
				}
				return null;
			}
		};
	}

	/**
	 * ProtectedBlockProcessor: does not replace the blocks of the set.
	 * @phpstan-param array<int, true> $protected
	 */
	public static function protectedBlocks(array $protected, JavaBlocks $blocks) : StructureProcessor{
		return new class($protected, $blocks) extends StructureProcessor{
			/** @phpstan-param array<int, true> $protected */
			public function __construct(private array $protected, private JavaBlocks $blocks){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : ?array{
				return isset($this->protected[$this->blocks->blockOf[$c->level->getState($current[0], $current[1], $current[2])]]) ? null : $current;
			}
		};
	}

	/**
	 * LavaSubmergedBlockProcessor: blocks that are not full cubes placed in lava become lava.
	 */
	public static function lavaSubmerged(JavaBlocks $blocks) : StructureProcessor{
		return new class($blocks) extends StructureProcessor{
			public function __construct(private JavaBlocks $blocks){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				$blocks = $this->blocks;
				if($blocks->blockOf[$c->level->getState($current[0], $current[1], $current[2])] === $blocks->lavaBlock && !$blocks->isFullCube($current[3])){
					return [$current[0], $current[1], $current[2], $blocks->lava, $current[4]];
				}
				return $current;
			}
		};
	}

	/**
	 * CappedProcessor: applies the delegate to at most a few random blocks of the template.
	 */
	public static function capped(StructureProcessor $delegate, int $min, int $max) : StructureProcessor{
		return new class($delegate, $min, $max) extends StructureProcessor{
			public function __construct(private StructureProcessor $delegate, private int $min, private int $max){}

			public function needsAllBlocks() : bool{
				return true;
			}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				return $current;
			}

			public function finalize(StructurePlaceContext $c, array $origin, array $pivot, array $originals, array $processed, PlaceSettings $settings) : array{
				if($this->max === 0 || count($processed) === 0 || count($originals) !== count($processed)){
					return $processed;
				}
				//RandomSource.create(level.getSeed()).forkPositional().at(pos)
				$random = (new LegacyRandom($c->seed))->forkPositional()->at($origin[0], $origin[1], $origin[2]);
				$limit = $this->min === $this->max ? $this->min : $random->nextBoundedInt($this->max - $this->min + 1) + $this->min;
				$max = min($limit, count($processed));
				if($max < 1){
					return $processed;
				}
				$indexes = range(0, count($processed) - 1);
				for($j = count($indexes); $j > 1; --$j){
					$k = $random->nextBoundedInt($j);
					$tmp = $indexes[$j - 1];
					$indexes[$j - 1] = $indexes[$k];
					$indexes[$k] = $tmp;
				}
				$count = 0;
				foreach($indexes as $index){
					if($count >= $max){
						break;
					}
					$before = $processed[$index];
					$result = $this->delegate->process($c, $origin, $pivot, $originals[$index], $before, $settings);
					if($result !== null && ($result[3] !== $before[3] || $result[0] !== $before[0] || $result[1] !== $before[1] || $result[2] !== $before[2] || $result[4] !== $before[4])){
						++$count;
						$processed[$index] = $result;
					}
				}
				return $processed;
			}
		};
	}

	/**
	 * A rule processor of simple rules: [input block, probability (1: block match), output state] (the rules
	 * RuinedPortalPiece builds in code).
	 * @phpstan-param list<array{string, float, string}> $rules
	 */
	public static function simpleRules(JavaBlocks $blocks, array $rules) : StructureProcessor{
		$parsed = [];
		foreach($rules as [$input, $probability, $output]){
			$parsed[] = [$blocks->blockIndex($input), FloatMath::fround($probability), $blocks->parseState($output)];
		}
		return new class($parsed, $blocks) extends StructureProcessor{
			/** @phpstan-param list<array{int, float, int}> $rules */
			public function __construct(private array $rules, private JavaBlocks $blocks){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				$random = new LegacyRandom(Int64::positionSeed($current[0], $current[1], $current[2]));
				$block = $this->blocks->blockOf[$current[3]];
				foreach($this->rules as [$input, $probability, $output]){
					if($block === $input && ($probability >= 1.0 || $random->nextFloat() < $probability)){
						return [$current[0], $current[1], $current[2], $output, $current[4]];
					}
				}
				return $current;
			}
		};
	}

	/**
	 * BlockAgeProcessor (ruined portals), simplified: stone bricks crack or get mossy, stairs / slabs / walls get
	 * mossy, obsidian sometimes becomes crying obsidian.
	 */
	public static function blockAge(JavaBlocks $blocks, float $mossiness) : StructureProcessor{
		return new class($blocks, FloatMath::fround($mossiness)) extends StructureProcessor{
			/** @phpstan-var array<int, string> */
			private array $kinds = [];

			public function __construct(private JavaBlocks $blocks, private float $mossiness){
				foreach(["stone_bricks", "stone", "chiseled_stone_bricks"] as $name){
					$this->kinds[$blocks->blockIndex($name)] = "full";
				}
				$this->kinds[$blocks->blockIndex("stone_brick_stairs")] = "stairs";
				$this->kinds[$blocks->blockIndex("stone_brick_slab")] = "slab";
				$this->kinds[$blocks->blockIndex("stone_brick_wall")] = "wall";
				$this->kinds[$blocks->blockIndex("obsidian")] = "obsidian";
			}

			private function copy(int $from, string $to) : int{
				$state = $this->blocks->defaultOf($to);
				foreach($this->blocks->propertyNames($from) as $property){
					$value = $this->blocks->getProperty($from, $property);
					if($value !== null && $this->blocks->hasProperty($state, $property)){
						$state = $this->blocks->with($state, $property, $value);
					}
				}
				return $state;
			}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				$kind = $this->kinds[$this->blocks->blockOf[$current[3]]] ?? null;
				if($kind === null){
					return $current;
				}
				$random = $settings->getRandom($current[0], $current[1], $current[2]);
				$state = null;
				switch($kind){
					case "full":
						if($random->nextFloat() < 0.5){
							$state = $this->blocks->defaultOf($random->nextFloat() < $this->mossiness ? "minecraft:mossy_stone_bricks" : "minecraft:cracked_stone_bricks");
						}
						break;
					case "stairs":
						if($random->nextFloat() < $this->mossiness){
							$state = $this->copy($current[3], "minecraft:mossy_stone_brick_stairs");
						}
						break;
					case "slab":
						if($random->nextFloat() < $this->mossiness){
							$state = $this->copy($current[3], "minecraft:mossy_stone_brick_slab");
						}
						break;
					case "wall":
						if($random->nextFloat() < $this->mossiness){
							$state = $this->copy($current[3], "minecraft:mossy_stone_brick_wall");
						}
						break;
					case "obsidian":
						if($random->nextFloat() < 0.15){
							$state = $this->blocks->defaultOf("minecraft:crying_obsidian");
						}
						break;
				}
				return $state !== null ? [$current[0], $current[1], $current[2], $state, $current[4]] : $current;
			}
		};
	}

	/**
	 * BlackstoneReplaceProcessor (Nether ruined portals): stone blocks become their blackstone variants.
	 */
	public static function blackstoneReplace(JavaBlocks $blocks) : StructureProcessor{
		$map = [
			"cobblestone" => "blackstone", "mossy_cobblestone" => "blackstone", "stone" => "polished_blackstone",
			"stone_bricks" => "polished_blackstone_bricks", "mossy_stone_bricks" => "polished_blackstone_bricks",
			"cobblestone_stairs" => "blackstone_stairs", "mossy_cobblestone_stairs" => "blackstone_stairs",
			"stone_stairs" => "polished_blackstone_stairs", "stone_brick_stairs" => "polished_blackstone_brick_stairs",
			"mossy_stone_brick_stairs" => "polished_blackstone_brick_stairs", "cobblestone_slab" => "blackstone_slab",
			"mossy_cobblestone_slab" => "blackstone_slab", "smooth_stone_slab" => "polished_blackstone_slab",
			"stone_slab" => "polished_blackstone_slab", "stone_brick_slab" => "polished_blackstone_brick_slab",
			"mossy_stone_brick_slab" => "polished_blackstone_brick_slab", "stone_brick_wall" => "polished_blackstone_brick_wall",
			"mossy_stone_brick_wall" => "polished_blackstone_brick_wall", "cobblestone_wall" => "blackstone_wall",
			"mossy_cobblestone_wall" => "blackstone_wall", "chiseled_stone_bricks" => "chiseled_polished_blackstone",
			"cracked_stone_bricks" => "cracked_polished_blackstone_bricks", "iron_bars" => "iron_chain"
		];
		$replacements = [];
		foreach($map as $from => $to){
			if($blocks->hasBlock($from) && $blocks->hasBlock($to)){
				$replacements[$blocks->blockIndex($from)] = "minecraft:" . $to;
			}
		}
		return new class($blocks, $replacements) extends StructureProcessor{
			/** @phpstan-param array<int, string> $replacements */
			public function __construct(private JavaBlocks $blocks, private array $replacements){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				$target = $this->replacements[$this->blocks->blockOf[$current[3]]] ?? null;
				if($target === null){
					return $current;
				}
				$state = $this->blocks->defaultOf($target);
				foreach($this->blocks->propertyNames($current[3]) as $property){
					$value = $this->blocks->getProperty($current[3], $property);
					if($value !== null && $this->blocks->hasProperty($state, $property) && in_array($value, $this->blocks->propertyValues($state, $property), true)){
						$state = $this->blocks->with($state, $property, $value);
					}
				}
				return [$current[0], $current[1], $current[2], $state, $current[4]];
			}
		};
	}

	/**
	 * A rule processor turning one block into another with a loot table (OceanRuinPieces.archyRuleProcessor()).
	 */
	public static function archaeology(JavaBlocks $blocks, string $input, string $output, string $lootTable) : StructureProcessor{
		return new class($blocks->blockIndex($input), $blocks->defaultOf($output), $lootTable, $blocks) extends StructureProcessor{
			public function __construct(private int $input, private int $output, private string $lootTable, private JavaBlocks $blocks){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				if($this->blocks->blockOf[$current[3]] !== $this->input){
					return $current;
				}
				$random = new LegacyRandom(Int64::positionSeed($current[0], $current[1], $current[2]));
				$nbt = ($current[4] !== null ? clone $current[4] : CompoundTag::create())
					->setString("LootTable", $this->lootTable)
					->setLong("LootTableSeed", $random->nextLong());
				return [$current[0], $current[1], $current[2], $this->output, $nbt];
			}
		};
	}

	/**
	 * RuleProcessor
	 * @param mixed[] $json
	 */
	private function rule(array $json) : StructureProcessor{
		$blocks = $this->blocks;
		$rules = [];
		foreach(WorldgenData::array($json, "rules") as $rule){
			if(!is_array($rule)){
				throw new \RuntimeException("Invalid processor rule");
			}
			$rules[] = [
				Predicates::rule($blocks, $rule["input_predicate"] ?? null),
				Predicates::rule($blocks, $rule["location_predicate"] ?? null),
				$this->positionPredicate($rule["position_predicate"] ?? null),
				$blocks->stateFromJson($rule["output_state"] ?? null),
				$this->blockEntityModifier($rule["block_entity_modifier"] ?? null)
			];
		}
		return new class($rules) extends StructureProcessor{
			/**
			 * @phpstan-param list<array{
			 *     \Closure(int, FeatureRandom|RandomSource, int) : bool,
			 *     \Closure(int, FeatureRandom|RandomSource, int) : bool,
			 *     \Closure(array{int, int, int}, array{int, int, int}, array{int, int, int}, LegacyRandom) : bool,
			 *     int,
			 *     (\Closure(LegacyRandom, ?CompoundTag) : CompoundTag)|null
			 * }> $rules
			 */
			public function __construct(private array $rules){}

			public function process(StructurePlaceContext $c, array $origin, array $pivot, array $original, array $current, PlaceSettings $settings) : array{
				$random = new LegacyRandom(Int64::positionSeed($current[0], $current[1], $current[2]));
				$location = $c->level->getState($current[0], $current[1], $current[2]);
				foreach($this->rules as [$input, $locationTest, $positionTest, $output, $modifier]){
					if($input($current[3], $random, $current[1]) && $locationTest($location, $random, $current[1]) && $positionTest([$original[0], $original[1], $original[2]], [$current[0], $current[1], $current[2]], $pivot, $random)){
						return [$current[0], $current[1], $current[2], $output, $modifier !== null ? $modifier($random, $current[4]) : $current[4]];
					}
				}
				return $current;
			}
		};
	}

	/**
	 * PosRuleTest: (localPos, worldPos, structurePos, random) => bool
	 * @phpstan-return \Closure(array{int, int, int}, array{int, int, int}, array{int, int, int}, LegacyRandom) : bool
	 */
	private function positionPredicate(mixed $json) : \Closure{
		$type = is_array($json) ? WorldgenData::stripNamespace(WorldgenData::string($json, "predicate_type", "always_true")) : "always_true";
		switch($type){
			case "axis_aligned_linear_pos":
				/** @var mixed[] $json */
				$minChance = FloatMath::fround(WorldgenData::float($json, "min_chance", 0.0));
				$maxChance = FloatMath::fround(WorldgenData::float($json, "max_chance", 0.0));
				$minDist = WorldgenData::int($json, "min_dist", 0);
				$maxDist = WorldgenData::int($json, "max_dist", 0);
				$axis = match(WorldgenData::string($json, "axis", "y")){
					"x" => 0,
					"z" => 2,
					default => 1
				};
				return function(array $local, array $world, array $structure, LegacyRandom $random) use ($minChance, $maxChance, $minDist, $maxDist, $axis) : bool{
					$distance = (float) abs($world[$axis] - $structure[$axis]);
					$chance = $random->nextFloat();
					$t = $maxDist === $minDist ? 0.0 : ($distance - $minDist) / ($maxDist - $minDist);
					$t = max(0.0, min(1.0, $t));
					return $chance <= $minChance + $t * ($maxChance - $minChance);
				};
			case "always_true":
				return fn(array $local, array $world, array $structure, LegacyRandom $random) : bool => true;
			default:
				($this->warn)("processor_position:$type", "Unsupported processor position predicate $type");
				return fn(array $local, array $world, array $structure, LegacyRandom $random) : bool => true;
		}
	}

	/**
	 * RuleBlockEntityModifier: (random, nbt) => nbt, or null for passthrough.
	 * @phpstan-return (\Closure(LegacyRandom, ?CompoundTag) : CompoundTag)|null
	 */
	private function blockEntityModifier(mixed $json) : ?\Closure{
		if(!is_array($json)){
			return null;
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($type){
			case "passthrough":
				return null;
			case "clear":
				return fn(LegacyRandom $random, ?CompoundTag $nbt) : CompoundTag => CompoundTag::create();
			case "append_loot":
				$table = JavaBlocks::namespaced(WorldgenData::string($json, "loot_table"));
				return fn(LegacyRandom $random, ?CompoundTag $nbt) : CompoundTag => ($nbt !== null ? clone $nbt : CompoundTag::create())
					->setString("LootTable", $table)
					->setLong("LootTableSeed", $random->nextLong());
			default:
				($this->warn)("block_entity_modifier:$type", "Unsupported block entity modifier $type (ignored)");
				return null;
		}
	}
}
