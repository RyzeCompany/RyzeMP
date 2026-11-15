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

namespace pocketmine\entity\golem;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\CarvedPumpkin;
use pocketmine\block\Copper;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\World;

/**
 * Building golems by placing a carved pumpkin or a jack o'lantern on top of a body (vanilla CarvedPumpkinBlock):
 * - snow golem: two snow blocks;
 * - iron golem: a T of four iron blocks with air in the two corners beside the head and beside the legs (built in
 *   either horizontal direction); the golem is player-created and never turns on players;
 * - copper golem: one copper block (any oxidation, waxed or not), which turns into a copper chest of the same
 *   oxidation and wax facing like the pumpkin; the golem stands on it with the oxidation of the block.
 * Snow golems are checked first, then iron golems, then copper golems. The pattern blocks are removed with break
 * particles. Only upright patterns count (Java also accepts lying ones, Bedrock does not).
 */
final class GolemConstruction{
	public const SNOW_GOLEM = "snow_golem";
	public const IRON_GOLEM = "iron_golem";
	public const COPPER_GOLEM = "copper_golem";

	private function __construct(){
		//NOOP
	}

	/**
	 * Finds the golem pattern whose head is the pumpkin at the given position.
	 *
	 * @phpstan-param \Closure(int, int, int) : Block $getBlock
	 * @return array{string, list<Vector3>, Vector3}|null type, positions of the pattern blocks (head first) and the
	 *                                                    block the golem spawns in
	 */
	public static function findPattern(\Closure $getBlock, int $x, int $y, int $z) : ?array{
		if(!$getBlock($x, $y, $z) instanceof CarvedPumpkin){
			return null;
		}
		$head = new Vector3($x, $y, $z);
		if(self::is($getBlock($x, $y - 1, $z), BlockTypeIds::SNOW) && self::is($getBlock($x, $y - 2, $z), BlockTypeIds::SNOW)){
			return [self::SNOW_GOLEM, [$head, new Vector3($x, $y - 1, $z), new Vector3($x, $y - 2, $z)], new Vector3($x, $y - 2, $z)];
		}
		if(self::is($getBlock($x, $y - 1, $z), BlockTypeIds::IRON) && self::is($getBlock($x, $y - 2, $z), BlockTypeIds::IRON)){
			foreach([[1, 0], [0, 1]] as [$dx, $dz]){
				if(
					self::is($getBlock($x - $dx, $y - 1, $z - $dz), BlockTypeIds::IRON) && self::is($getBlock($x + $dx, $y - 1, $z + $dz), BlockTypeIds::IRON) &&
					self::isAir($getBlock($x - $dx, $y, $z - $dz)) && self::isAir($getBlock($x + $dx, $y, $z + $dz)) &&
					self::isAir($getBlock($x - $dx, $y - 2, $z - $dz)) && self::isAir($getBlock($x + $dx, $y - 2, $z + $dz))
				){
					return [self::IRON_GOLEM, [
						$head,
						new Vector3($x - $dx, $y - 1, $z - $dz), new Vector3($x, $y - 1, $z), new Vector3($x + $dx, $y - 1, $z + $dz),
						new Vector3($x, $y - 2, $z)
					], new Vector3($x, $y - 2, $z)];
				}
			}
		}
		if(self::is($getBlock($x, $y - 1, $z), BlockTypeIds::COPPER)){
			return [self::COPPER_GOLEM, [$head, new Vector3($x, $y - 1, $z)], $head];
		}
		return null;
	}

	private static function is(Block $block, int $typeId) : bool{
		return $block->getTypeId() === $typeId;
	}

	private static function isAir(Block $block) : bool{
		return $block->getTypeId() === BlockTypeIds::AIR;
	}

	/**
	 * Builds the golem whose head was just placed at the given position, if the blocks below form a golem.
	 */
	public static function trySpawnGolem(World $world, Vector3 $pumpkinPos) : ?Entity{
		$x = $pumpkinPos->getFloorX();
		$y = $pumpkinPos->getFloorY();
		$z = $pumpkinPos->getFloorZ();
		$match = self::findPattern(fn(int $bx, int $by, int $bz) : Block => $world->isInWorld($bx, $by, $bz) ? $world->getBlockAt($bx, $by, $bz) : VanillaBlocks::AIR(), $x, $y, $z);
		if($match === null){
			return null;
		}
		[$type, $positions, $spawnPos] = $match;
		$pumpkin = $world->getBlockAt($x, $y, $z);
		$copper = $type === self::COPPER_GOLEM ? $world->getBlockAt($x, $y - 1, $z) : null;

		foreach($positions as $pos){
			$block = $world->getBlock($pos);
			$world->setBlock($pos, VanillaBlocks::AIR());
			$world->addParticle($pos->add(0.5, 0.5, 0.5), new BlockBreakParticle($block));
		}

		$location = new Location($spawnPos->x + 0.5, $spawnPos->y + 0.05, $spawnPos->z + 0.5, $world, 0, 0);
		if($type === self::SNOW_GOLEM){
			$golem = new SnowGolem($location);
		}elseif($type === self::IRON_GOLEM){
			$golem = new IronGolem($location);
			$golem->setPlayerCreated(true);
		}else{
			$golem = new CopperGolem($location);
			if($copper instanceof Copper){
				$golem->setOxidationLevel($copper->getOxidation()->value);
				$chest = VanillaBlocks::COPPER_CHEST()->setOxidation($copper->getOxidation())->setWaxed($copper->isWaxed());
				if($pumpkin instanceof CarvedPumpkin){
					$chest->setFacing($pumpkin->getFacing());
				}
				$world->setBlockAt($x, $y - 1, $z, $chest);
			}
		}
		$golem->spawnToAll();
		return $golem;
	}
}
