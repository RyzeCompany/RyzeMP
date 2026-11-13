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

use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\RuinedPortalPiece;
use pocketmine\world\generator\vanilla\structure\StructureEnvironment;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function intdiv;
use function is_array;

/**
 * minecraft:ruined_portal (vanilla's RuinedPortalStructure): a random portal template, its height found from the
 * terrain columns of its corners according to the setup's vertical placement.
 */
final class RuinedPortalStructure implements StructureType{
	public const PLACEMENT_ON_LAND_SURFACE = "on_land_surface";
	public const PLACEMENT_PARTLY_BURIED = "partly_buried";
	public const PLACEMENT_ON_OCEAN_FLOOR = "on_ocean_floor";
	public const PLACEMENT_IN_MOUNTAIN = "in_mountain";
	public const PLACEMENT_UNDERGROUND = "underground";
	public const PLACEMENT_IN_NETHER = "in_nether";

	/** @phpstan-var list<array{placement: string, airPocket: float, mossiness: float, overgrown: bool, vines: bool, canBeCold: bool, blackstone: bool, weight: float}> */
	private array $setups = [];

	/**
	 * @param mixed[] $json
	 */
	public function __construct(array $json){
		foreach(WorldgenData::array($json, "setups") as $setup){
			if(!is_array($setup)){
				continue;
			}
			$this->setups[] = [
				"placement" => WorldgenData::string($setup, "placement"),
				"airPocket" => FloatMath::fround(WorldgenData::float($setup, "air_pocket_probability")),
				"mossiness" => FloatMath::fround(WorldgenData::float($setup, "mossiness")),
				"overgrown" => (bool) ($setup["overgrown"] ?? false),
				"vines" => (bool) ($setup["vines"] ?? false),
				"canBeCold" => (bool) ($setup["can_be_cold"] ?? false),
				"blackstone" => (bool) ($setup["replace_with_blackstone"] ?? false),
				"weight" => FloatMath::fround(WorldgenData::float($setup, "weight")),
			];
		}
		if(count($this->setups) === 0){
			throw new \RuntimeException("Ruined portal without setups");
		}
	}

	private static function sample(LegacyRandom $random, float $probability) : bool{
		return $probability === 0.0 ? false : ($probability === 1.0 ? true : $random->nextFloat() < $probability);
	}

	public function findGenerationPoint(StructureGenContext $context) : ?array{
		$random = $context->random;
		$env = $context->env;
		$setup = null;
		if(count($this->setups) > 1){
			$total = 0.0;
			foreach($this->setups as $s){
				$total = FloatMath::fround($total + $s["weight"]);
			}
			$f = $random->nextFloat();
			foreach($this->setups as $s){
				$f = FloatMath::fround($f - FloatMath::fround($s["weight"] / $total));
				if($f < 0.0){
					$setup = $s;
					break;
				}
			}
		}else{
			$setup = $this->setups[0];
		}
		if($setup === null){
			return null;
		}
		$airPocket = self::sample($random, $setup["airPocket"]);
		if($random->nextFloat() < 0.05){
			$location = "minecraft:ruined_portal/giant_portal_" . ($random->nextBoundedInt(3) + 1);
		}else{
			$location = "minecraft:ruined_portal/portal_" . ($random->nextBoundedInt(10) + 1);
		}
		[$sizeX, $sizeY, $sizeZ] = $env->templates->size($location);
		$rotation = $random->nextBoundedInt(4);
		$mirror = $random->nextFloat() < 0.5 ? Rotations::MIRROR_NONE : Rotations::MIRROR_FRONT_BACK;
		$pivotX = intdiv($sizeX, 2);
		$pivotZ = intdiv($sizeZ, 2);
		$originX = $context->getMinBlockX();
		$originZ = $context->getMinBlockZ();
		[$minX, $minY, $minZ, $maxX, $maxY, $maxZ] = Rotations::boundingBox($originX, 0, $originZ, $sizeX, $sizeY, $sizeZ, $rotation, $mirror, $pivotX, $pivotZ);
		$box = new BoundingBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
		[$centerX, , $centerZ] = $box->getCenter();
		$heightmap = $setup["placement"] === self::PLACEMENT_ON_OCEAN_FLOOR ? TerrainSampler::OCEAN_FLOOR_WG : TerrainSampler::WORLD_SURFACE_WG;
		$baseHeight = $env->terrain->getFirstFreeHeight($centerX, $centerZ, $heightmap) - 1;
		$y = self::findSuitableY($env, $random, $setup["placement"], $airPocket, $baseHeight, $box->getYSpan(), $box);
		return [$originX, $y, $originZ, function() use ($env, $setup, $airPocket, $location, $rotation, $mirror, $pivotX, $pivotZ, $originX, $y, $originZ) : array{
			$cold = $setup["canBeCold"] && $env->isColdEnoughToSnow($originX, $y, $originZ);
			return [new RuinedPortalPiece(
				$env->templates, $location, $originX, $y, $originZ, $rotation, $mirror, $pivotX, $pivotZ,
				$setup["placement"], $airPocket, $setup["mossiness"], $setup["overgrown"], $setup["vines"], $cold, $setup["blackstone"]
			)];
		}];
	}

	private static function findSuitableY(StructureEnvironment $env, LegacyRandom $random, string $placement, bool $airPocket, int $baseHeight, int $ySpan, BoundingBox $box) : int{
		$minY = $env->minY + 15;
		if($placement === self::PLACEMENT_IN_NETHER){
			if($airPocket){
				$y = StructureRandom::between($random, 32, 100);
			}elseif($random->nextFloat() < 0.5){
				$y = StructureRandom::between($random, 27, 29);
			}else{
				$y = StructureRandom::between($random, 29, 100);
			}
		}elseif($placement === self::PLACEMENT_IN_MOUNTAIN){
			$y = self::randomWithinInterval($random, 70, $baseHeight - $ySpan);
		}elseif($placement === self::PLACEMENT_UNDERGROUND){
			$y = self::randomWithinInterval($random, $minY, $baseHeight - $ySpan);
		}elseif($placement === self::PLACEMENT_PARTLY_BURIED){
			$y = $baseHeight - $ySpan + StructureRandom::between($random, 2, 8);
		}else{
			$y = $baseHeight;
		}
		$corners = [[$box->minX, $box->minZ], [$box->maxX, $box->minZ], [$box->minX, $box->maxZ], [$box->maxX, $box->maxZ]];
		$oceanFloor = $placement === self::PLACEMENT_ON_OCEAN_FLOOR;
		$terrain = $env->terrain;
		$l = $y;
		for(; $l > $minY; --$l){
			$count = 0;
			foreach($corners as [$cx, $cz]){
				$kind = $terrain->getKind($cx, $l, $cz);
				if($oceanFloor ? $kind === TerrainSampler::KIND_SOLID : $kind !== TerrainSampler::KIND_AIR){
					if(++$count === 3){
						return $l;
					}
				}
			}
		}
		return $l;
	}

	private static function randomWithinInterval(LegacyRandom $random, int $min, int $max) : int{
		return $min < $max ? StructureRandom::between($random, $min, $max) : $max;
	}

	public function getReach() : int{
		return 3;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
