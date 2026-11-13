<?php

declare(strict_types=1);

namespace pocketmine\world\redstone;

use pocketmine\block\Block;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Container;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\inventory\Inventory;
use pocketmine\math\Facing;
use pocketmine\world\Position;
use function floor;
use function max;
use function min;

/** Native container readout and targeted notifications when their contents change. */
final class ComparatorInput{
	private function __construct(){}

	public static function getInventoryPower(Inventory $inventory) : int{
		$fullness = 0.0;
		foreach($inventory->getContents() as $item){
			if(!$item->isNull()){
				$fullness += min(1.0, $item->getCount() / max(1, min($item->getMaxStackSize(), $inventory->getMaxStackSize())));
			}
		}
		return $fullness > 0 && $inventory->getSize() > 0 ? min(15, (int) floor(14 * $fullness / $inventory->getSize()) + 1) : 0;
	}

	/** null means this block has no container readout, distinct from an empty container. */
	public static function getOverride(Block $block) : ?int{
		$tile = $block->getPosition()->getWorld()->getTile($block->getPosition());
		return $tile instanceof Container ? self::getInventoryPower($tile->getInventory()) : null;
	}

	public static function notifyInventoryChange(Inventory $inventory) : void{
		if(!$inventory instanceof BlockInventory){
			return;
		}
		$holder = $inventory->getHolder();
		if(!$holder->isValid()){
			return;
		}
		self::notifyAt($holder);
		$tile = $holder->getWorld()->getTile($holder);
		if($tile instanceof Chest && ($pair = $tile->getPair()) !== null){
			self::notifyAt($pair->getPosition());
		}
	}

	/** Also used for block replacement, which can remove a readout behind a solid block. */
	public static function notifyAt(Position $holder) : void{
		$world = $holder->getWorld();
		foreach(Facing::HORIZONTAL as $side){
			for($distance = 1; $distance <= 2; ++$distance){
				$pos = $holder->getSide($side, $distance);
				$block = RedstoneSignal::getBlockAt($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
				if($block instanceof RedstoneComparator && $block->getFacing() === $side){
					$world->notifyNeighbourBlockUpdate($pos);
				}
				if($block === null || !RedstoneSignal::isConductor($block)){
					break;
				}
			}
		}
	}
}
