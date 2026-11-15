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

namespace pocketmine\block;

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\World;
use function count;
use function min;
use function mt_rand;

class Nylium extends Opaque{

	/**
	 * @param Block[] $vegetation An array of Block instances that can be grown on this Nylium block using Bone Meal.
	 */
	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private readonly array $vegetation){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::NETHERRACK()->asItem()
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		//vanilla (NyliumBlock.randomTick): covered nylium turns into netherrack; it never spreads by itself (only with
		//bone meal, see onInteract())
		if(!$this->getSide(Facing::UP)->isTransparent()){
			BlockEventHelper::spread($this, VanillaBlocks::NETHERRACK(), $this);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$pos = $this->position;
		if($item instanceof Fertilizer && $pos->getWorld()->isInWorld($pos->getFloorX(), $pos->getFloorY() + 1, $pos->getFloorZ()) && $this->getSide(Facing::UP)->getTypeId() === BlockTypeIds::AIR){
			$item->pop();
			match($this->getTypeId()){
				BlockTypeIds::CRIMSON_NYLIUM => $this->growNetherVegetation(false),
				BlockTypeIds::WARPED_NYLIUM => $this->growNetherVegetation(true),
				default => $this->growVegetation(new Random())
			};
			return true;
		}
		return false;
	}

	/**
	 * Vanilla nylium bone meal (the nylium_bonemeal feature): 9 tries around the block above (each axis -2..2, a
	 * triangular distribution) to place crimson roots 87 : crimson fungus 11 : warped fungus 1 on crimson nylium,
	 * or warped roots 85 : warped fungus 13 : crimson roots 1 : crimson fungus 1 on warped nylium, which also gets 9
	 * tries of nether sprouts and, if the block above is still air, a 1 in 8 chance of twisting vines.
	 */
	private function growNetherVegetation(bool $warped) : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY() + 1;
		$z = $this->position->getFloorZ();
		$this->scatter($world, $x, $y, $z, $warped ?
			[[VanillaBlocks::WARPED_ROOTS(), 85], [VanillaBlocks::CRIMSON_ROOTS(), 1], [VanillaBlocks::WARPED_FUNGUS(), 13], [VanillaBlocks::CRIMSON_FUNGUS(), 1]] :
			[[VanillaBlocks::CRIMSON_ROOTS(), 87], [VanillaBlocks::CRIMSON_FUNGUS(), 11], [VanillaBlocks::WARPED_FUNGUS(), 1]]
		);
		if($warped){
			$this->scatter($world, $x, $y, $z, [[VanillaBlocks::NETHER_SPROUTS(), 1]]);
			//the vines' placement filter checks the origin first: it must still be air after the roots and sprouts
			if($world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::AIR && mt_rand(0, 7) === 0){
				$this->growTwistingVines($world, $x, $y, $z);
			}
		}
	}

	/**
	 * @param Block[][]|int[][] $weighted blocks and their weights
	 * @phpstan-param list<array{Block, int}> $weighted
	 */
	private function scatter(World $world, int $x, int $y, int $z, array $weighted) : void{
		$total = 0;
		foreach($weighted as [, $weight]){
			$total += $weight;
		}
		for($i = 0; $i < 9; ++$i){
			$px = $x + mt_rand(0, 2) + mt_rand(0, 2) - 2;
			$pz = $z + mt_rand(0, 2) + mt_rand(0, 2) - 2;
			$roll = mt_rand(0, $total - 1);
			$place = $weighted[0][0];
			foreach($weighted as [$block, $weight]){
				$roll -= $weight;
				if($roll < 0){
					$place = $block;
					break;
				}
			}
			$replace = $world->getBlockAt($px, $y, $pz);
			if($replace->getTypeId() === BlockTypeIds::AIR && $place->canBePlacedAt($replace, Vector3::zero(), Facing::DOWN, true)){
				$world->setBlockAt($px, $y, $pz, $place);
			}
		}
	}

	/**
	 * The twisting vines of warped nylium bone meal: 9 tries -3..3 blocks away (y -1..1), dropped to the ground
	 * (32 blocks at most), on netherrack, warped nylium or warped wart block; columns of 0-1 (weight 10), 1-3
	 * (weight 2) or no (weight 3) vine blocks below the tip, cut to the free space.
	 */
	private function growTwistingVines(World $world, int $x, int $y, int $z) : void{
		for($i = 0; $i < 9; ++$i){
			$px = $x + mt_rand(-3, 3);
			$py = $y + mt_rand(-1, 1) - 1;
			$pz = $z + mt_rand(-3, 3);
			//scan down to the first non-air block, then stand on it
			for($steps = 0; $steps < 32 && $world->isInWorld($px, $py, $pz) && $world->getBlockAt($px, $py, $pz)->getTypeId() === BlockTypeIds::AIR; ++$steps){
				--$py;
			}
			if(!$world->isInWorld($px, $py, $pz) || $world->getBlockAt($px, $py, $pz)->getTypeId() === BlockTypeIds::AIR){
				continue;
			}
			++$py;
			$groundId = $world->getBlockAt($px, $py - 1, $pz)->getTypeId();
			if(
				!$world->isInWorld($px, $py, $pz) || $world->getBlockAt($px, $py, $pz)->getTypeId() !== BlockTypeIds::AIR ||
				($groundId !== BlockTypeIds::NETHERRACK && $groundId !== BlockTypeIds::WARPED_NYLIUM && $groundId !== BlockTypeIds::WARPED_WART_BLOCK)
			){
				continue;
			}
			$roll = mt_rand(0, 14);
			$body = $roll < 10 ? mt_rand(0, 1) : ($roll < 12 ? mt_rand(1, 3) : 0);
			$free = 0;
			while($free < $body + 1 && $world->isInWorld($px, $py + $free, $pz) && $world->getBlockAt($px, $py + $free, $pz)->getTypeId() === BlockTypeIds::AIR){
				++$free;
			}
			//the tip has priority: the body is shortened first
			$body = min($body, $free - 1);
			for($dy = 0; $dy < $body; ++$dy){
				$world->setBlockAt($px, $py + $dy, $pz, VanillaBlocks::TWISTING_VINES()->setAge(NetherVines::MAX_AGE));
			}
			$world->setBlockAt($px, $py + $body, $pz, VanillaBlocks::TWISTING_VINES()->setAge(mt_rand(17, 25)));
		}
	}

	private function growVegetation(Random $random) : void{
		$world = $this->position->getWorld();

		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				if($random->nextBoundedInt(3) === 0){
					$pos = $this->position->add($x, 1, $z);
					$replace = $world->getBlock($pos);
					$place = $this->vegetation[$random->nextBoundedInt(count($this->vegetation))];
					if($world->isInWorld($pos->x, $pos->y, $pos->z) && $replace->getTypeId() === BlockTypeIds::AIR && $place->canBePlacedAt($replace, Vector3::zero(), Facing::DOWN, true)){
						$world->setBlock($pos, $place);
					}
				}
			}
		}
	}
}
