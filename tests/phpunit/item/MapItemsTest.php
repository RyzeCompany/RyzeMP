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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\world\format\io\GlobalItemDataHandlers;

class MapItemsTest extends TestCase{

	public function testEmptyMapMetaRoundTrip() : void{
		$serializer = GlobalItemDataHandlers::getSerializer();
		$deserializer = GlobalItemDataHandlers::getDeserializer();

		$plain = $deserializer->deserializeType(new SavedItemData(ItemTypeNames::EMPTY_MAP, 0));
		self::assertInstanceOf(EmptyMap::class, $plain);
		self::assertFalse($plain->isLocator());

		$locator = $deserializer->deserializeType(new SavedItemData(ItemTypeNames::EMPTY_MAP, EmptyMap::META_LOCATOR));
		self::assertInstanceOf(EmptyMap::class, $locator);
		self::assertTrue($locator->isLocator());
		self::assertSame(EmptyMap::META_LOCATOR, $serializer->serializeType($locator)->getMeta());

		//meta 1 is used as a recipe input in BedrockData and must load
		$legacy = $deserializer->deserializeType(new SavedItemData(ItemTypeNames::EMPTY_MAP, EmptyMap::META_EMPTY_LEGACY));
		self::assertInstanceOf(EmptyMap::class, $legacy);
		self::assertSame(EmptyMap::META_EMPTY_LEGACY, $serializer->serializeType($legacy)->getMeta());

		$parsed = StringToItemParser::getInstance()->parse("empty_locator_map");
		self::assertInstanceOf(EmptyMap::class, $parsed);
		self::assertTrue($parsed->isLocator());
	}

	public function testFilledMapRoundTrip() : void{
		$map = VanillaItems::FILLED_MAP()
			->setMapType(FilledMap::TYPE_LOCATOR)
			->setMapId(123456789012)
			->setScale(2)
			->setDisplayPlayers(true);

		$tag = $map->getNamedTag();
		self::assertSame(123456789012, $tag->getLong(FilledMap::TAG_MAP_UUID));
		self::assertSame(2, $tag->getInt(FilledMap::TAG_MAP_SCALE));
		self::assertSame(1, $tag->getByte(FilledMap::TAG_MAP_DISPLAY_PLAYERS));

		$copy = Item::nbtDeserialize($map->nbtSerialize());
		self::assertInstanceOf(FilledMap::class, $copy);
		self::assertSame(FilledMap::TYPE_LOCATOR, $copy->getMapType());
		self::assertSame(123456789012, $copy->getMapId());
		self::assertSame(2, $copy->getScale());
		self::assertTrue($copy->displaysPlayers());
		self::assertTrue($copy->equalsExact($map));

		$serialized = GlobalItemDataHandlers::getSerializer()->serializeType($copy);
		self::assertSame(ItemTypeNames::FILLED_MAP, $serialized->getName());
		self::assertSame(FilledMap::TYPE_LOCATOR, $serialized->getMeta());

		//explorer and locked maps are other metas of the same item
		$explorer = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData(ItemTypeNames::FILLED_MAP, FilledMap::TYPE_TRIAL_EXPLORER));
		self::assertInstanceOf(FilledMap::class, $explorer);
		self::assertSame(FilledMap::TYPE_TRIAL_EXPLORER, $explorer->getMapType());

		$noMap = VanillaItems::FILLED_MAP();
		self::assertNull($noMap->getNamedTag()->getTag(FilledMap::TAG_MAP_UUID));
	}
}
