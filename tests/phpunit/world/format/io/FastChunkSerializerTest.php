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

namespace pocketmine\world\format\io;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\Chunk;

class FastChunkSerializerTest extends TestCase{

	public function testPendingTilesSurviveSerialization() : void{
		$chunk = new Chunk([], true);
		self::assertCount(0, FastChunkSerializer::deserializeTerrain(FastChunkSerializer::serializeTerrain($chunk))->getPendingTileNBT());

		$chunk->addPendingTileNBT(CompoundTag::create()->setString("id", "Chest")->setInt("x", 17)->setInt("y", -20)->setInt("z", -3)->setLong("LootTableSeedLong", -1234567890123));
		$chunk->addPendingTileNBT(CompoundTag::create()->setString("id", "MobSpawner")->setInt("x", 18)->setInt("y", 5)->setInt("z", -4));
		//replaces the first one (same position)
		$chunk->addPendingTileNBT(CompoundTag::create()->setString("id", "Chest")->setInt("x", 17)->setInt("y", -20)->setInt("z", -3)->setLong("LootTableSeedLong", 42));

		$copy = FastChunkSerializer::deserializeTerrain(FastChunkSerializer::serializeTerrain($chunk));
		self::assertTrue($copy->isPopulated());
		self::assertCount(2, $copy->getPendingTileNBT());
		$chest = $copy->getPendingTileNBTAt(1, -20, 13);
		self::assertNotNull($chest);
		self::assertSame(42, $chest->getLong("LootTableSeedLong"));
		self::assertSame("MobSpawner", $copy->getPendingTileNBTAt(2, 5, 12)?->getString("id"));

		$copy->removePendingTileNBT(2, 5, 12);
		self::assertCount(1, $copy->getPendingTileNBT());
		$copy->clearPendingTileNBT();
		self::assertCount(0, $copy->getPendingTileNBT());
	}
}
