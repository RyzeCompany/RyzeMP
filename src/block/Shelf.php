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

use pocketmine\block\tile\Shelf as TileShelf;
use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\FacesOppositePlacingPlayerTrait;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\block\utils\ShelfChainPart;
use pocketmine\block\utils\WoodTypeTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\ShelfActivateSound;
use pocketmine\world\sound\ShelfDeactivateSound;
use pocketmine\world\vibration\GameEvent;
use function count;
use function floor;
use function max;
use function min;

/**
 * Wooden shelf (Bedrock 1.21.110+). Displays up to three item stacks on its front.
 *
 * Unpowered: using the front face swaps the held item with the clicked slot (in creative the held item is copied
 * into the slot instead, unless the hand is empty).
 * Powered: the shelf joins adjacent powered shelves facing the same way into chains of up to three (filled from the
 * left as seen from the front), and using any shelf of a chain swaps the whole chain with the rightmost 3, 6 or 9
 * hotbar slots.
 *
 * The powered state follows the redstone signal received by the shelf (on placement and neighbour changes); the
 * chain shape (powered_shelf_type) is recalculated from the neighbouring shelves.
 *
 * A comparator behind the shelf reads which slots hold an item: 1 for the left one, 2 for the middle one, 4 for the
 * right one (as seen from the front), added up.
 */
class Shelf extends Transparent implements HorizontalFacing, PoweredByRedstone, ComparatorReadable{
	use FacesOppositePlacingPlayerTrait{
		place as private placeFacingOppositePlayer;
	}
	use PoweredByRedstoneTrait;
	use WoodTypeTrait;

	public const MAX_CHAIN_LENGTH = 3;
	/** Limit for scanning rows of powered shelves, to keep updates bounded. */
	private const MAX_ROW_SCAN = 64;

