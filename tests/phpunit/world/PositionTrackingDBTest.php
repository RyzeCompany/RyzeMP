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

use PHPUnit\Framework\TestCase;
use pocketmine\item\LodestoneCompass;
use pocketmine\item\VanillaItems;
use function file_exists;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class PositionTrackingDBTest extends TestCase{
	private string $path;

	protected function setUp() : void{
		$path = tempnam(sys_get_temp_dir(), "pm_pos_tracking");
		if($path === false){
			self::fail("Unable to create a temporary file");
		}
		unlink($path);
		$this->path = $path;
	}

	protected function tearDown() : void{
		if(file_exists($this->path)){
			unlink($this->path);
		}
	}

	public function testRegisterPersistsAndRemoves() : void{
		$db = new PositionTrackingDB($this->path);
		$first = $db->register("world", 1, 64, -3);
		$second = $db->register("nether", -10, 30, 7);
		self::assertSame(1, $first, "handle 0 means not tracking, so the first handle must be 1");
		self::assertNotSame($first, $second);

		$reloaded = new PositionTrackingDB($this->path);
		self::assertSame(["world" => "world", "x" => 1, "y" => 64, "z" => -3], $reloaded->get($first));
		self::assertSame(["world" => "nether", "x" => -10, "y" => 30, "z" => 7], $reloaded->get($second));

		$reloaded->remove($first);
		self::assertNull($reloaded->get($first));
		self::assertTrue($reloaded->wasAssigned($first));
		self::assertFalse($reloaded->wasAssigned($second + 1));

		//removed handles are never reused
		$third = (new PositionTrackingDB($this->path))->register("world", 0, 0, 0);
		self::assertGreaterThan($second, $third);
	}

	public function testLodestoneCompassTrackingHandleNbt() : void{
		$compass = VanillaItems::LODESTONE_COMPASS()->setTrackingHandle(42);
		self::assertSame(42, $compass->getNamedTag()->getInt(LodestoneCompass::TAG_TRACKING_HANDLE));

		$copy = VanillaItems::LODESTONE_COMPASS();
		$copy->setNamedTag($compass->getNamedTag());
		self::assertSame(42, $copy->getTrackingHandle());

		$compass->setTrackingHandle(0);
		self::assertNull($compass->getNamedTag()->getTag(LodestoneCompass::TAG_TRACKING_HANDLE));
	}
}
