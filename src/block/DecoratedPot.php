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

use pocketmine\block\tile\DecoratedPot as TileDecoratedPot;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\item\Axe;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Hoe;
use pocketmine\item\Item;
use pocketmine\item\Pickaxe;
use pocketmine\item\PotterySherdType;
use pocketmine\item\PotterySherd;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\item\Shovel;
use pocketmine\item\Sword;
use pocketmine\item\Trident;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\DecoratedPotInsertSound;
use pocketmine\world\sound\DecoratedPotShatterSound;
use function floor;

/**
 * Decorated pot. The pattern on each side and the stored items are kept in the block entity.
 *
 * - Using an item on it stores one item (one stack of a single item type at most) and makes the pot wobble.
 * - Hopper, dropper and crafter integration requires the later RyzeMP item-transfer adapter.
 * - Broken with a sword, axe, pickaxe, shovel, hoe or trident, it shatters into its
 *   sherds (bricks for plain sides). Broken by hand, with any other item (spears included) or with silk touch, it
 *   drops itself with its sherds. The stored items always drop.
 * - Comparator integration depends on RyzeMP's later redstone adapter; this class exposes the output value.
 */
class DecoratedPot extends Transparent implements HorizontalFacing{
	use HorizontalFacingTrait;

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(1 / 16, 0, 1 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function getTile() : ?TileDecoratedPot{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileDecoratedPot ? $tile : null;
	}

	/**
	 * @return (PotterySherdType|null)[]
	 * @phpstan-return array{PotterySherdType|null, PotterySherdType|null, PotterySherdType|null, PotterySherdType|null}
	 */
	public function getSherds() : array{
		return $this->getTile()?->getSherds() ?? [null, null, null, null];
	}

	/**
	 * Returns a decorated pot item with the given sherds (vanilla stores them at the root of the item tag).
	 *
	 * @param (PotterySherdType|null)[] $sherds
	 */
	public function asItemWithSherds(array $sherds) : Item{
		$item = $this->asItem();
		if(TileDecoratedPot::hasDecorations($sherds)){
			$nbt = $item->getNamedTag();
			TileDecoratedPot::writeSherds($nbt, $sherds);
			$item->setNamedTag($nbt);
		}
		return $item;
	}

	/**
	 * @return Item[]
	 */
	private function getShards() : array{
		$drops = [];
		foreach($this->getSherds() as $sherd){
			$drops[] = $sherd === null ? VanillaItems::BRICK() : (new PotterySherd(new ItemIdentifier(ItemTypeIds::POTTERY_SHERD), "Pottery Sherd"))->setSherdType($sherd);
		}
		return $drops;
	}

	/**
	 * Bedrock item tag minecraft:is_tool (swords, axes, pickaxes, shovels, hoes and the mace, not spears) plus the
	 * trident (minecraft.wiki, both editions; Java #breaks_decorated_pots).
	 */
	private function shattersWith(Item $item) : bool{
		return $item instanceof Sword || $item instanceof Axe || $item instanceof Pickaxe || $item instanceof Shovel ||
			$item instanceof Hoe || $item instanceof Trident;
	}

	/**
	 * Container signal of the stored stack (vanilla AbstractContainerMenu.getRedstoneSignalFromContainer() with one
	 * slot): 0 when empty, otherwise 1 + floor(14 * count / max stack size).
	 */
	public function getComparatorOutput() : int{
		$stored = $this->getTile()?->getItem();
		if($stored === null){
			return 0;
		}
		return (int) floor(14 * $stored->getCount() / $stored->getMaxStackSize()) + 1;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->shattersWith($item) ? $this->getShards() : [$this->asItemWithSherds($this->getSherds())];
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [$this->asItemWithSherds($this->getSherds())];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return $this->asItemWithSherds($this->getSherds());
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->dropContents();
		if($this->shattersWith($item) && !$item->hasEnchantment(VanillaEnchantments::SILK_TOUCH())){
			$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new DecoratedPotShatterSound());
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	private function dropContents() : void{
		$tile = $this->getTile();
		if($tile !== null && ($stored = $tile->getItem()) !== null){
			$this->position->getWorld()->dropItem($this->position->add(0.5, 0.5, 0.5), $stored);
			$tile->setItem(null);
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$tile = $this->getTile();
		if($tile === null){
			return false;
		}
		$world = $this->position->getWorld();
		$center = $this->position->add(0.5, 0.5, 0.5);
		$stored = $tile->getItem();
		if(!$item->isNull() && ($stored === null || ($stored->canStackWith($item) && $stored->getCount() < $stored->getMaxStackSize()))){
			$added = $item->pop();
			if($stored !== null){
				$added->setCount($stored->getCount() + 1);
			}
			$tile->setItem($added);
			$world->broadcastPacketToViewersByTypeConverter($this->position, static fn(TypeConverter $converter) : array =>
				$tile->canSpawnTo($converter) ? [$tile->createAnimationPacket($converter, TileDecoratedPot::ANIMATION_WOBBLE_SUCCESS)] : []);
			$world->addSound($center, new DecoratedPotInsertSound(true));
		}else{
			$world->broadcastPacketToViewersByTypeConverter($this->position, static fn(TypeConverter $converter) : array =>
				$tile->canSpawnTo($converter) ? [$tile->createAnimationPacket($converter, TileDecoratedPot::ANIMATION_WOBBLE_FAIL)] : []);
			$world->addSound($center, new DecoratedPotInsertSound(false));
		}
		return true;
	}
}
