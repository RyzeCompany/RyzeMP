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

namespace pocketmine\network\mcpe;

use pocketmine\block\inventory\AnvilInventory;
use pocketmine\block\inventory\BeaconInventory;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\inventory\BrewingStandInventory;
use pocketmine\block\inventory\CartographyTableInventory;
use pocketmine\block\inventory\CommandBlockInventory;
use pocketmine\block\inventory\CrafterInventory;
use pocketmine\block\inventory\CraftingTableInventory;
use pocketmine\block\inventory\DispenserInventory;
use pocketmine\block\inventory\DropperInventory;
use pocketmine\block\inventory\EnchantInventory;
use pocketmine\block\inventory\FurnaceInventory;
use pocketmine\block\inventory\GrindstoneInventory;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\block\inventory\JigsawInventory;
use pocketmine\block\inventory\LoomInventory;
use pocketmine\block\inventory\SmithingTableInventory;
use pocketmine\block\inventory\StonecutterInventory;
use pocketmine\block\inventory\StructureBlockInventory;
use pocketmine\crafting\FurnaceType;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\npc\TradePrices;
use pocketmine\entity\vehicle\MinecartHopper;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\ChestBoatInventory;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\MinecartCommandBlockInventory;
use pocketmine\inventory\MinecartInventory;
use pocketmine\inventory\TradeInventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\item\Bundle;
use pocketmine\item\enchantment\EnchantingOption;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\cache\CreativeInventoryCache;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\ContainerRegistryCleanupPacket;
use pocketmine\network\mcpe\protocol\ContainerSetDataPacket;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\PlayerEnchantOptionsPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\Enchant;
use pocketmine\network\mcpe\protocol\types\EnchantOption as ProtocolEnchantOption;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\UpdateEquipPacket;
use pocketmine\network\mcpe\protocol\UpdateTradePacket;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Binary;
use pocketmine\utils\ObjectSet;
use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_search;
use function count;
use function get_class;
use function implode;
use function is_int;
use function max;
use function spl_object_id;

/**
 * @phpstan-type ContainerOpenClosure \Closure(int $id, Inventory $inventory) : (list<ClientboundPacket>|null)
 */
class InventoryManager{

	//TODO: Hack! In Bedrock these indexes go together with regular crafting recipes,
	//so we make offsets to prevent conflicts.
	public const ENCHANTING_OPTION_NETWORK_OFFSET = 100000;
	public const SMITHING_RECIPE_NETWORK_OFFSET = 200000;

	/**
	 * @var InventoryManagerEntry[] spl_object_id(Inventory) => InventoryManagerEntry
	 * @phpstan-var array<int, InventoryManagerEntry>
	 */
	private array $inventories = [];

	/**
	 * @var Inventory[] network window ID => Inventory
	 * @phpstan-var array<int, Inventory>
	 */
	private array $networkIdToInventoryMap = [];
	/**
	 * @var ComplexInventoryMapEntry[] net slot ID => ComplexWindowMapEntry
	 * @phpstan-var array<int, ComplexInventoryMapEntry>
	 */
	private array $complexSlotToInventoryMap = [];

	private int $lastInventoryNetworkId = ContainerIds::FIRST;
	private int $currentWindowType = WindowTypes::CONTAINER;

	private int $clientSelectedHotbarSlot = -1;

	/** @phpstan-var ObjectSet<ContainerOpenClosure> */
	private ObjectSet $containerOpenCallbacks;

	private ?int $pendingCloseWindowId = null;
	/** @phpstan-var \Closure() : void */
	private ?\Closure $pendingOpenWindowCallback = null;

	private int $nextItemStackId = 1;
	private ?int $currentItemStackRequestId = null;

	private bool $fullSyncRequested = false;

	/**
	 * Client-side dynamic containers showing the contents of bundles in tracked inventories.
	 * @var BundleContainer[] dynamic container ID => container
	 * @phpstan-var array<int, BundleContainer>
	 */
	private array $bundleContainers = [];
	private int $nextBundleContainerId = 1;
	/**
	 * @var int[] dynamic container IDs to remove from the client's container registry
	 * @phpstan-var list<int>
	 */
	private array $pendingBundleContainerCleanups = [];
	/** Set while a bundle container's contents are mirrored from the bundle item, so the change isn't written back */
	private bool $updatingBundleContainer = false;
	/** The bundle slot currently being rewritten after a change of its contents, whose stack ID the client keeps */
	private ?BundleContainer $bundleWriteback = null;

	/** @var int[] network recipe ID => enchanting table option index */
	private array $enchantingTableOptions = [];
	//TODO: this should be based on the total number of crafting recipes - if there are ever 100k recipes, this will
	//conflict with regular recipes
	private int $nextEnchantingTableOptionId = self::ENCHANTING_OPTION_NETWORK_OFFSET;

	public function __construct(
		private Player $player,
		private NetworkSession $session
	){
		$this->containerOpenCallbacks = new ObjectSet();
		$this->containerOpenCallbacks->add(self::createContainerOpen(...));

		$this->add(ContainerIds::INVENTORY, $this->player->getInventory());
		$this->add(ContainerIds::OFFHAND, $this->player->getOffHandInventory());
		$this->add(ContainerIds::ARMOR, $this->player->getArmorInventory());
		$this->addComplex(UIInventorySlotOffset::CURSOR, $this->player->getCursorInventory());
		$this->addComplex(UIInventorySlotOffset::CRAFTING2X2_INPUT, $this->player->getCraftingGrid());

		$this->player->getInventory()->getHeldItemIndexChangeListeners()->add($this->syncSelectedHotbarSlot(...));
	}

	private function associateIdWithInventory(int $id, Inventory $inventory) : void{
		$this->networkIdToInventoryMap[$id] = $inventory;
	}

	private function getNewWindowId() : int{
		$this->lastInventoryNetworkId = max(ContainerIds::FIRST, ($this->lastInventoryNetworkId + 1) % ContainerIds::LAST);
		return $this->lastInventoryNetworkId;
	}

	private function add(int $id, Inventory $inventory) : void{
		if(isset($this->inventories[spl_object_id($inventory)])){
			throw new \InvalidArgumentException("Inventory " . get_class($inventory) . " is already tracked");
		}
		$this->inventories[spl_object_id($inventory)] = new InventoryManagerEntry($inventory);
		$this->associateIdWithInventory($id, $inventory);
	}

	private function addDynamic(Inventory $inventory) : int{
		$id = $this->getNewWindowId();
		$this->add($id, $inventory);
		return $id;
	}

