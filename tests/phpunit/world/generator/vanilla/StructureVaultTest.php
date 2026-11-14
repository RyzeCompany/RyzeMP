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

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\DecoratedPot;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\Vault as TileVault;
use pocketmine\block\Vault;
use pocketmine\block\vault\VaultConfig;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\structure\template\StructureBlockEntities;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function getenv;
use function is_file;

final class StructureVaultTest extends TestCase{

	public function testDecoratedPotKeepsSherdsAndLoot() : void{
		//Java 26.3 trial chamber decor/flow_pot
		$java = CompoundTag::create()
			->setString("id", "minecraft:decorated_pot")
			->setString("LootTable", "minecraft:pots/trial_chambers/corridor")
			->setLong("LootTableSeed", 0x123456789)
			->setTag("sherds", CompoundTag::create()
				->setTag("back", CompoundTag::create()->setString("id", "minecraft:flow_pottery_sherd"))
				->setTag("front", CompoundTag::create()->setString("id", "minecraft:brick"))
				->setTag("right", CompoundTag::create()->setString("id", "minecraft:brick")));
		self::assertTrue(BlockEntityData::isRandomizableContainer("minecraft:decorated_pot"), "templates give pots a loot seed, like vanilla");
		$tile = BlockEntityData::fromJava("minecraft:decorated_pot", $java);
		self::assertNotNull($tile);
		self::assertSame("DecoratedPot", $tile->getString(Tile::TAG_ID));
		self::assertSame("loot_tables/pots/trial_chambers/corridor.json", $tile->getString(Container::TAG_LOOT_TABLE));
		self::assertSame(0x23456789, $tile->getInt(Container::TAG_LOOT_TABLE_SEED));
		self::assertSame(0x123456789, $tile->getLong(Container::TAG_LOOT_TABLE_SEED_LONG));
		self::assertSame(["minecraft:flow_pottery_sherd", "minecraft:brick", "minecraft:brick", "minecraft:brick"], $tile->getListTag(DecoratedPot::TAG_SHERDS)?->getAllValues());

		//pots without loot keep their sherds (older Java versions: a list of ids)
		$plain = BlockEntityData::fromJava("minecraft:decorated_pot", CompoundTag::create()->setTag("sherds", new ListTag([new StringTag("minecraft:scrape_pottery_sherd")], NBT::TAG_String)));
		self::assertNotNull($plain);
		self::assertNull($plain->getTag(Container::TAG_LOOT_TABLE));
		self::assertSame(["minecraft:scrape_pottery_sherd"], $plain->getListTag(DecoratedPot::TAG_SHERDS)?->getAllValues());
	}

	/**
	 * The blocks the template places, as PocketMine blocks.
	 * @phpstan-var list<Block>
	 */
	private array $placedBlocks = [];

	/**
	 * @phpstan-return list<CompoundTag>
	 */
	private function templateTiles(string $template, string $block) : array{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_file($dir . "/data/minecraft/structure/trial_chambers/reward/vault.nbt")){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no structure templates");
		}
		$blocks = JavaBlocks::get($dir);
		$data = StructureTemplates::shared(new WorldgenData($dir), $blocks)->get($template);
		$tiles = [];
		$this->placedBlocks = [];
		foreach($data->blocks(0) as $packed){
			$position = $packed & 0x3fffffff;
			$nbt = $data->blockNbt[$position] ?? null;
			$state = $data->states[0][$packed >> 30] ?? null;
			if($nbt === null || $state === null || $blocks->blockName($state) !== $block){
				continue;
			}
			$tile = StructureBlockEntities::create($blocks, $state, $nbt);
			self::assertNotNull($tile);
			$tiles[] = $tile;
			$this->placedBlocks[] = $blocks->toPmBlock($state);
		}
		return $tiles;
	}

	public function testTrialChamberVaultsKeepTheirConfig() : void{
		$tiles = $this->templateTiles("minecraft:trial_chambers/reward/vault", "minecraft:vault");
		self::assertCount(1, $tiles);
		self::assertSame("Vault", $tiles[0]->getString(Tile::TAG_ID));
		self::assertInstanceOf(Vault::class, $this->placedBlocks[0]);
		self::assertFalse($this->placedBlocks[0]->isOminous());
		$config = $tiles[0]->getCompoundTag(TileVault::TAG_CONFIG);
		self::assertNotNull($config);
		self::assertSame("loot_tables/chests/trial_chambers/reward.json", $config->getString(VaultConfig::TAG_LOOT_TABLE));
		self::assertSame("minecraft:trial_key", $config->getCompoundTag(VaultConfig::TAG_KEY_ITEM)?->getString("Name"));

		$tiles = $this->templateTiles("minecraft:trial_chambers/reward/ominous_vault", "minecraft:vault");
		self::assertCount(1, $tiles);
		self::assertInstanceOf(Vault::class, $this->placedBlocks[0]);
		self::assertTrue($this->placedBlocks[0]->isOminous(), "ominous vaults look ominous (block state)");
		$config = $tiles[0]->getCompoundTag(TileVault::TAG_CONFIG);
		self::assertNotNull($config);
		self::assertSame("loot_tables/chests/trial_chambers/reward_ominous.json", $config->getString(VaultConfig::TAG_LOOT_TABLE));
		self::assertSame("minecraft:ominous_trial_key", $config->getCompoundTag(VaultConfig::TAG_KEY_ITEM)?->getString("Name"));
		self::assertSame(1, $config->getCompoundTag(VaultConfig::TAG_KEY_ITEM)?->getByte("Count"));
	}

	public function testTrialChamberCorridorPotsHaveLoot() : void{
		$tiles = $this->templateTiles("minecraft:trial_chambers/decor/flow_pot", "minecraft:decorated_pot");
		self::assertCount(1, $tiles);
		self::assertSame("loot_tables/pots/trial_chambers/corridor.json", $tiles[0]->getString(Container::TAG_LOOT_TABLE));
		self::assertSame("minecraft:flow_pottery_sherd", $tiles[0]->getListTag(DecoratedPot::TAG_SHERDS)?->getAllValues()[0] ?? null);
	}
}
