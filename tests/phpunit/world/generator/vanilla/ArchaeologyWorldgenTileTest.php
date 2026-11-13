<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\tile\DecoratedPot;
use pocketmine\block\tile\Tile;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;

/** Java VanillaMine structure-entity data must survive Bedrock tile conversion. */
final class ArchaeologyWorldgenTileTest extends TestCase{

	public function testGeneratedDecoratedPotRetainsSherdsAndLootSeed() : void{
		$seed = 0x1234567887654321;
		$pot = CompoundTag::create()
			->setTag("sherds", CompoundTag::create()
				->setTag("back", CompoundTag::create()->setString("id", "minecraft:angler_pottery_sherd"))
				->setTag("left", CompoundTag::create()->setString("id", "minecraft:flow_pottery_sherd"))
				->setTag("front", CompoundTag::create()->setString("id", "minecraft:snort_pottery_sherd")))
			->setString("LootTable", "minecraft:pots/trial_chambers/corridor")
			->setLong("LootTableSeed", $seed);
		$converted = BlockEntityData::fromJava("minecraft:decorated_pot", $pot);
		self::assertNotNull($converted);
		self::assertSame("DecoratedPot", $converted->getString(Tile::TAG_ID));
		self::assertSame("loot_tables/pots/trial_chambers/corridor.json", $converted->getString("LootTable"));
		self::assertSame($seed, $converted->getLong("LootTableSeedLong"));
		self::assertSame([ "minecraft:angler_pottery_sherd", "minecraft:flow_pottery_sherd", "minecraft:brick", "minecraft:snort_pottery_sherd" ],
			array_map(static fn(StringTag $tag) : string => $tag->getValue(), $converted->getListTag("sherds", StringTag::class)?->getValue() ?? []));
	}

	public function testGeneratedSuspiciousSandRetainsOneTimeLoot() : void{
		$seed = 0x123456789;
		$sand = CompoundTag::create()
			->setString("LootTable", "minecraft:archaeology/desert_pyramid")
			->setLong("LootTableSeed", $seed);
		$converted = BlockEntityData::fromJava("minecraft:suspicious_sand", $sand);
		self::assertNotNull($converted);
		self::assertSame("BrushableBlock", $converted->getString(Tile::TAG_ID));
		self::assertSame("minecraft:suspicious_sand", $converted->getString("type"));
		self::assertSame("loot_tables/entities/desert_pyramid_brushable_block.json", $converted->getString("LootTable"));
		self::assertSame($seed, $converted->getLong("LootTableSeedLong"));
	}
}