	/**
	 * @param int[]|int $slotMap
	 * @phpstan-param array<int, int>|int $slotMap
	 */
	private function addComplex(array|int $slotMap, Inventory $inventory) : void{
		if(isset($this->inventories[spl_object_id($inventory)])){
			throw new \InvalidArgumentException("Inventory " . get_class($inventory) . " is already tracked");
		}
		$complexSlotMap = new ComplexInventoryMapEntry($inventory, is_int($slotMap) ? [$slotMap => 0] : $slotMap);
		$this->inventories[spl_object_id($inventory)] = new InventoryManagerEntry(
			$inventory,
			$complexSlotMap
		);
		foreach($complexSlotMap->getSlotMap() as $netSlot => $coreSlot){
			$this->complexSlotToInventoryMap[$netSlot] = $complexSlotMap;
		}
	}

	/**
	 * @param int[]|int $slotMap
	 * @phpstan-param array<int, int>|int $slotMap
	 */
	private function addComplexDynamic(array|int $slotMap, Inventory $inventory) : int{
		$this->addComplex($slotMap, $inventory);
		$id = $this->getNewWindowId();
		$this->associateIdWithInventory($id, $inventory);
		return $id;
	}

	private function remove(int $id) : void{
		$inventory = $this->networkIdToInventoryMap[$id];
		unset($this->networkIdToInventoryMap[$id]);
		if($this->getWindowId($inventory) === null){
			$removedEntry = $this->inventories[spl_object_id($inventory)] ?? null;
			if($removedEntry !== null){
				$this->releaseBundleContainers($removedEntry);
			}
			unset($this->inventories[spl_object_id($inventory)]);
			foreach($this->complexSlotToInventoryMap as $netSlot => $entry){
				if($entry->getInventory() === $inventory){
					unset($this->complexSlotToInventoryMap[$netSlot]);
				}
			}
		}
	}

	public function getWindowId(Inventory $inventory) : ?int{
		return ($id = array_search($inventory, $this->networkIdToInventoryMap, true)) !== false ? $id : null;
	}

	public function getCurrentWindowId() : int{
		return $this->lastInventoryNetworkId;
	}

	/**
	 * @phpstan-return array{Inventory, int}|null
	 */
	public function locateWindowAndSlot(int $windowId, int $netSlotId) : ?array{
		if($windowId === ContainerIds::UI){
			$entry = $this->complexSlotToInventoryMap[$netSlotId] ?? null;
			if($entry === null){
				return null;
			}
			$inventory = $entry->getInventory();
			$coreSlotId = $entry->mapNetToCore($netSlotId);
			return $coreSlotId !== null && $inventory->slotExists($coreSlotId) ? [$inventory, $coreSlotId] : null;
		}
		$inventory = $this->networkIdToInventoryMap[$windowId] ?? null;
		if($inventory !== null && $inventory->slotExists($netSlotId)){
			return [$inventory, $netSlotId];
		}
		return null;
	}

	private function addPredictedSlotChangeInternal(Inventory $inventory, int $slot, ItemStack $item) : void{
		$this->inventories[spl_object_id($inventory)]->predictions[$slot] = $item;
	}

	public function addPredictedSlotChange(Inventory $inventory, int $slot, Item $item) : void{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		$itemStack = $entry !== null ?
			$this->coreItemToNetForSlot($entry, $slot, $item) :
			$this->session->getTypeConverter()->coreItemStackToNet($item);
		$this->addPredictedSlotChangeInternal($inventory, $slot, $itemStack);
	}

	public function addTransactionPredictedSlotChanges(InventoryTransaction $tx) : void{
		foreach($tx->getActions() as $action){
			if($action instanceof SlotChangeAction){
				//TODO: ItemStackRequestExecutor can probably build these predictions with much lower overhead
				$this->addPredictedSlotChange(
					$action->getInventory(),
					$action->getSlot(),
					$action->getTargetItem()
				);
			}
		}
	}

	/**
	 * @param NetworkInventoryAction[] $networkInventoryActions
	 * @throws PacketHandlingException
	 */
	public function addRawPredictedSlotChanges(array $networkInventoryActions) : void{
		foreach($networkInventoryActions as $action){
			if($action->sourceType !== NetworkInventoryAction::SOURCE_CONTAINER){
				continue;
			}
			if($action->windowId === null){
				throw new PacketHandlingException("Window ID should always be set for SOURCE_CONTAINER");
			}

			//legacy transactions should not modify or predict anything other than these inventories, since these are
			//the only ones accessible when not in-game (ItemStackRequest is used for everything else)
			if(match($action->windowId){
				ContainerIds::INVENTORY, ContainerIds::OFFHAND, ContainerIds::ARMOR => false,
				default => true
			}){
				throw new PacketHandlingException("Legacy transactions cannot predict changes to inventory with ID " . $action->windowId);
			}
			$info = $this->locateWindowAndSlot($action->windowId, $action->inventorySlot);
			if($info === null){
				continue;
			}

			[$inventory, $slot] = $info;
			$this->addPredictedSlotChangeInternal($inventory, $slot, $action->newItem->getItemStack());
		}
	}

	public function setCurrentItemStackRequestId(?int $id) : void{
		$this->currentItemStackRequestId = $id;
	}

	/**
	 * When the server initiates a window close, it does so by sending a ContainerClose to the client, which causes the
	 * client to behave as if it initiated the close itself. It responds by sending a ContainerClose back to the server,
	 * which the server is then expected to respond to.
	 *
	 * Sending the client a new window before sending this final response creates buggy behaviour on the client, which
	 * is problematic when switching windows. Therefore, we defer sending any new windows until after the client
	 * responds to our window close instruction, so that we can complete the window handshake correctly.
	 *
	 * This is a pile of complicated garbage that only exists because Mojang overengineered the process of opening and
	 * closing inventory windows.
	 *
	 * @phpstan-param \Closure() : void $func
	 */
	private function openWindowDeferred(\Closure $func) : void{
		if($this->pendingCloseWindowId !== null){
			$this->session->getLogger()->debug("Deferring opening of new window, waiting for close ack of window $this->pendingCloseWindowId");
			$this->pendingOpenWindowCallback = $func;
		}else{
			$func();
		}
	}

