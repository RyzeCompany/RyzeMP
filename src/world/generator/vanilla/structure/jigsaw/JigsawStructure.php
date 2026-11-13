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

namespace pocketmine\world\generator\vanilla\structure\jigsaw;

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\HeightProviders;
use pocketmine\world\generator\vanilla\structure\piece\PoolElementPiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\WorldgenData;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;

/**
 * minecraft:jigsaw structures (villages, pillager outposts, bastions, ancient cities, trail ruins, trial chambers,
 * abandoned camps): vanilla's JigsawStructure and JigsawPlacement.addPieces().
 */
final class JigsawStructure implements StructureType{
	/** @phpstan-var \Closure(LegacyRandom, int, int) : int */
	private \Closure $startHeight;
	private string $startPool;
	private ?string $startJigsawName;
	private int $maxDepth;
	private ?int $projectToHeightmap;
	private int $maxDistance;
	private bool $useExpansionHack;
	private int $padBottom;
	private int $padTop;
	private bool $applyWaterlogging;
	/** @phpstan-var list<mixed[]> */
	private array $poolAliases;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(array $json){
		$this->startPool = JavaBlocks::namespaced(WorldgenData::string($json, "start_pool"));
		$this->startJigsawName = isset($json["start_jigsaw_name"]) ? JavaBlocks::namespaced(WorldgenData::string($json, "start_jigsaw_name")) : null;
		$this->maxDepth = WorldgenData::int($json, "size");
		$this->startHeight = HeightProviders::parse($json["start_height"] ?? null);
		$heightmap = $json["project_start_to_heightmap"] ?? null;
		$this->projectToHeightmap = is_string($heightmap) ? ($heightmap === "OCEAN_FLOOR_WG" ? TerrainSampler::OCEAN_FLOOR_WG : TerrainSampler::WORLD_SURFACE_WG) : null;
		$this->maxDistance = WorldgenData::int($json, "max_distance_from_center", 80);
		$this->useExpansionHack = (bool) ($json["use_expansion_hack"] ?? false);
		$padding = $json["dimension_padding"] ?? 0;
		if(is_int($padding)){
			$this->padBottom = $this->padTop = $padding;
		}elseif(is_array($padding)){
			$this->padBottom = WorldgenData::int($padding, "bottom", 0);
			$this->padTop = WorldgenData::int($padding, "top", 0);
		}else{
			$this->padBottom = $this->padTop = 0;
		}
		$this->applyWaterlogging = WorldgenData::string($json, "liquid_settings", "apply_waterlogging") !== "ignore_waterlogging";
		$aliases = [];
		foreach(is_array($json["pool_aliases"] ?? null) ? $json["pool_aliases"] : [] as $alias){
			if(is_array($alias)){
				$aliases[] = $alias;
			}
		}
		$this->poolAliases = $aliases;
	}

	public function getReach() : int{
		return 8;
	}

	/**
	 * PoolAliasLookup.create()
	 * @phpstan-return array<string, string>
	 */
	private function resolveAliases(int $seed, int $x, int $y, int $z) : array{
		if($this->poolAliases === []){
			return [];
		}
		$random = (new LegacyRandom($seed))->forkPositional()->at($x, $y, $z);
		if(!$random instanceof LegacyRandom){
			throw new \LogicException("Expected a legacy random");
		}
		$map = [];
		foreach($this->poolAliases as $binding){
			self::resolveBinding($binding, $random, $map);
		}
		return $map;
	}

	/**
	 * @param mixed[] $binding
	 * @phpstan-param array<string, string> $map
	 * @phpstan-param-out array<string, string> $map
	 */
	private static function resolveBinding(array $binding, LegacyRandom $random, array &$map) : void{
		$type = WorldgenData::stripNamespace(WorldgenData::string($binding, "type"));
		switch($type){
			case "direct":
				$map[JavaBlocks::namespaced(WorldgenData::string($binding, "alias"))] = JavaBlocks::namespaced(WorldgenData::string($binding, "target"));
				break;
			case "random":
				$picked = self::pickWeighted(WorldgenData::array($binding, "targets"), $random);
				if(is_string($picked)){
					$map[JavaBlocks::namespaced(WorldgenData::string($binding, "alias"))] = JavaBlocks::namespaced($picked);
				}
				break;
			case "random_group":
				$picked = self::pickWeighted(WorldgenData::array($binding, "groups"), $random);
				if(is_array($picked)){
					foreach($picked as $inner){
						if(is_array($inner)){
							self::resolveBinding($inner, $random, $map);
						}
					}
				}
				break;
			default:
				throw new \RuntimeException("Unknown pool alias binding $type");
		}
	}

