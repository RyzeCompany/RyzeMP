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

use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\Utils;
use pocketmine\world\sound\BlockBreakSound;
use function mt_rand;

/**
 * Frog spawn (https://minecraft.wiki/w/Frogspawn): floats on top of water like a lily pad, has no collision and drops
 * nothing, even with silk touch. Hatches into 2-6 tadpoles 3600-12000 ticks after it is laid or placed (a scheduled
 * update, saved with the chunk). Frogspawn without a pending update (older worlds, set by plugins) schedules one on
 * its next random tick.
 */
class FrogSpawn extends Flowable{
	private const MIN_HATCH_DELAY = 3600;
	private const MAX_HATCH_DELAY = 12000;

	use StaticSupportTrait {
		canBePlacedAt as supportedWhenPlacedAt;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return !$blockReplace instanceof Water && $this->supportedWhenPlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	private function canBeSupportedAt(Block $block) : bool{
		$down = $block->getSide(Facing::DOWN);
		return $down instanceof Water && $down->isSource();
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onPostPlace() : void{
		$this->scheduleHatch();
	}

	public function onRandomTick() : void{
		$position = $this->position;
		if(!$position->getWorld()->isBlockUpdateScheduled($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())){
			$this->scheduleHatch();
		}
	}

	public function onScheduledUpdate() : void{
		$this->hatch();
	}

	private function scheduleHatch() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, mt_rand(self::MIN_HATCH_DELAY, self::MAX_HATCH_DELAY));
	}

	/**
	 * Removes the frogspawn and releases 2-6 tadpoles in the water below.
	 */
	public function hatch() : void{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new BlockBreakSound($this));
		for($i = mt_rand(2, 6); $i > 0; --$i){
			$tadpole = new Tadpole(Location::fromObject($this->position->add(0.2 + Utils::getRandomFloat() * 0.6, -0.8, 0.2 + Utils::getRandomFloat() * 0.6), $world, Utils::getRandomFloat() * 360, 0));
			$tadpole->setPersistent(true);
			$tadpole->spawnToAll();
		}
	}
}
