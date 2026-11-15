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

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\structure\jigsaw\JigsawStructure;
use pocketmine\world\generator\vanilla\structure\jigsaw\OtherPoolElements;
use pocketmine\world\generator\vanilla\structure\jigsaw\PoolElement;
use pocketmine\world\generator\vanilla\structure\jigsaw\SinglePoolElement;
use pocketmine\world\generator\vanilla\structure\jigsaw\TemplatePool;
use pocketmine\world\generator\vanilla\structure\placement\ConcentricRingsPlacement;
use pocketmine\world\generator\vanilla\structure\placement\RandomSpreadPlacement;
use pocketmine\world\generator\vanilla\structure\placement\StructurePlacement;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\type\BuriedTreasureStructure;
use pocketmine\world\generator\vanilla\structure\type\DesertPyramidStructure;
use pocketmine\world\generator\vanilla\structure\type\EndCityStructure;
use pocketmine\world\generator\vanilla\structure\type\IglooStructure;
use pocketmine\world\generator\vanilla\structure\type\JungleTempleStructure;
use pocketmine\world\generator\vanilla\structure\type\MineshaftStructure;
use pocketmine\world\generator\vanilla\structure\type\NetherFortressStructure;
use pocketmine\world\generator\vanilla\structure\type\NetherFossilStructure;
use pocketmine\world\generator\vanilla\structure\type\OceanMonumentStructure;
use pocketmine\world\generator\vanilla\structure\type\OceanRuinStructure;
use pocketmine\world\generator\vanilla\structure\type\RuinedPortalStructure;
use pocketmine\world\generator\vanilla\structure\type\ShipwreckStructure;
use pocketmine\world\generator\vanilla\structure\type\StrongholdStructure;
use pocketmine\world\generator\vanilla\structure\type\SwampHutStructure;
use pocketmine\world\generator\vanilla\structure\type\WoodlandMansionStructure;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_key_exists;
use function array_search;
use function basename;
use function file_exists;
use function glob;
use function is_array;
use function is_dir;
use function is_string;
use function ksort;
use function sort;
use function str_starts_with;
use function substr;

/**
 * The structures, structure sets and template pools of a data cache (worldgen/structure, structure_set,
 * template_pool), parsed once per thread and data directory (see shared()). Structure sets are listed in vanilla's
 * registry order (sorted by id, as the data pack loader does).
 */
final class StructureRegistry{
	/** @phpstan-var array<string, StructureDefinition> by id, sorted */
	private array $structures = [];
	/**
	 * Structure sets: [id, Java placement, list of [structure id, weight]], sorted by id.
	 * @phpstan-var array<string, array{StructurePlacement, list<array{string, int}>}>
	 */
	private array $sets = [];
	/** @phpstan-var array<string, ?TemplatePool> */
	private array $pools = [];
	/** @phpstan-var array<string, list<string>> biome tag => biome names */
	private array $biomeTags = [];
	/** @phpstan-var array<string, true> */
	private array $warned = [];
	/** @phpstan-var array<string, string> unsupported structure id => type */
	private array $unsupported = [];
	/** @phpstan-var array<string, int> every structure id (supported or not) => decoration step, sorted by id */
	private array $steps = [];

	public readonly Processors $processors;

	/** @phpstan-var array<string, self> */
	private static array $shared = [];

	/** Structure types implemented, by "type" of the structure data. */
	public const TYPES = [
		"jigsaw", "swamp_hut", "igloo", "desert_pyramid", "jungle_temple", "shipwreck", "ocean_ruin", "ruined_portal", "buried_treasure"
	];

