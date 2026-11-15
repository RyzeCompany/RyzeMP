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

namespace pocketmine\world\format\io;

/**
 * A writable world provider that can group the writes of several chunks into one write.
 *
 * Between {@link WriteBatchWorldProvider::beginWriteBatch()} and {@link WriteBatchWorldProvider::commitWriteBatch()},
 * {@link WritableWorldProvider::saveChunk()} may keep the data of the chunk in memory instead of writing it at once.
 * - The data of a chunk is still written atomically: all of it or nothing.
 * - Every read of the provider (loading a chunk, iterating chunks, raw database access) first writes what is kept, so
 *   reads always see the latest saved data.
 * - Batches nest: only the outermost commit writes. The provider may also write early, e.g. when a lot is kept.
 * - If a write fails, the exception is thrown and the data is kept: the next commit or read writes it again (as do
 *   later writes on close()).
 */
interface WriteBatchWorldProvider extends WritableWorldProvider{

	public function beginWriteBatch() : void;

	/**
	 * Ends the batch started by the matching {@link WriteBatchWorldProvider::beginWriteBatch()}. The outermost commit
	 * writes everything kept.
	 */
	public function commitWriteBatch() : void;
}
