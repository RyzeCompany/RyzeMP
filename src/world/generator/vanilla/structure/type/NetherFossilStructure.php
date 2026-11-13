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

namespace pocketmine\world\generator\vanilla\structure\type;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\HeightProviders;
use pocketmine\world\generator\vanilla\structure\piece\NetherFossilPiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * minecraft:nether_fossil (vanilla's NetherFossilStructure and NetherFossilPieces): a random bone block fossil on
 * the floor of a cave or valley, above the lava sea.
 */
final class NetherFossilStructure implements StructureType{
	/** NetherFossilPieces.FOSSILS */
	private const FOSSIL_COUNT = 14;

	/** @phpstan-var \Closure(LegacyRandom, int, int) : int */
	private \Closure $height;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(array $json){
		$this->height = HeightProviders::parse($json["height"] ?? null);
	}

	public function findGenerationPoint(StructureGenContext $context) : ?array{
		$random = $context->random;
		$env = $context->env;
		$blockX = $context->getMinBlockX() + $random->nextBoundedInt(16);
		$blockZ = $context->getMinBlockZ() + $random->nextBoundedInt(16);
		$seaLevel = $env->seaLevel;
		$y = ($this->height)($random, $env->minY, $env->getMaxY());
		//the base column (noise terrain without surface rules): the first air block above a sturdy block or soul sand;
		//solid noise blocks are netherrack (sturdy) and soul sand only comes from the surface rules
		$terrain = $env->terrain;
		while($y > $seaLevel){
			$current = $terrain->getKind($blockX, $y, $blockZ);
			--$y;
			if($current === TerrainSampler::KIND_AIR && $terrain->getKind($blockX, $y, $blockZ) === TerrainSampler::KIND_SOLID){
				break;
			}
		}
		if($y <= $seaLevel){
			return null;
		}
		return [$blockX, $y, $blockZ, function() use ($context, $blockX, $y, $blockZ) : array{
			$random = $context->random;
			//NetherFossilPieces.addPieces(): Rotation.getRandom(), then Util.getRandom(FOSSILS)
			$rotation = $random->nextBoundedInt(4);
			$location = "minecraft:nether_fossils/fossil_" . ($random->nextBoundedInt(self::FOSSIL_COUNT) + 1);
			return [new NetherFossilPiece($context->env->templates, $location, $blockX, $y, $blockZ, $rotation)];
		}];
	}

	public function getReach() : int{
		return 1;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