	/**
	 * @phpstan-param (\Closure(string) : void)|null $logger
	 */
	public function __construct(
		public readonly WorldgenData $data,
		public readonly JavaBlocks $blocks,
		private ?\Closure $logger = null
	){
		$this->processors = new Processors($data, $blocks, fn(string $key, string $message) => $this->warn($key, $message));
		$root = $data->getRoot() . "/data/minecraft/worldgen";
		if(!is_dir($root . "/structure") || !is_dir($root . "/structure_set")){
			throw new \RuntimeException("The vanilla data cache has no structure data (refetch it: data format 5+)");
		}
		$structureFiles = glob($root . "/structure/*.json");
		$ids = [];
		foreach($structureFiles !== false ? $structureFiles : [] as $file){
			$ids[] = "minecraft:" . basename($file, ".json");
		}
		sort($ids);
		foreach($ids as $id){
			$json = $data->readObject("data/minecraft/worldgen/structure/" . WorldgenData::stripNamespace($id) . ".json");
			$step = array_search(WorldgenData::string($json, "step", "surface_structures"), StructureDefinition::STEPS, true);
			$this->steps[$id] = $step === false ? 4 : $step;
			$definition = $this->parseStructure($id, $json);
			if($definition !== null){
				$this->structures[$id] = $definition;
			}
		}
		$setFiles = glob($root . "/structure_set/*.json");
		foreach($setFiles !== false ? $setFiles : [] as $file){
			$id = "minecraft:" . basename($file, ".json");
			$json = $data->readObject("data/minecraft/worldgen/structure_set/" . basename($file));
			$placementJson = WorldgenData::array($json, "placement");
			$placementType = WorldgenData::stripNamespace(WorldgenData::string($placementJson, "type"));
			if($placementType === "random_spread"){
				$placement = RandomSpreadPlacement::fromJson($placementJson);
			}elseif($placementType === "concentric_rings"){
				//strongholds: unbound, StructureManager binds it to its seed and biome source
				$placement = ConcentricRingsPlacement::fromJson($placementJson, fn(mixed $biomes) : array => $this->resolveBiomes($biomes));
			}else{
				continue;
			}
			$entries = [];
			foreach(WorldgenData::array($json, "structures") as $entry){
				if(is_array($entry)){
					$entries[] = [JavaBlocks::namespaced(WorldgenData::string($entry, "structure")), WorldgenData::int($entry, "weight", 1)];
				}
			}
			$this->sets[$id] = [$placement, $entries];
		}
		ksort($this->sets);
	}

	/**
	 * @phpstan-param (\Closure(string) : void)|null $logger
	 */
	public static function shared(WorldgenData $data, JavaBlocks $blocks, ?\Closure $logger = null) : self{
		return self::$shared[$data->getRoot()] ??= new self($data, $blocks, $logger);
	}

	public function warn(string $key, string $message) : void{
		if(isset($this->warned[$key])){
			return;
		}
		$this->warned[$key] = true;
		if($this->logger !== null){
			($this->logger)($message);
		}
	}

	/**
	 * @param mixed[] $json
	 */
	private function parseStructure(string $id, array $json) : ?StructureDefinition{
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		$structureType = match($type){
			"jigsaw" => new JigsawStructure($json),
			"swamp_hut" => new SwampHutStructure(),
			"igloo" => new IglooStructure(),
			"desert_pyramid" => new DesertPyramidStructure(),
			"jungle_temple" => new JungleTempleStructure(),
			"shipwreck" => new ShipwreckStructure((bool) ($json["is_beached"] ?? false)),
			"ocean_ruin" => new OceanRuinStructure($json),
			"ruined_portal" => new RuinedPortalStructure($json),
			"buried_treasure" => new BuriedTreasureStructure(),
			"mineshaft" => new MineshaftStructure($json),
			"stronghold" => new StrongholdStructure(),
			"ocean_monument" => new OceanMonumentStructure(),
			"fortress" => new NetherFortressStructure(),
			"nether_fossil" => new NetherFossilStructure($json),
			"woodland_mansion" => new WoodlandMansionStructure(),
			"end_city" => new EndCityStructure(),
			default => null
		};
		if($structureType === null){
			$this->unsupported[$id] = $type;
			return null;
		}
		$step = array_search(WorldgenData::string($json, "step", "surface_structures"), StructureDefinition::STEPS, true);
		$biomes = [];
		foreach($this->resolveBiomes($json["biomes"] ?? []) as $biome){
			$biomes[$biome] = true;
		}
		return new StructureDefinition(
			$id,
			$structureType,
			$biomes,
			$step === false ? 4 : $step,
			WorldgenData::string($json, "terrain_adaptation", StructureDefinition::ADAPTATION_NONE),
			$json
		);
	}

