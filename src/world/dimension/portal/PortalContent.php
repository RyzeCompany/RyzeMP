<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use pocketmine\world\dimension\portal\block\EndPortal;
use pocketmine\world\dimension\portal\block\EndGateway;
use pocketmine\world\dimension\portal\item\EnderEye;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\EndGateway as EndGatewayTile;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeInventory;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\Armor;
use pocketmine\item\ArmorTypeInfo;
use pocketmine\item\enchantment\ItemEnchantmentTags;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\scheduler\AsyncPool;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\generator\vanilla\structure\TrialChamberContent;
use InvalidArgumentException;

/**
 * Registers the end portal block and the eye of ender item, which the server
 * does not provide. The block is registered on every thread with the type id
 * chosen on the main thread so runtime state ids match across threads.
 */
final class PortalContent{

	private static ?EndPortal $endPortal = null;
	private static ?EnderEye $enderEye = null;

	private function __construct(){
	}

	public static function endPortal() : EndPortal{
		if(self::$endPortal === null){
			throw new \LogicException("Portal content is not registered");
		}
		return clone self::$endPortal;
	}

	public static function enderEye() : EnderEye{
		if(self::$enderEye === null){
			throw new \LogicException("Portal content is not registered");
		}
		return clone self::$enderEye;
	}

	public static function register(AsyncPool $pool, CraftingManager $craftingManager) : void{
		if(self::$endPortal !== null){
			return;
		}
		$typeId = BlockTypeIds::newId();
		$gatewayId = BlockTypeIds::newId();
		self::registerBlock($typeId);
		self::registerGateway($gatewayId);
		self::registerElytra();
		TrialChamberContent::register();
		self::registerItem();
		self::registerRecipe($craftingManager);

		$pool->addWorkerStartHook(function(int $worker) use($pool, $typeId, $gatewayId) : void{
			$pool->submitTaskToWorker(new class($typeId, $gatewayId) extends AsyncTask{
				public function __construct(
					private int $typeId,
					private int $gatewayId
				){
				}

				public function onRun() : void{
					PortalContent::registerBlock($this->typeId);
					PortalContent::registerGateway($this->gatewayId);
					PortalContent::registerElytra();
					TrialChamberContent::register();
				}
			}, $worker);
		});
	}

	/** @internal */
	public static function registerGateway(int $typeId) : void{
		$block = new EndGateway(new BlockIdentifier($typeId, EndGatewayTile::class), "End Gateway", new BlockTypeInfo(BlockBreakInfo::indestructible()));
		RuntimeBlockStateRegistry::getInstance()->register($block);
		GlobalBlockStateHandlers::getDeserializer()->mapSimple(BlockTypeNames::END_GATEWAY, static fn() => clone $block);
		GlobalBlockStateHandlers::getSerializer()->mapSimple($block, BlockTypeNames::END_GATEWAY);
	}

	/** @internal */
	public static function registerElytra() : void{
		$item = new Armor(
			new ItemIdentifier(ItemTypeIds::ELYTRA),
			"Elytra",
			new ArmorTypeInfo(0, 432, ArmorInventory::SLOT_CHEST),
			[ItemEnchantmentTags::ELYTRA]
		);
		GlobalItemDataHandlers::getDeserializer()->map(ItemTypeNames::ELYTRA, static fn() => clone $item);
		GlobalItemDataHandlers::getSerializer()->map($item, static fn() => new SavedItemData(ItemTypeNames::ELYTRA));
		StringToItemParser::getInstance()->register("elytra", static fn() => clone $item);
	}

	/**
	 * @internal
	 */
	public static function registerBlock(int $typeId) : void{
		if(self::$endPortal !== null){
			return;
		}
		$block = new EndPortal(new BlockIdentifier($typeId), "End Portal", new BlockTypeInfo(BlockBreakInfo::indestructible()));
		RuntimeBlockStateRegistry::getInstance()->register($block);
		GlobalBlockStateHandlers::getDeserializer()->mapSimple(BlockTypeNames::END_PORTAL, fn() => clone $block);
		GlobalBlockStateHandlers::getSerializer()->mapSimple($block, BlockTypeNames::END_PORTAL);
		self::$endPortal = $block;
	}

	private static function registerItem() : void{
		$item = new EnderEye(new ItemIdentifier(ItemTypeIds::newId()), "Eye of Ender");
		GlobalItemDataHandlers::getDeserializer()->map(ItemTypeNames::ENDER_EYE, fn() => clone $item);
		GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData(ItemTypeNames::ENDER_EYE));
		foreach(["ender_eye", "eye_of_ender"] as $name){
			try{
				StringToItemParser::getInstance()->register($name, fn() => clone $item);
			}catch(InvalidArgumentException){
				StringToItemParser::getInstance()->override($name, fn() => clone $item);
			}
		}
		// Initializing the creative inventory already loads this item from BedrockData
		// through the deserializer registered above. Add only when the data omits it.
		$creative = CreativeInventory::getInstance();
		if(!$creative->contains($item)){
			$creative->add($item);
		}
		self::$enderEye = $item;
	}

	private static function registerRecipe(CraftingManager $craftingManager) : void{
		$craftingManager->registerShapelessRecipe(new ShapelessRecipe(
			[
				new ExactRecipeIngredient(VanillaItems::ENDER_PEARL()),
				new ExactRecipeIngredient(VanillaItems::BLAZE_POWDER())
			],
			[self::enderEye()],
			ShapelessRecipeType::CRAFTING
		));
	}
}

