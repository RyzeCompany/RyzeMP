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

use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function abs;
use function mt_rand;

class SeaPickle extends Transparent implements Waterlogged{
	public const MIN_COUNT = 1;
	public const MAX_COUNT = 4;

	protected int $count = self::MIN_COUNT;
	protected bool $underwater = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::MIN_COUNT, self::MAX_COUNT, $this->count);
		$w->bool($this->underwater);
	}

	public function getCount() : int{ return $this->count; }

	/** @return $this */
	public function setCount(int $count) : self{
		if($count < self::MIN_COUNT || $count > self::MAX_COUNT){
			throw new \InvalidArgumentException("Count must be in range " . self::MIN_COUNT . " ... " . self::MAX_COUNT);
		}
		$this->count = $count;
		return $this;
	}

	public function isUnderwater() : bool{ return $this->underwater; }

	/** @return $this */
	public function setUnderwater(bool $underwater) : self{
		$this->underwater = $underwater;
		return $this;
	}

	public function isWaterlogged() : bool{ return $this->underwater; }

	public function isSolid() : bool{
		return false;
	}

	public function getLightLevel() : int{
		return $this->underwater ? ($this->count + 1) * 3 : 0;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		if(!$this->canBeSupportedAt($blockReplace)){
			return false;
		}
		return ($blockReplace instanceof SeaPickle && $blockReplace->count < self::MAX_COUNT) || parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($blockReplace instanceof SeaPickle && $blockReplace->count < self::MAX_COUNT){
			$this->count = $blockReplace->count + 1;
			$this->underwater = $blockReplace->underwater;
		}else{
			$this->underwater = WaterloggedHelper::isWaterSource($blockReplace);
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer && $this->underwater){
			//Bedrock wastes bone meal used on an underwater sea pickle which is not on a coral block
			if(!self::isLiveCoralBlock($this->getSide(Facing::DOWN)) || $this->growColony($player)){
				$item->pop();
			}
			return true;
		}
		return parent::onInteract($item, $face, $clickVector, $player, $returnedItems);
	}

	private static function isLiveCoralBlock(Block $block) : bool{
		return $block instanceof CoralBlock && !$block->isDead();
	}

	/**
	 * Vanilla SeaPickleBlock.performBonemeal(): the clicked pickle becomes a cluster of 4, and each water block above a
	 * live coral block in a diamond of radius 2 around it (at its height or one below) gets 1-4 pickles with a 1/6
	 * chance.
	 *
	 * @return bool whether the growth happened (not cancelled by a plugin)
	 */
	private function growColony(?Player $player) : bool{
		$world = $this->position->getWorld();
		$originX = $this->position->getFloorX();
		$originY = $this->position->getFloorY();
		$originZ = $this->position->getFloorZ();

		$tx = new BlockTransaction($world);
		$tx->addBlockAt($originX, $originY, $originZ, (clone $this)->setCount(self::MAX_COUNT));
		for($dx = -2; $dx <= 2; ++$dx){
			$zRadius = 2 - abs($dx);
			for($dz = -$zRadius; $dz <= $zRadius; ++$dz){
				for($y = $originY - 1; $y <= $originY; ++$y){
					if(($dx === 0 && $dz === 0 && $y === $originY) || mt_rand(0, 5) !== 0){
						continue;
					}
					$x = $originX + $dx;
					$z = $originZ + $dz;
					if(
						$world->isInWorld($x, $y - 1, $z) &&
						$world->getBlockAt($x, $y, $z) instanceof Water &&
						self::isLiveCoralBlock($world->getBlockAt($x, $y - 1, $z))
					){
						$tx->addBlockAt($x, $y, $z, VanillaBlocks::SEA_PICKLE()->setUnderwater(true)->setCount(mt_rand(self::MIN_COUNT, self::MAX_COUNT)));
					}
				}
			}
		}

		$ev = new StructureGrowEvent($this, $tx, $player);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$tx->apply();
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->asItem()->setCount($this->count)];
	}

	/**
	 * Vanilla: sea pickles need a block with a sturdy top face below them.
	 */
	private function canBeSupportedAt(Block $block) : bool{
		return $block->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
		}elseif($this->underwater){
			WaterloggedHelper::scheduleFlow($this);
		}
	}

	public function onScheduledUpdate() : void{
		if($this->underwater){
			WaterloggedHelper::flow($this);
		}
	}

	public function hasEntityCollision() : bool{
		return $this->underwater;
	}

	public function onEntityInside(Entity $entity) : bool{
		return !$this->underwater || VanillaBlocks::WATER()->onEntityInside($entity);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->underwater){
			WaterloggedHelper::leaveWater($this);
			return true;
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function getCompostabilityChance() : int{
		return 65;
	}

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}
}
