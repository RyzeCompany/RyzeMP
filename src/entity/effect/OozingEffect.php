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
namespace pocketmine\entity\effect;

use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Slime;
use pocketmine\world\World;
use function mt_rand;

/**
 * Oozing (https://minecraft.wiki/w/Oozing): 2 medium slimes (size 2) come out of the entity when it dies, half a block
 * above where it died (Java OozingMobEffect). Bedrock has no entity cramming rule, so the nearby slime count of Java
 * doesn't limit them, and the amplifier doesn't change the count. Nothing spawns in peaceful, where slimes despawn.
 * Slimes themselves are immune to it.
 */
class OozingEffect extends Effect{
	public const SLIME_COUNT = 2;
	public const SLIME_SIZE = 2;

	public function onEntityDeath(Living $entity, EffectInstance $instance) : void{
		self::spawnSlimes($entity);
	}

	/**
	 * Spawns the slimes of a dead oozing entity and returns them (none in peaceful).
	 *
	 * @return Slime[]
	 */
	public static function spawnSlimes(Living $entity) : array{
		$world = $entity->getWorld();
		if($world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return [];
		}
		$pos = $entity->getPosition();
		$slimes = [];
		for($i = 0; $i < self::SLIME_COUNT; ++$i){
			$slime = new Slime(new Location($pos->x, $pos->y + 0.5, $pos->z, $world, mt_rand(0, 359), 0), null, self::SLIME_SIZE);
			$slime->setPersistent(false);
			$slime->spawnToAll();
			$slimes[] = $slime;
		}
		return $slimes;
	}
}
