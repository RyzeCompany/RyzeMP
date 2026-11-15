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

namespace pocketmine\network\mcpe\handler;

use pocketmine\block\Beacon;
use pocketmine\block\inventory\AnvilInventory;
use pocketmine\block\inventory\BeaconInventory;
use pocketmine\block\inventory\CartographyTableInventory;
use pocketmine\block\inventory\EnchantInventory;
use pocketmine\block\inventory\GrindstoneInventory;
use pocketmine\block\inventory\LoomInventory;
use pocketmine\block\inventory\SmithingTableInventory;
use pocketmine\crafting\CartographyMapRecipe;
use pocketmine\crafting\MultiCraftingRecipe;
use pocketmine\data\bedrock\BannerPatternTypeIdMap;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\TradeInventory;
use pocketmine\inventory\transaction\action\CreateItemAction;
use pocketmine\inventory\transaction\action\DestroyItemAction;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\AnvilTransaction;
use pocketmine\inventory\transaction\BeaconPaymentTransaction;
use pocketmine\inventory\transaction\CartographyRenameTransaction;
use pocketmine\inventory\transaction\CartographyTransaction;
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\inventory\transaction\EnchantingTransaction;
use pocketmine\inventory\transaction\GrindstoneTransaction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\LoomTransaction;
use pocketmine\inventory\transaction\SmithingTransaction;
use pocketmine\inventory\transaction\TradeTransaction;
use pocketmine\inventory\transaction\TransactionBuilder;
use pocketmine\inventory\transaction\TransactionBuilderInventory;
use pocketmine\item\Bundle;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\cache\CraftingDataCache;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\BeaconPaymentStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingConsumeInputStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftingCreateSpecificResultStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeAutoStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeOptionalStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CreativeCreateStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DestroyStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DropStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\GrindstoneStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestSlotInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\LoomStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\MineBlockStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\PlaceStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\SwapStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\TakeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Utils;
use pocketmine\world\map\MapManager;
use function array_key_first;
use function count;
use function implode;
use function spl_object_id;

class ItemStackRequestExecutor{
	private TransactionBuilder $builder;

	/** @var ItemStackRequestSlotInfo[] */
	private array $requestSlotInfos = [];

	private ?InventoryTransaction $specialTransaction = null;
	private ?GrindstoneInventory $grindstoneWindow = null;
	private ?BeaconInventory $beaconWindow = null;

	/** @var Item[] */
	private array $craftingResults = [];

	/**
	 * @var int[] dynamic container IDs of the bundles whose contents this request touches
	 * @phpstan-var array<int, int>
	 */
	private array $bundleContainerIds = [];
	/**
	 * @var true[][] spl_object_id(inventory) => slot => true, for every slot this request touches
	 * @phpstan-var array<int, array<int, true>>
	 */
	private array $touchedSlots = [];
	/** @phpstan-var array{Inventory, int}|null */
	private ?array $lastLocatedSlot = null;
	/**
	 * Bundles moved or swapped by this request, as [inventory A, slot A, inventory B, slot B].
	 * @phpstan-var list<array{Inventory, int, Inventory, int}>
	 */
	private array $bundleMoves = [];

	private ?Item $nextCreatedItem = null;
	private bool $createdItemFromCreativeInventory = false;
	private int $createdItemsTakenCount = 0;

	public function __construct(
		private Player $player,
		private InventoryManager $inventoryManager,
		private ItemStackRequest $request
	){
		$this->builder = new TransactionBuilder();
	}

