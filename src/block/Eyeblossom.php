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

namespace pocketmine\block;

use pocketmine\math\Vector3;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\particle\EyeblossomTransformParticle;
use pocketmine\world\sound\EyeblossomSwitchSound;
use pocketmine\world\World;
use function count;
use function in_array;
use function max;
use function min;
use function mt_rand;
use function sqrt;

/**
 * Open and closed eyeblossom (Bedrock 1.21.50). In the Overworld, on random ticks an open eyeblossom closes during the
 * day and a closed one opens at night, with the long switch sound and particles. The eyeblossoms in the same state
 * within 3 blocks (2 vertically) follow a few ticks later (5-10 ticks per block of distance) with the short sound, so
 * patches open and close together (Java EyeblossomBlock).
 */
class Eyeblossom extends Flower{
	private const CHAIN_XZ_RANGE = 3;
	private const CHAIN_Y_RANGE = 2;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private bool $open){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function isOpen() : bool{
		return $this->open;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$this->tryChangingState(true);
	}

	public function onScheduledUpdate() : void{
		$this->tryChangingState(false);
	}

	/**
	 * Opens or closes the eyeblossom if it doesn't match the time of day (only in the Overworld), and schedules the
	 * nearby eyeblossoms in the same state to follow. Returns whether it changed.
	 */
	private function tryChangingState(bool $longSound) : bool{
		$world = $this->position->getWorld();
		if($world->getDimension() !== Dimension::OVERWORLD){
			return false;
		}
		$time = $world->getTimeOfDay();
		$night = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
		if($night === $this->open){
			return false;
		}
		$oldStateId = $this->getStateId();
		$new = $night ? VanillaBlocks::OPEN_EYEBLOSSOM() : VanillaBlocks::CLOSED_EYEBLOSSOM();
		$world->setBlock($this->position, $new);
		$center = $this->position->add(0.5, 0.5, 0.5);
		$world->addSound($center, new EyeblossomSwitchSound($new, $night, $longSound));
		$world->addParticle($center, new EyeblossomTransformParticle($night));

		$this->scheduleNearby($world, $oldStateId);
		return true;
	}

	/**
	 * Schedules an update for the eyeblossoms in the given state around. The box is only read when a flower changes,
	 * from the block arrays of the subchunks whose palette has that state (no block objects).
	 */
	private function scheduleNearby(World $world, int $stateId) : void{
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$minX = $x - self::CHAIN_XZ_RANGE;
		$maxX = $x + self::CHAIN_XZ_RANGE;
		$minZ = $z - self::CHAIN_XZ_RANGE;
		$maxZ = $z + self::CHAIN_XZ_RANGE;
		$minY = max($world->getMinY(), $y - self::CHAIN_Y_RANGE);
		$maxY = min($world->getMaxY() - 1, $y + self::CHAIN_Y_RANGE);
		for($chunkX = $minX >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxX >> Chunk::COORD_BIT_SIZE; ++$chunkX){
			for($chunkZ = $minZ >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$chunkZ){
				$chunk = $world->getChunk($chunkX, $chunkZ);
				if($chunk === null){
					continue;
				}
				for($subY = $minY >> SubChunk::COORD_BIT_SIZE; $subY <= $maxY >> SubChunk::COORD_BIT_SIZE; ++$subY){
					$layers = $chunk->getSubChunk($subY)->getBlockLayers();
					if(count($layers) === 0 || !in_array($stateId, $layers[0]->getPalette(), true)){
						continue;
					}
					$blocks = $layers[0];
					$fromY = max($minY, $subY << SubChunk::COORD_BIT_SIZE);
					$toY = min($maxY, ($subY << SubChunk::COORD_BIT_SIZE) | SubChunk::COORD_MASK);
					for($bx = max($minX, $chunkX << Chunk::COORD_BIT_SIZE), $toX = min($maxX, ($chunkX << Chunk::COORD_BIT_SIZE) | Chunk::COORD_MASK); $bx <= $toX; ++$bx){
						for($bz = max($minZ, $chunkZ << Chunk::COORD_BIT_SIZE), $toZ = min($maxZ, ($chunkZ << Chunk::COORD_BIT_SIZE) | Chunk::COORD_MASK); $bz <= $toZ; ++$bz){
							for($by = $fromY; $by <= $toY; ++$by){
								if($blocks->get($bx & Chunk::COORD_MASK, $by & SubChunk::COORD_MASK, $bz & Chunk::COORD_MASK) === $stateId){
									$distance = sqrt(($bx - $x) ** 2 + ($by - $y) ** 2 + ($bz - $z) ** 2);
									$world->scheduleDelayedBlockUpdate(new Vector3($bx, $by, $bz), mt_rand((int) ($distance * 5), (int) ($distance * 10)));
								}
							}
						}
					}
				}
			}
		}
	}
}
