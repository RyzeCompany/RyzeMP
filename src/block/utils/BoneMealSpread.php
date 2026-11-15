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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\DoublePlant;
use pocketmine\block\PinkPetals;
use pocketmine\block\TallGrass;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\generator\noise\Simplex;
use pocketmine\world\World;
use function count;
use function intdiv;
use function max;
use function min;
use function mt_rand;

/**
 * @internal
 *
 * Bone meal effects that place plants around the fertilized block: grass blocks (short grass, ferns and the biome's
 * flowers), Bedrock's small flower spreading and the bushes that place a copy next to themselves.
 *
 * The grass and flower spreading are random walks like vanilla GrassBlock.performBonemeal(): later attempts take more
 * random steps, each one block sideways and sometimes one block up or down, and stops when it leaves the grass.
 * Walks take at most 3 steps (the 7x7 area of the wiki). The attempt counts are fitted to BDS 1.26.52 (bone meal
 * from dispensers): about 10 plants per grass block and 1-7 flowers (3 on average) per small flower.
 */
final class BoneMealSpread{
	private const GRASS_ATTEMPTS = 32;
	private const GRASS_STEPS_DIVISOR = 8;
	private const FLOWER_ATTEMPTS = 64;
	private const FLOWER_STEPS_DIVISOR = 16;
	/** 1 in 8 walks places a flower (GrassBlock.PLACE_FLOWER_CHANCE) */
	private const FLOWER_CHANCE = 8;
	/** 1 in 10 walks ending on short grass grows it into tall grass (GrassBlock.GROW_TALL_GRASS_CHANCE) */
	private const TALL_GRASS_CHANCE = 10;

	private static ?Simplex $flowerNoise = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Bone meal on the grass block at the given position (the block above must be air): short grass (ferns in taigas
	 * and jungles), the biome's flowers, and some of the short grass grows tall.
	 */
	public static function growGrass(World $world, int $x, int $y, int $z, Random $random) : void{
		for($attempt = 0; $attempt < self::GRASS_ATTEMPTS; ++$attempt){
			$target = self::walk($world, $x, $y + 1, $z, intdiv($attempt, self::GRASS_STEPS_DIVISOR), $random);
			if($target === null){
				continue;
			}
			[$tx, $ty, $tz] = $target;
			$block = $world->getBlockAt($tx, $ty, $tz);
			if($block instanceof TallGrass && $block->getTypeId() === BlockTypeIds::TALL_GRASS && $random->nextBoundedInt(self::TALL_GRASS_CHANCE) === 0){
				self::growTallGrass($world, $tx, $ty, $tz, VanillaBlocks::DOUBLE_TALLGRASS());
				continue;
			}
			if($block->getTypeId() !== BlockTypeIds::AIR){
				continue;
			}
			$biome = $world->getBiomeId($tx, $ty, $tz);
			$place = $random->nextBoundedInt(self::FLOWER_CHANCE) === 0 ?
				self::biomeFlower($biome, $tx, $tz, $random) :
				self::biomeGrass($biome, $random);
			if($place !== null){
				$world->setBlockAt($tx, $ty, $tz, $place);
			}
		}
	}

	/**
	 * Bedrock bone meal on a one-block flower: copies of it grow on the grass around it.
	 * Returns how many flowers were placed.
	 */
	public static function spreadFlower(World $world, Block $flower, Random $random) : int{
		$pos = $flower->getPosition();
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		$placed = 0;
		//the first walks take no step and end on the flower itself
		for($attempt = self::FLOWER_STEPS_DIVISOR; $attempt < self::FLOWER_ATTEMPTS; ++$attempt){
			$target = self::walk($world, $x, $y, $z, intdiv($attempt, self::FLOWER_STEPS_DIVISOR), $random);
			if($target === null || $random->nextBoundedInt(self::FLOWER_CHANCE) !== 0){
				continue;
			}
			[$tx, $ty, $tz] = $target;
			if($world->getBlockAt($tx, $ty, $tz)->getTypeId() === BlockTypeIds::AIR){
				$world->setBlockAt($tx, $ty, $tz, $flower);
				++$placed;
			}
		}
		return $placed;
	}

	/**
	 * Bone meal on a bush, firefly bush, red shrub or tall dry grass: places the given block on a free neighbour.
	 * Returns false (the bone meal is not used) if there is no free neighbour where it can stand.
	 */
	public static function placeOnNeighbour(Block $plant, Block $toPlace, Item $boneMeal, ?Player $player) : bool{
		$target = self::findSpreadableNeighbour($plant, $toPlace, new Random(mt_rand()));
		if($target === null){
			return false;
		}
		if(BlockEventHelper::grow($target, $toPlace, $player)){
			$boneMeal->pop();
		}
		return true;
	}