	protected function prettyInventoryAndSlot(Inventory $inventory, int $slot) : string{
		if($inventory instanceof TransactionBuilderInventory){
			$inventory = $inventory->getActualInventory();
		}
		return (new \ReflectionClass($inventory))->getShortName() . "#" . spl_object_id($inventory) . ", slot: $slot";
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	private function matchItemStack(Inventory $inventory, int $slotId, int $clientItemStackId) : void{
		$info = $this->inventoryManager->getItemStackInfo($inventory, $slotId);
		if($info === null){
			throw new AssumptionFailedError("The inventory is tracked and the slot is valid, so this should not be null");
		}

		if(!($clientItemStackId < 0 ? $info->getRequestId() === $clientItemStackId : $info->getStackId() === $clientItemStackId)){
			throw new ItemStackRequestProcessException(
				$this->prettyInventoryAndSlot($inventory, $slotId) . ": " .
				"Mismatched expected itemstack, " .
				"client expected: $clientItemStackId, server actual: " . $info->getStackId() . ", last modified by request: " . ($info->getRequestId() ?? "none")
			);
		}
	}

	/**
	 * @phpstan-return array{TransactionBuilderInventory, int}
	 *
	 * @throws ItemStackRequestProcessException
	 */
	protected function getBuilderInventoryAndSlot(ItemStackRequestSlotInfo $info) : array{
		$containerName = $info->getContainerName();
		if($containerName->getContainerId() === ContainerUIIds::DYNAMIC){
			//the contents of a bundle (FullContainerName DynamicContainer + the bundle_id of the bundle item)
			$dynamicId = $containerName->getDynamicId() ?? throw new ItemStackRequestProcessException("Dynamic container without a dynamic ID");
			$windowAndSlot = $this->inventoryManager->locateBundleContainerSlot($dynamicId, $info->getSlotId());
			if($windowAndSlot === null){
				throw new ItemStackRequestProcessException("No bundle matches dynamic container ID $dynamicId, slot ID: " . $info->getSlotId());
			}
			$this->bundleContainerIds[$dynamicId] = $dynamicId;
		}else{
			[$windowId, $slotId] = ItemStackContainerIdTranslator::translate($containerName->getContainerId(), $this->inventoryManager->getCurrentWindowId(), $info->getSlotId());
			$windowAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slotId);
			if($windowAndSlot === null){
				throw new ItemStackRequestProcessException("No open inventory matches container UI ID: " . $containerName->getContainerId() . ", slot ID: " . $info->getSlotId());
			}
		}
		[$inventory, $slot] = $windowAndSlot;
		if(!$inventory->slotExists($slot)){
			throw new ItemStackRequestProcessException("No such inventory slot :" . $this->prettyInventoryAndSlot($inventory, $slot));
		}

		//the itemstack may have been modified by the current request: the client then names it by the request ID, or by
		//0 if an earlier action of the request emptied it (enchanting: the item is consumed, then placed back from the
		//output; BDS refuses the stack ID from before the request there)
		$emptiedByRequest = $info->getStackId() === 0 &&
			isset($this->touchedSlots[spl_object_id($inventory)][$slot]) &&
			$this->builder->getInventory($inventory)->getItem($slot)->isNull();
		if($info->getStackId() !== $this->request->getRequestId() && !$emptiedByRequest){
			$this->matchItemStack($inventory, $slot, $info->getStackId());
		}

		$this->touchedSlots[spl_object_id($inventory)][$slot] = true;
		$this->lastLocatedSlot = [$inventory, $slot];
		return [$this->builder->getInventory($inventory), $slot];
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function transferItems(ItemStackRequestSlotInfo $source, ItemStackRequestSlotInfo $destination, int $count) : void{
		$this->lastLocatedSlot = null;
		$removed = $this->removeItemFromSlot($source, $count);
		$sourceSlot = $this->takeLastLocatedSlot();
		$this->addItemToSlot($destination, $removed, $count);
		$destinationSlot = $this->takeLastLocatedSlot();
		if($removed instanceof Bundle && $sourceSlot !== null && $destinationSlot !== null){
			//the client keeps the bundle_id of a bundle it moves, so its dynamic container must follow it
			$this->bundleMoves[] = [$sourceSlot[0], $sourceSlot[1], $destinationSlot[0], $destinationSlot[1]];
		}
	}

	/**
	 * @phpstan-return array{Inventory, int}|null
	 */
	private function takeLastLocatedSlot() : ?array{
		$slot = $this->lastLocatedSlot;
		$this->lastLocatedSlot = null;
		return $slot;
	}

	/**
	 * Deducts items from an inventory slot, returning a stack containing the removed items.
	 * @throws ItemStackRequestProcessException
	 */
	protected function removeItemFromSlot(ItemStackRequestSlotInfo $slotInfo, int $count) : Item{
		if($slotInfo->getContainerName()->getContainerId() === ContainerUIIds::CREATED_OUTPUT && $slotInfo->getSlotId() === UIInventorySlotOffset::CREATED_ITEM_OUTPUT){
			//special case for the "created item" output slot
			//TODO: do we need to send a response for this slot info?
			return $this->takeCreatedItem($count);
		}
		$this->requestSlotInfos[] = $slotInfo;
		[$inventory, $slot] = $this->getBuilderInventoryAndSlot($slotInfo);
		if($count < 1){
			//this should be impossible at the protocol level, but in case of buggy core code this will prevent exploits
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Cannot take less than 1 items from a stack");
		}

		$existingItem = $inventory->getItem($slot);
		if($existingItem->getCount() < $count){
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Cannot take $count items from a stack of " . $existingItem->getCount());
		}

		$removed = $existingItem->pop($count);
		$inventory->setItem($slot, $existingItem);

		return $removed;
	}

	/**
	 * Adds items to the target slot, if they are stackable.
	 * @throws ItemStackRequestProcessException
	 */
	protected function addItemToSlot(ItemStackRequestSlotInfo $slotInfo, Item $item, int $count) : void{
		$this->requestSlotInfos[] = $slotInfo;
		[$inventory, $slot] = $this->getBuilderInventoryAndSlot($slotInfo);
		if($count < 1){
			//this should be impossible at the protocol level, but in case of buggy core code this will prevent exploits
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Cannot take less than 1 items from a stack");
		}

		$existingItem = $inventory->getItem($slot);
		if(!$existingItem->isNull() && !$existingItem->canStackWith($item)){
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Can only add items to an empty slot, or a slot containing the same item");
		}

		//we can't use the existing item here; it may be an empty stack
		$newItem = clone $item;
		$newItem->setCount($existingItem->getCount() + $count);
		$inventory->setItem($slot, $newItem);
	}

	protected function dropItem(Item $item, int $count) : void{
		if($count < 1){
			throw new ItemStackRequestProcessException("Cannot drop less than 1 of an item");
		}
		$this->builder->addAction(new DropItemAction((clone $item)->setCount($count)));
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function setNextCreatedItem(?Item $item, bool $creative = false) : void{
		if($item !== null && $item->isNull()){
			$item = null;
		}
		if($this->nextCreatedItem !== null){
			//while this is more complicated than simply adding the action when the item is taken, this ensures that
			//plugins can tell the difference between 1 item that got split into 2 slots, vs 2 separate items.
			if($this->createdItemFromCreativeInventory && $this->createdItemsTakenCount > 0){
				$this->nextCreatedItem->setCount($this->createdItemsTakenCount);
				$this->builder->addAction(new CreateItemAction($this->nextCreatedItem));
			}elseif($this->createdItemsTakenCount < $this->nextCreatedItem->getCount()){
				throw new ItemStackRequestProcessException("Not all of the previous created item was taken");
			}
		}
		$this->nextCreatedItem = $item;
		$this->createdItemFromCreativeInventory = $creative;
		$this->createdItemsTakenCount = 0;
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function beginCrafting(int $recipeId, int $repetitions) : void{
		if($this->specialTransaction !== null){
			throw new ItemStackRequestProcessException("Another special transaction is already in progress");
		}
		if($repetitions < 1){
			throw new ItemStackRequestProcessException("Cannot craft a recipe less than 1 time");
		}
		if($repetitions > 256){
			//TODO: we can probably lower this limit to 64, but I'm unsure if there are cases where the client may
			//request more than 64 repetitions of a recipe.
			//It's already hard-limited to 256 repetitions in the protocol, so this is just a sanity check.
			throw new ItemStackRequestProcessException("Cannot craft a recipe more than 256 times");
		}
		$craftingManager = $this->player->getServer()->getCraftingManager();
		$recipeIndex = $recipeId - CraftingDataCache::RECIPE_ID_OFFSET;
		$recipe = $craftingManager->getCraftingRecipeFromIndex($recipeIndex);
		if($recipe === null){
			throw new ItemStackRequestProcessException("No such crafting recipe index: $recipeIndex");
		}
		if($recipe instanceof MultiCraftingRecipe){
			//client-hardcoded recipes: don't trust which one the client claims, use the one matching the grid
			$recipe = $this->matchMultiCraftingRecipe();
		}
		if(!$this->player->getRecipeBook()->canCraft($recipe)){
			throw new ItemStackRequestProcessException("Recipe $recipeIndex is locked (doLimitedCrafting)");
		}

		$this->specialTransaction = new CraftingTransaction($this->player, $craftingManager, [], $recipe, $repetitions);

		//TODO: Since the system assumes that crafting can only be done in the crafting grid, we have to give it a
		//crafting grid to make the API happy. No implementation of getResultsFor() actually uses the crafting grid
		//right now, so this will work, but this will become a problem in the future for things like shulker boxes and
		//custom crafting recipes.
		$craftingResults = $recipe->getResultsFor($this->player->getActiveCraftingGrid());
		foreach($craftingResults as $k => $craftingResult){
			$craftingResult->setCount($craftingResult->getCount() * $repetitions);
			$this->craftingResults[$k] = $craftingResult;
		}
		if(count($this->craftingResults) === 1){
			//for multi-output recipes, later actions will tell us which result to create and when
			$this->setNextCreatedItem($this->craftingResults[array_key_first($this->craftingResults)]);
		}
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	private function matchMultiCraftingRecipe() : MultiCraftingRecipe{
		$grid = $this->player->getActiveCraftingGrid();
		foreach($this->player->getServer()->getCraftingManager()->getSpecialCraftingRecipes() as $recipe){
			if($recipe instanceof MultiCraftingRecipe && $recipe->matchesCraftingGrid($grid)){
				return $recipe;
			}
		}
		$contents = [];
		foreach($grid->getContents() as $slot => $item){
			$contents[] = "$slot: $item";
		}
		throw new ItemStackRequestProcessException("The crafting grid contents don't match any hardcoded recipe (" . implode(", ", $contents) . ")");
	}

	/**
	 * A trade with a villager or wandering trader: the recipe ID is the trade's network ID (see
	 * {@link \pocketmine\entity\npc\TradeOffer}); the payment is consumed from the trade slots by later actions and
	 * {@link TradeTransaction} checks it.
	 *
	 * @throws ItemStackRequestProcessException
	 */
	protected function beginTrade(TradeInventory $window, int $networkId, int $repetitions) : void{
		if($this->specialTransaction !== null){
			throw new ItemStackRequestProcessException("Another special transaction is already in progress");
		}
		if($repetitions < 1 || $repetitions > 64){
			throw new ItemStackRequestProcessException("Invalid number of trades: $repetitions");
		}
		$offer = $window->getOffer($networkId);
		if($offer === null){
			throw new ItemStackRequestProcessException("No such trade: $networkId");
		}
		if($offer->getTier() > $window->getTrader()->getTradeTier() || $offer->getUses() + $repetitions > $offer->getMaxUses()){
			throw new ItemStackRequestProcessException("Trade $networkId is not available");
		}
		$this->specialTransaction = new TradeTransaction($this->player, $window->getTrader(), $offer, $repetitions);
		$sell = $offer->getSell();
		$this->setNextCreatedItem($sell->setCount($sell->getCount() * $repetitions));
	}

	/**
	 * Map operations of the cartography table (zoom out, clone, add markers, lock): the result depends on the map in
	 * the table, not on a crafting grid.
	 *
	 * @throws ItemStackRequestProcessException
	 */
	protected function beginCartography(CartographyTableInventory $window, CartographyMapRecipe $recipe) : void{
		$this->startCartography($window, $recipe, null);
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	private function startCartography(CartographyTableInventory $window, CartographyMapRecipe $recipe, ?string $newName) : void{
		if($this->specialTransaction !== null){
			throw new ItemStackRequestProcessException("Another special transaction is already in progress");
		}
		$input = $window->getItem(0);
		$additional = $window->getItem(1);
		if($recipe->getResultFor($input, $additional) === null){
			throw new ItemStackRequestProcessException("The items in the cartography table don't fit this operation");
		}
		$newMapId = $recipe->createsNewMap() ? MapManager::getInstance()->allocateMapId() : 0;
		$result = $recipe->getResultFor($input, $additional, $newMapId) ?? throw new AssumptionFailedError("Checked above");
		if($newName !== null){
			$result = CartographyRenameTransaction::applyName($result, $newName) ?? $result;
		}
		$this->specialTransaction = new CartographyTransaction($this->player, $recipe, $input, $additional, $result);
		$this->setNextCreatedItem($result);
	}

	/**
	 * The name field of the cartography table (Bedrock): the client sends CraftRecipeOptional with the typed name, and
	 * the recipe ID of a map operation when there is something in the additional slot too. Renaming is free.
	 *
	 * @throws ItemStackRequestProcessException
	 */
	private function beginCartographyRename(CartographyTableInventory $window, int $recipeId, ?string $newName) : void{
		if($this->specialTransaction !== null){
			throw new ItemStackRequestProcessException("Another special transaction is already in progress");
		}
		$recipe = $this->player->getServer()->getCraftingManager()->getCraftingRecipeFromIndex($recipeId - CraftingDataCache::RECIPE_ID_OFFSET);
		if(!$recipe instanceof CartographyMapRecipe){
			$input = $window->getItem(0);
			$result = $newName === null ? null : CartographyRenameTransaction::calculateResult($input, $newName);
			if($result === null){
				throw new ItemStackRequestProcessException("The item in the cartography table can't be renamed");
			}
			$this->specialTransaction = new CartographyRenameTransaction($this->player, $input, $result);
			$this->setNextCreatedItem($result);
			return;
		}

		$this->startCartography($window, $recipe, $newName);
	}

	/**
	 * Choosing the powers of a beacon. The client follows this action with a Destroy of the payment slot
	 * (dragonfly, PowerNukkitX); if it doesn't, consumeBeaconPayment() takes the payment anyway.
	 *
	 * @throws ItemStackRequestProcessException
	 */
	protected function beginBeaconPayment(int $primaryEffectId, int $secondaryEffectId) : void{
		$window = $this->player->getCurrentWindow();
		if(!$window instanceof BeaconInventory){
			throw new ItemStackRequestProcessException("Beacon payment without an open beacon");
		}
		if($this->specialTransaction !== null){
			throw new ItemStackRequestProcessException("Another special transaction is already in progress");
		}
		$effectIdMap = EffectIdMap::getInstance();
		$primary = $primaryEffectId !== 0 ? $effectIdMap->fromId($primaryEffectId) : null;
		$secondary = $secondaryEffectId !== 0 ? $effectIdMap->fromId($secondaryEffectId) : null;
		if($primary === null || ($secondaryEffectId !== 0 && $secondary === null)){
			throw new ItemStackRequestProcessException("Unknown or missing beacon effects $primaryEffectId / $secondaryEffectId");
		}
		$holder = $window->getHolder();
		$level = Beacon::calculatePyramidLevel($holder->getWorld(), $holder->getFloorX(), $holder->getFloorY(), $holder->getFloorZ());
		if(!Beacon::isValidSelection($primary, $secondary, $level)){
			throw new ItemStackRequestProcessException("Beacon effects $primaryEffectId / $secondaryEffectId are not available with a pyramid of level $level");
		}
		if(!Beacon::isPaymentItem($window->getPayment())){
			throw new ItemStackRequestProcessException("The beacon payment slot does not hold a payment item");
		}
		$this->specialTransaction = new BeaconPaymentTransaction($this->player, $window, $primary, $secondary);
		$this->beaconWindow = $window;
	}

	/**
	 * Makes sure one payment item leaves the beacon slot, in case the client did not destroy it.
	 *
	 * @throws ItemStackRequestProcessException
	 */
	private function consumeBeaconPayment(BeaconInventory $window) : void{
		$original = $window->getPayment();
		$builderInventory = $this->builder->getInventory($window);
		$current = $builderInventory->getItem(BeaconInventory::SLOT_PAYMENT);
		if($current->getCount() >= $original->getCount()){
			if($current->isNull()){
				throw new ItemStackRequestProcessException("No beacon payment item");
			}
			$current->pop();
			$builderInventory->setItem(BeaconInventory::SLOT_PAYMENT, $current);
			$this->requestSlotInfos[] = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::BEACON_PAYMENT), UIInventorySlotOffset::BEACON_PAYMENT, 0);
		}
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function takeCreatedItem(int $count) : Item{
		if($count < 1){
			//this should be impossible at the protocol level, but in case of buggy core code this will prevent exploits
			throw new ItemStackRequestProcessException("Cannot take less than 1 created item");
		}
		$createdItem = $this->nextCreatedItem;
		if($createdItem === null){
			throw new ItemStackRequestProcessException("No created item is waiting to be taken");
		}

		if(!$this->createdItemFromCreativeInventory){
			$availableCount = $createdItem->getCount() - $this->createdItemsTakenCount;
			if($count > $availableCount){
				throw new ItemStackRequestProcessException("Not enough created items available to be taken (have $availableCount, tried to take $count)");
			}
		}

		$this->createdItemsTakenCount += $count;
		$takenItem = clone $createdItem;
		$takenItem->setCount($count);
		if(!$this->createdItemFromCreativeInventory && $this->createdItemsTakenCount >= $createdItem->getCount()){
			$this->setNextCreatedItem(null);
		}
		return $takenItem;
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	private function assertDoingCrafting() : void{
		if(!$this->specialTransaction instanceof CraftingTransaction && !$this->specialTransaction instanceof EnchantingTransaction && !$this->specialTransaction instanceof SmithingTransaction && !$this->specialTransaction instanceof GrindstoneTransaction && !$this->specialTransaction instanceof AnvilTransaction && !$this->specialTransaction instanceof LoomTransaction && !$this->specialTransaction instanceof CartographyTransaction && !$this->specialTransaction instanceof CartographyRenameTransaction && !$this->specialTransaction instanceof BeaconPaymentTransaction && !$this->specialTransaction instanceof TradeTransaction){
			if($this->specialTransaction === null){
				throw new ItemStackRequestProcessException("Expected CraftRecipe or CraftRecipeAuto action to precede this action");
			}else{
				throw new ItemStackRequestProcessException("A different special transaction is already in progress");
			}
		}
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function processItemStackRequestAction(ItemStackRequestAction $action) : void{
		if(
			$action instanceof TakeStackRequestAction ||
			$action instanceof PlaceStackRequestAction
		){
			$this->transferItems($action->getSource(), $action->getDestination(), $action->getCount());
		}elseif($action instanceof SwapStackRequestAction){
			$this->requestSlotInfos[] = $action->getSlot1();
			$this->requestSlotInfos[] = $action->getSlot2();

			[$inventory1, $slot1] = $this->getBuilderInventoryAndSlot($action->getSlot1());
			[$inventory2, $slot2] = $this->getBuilderInventoryAndSlot($action->getSlot2());

			$item1 = $inventory1->getItem($slot1);
			$item2 = $inventory2->getItem($slot2);
			$inventory1->setItem($slot1, $item2);
			$inventory2->setItem($slot2, $item1);
			if($item1 instanceof Bundle || $item2 instanceof Bundle){
				$this->bundleMoves[] = [$inventory1->getActualInventory(), $slot1, $inventory2->getActualInventory(), $slot2];
			}
		}elseif($action instanceof DropStackRequestAction){
			//TODO: this action has a "randomly" field, I have no idea what it's used for
			$dropped = $this->removeItemFromSlot($action->getSource(), $action->getCount());
			$this->builder->addAction(new DropItemAction($dropped));

		}elseif($action instanceof DestroyStackRequestAction){
			$destroyed = $this->removeItemFromSlot($action->getSource(), $action->getCount());
			if($this->beaconWindow === null || $action->getSource()->getContainerName()->getContainerId() !== ContainerUIIds::BEACON_PAYMENT){
				$this->builder->addAction(new DestroyItemAction($destroyed));
			}//else: the beacon payment, accounted for by BeaconPaymentTransaction

		}elseif($action instanceof CreativeCreateStackRequestAction){
			$item = $this->player->getCreativeInventory()->getItem($action->getCreativeItemId());
			if($item === null){
				throw new ItemStackRequestProcessException("No such creative item index: " . $action->getCreativeItemId());
			}

			$this->setNextCreatedItem($item, true);
		}elseif($action instanceof CraftRecipeStackRequestAction){
			$window = $this->player->getCurrentWindow();
			if($window instanceof EnchantInventory){
				$optionId = $this->inventoryManager->getEnchantingTableOptionIndex($action->getRecipeId());
				if($optionId !== null && ($option = $window->getOption($optionId)) !== null){
					$this->specialTransaction = new EnchantingTransaction($this->player, $option, $optionId + 1);
					$this->setNextCreatedItem($window->getOutput($optionId));
				}
			}elseif($window instanceof SmithingTableInventory){
				$craftingManager = $this->player->getServer()->getCraftingManager();
				$recipe = $craftingManager->getSmithingRecipeFromIndex($action->getRecipeId() - InventoryManager::SMITHING_RECIPE_NETWORK_OFFSET);
				if($recipe !== null){
					$this->specialTransaction = new SmithingTransaction($this->player, $recipe);
					$this->setNextCreatedItem($recipe->getResultFor($window->getContents()));
				}
			}elseif($window instanceof TradeInventory){
				$this->beginTrade($window, $action->getRecipeId(), $action->getRepetitions());
			}elseif(
				$window instanceof CartographyTableInventory &&
				($recipe = $this->player->getServer()->getCraftingManager()->getCraftingRecipeFromIndex($action->getRecipeId() - CraftingDataCache::RECIPE_ID_OFFSET)) instanceof CartographyMapRecipe
			){
				$this->beginCartography($window, $recipe);
			}else{
				$this->beginCrafting($action->getRecipeId(), $action->getRepetitions());
			}
		}elseif($action instanceof GrindstoneStackRequestAction){
			$window = $this->player->getCurrentWindow();
			if(!$window instanceof GrindstoneInventory){
				throw new ItemStackRequestProcessException("Grindstone action used without an open grindstone");
			}
			if($this->specialTransaction !== null){
				throw new ItemStackRequestProcessException("Another special transaction is already in progress");
			}
			$result = GrindstoneTransaction::calculateResult(
				$window->getItem(GrindstoneInventory::SLOT_INPUT),
				$window->getItem(GrindstoneInventory::SLOT_ADDITIONAL)
			);
			if($result === null){
				throw new ItemStackRequestProcessException("The items in the grindstone can't be combined or disenchanted");
			}
			$this->specialTransaction = new GrindstoneTransaction($this->player, $window->getHolder());
			$this->grindstoneWindow = $window;
			$this->setNextCreatedItem($result);
		}elseif($action instanceof CraftRecipeOptionalStackRequestAction){
			$window = $this->player->getCurrentWindow();
			if($window instanceof CartographyTableInventory){
				$this->beginCartographyRename($window, $action->getRecipeId(), $this->request->getFilterStrings()[$action->getFilterStringIndex()] ?? null);
				return;
			}
			if(!$window instanceof AnvilInventory){
				throw new ItemStackRequestProcessException("Optional recipe action used without an open anvil");
			}
			if($this->specialTransaction !== null){
				throw new ItemStackRequestProcessException("Another special transaction is already in progress");
			}
			$newName = $this->request->getFilterStrings()[$action->getFilterStringIndex()] ?? null;
			$result = AnvilTransaction::calculateResult(
				$window->getItem(AnvilInventory::SLOT_INPUT),
				$window->getItem(AnvilInventory::SLOT_MATERIAL),
				$newName,
				!$this->player->hasFiniteResources()
			);
			if($result === null){
				throw new ItemStackRequestProcessException("The items in the anvil can't be repaired, combined or renamed");
			}
			$this->specialTransaction = new AnvilTransaction($this->player, $window->getHolder(), $result);
			$this->setNextCreatedItem($result->getResult());
		}elseif($action instanceof LoomStackRequestAction){
			$window = $this->player->getCurrentWindow();
			if(!$window instanceof LoomInventory){
				throw new ItemStackRequestProcessException("Loom action used without an open loom");
			}
			if($this->specialTransaction !== null){
				throw new ItemStackRequestProcessException("Another special transaction is already in progress");
			}
			$pattern = BannerPatternTypeIdMap::getInstance()->fromId($action->getPatternId());
			$result = $pattern === null ? null : LoomTransaction::calculateResult(
				$window->getItem(LoomInventory::SLOT_BANNER),
				$window->getItem(LoomInventory::SLOT_DYE),
				$window->getItem(LoomInventory::SLOT_PATTERN),
				$pattern
			);
			if($result === null){
				throw new ItemStackRequestProcessException("The loom can't make pattern \"" . $action->getPatternId() . "\" with these items");
			}
			$this->specialTransaction = new LoomTransaction($this->player, $result);
			$this->setNextCreatedItem($result);
		}elseif($action instanceof BeaconPaymentStackRequestAction){
			$this->beginBeaconPayment($action->getPrimaryEffectId(), $action->getSecondaryEffectId());
		}elseif($action instanceof CraftRecipeAutoStackRequestAction){
			$window = $this->player->getCurrentWindow();
			if($window instanceof TradeInventory){
				$this->beginTrade($window, $action->getRecipeId(), $action->getRepetitions());
			}else{
				$this->beginCrafting($action->getRecipeId(), $action->getRepetitions());
			}
		}elseif($action instanceof CraftingConsumeInputStackRequestAction){
			$this->assertDoingCrafting();
			$this->removeItemFromSlot($action->getSource(), $action->getCount()); //output discarded - we allow CraftingTransaction to verify the balance

		}elseif($action instanceof CraftingCreateSpecificResultStackRequestAction){
			$this->assertDoingCrafting();

			$nextResultItem = $this->craftingResults[$action->getResultIndex()] ?? null;
			if($nextResultItem === null){
				throw new ItemStackRequestProcessException("No such crafting result index: " . $action->getResultIndex());
			}
			$this->setNextCreatedItem($nextResultItem);
		}elseif($action instanceof DeprecatedCraftingResultsStackRequestAction){
			//no obvious use
		}elseif($action instanceof MineBlockStackRequestAction){
			$slot = $action->getHotbarSlot();
			$this->requestSlotInfos[] = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::HOTBAR), $slot, $action->getStackId());
			$inventory = $this->player->getInventory();
			$usedItem = $inventory->slotExists($slot) ? $inventory->getItem($slot) : null;
			$predictedDamage = $action->getPredictedDurability();
			if($usedItem instanceof Durable && $predictedDamage >= 0 && $predictedDamage <= $usedItem->getMaxDurability()){
				$usedItem->setDamage($predictedDamage);
				$this->inventoryManager->addPredictedSlotChange($inventory, $slot, $usedItem);
			}
		}else{
			throw new ItemStackRequestProcessException("Unhandled item stack request action");
		}
	}

	/**
	 * Checks the final contents of every bundle the request puts items into or takes items out of: the bundle must
	 * stay where it is (neither it nor a bundle containing it may be moved by the same request), and the vanilla
	 * storage rules (weight limit 64, no shulker boxes) must hold.
	 *
	 * @throws ItemStackRequestProcessException
	 */
	private function validateBundleContainers() : void{
		foreach($this->bundleContainerIds as $dynamicId){
			$container = $this->inventoryManager->getBundleContainerById($dynamicId);
			if($container === null){
				throw new ItemStackRequestProcessException("Bundle container $dynamicId disappeared");
			}
			for($ancestor = $container; $ancestor !== null; $ancestor = $this->inventoryManager->getBundleContainerByInventory($ancestor->ownerInventory)){
				if(isset($this->touchedSlots[spl_object_id($ancestor->ownerInventory)][$ancestor->ownerSlot])){
					throw new ItemStackRequestProcessException("Bundle $dynamicId cannot be moved while its contents are changed");
				}
			}

			$bundle = $container->ownerInventory->getItem($container->ownerSlot);
			if(!$bundle instanceof Bundle){
				throw new ItemStackRequestProcessException("Bundle container $dynamicId has no bundle");
			}
			$before = $bundle->getContents();
			$mirrored = $container->inventory->getContents();
			if(count($before) !== count($mirrored)){
				throw new ItemStackRequestProcessException("Bundle container $dynamicId is out of sync");
			}
			$index = 0;
			foreach($mirrored as $item){
				if(!$item->equalsExact($before[$index++])){
					throw new ItemStackRequestProcessException("Bundle container $dynamicId is out of sync");
				}
			}

			$builderInventory = $this->builder->getInventory($container->inventory);
			$after = [];
			$bannedBefore = 0;
			$bannedAfter = 0;
			foreach($before as $item){
				if(!Bundle::canBeStored($item)){
					$bannedBefore++;
				}
			}
			for($slot = 0, $size = $builderInventory->getSize(); $slot < $size; ++$slot){
				$item = $builderInventory->getItem($slot);
				if(!$item->isNull()){
					$after[] = $item;
					if(!Bundle::canBeStored($item)){
						$bannedAfter++;
					}
				}
			}
			if($bannedAfter > $bannedBefore){
				throw new ItemStackRequestProcessException("This item cannot be put in a bundle");
			}
			$weightBefore = $bundle->getContentsWeight();
			$weightAfter = (clone $bundle)->setContents($after)->getContentsWeight();
			if($weightAfter > Bundle::MAX_WEIGHT && $weightAfter > $weightBefore){
				throw new ItemStackRequestProcessException("Bundle $dynamicId would weigh $weightAfter (max " . Bundle::MAX_WEIGHT . ")");
			}
		}
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	public function generateInventoryTransaction() : ?InventoryTransaction{
		foreach(Utils::promoteKeys($this->request->getActions()) as $k => $action){
			try{
				$this->processItemStackRequestAction($action);
			}catch(ItemStackRequestProcessException $e){
				throw new ItemStackRequestProcessException("Error processing action $k (" . (new \ReflectionClass($action))->getShortName() . "): " . $e->getMessage(), 0, $e);
			}
		}
		$this->setNextCreatedItem(null);
		if($this->grindstoneWindow !== null){
			//the grindstone always consumes everything in its input slots; in case the client did not send
			//CraftingConsumeInput for every slot, empty them here so the transaction balances
			$builderInventory = $this->builder->getInventory($this->grindstoneWindow);
			foreach([GrindstoneInventory::SLOT_INPUT, GrindstoneInventory::SLOT_ADDITIONAL] as $slot){
				if(!$builderInventory->getItem($slot)->isNull()){
					$builderInventory->setItem($slot, VanillaItems::AIR());
				}
			}
		}
		if($this->beaconWindow !== null){
			$this->consumeBeaconPayment($this->beaconWindow);
		}
		$this->validateBundleContainers();
		$inventoryActions = $this->builder->generateActions();
		foreach($this->bundleMoves as [$inventoryA, $slotA, $inventoryB, $slotB]){
			$this->inventoryManager->onBundleMoved($inventoryA, $slotA, $inventoryB, $slotB);
		}
		if(count($inventoryActions) === 0){
			return null;
		}

		$transaction = $this->specialTransaction ?? new InventoryTransaction($this->player);
		foreach($inventoryActions as $action){
			$transaction->addAction($action);
		}

		return $transaction;
	}

	public function getItemStackResponseBuilder() : ItemStackResponseBuilder{
		$builder = new ItemStackResponseBuilder($this->request->getRequestId(), $this->inventoryManager);
		foreach($this->requestSlotInfos as $requestInfo){
			$builder->addSlot($requestInfo->getContainerName()->getContainerId(), $requestInfo->getSlotId(), $requestInfo->getContainerName()->getDynamicId());
		}

		return $builder;
	}

	public function buildItemStackResponse() : ItemStackResponse{
		return $this->getItemStackResponseBuilder()->build();
	}
}
