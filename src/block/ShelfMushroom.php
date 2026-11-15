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
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;

/**
 * Shelf mushroom (Bedrock 1.26.40): a flat fungus that grows on the side of a block.
 *
 * Vanilla (wall_foliage_block, size_count 2) only uses growth 0 (small) and 1 (large): bone meal grows a small one into
 * a large one, which drops 2. Only these two states occur in the 1.26.60 network palette.
 */
class ShelfMushroom extends Flowable implements HorizontalFacing{
	use HorizontalFacingTrait;
	use StaticSupportTrait;

	public const MIN_GROWTH = 0;
	public const MAX_GROWTH = 1;
	/** Growth of a large shelf mushroom, the last vanilla stage. */
	private const LARGE_GROWTH = 1;

	protected int $growth = self::MIN_GROWTH;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(self::MIN_GROWTH, self::MAX_GROWTH, $this->growth);
	}

	public function getGrowth() : int{ return $this->growth; }

	/** @return $this */
	public function setGrowth(int $growth) : self{
		if($growth < self::MIN_GROWTH || $growth > self::MAX_GROWTH){
			throw new \InvalidArgumentException("Growth must be in range " . self::MIN_GROWTH . " ... " . self::MAX_GROWTH);
		}
		$this->growth = $growth;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(Facing::axis($face) === Axis::Y){
			return false;
		}
		$this->facing = $face;
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getSide(Facing::opposite($this->facing))->isSolid();
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer && $this->growth === self::MIN_GROWTH){
			if(BlockEventHelper::grow($this, (clone $this)->setGrowth(self::LARGE_GROWTH), $player)){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->asItem()->setCount($this->growth === self::LARGE_GROWTH ? 2 : 1)];
	}

	public function getLightLevel() : int{
		return 0; //BDS 1.26.51 properties export: shelf_mushroom does not emit light
	}
}
