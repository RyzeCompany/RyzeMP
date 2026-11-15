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

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\UltraWarmHelper;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityCombustByBlockEvent;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Facing;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\GameRules;
use pocketmine\world\sound\BucketEmptyLavaSound;
use pocketmine\world\sound\BucketFillLavaSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\World;
use function mt_rand;

class Lava extends Liquid{
	private const FLAG_FLAMMABLE = 1;
	private const FLAG_SOLID = 2;
	/** X and Z offsets of the 9 columns around a block (one random draw picks both) */
	private const OFFSET_X = [-1, 0, 1, -1, 0, 1, -1, 0, 1];
	private const OFFSET_Z = [-1, -1, -1, 0, 0, 0, 1, 1, 1];

	/**
	 * @var int[] state ID => FLAG_* bits
	 * @phpstan-var array<int, int>
	 */
	private static array $stateFlags = [];
	private static ?int $airStateId = null;

	public function getLightLevel() : int{
		return 15;
	}

	public function getBucketFillSound() : Sound{
		return new BucketFillLavaSound();
	}

	public function getBucketEmptySound() : Sound{
		return new BucketEmptyLavaSound();
	}

	public function tickRate() : int{
		return UltraWarmHelper::isUltraWarm($this->position) ? 10 : 30;
	}

	public function getFlowDecayPerBlock() : int{
		//1 in ultra-warm dimensions: Nether lava flows 7 blocks, and looks 4 blocks away for a slope
		return UltraWarmHelper::isUltraWarm($this->position) ? 1 : 2;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	/**
	 * Sets fire to flammable blocks nearby, like Java LavaFluid.randomTick(): either up to 2 random steps upwards,
	 * where air next to a flammable block catches fire (a solid block stops the search), or 3 random blocks at the
	 * same height, where a flammable block catches fire on top.
	 *
	 * Random ticks hit every lava block of the Nether seas, so the search reads state IDs and cached per-state flags
	 * and only builds blocks when it sets a fire.
	 */
	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($world->getGameRule(GameRules::DO_FIRE_TICK) === false){
			return;
		}
		$x = (int) $this->position->x;
		$y = (int) $this->position->y;
		$z = (int) $this->position->z;
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$layer = $chunk?->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getBlockLayers()[0] ?? null;
		if($chunk !== null && $layer !== null){
			self::randomTickAt($world, $chunk, $layer, $x, $y, $z);
		}
	}

	/**
	 * Random tick of the lava at the coordinates, in the given (loaded) chunk whose subchunk at that height has the
	 * given block layer 0, without building the lava block:
	 * World::tickChunk() calls it directly while doFireTick is on, since nearly every random tick of a lava sea hits
	 * lava under lava and does nothing. The caller checks doFireTick. See {@link Lava::onRandomTick()}.
	 *
	 * @internal
	 */
	public static function randomTickAt(World $world, Chunk $chunk, PalettedBlockArray $layer, int $x, int $y, int $z) : void{
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		//most reads stay in the subchunk of the lava: they go straight to its block layer
		$subChunkY = $y >> SubChunk::COORD_BIT_SIZE;
		//the search only goes up from the lava (or down to its height), so only the top of the world can be crossed
		$maxY = $world->getMaxY();
		$air = self::$airStateId ??= VanillaBlocks::AIR()->getStateId();
		//one draw for the whole search, 3 * 9^3 values: the kind of search (0: 3 blocks around, 1 or 2: steps upwards)
		//and up to 3 columns, each uniform over the 3x3 square around the lava like two draws of -1..1
		$random = mt_rand(0, 2186);
		$offset = $random % 27;
		$passes = $offset >= 18 ? 2 : ($offset >= 9 ? 1 : 0);
		$offset -= $passes * 9;
		$random = (int) ($random / 27);
		$offset1 = $random % 9;
		if($passes > 0){
			$bx = $x;
			$by = $y;
			$bz = $z;
			for($i = 0; $i < $passes; ++$i){
				if($i > 0){
					$offset = $offset1;
				}
				$bx += self::OFFSET_X[$offset];
				$bz += self::OFFSET_Z[$offset];
				if(++$by >= $maxY){
					return;
				}
				if(($bx >> Chunk::COORD_BIT_SIZE) === $chunkX && ($bz >> Chunk::COORD_BIT_SIZE) === $chunkZ){
					$stateId = ($by >> SubChunk::COORD_BIT_SIZE) === $subChunkY ?
						$layer->get($bx & Chunk::COORD_MASK, $by & SubChunk::COORD_MASK, $bz & Chunk::COORD_MASK) :
						$chunk->getBlockStateId($bx & Chunk::COORD_MASK, $by, $bz & Chunk::COORD_MASK);
				}else{
					$stateId = self::stateIdAt($world, $chunk, $chunkX, $chunkZ, $bx, $by, $bz);
					if($stateId === null){
						return;
					}
				}
				if($stateId === $air){
					if(self::hasFlammableNeighbours($world, $chunk, $chunkX, $chunkZ, $bx, $by, $bz)){
						self::ignite($world, $x, $y, $z, $bx, $by, $bz);
						return;
					}
				}elseif(((self::$stateFlags[$stateId] ?? self::computeStateFlags($stateId)) & self::FLAG_SOLID) !== 0){
					return;
				}
			}
		}else{
			if($y + 1 >= $maxY){
				return;
			}
			$aboveInLayer = (($y + 1) >> SubChunk::COORD_BIT_SIZE) === $subChunkY;
			$localY = $y & SubChunk::COORD_MASK;
			for($i = 0; $i < 3; ++$i){
				if($i === 1){
					$offset = $offset1;
				}elseif($i === 2){
					$offset = (int) ($random / 9);
				}
				$fx = $x + self::OFFSET_X[$offset];
				$fz = $z + self::OFFSET_Z[$offset];
				//the block above is read first: under the surface of a lava sea it is lava, and nothing else is needed
				if(($fx >> Chunk::COORD_BIT_SIZE) === $chunkX && ($fz >> Chunk::COORD_BIT_SIZE) === $chunkZ){
					$above = $aboveInLayer ?
						$layer->get($fx & Chunk::COORD_MASK, $localY + 1, $fz & Chunk::COORD_MASK) :
						$chunk->getBlockStateId($fx & Chunk::COORD_MASK, $y + 1, $fz & Chunk::COORD_MASK);
					if($above !== $air){
						continue;
					}
					$stateId = $layer->get($fx & Chunk::COORD_MASK, $localY, $fz & Chunk::COORD_MASK);
				}else{
					$above = self::stateIdAt($world, $chunk, $chunkX, $chunkZ, $fx, $y + 1, $fz);
					if($above === null){
						return;
					}
					if($above !== $air){
						continue;
					}
					$stateId = self::stateIdAt($world, $chunk, $chunkX, $chunkZ, $fx, $y, $fz);
				}
				if($stateId !== null && ((self::$stateFlags[$stateId] ?? self::computeStateFlags($stateId)) & self::FLAG_FLAMMABLE) !== 0){
					self::ignite($world, $x, $y, $z, $fx, $y + 1, $fz);
				}
			}
		}
	}

