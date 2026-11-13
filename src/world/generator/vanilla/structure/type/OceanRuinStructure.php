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
use pocketmine\world\generator\vanilla\structure\piece\OceanRuinPiece;
use pocketmine\world\generator\vanilla\structure\StructureEnvironment;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\StructureStart;
use pocketmine\world\generator\vanilla\structure\StructureType;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_splice;
use function count;
use function min;

/**
 * minecraft:ocean_ruin (vanilla's OceanRuinStructure and OceanRuinPieces): warm (sandstone) or cold (stone brick)
 * ruins on the ocean floor, sometimes a big one surrounded by a cluster of small ones.
 */
final class OceanRuinStructure implements StructureType{
	private const WARM_RUINS = ["warm_1", "warm_2", "warm_3", "warm_4", "warm_5", "warm_6", "warm_7", "warm_8"];
	private const RUINS_BRICK = ["brick_1", "brick_2", "brick_3", "brick_4", "brick_5", "brick_6", "brick_7", "brick_8"];
	private const RUINS_CRACKED = ["cracked_1", "cracked_2", "cracked_3", "cracked_4", "cracked_5", "cracked_6", "cracked_7", "cracked_8"];
	private const RUINS_MOSSY = ["mossy_1", "mossy_2", "mossy_3", "mossy_4", "mossy_5", "mossy_6", "mossy_7", "mossy_8"];
	private const BIG_RUINS_BRICK = ["big_brick_1", "big_brick_2", "big_brick_3", "big_brick_8"];
	private const BIG_RUINS_MOSSY = ["big_mossy_1", "big_mossy_2", "big_mossy_3", "big_mossy_8"];
	private const BIG_RUINS_CRACKED = ["big_cracked_1", "big_cracked_2", "big_cracked_3", "big_cracked_8"];
	private const BIG_WARM_RUINS = ["big_warm_4", "big_warm_5", "big_warm_6", "big_warm_7"];

	private bool $warm;
	private float $largeProbability;
	private float $clusterProbability;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(array $json){
		$this->warm = WorldgenData::string($json, "biome_temp", "warm") === "warm";
		$this->largeProbability = FloatMath::fround(WorldgenData::float($json, "large_probability"));
		$this->clusterProbability = FloatMath::fround(WorldgenData::float($json, "cluster_probability"));
	}

	public function findGenerationPoint(StructureGenContext $context) : array{
		[$x, $y, $z] = $context->onTopOfChunkCenter(TerrainSampler::OCEAN_FLOOR_WG);
		return [$x, $y, $z, function() use ($context) : array{
			$random = $context->random;
			$rotation = $random->nextBoundedInt(4);
			$pieces = [];
			$x = $context->getMinBlockX();
			$z = $context->getMinBlockZ();
			$big = $random->nextFloat() <= $this->largeProbability;
			$this->addPiece($context->env, $x, $z, $rotation, $pieces, $random, $big, $big ? 0.9 : 0.8);
			if($big && $random->nextFloat() <= $this->clusterProbability){
				$this->addClusterRuins($context->env, $random, $rotation, $x, $z, $pieces);
			}
			return $pieces;
		}];
	}