	/**
	 * Returns a random free horizontal neighbour of the plant where the given block can stand, or null if there is
	 * none (vanilla BonemealableBlock.findSpreadableNeighbourPos(), used by bushes and tall dry grass).
	 */
	public static function findSpreadableNeighbour(Block $plant, Block $toPlace, Random $random) : ?Block{
		$faces = Facing::HORIZONTAL;
		//Fisher-Yates shuffle with the given random, so seeded tests are reproducible
		for($i = count($faces) - 1; $i > 0; --$i){
			$j = $random->nextBoundedInt($i + 1);
			[$faces[$i], $faces[$j]] = [$faces[$j], $faces[$i]];
		}
		$world = $plant->getPosition()->getWorld();
		foreach($faces as $face){
			$side = $plant->getPosition()->getSide($face);
			if(!$world->isInWorld($side->getFloorX(), $side->getFloorY(), $side->getFloorZ())){
				continue;
			}
			$replace = $world->getBlock($side);
			if($replace->getTypeId() === BlockTypeIds::AIR && $toPlace->canBePlacedAt($replace, Vector3::zero(), Facing::DOWN, true)){
				return $replace;
			}
		}
		return null;
	}

	/**
	 * One walk from the given position: returns where it ends, or null if it left the grass (the block below a step
	 * is not a grass block, or the step is in a full block).
	 *
	 * @phpstan-return array{int, int, int}|null
	 */
	private static function walk(World $world, int $x, int $y, int $z, int $steps, Random $random) : ?array{
		for($i = 0; $i < $steps; ++$i){
			$x += $random->nextBoundedInt(3) - 1;
			//Java integer division: only a 2 makes the step go up or down
			$y += intdiv(($random->nextBoundedInt(3) - 1) * $random->nextBoundedInt(3), 2);
			$z += $random->nextBoundedInt(3) - 1;
			if(
				!$world->isInWorld($x, $y - 1, $z) || !$world->isInWorld($x, $y, $z) ||
				$world->getBlockAt($x, $y - 1, $z)->getTypeId() !== BlockTypeIds::GRASS ||
				$world->getBlockAt($x, $y, $z)->isFullCube()
			){
				return null;
			}
		}
		return [$x, $y, $z];
	}

	private static function growTallGrass(World $world, int $x, int $y, int $z, DoublePlant $plant) : void{
		if(!$world->isInWorld($x, $y + 1, $z) || $world->getBlockAt($x, $y + 1, $z)->getTypeId() !== BlockTypeIds::AIR){
			return;
		}
		$world->setBlockAt($x, $y, $z, (clone $plant)->setTop(false));
		$world->setBlockAt($x, $y + 1, $z, (clone $plant)->setTop(true));
	}

	/**
	 * The grass of the biome's grass patches: ferns are common in taigas (4 in 5) and jungles (1 in 4).
	 */
	private static function biomeGrass(int $biome, Random $random) : Block{
		$fernChance = match($biome){
			BiomeIds::TAIGA, BiomeIds::TAIGA_HILLS, BiomeIds::TAIGA_MUTATED,
			BiomeIds::COLD_TAIGA, BiomeIds::COLD_TAIGA_HILLS, BiomeIds::COLD_TAIGA_MUTATED,
			BiomeIds::MEGA_TAIGA, BiomeIds::MEGA_TAIGA_HILLS,
			BiomeIds::REDWOOD_TAIGA_MUTATED, BiomeIds::REDWOOD_TAIGA_HILLS_MUTATED => 4,
			BiomeIds::JUNGLE, BiomeIds::JUNGLE_HILLS, BiomeIds::JUNGLE_EDGE, BiomeIds::JUNGLE_MUTATED,
			BiomeIds::JUNGLE_EDGE_MUTATED, BiomeIds::BAMBOO_JUNGLE, BiomeIds::BAMBOO_JUNGLE_HILLS => 1,
			default => 0
		};
		if($fernChance === 4){
			return $random->nextBoundedInt(5) < 4 ? VanillaBlocks::FERN() : VanillaBlocks::TALL_GRASS();
		}
		if($fernChance === 1){
			return $random->nextBoundedInt(4) === 0 ? VanillaBlocks::FERN() : VanillaBlocks::TALL_GRASS();
		}
		return VanillaBlocks::TALL_GRASS();
	}

