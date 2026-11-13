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

namespace pocketmine\world\generator\executor;

use pocketmine\world\generator\Generator;
use pocketmine\world\generator\vanilla\InvalidVanillaDataException;

/**
 * Manages thread-local caches for generators and the things needed to support them
 */
final class ThreadLocalGeneratorContext{
	/**
	 * @var self[]
	 * @phpstan-var array<int, self>
	 */
	private static array $contexts = [];
	/** @var array<int, string> */
	private static array $initializationFailures = [];

	public static function register(self $context, int $worldId) : void{
		self::$contexts[$worldId] = $context;
		unset(self::$initializationFailures[$worldId]);
	}

	public static function unregister(int $worldId) : void{
		unset(self::$contexts[$worldId], self::$initializationFailures[$worldId]);
	}

	public static function failInitialization(int $worldId, string $message) : void{
		unset(self::$contexts[$worldId]);
		self::$initializationFailures[$worldId] = $message;
	}

	public static function fetch(int $worldId) : ?self{
		if(isset(self::$initializationFailures[$worldId])){
			throw new InvalidVanillaDataException(self::$initializationFailures[$worldId]);
		}
		return self::$contexts[$worldId] ?? null;
	}

	public function __construct(
		private Generator $generator,
		private int $worldMinY,
		private int $worldMaxY
	){}

	public function getGenerator() : Generator{ return $this->generator; }

	public function getWorldMinY() : int{ return $this->worldMinY; }

	public function getWorldMaxY() : int{ return $this->worldMaxY; }
}
