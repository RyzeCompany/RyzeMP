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

namespace pocketmine\player;

use pocketmine\crafting\CraftingRecipe;
use pocketmine\crafting\RecipeUnlockIndex;
use pocketmine\crafting\RecipeUnlocking;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryListener;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\UnlockedRecipesPacket;
use pocketmine\utils\Utils;
use pocketmine\world\GameRules;
use function array_values;
use function count;

/**
 * The recipes a player has unlocked in the recipe book, saved with the player data like BDS (which keeps them
 * server-side and tells the client with UnlockedRecipesPacket).
 *
 * Recipes are unlocked when the player gets one of their unlocking ingredients in the inventory, or by context: the
 * crafting table is always unlocked, boats when the player is in water and the chest when the inventory has items in
 * {@link RecipeUnlocking::MANY_ITEMS_SLOTS} slots. The client applies the recipesunlock (false: everything unlocked)
 * and showrecipemessages (toasts) game rules itself; doLimitedCrafting is enforced by {@link RecipeBook::canCraft()}.
 */
final class RecipeBook implements InventoryListener{
	public const TAG_UNLOCKED_RECIPES = "UnlockedRecipes"; //TAG_List<TAG_String>

	/**
	 * @var true[] recipe ID => true (IDs of recipes that no longer exist are kept, in case they come back)
	 * @phpstan-var array<string, true>
	 */
	private array $unlocked = [];

	private bool $initialSent = false;
	private bool $waitingForWater = false;
	private bool $waitingForManyItems = false;

	public function __construct(
		private Player $player
	){}

	/**
	 * @internal
	 */
	public function load(CompoundTag $nbt) : void{
		$tag = $nbt->getTag(self::TAG_UNLOCKED_RECIPES);
		if($tag instanceof ListTag){
			foreach($tag as $recipeId){
				if($recipeId instanceof StringTag){
					$this->unlocked[$recipeId->getValue()] = true;
				}
			}
		}
	}

	/**
	 * @internal
	 */
	public function save(CompoundTag $nbt) : void{
		if(count($this->unlocked) === 0){
			return;
		}
		$tags = [];
		foreach(Utils::stringifyKeys($this->unlocked) as $recipeId => $_){
			$tags[] = new StringTag($recipeId);
		}
		$nbt->setTag(self::TAG_UNLOCKED_RECIPES, new ListTag($tags));
	}

	private function getIndex() : RecipeUnlockIndex{
		return RecipeUnlockIndex::get($this->player->getServer()->getCraftingManager());
	}

	public function isUnlocked(string $recipeId) : bool{
		return isset($this->unlocked[$recipeId]);
	}

	/**
	 * Returns the IDs of the recipes the player unlocked (recipes that are always unlocked are not included).
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getUnlockedRecipes() : array{
		$result = [];
		foreach(Utils::stringifyKeys($this->unlocked) as $recipeId => $_){
			$result[] = $recipeId;
		}
		return $result;
	}

	/**
	 * Unlocks recipes by ID (see {@link RecipeUnlockIndex::getRecipeId()}); the client shows the "new recipes
	 * unlocked" toast for them.
	 *
	 * @param string[] $recipeIds
	 * @phpstan-param list<string> $recipeIds
	 */
	public function unlockRecipes(array $recipeIds) : void{
		$new = [];
		foreach($recipeIds as $recipeId){
			if(!isset($this->unlocked[$recipeId])){
				$this->unlocked[$recipeId] = true;
				$new[] = $recipeId;
			}
		}
		if(count($new) > 0 && $this->initialSent && $this->player->isConnected()){
			$this->player->getNetworkSession()->sendDataPacket(UnlockedRecipesPacket::create(UnlockedRecipesPacket::TYPE_NEWLY_UNLOCKED, $new));
		}
	}

