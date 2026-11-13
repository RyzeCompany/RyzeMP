<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\feature;

use pocketmine\block\Block;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\BrushableBlock;
use pocketmine\block\DecoratedPot;
use pocketmine\block\tile\DecoratedPot as DecoratedPotTile;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\BrushableBlock as BrushableBlockTile;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\data\bedrock\block\BlockStateNames;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\data\bedrock\block\convert\BlockStateWriter;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Brush;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\PotterySherd;
use pocketmine\item\PotterySherdType;
use function strtolower;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class ArchaeologyContent{
	private static bool $sherdsRegistered = false;
	private function __construct(){}

	/** @internal */
	public static function register() : void{
		self::registerBlock(BlockTypeIds::SUSPICIOUS_SAND, BlockTypeNames::SUSPICIOUS_SAND, "Suspicious Sand", VanillaBlocks::SAND(...));
		self::registerBlock(BlockTypeIds::SUSPICIOUS_GRAVEL, BlockTypeNames::SUSPICIOUS_GRAVEL, "Suspicious Gravel", VanillaBlocks::GRAVEL(...));
		$brush = self::brush();
		GlobalItemDataHandlers::getDeserializer()->map(ItemTypeNames::BRUSH, static fn() : Brush => clone $brush);
		GlobalItemDataHandlers::getSerializer()->map($brush, static fn() : SavedItemData => new SavedItemData(ItemTypeNames::BRUSH));
		StringToItemParser::getInstance()->register("brush", static fn() : Brush => clone $brush);
		self::registerSherds();
		self::registerDecoratedPot();
	}


	/**
	 * Registers all 23 vanilla pottery sherds with a single stateful item type,
	 * just as VanillaMine does. Names are serialized as individual Bedrock IDs;
	 * multi-version packet adapters remain responsible for older client fallbacks.
	 * @internal
	 */
	public static function registerSherds() : void{
		if(self::$sherdsRegistered){
			return;
		}
		$prototype = new PotterySherd(new ItemIdentifier(ItemTypeIds::POTTERY_SHERD), "Pottery Sherd");
		$deserializer = GlobalItemDataHandlers::getDeserializer();
		foreach(PotterySherdType::cases() as $type){
			$id = "minecraft:" . strtolower($type->name) . "_pottery_sherd";
			$sherd = (clone $prototype)->setSherdType($type);
			$deserializer->map($id, static fn() : PotterySherd => clone $sherd);
			StringToItemParser::getInstance()->register($id, static fn() : PotterySherd => clone $sherd);
		}
		GlobalItemDataHandlers::getSerializer()->map($prototype, static fn(PotterySherd $item) : SavedItemData =>
			new SavedItemData("minecraft:" . strtolower($item->getSherdType()->name) . "_pottery_sherd"));
		self::$sherdsRegistered = true;
	}


	private static bool $decoratedPotRegistered = false;

	/** Register native decorated-pot block states and their LevelDB tile. */
	private static function registerDecoratedPot() : void{
		if(self::$decoratedPotRegistered){
			return;
		}
		$pot = new DecoratedPot(
			new BlockIdentifier(BlockTypeIds::DECORATED_POT, DecoratedPotTile::class),
			"Decorated Pot",
			new BlockTypeInfo(BlockBreakInfo::instant())
		);
		RuntimeBlockStateRegistry::getInstance()->register($pot);
		GlobalBlockStateHandlers::getDeserializer()->map(BlockTypeNames::DECORATED_POT, static fn(BlockStateReader $in) : DecoratedPot =>
			(clone $pot)->setFacing($in->readCardinalHorizontalFacing()));
		GlobalBlockStateHandlers::getSerializer()->map($pot, static fn(DecoratedPot $state) : BlockStateWriter =>
			BlockStateWriter::create(BlockTypeNames::DECORATED_POT)->writeCardinalHorizontalFacing($state->getFacing()));
		self::$decoratedPotRegistered = true;
	}

	/** @internal */
	public static function registerRecipe(CraftingManager $craftingManager) : void{
		$brush = self::brush();
		if($craftingManager->matchRecipeByOutputs([$brush])->valid()){
			return;
		}
		$craftingManager->registerShapedRecipe(new ShapedRecipe(
			["F", "C", "S"],
			[
				"F" => new ExactRecipeIngredient(VanillaItems::FEATHER()),
				"C" => new ExactRecipeIngredient(VanillaItems::COPPER_INGOT()),
				"S" => new ExactRecipeIngredient(VanillaItems::STICK())
			],
			[$brush]
		));
	}

	private static function brush() : Brush{
		return new Brush(new ItemIdentifier(ItemTypeIds::BRUSH), "Brush");
	}

	/** @phpstan-param \Closure() : Block $brushedResult */
	private static function registerBlock(int $id, string $identifier, string $name, \Closure $brushedResult) : void{
		$block = new BrushableBlock(new BlockIdentifier($id, BrushableBlockTile::class), $name, new BlockTypeInfo(BlockBreakInfo::shovel(0.25)), $brushedResult);
		RuntimeBlockStateRegistry::getInstance()->register($block);
		GlobalBlockStateHandlers::getDeserializer()->map($identifier, static fn(BlockStateReader $in) : BrushableBlock => (clone $block)
			->setBrushedProgress($in->readBoundedInt(BlockStateNames::BRUSHED_PROGRESS, 0, BrushableBlock::MAX_BRUSHED_PROGRESS))
			->setHanging($in->readBool(BlockStateNames::HANGING)));
		GlobalBlockStateHandlers::getSerializer()->map($block, static fn(BrushableBlock $state) : BlockStateWriter => BlockStateWriter::create($identifier)
			->writeInt(BlockStateNames::BRUSHED_PROGRESS, $state->getBrushedProgress())
			->writeBool(BlockStateNames::HANGING, $state->isHanging()));
	}
}
