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

namespace pocketmine\world\mobs;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Pillager;
use pocketmine\utils\Filesystem;
use pocketmine\world\dimension\DimensionWorldTestCase;
use pocketmine\world\format\io\data\BedrockWorldData;
use pocketmine\world\mobs\spawn\SpawnBlockInfo;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Pillager patrols: the world age gate (world_age_filter) and the pillager population cap.
 */
final class PatrolSpawnerTest extends DimensionWorldTestCase{
	private const FLOOR_Y = 63;

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		SpawnBlockInfo::clearCache();
		MobDespawner::clearCache();
		$this->fill(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y, 31, VanillaBlocks::GRASS());
	}

	public function testWorldAgeIsNotTheWorldTime() : void{
		self::assertSame(0, $this->world->getWorldAge());
		$this->world->setTime(200000);
		self::assertSame(0, $this->world->getWorldAge(), "/time set does not age the world");
		$this->world->stopTime();
		$this->runTicks(5);
		self::assertSame(200000, $this->world->getTime());
		self::assertSame(5, $this->world->getWorldAge(), "the world ages while the daylight cycle is off");

		$patrols = new PatrolSpawner($this->world);
		self::assertFalse($patrols->isWorldOldEnough(), "patrols wait for 6000 s of world age, not world time");
		$world = $this->world;
		(fn() => $this->worldAge = 120000)->call($world);
		self::assertTrue($patrols->isWorldOldEnough());
	}

	public function testWorldAgeIsSaved() : void{
		$path = sys_get_temp_dir() . "/pm-world-age-test-" . uniqid();
		mkdir($path);
		try{
			BedrockWorldData::generate($path, "age", WorldCreationOptions::create());
			$data = new BedrockWorldData($path . "/level.dat");
			self::assertSame(0, $data->getWorldAge());
			$data->setWorldAge(130000);
			$data->save();
			self::assertSame(130000, (new BedrockWorldData($path . "/level.dat"))->getWorldAge());
		}finally{
			Filesystem::recursiveUnlink($path);
		}
	}

	public function testPillagerCap() : void{
		$spawner = $this->world->getNaturalMobSpawner();
		self::assertNotNull($spawner);
		$spawner->setRules(SpawnRules::builtin());
		$patrols = new PatrolSpawner($this->world);
		self::assertFalse($patrols->isPillagerCapReached(8, 8));
		for($i = 0; $i < 8; ++$i){
			$pillager = new Pillager(new Location(2.5 + $i, self::FLOOR_Y + 1, 2.5, $this->world, 0, 0));
			$pillager->setPersistent(false);
			$pillager->spawnToAll();
		}
		++$this->tick;
		self::assertTrue($patrols->isPillagerCapReached(8, 8), "8 pillagers on the surface: the pillager cap is reached");
		self::assertFalse($patrols->isPillagerCapReached(8 + 16 * 5, 8), "outside the 9x9 chunks");
	}
}