	/**
	 * @return int[]|null
	 * @phpstan-return array<int, int>|null
	 */
	private function createComplexSlotMapping(Inventory $inventory) : ?array{
		//TODO: make this dynamic so plugins can add mappings for stuff not implemented by PM
		return match(true){
			$inventory instanceof AnvilInventory => UIInventorySlotOffset::ANVIL,
			$inventory instanceof EnchantInventory => UIInventorySlotOffset::ENCHANTING_TABLE,
			$inventory instanceof LoomInventory => UIInventorySlotOffset::LOOM,
			$inventory instanceof StonecutterInventory => [UIInventorySlotOffset::STONE_CUTTER_INPUT => StonecutterInventory::SLOT_INPUT],
			$inventory instanceof CraftingTableInventory => UIInventorySlotOffset::CRAFTING3X3_INPUT,
			$inventory instanceof CartographyTableInventory => UIInventorySlotOffset::CARTOGRAPHY_TABLE,
			$inventory instanceof SmithingTableInventory => UIInventorySlotOffset::SMITHING_TABLE,
			$inventory instanceof GrindstoneInventory => UIInventorySlotOffset::GRINDSTONE,
			$inventory instanceof BeaconInventory => [UIInventorySlotOffset::BEACON_PAYMENT => BeaconInventory::SLOT_PAYMENT],
			$inventory instanceof TradeInventory => UIInventorySlotOffset::TRADE2_INGREDIENT,
			default => null,
		};
	}

	/**
	 * Trading screen of a villager or wandering trader (the client opens it on UpdateTradePacket instead of
	 * ContainerOpen). Offers are the vanilla "Recipes" list (see {@link \pocketmine\entity\npc\TradeOffer::toNbt()}).
	 */
	public static function createTradePacket(int $windowId, TradeInventory $inventory) : UpdateTradePacket{
		$trader = $inventory->getTrader();
		$recipes = [];
		foreach($trader->getTradeOffers() as $offer){
			if($offer->getTier() <= $trader->getTradeTier()){
				$recipes[] = $offer->toNbt(TradePrices::getClientBuyACount($offer, $inventory->getPlayer(), $trader));
			}
		}
		$requirements = [];
		foreach($trader->getTierExpRequirements() as $tier => $exp){
			$requirements[] = CompoundTag::create()->setInt((string) $tier, $exp);
		}
		return UpdateTradePacket::create(
			$windowId,
			WindowTypes::TRADING,
			0,
			$trader->getTradeTier(),
			$trader->getId(),
			$inventory->getPlayer()->getId(),
			$trader->getTradeDisplayName(),
			true,
			true,
			new CacheableNbt(CompoundTag::create()
				->setTag("Recipes", new ListTag($recipes, NBT::TAG_Compound))
				->setTag("TierExpRequirements", new ListTag($requirements, NBT::TAG_Compound))
			)
		);
	}

	/**
	 * Sends the trades again (after a trade changed their uses or the trader's level).
	 */
	public function syncTrades(TradeInventory $inventory) : void{
		$windowId = $this->getWindowId($inventory);
		if($windowId !== null){
			$this->session->sendDataPacket(self::createTradePacket($windowId, $inventory));
		}
	}

	public function onCurrentWindowChange(Inventory $inventory) : void{
		$this->onCurrentWindowRemove();

		$this->openWindowDeferred(function() use ($inventory) : void{
			if(($slotMap = $this->createComplexSlotMapping($inventory)) !== null){
				$windowId = $this->addComplexDynamic($slotMap, $inventory);
			}else{
				$windowId = $this->addDynamic($inventory);
			}

			foreach($this->containerOpenCallbacks as $callback){
				$pks = $callback($windowId, $inventory);
				if($pks !== null){
					$windowType = null;
					foreach($pks as $pk){
						if($pk instanceof ContainerOpenPacket){
							//workaround useless bullshit in 1.21 - ContainerClose requires a type now for some reason
							$windowType = $pk->windowType;
						}elseif($pk instanceof UpdateTradePacket){
							$windowType = $pk->windowType;
						}
						$this->session->sendDataPacket($pk);
					}
					$this->currentWindowType = $windowType ?? WindowTypes::CONTAINER;
					$this->syncContents($inventory);
					return;
				}
			}
			throw new \LogicException("Unsupported inventory type");
		});
	}

	/** @phpstan-return ObjectSet<ContainerOpenClosure> */
	public function getContainerOpenCallbacks() : ObjectSet{ return $this->containerOpenCallbacks; }

	/**
	 * @return ClientboundPacket[]|null
	 * @phpstan-return list<ClientboundPacket>|null
	 */
	protected static function createContainerOpen(int $id, Inventory $inv) : ?array{
		//TODO: we should be using some kind of tagging system to identify the types. Instanceof is flaky especially
		//if the class isn't final, not to mention being inflexible.
		if($inv instanceof BlockInventory){
			$blockPosition = BlockPosition::fromVector3($inv->getHolder());
			$windowType = match(true){
				$inv instanceof LoomInventory => WindowTypes::LOOM,
				$inv instanceof FurnaceInventory => match($inv->getFurnaceType()){
						FurnaceType::FURNACE => WindowTypes::FURNACE,
						FurnaceType::BLAST_FURNACE => WindowTypes::BLAST_FURNACE,
						FurnaceType::SMOKER => WindowTypes::SMOKER,
						FurnaceType::CAMPFIRE, FurnaceType::SOUL_CAMPFIRE => throw new \LogicException("Campfire inventory cannot be displayed to a player")
					},
				$inv instanceof EnchantInventory => WindowTypes::ENCHANTMENT,
				$inv instanceof BrewingStandInventory => WindowTypes::BREWING_STAND,
				$inv instanceof AnvilInventory => WindowTypes::ANVIL,
				$inv instanceof HopperInventory => WindowTypes::HOPPER,
				$inv instanceof CraftingTableInventory => WindowTypes::WORKBENCH,
				$inv instanceof StonecutterInventory => WindowTypes::STONECUTTER,
				$inv instanceof CartographyTableInventory => WindowTypes::CARTOGRAPHY,
				$inv instanceof SmithingTableInventory => WindowTypes::SMITHING_TABLE,
				$inv instanceof GrindstoneInventory => WindowTypes::GRINDSTONE,
				$inv instanceof BeaconInventory => WindowTypes::BEACON,
				//window types 6, 7 and 36 (BedrockProtocol WindowTypes; CloudburstMC ContainerType DISPENSER, DROPPER, CRAFTER)
				$inv instanceof DropperInventory => WindowTypes::DROPPER,
				$inv instanceof DispenserInventory => WindowTypes::DISPENSER,
				$inv instanceof CrafterInventory => WindowTypes::CRAFTER,
				$inv instanceof StructureBlockInventory => WindowTypes::STRUCTURE_EDITOR,
				$inv instanceof CommandBlockInventory => WindowTypes::COMMAND_BLOCK,
				$inv instanceof JigsawInventory => WindowTypes::JIGSAW_EDITOR,
				default => WindowTypes::CONTAINER
			};
			return [ContainerOpenPacket::blockInv($id, $windowType, $blockPosition)];
		}
		if($inv instanceof TradeInventory){
			return [self::createTradePacket($id, $inv)];
		}
		if($inv instanceof MinecartInventory){
			$minecart = $inv->getMinecart();
			return [ContainerOpenPacket::entityInv($id, $minecart instanceof MinecartHopper ? WindowTypes::MINECART_HOPPER : WindowTypes::MINECART_CHEST, $minecart->getId())];
		}
		if($inv instanceof ChestBoatInventory){
			return [ContainerOpenPacket::entityInv($id, WindowTypes::CHEST_BOAT, $inv->getBoat()->getId())];
		}
		if($inv instanceof MinecartCommandBlockInventory){
			return [ContainerOpenPacket::entityInv($id, WindowTypes::COMMAND_BLOCK, $inv->getMinecart()->getId())];
		}
		if($inv instanceof HorseInventory){
			$horse = $inv->getHorse();
			return [
				ContainerOpenPacket::entityInv($id, WindowTypes::HORSE, $horse->getId()),
				self::createHorseEquipPacket($id, $inv)
			];
		}
		return null;
	}

