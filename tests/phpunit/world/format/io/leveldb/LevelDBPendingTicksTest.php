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

namespace pocketmine\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\WorldCreationOptions;
use function extension_loaded;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Pending scheduled block updates are saved in the Bedrock PendingTicks record of the chunk and read back, including
 * the ones written by Bedrock (relative to its saved current tick).
 */
final class LevelDBPendingTicksTest extends TestCase{
	private string $path;
	private ?LevelDB $provider = null;

	protected function setUp() : void{
		if(!extension_loaded("leveldb")){
			self::markTestSkipped("leveldb extension not loaded");
		}
		$this->path = sys_get_temp_dir() . "/pm-pending-ticks-" . uniqid();
		LevelDB::generate($this->path, "pending_ticks", WorldCreationOptions::create());
		$this->provider = new LevelDB($this->path, new NullThreadSafeLogger());
	}

	protected function tearDown() : void{
		$this->provider?->close();
		$this->provider = null;
		if(isset($this->path) && is_dir($this->path)){
			Filesystem::recursiveUnlink($this->path);
		}
	}

	private function provider() : LevelDB{
		self::assertNotNull($this->provider);
		return $this->provider;
	}

	/**
	 * @param int[][] $scheduledBlockUpdates
	 * @phpstan-param list<array{int, int, int, int}> $scheduledBlockUpdates
	 */
	private function saveChunkWithRepeater(array $scheduledBlockUpdates) : void{
		$chunk = new Chunk([], true);
		$chunk->setBlockStateId(1, 2, 3, VanillaBlocks::REDSTONE_REPEATER()->getStateId());
		$this->provider()->saveChunk(0, 0, new ChunkData($chunk->getSubChunks(), true, [], [], $scheduledBlockUpdates), Chunk::DIRTY_FLAGS_ALL);
	}

	/**
	 * @phpstan-return list<array{int, int, int, int}>
	 */
	private function loadScheduledBlockUpdates() : array{
		$loaded = $this->provider()->loadChunk(0, 0);
		self::assertNotNull($loaded);
		return $loaded->getData()->getScheduledBlockUpdates();
	}

	public function testRoundTrip() : void{
		$this->saveChunkWithRepeater([[1, 2, 3, 4], [5, 2, 3, 0]]);
		self::assertSame([[1, 2, 3, 4], [5, 2, 3, 0]], $this->loadScheduledBlockUpdates());

		//saving without updates removes the record
		$this->saveChunkWithRepeater([]);
		self::assertSame([], $this->loadScheduledBlockUpdates());
		self::assertFalse($this->provider()->getDatabase()->get(LevelDB::chunkIndex(0, 0) . ChunkDataKey::PENDING_SCHEDULED_TICKS));
	}

	private static function tick(CompoundTag $blockState, int $time, int $x, int $y, int $z) : CompoundTag{
		return CompoundTag::create()
			->setTag("blockState", $blockState)
			->setLong("time", $time)
			->setInt("x", $x)
			->setInt("y", $y)
			->setInt("z", $z);
	}

	public function testBedrockPendingTicks() : void{
		$this->saveChunkWithRepeater([]);
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$repeater = $serializer->serialize(VanillaBlocks::REDSTONE_REPEATER()->getStateId())->toNbt();
		$stone = $serializer->serialize(VanillaBlocks::STONE()->getStateId())->toNbt();
		$unknown = CompoundTag::create()->setString("name", "minecraft:not_a_block")->setTag("states", CompoundTag::create())->setInt("version", 1);
		$data = (new LittleEndianNbtSerializer())->write(new TreeRoot(CompoundTag::create()
			->setInt("currentTick", 1000)
			->setTag("tickList", new ListTag([
				self::tick($repeater, 1004, 1, 2, 3),
				self::tick($stone, 1002, 6, 2, 3), //the block changed since: dropped
				self::tick($unknown, 1001, 1, 2, 3), //unknown block: dropped
				self::tick($repeater, 990, 1, 2, 3), //overdue: runs at once
			], NBT::TAG_Compound))
		));
		$this->provider()->getDatabase()->put(LevelDB::chunkIndex(0, 0) . ChunkDataKey::PENDING_SCHEDULED_TICKS, $data);

		self::assertSame([[1, 2, 3, 4], [1, 2, 3, 0]], $this->loadScheduledBlockUpdates());
	}
}
