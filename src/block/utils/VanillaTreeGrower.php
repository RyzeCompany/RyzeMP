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

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\feature\WorldFeatureGrower;
use function array_keys;
use function array_sum;
use function count;
use function mt_rand;

/**
 * @internal
 *
 * Grows the vanilla tree, huge fungus and huge mushroom features from blocks (Java 26.3 TreeGrower, FungusBlock,
 * MushroomBlock), through {@link WorldFeatureGrower}. The changes go through StructureGrowEvent like the old
 * PocketMine trees, which are still used in worlds of other generators and when the vanilla data is not available.
 */
final class VanillaTreeGrower{
	/**
	 * Growers (TreeGrower <clinit>): normal trees, 2x2 trees and the trees grown near flowers, as feature => weight.
	 */
	public const OAK = [["oak" => 9, "fancy_oak" => 1], [], ["oak_bees_005" => 9, "fancy_oak_bees_005" => 1]];
	public const SPRUCE = [["spruce" => 1], ["mega_spruce" => 1, "mega_pine" => 1], []];
	public const BIRCH = [["birch" => 1], [], ["birch_bees_005" => 1]];
	public const JUNGLE = [["jungle_tree_no_vine" => 1], ["mega_jungle_tree" => 1], []];
	public const ACACIA = [["acacia" => 1], [], []];
	public const CHERRY = [["cherry" => 1], [], ["cherry_bees_005" => 1]];
	public const DARK_OAK = [[], ["dark_oak" => 1], []];
	public const PALE_OAK = [[], ["pale_oak_bonemeal" => 1], []];
	public const POPLAR = [["red_poplar" => 1, "orange_poplar" => 1, "yellow_poplar" => 1], [], []];
	public const MANGROVE = [["mangrove" => 15, "tall_mangrove" => 85], [], []];
	public const AZALEA = [["azalea_tree" => 1], [], []];

	/** Features grown by fungi and mushrooms (see NetherFungus, RedMushroom). */
	private const OTHER_FEATURES = ["crimson_fungus_planted", "warped_fungus_planted", "huge_red_mushroom", "huge_brown_mushroom"];

	private function __construct(){
		//NOOP
	}

	/**
	 * Main thread, when a world of a vanilla generator is loaded: loads the vanilla data of the given root and every
	 * feature that blocks grow, so growing blocks never decode them during a tick.
	 */
	public static function prepare(string $dataRoot) : void{
		$features = [];
		foreach([self::OAK, self::SPRUCE, self::BIRCH, self::JUNGLE, self::ACACIA, self::CHERRY, self::DARK_OAK, self::PALE_OAK, self::POPLAR, self::MANGROVE, self::AZALEA] as $grower){
			foreach($grower as $weights){
				foreach(Utils::stringifyKeys($weights) as $feature => $_){
					$features["minecraft:" . $feature] = true;
				}
			}
		}
		foreach(self::OTHER_FEATURES as $feature){
			$features["minecraft:" . $feature] = true;
		}
		WorldFeatureGrower::prepare($dataRoot, array_keys($features));
	}

	/**
	 * @phpstan-return array{array<string, int>, array<string, int>, array<string, int>}
	 */
	public static function forSapling(SaplingType $type) : array{
		return match($type){
			SaplingType::OAK => self::OAK,
			SaplingType::SPRUCE => self::SPRUCE,
			SaplingType::BIRCH => self::BIRCH,
			SaplingType::JUNGLE => self::JUNGLE,
			SaplingType::ACACIA => self::ACACIA,
			SaplingType::DARK_OAK => self::DARK_OAK,
			SaplingType::POPLAR => self::POPLAR,
			SaplingType::CHERRY => self::CHERRY,
			SaplingType::PALE_OAK => self::PALE_OAK,
		};
	}

	/**
	 * Whether the vanilla features can be grown in the world of the block (the vanilla data is available).
	 */
	public static function isAvailable(Block $block) : bool{
		return WorldFeatureGrower::get($block->getPosition()->getWorld()) !== null;
	}

	/**
	 * Picks a weighted entry (WeightedList.getRandom()), or null if there is none.
	 *
	 * @phpstan-param array<string, int> $weights
	 */
	public static function pick(array $weights) : ?string{
		if(count($weights) === 0){
			return null;
		}
		$roll = mt_rand(0, array_sum($weights) - 1);
		foreach(Utils::stringifyKeys($weights) as $feature => $weight){
			$roll -= $weight;
			if($roll < 0){
				return $feature;
			}
		}
		return null;
	}

