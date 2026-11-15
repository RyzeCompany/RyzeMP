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

use function strlen;

/**
 * The writes of one chunk, collected before any of them is added to a LevelDB write batch, so that a chunk is always
 * added to a batch whole (a batch kept for a later write must never hold part of a chunk).
 *
 * @internal
 */
final class ChunkWriteOps{
	/**
	 * Key, value (null = delete), in order. A list rather than a key => value map: PHP would turn keys that look like
	 * numbers into integers.
	 * @phpstan-var list<array{string, ?string}>
	 */
	private array $ops = [];

	public function put(string $key, string $value) : void{
		$this->ops[] = [$key, $value];
	}

	public function delete(string $key) : void{
		$this->ops[] = [$key, null];
	}

	/**
	 * Adds the writes to the batch, in order.
	 *
	 * @return int approximate size of the added data in bytes
	 */
	public function addTo(\LevelDBWriteBatch $batch) : int{
		$bytes = 0;
		foreach($this->ops as [$key, $value]){
			if($value === null){
				$batch->delete($key);
				$bytes += strlen($key);
			}else{
				$batch->put($key, $value);
				$bytes += strlen($key) + strlen($value);
			}
		}
		return $bytes;
	}
}