	/**
	 * The slots of a horse screen, sent after its ContainerOpen (BDS does so, per gophertunnel's UpdateEquip docs):
	 * what each equipment slot accepts and the icon of the empty slot, built like GeyserMC's mount screens. Slot 0 is
	 * the saddle (or a llama's carpet), slot 1 the body armor (horse or nautilus armor).
	 */
	public static function createHorseEquipPacket(int $windowId, HorseInventory $inventory) : UpdateEquipPacket{
		$horse = $inventory->getHorse();
		$slot = self::createHorseEquipSlotTag(...);
		$slots = [];
		if($horse->canWearSaddle()){
			$slots[] = $slot(HorseInventory::SLOT_EQUIP, "minecraft:saddle", ["minecraft:saddle"]);
		}elseif($horse instanceof Llama){
			//GeyserMC numbers the carpet slot 1 (vanilla llama equippable slot), although it is the first item of the window
			$slots[] = $slot(1, "minecraft:carpet", ["minecraft:carpet"]);
		}
		if($horse->hasArmorSlot()){
			$slots[] = $slot(HorseInventory::SLOT_ARMOR, $horse->getArmorSlotIcon(), $horse->getArmorSlotItemNames());
		}
		return UpdateEquipPacket::create(
			$windowId,
			WindowTypes::HORSE,
			$inventory->getSize(),
			$horse->getId(),
			new CacheableNbt(CompoundTag::create()->setTag("slots", new ListTag($slots, NBT::TAG_Compound)))
		);
	}

	/**
	 * @param string[] $acceptedNames
	 * @phpstan-param list<string> $acceptedNames
	 */
	private static function createHorseEquipSlotTag(int $slotNumber, string $iconName, array $acceptedNames) : CompoundTag{
		$item = static fn(string $name) => CompoundTag::create()
			->setShort("Aux", 0x7fff)
			->setString("Name", $name);
		return CompoundTag::create()
			->setTag("acceptedItems", new ListTag(array_map(
				fn(string $name) => CompoundTag::create()->setTag("slotItem", $item($name)),
				$acceptedNames
			), NBT::TAG_Compound))
			->setTag("item", $item($iconName))
			->setInt("slotNumber", $slotNumber);
	}

	public function onClientOpenMainInventory() : void{
		$this->onCurrentWindowRemove();

		$this->openWindowDeferred(function() : void{
			$windowId = $this->getNewWindowId();
			$this->associateIdWithInventory($windowId, $this->player->getInventory());
			$this->currentWindowType = WindowTypes::INVENTORY;

			$this->session->sendDataPacket(ContainerOpenPacket::entityInv(
				$windowId,
				$this->currentWindowType,
				$this->player->getId()
			));
		});
	}

	public function onCurrentWindowRemove() : void{
		if(isset($this->networkIdToInventoryMap[$this->lastInventoryNetworkId])){
			$this->remove($this->lastInventoryNetworkId);
			$this->session->sendDataPacket(ContainerClosePacket::create($this->lastInventoryNetworkId, $this->currentWindowType, true));
			if($this->pendingCloseWindowId !== null){
				throw new AssumptionFailedError("We should not have opened a new window while a window was waiting to be closed");
			}
			$this->pendingCloseWindowId = $this->lastInventoryNetworkId;
			$this->enchantingTableOptions = [];
		}
	}

	public function onClientRemoveWindow(int $id) : void{
		if(Binary::signByte($id) === ContainerIds::NONE){ //TODO: REMOVE signByte() once BedrockProtocol + ext-encoding are implemented
			//TODO: HACK! Since 1.21.100 (and probably earlier), the client will send -1 to close windows that it can't
			//view for some reason, e.g. if the chat window was already open. This is pretty awkward, since it means
			//that we can only assume it refers to the most recently sent window, and if we don't handle it,
			//InventoryManager will never get the green light to send subsequent windows, which breaks inventory UIs.
			//Fortunately, we already wait for close acks anyway, so the window ID is technically useless...?
			$this->session->getLogger()->debug("Client rejected opening of a window, assuming it was $this->lastInventoryNetworkId");
			$id = $this->lastInventoryNetworkId;
		}
		if($id === $this->lastInventoryNetworkId){
			if(isset($this->networkIdToInventoryMap[$id]) && $id !== $this->pendingCloseWindowId){
				$this->remove($id);
				$this->player->removeCurrentWindow();
			}
		}else{
			$this->session->getLogger()->debug("Attempted to close inventory with network ID $id, but current is $this->lastInventoryNetworkId");
		}

		//Always send this, even if no window matches. If we told the client to close a window, it will behave as if it
		//initiated the close and expect an ack.
		$this->session->sendDataPacket(ContainerClosePacket::create($id, $this->currentWindowType, false));

		if($this->pendingCloseWindowId === $id){
			$this->pendingCloseWindowId = null;
			if($this->pendingOpenWindowCallback !== null){
				$this->session->getLogger()->debug("Opening deferred window after close ack of window $id");
				($this->pendingOpenWindowCallback)();
				$this->pendingOpenWindowCallback = null;
			}
		}
	}

