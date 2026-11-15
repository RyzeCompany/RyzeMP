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
use pocketmine\block\utils\DirtPathHelper;
use pocketmine\block\utils\DirtType;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\item\Item;
use pocketmine\item\Shovel;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function mt_rand;

class Mycelium extends Opaque{

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::DIRT()->asItem()
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(!self::canStayAlive($world->getBlockAt($x, $y + 1, $z))){
			//covered by an opaque block or water: turns back into dirt (vanilla SpreadingSnowyBlock)
			BlockEventHelper::spread($this, VanillaBlocks::DIRT(), $this);
			return;
		}
		if($world->getFullLightAt($x, $y + 1, $z) < 9){
			return;
		}
		for($i = 0; $i < 4; ++$i){
			$bx = mt_rand($x - 1, $x + 1);
			$by = mt_rand($y - 3, $y + 1);
			$bz = mt_rand($z - 1, $z + 1);
			$block = $world->getBlockAt($bx, $by, $bz);
			if(!($block instanceof Dirt) || $block->getDirtType() !== DirtType::NORMAL){
				continue;
			}
			$above = $world->getBlockAt($bx, $by + 1, $bz);
			if(self::canStayAlive($above) && !LiquidLayer::isWater($above)){
				BlockEventHelper::spread($block, VanillaBlocks::MYCELIUM(), $this);
			}
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		return $item instanceof Shovel && DirtPathHelper::flatten($this, $item, $face);
	}

	/**
	 * Whether mycelium survives with the given block above it: not under a full water block or a block that fully
	 * blocks light (a single snow layer is always fine).
	 */
	private static function canStayAlive(Block $above) : bool{
		if($above instanceof SnowLayer && $above->getLayers() === 1){
			return true;
		}
		if(($above instanceof Water && ($above->isSource() || $above->isFalling())) || LiquidLayer::holdsWater($above)){
			return false;
		}
		return $above->getLightFilter() < 15;
	}
}
