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

namespace pocketmine\entity\ai;

use pocketmine\block\Block;
use pocketmine\block\HoneyBlock;
use pocketmine\block\Lava;
use pocketmine\block\PowderSnow;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\SoulSand;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;

/**
 * @internal
 * What the blocks around an AI-moved mob do to its movement (see {@link \pocketmine\entity\Mob::tryChangeMovement()}):
 * climbing, lava, powder snow, slipperiness and speed factor. Looked up every tick for each walking mob, so everything
 * is cached per block state: a lookup is a chunk access and an array read, no Block object.
 *
 * {@link MobBlockPhysics::at()} packs the state ID and its flags in one int (state ID << 8 | flags), so callers get
 * both without allocating; the friction and the speed factor are only read for states flagged as having one.
 */
final class MobBlockPhysics{
	/** Ladders, vines, scaffolding, twisting, weeping and cave vines (vanilla CLIMBABLE tag). */
	public const CLIMBABLE = 1;
	public const LAVA = 2;
	public const POWDER_SNOW = 4;
	/** The block slows down what walks on it or in it (vanilla speed factor below 1: soul sand, honey). */
	public const SLOW = 8;
	/** The block's friction is not the default 0.6 (ice, slime). */
	public const SLIPPERY = 16;

	public const FLAGS_MASK = 0xff;
	private const STATE_SHIFT = 8;

	public const DEFAULT_FRICTION = 0.6;
	/** Vanilla speed factor of soul sand and honey blocks. */
	private const SLOW_SPEED_FACTOR = 0.4;

	/**
	 * @var int[] state ID => flags
	 * @phpstan-var array<int, int>
	 */
	private static array $flags = [];
	/**
	 * @var float[] state ID => friction, for the states flagged SLIPPERY
	 * @phpstan-var array<int, float>
	 */
	private static array $friction = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the state ID of the block at the given position shifted left by 8, with its flags in the low 8 bits
	 * (see {@link MobBlockPhysics::FLAGS_MASK}); 0 outside the world and in unloaded chunks.
	 */
	public static function at(World $world, int $x, int $y, int $z) : int{
		//the state read in C++ when chunkutils2 can (0.3.12): one call instead of the lookups below
		if(($native = World::$nativePhysics) !== null && ($stateId = $native->stateAt($world, $x, $y, $z)) !== -4){
			return $stateId < 0 ? 0 : ($stateId << self::STATE_SHIFT) | (self::$flags[$stateId] ?? self::compute($stateId));
		}
		if($y < $world->getMinY() || $y >= $world->getMaxY()){
			return 0;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return 0;
		}
		$stateId = $chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
		return ($stateId << self::STATE_SHIFT) | (self::$flags[$stateId] ?? self::compute($stateId));
	}

	/**
	 * Same as {@link MobBlockPhysics::at()} for a block already at hand. World::getBlockAt() answers from the world's block
	 * cache: cheaper than reading the chunk for the blocks mobs walk on, looked up again on every tick.
	 */
	public static function ofBlock(Block $block) : int{
		$stateId = $block->getStateId();
		return ($stateId << self::STATE_SHIFT) | (self::$flags[$stateId] ?? self::compute($stateId));
	}

	/**
	 * Friction of the block of a packed value returned by {@link MobBlockPhysics::at()} (vanilla slipperiness).
	 */
	public static function friction(int $packed) : float{
		return ($packed & self::SLIPPERY) !== 0 ? self::$friction[$packed >> self::STATE_SHIFT] : self::DEFAULT_FRICTION;
	}

	/**
	 * Speed factor of the block of a packed value returned by {@link MobBlockPhysics::at()}: the velocity of what moves
	 * on it or in it is multiplied by this every tick.
	 */
	public static function speedFactor(int $packed) : float{
		return ($packed & self::SLOW) !== 0 ? self::SLOW_SPEED_FACTOR : 1.0;
	}

	private static function compute(int $stateId) : int{
		$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		$flags = self::classify($block);
		if(($flags & self::SLIPPERY) !== 0){
			self::$friction[$stateId] = $block->getFrictionFactor();
		}
		return self::$flags[$stateId] = $flags;
	}

	private static function classify(Block $block) : int{
		$flags = 0;
		if($block->canClimb()){
			$flags |= self::CLIMBABLE;
		}
		if($block instanceof Lava){
			$flags |= self::LAVA;
		}elseif($block instanceof PowderSnow){
			$flags |= self::POWDER_SNOW;
		}
		if($block instanceof SoulSand || $block instanceof HoneyBlock){
			$flags |= self::SLOW;
		}
		//honey holds mobs back by its speed factor only (Java HoneyBlock keeps the default friction): its 0.8 friction
		//factor on top of that would slow them down twice
		if($block->getFrictionFactor() !== self::DEFAULT_FRICTION && !$block instanceof HoneyBlock){
			$flags |= self::SLIPPERY;
		}
		return $flags;
	}

	/**
	 * @internal for tests
	 */
	public static function clearCache() : void{
		self::$flags = [];
		self::$friction = [];
	}
}
