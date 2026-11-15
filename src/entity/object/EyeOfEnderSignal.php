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

namespace pocketmine\entity\object;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use function atan2;
use function cos;
use function mt_rand;
use function sin;
use function sqrt;

/**
 * A thrown eye of ender (vanilla EyeOfEnder): flies towards the stronghold (at most 12 blocks horizontally, rising 8
 * blocks when it is farther away) for 4 seconds, then drops as an item (80%) or shatters.
 */
class EyeOfEnderSignal extends Entity implements NeverSavedWithChunkEntity{
	public const LIFETIME = 80;
	private const MAX_STEP = 12.0;

	private Vector3 $target;
	private int $life = 0;
	private bool $survives;

	public static function getNetworkTypeId() : string{ return EntityIds::EYE_OF_ENDER_SIGNAL; }

	public function __construct(Location $location, Vector3 $stronghold){
		parent::__construct($location);
		$dx = $stronghold->x - $location->x;
		$dz = $stronghold->z - $location->z;
		$distance = sqrt($dx * $dx + $dz * $dz);
		$this->target = $distance > self::MAX_STEP ?
			new Vector3($location->x + $dx / $distance * self::MAX_STEP, $location->y + 8, $location->z + $dz / $distance * self::MAX_STEP) :
			new Vector3($stronghold->x, $stronghold->y, $stronghold->z);
		$this->survives = mt_rand(0, 4) > 0;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function getTarget() : Vector3{
		return $this->target;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || $this->isFlaggedForDespawn()){
			return false;
		}
		$this->life += $tickDiff;
		if($this->life > self::LIFETIME){
			$world = $this->getWorld();
			if($this->survives){
				$world->dropItem($this->location, VanillaItems::ENDER_EYE());
			}else{
				$world->broadcastPacketToViewers($this->location, LevelEventPacket::create(LevelEvent::PARTICLE_EYE_DESPAWN, 0, $this->location->asVector3()));
			}
			$this->flagForDespawn();
			return false;
		}

		//vanilla EyeOfEnder.tick(): turn towards the target, slowly speeding up, and rise or sink towards its height
		$dx = $this->target->x - $this->location->x;
		$dz = $this->target->z - $this->location->z;
		$horizontal = sqrt($dx * $dx + $dz * $dz);
		$angle = atan2($dz, $dx);
		$speed = sqrt($this->motion->x ** 2 + $this->motion->z ** 2);
		$speed += ($horizontal - $speed) * 0.0025;
		$vertical = $this->motion->y;
		if($horizontal < 1){
			$speed *= 0.8;
			$vertical *= 0.8;
		}
		$direction = $this->location->y < $this->target->y ? 1 : -1;
		$this->motion = new Vector3(cos($angle) * $speed, $vertical + ($direction - $vertical) * 0.015, sin($angle) * $speed);
		return true;
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		//flies through blocks
		$this->setPosition($this->location->add($dx, $dy, $dz));
	}

	public function hasMovementUpdate() : bool{
		return true;
	}
}