	/**
	 * Biome names of a biome holder set: "#tag", an id or a list of ids.
	 * @phpstan-return list<string>
	 */
	public function resolveBiomes(mixed $json, int $depth = 0) : array{
		if($depth > 16){
			throw new \RuntimeException("Biome tag nested too deeply");
		}
		if(is_string($json)){
			if(str_starts_with($json, "#")){
				$tag = JavaBlocks::namespaced(substr($json, 1));
				if(!isset($this->biomeTags[$tag])){
					$path = "data/minecraft/tags/worldgen/biome/" . WorldgenData::stripNamespace($tag) . ".json";
					$list = [];
					if(file_exists($this->data->getRoot() . "/" . $path)){
						foreach(WorldgenData::array($this->data->readObject($path), "values") as $value){
							if(is_array($value)){
								$value = $value["id"] ?? null;
							}
							foreach($this->resolveBiomes($value, $depth + 1) as $biome){
								$list[] = $biome;
							}
						}
					}
					$this->biomeTags[$tag] = $list;
				}
				return $this->biomeTags[$tag];
			}
			return [JavaBlocks::namespaced($json)];
		}
		$list = [];
		if(is_array($json)){
			foreach($json as $value){
				foreach($this->resolveBiomes($value, $depth + 1) as $biome){
					$list[] = $biome;
				}
			}
		}
		return $list;
	}

	/**
	 * @phpstan-return array<string, StructureDefinition>
	 */
	public function getStructures() : array{
		return $this->structures;
	}

	public function getStructure(string $id) : ?StructureDefinition{
		return $this->structures[JavaBlocks::namespaced($id)] ?? null;
	}

	/**
	 * The decoration step of every structure of the data, implemented or not, in registry order.
	 * @phpstan-return array<string, int>
	 */
	public function getStructureSteps() : array{
		return $this->steps;
	}

	/**
	 * Structures of the data that have no implementation yet: id => type.
	 * @phpstan-return array<string, string>
	 */
	public function getUnsupported() : array{
		return $this->unsupported;
	}

	/**
	 * @phpstan-return array<string, array{StructurePlacement, list<array{string, int}>}>
	 */
	public function getSets() : array{
		return $this->sets;
	}

	public function getPool(string $id) : ?TemplatePool{
		$id = JavaBlocks::namespaced($id);
		if(!array_key_exists($id, $this->pools)){
			$this->pools[$id] = $this->loadPool($id);
		}
		return $this->pools[$id];
	}

	private function loadPool(string $id) : ?TemplatePool{
		$path = "data/minecraft/worldgen/template_pool/" . WorldgenData::stripNamespace($id) . ".json";
		if(!file_exists($this->data->getRoot() . "/" . $path)){
			return $id === "minecraft:empty" ? new TemplatePool($id, [], "minecraft:empty") : null;
		}
		$json = $this->data->readObject($path);
		$templates = [];
		foreach(WorldgenData::array($json, "elements") as $entry){
			if(!is_array($entry)){
				continue;
			}
			$element = $this->parseElement($entry["element"] ?? null, null);
			$weight = WorldgenData::int($entry, "weight", 1);
			for($i = 0; $i < $weight; ++$i){
				$templates[] = $element;
			}
		}
		return new TemplatePool($id, $templates, JavaBlocks::namespaced(WorldgenData::string($json, "fallback", "minecraft:empty")));
	}

	private function parseElement(mixed $json, ?int $projectionOverride) : PoolElement{
		if(!is_array($json)){
			throw new \RuntimeException("Invalid pool element");
		}
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "element_type"));
		$projection = $projectionOverride ?? (WorldgenData::string($json, "projection", "rigid") === "terrain_matching" ? PoolElement::PROJECTION_TERRAIN_MATCHING : PoolElement::PROJECTION_RIGID);
		switch($type){
			case "single_pool_element":
			case "legacy_single_pool_element":
				$liquid = $json["override_liquid_settings"] ?? null;
				return new SinglePoolElement(
					JavaBlocks::namespaced(WorldgenData::string($json, "location")),
					$this->processors->parseList($json["processors"] ?? []),
					$projection,
					$type === "legacy_single_pool_element",
					is_string($liquid) ? $liquid !== "ignore_waterlogging" : null
				);
			case "feature_pool_element":
				return OtherPoolElements::feature(JavaBlocks::namespaced(WorldgenData::string($json, "feature")), $projection);
			case "list_pool_element":
				$elements = [];
				foreach(WorldgenData::array($json, "elements") as $inner){
					$elements[] = $this->parseElement($inner, $projection);
				}
				return OtherPoolElements::list($elements, $projection);
			case "empty_pool_element":
				return OtherPoolElements::emptyElement();
			default:
				throw new \RuntimeException("Unknown pool element type $type");
		}
	}
}