	/**
	 * Whether the player may craft this recipe: with the doLimitedCrafting game rule only unlocked recipes can be crafted
	 * (unless recipesunlock is false, which unlocks everything).
	 */
	public function canCraft(CraftingRecipe $recipe) : bool{
		$world = $this->player->getWorld();
		if($world->getGameRule(GameRules::DO_LIMITED_CRAFTING) !== true || $world->getGameRule(GameRules::RECIPES_UNLOCK) === false){
			return true;
		}
		$index = $this->getIndex();
		$recipeId = $index->getRecipeId($recipe);
		if($recipeId === null){
			return true; //special recipes aren't in the recipe book
		}
		$context = $index->getContext($recipeId);
		return $context === null || $context === RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED || isset($this->unlocked[$recipeId]);
	}

	/**
	 * Sends the recipes unlocked so far, after unlocking the ones the player's items unlock (players who joined before
	 * recipes could be unlocked start with what their inventory unlocks).
	 *
	 * @internal
	 */
	public function sendInitial() : void{
		if($this->initialSent){
			return;
		}
		$index = $this->getIndex();
		foreach([$this->player->getInventory(), $this->player->getArmorInventory(), $this->player->getOffHandInventory()] as $inventory){
			$this->unlockItems($inventory->getContents());
		}
		$this->waitingForWater = $this->hasLockedContextRecipes($index, RecipeUnlocking::CONTEXT_PLAYER_IN_WATER);
		$this->waitingForManyItems = $this->hasLockedContextRecipes($index, RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS);
		$this->checkManyItems();

		$recipeIds = $index->getContextRecipes(RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED);
		foreach(Utils::stringifyKeys($this->unlocked) as $recipeId => $_){
			if($index->isKnownRecipe($recipeId)){
				$recipeIds[] = $recipeId;
			}
		}
		$this->initialSent = true;
		if($this->player->isConnected()){
			$this->player->getNetworkSession()->sendDataPacket(UnlockedRecipesPacket::create(UnlockedRecipesPacket::TYPE_INITIALLY_UNLOCKED, $recipeIds));
		}
	}

	private function hasLockedContextRecipes(RecipeUnlockIndex $index, int $context) : bool{
		foreach($index->getContextRecipes($context) as $recipeId){
			if(!isset($this->unlocked[$recipeId])){
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the player still has recipes that being in water unlocks (boats): the player checks it every second.
	 */
	public function isWaitingForWater() : bool{
		return $this->waitingForWater;
	}

	/**
	 * @internal
	 */
	public function onEnterWater() : void{
		if($this->waitingForWater){
			$this->waitingForWater = false;
			$this->unlockRecipes($this->getIndex()->getContextRecipes(RecipeUnlocking::CONTEXT_PLAYER_IN_WATER));
		}
	}

	private function checkManyItems() : void{
		if($this->waitingForManyItems && count($this->player->getInventory()->getContents()) >= RecipeUnlocking::MANY_ITEMS_SLOTS){
			$this->waitingForManyItems = false;
			$this->unlockRecipes($this->getIndex()->getContextRecipes(RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS));
		}
	}

	/**
	 * @param Item[] $items
	 */
	private function unlockItems(array $items) : void{
		$index = $this->getIndex();
		$recipeIds = [];
		foreach($items as $item){
			foreach($index->getRecipesUnlockedBy($item) as $recipeId){
				if(!isset($this->unlocked[$recipeId])){
					$recipeIds[$recipeId] = $recipeId;
				}
			}
		}
		if(count($recipeIds) > 0){
			$this->unlockRecipes(array_values($recipeIds));
		}
	}

	/**
	 * @internal
	 */
	public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
		$item = $inventory->getItem($slot);
		if($item->isNull() || ($item->getStateId() === $oldItem->getStateId() && !$oldItem->isNull())){
			return; //count changes (placing blocks, eating, picking up more of the same) never unlock anything
		}
		$this->unlockItems([$item]);
		if($this->waitingForManyItems && $oldItem->isNull() && $inventory === $this->player->getInventory()){
			$this->checkManyItems();
		}
	}

	/**
	 * @internal
	 */
	public function onContentChange(Inventory $inventory, array $oldContents) : void{
		$this->unlockItems($inventory->getContents());
		if($this->waitingForManyItems && $inventory === $this->player->getInventory()){
			$this->checkManyItems();
		}
	}
}