	/**
	 * A flower of the biome's bone meal flower features (Java's can_spawn_from_bone_meal features of each biome), or
	 * null if the biome has none (badlands, mushroom fields, mangrove swamps, mountains, caves...).
	 */
	public static function biomeFlower(int $biome, int $x, int $z, Random $random) : ?Block{
		return match($biome){
			BiomeIds::PLAINS, BiomeIds::SUNFLOWER_PLAINS, BiomeIds::DRIPSTONE_CAVES, BiomeIds::DEEP_DARK => self::plainsFlower($x, $z, $random),
			BiomeIds::FLOWER_FOREST => self::flowerForestFlower($x, $z),
			BiomeIds::MEADOW => $random->nextBoundedInt(2) === 0 ? self::meadowFlower($random) : self::petals(VanillaBlocks::WILDFLOWERS(), $random),
			BiomeIds::BIRCH_FOREST, BiomeIds::BIRCH_FOREST_HILLS,
			BiomeIds::BIRCH_FOREST_MUTATED, BiomeIds::BIRCH_FOREST_HILLS_MUTATED => $random->nextBoundedInt(2) === 0 ? self::defaultFlower($random) : self::petals(VanillaBlocks::WILDFLOWERS(), $random),
			BiomeIds::SWAMPLAND, BiomeIds::SWAMPLAND_MUTATED => VanillaBlocks::BLUE_ORCHID(),
			BiomeIds::CHERRY_GROVE => self::petals(VanillaBlocks::PINK_PETALS(), $random),
			BiomeIds::PALE_GARDEN => VanillaBlocks::CLOSED_EYEBLOSSOM(),
			BiomeIds::MESA, BiomeIds::MESA_PLATEAU, BiomeIds::MESA_PLATEAU_STONE, BiomeIds::MESA_BRYCE,
			BiomeIds::MESA_PLATEAU_MUTATED, BiomeIds::MESA_PLATEAU_STONE_MUTATED,
			BiomeIds::MUSHROOM_ISLAND, BiomeIds::MUSHROOM_ISLAND_SHORE, BiomeIds::MANGROVE_SWAMP,
			BiomeIds::JAGGED_PEAKS, BiomeIds::FROZEN_PEAKS, BiomeIds::STONY_PEAKS, BiomeIds::SNOWY_SLOPES, BiomeIds::GROVE,
			BiomeIds::LUSH_CAVES, BiomeIds::SULFUR_CAVES,
			BiomeIds::HELL, BiomeIds::SOULSAND_VALLEY, BiomeIds::CRIMSON_FOREST, BiomeIds::WARPED_FOREST, BiomeIds::BASALT_DELTAS,
			BiomeIds::THE_END => null,
			default => self::defaultFlower($random)
		};
	}

	/** flower_default: poppy 2 : dandelion 1 */
	private static function defaultFlower(Random $random) : Block{
		return $random->nextBoundedInt(3) === 0 ? VanillaBlocks::DANDELION() : VanillaBlocks::POPPY();
	}

	/**
	 * flower_plain (noise_threshold provider): tulips where the noise is low, else poppy, azure bluet, oxeye daisy or
	 * cornflower 1 in 3, dandelion otherwise.
	 */
	private static function plainsFlower(int $x, int $z, Random $random) : Block{
		if(self::noise($x, $z, 0.005) < -0.8){
			return match($random->nextBoundedInt(4)){
				0 => VanillaBlocks::ORANGE_TULIP(),
				1 => VanillaBlocks::RED_TULIP(),
				2 => VanillaBlocks::PINK_TULIP(),
				default => VanillaBlocks::WHITE_TULIP()
			};
		}
		if($random->nextFloat() < 1 / 3){
			return match($random->nextBoundedInt(4)){
				0 => VanillaBlocks::POPPY(),
				1 => VanillaBlocks::AZURE_BLUET(),
				2 => VanillaBlocks::OXEYE_DAISY(),
				default => VanillaBlocks::CORNFLOWER()
			};
		}
		return VanillaBlocks::DANDELION();
	}

	/** flower_flower_forest (noise provider): patches of each of the 11 flowers */
	private static function flowerForestFlower(int $x, int $z) : Block{
		$flowers = [
			VanillaBlocks::DANDELION(), VanillaBlocks::POPPY(), VanillaBlocks::ALLIUM(), VanillaBlocks::AZURE_BLUET(),
			VanillaBlocks::RED_TULIP(), VanillaBlocks::ORANGE_TULIP(), VanillaBlocks::WHITE_TULIP(), VanillaBlocks::PINK_TULIP(),
			VanillaBlocks::OXEYE_DAISY(), VanillaBlocks::CORNFLOWER(), VanillaBlocks::LILY_OF_THE_VALLEY()
		];
		$n = count($flowers);
		$index = (int) ((self::noise($x, $z, 0.020833334) + 1) / 2 * $n);
		return $flowers[max(0, min($n - 1, $index))];
	}

	/** flower_meadow (dual noise provider, approximated with random picks): flowers and grass */
	private static function meadowFlower(Random $random) : Block{
		return match($random->nextBoundedInt(8)){
			0 => VanillaBlocks::ALLIUM(),
			1 => VanillaBlocks::POPPY(),
			2 => VanillaBlocks::AZURE_BLUET(),
			3 => VanillaBlocks::DANDELION(),
			4 => VanillaBlocks::CORNFLOWER(),
			5 => VanillaBlocks::OXEYE_DAISY(),
			default => VanillaBlocks::TALL_GRASS() //6 and 7

		};
	}

	/**
	 * Pink petals and wildflowers: 1-4 flowers facing a random direction.
	 */
	private static function petals(PinkPetals $block, Random $random) : Block{
		return $block
			->setCount($random->nextRange(PinkPetals::MIN_COUNT, PinkPetals::MAX_COUNT))
			->setFacing(Facing::HORIZONTAL[$random->nextBoundedInt(4)]);
	}

	/**
	 * Smooth 2D noise in -1..1 for the flower patches, the same for every world (like the fixed seed of the vanilla
	 * flower providers).
	 */
	private static function noise(int $x, int $z, float $scale) : float{
		$noise = self::$flowerNoise ??= new Simplex(new Random(2345), 1, 1.0, 1.0);
		return $noise->noise2D($x * $scale, $z * $scale, true);
	}
}