	/**
	 * Offset (0 or -1 on x and z) of the north-west corner of a 2x2 square of the same sapling that includes the
	 * given one, or null (TreeGrower.findTwoByTwoSaplingPos(), same search order).
	 *
	 * @phpstan-param \Closure(Block) : bool $sameSapling
	 * @phpstan-return array{int, int}|null
	 */
	public static function findTwoByTwo(Block $sapling, \Closure $sameSapling) : ?array{
		$pos = $sapling->getPosition();
		$world = $pos->getWorld();
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		for($dx = 0; $dx >= -1; --$dx){
			for($dz = 0; $dz >= -1; --$dz){
				foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$ox, $oz]){
					if(!$sameSapling($world->getBlockAt($x + $dx + $ox, $y, $z + $dz + $oz))){
						continue 2;
					}
				}
				return [$dx, $dz];
			}
		}
		return null;
	}

	/**
	 * Grows the tree of a sapling (TreeGrower.growTree()): a 2x2 tree when the grower has some and the sapling is part
	 * of a 2x2 square, else a normal tree (a tree with a bee nest when there are flowers nearby). Returns whether a
	 * tree grew, or null if the vanilla data is not available.
	 *
	 * @phpstan-param array{array<string, int>, array<string, int>, array<string, int>} $grower
	 * @phpstan-param \Closure(Block) : bool $sameSapling
	 */
	public static function growTree(Block $sapling, ?Player $player, array $grower, \Closure $sameSapling, bool $waterlogged = false) : ?bool{
		$pos = $sapling->getPosition();
		$world = $pos->getWorld();
		$featureGrower = WorldFeatureGrower::get($world);
		if($featureGrower === null){
			return null;
		}
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		[$trees, $megaTrees, $flowerTrees] = $grower;
		$mega = self::pick($megaTrees);
		if($mega !== null && ($offset = self::findTwoByTwo($sapling, $sameSapling)) !== null){
			[$dx, $dz] = $offset;
			$clear = [];
			foreach([[0, 0], [1, 0], [0, 1], [1, 1]] as [$ox, $oz]){
				$clear[] = [$x + $dx + $ox, $y, $z + $dz + $oz, LiquidLayer::hasLayerWaterAt($world, $x + $dx + $ox, $y, $z + $dz + $oz)];
			}
			return self::place($featureGrower, $sapling, $player, $mega, $x + $dx, $y, $z + $dz, $clear);
		}
		$feature = count($flowerTrees) > 0 && $featureGrower->hasFlowers($world, $x, $y, $z) ? self::pick($flowerTrees) : self::pick($trees);
		if($feature === null){
			return false;
		}
		return self::place($featureGrower, $sapling, $player, $feature, $x, $y, $z, [[$x, $y, $z, $waterlogged]]);
	}

	/**
	 * Places a configured feature grown by a block (huge fungus, huge mushroom). Returns whether it was placed, or null
	 * if the vanilla data is not available.
	 *
	 * @param int[][] $clear positions emptied first, see {@link WorldFeatureGrower::place()}
	 * @phpstan-param list<array{int, int, int, bool}> $clear
	 */
	public static function placeFeature(Block $source, ?Player $player, string $feature, array $clear) : ?bool{
		$pos = $source->getPosition();
		$featureGrower = WorldFeatureGrower::get($pos->getWorld());
		if($featureGrower === null){
			return null;
		}
		return self::place($featureGrower, $source, $player, $feature, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $clear);
	}

	/**
	 * @phpstan-param list<array{int, int, int, bool}> $clear
	 */
	private static function place(WorldFeatureGrower $featureGrower, Block $source, ?Player $player, string $feature, int $x, int $y, int $z, array $clear) : bool{
		$world = $source->getPosition()->getWorld();
		//the world random seeds the feature (Java passes the level's random)
		$growth = $featureGrower->place($world, "minecraft:" . $feature, $x, $y, $z, $clear, (mt_rand() << 31) ^ mt_rand());
		if($growth === null){
			return false;
		}
		$ev = new StructureGrowEvent($source, $growth->transaction, $player);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		return $growth->apply($world);
	}
}
