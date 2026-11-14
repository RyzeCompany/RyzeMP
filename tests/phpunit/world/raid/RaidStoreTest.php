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

namespace pocketmine\world\raid;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class RaidStoreTest extends TestCase{
	private string $path;

	protected function setUp() : void{
		$this->path = sys_get_temp_dir() . "/pm-raids-test-" . uniqid() . ".json";
	}

	protected function tearDown() : void{
		if(file_exists($this->path)){
			unlink($this->path);
		}
	}

	public function testRaidRoundTrip() : void{
		$raid = new Raid(7, new Vector3(10.5, 64.0, -3.5), 3, World::DIFFICULTY_HARD, true);
		$raid->setBeds([new Vector3(10, 64, -4), new Vector3(12, 64, -4)]);
		$data = $raid->toArray();

		$loaded = Raid::fromArray($data);
		self::assertNotNull($loaded);
		self::assertSame(7, $loaded->getId());
		self::assertTrue($loaded->getCenter()->equals(new Vector3(10.5, 64.0, -3.5)));
		self::assertSame(3, $loaded->getOmenLevel());
		self::assertSame(7, $loaded->getTotalWaves());
		self::assertSame(0, $loaded->getWave());
		self::assertSame(Raid::PREPARE_TICKS, $loaded->getCooldown());
		self::assertSame(RaidStatus::ONGOING, $loaded->getStatus());
		self::assertCount(2, $loaded->getBeds());
		self::assertSame($data, $loaded->toArray());
	}

	public function testInvalidRaidDataIsRejected() : void{
		self::assertNull(Raid::fromArray([]));
		self::assertNull(Raid::fromArray(["id" => 1, "totalWaves" => 5, "center" => [1, 2]]));
		self::assertNull(Raid::fromArray(["id" => "x", "totalWaves" => 5, "center" => [1, 2, 3]]));
	}

	public function testStoreSurvivesReload() : void{
		$raid = new Raid(2, new Vector3(0.5, 70, 0.5), 1, World::DIFFICULTY_NORMAL, false);
		$store = new RaidStore($this->path);
		$store->set("world", 3, [$raid->toArray()]);
		self::assertFileExists($this->path);

		$reloaded = new RaidStore($this->path);
		$saved = $reloaded->get("world");
		self::assertSame(3, $saved["nextId"]);
		self::assertCount(1, $saved["raids"]);
		$loaded = Raid::fromArray($saved["raids"][0]);
		self::assertNotNull($loaded);
		self::assertSame(5, $loaded->getTotalWaves());
		self::assertSame(["nextId" => 1, "raids" => []], $reloaded->get("other_world"));
	}

	public function testCorruptedFileIsIgnored() : void{
		file_put_contents($this->path, "{not json");
		$store = new RaidStore($this->path);
		self::assertSame(["nextId" => 1, "raids" => []], $store->get("world"));
	}
}