	/**
	 * Compares itemstack extra data for equality. This is used to verify legacy InventoryTransaction slot predictions.
	 *
	 * TODO: It would be preferable if we didn't have to deserialize this, to improve performance and reduce attack
	 * surface. However, the raw data may not match due to differences in ordering. Investigate whether the
	 * client-provided NBT is consistently sorted.
	 */
	private function itemStackExtraDataEqual(ItemStack $left, ItemStack $right) : bool{
		if($left->getRawExtraData() === $right->getRawExtraData()){
			return true;
		}

		$typeConverter = $this->session->getTypeConverter();
		$leftExtraData = $typeConverter->deserializeItemStackExtraData($left->getRawExtraData(), $left->getId());
		$rightExtraData = $typeConverter->deserializeItemStackExtraData($right->getRawExtraData(), $right->getId());

		$leftNbt = $leftExtraData->getNbt();
		$rightNbt = $rightExtraData->getNbt();
		return
			$leftExtraData->getCanPlaceOn() === $rightExtraData->getCanPlaceOn() &&
			$leftExtraData->getCanDestroy() === $rightExtraData->getCanDestroy() && (
				$leftNbt === $rightNbt || //this covers null === null and fast object identity
				($leftNbt !== null && $rightNbt !== null && $leftNbt->equals($rightNbt))
			);
	}

	private function itemStacksEqual(ItemStack $left, ItemStack $right) : bool{
		return
			$left->getId() === $right->getId() &&
			$left->getMeta() === $right->getMeta() &&
			$left->getBlockRuntimeId() === $right->getBlockRuntimeId() &&
			$left->getCount() === $right->getCount() &&
			$this->itemStackExtraDataEqual($left, $right);
	}

	public function onSlotChange(Inventory $inventory, int $slot) : void{
		$inventoryEntry = $this->inventories[spl_object_id($inventory)] ?? null;
		if($inventoryEntry === null){
			//this can happen when an inventory changed during InventoryCloseEvent, or when a temporary inventory
			//is cleared before removal.
			return;
		}
		$item = $inventory->getItem($slot);
		if(!$item instanceof Bundle && isset($inventoryEntry->bundleIds[$slot])){
			$this->releaseBundleContainer($inventoryEntry, $slot);
		}
		$writeback = $this->bundleWriteback;
		if($writeback !== null && $writeback->ownerInventory === $inventory && $writeback->ownerSlot === $slot){
			//only the bundle's contents changed, and the client already applied that change to the bundle's dynamic
			//container; the bundle itself looks the same to the client, so it keeps its stack ID
			unset($inventoryEntry->predictions[$slot]);
			return;
		}
		$currentItem = $this->coreItemToNetForSlot($inventoryEntry, $slot, $item);
		$clientSideItem = $inventoryEntry->predictions[$slot] ?? null;
		if($clientSideItem === null || !$this->itemStacksEqual($currentItem, $clientSideItem) || isset($inventoryEntry->newBundleIds[$slot])){
			//a bundle whose container ID the client doesn't know yet has to be sent even if the change was predicted
			//no prediction or incorrect - do not associate this with the currently active itemstack request
			$this->trackItemStack($inventoryEntry, $slot, $currentItem, null);
			$inventoryEntry->pendingSyncs[$slot] = $currentItem;
		}else{
			//correctly predicted - associate the change with the currently active itemstack request
			$this->trackItemStack($inventoryEntry, $slot, $currentItem, $this->currentItemStackRequestId);
		}

		unset($inventoryEntry->predictions[$slot]);
	}

	private function sendInventorySlotPackets(int $windowId, int $netSlot, ItemStackWrapper $itemStackWrapper) : void{
		/*
		 * TODO: HACK!
		 * As of 1.20.12, the client ignores change of itemstackID in some cases when the old item == the new item.
		 * Notably, this happens with armor, offhand and enchanting tables, but not with main inventory.
		 * While we could track the items previously sent to the client, that's a waste of memory and would
		 * cost performance. Instead, clear the slot(s) first, then send the new item(s).
		 * The network cost of doing this is fortunately minimal, as an air itemstack is only 1 byte.
		 */
		if($itemStackWrapper->getStackId() !== 0){
			$this->session->sendDataPacket(InventorySlotPacket::create(
				$windowId,
				$netSlot,
				null,
				null,
				new ItemStackWrapper(0, ItemStack::null())
			));
		}
		//now send the real contents
		$this->session->sendDataPacket(InventorySlotPacket::create(
			$windowId,
			$netSlot,
			null,
			null,
			$itemStackWrapper
		));
	}

	/**
	 * @param ItemStackWrapper[] $itemStackWrappers
	 */
	private function sendInventoryContentPackets(int $windowId, array $itemStackWrappers) : void{
		/*
		 * TODO: HACK!
		 * As of 1.20.12, the client ignores change of itemstackID in some cases when the old item == the new item.
		 * Notably, this happens with armor, offhand and enchanting tables, but not with main inventory.
		 * While we could track the items previously sent to the client, that's a waste of memory and would
		 * cost performance. Instead, clear the slot(s) first, then send the new item(s).
		 * The network cost of doing this is fortunately minimal, as an air itemstack is only 1 byte.
		 */
		$this->session->sendDataPacket(InventoryContentPacket::create(
			$windowId,
			array_fill_keys(array_keys($itemStackWrappers), new ItemStackWrapper(0, ItemStack::null())),
			new FullContainerName(0, null),
			new ItemStackWrapper(0, ItemStack::null())
		));
		//now send the real contents
		$this->session->sendDataPacket(InventoryContentPacket::create($windowId, $itemStackWrappers, new FullContainerName(0, null), new ItemStackWrapper(0, ItemStack::null())));
	}

