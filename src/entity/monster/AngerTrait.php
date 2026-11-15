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

namespace pocketmine\entity\monster;

use pocketmine\entity\Living;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\Player;

/**
 * Anger of a neutral mob (vanilla minecraft:angry): who it is angry at and for how many more ticks. Anger can be
 * broadcast to mobs of the same kind nearby (zombified piglins, piglins, silverfish). Attacking the anger target is
 * done by {@link \pocketmine\entity\ai\hostile\goal\AngerTargetGoal}.
 *
 * The using class must call {@link AngerTrait::tickAnger()} every tick.
 */
trait AngerTrait{
	private int $angerTicks = 0;
	private ?int $angerTargetId = null;

	public function isAngry() : bool{
		return $this->angerTicks !== 0 && $this->angerTargetId !== null;
	}

	/**
	 * Returns the entity the mob is angry at, if it is still around.
	 */
	public function getAngerTarget() : ?Living{
		if(!$this->isAngry() || $this->angerTargetId === null){
			return null;
		}
		$entity = $this->getWorld()->getEntity($this->angerTargetId);
		if(!$entity instanceof Living || !$entity->isAlive() || ($entity instanceof Player && ($entity->isCreative() || $entity->isSpectator()))){
			return null;
		}
		return $entity;
	}

	/**
	 * Makes the mob angry at the target for the given ticks (-1: until the target is gone), or calms it down (null).
	 */
	public function setAngryAt(?Living $target, int $ticks) : void{
		$wasAngry = $this->isAngry();
		$this->angerTargetId = $target?->getId();
		$this->angerTicks = $target !== null ? $ticks : 0;
		if($wasAngry !== $this->isAngry()){
			$this->networkPropertiesDirty = true;
			$this->onAngerChange($this->isAngry());
		}
	}

	/**
	 * Called when the mob becomes angry or calms down.
	 */
	protected function onAngerChange(bool $angry) : void{

	}

	protected function tickAnger(int $tickDiff) : void{
		if($this->angerTicks > 0){
			$this->angerTicks -= $tickDiff;
			if($this->angerTicks <= 0){
				$this->setAngryAt(null, 0);
			}
		}elseif($this->angerTicks < 0 && $this->getAngerTarget() === null){
			$this->setAngryAt(null, 0);
		}
	}

	/**
	 * Makes the nearby mobs of the given class angry at the target too.
	 *
	 * @phpstan-param class-string<Angerable> $class
	 */
	protected function alertNearby(Living $target, float $range, string $class, int $ticks) : void{
		$loc = $this->location;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $range, $loc->y - 10, $loc->z - $range, $loc->x + $range, $loc->y + 10, $loc->z + $range), $this) as $entity){
			if($entity instanceof $class && $entity !== $target && $entity->isAlive() && !$entity->isAngry()){
				$entity->setAngryAt($target, $ticks);
			}
		}
	}
}
