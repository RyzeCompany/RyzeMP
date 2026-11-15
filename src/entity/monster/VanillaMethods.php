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

namespace pocketmine\entity\monster;

use function in_array;

/**
 * Whether a mob class still runs the vanilla code of a method, i.e. no subclass (a plugin's, say) overrides it: what the
 * method accepts is then known, so target searches can pass over the entities of every other class (see the candidate
 * classes of {@link \pocketmine\entity\ai\goal\NearestAttackableEntityGoal}). Worked out once per class and method.
 *
 * @internal
 */
final class VanillaMethods{
	/**
	 * The class declaring each class::method asked about (only the declaring class is cached: callers may ask about the
	 * same method with different owners).
	 *
	 * @var string[]
	 * @phpstan-var array<string, class-string>
	 */
	private static array $declaringClasses = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns whether $class gets $method from one of $owners.
	 *
	 * @param string[] $owners
	 * @phpstan-param class-string $class
	 * @phpstan-param list<class-string> $owners
	 */
	public static function isDeclaredBy(string $class, string $method, array $owners) : bool{
		$declaringClass = self::$declaringClasses[$class . "::" . $method] ??= (new \ReflectionMethod($class, $method))->getDeclaringClass()->getName();
		return in_array($declaringClass, $owners, true);
	}
}