	/**
	 * Returns the state ID of the block at the coordinates, or null if they are outside the world or in a chunk which
	 * is not loaded (fire never starts there). Most lookups fall in the chunk of the lava block, given with its
	 * coordinates.
	 */
	private static function stateIdAt(World $world, Chunk $chunk, int $chunkX, int $chunkZ, int $x, int $y, int $z) : ?int{
		if($y < $world->getMinY() || $y >= $world->getMaxY()){
			return null;
		}
		if(($x >> Chunk::COORD_BIT_SIZE) !== $chunkX || ($z >> Chunk::COORD_BIT_SIZE) !== $chunkZ){
			$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
			if($chunk === null){
				return null;
			}
		}
		return $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
	}

	private static function hasFlammableNeighbours(World $world, Chunk $chunk, int $chunkX, int $chunkZ, int $x, int $y, int $z) : bool{
		foreach(Facing::OFFSET as [$dx, $dy, $dz]){
			$stateId = self::stateIdAt($world, $chunk, $chunkX, $chunkZ, $x + $dx, $y + $dy, $z + $dz);
			if($stateId !== null && ((self::$stateFlags[$stateId] ?? self::computeStateFlags($stateId)) & self::FLAG_FLAMMABLE) !== 0){
				return true;
			}
		}
		return false;
	}

	/**
	 * Sets fire at the coordinates, spreading from the lava at $lavaX/$lavaY/$lavaZ.
	 */
	private static function ignite(World $world, int $lavaX, int $lavaY, int $lavaZ, int $x, int $y, int $z) : void{
		$block = $world->getBlockAt($x, $y, $z);
		$fire = SoulFire::canBeSupportedBy($block->getSide(Facing::DOWN)) ? VanillaBlocks::SOUL_FIRE() : VanillaBlocks::FIRE();
		BlockEventHelper::spread($block, $fire, $world->getBlockAt($lavaX, $lavaY, $lavaZ));
	}

	private static function computeStateFlags(int $stateId) : int{
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		return self::$stateFlags[$stateId] = ($block->isFlammable() ? self::FLAG_FLAMMABLE : 0) | ($block->isSolid() ? self::FLAG_SOLID : 0);
	}

	/**
	 * @phpstan-return \Generator<int, Block, void, void>
	 */
	private function getAdjacentBlocksExceptDown() : \Generator{
		foreach(Facing::ALL as $side){
			if($side === Facing::DOWN){
				continue;
			}
			yield $this->getSide($side);
		}
	}

	protected function checkForHarden() : bool{
		if($this->falling){
			return false;
		}
		foreach($this->getAdjacentBlocksExceptDown() as $colliding){
			//waterlogged blocks harden lava too, without losing their water
			if(LiquidLayer::isWater($colliding)){
				if($this->decay === 0){
					$this->liquidCollide($colliding, VanillaBlocks::OBSIDIAN());
					return true;
				}elseif($this->decay <= 4){
					$this->liquidCollide($colliding, VanillaBlocks::COBBLESTONE());
					return true;
				}
			}
		}

		if($this->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::SOUL_SOIL){
			foreach($this->getAdjacentBlocksExceptDown() as $colliding){
				if($colliding->getTypeId() === BlockTypeIds::BLUE_ICE){
					$this->liquidCollide($colliding, VanillaBlocks::BASALT());
					return true;
				}
			}
		}

		return false;
	}

	protected function flowIntoBlock(Block $block, int $newFlowDecay, bool $falling) : void{
		if($block instanceof Water){
			$block->liquidCollide($this, VanillaBlocks::STONE());
		}else{
			parent::flowIntoBlock($block, $newFlowDecay, $falling);
		}
	}

	public function onEntityInside(Entity $entity) : bool{
		$ev = new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_LAVA, 4);
		$entity->attack($ev);

		//in java burns entities for 15 seconds - seems to be a parity issue in bedrock
		$ev = new EntityCombustByBlockEvent($this, $entity, 8);
		$ev->call();
		if(!$ev->isCancelled()){
			$entity->setOnFire($ev->getDuration());
		}

		$entity->resetFallDistance();
		return true;
	}
}
