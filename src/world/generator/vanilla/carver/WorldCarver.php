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

namespace pocketmine\world\generator\vanilla\carver;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\WorldgenData;

/**
 * A configured carver of the worldgen data (worldgen/carver, 26.3+ format): carves tunnels started from one chunk
 * into the chunk being generated.
 */
abstract class WorldCarver{
	/** Chunks around a start chunk that its tunnels can reach (WorldCarver.getRange()). */
	public const RANGE = 4;

	public function __construct(
		public readonly string $id,
		public readonly float $probability,
		protected readonly ValueProvider $y
	){}

	/**
	 * @param mixed[] $json
	 */
	public static function fromJson(string $id, array $json, int $minY, int $height) : self{
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		return match($type){
			"cave" => CaveCarver::fromJson($id, $json, $minY, $height),
			"canyon" => CanyonCarver::fromJson($id, $json, $minY, $height),
			default => throw new \RuntimeException("Unsupported carver type \"$type\" ($id)")
		};
	}

	/** ConfiguredWorldCarver.isStartChunk(): the first draw of the start chunk's random. */
	public function isStartChunk(LegacyRandom $random) : bool{
		return $random->nextFloat() <= $this->probability;
	}

	/**
	 * Carves the tunnels started in chunk ($startX, $startZ) into the target chunk of $run.
	 */
	abstract public function carve(CarvingRun $run, LegacyRandom $random, int $startX, int $startZ) : void;

	/**
	 * WorldCarver.canReach(): whether a tunnel at (x, z) with the given steps left can still reach the target chunk.
	 */
	public static function canReach(CarvingRun $run, float $x, float $z, int $step, int $steps, float $thickness) : bool{
		$dx = $x - $run->middleX;
		$dz = $z - $run->middleZ;
		$left = (float) ($steps - $step);
		$reach = CarverMath::f(CarverMath::f($thickness + 2.0) + 16.0);
		return $dx * $dx + $dz * $dz - $left * $left <= $reach * $reach;
	}
}
