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

namespace pocketmine\block\dispenser;

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\sound\LaunchSound;
use pocketmine\world\sound\Sound;
use function atan2;
use function sqrt;
use const M_PI;

/**
 * Shoots the item as a projectile in the direction the dispenser faces (vanilla ProjectileDispenseBehavior: the
 * direction gets 0.1 added upwards, then a random spread of 0.0075 * uncertainty per axis, times the power).
 */
class ProjectileDispenseBehavior implements DispenseBehavior{

	/**
	 * @phpstan-param \Closure(Location $location, Item $item) : Entity $createEntity
	 */
	public function __construct(
		private \Closure $createEntity,
		private float $power = 1.1,
		private float $uncertainty = 6.0,
		private ?Sound $sound = null
	){}

	public function dispense(DispenseContext $context, Item $stack) : ?Item{
		[$dx, $dy, $dz] = Facing::OFFSET[$context->getFacing()];
		$direction = (new Vector3($dx, $dy + 0.1, $dz))->normalize();
		$spread = 0.0075 * $this->uncertainty;
		$motion = (new Vector3(
			$direction->x + DispenseContext::nextGaussian() * $spread,
			$direction->y + DispenseContext::nextGaussian() * $spread,
			$direction->z + DispenseContext::nextGaussian() * $spread
		))->multiply($this->power);

		$horizontal = sqrt($motion->x ** 2 + $motion->z ** 2);
		$yaw = atan2($motion->z, $motion->x) * 180 / M_PI - 90;
		$pitch = -atan2($motion->y, $horizontal) * 180 / M_PI;
		$location = Location::fromObject($context->getDispensePosition(), $context->getWorld(), $yaw, $pitch);

		$entity = ($this->createEntity)($location, (clone $stack)->setCount(1));
		$entity->setMotion($motion);
		if($entity instanceof Projectile){
			$ev = new ProjectileLaunchEvent($entity);
			$ev->call();
			if($ev->isCancelled()){
				$entity->flagForDespawn();
				return null;
			}
		}
		$entity->spawnToAll();
		$context->playEffects($this->sound ?? new LaunchSound());
		return $stack->setCount($stack->getCount() - 1);
	}
}
