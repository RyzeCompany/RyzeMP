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

namespace pocketmine\plugin;

use function array_filter;
use function array_map;
use function array_values;
use function is_string;
use function str_contains;

final class CapturingLogger extends \SimpleLogger{
	/**
	 * @var string[][]
	 * @phpstan-var list<array{string, string}>
	 */
	public array $lines = [];

	public function log($level, $message){
		$this->lines[] = [is_string($level) ? $level : "", (string) $message];
	}

	public function logException(\Throwable $e, $trace = null){
		$this->critical($e->getMessage());
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function messages(string $level, string $contains = "") : array{
		return array_values(array_map(
			fn(array $line) => $line[1],
			array_filter($this->lines, fn(array $line) => $line[0] === $level && ($contains === "" || str_contains($line[1], $contains)))
		));
	}
}
