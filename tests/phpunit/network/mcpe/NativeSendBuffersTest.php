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

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use function class_exists;
use function str_repeat;

/**
 * The C++ send buffers (chunkutils2 >= 0.3.9) must behave like the PHP arrays they replace: packets appended in order,
 * fan-out to many sessions in one call, nothing delivered to closed or unknown ids.
 */
final class NativeSendBuffersTest extends TestCase{

	protected function setUp() : void{
		if(!class_exists(NativeSendBuffers::class)){
			self::markTestSkipped("chunkutils2 >= 0.3.9 is not loaded");
		}
	}

	public function testAppendTakeAndFanOut() : void{
		$buffers = new NativeSendBuffers();
		$buffers->open(1);
		$buffers->open(2);
		$buffers->open(7);
		self::assertSame(3, $buffers->count());
		self::assertSame("", $buffers->take(1));

		$buffers->append(1, "a");
		self::assertSame(2, $buffers->appendMany([1, 2, 99, 0, -5], "bc")); //unknown ids are ignored
		$buffers->append(7, str_repeat("z", 100000));
		self::assertSame(3, $buffers->length(1));
		self::assertSame("abc", $buffers->take(1));
		self::assertSame("", $buffers->take(1)); //taken = cleared
		self::assertSame("bc", $buffers->take(2));
		self::assertSame(100000, $buffers->length(7));
		self::assertSame(str_repeat("z", 100000), $buffers->take(7));
	}

	public function testClosedIdsGetNothing() : void{
		$buffers = new NativeSendBuffers();
		$buffers->open(3);
		$buffers->append(3, "x");
		$buffers->close(3);
		self::assertFalse($buffers->isOpen(3));
		self::assertSame("", $buffers->take(3));
		$buffers->append(3, "y");
		self::assertSame(0, $buffers->appendMany([3], "y"));
		self::assertSame("", $buffers->take(3));
		self::assertSame(0, $buffers->count());

		$this->expectException(\InvalidArgumentException::class);
		$buffers->open(4);
		$buffers->open(4);
	}
}