	public function syncSlot(Inventory $inventory, int $slot, ItemStack $itemStack) : void{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		if($entry === null){
			throw new \LogicException("Cannot sync an untracked inventory");
		}
		if($entry->bundleContainer !== null){
			//slots of a bundle's dynamic container are only ever sent all together
			$this->syncBundleContainerFromOwner($entry->bundleContainer);
			unset($entry->predictions[$slot], $entry->pendingSyncs[$slot]);
			return;
		}
		$itemStackInfo = $entry->itemStackInfos[$slot];
		if($itemStackInfo === null){
			throw new \LogicException("Cannot sync an untracked inventory slot");
		}
		$item = $inventory->getItem($slot);
		if($item instanceof Bundle){
			//the client needs the contents before it sees the bundle referring to them
			$this->syncBundleContainer($this->getBundleContainer($entry, $slot, $item), $item, $itemStackInfo->getStackId());
			unset($entry->newBundleIds[$slot]);
		}
		if($entry->complexSlotMap !== null){
			$windowId = ContainerIds::UI;
			$netSlot = $entry->complexSlotMap->mapCoreToNet($slot) ?? throw new AssumptionFailedError("We already have an ItemStackInfo, so this should not be null");
		}else{
			$windowId = $this->getWindowId($inventory) ?? throw new AssumptionFailedError("We already have an ItemStackInfo, so this should not be null");
			$netSlot = $slot;
		}

		$itemStackWrapper = new ItemStackWrapper($itemStackInfo->getStackId(), $itemStack);
		if($windowId === ContainerIds::OFFHAND){
			//TODO: HACK!
			//The client may sometimes ignore the InventorySlotPacket for the offhand slot.
			//This can cause a lot of problems (totems, arrows, and more...).
			//The workaround is to send an InventoryContentPacket instead
			//BDS (Bedrock Dedicated Server) also seems to work this way.
			$this->sendInventoryContentPackets($windowId, [$itemStackWrapper]);
		}else{
			$this->sendInventorySlotPackets($windowId, $netSlot, $itemStackWrapper);
		}
		unset($entry->predictions[$slot], $entry->pendingSyncs[$slot]);
	}

	public function syncContents(Inventory $inventory) : void{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		if($entry === null){
			//this can happen when an inventory changed during InventoryCloseEvent, or when a temporary inventory
			//is cleared before removal.
			return;
		}
		if($entry->bundleContainer !== null){
			//bundle containers are sent together with the slot holding the bundle
			return;
		}
		if($entry->complexSlotMap !== null){
			$windowId = ContainerIds::UI;
		}else{
			$windowId = $this->getWindowId($inventory);
		}
		if($windowId !== null){
			$entry->predictions = [];
			$entry->pendingSyncs = [];
			$contents = [];
			foreach($inventory->getContents(true) as $slot => $item){
				if(!$item instanceof Bundle && isset($entry->bundleIds[$slot])){
					$this->releaseBundleContainer($entry, $slot);
				}
				$itemStack = $this->coreItemToNetForSlot($entry, $slot, $item);
				$info = $this->trackItemStack($entry, $slot, $itemStack, null);
				$contents[] = new ItemStackWrapper($info->getStackId(), $itemStack);
				if($item instanceof Bundle){
					$this->syncBundleContainer($this->getBundleContainer($entry, $slot, $item), $item, $info->getStackId());
					unset($entry->newBundleIds[$slot]);
				}
			}
			if($entry->complexSlotMap !== null){
				foreach($contents as $slotId => $info){
					$packetSlot = $entry->complexSlotMap->mapCoreToNet($slotId) ?? null;
					if($packetSlot === null){
						continue;
					}
					$this->sendInventorySlotPackets($windowId, $packetSlot, $info);
				}
			}else{
				$this->sendInventoryContentPackets($windowId, $contents);
			}
		}
	}

	public function syncAll() : void{
		foreach($this->inventories as $entry){
			$this->syncContents($entry->inventory);
		}
	}

	public function requestSyncAll() : void{
		$this->fullSyncRequested = true;
	}

	public function syncMismatchedPredictedSlotChanges() : void{
		foreach($this->inventories as $entry){
			$inventory = $entry->inventory;
			foreach($entry->predictions as $slot => $expectedItem){
				if(!$inventory->slotExists($slot) || $entry->itemStackInfos[$slot] === null){
					continue; //TODO: size desync ???
				}

				//any prediction that still exists at this point is a slot that was predicted to change but didn't
				$this->session->getLogger()->debug("Detected prediction mismatch in inventory " . get_class($inventory) . "#" . spl_object_id($inventory) . " slot $slot");
				$entry->pendingSyncs[$slot] = $this->coreItemToNetForSlot($entry, $slot, $inventory->getItem($slot));
			}

			$entry->predictions = [];
		}
	}

	public function flushPendingUpdates() : void{
		if(count($this->pendingBundleContainerCleanups) > 0){
			//BDS removes the dynamic containers of bundles that are gone from the client's container registry
			$this->session->sendDataPacket(ContainerRegistryCleanupPacket::create(array_map(
				fn(int $id) => new FullContainerName(ContainerUIIds::DYNAMIC, $id),
				$this->pendingBundleContainerCleanups
			)));
			$this->pendingBundleContainerCleanups = [];
		}
		if($this->fullSyncRequested){
			$this->fullSyncRequested = false;
			$this->session->getLogger()->debug("Full inventory sync requested, sending contents of " . count($this->inventories) . " inventories");
			$this->syncAll();
		}else{
			foreach($this->inventories as $entry){
				if(count($entry->pendingSyncs) === 0){
					continue;
				}
				$inventory = $entry->inventory;
				$this->session->getLogger()->debug("Syncing slots " . implode(", ", array_keys($entry->pendingSyncs)) . " in inventory " . get_class($inventory) . "#" . spl_object_id($inventory));
				if($entry->bundleContainer !== null){
					$this->syncBundleContainerFromOwner($entry->bundleContainer);
					$entry->pendingSyncs = [];
					continue;
				}
				foreach($entry->pendingSyncs as $slot => $itemStack){
					$this->syncSlot($inventory, $slot, $itemStack);
				}
				$entry->pendingSyncs = [];
			}
		}
	}

	public function syncData(Inventory $inventory, int $propertyId, int $value) : void{
		$windowId = $this->getWindowId($inventory);
		if($windowId !== null){
			$this->session->sendDataPacket(ContainerSetDataPacket::create($windowId, $propertyId, $value));
		}
	}

	public function onClientSelectHotbarSlot(int $slot) : void{
		$this->clientSelectedHotbarSlot = $slot;
	}

	public function syncSelectedHotbarSlot() : void{
		$playerInventory = $this->player->getInventory();
		$selected = $playerInventory->getHeldItemIndex();
		if($selected !== $this->clientSelectedHotbarSlot){
			$inventoryEntry = $this->inventories[spl_object_id($playerInventory)] ?? null;
			if($inventoryEntry === null){
				throw new AssumptionFailedError("Player inventory should always be tracked");
			}
			$itemStackInfo = $inventoryEntry->itemStackInfos[$selected] ?? null;
			if($itemStackInfo === null){
				throw new AssumptionFailedError("Untracked player inventory slot $selected");
			}

			$this->session->sendDataPacket(MobEquipmentPacket::create(
				$this->player->getId(),
				new ItemStackWrapper($itemStackInfo->getStackId(), $this->coreItemToNetForSlot($inventoryEntry, $selected, $playerInventory->getItemInHand())),
				$selected,
				$selected,
				ContainerIds::INVENTORY
			));
			$this->clientSelectedHotbarSlot = $selected;
		}
	}

