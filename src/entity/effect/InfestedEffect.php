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
use pocketmine\entity\monster\Silverfish;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use function cos;
use function mt_rand;
use function sin;
use const M_PI_2;

/**
 * Infested (https://minecraft.wiki/w/Infested): each time the entity is hurt there is a 10% chance that 1-2 silverfish
 * come out of it, halfway up its body, pushed out in front of it (Java InfestedMobEffect: look direction x0.3, 1.5x
 * upwards, turned by a random angle up to 90 degrees). The amplifier doesn't change the chance or the count. Nothing
 * spawns in peaceful, where silverfish despawn. Silverfish themselves are immune to it, so they never chain.
 */
class InfestedEffect extends Effect implements DamageAwareEffect{
	public const SPAWN_CHANCE = 0.1;

	public function onEntityDamaged(Living $entity, EffectInstance $instance, EntityDamageEvent $source) : void{
		$count = self::rollSilverfishCount();
		if($count > 0){
			self::spawnSilverfish($entity, $count);
		}
	}

	/**
	 * Returns how many silverfish one hit lets out: 0 (90%), 1 or 2.
	 */
	public static function rollSilverfishCount() : int{
		return Utils::getRandomFloat() < self::SPAWN_CHANCE ? mt_rand(1, 2) : 0;
	}

	/**
	 * Spawns the given number of silverfish out of the entity and returns them (none in peaceful).
	 *
	 * @return Silverfish[]
	 */
	public static function spawnSilverfish(Living $entity, int $count) : array{
		$world = $entity->getWorld();
		if($world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return [];
		}
		$pos = $entity->getPosition();
		$y = $pos->y + $entity->getSize()->getHeight() / 2;
		$look = $entity->getDirectionVector()->multiply(0.3);
		$spawned = [];
		for($i = 0; $i < $count; ++$i){
			$silverfish = new Silverfish(new Location($pos->x, $y, $pos->z, $world, mt_rand(0, 359), 0));
			//rotate the look direction around the vertical axis by -90..90 degrees
			$angle = (Utils::getRandomFloat() * 2 - 1) * M_PI_2;
			$cos = cos($angle);
			$sin = sin($angle);
			$silverfish->setMotion($look->withComponents(
				$look->x * $cos + $look->z * $sin,
				$look->y * 1.5,
				$look->z * $cos - $look->x * $sin
			));
			$silverfish->setPersistent(false);
			$silverfish->spawnToAll();
			$spawned[] = $silverfish;
		}
		return $spawned;
	}
}
