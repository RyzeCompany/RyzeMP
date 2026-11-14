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

use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;

//Classes added by the chunkutils2 extension of PocketMine-MP Reborn in the io namespace (see chunkutils2-reborn.php).
//Scanned by PHPStan only (phpstan.neon.dist scanFiles), never loaded.

/**
 * chunkutils2 >= 0.3.8: the subchunk section of FastChunkSerializer, natively (same bytes).
 */
final class FastSubChunkSerializer{
	/**
	 * @param SubChunk[] $subChunks
	 * @phpstan-param array<int, SubChunk> $subChunks
	 */
	public static function serialize(array $subChunks) : string{ return ""; }

	/**
	 * @return array[] subchunk index => [empty block ID, block layers, biome array]
	 * @phpstan-return array<int, array{int, list<PalettedBlockArray>, PalettedBlockArray}>
	 */
	public static function deserialize(string $data, int &$offset) : array{ return []; }

	private function __construct(){}
}
