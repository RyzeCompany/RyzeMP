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

use pocketmine\block\dispenser\DefaultDispenseBehavior;
use pocketmine\block\dispenser\DispenseContext;
use pocketmine\block\inventory\CrafterInventory;
use pocketmine\block\tile\Crafter as TileCrafter;
use pocketmine\block\utils\CrafterOrientation;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\crafting\CraftingGrid;
use pocketmine\crafting\MultiCraftingRecipe;
use pocketmine\crafting\RecipeIngredient;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\LiquidBucket;
use pocketmine\item\MilkBucket;
use pocketmine\item\Potion;
use pocketmine\item\PowderSnowBucket;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\CrafterCraftSound;
use pocketmine\world\sound\CrafterFailSound;
use function array_splice;
use function count;
use function max;
use function min;

/**
 * Crafter (Minecraft Wiki "Crafter"): on a rising redstone edge, waits 4 game ticks, then crafts one recipe from its
 * 3x3 grid and ejects the result from its front, into a container if there is one (whatever does not fit is dropped),
 * followed by the leftovers of the ingredients (empty buckets and bottles).
 */
class Crafter extends Opaque{
	public const CRAFT_DELAY_TICKS = 4;
	/** How long the "crafting" state (open mouth, glowing top) lasts after a craft (Java crafting_ticks_remaining). */
	public const CRAFTING_STATE_TICKS = 6;

