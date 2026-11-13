<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\tile\TrialSpawner as TrialSpawnerTile;
use pocketmine\block\tile\Vault as VaultTile;
use pocketmine\block\TrialSpawner;
use pocketmine\block\utils\VaultState;
use pocketmine\block\Vault;
use pocketmine\data\bedrock\block\BlockStateNames;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\data\bedrock\block\convert\BlockStateWriter;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Item;
use pocketmine\item\Crossbow;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\enchantment\ItemEnchantmentTags;
use pocketmine\item\StringToItemParser;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;

/** Registers the runtime blocks and keys on both the world and chunk workers. */
final class TrialChamberContent{
	private static bool $registered = false;

	private function __construct(){}

	/** @internal */
	public static function register() : void{
		if(self::$registered){
			return;
		}
		$spawner = new TrialSpawner(
			new BlockIdentifier(BlockTypeIds::TRIAL_SPAWNER, TrialSpawnerTile::class),
			"Trial Spawner",
			new BlockTypeInfo(BlockBreakInfo::pickaxe(50.0, blastResistance: 250.0))
		);
		$vault = new Vault(new BlockIdentifier(BlockTypeIds::VAULT, VaultTile::class), "Vault", new BlockTypeInfo(BlockBreakInfo::indestructible()));
		$registry = RuntimeBlockStateRegistry::getInstance();
		$registry->register($spawner);
		$registry->register($vault);

		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$deserializer->map(BlockTypeNames::TRIAL_SPAWNER, static fn(BlockStateReader $in) : TrialSpawner => (clone $spawner)
			->setSpawnerState($in->readBoundedInt(BlockStateNames::TRIAL_SPAWNER_STATE, TrialSpawner::STATE_INACTIVE, TrialSpawner::STATE_COOLDOWN))
			->setOminous($in->readBool(BlockStateNames::OMINOUS)));
		$serializer->map($spawner, static fn(TrialSpawner $block) : BlockStateWriter => BlockStateWriter::create(BlockTypeNames::TRIAL_SPAWNER)
			->writeInt(BlockStateNames::TRIAL_SPAWNER_STATE, $block->getSpawnerState())
			->writeBool(BlockStateNames::OMINOUS, $block->isOminous()));
		$deserializer->map(BlockTypeNames::VAULT, static fn(BlockStateReader $in) : Vault => (clone $vault)
			->setFacing($in->readCardinalHorizontalFacing())
			->setOminous($in->readBool(BlockStateNames::OMINOUS))
			->setVaultState(VaultState::tryFrom($in->readString(BlockStateNames::VAULT_STATE)) ??
				throw new BlockStateDeserializeException("Invalid vault state")));
		$serializer->map($vault, static fn(Vault $block) : BlockStateWriter => BlockStateWriter::create(BlockTypeNames::VAULT)
			->writeCardinalHorizontalFacing($block->getFacing())
			->writeBool(BlockStateNames::OMINOUS, $block->isOminous())
			->writeString(BlockStateNames::VAULT_STATE, $block->getVaultState()->value));

		self::registerKey(self::trialKey(), ItemTypeNames::TRIAL_KEY);
		self::registerKey(self::trialKey(ominous: true), ItemTypeNames::OMINOUS_TRIAL_KEY);
		self::registerKey(new Item(new ItemIdentifier(ItemTypeIds::WIND_CHARGE), "Wind Charge"), ItemTypeNames::WIND_CHARGE);
		self::registerKey(new Item(new ItemIdentifier(ItemTypeIds::HEAVY_CORE), "Heavy Core"), BlockTypeNames::HEAVY_CORE);
		self::registerKey(new Crossbow(new ItemIdentifier(ItemTypeIds::CROSSBOW), "Crossbow", [ItemEnchantmentTags::CROSSBOW]), ItemTypeNames::CROSSBOW);
		self::registerKey(new Item(new ItemIdentifier(ItemTypeIds::FLOW_ARMOR_TRIM_SMITHING_TEMPLATE), "Flow Armor Trim Smithing Template"), ItemTypeNames::FLOW_ARMOR_TRIM_SMITHING_TEMPLATE);
		self::registerKey(new Item(new ItemIdentifier(ItemTypeIds::OMINOUS_BOTTLE), "Ominous Bottle"), ItemTypeNames::OMINOUS_BOTTLE);
		self::registerKey(new Item(new ItemIdentifier(ItemTypeIds::FLOW_BANNER_PATTERN), "Flow Banner Pattern"), ItemTypeNames::FLOW_BANNER_PATTERN);
		self::$registered = true;
	}

	public static function trialKey(bool $ominous = false) : Item{
		return $ominous ?
			new Item(new ItemIdentifier(ItemTypeIds::OMINOUS_TRIAL_KEY), "Ominous Trial Key") :
			new Item(new ItemIdentifier(ItemTypeIds::TRIAL_KEY), "Trial Key");
	}

	private static function registerKey(Item $key, string $id) : void{
		GlobalItemDataHandlers::getDeserializer()->map($id, static fn() : Item => clone $key);
		GlobalItemDataHandlers::getSerializer()->map($key, static fn() : SavedItemData => new SavedItemData($id));
		StringToItemParser::getInstance()->register($id, static fn() : Item => clone $key);
	}
}
