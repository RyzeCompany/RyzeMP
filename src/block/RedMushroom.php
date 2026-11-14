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
use pocketmine\block\utils\VanillaTreeGrower;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;
use function mt_rand;

class RedMushroom extends Flowable{
	/** Spread attempts: 1 in this many random ticks (MushroomBlock.randomTick()) */
	private const SPREAD_CHANCE = 25;
	/** A mushroom doesn't spread when this many mushrooms of its kind are in the 9x3x9 box around it (it included) */
	private const MAX_NEARBY = 5;
	/** Percent chance for bone meal to grow a huge mushroom (MushroomBlock.isBonemealSuccess()) */
	private const BONE_MEAL_CHANCE = 40;

	public function ticksRandomly() : bool{
		return true;
	}

	public function onNearbyBlockChange() : void{
		if(!self::canBeSupportedBy($this->getSide(Facing::DOWN))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(self::canBeSupportedBy($blockReplace->getSide(Facing::DOWN))){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}

		return false;
	}

	/**
	 * Since Bedrock 26.60 mushrooms are placed, and stay, on any block with a solid top face or a central support
	 * (fences, walls), whatever the light: only spreading still needs low light (see canSpreadAt()).
	 */
	private static function canBeSupportedBy(Block $down) : bool{
		return $down->getSupportType(Facing::UP)->hasCenterSupport();
	}

	/**
	 * Where a mushroom may spread (MushroomBlock.canSurvive(), unchanged in 26.60): on mycelium, podzol or nylium, or on
	 * an opaque block where the light is below 13.
	 */
	private static function canSpreadAt(World $world, int $x, int $y, int $z) : bool{
		$down = $world->getBlockAt($x, $y - 1, $z);
		$downId = $down->getTypeId();
		if($downId === BlockTypeIds::MYCELIUM || $downId === BlockTypeIds::PODZOL || $down->hasTypeTag(BlockTypeTags::NYLIUM)){
			return true;
		}
		return !$down->isTransparent() && $world->getPotentialLightAt($x, $y, $z) < 13;
	}

	public function onRandomTick() : void{
		if(mt_rand(0, self::SPREAD_CHANCE - 1) !== 0){
			return;
		}
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$typeId = $this->getTypeId();
		$nearby = 0;
		for($dx = -4; $dx <= 4; ++$dx){
			for($dz = -4; $dz <= 4; ++$dz){
				for($dy = -1; $dy <= 1; ++$dy){
					if($world->getBlockAt($x + $dx, $y + $dy, $z + $dz, addToCache: false)->getTypeId() === $typeId && ++$nearby >= self::MAX_NEARBY){
						return;
					}
				}
			}
		}

		//a short random walk through empty spots where the mushroom survives
		$tx = $x + mt_rand(0, 2) - 1;
		$ty = $y + mt_rand(0, 1) - mt_rand(0, 1);
		$tz = $z + mt_rand(0, 2) - 1;
		for($i = 0; $i < 4; ++$i){
			if($this->canSpreadTo($world, $tx, $ty, $tz)){
				$x = $tx;
				$y = $ty;
				$z = $tz;
			}
			$tx = $x + mt_rand(0, 2) - 1;
			$ty = $y + mt_rand(0, 1) - mt_rand(0, 1);
			$tz = $z + mt_rand(0, 2) - 1;
		}
		if($this->canSpreadTo($world, $tx, $ty, $tz)){
			BlockEventHelper::spread($world->getBlockAt($tx, $ty, $tz), clone $this, $this);
		}
	}

	private function canSpreadTo(World $world, int $x, int $y, int $z) : bool{
		return $world->isInWorld($x, $y - 1, $z) && $world->isInWorld($x, $y, $z) &&
			$world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::AIR &&
			self::canSpreadAt($world, $x, $y, $z);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		//vanilla (MushroomBlock): bone meal grows a huge mushroom with a 40% chance and is used either way, if the huge
		//mushroom fits under the build limit
		if($item instanceof Fertilizer && $this->position->getFloorY() + 7 < $this->position->getWorld()->getMaxY() && VanillaTreeGrower::isAvailable($this)){
			$item->pop();
			if(($player !== null && !$player->hasFiniteResources()) || mt_rand(0, 99) < self::BONE_MEAL_CHANCE){
				$pos = $this->position;
				VanillaTreeGrower::placeFeature(
					$this,
					$player,
					$this instanceof BrownMushroom ? "huge_brown_mushroom" : "huge_red_mushroom",
					[[$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), false]]
				);
			}
			return true;
		}
		return false;
	}

	public function getCompostabilityChance() : int{ return 65; }
}