	protected CrafterOrientation $orientation = CrafterOrientation::NORTH_UP;
	protected bool $crafting = false;
	protected bool $triggered = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->orientation);
		$w->bool($this->crafting);
		$w->bool($this->triggered);
	}

	public function getOrientation() : CrafterOrientation{ return $this->orientation; }

	/** @return $this */
	public function setOrientation(CrafterOrientation $orientation) : self{
		$this->orientation = $orientation;
		return $this;
	}

	public function isCrafting() : bool{ return $this->crafting; }

	/** @return $this */
	public function setCrafting(bool $crafting) : self{
		$this->crafting = $crafting;
		return $this;
	}

	public function isTriggered() : bool{ return $this->triggered; }

	/** @return $this */
	public function setTriggered(bool $triggered) : self{
		$this->triggered = $triggered;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$front = Dispenser::getPlacementFacing($this->position, $player);
			$horizontal = $player->getHorizontalFacing();
			$top = match($front){
				Facing::UP => $horizontal,
				Facing::DOWN => Facing::opposite($horizontal),
				default => Facing::UP
			};
			$this->orientation = CrafterOrientation::fromFrontAndTop($front, $top);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$tile = $this->position->getWorld()->getTile($this->position);
			if($tile instanceof TileCrafter && $tile->canOpenWith($item->getCustomName())){
				$player->setCurrentWindow($tile->getInventory());
			}
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$powered = $world->isBlockPowered($this->position);
		if($powered && !$this->triggered){
			$this->triggered = true;
			$world->setBlock($this->position, $this);
			$tile = $world->getTile($this->position);
			if($tile instanceof TileCrafter && $tile->getCraftAtTick() === null){
				$tile->setCraftAtTick($world->getServer()->getTick() + self::CRAFT_DELAY_TICKS);
				$world->scheduleDelayedBlockUpdate($this->position, self::CRAFT_DELAY_TICKS);
			}
		}elseif(!$powered && $this->triggered){
			$this->triggered = false;
			$world->setBlock($this->position, $this);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileCrafter){
			return;
		}
		$now = $world->getServer()->getTick();
		$craftAt = $tile->getCraftAtTick();
		if($craftAt !== null && $now >= $craftAt){
			$tile->setCraftAtTick(null);
			$this->craft($tile);
		}
		if($this->crafting && $now >= $tile->getCraftingUntilTick()){
			$this->crafting = false;
			$world->setBlock($this->position, $this);
		}

		//scheduled updates at the same position may be merged, so make sure nothing pending is forgotten
		$next = $tile->getCraftAtTick();
		if($this->crafting){
			$next = $next === null ? $tile->getCraftingUntilTick() : min($next, $tile->getCraftingUntilTick());
		}
		if($next !== null){
			$world->scheduleDelayedBlockUpdate($this->position, max(1, $next - $now));
		}
	}

	/**
	 * Crafts once if the grid holds a valid recipe. Returns whether something was crafted.
	 */
	public function craft(TileCrafter $tile) : bool{
		$world = $this->position->getWorld();
		$inventory = $tile->getInventory();
		$grid = new class(CraftingGrid::SIZE_BIG) extends CraftingGrid{};
		for($slot = 0; $slot < CrafterInventory::SIZE; ++$slot){
			$grid->setItem($slot, $inventory->getItem($slot));
		}
		$center = $this->position->add(0.5, 0.5, 0.5);

		$recipe = ItemTransfer::isEmpty($inventory) ? null : $world->getServer()->getCraftingManager()->matchRecipeByGrid($grid);
		$results = $recipe !== null ? $recipe->getResultsFor($grid) : [];
		if(count($results) === 0){
			$world->addSound($center, new CrafterFailSound());
			return false;
		}

		//grid items a multi recipe doesn't use up (e.g. the written book being copied) come out with the leftovers,
		//like Java CrafterBlock, which ejects the recipe's remaining items and then takes one of everything
		$ingredients = $recipe instanceof MultiCraftingRecipe ? $recipe->getIngredientListFor($grid) : null;
		$leftovers = [];
		for($slot = 0; $slot < CrafterInventory::SIZE; ++$slot){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}
			if($ingredients !== null && !self::takeIngredient($ingredients, $item)){
				$leftovers[] = (clone $item)->setCount(1);
			}else{
				$remainder = self::getCraftingRemainder($item);
				if($remainder !== null){
					$leftovers[] = $remainder;
				}
			}
			$inventory->setItem($slot, $item->setCount($item->getCount() - 1));
		}

		foreach($results as $result){
			$this->eject($result);
		}
		foreach($leftovers as $leftover){
			$this->eject($leftover);
		}
		if($recipe instanceof MultiCraftingRecipe){
			$recipe->onCraftExecuted($results);
		}

		$world->addSound($center, new CrafterCraftSound());
		$tile->setCraftingUntilTick($world->getServer()->getTick() + self::CRAFTING_STATE_TICKS);
		if(!$this->crafting){
			$this->crafting = true;
			$world->setBlock($this->position, $this);
		}
		return true;
	}

	/**
	 * Puts the item into the container (or chest/hopper minecart) in front of the crafter, dropping what does not fit.
	 */
	private function eject(Item $item) : void{
		$world = $this->position->getWorld();
		$front = $this->orientation->getFront();
		$target = $this->position->getSide($front);
		$x = $target->getFloorX();
		$y = $target->getFloorY();
		$z = $target->getFloorZ();
		if(ItemTransfer::canReceiveItems($world, $x, $y, $z)){
			$item = ItemTransfer::insertStack($world, $x, $y, $z, Facing::opposite($front), $item);
		}elseif(($minecart = ItemTransfer::getEntityContainerAt($world, $x, $y, $z)) !== null){
			//a chest or hopper minecart in front
			$item = clone $item;
			while(!$item->isNull() && ItemTransfer::insertOneIntoEntity($minecart, $item)){
				$item->pop();
			}
		}
		if(!$item->isNull()){
			$tile = $world->getTile($this->position);
			if($tile instanceof TileCrafter){
				DefaultDispenseBehavior::spawnItem(new DispenseContext($world, $this->position, $front, $tile->getInventory()), $item);
			}
		}
	}

	/**
	 * Removes the first ingredient accepting the item from the list. Returns false if none does.
	 *
	 * @param RecipeIngredient[] $ingredients
	 * @phpstan-param list<RecipeIngredient> $ingredients
	 */
	private static function takeIngredient(array &$ingredients, Item $item) : bool{
		foreach($ingredients as $k => $ingredient){
			if($ingredient->accepts($item)){
				array_splice($ingredients, $k, 1);
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the item left behind by an ingredient when crafting (vanilla "crafting remainder"), or null.
	 */
	public static function getCraftingRemainder(Item $item) : ?Item{
		if($item instanceof LiquidBucket || $item instanceof MilkBucket || $item instanceof PowderSnowBucket){
			return VanillaItems::BUCKET();
		}
		if($item instanceof Potion || $item->getTypeId() === ItemTypeIds::HONEY_BOTTLE || $item->getTypeId() === ItemTypeIds::DRAGON_BREATH){
			return VanillaItems::GLASS_BOTTLE();
		}
		return null;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
