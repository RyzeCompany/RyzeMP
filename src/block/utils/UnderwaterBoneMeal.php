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
use pocketmine\block\Coral;
use pocketmine\block\FloorCoralFan;
use pocketmine\block\Seagrass;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WallCoralFan;
use pocketmine\block\Water;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;
use function count;
use function intdiv;
use function mt_rand;

/**
 * Bone meal used on the face of a block under water (vanilla BoneMealItem.growWaterPlant()): 128 short random walks
 * from the water in front of the face, each placing seagrass, or now and then a coral or a coral fan, into a water
 * source, or turning seagrass into tall seagrass.
 *
 * Bedrock differences: the water in front of the face must be still with more water above it (2 water blocks deep),
 * and corals grow in any biome (Java: warm oceans only).
 *
 * @internal
 */
final class UnderwaterBoneMeal{
	private const ATTEMPTS = 128;

	private function __construct(){
		//NOOP
	}

	/**
	 * Tries to grow water plants from the water at the given position, the block in front of the face of the block
	 * which the bone meal was used on ($clickedFace, null for a dispenser). The bone meal is consumed unless a plugin
	 * cancels the growth.
	 *
	 * @return bool whether the bone meal was used (the position is deep enough water)
	 */
	public static function grow(World $world, int $x, int $y, int $z, ?int $clickedFace, Item $item, ?Player $player) : bool{
		if(!$world->isInWorld($x, $y + 1, $z)){
			return false;
		}
		$origin = $world->getBlockAt($x, $y, $z);
		if(!$origin instanceof Water || !$origin->isSource() || !LiquidLayer::hasWaterAt($world, $x, $y + 1, $z)){
			return false;
		}

		$tx = new BlockTransaction($world);
		for($j = 0; $j < self::ATTEMPTS; ++$j){
			$testX = $x;
			$testY = $y;
			$testZ = $z;
			for($i = 0, $steps = intdiv($j, 16); $i < $steps; ++$i){
				$testX += mt_rand(0, 2) - 1;
				$testY += intdiv((mt_rand(0, 2) - 1) * mt_rand(0, 2), 2);
				$testZ += mt_rand(0, 2) - 1;
				if(!$world->isInWorld($testX, $testY, $testZ) || $tx->fetchBlockAt($testX, $testY, $testZ)->isFullCube()){
					continue 2;
				}
			}

			$plant = self::choosePlant($j, $clickedFace);
			if($plant instanceof WallCoralFan){
				for($d = 0; $d < 4 && !self::canSurvive($tx, $plant, $testX, $testY, $testZ); ++$d){
					$plant->setFacing(Facing::HORIZONTAL[mt_rand(0, 3)]);
				}
			}
			if(!self::canSurvive($tx, $plant, $testX, $testY, $testZ)){
				continue;
			}

			$testBlock = $tx->fetchBlockAt($testX, $testY, $testZ);
			if($testBlock instanceof Water){
				if($testBlock->isSource() || $testBlock->isFalling()){
					$tx->addBlockAt($testX, $testY, $testZ, $plant);
				}
			}elseif($testBlock instanceof Seagrass && $world->isInWorld($testX, $testY + 1, $testZ)){
				//vanilla SeagrassBlock bone meal, with a 1/10 chance each time
				$above = $tx->fetchBlockAt($testX, $testY + 1, $testZ);
				if($above instanceof Water && $above->isSource() && mt_rand(0, 9) === 0){
					$tx->addBlockAt($testX, $testY, $testZ, VanillaBlocks::TALL_SEAGRASS()->setTop(false));
					$tx->addBlockAt($testX, $testY + 1, $testZ, VanillaBlocks::TALL_SEAGRASS()->setTop(true));
				}
			}
		}

		$ev = new StructureGrowEvent($origin, $tx, $player);
		$ev->call();
		if(!$ev->isCancelled()){
			$tx->apply();
			$item->pop();
		}
		return true;
	}

	/**
	 * Vanilla picks a wall coral fan facing out of the clicked side face on the first attempt, then seagrass with a 3/4
	 * chance, else one of the underwater_bonemeals tag (seagrass, corals, coral fans and wall coral fans).
	 */
	private static function choosePlant(int $attempt, ?int $clickedFace) : Block{
		if($attempt === 0 && $clickedFace !== null && Facing::axis($clickedFace) !== Axis::Y){
			return VanillaBlocks::WALL_CORAL_FAN()->setCoralType(self::randomCoralType())->setFacing($clickedFace);
		}
		if(mt_rand(0, 3) !== 0){
			return VanillaBlocks::SEAGRASS();
		}
		//the tag holds 16 blocks: seagrass, then 5 corals, 5 coral fans and 5 wall coral fans
		$index = mt_rand(0, 15);
		if($index === 0){
			return VanillaBlocks::SEAGRASS();
		}
		$coralType = CoralType::cases()[($index - 1) % 5];
		return match(intdiv($index - 1, 5)){
			0 => VanillaBlocks::CORAL()->setCoralType($coralType),
			1 => VanillaBlocks::CORAL_FAN()->setCoralType($coralType),
			default => VanillaBlocks::WALL_CORAL_FAN()->setCoralType($coralType),
		};
	}

	private static function randomCoralType() : CoralType{
		$cases = CoralType::cases();
		return $cases[mt_rand(0, count($cases) - 1)];
	}

	/**
	 * Returns whether the plant would stay at the given position, judged from the world with the changes made so far.
	 */
	private static function canSurvive(BlockTransaction $tx, Block $plant, int $x, int $y, int $z) : bool{
		if($plant instanceof WallCoralFan){
			$facing = $plant->getFacing();
			[$dx, , $dz] = Facing::OFFSET[Facing::opposite($facing)];
			return $tx->fetchBlockAt($x + $dx, $y, $z + $dz)->getSupportType($facing)->hasCenterSupport();
		}
		$below = $tx->fetchBlockAt($x, $y - 1, $z);
		if($plant instanceof Coral || $plant instanceof FloorCoralFan){
			return $below->getSupportType(Facing::UP)->hasCenterSupport();
		}
		//seagrass: a full top face, but not magma (Java SeagrassBlock.mayPlaceOn())
		return $below->getSupportType(Facing::UP) === SupportType::FULL && $below->getTypeId() !== BlockTypeIds::MAGMA;
	}
}
