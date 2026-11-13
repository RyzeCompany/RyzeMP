<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use PHPUnit\Framework\TestCase;
use pocketmine\item\PotterySherdType;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\generator\vanilla\feature\ArchaeologyContent;

/**
 * VanillaMine decorated pot side order and Bedrock NBT serialization.
 * All tests are world-independent and do not register global block state IDs.
 */
final class DecoratedPotSherdTest extends TestCase{

	public static function setUpBeforeClass() : void{
		parent::setUpBeforeClass();
		ArchaeologyContent::registerSherds();
	}

	public function testAllFourSidesRetainPatterns() : void{
		$sherds = [
			PotterySherdType::ARCHER,
			PotterySherdType::FLOW,
			null,
			PotterySherdType::SKULL
		];
		$nbt = CompoundTag::create();
		DecoratedPot::writeSherds($nbt, $sherds);
		$encoded = $nbt->getListTag(DecoratedPot::TAG_SHERDS, StringTag::class);
		self::assertNotNull($encoded);
		self::assertSame([
			"minecraft:archer_pottery_sherd",
			"minecraft:flow_pottery_sherd",
			"minecraft:brick",
			"minecraft:skull_pottery_sherd"
		], array_map(static fn(StringTag $tag) : string => $tag->getValue(), $encoded->getValue()));
		self::assertSame($sherds, DecoratedPot::readSherds($nbt));
		self::assertTrue(DecoratedPot::hasDecorations($sherds));
	}

	public function testPlainPotUsesFourBricks() : void{
		$nbt = CompoundTag::create();
		DecoratedPot::writeSherds($nbt, [null, null, null, null]);
		self::assertSame([null, null, null, null], DecoratedPot::readSherds($nbt));
		self::assertFalse(DecoratedPot::hasDecorations(DecoratedPot::readSherds($nbt)));
	}

	public function testUnknownDecorationsDoNotCorruptOtherSides() : void{
		$nbt = CompoundTag::create()->setTag(DecoratedPot::TAG_SHERDS, new ListTag([
			new StringTag("minecraft:unknown_pottery_sherd"),
			new StringTag("minecraft:snort_pottery_sherd"),
			new StringTag("minecraft:brick"),
			new StringTag("minecraft:angler_pottery_sherd")
		], NBT::TAG_String));
		self::assertSame([null, PotterySherdType::SNORT, null, PotterySherdType::ANGLER], DecoratedPot::readSherds($nbt));
	}
}
