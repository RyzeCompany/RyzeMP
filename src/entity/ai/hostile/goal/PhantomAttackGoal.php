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

namespace pocketmine\entity\ai\hostile\goal;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Phantom;
use pocketmine\math\Vector3;
use function cos;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Phantom flight (vanilla behavior.circle_around_anchor and behavior.swoop_attack, Java Phantom attack strategy):
 * phantoms circle around an anchor point (20-40 blocks above their target, or above where they spawned) and every
 * 10-20 seconds (Bedrock swoop_attack default delay_range; Java: 8-11 seconds) swoop down at the target, bite it once and climb back. A cat or ocelot within 16 blocks of the phantom
 * (behavior.avoid_mob_type) stops it from swooping: checked when a swoop would start and every second of a swoop.
 */
class PhantomAttackGoal extends Goal{
	public const CIRCLE_SPEED = 0.35;
	public const SWOOP_SPEED = 0.6;
	/** Ticks between swoops (Bedrock behavior.swoop_attack default delay_range 10-20 seconds). */
	public const MIN_SWOOP_DELAY = 200;
	public const MAX_SWOOP_DELAY = 400;

	private bool $swooping = false;
	private int $swoopTicks = 0;
	private int $nextSweepTick = 0;
	private float $angle = 0.0;
	private float $radius = 10.0;
	private float $heightOffset = 0.0;
	private bool $clockwise = false;
	private ?Vector3 $anchor = null;

	public function __construct(
		protected Phantom $phantom
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return true;
	}

	public function canContinue() : bool{
		return true;
	}

	public function isSwooping() : bool{
		return $this->swooping;
	}

	public function start() : void{
		$this->nextSweepTick = 10;
		$this->swooping = false;
		$this->angle = mt_rand(0, 359) / 180 * M_PI;
		$this->radius = mt_rand(5, 15);
		$this->clockwise = mt_rand(0, 1) === 0;
	}

	private function setAnchorAbove(Vector3 $pos) : void{
		$this->anchor = new Vector3($pos->x, $pos->y + 20 + mt_rand(0, 20), $pos->z);
	}

	public function tick() : void{
		$phantom = $this->phantom;
		$target = $phantom->getAttackTarget();
		if($target !== null && !$phantom->getSensing()->isValidTarget($target, 64.0)){
			$target = null;
		}

		if($target === null){
			$this->swooping = false;
			if($this->anchor === null){
				$this->setAnchorAbove($phantom->getLiveLocation()->subtract(0, 20, 0));
			}
		}elseif(!$this->swooping){
			if($this->anchor === null){
				$this->setAnchorAbove($target->getLocation());
			}
			if(--$this->nextSweepTick <= 0){
				if($phantom->isScaredByCats()){
					$this->nextSweepTick = 20; //try again in a second
				}else{
					$this->swooping = true;
					$this->swoopTicks = 0;
					$this->setAnchorAbove($target->getLocation());
					$this->nextSweepTick = mt_rand(self::MIN_SWOOP_DELAY, self::MAX_SWOOP_DELAY);
					$phantom->onSwoop();
				}
			}
		}

		if($this->swooping && $target !== null && ++$this->swoopTicks % 20 === 0 && $phantom->isScaredByCats()){
			$this->swooping = false; //a cat nearby: back to circling
		}

		if($this->swooping && $target !== null){
			$tLoc = Mob::getLiveLocationOf($target) ?? $target->getLocation();
			$phantom->getFlightControl()->setWantedPosition($tLoc->x, $tLoc->y + $target->getSize()->getHeight() * 0.5, $tLoc->z, self::SWOOP_SPEED);
			$phantom->getLookControl()->setLookAtEntity($target, 30.0, 30.0);
			if($phantom->getBoundingBox()->expandedCopy(0.2, 0.2, 0.2)->intersectsWith($target->getBoundingBox())){
				$phantom->attackMelee($target, Phantom::ATTACK_DAMAGE);
				$this->swooping = false;
			}elseif($phantom->isCollidedHorizontally || $phantom->getAiTick() - $phantom->getLastAttackedTick() < 2){
				$this->swooping = false;
			}
			return;
		}

		//circle around the anchor
		if($this->anchor === null){
			return;
		}
		if(mt_rand(0, 349) === 0){
			$this->heightOffset = mt_rand(-4, 5);
		}
		if(mt_rand(0, 249) === 0){
			$this->radius = mt_rand(5, 15);
			$this->clockwise = !$this->clockwise;
		}
		$this->angle += ($this->clockwise ? 1 : -1) * 15 / 180 * M_PI / $this->radius * 2;
		$phantom->getFlightControl()->setWantedPosition(
			$this->anchor->x + cos($this->angle) * $this->radius,
			$this->anchor->y + $this->heightOffset,
			$this->anchor->z + sin($this->angle) * $this->radius,
			self::CIRCLE_SPEED
		);
	}
}