	public function syncCreative() : void{
		$this->session->sendDataPacket(CreativeInventoryCache::getInstance()->buildPacket($this->player->getCreativeInventory(), $this->session));
	}

	/**
	 * @param EnchantingOption[] $options
	 * @phpstan-param list<EnchantingOption> $options
	 */
	public function syncEnchantingTableOptions(array $options) : void{
		$protocolOptions = [];

		foreach($options as $index => $option){
			$optionId = $this->nextEnchantingTableOptionId++;
			$this->enchantingTableOptions[$optionId] = $index;

			$protocolEnchantments = array_map(
				fn(EnchantmentInstance $e) => new Enchant(EnchantmentIdMap::getInstance()->toId($e->getType()), $e->getLevel()),
				$option->getEnchantments()
			);
			// We don't pay attention to the $slotFlags, $heldActivatedEnchantments and $selfActivatedEnchantments
			// as everything works fine without them (perhaps these values are used somehow in the BDS).
			$protocolOptions[] = new ProtocolEnchantOption(
				$option->getRequiredXpLevel(),
				0, $protocolEnchantments,
				[],
				[],
				$option->getDisplayName(),
				$optionId
			);
		}

		$this->session->sendDataPacket(PlayerEnchantOptionsPacket::create($protocolOptions));
	}

	public function getEnchantingTableOptionIndex(int $recipeId) : ?int{
		return $this->enchantingTableOptions[$recipeId] ?? null;
	}

	/**
	 * Converts the item in a tracked inventory slot for the network. Bundles get the ID of the dynamic container that
	 * shows their contents to the client (one per bundle slot, kept when the client moves the bundle).
	 */
	private function coreItemToNetForSlot(InventoryManagerEntry $entry, int $slot, Item $item) : ItemStack{
		$typeConverter = $this->session->getTypeConverter();
		if(!$item instanceof Bundle){
			return $typeConverter->coreItemStackToNet($item);
		}
		return $typeConverter->coreItemStackToNet($item, $this->getBundleContainer($entry, $slot, $item)->id);
	}

	private function getBundleContainer(InventoryManagerEntry $entry, int $slot, Bundle $bundle) : BundleContainer{
		$id = $entry->bundleIds[$slot] ?? null;
		if($id !== null && isset($this->bundleContainers[$id])){
			return $this->bundleContainers[$id];
		}

		$id = $this->nextBundleContainerId++;
		$container = new BundleContainer($id, $entry->inventory, $slot);
		$this->bundleContainers[$id] = $container;
		$entry->bundleIds[$slot] = $id;
		$entry->newBundleIds[$slot] = true;

		$contentsEntry = new InventoryManagerEntry($container->inventory, null, $container);
		$this->inventories[spl_object_id($container->inventory)] = $contentsEntry;
		$this->setBundleContainerContents($container, $bundle->getContents());
		foreach($container->inventory->getContents(true) as $contentsSlot => $item){
			$this->trackItemStack($contentsEntry, $contentsSlot, $this->coreItemToNetForSlot($contentsEntry, $contentsSlot, $item), null);
		}

		$container->inventory->getListeners()->add(new CallbackInventoryListener(
			function(Inventory $unused, int $contentsSlot, Item $unusedB) use ($container) : void{
				$this->onBundleContentsSlotChange($container, $contentsSlot);
			},
			function(Inventory $inventory, array $unused) use ($container) : void{
				for($contentsSlot = 0, $size = $inventory->getSize(); $contentsSlot < $size; ++$contentsSlot){
					$this->onBundleContentsSlotChange($container, $contentsSlot);
				}
			}
		));
		return $container;
	}

	private function onBundleContentsSlotChange(BundleContainer $container, int $slot) : void{
		if($this->updatingBundleContainer){
			return;
		}
		$container->dirty = true;
		$this->onSlotChange($container->inventory, $slot);
	}

	/**
	 * @param Item[] $contents
	 * @phpstan-param list<Item> $contents
	 */
	private function setBundleContainerContents(BundleContainer $container, array $contents) : void{
		$this->updatingBundleContainer = true;
		try{
			$container->inventory->setContents($contents);
		}finally{
			$this->updatingBundleContainer = false;
		}
	}

	private function syncBundleContainerFromOwner(BundleContainer $container) : void{
		$owner = $container->ownerInventory->getItem($container->ownerSlot);
		if($owner instanceof Bundle){
			$ownerInfo = $this->getItemStackInfo($container->ownerInventory, $container->ownerSlot);
			$this->syncBundleContainer($container, $owner, $ownerInfo?->getStackId() ?? 0);
		}
	}

	/**
	 * Sends the contents of a bundle's dynamic container, the same way BDS does (as observed by GeyserMC's
	 * BundleCache): an InventoryContentPacket for container ID 125 (CONTAINER_ID_REGISTRY) with all 64 slots, the
	 * FullContainerName (DynamicContainer, bundle ID) and the bundle itself as the storage item.
	 * Nested bundles get their own containers, sent first.
	 */
	private function syncBundleContainer(BundleContainer $container, Bundle $bundle, int $bundleStackId) : void{
		$entry = $this->inventories[spl_object_id($container->inventory)] ?? null;
		if($entry === null){
			return;
		}
		$this->setBundleContainerContents($container, $bundle->getContents());
		$entry->predictions = [];
		$entry->pendingSyncs = [];
		$wrappers = [];
		foreach($container->inventory->getContents(true) as $slot => $item){
			if(!$item instanceof Bundle && isset($entry->bundleIds[$slot])){
				$this->releaseBundleContainer($entry, $slot);
			}
			$itemStack = $this->coreItemToNetForSlot($entry, $slot, $item);
			$info = $this->trackItemStack($entry, $slot, $itemStack, null);
			if($item instanceof Bundle){
				$this->syncBundleContainer($this->getBundleContainer($entry, $slot, $item), $item, $info->getStackId());
				unset($entry->newBundleIds[$slot]);
			}
			$wrappers[] = new ItemStackWrapper($info->getStackId(), $itemStack);
		}
		$this->session->sendDataPacket(InventoryContentPacket::create(
			ContainerIds::CONTAINER_ID_REGISTRY,
			$wrappers,
			new FullContainerName(ContainerUIIds::DYNAMIC, $container->id),
			new ItemStackWrapper($bundleStackId, $this->session->getTypeConverter()->coreItemStackToNet($bundle, $container->id))
		));
	}