	/**
	 * @phpstan-param list<OceanRuinPiece> $pieces
	 * @phpstan-param-out list<OceanRuinPiece> $pieces
	 */
	private function addClusterRuins(StructureEnvironment $env, LegacyRandom $random, int $rotation, int $x, int $z, array &$pieces) : void{
		[$tx, $tz] = Rotations::transform(15, 15, Rotations::MIRROR_NONE, $rotation, 0, 0);
		$box = BoundingBox::fromCorners($x, 90, $z, $tx + $x, 90, $tz + $z);
		$baseX = min($x, $tx + $x);
		$baseZ = min($z, $tz + $z);
		$positions = [
			[$baseX - 16 + StructureRandom::mthNextInt($random, 1, 8), $baseZ + 16 + StructureRandom::mthNextInt($random, 1, 7)],
			[$baseX - 16 + StructureRandom::mthNextInt($random, 1, 8), $baseZ + StructureRandom::mthNextInt($random, 1, 7)],
			[$baseX - 16 + StructureRandom::mthNextInt($random, 1, 8), $baseZ - 16 + StructureRandom::mthNextInt($random, 4, 8)],
			[$baseX + StructureRandom::mthNextInt($random, 1, 7), $baseZ + 16 + StructureRandom::mthNextInt($random, 1, 7)],
			[$baseX + StructureRandom::mthNextInt($random, 1, 7), $baseZ - 16 + StructureRandom::mthNextInt($random, 4, 6)],
			[$baseX + 16 + StructureRandom::mthNextInt($random, 1, 7), $baseZ + 16 + StructureRandom::mthNextInt($random, 3, 8)],
			[$baseX + 16 + StructureRandom::mthNextInt($random, 1, 7), $baseZ + StructureRandom::mthNextInt($random, 1, 7)],
			[$baseX + 16 + StructureRandom::mthNextInt($random, 1, 7), $baseZ - 16 + StructureRandom::mthNextInt($random, 4, 8)],
		];
		$count = StructureRandom::mthNextInt($random, 4, 8);
		for($i = 0; $i < $count; ++$i){
			if(count($positions) === 0){
				continue;
			}
			$index = $random->nextBoundedInt(count($positions));
			[[$px, $pz]] = array_splice($positions, $index, 1);
			$pieceRotation = $random->nextBoundedInt(4);
			[$qx, $qz] = Rotations::transform(5, 6, Rotations::MIRROR_NONE, $pieceRotation, 0, 0);
			$pieceBox = BoundingBox::fromCorners($px, 90, $pz, $qx + $px, 90, $qz + $pz);
			if(!$pieceBox->intersects($box)){
				$this->addPiece($env, $px, $pz, $pieceRotation, $pieces, $random, false, 0.8);
			}
		}
	}

	/**
	 * @phpstan-param list<OceanRuinPiece> $pieces
	 * @phpstan-param-out list<OceanRuinPiece> $pieces
	 */
	private function addPiece(StructureEnvironment $env, int $x, int $z, int $rotation, array &$pieces, LegacyRandom $random, bool $big, float $integrity) : void{
		$integrity = FloatMath::fround($integrity);
		if($this->warm){
			$names = $big ? self::BIG_WARM_RUINS : self::WARM_RUINS;
			$pieces[] = new OceanRuinPiece($env, "minecraft:underwater_ruin/" . $names[$random->nextBoundedInt(count($names))], $x, $z, $rotation, $integrity, true, $big);
		}else{
			$bricks = $big ? self::BIG_RUINS_BRICK : self::RUINS_BRICK;
			$cracked = $big ? self::BIG_RUINS_CRACKED : self::RUINS_CRACKED;
			$mossy = $big ? self::BIG_RUINS_MOSSY : self::RUINS_MOSSY;
			$i = $random->nextBoundedInt(count($bricks));
			$pieces[] = new OceanRuinPiece($env, "minecraft:underwater_ruin/" . $bricks[$i], $x, $z, $rotation, $integrity, false, $big);
			$pieces[] = new OceanRuinPiece($env, "minecraft:underwater_ruin/" . $cracked[$i], $x, $z, $rotation, FloatMath::fround(0.7), false, $big);
			$pieces[] = new OceanRuinPiece($env, "minecraft:underwater_ruin/" . $mossy[$i], $x, $z, $rotation, FloatMath::fround(0.5), false, $big);
		}
	}

	public function getReach() : int{
		return 3;
	}

	public function afterPlace(StructurePlaceContext $c, StructureStart $start, BoundingBox $chunkBox, int $chunkX, int $chunkZ) : void{
		//NOOP
	}
}
