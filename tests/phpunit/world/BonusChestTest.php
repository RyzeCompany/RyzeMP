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

namespace pocketmine\world;

use pocketmine\block\Chest;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\Torch;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\utils\Random;
use pocketmine\world\format\io\data\BedrockWorldData;
use pocketmine\world\redstone\RedstoneTestCase;
use function bin2hex;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use const DIRECTORY_SEPARATOR;

final class BonusChestTest extends RedstoneTestCase{

	public function testOptionOffByDefault() : void{
		$options = WorldCreationOptions::create();
		self::assertFalse($options->hasBonusChest());
		self::assertTrue($options->setBonusChest(true)->hasBonusChest());
	}

	public function testLevelDatWaitsForThePlacement() : void{
		$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "pmbonuschest" . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
		mkdir($path);
		try{
			BedrockWorldData::generate($path, "bonus", WorldCreationOptions::create()->setBonusChest(true));
			$tag = (new BedrockWorldData($path . "level.dat"))->getCompoundTag();
			self::assertSame(1, $tag->getByte(BonusChest::TAG_ENABLED));
			//set by BonusChest once the chest is placed, so an interrupted placement is retried on the next load
			self::assertSame(0, $tag->getByte(BonusChest::TAG_SPAWNED));
		}finally{
			@unlink($path . "level.dat");
			@rmdir($path);
		}
	}

	public function testPlacedOnTheSurfaceWithTorches() : void{
		$this->floor(-1, -1, 16, 16);
		$pos = BonusChest::place($this->world, 7, 9, new Random(1));
		self::assertNotNull($pos);
		self::assertSame(self::FLOOR_Y + 1, $pos->getFloorY());
		self::assertGreaterThanOrEqual(0, $pos->getFloorX());
		self::assertLessThan(16, $pos->getFloorX());
		self::assertGreaterThanOrEqual(0, $pos->getFloorZ());
		self::assertLessThan(16, $pos->getFloorZ());

		self::assertInstanceOf(Chest::class, $this->world->getBlock($pos));
		$tile = $this->world->getTile($pos);
		self::assertInstanceOf(TileChest::class, $tile);
		self::assertSame(BonusChest::LOOT_TABLE, $tile->getLootTable());

		foreach(Facing::HORIZONTAL as $face){
			$torch = $this->world->getBlock($pos->getSide($face));
			self::assertInstanceOf(Torch::class, $torch);
			self::assertSame(Facing::UP, $torch->getFacing());
		}
	}

	public function testSameSeedSamePlace() : void{
		$this->floor(-1, -1, 16, 16);
		$first = BonusChest::place($this->world, 0, 0, new Random(42));
		self::assertNotNull($first);
		$this->set($first->getFloorX(), $first->getFloorY(), $first->getFloorZ(), VanillaBlocks::AIR());
		foreach(Facing::HORIZONTAL as $face){
			$side = $first->getSide($face);
			$this->set($side->getFloorX(), $side->getFloorY(), $side->getFloorZ(), VanillaBlocks::AIR());
		}
		$second = BonusChest::place($this->world, 15, 15, new Random(42));
		self::assertNotNull($second);
		self::assertTrue($first->equals($second));
	}

	public function testLeavesAreSkippedLikeTheHeightmap() : void{
		$this->floor(-1, -1, 16, 16);
		$leaves = VanillaBlocks::OAK_LEAVES();
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$this->world->setBlockAt($x, 6, $z, $leaves, false);
			}
		}
		$pos = BonusChest::place($this->world, 3, 3, new Random(7));
		self::assertNotNull($pos);
		self::assertSame(self::FLOOR_Y + 1, $pos->getFloorY());
	}

	public function testNoTorchWithoutFloor() : void{
		//a single stone pillar in an empty chunk: the chest stands on it, the torches have nothing to stand on
		$this->set(5, self::FLOOR_Y, 5, VanillaBlocks::STONE());
		$pos = BonusChest::place($this->world, 5, 5, new Random(3));
		self::assertNotNull($pos);
		self::assertSame(5, $pos->getFloorX());
		self::assertSame(5, $pos->getFloorZ());
		foreach(Facing::HORIZONTAL as $face){
			self::assertNotInstanceOf(Torch::class, $this->world->getBlock($pos->getSide($face)));
		}
	}
}