	private function releaseBundleContainer(InventoryManagerEntry $entry, int $slot) : void{
		$id = $entry->bundleIds[$slot] ?? null;
		unset($entry->bundleIds[$slot], $entry->newBundleIds[$slot]);
		if($id === null){
			return;
		}
		$container = $this->bundleContainers[$id] ?? null;
		unset($this->bundleContainers[$id]);
		$this->pendingBundleContainerCleanups[] = $id;
		if($container !== null){
			$contentsEntry = $this->inventories[spl_object_id($container->inventory)] ?? null;
			if($contentsEntry !== null){
				$this->releaseBundleContainers($contentsEntry);
				unset($this->inventories[spl_object_id($container->inventory)]);
			}
			$container->inventory->getListeners()->clear();
		}
	}

	private function releaseBundleContainers(InventoryManagerEntry $entry) : void{
		foreach(array_keys($entry->bundleIds) as $slot){
			$this->releaseBundleContainer($entry, $slot);
		}
	}

	/**
	 * Returns the inventory mirroring a bundle's dynamic container and the slot in it, as referenced by the client
	 * with a FullContainerName (DynamicContainer, $dynamicId).
	 *
	 * @phpstan-return array{Inventory, int}|null
	 */
	public function locateBundleContainerSlot(int $dynamicId, int $slot) : ?array{
		$container = $this->bundleContainers[$dynamicId] ?? null;
		if($container === null || !$container->inventory->slotExists($slot)){
			return null;
		}
		return [$container->inventory, $slot];
	}

	public function getBundleContainerById(int $dynamicId) : ?BundleContainer{
		return $this->bundleContainers[$dynamicId] ?? null;
	}

	/**
	 * Returns the bundle container mirrored by the given inventory, if it is one.
	 */
	public function getBundleContainerByInventory(Inventory $inventory) : ?BundleContainer{
		return ($this->inventories[spl_object_id($inventory)] ?? null)?->bundleContainer;
	}

	/**
	 * Called when an ItemStackRequest moves or swaps bundles between two slots, so that the bundles keep their
	 * dynamic containers: the client keeps the bundle_id of a bundle it moves.
	 */
	public function onBundleMoved(Inventory $inventoryA, int $slotA, Inventory $inventoryB, int $slotB) : void{
		$entryA = $this->inventories[spl_object_id($inventoryA)] ?? null;
		$entryB = $this->inventories[spl_object_id($inventoryB)] ?? null;
		if($entryA === null || $entryB === null){
			return;
		}
		$idA = $entryA->bundleIds[$slotA] ?? null;
		$newA = isset($entryA->newBundleIds[$slotA]);
		$idB = $entryB->bundleIds[$slotB] ?? null;
		$newB = isset($entryB->newBundleIds[$slotB]);
		unset($entryA->bundleIds[$slotA], $entryA->newBundleIds[$slotA], $entryB->bundleIds[$slotB], $entryB->newBundleIds[$slotB]);
		foreach([[$idA, $newA, $entryB, $slotB], [$idB, $newB, $entryA, $slotA]] as [$id, $new, $entry, $slot]){
			if($id === null){
				continue;
			}
			$entry->bundleIds[$slot] = $id;
			if($new){
				$entry->newBundleIds[$slot] = true;
			}
			$container = $this->bundleContainers[$id] ?? null;
			if($container !== null){
				$container->ownerInventory = $entry->inventory;
				$container->ownerSlot = $slot;
			}
		}
	}

	/**
	 * Writes the contents of bundle containers changed by the last transaction back to their bundles. Like the
	 * client, the contents are compacted (no gaps between stacks); stack IDs move with their items, since the client
	 * moves them the same way.
	 */
	public function flushBundleWritebacks() : void{
		do{
			$written = false;
			foreach($this->bundleContainers as $container){
				if($container->dirty){
					$container->dirty = false;
					$written = true;
					$this->writeBackBundleContainer($container);
				}
			}
		}while($written);
	}

	private function writeBackBundleContainer(BundleContainer $container) : void{
		$entry = $this->inventories[spl_object_id($container->inventory)] ?? null;
		$owner = $container->ownerInventory->getItem($container->ownerSlot);
		if($entry === null || !$owner instanceof Bundle){
			return;
		}

		$contents = [];
		$infos = [];
		$bundleIds = [];
		$newBundleIds = [];
		$compacted = false;
		foreach($container->inventory->getContents() as $slot => $item){
			$index = count($contents);
			if($index !== $slot){
				$compacted = true;
			}
			$contents[] = $item;
			$infos[$index] = $entry->itemStackInfos[$slot] ?? null;
			if(isset($entry->bundleIds[$slot])){
				$bundleIds[$index] = $entry->bundleIds[$slot];
				$nested = $this->bundleContainers[$entry->bundleIds[$slot]] ?? null;
				if($nested !== null){
					$nested->ownerSlot = $index;
				}
			}
			if(isset($entry->newBundleIds[$slot])){
				$newBundleIds[$index] = true;
			}
		}
		if($compacted){
			$this->setBundleContainerContents($container, $contents);
			for($slot = 0, $size = $container->inventory->getSize(); $slot < $size; ++$slot){
				$entry->itemStackInfos[$slot] = $infos[$slot] ?? new ItemStackInfo(null, 0);
			}
			$entry->bundleIds = $bundleIds;
			$entry->newBundleIds = $newBundleIds;
		}

		$this->bundleWriteback = $container;
		try{
			$container->ownerInventory->setItem($container->ownerSlot, (clone $owner)->setContents($contents));
		}finally{
			$this->bundleWriteback = null;
		}
	}

	private function newItemStackId() : int{
		return $this->nextItemStackId++;
	}

	public function getItemStackInfo(Inventory $inventory, int $slot) : ?ItemStackInfo{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		return $entry?->itemStackInfos[$slot] ?? null;
	}

	private function trackItemStack(InventoryManagerEntry $entry, int $slotId, ItemStack $itemStack, ?int $itemStackRequestId) : ItemStackInfo{
		//TODO: ItemStack->isNull() would be nice to have here
		$info = new ItemStackInfo($itemStackRequestId, $itemStack->getId() === 0 ? 0 : $this->newItemStackId());
		return $entry->itemStackInfos[$slotId] = $info;
	}
}
