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

namespace pocketmine\entity\projectile;

use pocketmine\entity\Entity;
use pocketmine\entity\monster\Blaze;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\math\RayTraceResult;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\particle\SnowballPoofParticle;

/**
 * Snowballs only push mobs, except blazes which take 3 damage (vanilla Snowball.onHitEntity).
 */
class Snowball extends Throwable{
	public const BLAZE_DAMAGE = 3.0;

	public static function getNetworkTypeId() : string{ return EntityIds::SNOWBALL; }

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		if($entityHit instanceof Blaze){
			$this->setBaseDamage(self::BLAZE_DAMAGE);
		}
		parent::onHitEntity($entityHit, $hitResult);
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		$world = $this->getWorld();
		for($i = 0; $i < 6; ++$i){
			$world->addParticle($this->location, new SnowballPoofParticle());
		}
	}
}
