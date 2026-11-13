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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\block\tile\Banner;
use pocketmine\block\tile\Bed;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\tile\TrialSpawner;
use pocketmine\block\tile\Vault;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\vault\VaultConfig;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\item\VanillaItems;
use pocketmine\loot\LootItemConverter;
use pocketmine\loot\LootStack;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\WorldgenData;
use function array_key_exists;
use function count;
use function max;
use function min;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Bedrock tile data for the blocks structures place: the loot containers and suspicious blocks of the templates
 * (BlockEntityData), spawners and a plain tile for every other block that
 * needs one in PocketMine (beds with their colour, banners, flower pots with their plant, furnaces, lecterns, signs...),
 * so the blocks work like placed ones.
 */
final class StructureBlockEntities{
	/** @phpstan-var array<int, ?string> Java state => tile save id (null: no tile) */
	private static array $tileIds = [];
	private static ?JavaBlocks $owner = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Tile data for a placed block, or null if it needs none.
	 */
	public static function create(JavaBlocks $blocks, int $state, ?CompoundTag $javaNbt) : ?CompoundTag{
		$name = $blocks->blockName($state);
		if($javaNbt !== null){
			$tile = BlockEntityData::fromJava($name, $javaNbt);
			if($tile !== null){
				return $tile;
			}
			$spawnData = $javaNbt->getCompoundTag("SpawnData");
			$entity = $spawnData?->getCompoundTag("entity");
			if($name === "minecraft:spawner" && $entity !== null && $entity->getString("id", "") !== ""){
				return BlockEntityData::spawner(EntityConversion::bedrockId($entity->getString("id")));
			}
			if($blocks->hasStructureContent && $name === "minecraft:trial_spawner"){
				$tag = CompoundTag::create()->setString(Tile::TAG_ID, TileFactory::getInstance()->getSaveId(TrialSpawner::class));
				TrialSpawner::copyConfiguration($javaNbt, $tag);
				return $tag;
			}
			if($blocks->hasStructureContent && $name === "minecraft:vault"){
				return self::vault($blocks, $state, $javaNbt);
			}
		}
		$tileId = self::tileId($blocks, $state);
		if($tileId === null){
			return null;
		}
		$nbt = CompoundTag::create()->setString(Tile::TAG_ID, $tileId);
		$items = $javaNbt?->getListTag(Container::TAG_ITEMS);
		if($items !== null){
			//fixed container contents (e.g. the golden apple at the centre of ancient cities); item components are lost
			$converted = [];
			foreach($items as $entry){
				if(!$entry instanceof CompoundTag){
					continue;
				}
				$item = LootItemConverter::toItem(new LootStack($entry->getString("id", "minecraft:air")), $blocks->getDataDir());
				if($item !== null && !$item->isNull()){
					$item->setCount(max(1, min($item->getMaxStackSize(), $entry->getInt("count", $entry->getByte("Count", 1)))));
					$converted[] = $item->nbtSerialize($entry->getByte("Slot", 0));
				}
			}
			if(count($converted) > 0){
				$nbt->setTag(Container::TAG_ITEMS, new ListTag($converted, NBT::TAG_Compound));
			}
		}
		$short = WorldgenData::stripNamespace($name);
		if(str_ends_with($short, "_bed")){
			$color = self::color(substr($short, 0, -4));
			if($color !== null){
				$nbt->setByte(Bed::TAG_COLOR, DyeColorIdMap::getInstance()->toId($color));
			}
		}elseif(str_ends_with($short, "_banner")){
			$color = self::color(str_ends_with($short, "_wall_banner") ? substr($short, 0, -12) : substr($short, 0, -7));
			if($color !== null){
				$nbt->setInt(Banner::TAG_BASE, DyeColorIdMap::getInstance()->toInvertedId($color));
			}
		}elseif(str_starts_with($short, "potted_")){
			$plant = "minecraft:" . substr($short, 7);
			if($blocks->hasBlock($plant)){
				try{
					$plantState = $blocks->toPm($blocks->defaultOf($plant));
					$nbt->setTag("PlantBlock", GlobalBlockStateHandlers::getSerializer()->serialize($plantState)->toNbt());
				}catch(\Throwable){
					//empty pot
				}
			}
		}
		return $nbt;
	}

	private static function vault(JavaBlocks $blocks, int $state, CompoundTag $javaNbt) : CompoundTag{
		$nbt = CompoundTag::create()->setString(Tile::TAG_ID, TileFactory::getInstance()->getSaveId(Vault::class));
		$defaults = $blocks->getBool($state, "ominous") ? VaultConfig::ominous() : new VaultConfig();
		$config = $javaNbt->getCompoundTag(Vault::TAG_CONFIG);
		if($config === null){
			return $nbt->setTag(Vault::TAG_CONFIG, $defaults->toNbt());
		}
		$key = $defaults->getKeyItem();
		if(($keyTag = $config->getCompoundTag(VaultConfig::TAG_KEY_ITEM)) !== null){
			$key = LootItemConverter::toItem(new LootStack($keyTag->getString("id", "minecraft:air")), $blocks->getDataDir()) ?? VanillaItems::AIR();
			$key->setCount(max(1, min($key->getMaxStackSize(), $keyTag->getInt("count", 1))));
		}
		$lootTable = $config->getTag(VaultConfig::TAG_LOOT_TABLE);
		$override = $config->getTag(VaultConfig::TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY);
		return $nbt->setTag(Vault::TAG_CONFIG, (new VaultConfig(
			$lootTable instanceof StringTag ? BlockEntityData::toBedrockPath($lootTable->getValue()) : $defaults->getLootTable(),
			VaultConfig::readRange($config, VaultConfig::TAG_ACTIVATION_RANGE, $defaults->getActivationRange()),
			VaultConfig::readRange($config, VaultConfig::TAG_DEACTIVATION_RANGE, $defaults->getDeactivationRange()),
			$key,
			$override instanceof StringTag ? BlockEntityData::toBedrockPath($override->getValue()) : null
		))->toNbt());
	}

	private static function color(string $name) : ?DyeColor{
		$upper = strtoupper($name);
		foreach(DyeColor::cases() as $color){
			if($color->name === $upper){
				return $color;
			}
		}
		return null;
	}

	private static function tileId(JavaBlocks $blocks, int $state) : ?string{
		if(self::$owner !== $blocks){
			self::$tileIds = [];
			self::$owner = $blocks;
		}
		if(array_key_exists($state, self::$tileIds)){
			return self::$tileIds[$state];
		}
		$id = null;
		$class = $blocks->toPmBlock($state)->getIdInfo()->getTileClass();
		if($class !== null){
			try{
				$id = TileFactory::getInstance()->getSaveId($class);
			}catch(\InvalidArgumentException){
				$id = null;
			}
		}
		if($id !== null && strlen($id) === 0){
			$id = null;
		}
		return self::$tileIds[$state] = $id;
	}
}
