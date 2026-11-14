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

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\particle\EvaporationParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\EvaporateSound;
use pocketmine\world\World;

/**
 * Rules of ultra-warm dimensions (the Nether, see {@link Dimension::isUltraWarm()}): water put there evaporates
 * instead of being placed, and melting ice leaves nothing behind.
 *
 * @internal
 */
final class UltraWarmHelper{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns whether the position is in a world of an ultra-warm dimension.
	 */
	public static function isUltraWarm(Position $position) : bool{
		return $position->isValid() && $position->getWorld()->getDimension()->isUltraWarm();
	}

	/**
	 * Plays the effects of water evaporating in the block at the given position (like Java BucketItem: a fizz and a
	 * puff of steam).
	 */
	public static function playEvaporation(World $world, Vector3 $blockPos) : void{
		$center = $blockPos->floor()->add(0.5, 0.5, 0.5);
		$world->addSound($center, new EvaporateSound());
		$world->addParticle($center, new EvaporationParticle());
	}

	/**
	 * Returns what melting ice (taken from the world) turns into: water, or air in an ultra-warm dimension (Java
	 * IceBlock.melt()).
	 */
	public static function getMeltedIceState(Block $ice) : Block{
		return self::isUltraWarm($ice->getPosition()) ? VanillaBlocks::AIR() : VanillaBlocks::WATER();
	}
}
