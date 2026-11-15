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

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\FlyingMobTrait;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\navigation\StraightLineNavigator;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use function floor;
use function mt_rand;

/**
 * Bedrock values (entities/bat.json): 6 health, 0.5x0.9, flies around erratically (behavior.float_wander: a new target
 * every 2-7 ticks, up to 10 blocks away horizontally, 9 down to 5 up: y_dist 7 either way, y_offset -2). Hangs upside
 * down (RESTING) under solid blocks from time to time and wakes up when a player comes within 4 blocks or when hurt.
 * No drops, no XP.
 */
class Bat extends Mob{
	use FlyingMobTrait;

	public const TAG_RESTING = "BatFlags"; //TAG_Byte (vanilla name)

	/** behavior.float_wander float_duration: 0.1-0.35 s between two targets. */
	public const FLOAT_DURATION_MIN = 2;
	public const FLOAT_DURATION_MAX = 7;
	public const FLOAT_XZ_DIST = 10;
	public const FLOAT_Y_DIST = 7;
	public const FLOAT_Y_OFFSET = -2;

	private bool $resting = false;

	public static function getNetworkTypeId() : string{ return EntityIds::BAT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.9, 0.5, 0.45);
	}

	public function getName() : string{
		return "Bat";
	}

	protected function pushesEntities() : bool{
		//bat.json has no pushable component (Java bats are pushed, Bedrock ones are not); fluttering about, they don't push
		//the others either
		return false;
	}

	protected function getFlyingSpeed() : float{
		return 0.15;
	}

	/**
	 * Straight to its targets (navigation.float), without path searches: it picks a new one several times a second.
	 */
	protected function createNavigation() : PathNavigator{
		return new StraightLineNavigator($this);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(6);
		$this->setMovementSpeed(0.1);
		parent::initEntity($nbt);
		$this->initFlying();
		$this->resting = $nbt->getByte(self::TAG_RESTING, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_RESTING, $this->resting ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		//behavior.float_wander: a new random target every 0.1-0.35 s (random_reselect), within xz_dist 10 and y_dist 7
		//around the bat, y_offset -2
		$this->goalSelector->addGoal(1, new class($this) extends Goal{
			private int $repath = 0;

			public function __construct(private Bat $bat){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{ return !$this->bat->isResting(); }

			public function tick() : void{
				if(--$this->repath > 0){
					return;
				}
				$target = $this->bat->findFloatWanderTarget();
				if($target === null){
					$this->repath = 1; //nowhere to go from here: another try on the next tick
					return;
				}
				$this->repath = mt_rand(Bat::FLOAT_DURATION_MIN, Bat::FLOAT_DURATION_MAX);
				$this->bat->getNavigation()->moveTo($target->x, $target->y, $target->z, 1.0);
			}
		});
	}

	/**
	 * A random open block (air, nothing dangerous) for behavior.float_wander, or null if the one picked isn't open.
	 */
	public function findFloatWanderTarget() : ?Vector3{
		$loc = $this->getLiveLocation();
		$x = $loc->x + mt_rand(-self::FLOAT_XZ_DIST, self::FLOAT_XZ_DIST);
		$y = $loc->y + mt_rand(-self::FLOAT_Y_DIST, self::FLOAT_Y_DIST) + self::FLOAT_Y_OFFSET;
		$z = $loc->z + mt_rand(-self::FLOAT_XZ_DIST, self::FLOAT_XZ_DIST);
		$flags = PathBlockTypes::at($this->getWorld(), (int) floor($x), (int) floor($y), (int) floor($z));
		if(($flags & PathBlockTypes::PASSABLE) === 0 || ($flags & (PathBlockTypes::WATER | PathBlockTypes::DANGER)) !== 0){
			return null;
		}
		return new Vector3($x, $y, $z);
	}

	public function isResting() : bool{
		return $this->resting;
	}

	public function setResting(bool $resting) : void{
		if($resting !== $this->resting){
			$this->resting = $resting;
			if($resting){
				$this->getNavigation()->stop();
				$this->motion = Vector3::zero();
			}
			$this->networkPropertiesDirty = true;
		}
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled()){
			$this->setResting(false);
		}
	}

	protected function tickAi(int $currentTick) : void{
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		$above = PathBlockTypes::at($world, $x, (int) floor($this->location->y + $this->size->getHeight() + 0.1), $z);
		if($this->resting){
			if(($above & PathBlockTypes::OPAQUE) === 0 || ($currentTick % 10 === 0 && $this->sensing->getNearestPlayer(4.0, fn(Player $p) => !$p->isSpectator()) !== null)){
				$this->setResting(false);
			}
			return;
		}
		if(($above & PathBlockTypes::OPAQUE) !== 0 && mt_rand(0, 99) === 0){
			$this->setResting(true);
			return;
		}
		parent::tickAi($currentTick);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::RESTING, $this->resting);
	}

	protected function makesFlapVibrations() : bool{
		return !$this->resting;
	}
}