	/**
	 * WeightedList.getRandom() over [{"data": ..., "weight": n}, ...]
	 * @param mixed[] $entries
	 */
	private static function pickWeighted(array $entries, LegacyRandom $random) : mixed{
		$total = 0;
		foreach($entries as $entry){
			if(is_array($entry)){
				$total += WorldgenData::int($entry, "weight", 1);
			}
		}
		if($total <= 0){
			return null;
		}
		$roll = $random->nextBoundedInt($total);
		foreach($entries as $entry){
			if(is_array($entry)){
				$roll -= WorldgenData::int($entry, "weight", 1);
				if($roll < 0){
					return $entry["data"] ?? null;
				}
			}
		}
		return null;
	}

	public function findGenerationPoint(StructureGenContext $context) : ?array{
		$env = $context->env;
		$random = $context->random;
		$startY = ($this->startHeight)($random, $env->minY, $env->getMaxY());
		$startX = $context->getMinBlockX();
		$startZ = $context->getMinBlockZ();
		$aliases = $this->resolveAliases($env->seed, $startX, $startY, $startZ);
		$templates = $env->templates;

		//JigsawPlacement.addPieces()
		$rotation = $random->nextBoundedInt(4);
		$pool = $env->registry->getPool($aliases[$this->startPool] ?? $this->startPool) ?? $env->registry->getPool($this->startPool);
		if($pool === null || $pool->size() === 0){
			return null;
		}
		$element = $pool->getRandomTemplate($random);
		if($element->isEmpty()){
			return null;
		}
		if($this->startJigsawName !== null){
			$named = JigsawAssembler::randomNamedJigsaw($templates, $element, $this->startJigsawName, $startX, $startY, $startZ, $rotation, $random);
			if($named === null){
				$env->registry->warn("start_jigsaw:" . $this->startJigsawName, "No starting jigsaw " . $this->startJigsawName . " found in start pool " . $this->startPool);
				return null;
			}
			[$offsetX, $offsetY, $offsetZ] = [$named[0] - $startX, $named[1] - $startY, $named[2] - $startZ];
		}else{
			[$offsetX, $offsetY, $offsetZ] = [0, 0, 0];
		}
		$adjustedX = $startX - $offsetX;
		$adjustedY = $startY - $offsetY;
		$adjustedZ = $startZ - $offsetZ;
		$startPiece = new PoolElementPiece(
			$element,
			$adjustedX, $adjustedY, $adjustedZ,
			$element->getGroundLevelDelta(),
			$rotation,
			$element->getBoundingBox($templates, $adjustedX, $adjustedY, $adjustedZ, $rotation),
			$element->getLiquidOverride() ?? $this->applyWaterlogging
		);
		$box = $startPiece->box;
		$centerX = intdiv($box->maxX + $box->minX, 2);
		$centerZ = intdiv($box->maxZ + $box->minZ, 2);
		$y = $this->projectToHeightmap !== null ? $startY + $env->terrain->getFirstFreeHeight($centerX, $centerZ, $this->projectToHeightmap) : $adjustedY;
		$groundDelta = $box->minY + $startPiece->getGroundLevelDelta();
		$startPiece->move(0, $y - $groundDelta, 0);
		$stubY = $y + $offsetY;

		$maxDepth = $this->maxDepth;
		$maxDistance = $this->maxDistance;
		$padBottom = $this->padBottom;
		$padTop = $this->padTop;
		$useExpansionHack = $this->useExpansionHack;
		$applyWaterlogging = $this->applyWaterlogging;
		return [$centerX, $stubY, $centerZ, function() use ($env, $startPiece, $maxDepth, $maxDistance, $padBottom, $padTop, $useExpansionHack, $random, $aliases, $centerX, $stubY, $centerZ, $applyWaterlogging) : array{
			$pieces = [$startPiece];
			if($maxDepth > 0){
				$space = JigsawAssembler::initialSpace($env, $centerX, $stubY, $centerZ, $maxDistance, $padBottom, $padTop, $startPiece->box);
				$assembler = new JigsawAssembler($env, $maxDepth, $useExpansionHack, $random, $pieces, $aliases, $applyWaterlogging);
				$assembler->run($startPiece, $space);
				$pieces = $assembler->pieces;
			}
			return $pieces;
		}];
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