	protected ShelfChainPart $chainPart = ShelfChainPart::UNCONNECTED;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->powered);
		$w->enum($this->chainPart);
	}

	public function getChainPart() : ShelfChainPart{ return $this->chainPart; }

	/**
	 * Sets the displayed chain shape. This is normally recalculated automatically from the neighbouring shelves.
	 * @return $this
	 */
	public function setChainPart(ShelfChainPart $chainPart) : self{
		$this->chainPart = $chainPart;
		return $this;
	}

	public function getFlameEncouragement() : int{
		return $this->getWoodType()->isFlammable() ? 30 : 0;
	}

	public function getFlammability() : int{
		return $this->getWoodType()->isFlammable() ? 20 : 0;
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	/**
	 * Direction pointing to the left of a player looking at the front of the shelf.
	 */
	private function getLeftDirection() : int{
		return Facing::rotateY(Facing::opposite($this->facing), false);
	}

	private function isChainableNeighbour(Block $block) : bool{
		return $block instanceof Shelf && $block->powered && $block->facing === $this->facing;
	}

	/**
	 * Returns the powered shelves of this shelf's chain, ordered from left to right (as seen from the front).
	 * Rows of adjacent powered shelves are split into chains of three starting from the left.
	 *
	 * @return Shelf[]
	 * @phpstan-return list<Shelf>
	 */
	public function getChain() : array{
		if(!$this->powered){
			return [$this];
		}
		$left = $this->getLeftDirection();
		$right = Facing::opposite($left);

		$index = 0; //position of this shelf in the row of powered shelves
		$cursor = $this;
		while($index < self::MAX_ROW_SCAN && $this->isChainableNeighbour($next = $cursor->getSide($left))){
			$cursor = $next;
			$index++;
		}
		$chainStart = $index - ($index % self::MAX_CHAIN_LENGTH);

		$chain = [];
		$cursor = $this;
		for($i = $index; $i > $chainStart; $i--){
			$cursor = $cursor->getSide($left);
		}
		for($i = $chainStart; $i < $chainStart + self::MAX_CHAIN_LENGTH; $i++){
			if(!$cursor instanceof Shelf || ($i !== $index && !$this->isChainableNeighbour($cursor))){
				break;
			}
			$chain[] = $cursor;
			$cursor = $cursor->getSide($right);
		}
		return $chain;
	}

	private function computeChainPart() : ShelfChainPart{
		if(!$this->powered){
			return ShelfChainPart::UNCONNECTED;
		}
		$chain = $this->getChain();
		$count = count($chain);
		if($count <= 1){
			return ShelfChainPart::UNCONNECTED;
		}
		foreach($chain as $i => $shelf){
			if($shelf->position->equals($this->position)){
				return match(true){
					$i === 0 => ShelfChainPart::LEFT,
					$i === $count - 1 => ShelfChainPart::RIGHT,
					default => ShelfChainPart::CENTER
				};
			}
		}
		return ShelfChainPart::UNCONNECTED;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		//vanilla ShelfBlock.getStateForPlacement(): placed powered next to a signal
		$world = $blockReplace->position->getWorld();
		if($world->getRedstone() !== null){
			$this->powered = $world->isBlockPowered($blockReplace->position);
		}
		return $this->placeFacingOppositePlayer($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$poweredChanged = false;
		if($world->getRedstone() !== null){
			//vanilla ShelfBlock.neighborChanged()
			$powered = $world->isBlockPowered($this->position);
			if($powered !== $this->powered){
				$this->powered = $powered;
				$poweredChanged = true;
			}
		}
		$chainPart = $this->computeChainPart();
		if($poweredChanged || $chainPart !== $this->chainPart){
			$this->chainPart = $chainPart;
			$world->setBlock($this->position, $this);
		}
		if($poweredChanged){
			$world->addSound($this->position, $this->powered ? new ShelfActivateSound($this) : new ShelfDeactivateSound($this));
			$world->addGameEvent($this->position, $this->powered ? GameEvent::BLOCK_ACTIVATE : GameEvent::BLOCK_DEACTIVATE);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($face !== $this->facing || $player === null){
			return false;
		}
		$tile = $this->position->getWorld()->getTile($this->position);
		if(!$tile instanceof TileShelf){
			return false;
		}

		if($this->powered){
			$this->swapChainWithHotbar($player);
			return true;
		}

		//slot 0 is the left one as seen from the front, like the chiseled bookshelf
		$x = Facing::axis($face) === Axis::X ? $clickVector->z : $clickVector->x;
		if(Facing::isPositive(Facing::rotateY($face, true))){
			$x = 1 - $x;
		}
		$slot = max(0, min(TileShelf::SLOTS - 1, (int) floor($x * TileShelf::SLOTS)));

		$inventory = $tile->getInventory();
		$stored = $inventory->getItem($slot);
		if($item->isNull() && $stored->isNull()){
			return true;
		}
		if(!$player->hasFiniteResources() && !$item->isNull()){
			//creative: put a copy of the held item on the shelf, replacing whatever was there
			$inventory->setItem($slot, clone $item);
			return true;
		}
		$inventory->setItem($slot, $item);
		$player->getInventory()->setItemInHand($stored);
		return true;
	}

	/**
	 * Swaps the contents of this shelf's chain with the rightmost hotbar slots of the player (3 per shelf).
	 */
	private function swapChainWithHotbar(Player $player) : void{
		$world = $this->position->getWorld();
		$playerInventory = $player->getInventory();
		$chain = $this->getChain();
		$firstHotbarSlot = $playerInventory->getHotbarSize() - TileShelf::SLOTS * count($chain);
		foreach($chain as $k => $shelf){
			$tile = $world->getTile($shelf->position);
			if(!$tile instanceof TileShelf){
				continue;
			}
			$shelfInventory = $tile->getInventory();
			$old = $shelfInventory->getContents(true);
			$new = [];
			for($j = 0; $j < TileShelf::SLOTS; $j++){
				$hotbarSlot = $firstHotbarSlot + TileShelf::SLOTS * $k + $j;
				$new[$j] = $playerInventory->getItem($hotbarSlot);
				$playerInventory->setItem($hotbarSlot, $old[$j]);
			}
			$shelfInventory->setContents($new);
		}
	}

	/**
	 * Bitmask of the filled slots, bit 0 for slot 0 (vanilla ShelfBlock.getAnalogOutputSignal()). Comparators only
	 * read it from behind the shelf, see {@link Shelf::getComparatorOutputFor()}.
	 */
	public function getComparatorOutput() : int{
		$tile = $this->position->isValid() ? $this->position->getWorld()->getTile($this->position) : null;
		if(!$tile instanceof TileShelf){
			return 0;
		}
		$inventory = $tile->getInventory();
		$output = 0;
		for($slot = 0; $slot < TileShelf::SLOTS; ++$slot){
			if(!$inventory->isSlotEmpty($slot)){
				$output |= 1 << $slot;
			}
		}
		return $output;
	}

	/**
	 * Output read by a comparator facing the given way (the side of the comparator its rear input is on): the shelf
	 * must have its back towards the comparator.
	 */
	public function getComparatorOutputFor(int $comparatorFacing) : int{
		return $comparatorFacing === $this->facing ? $this->getComparatorOutput() : 0;
	}

	public function getFuelTime() : int{
		return $this->getWoodType()->isFlammable() ? 300 : 0;
	}
}
