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

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Throwable;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\ThrowSound;
use pocketmine\world\vibration\GameEvent;
use function cos;
use function deg2rad;
use function sin;

abstract class ProjectileItem extends Item{

	abstract public function getThrowForce() : float;

	abstract protected function createEntity(Location $location, Player $thrower) : Throwable;

	/**
	 * Returns how many degrees the projectile is thrown below the direction the thrower looks at (Bedrock
	 * minecraft:projectile angle_offset; negative values throw it higher).
	 */
	protected function getThrowAngleOffset() : float{
		return 0.0;
	}

	/**
	 * Returns the direction a projectile is thrown in by a thrower looking with the given yaw and pitch (degrees), with
	 * its vertical part turned down by the angle offset (vanilla Projectile.shootFromRotation(): only the vertical part
	 * gets the offset, so looking straight up or down still throws straight up or down, never backwards).
	 */
	private static function getThrowDirection(float $yaw, float $pitch, float $angleOffset) : Vector3{
		$yaw = deg2rad($yaw);
		$horizontal = cos(deg2rad($pitch));
		return (new Vector3(-$horizontal * sin($yaw), -sin(deg2rad($pitch + $angleOffset)), $horizontal * cos($yaw)))->normalize();
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$location = $player->getLocation();
		$angleOffset = $this->getThrowAngleOffset();
		if($angleOffset !== 0.0){
			$directionVector = self::getThrowDirection($location->yaw, $location->pitch, $angleOffset);
		}

		$projectile = $this->createEntity(Location::fromObject($player->getEyePos(), $player->getWorld(), $location->yaw, $location->pitch), $player);
		$projectile->setMotion($directionVector->multiply($this->getThrowForce()));

		$projectileEv = new ProjectileLaunchEvent($projectile);
		$projectileEv->call();
		if($projectileEv->isCancelled()){
			$projectile->flagForDespawn();
			return ItemUseResult::FAIL;
		}

		$projectile->spawnToAll();

		$location->getWorld()->addSound($location, new ThrowSound());
		$location->getWorld()->addGameEvent($location, GameEvent::PROJECTILE_SHOOT, $player);

		$this->pop();

		return ItemUseResult::SUCCESS;
	}
}
