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
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use function abs;
use function floatval;
use function max;
use function min;
use function sqrt;

class ExperienceOrb extends Entity{

	public static function getNetworkTypeId() : string{ return EntityIds::XP_ORB; }

	public const TAG_VALUE_PC = "Value"; //short
	public const TAG_VALUE_PE = "experience value"; //int (WTF?)
	private const TAG_AGE = "Age"; //TAG_Short

	/** Max distance an orb will follow a player across. */
	public const MAX_TARGET_DISTANCE = 8.0;

	/** Split sizes used for dropping experience orbs. */
	public const ORB_SPLIT_SIZES = [2477, 1237, 617, 307, 149, 73, 37, 17, 7, 3, 1]; //This is indexed biggest to smallest so that we can return as soon as we found the biggest value.

	public const DEFAULT_DESPAWN_DELAY = 6000;
	public const NEVER_DESPAWN = -1;
	public const MAX_DESPAWN_DELAY = 32767 + self::DEFAULT_DESPAWN_DELAY; //max value storable by mojang NBT :(

	/**
	 * Returns the largest size of normal XP orb that will be spawned for the specified amount of XP. Used to split XP
	 * up into multiple orbs when an amount of XP is dropped.
	 */
	public static function getMaxOrbSize(int $amount) : int{
		foreach(self::ORB_SPLIT_SIZES as $split){
			if($amount >= $split){
				return $split;
			}
		}

		return 1;
	}

	/**
	 * Splits the specified amount of XP into an array of acceptable XP orb sizes.
	 *
	 * @return int[]
	 */
	public static function splitIntoOrbSizes(int $amount) : array{
		$result = [];

		while($amount > 0){
			$size = self::getMaxOrbSize($amount);
			$result[] = $size;
			$amount -= $size;
		}

		return $result;
	}

	/** Ticker used for determining interval in which to look for new target players. */
	protected int $lookForTargetTime = 0;

	/** Runtime entity ID of the player this XP orb is targeting. */
	protected ?int $targetPlayerRuntimeId = null;

	protected int $xpValue;

	private int $despawnDelay = self::DEFAULT_DESPAWN_DELAY;

	public function __construct(Location $location, int $xpValue, ?CompoundTag $nbt = null){
		$this->xpValue = $xpValue;
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$age = $nbt->getShort(self::TAG_AGE, 0);
		if($age === -32768){
			$this->despawnDelay = self::NEVER_DESPAWN;
		}else{
			$this->despawnDelay = max(0, self::DEFAULT_DESPAWN_DELAY - $age);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		if($this->despawnDelay === self::NEVER_DESPAWN){
			$age = -32768;
		}else{
			$age = self::DEFAULT_DESPAWN_DELAY - $this->despawnDelay;
		}
		$nbt->setShort(self::TAG_AGE, $age);

		$nbt->setShort(self::TAG_VALUE_PC, $this->getXpValue());
		$nbt->setInt(self::TAG_VALUE_PE, $this->getXpValue());

		return $nbt;
	}

	public function getDespawnDelay() : int{ return $this->despawnDelay; }

	public function setDespawnDelay(int $despawnDelay) : void{
		if(($despawnDelay < 0 || $despawnDelay > self::MAX_DESPAWN_DELAY) && $despawnDelay !== self::NEVER_DESPAWN){
			throw new \InvalidArgumentException("Despawn ticker must be in range 0 ... " . self::MAX_DESPAWN_DELAY . " or " . self::NEVER_DESPAWN . ", got $despawnDelay");
		}
		$this->despawnDelay = $despawnDelay;
	}

	public function getXpValue() : int{
		return $this->xpValue;
	}

	public function setXpValue(int $amount) : void{
		if($amount <= 0){
			throw new \InvalidArgumentException("XP amount must be greater than 0, got $amount");
		}
		$this->xpValue = $amount;
		$this->networkPropertiesDirty = true;
	}

	public function hasTargetPlayer() : bool{
		return $this->targetPlayerRuntimeId !== null;
	}

	public function getTargetPlayer() : ?Human{
		if($this->targetPlayerRuntimeId === null){
			return null;
		}

		$entity = $this->getWorld()->getEntity($this->targetPlayerRuntimeId);
		//TODO: HACK! We really shouldn't be keeping disconnected players (and generally flagged-for-despawn entities)
		//in the world's entity table, but changing that is too risky for a hotfix. This workaround will do for now.
		if($entity instanceof Human && !$entity->isFlaggedForDespawn()){
			return $entity;
		}

		return null;
	}

	public function setTargetPlayer(?Human $player) : void{
		$this->targetPlayerRuntimeId = $player !== null ? $player->getId() : null;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$this->updateWaterDepth();

		$this->despawnDelay -= $tickDiff;
		if($this->despawnDelay <= 0){
			$this->flagForDespawn();
			return true;
		}

		$currentTarget = $this->getTargetPlayer();
		if($currentTarget !== null && (!$currentTarget->isAlive() || !$currentTarget->getXpManager()->canAttractXpOrbs() || $currentTarget->location->distanceSquared($this->location) > self::MAX_TARGET_DISTANCE ** 2)){
			$currentTarget = null;
		}

		if($this->lookForTargetTime >= 20){
			if($currentTarget === null){
				$newTarget = $this->getWorld()->getNearestEntity($this->location, self::MAX_TARGET_DISTANCE, Human::class);

				if($newTarget instanceof Human && !($newTarget instanceof Player && $newTarget->isSpectator()) && $newTarget->getXpManager()->canAttractXpOrbs()){
					$currentTarget = $newTarget;
				}
			}

			$this->lookForTargetTime = 0;
		}else{
			$this->lookForTargetTime += $tickDiff;
		}

		$this->setTargetPlayer($currentTarget);

		if($currentTarget !== null){
			$vector = $currentTarget->getPosition()->add(0, $currentTarget->getEyeHeight() / 2, 0)->subtractVector($this->location)->divide(self::MAX_TARGET_DISTANCE);

			$distance = $vector->lengthSquared();
			if($distance < 1){
				$this->motion = $this->motion->addVector($vector->normalize()->multiply(0.2 * (1 - sqrt($distance)) ** 2));
			}

			if($currentTarget->getXpManager()->canPickupXp() && $this->boundingBox->intersectsWith($currentTarget->getBoundingBox())){
				$this->flagForDespawn();

				$currentTarget->getXpManager()->onPickupXp($this->getXpValue());
			}
		}

		return $hasUpdate;
	}

	/** How deep the orb is in water, and whether its eyes are under water, worked out at the end of each tick. */
	private float $waterDepth = 0.0;
	private bool $floatsUp = false;
	/**
	 * The orb stopped at the surface of the water (see restsOnWaterSurface()) and nothing changed since: its water
	 * depth stays the same until its next movement update.
	 */
	private bool $restingOnWater = false;

	/**
	 * Called each tick after the block intersection check: works out how the orb floats during the next tick.
	 */
	private function updateWaterDepth() : void{
		if($this->restingOnWater){
			return;
		}
		if($this->blocksAround === []){
			//nothing with entity actions around (the usual case): no water
			$this->waterDepth = 0.0;
			$this->floatsUp = false;
			return;
		}
		$this->waterDepth = $depth = $this->getBoundingBoxLiquidDepth();
		$this->floatsUp = $depth > 0.0 && $depth > $this->getEyeHeight();
		if($this->floatsUp){
			//an orb lying at the bottom of a pool floats up (a movement update here instead of an override of
			//hasMovementUpdate(), which is called several times a tick)
			$this->forceMovementUpdate = true;
		}elseif($depth > 0.0 && $this->onGround && floatval($this->motion->x) === 0.0 && floatval($this->motion->y) === 0.0 && floatval($this->motion->z) === 0.0){
			$this->restingOnWater = true;
		}
	}

	protected function tryChangeMovement() : void{
		$this->restingOnWater = false;
		$this->checkObstruction($this->location->x, $this->location->y, $this->location->z);

		//vanilla ExperienceOrb.tick(): orbs whose eyes are in water float up instead of falling
		if(!$this->floatsUp){
			if($this->waterDepth > 0.0 && $this->targetPlayerRuntimeId === null && $this->restsOnWaterSurface()){
				return;
			}
			parent::tryChangeMovement();
			return;
		}
		$friction = 1 - $this->drag;
		$this->motion = new Vector3(
			$this->motion->x * self::WATER_FLOAT_HORIZONTAL_KEPT * $friction,
			min($this->motion->y + self::FLOAT_RISE_ACCELERATION, self::FLOAT_MAX_RISE_SPEED) * $friction,
			$this->motion->z * self::WATER_FLOAT_HORIZONTAL_KEPT * $friction
		);
	}

	/** Vertical speed gained each tick while floating up, up to FLOAT_MAX_RISE_SPEED (vanilla setUnderwaterMovement()). */
	private const FLOAT_RISE_ACCELERATION = 0.0005;
	private const FLOAT_MAX_RISE_SPEED = 0.06;
	/** Horizontal speed kept each tick while floating in water (vanilla setUnderwaterMovement()). */
	private const WATER_FLOAT_HORIZONTAL_KEPT = 0.99;
	/** Below this speed an orb that reached the surface of still water stops there, see restsOnWaterSurface(). */
	private const FLOAT_REST_MAX_SPEED = 0.05;

	/**
	 * Called when the orb touches water at the surface (its eyes out of the water): an orb that floated up slowly
	 * stops there instead of bobbing up and down every tick, like {@link ItemEntity}.
	 */
	private function restsOnWaterSurface() : bool{
		$motion = $this->motion;
		if(abs($motion->y) >= self::FLOAT_REST_MAX_SPEED || $motion->x * $motion->x + $motion->z * $motion->z > 1.0e-4){
			return false;
		}
		$this->motion = Vector3::zero();
		$this->onGround = true;
		return true;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setInt(EntityMetadataProperties::EXPERIENCE_VALUE, $this->xpValue);
	}
}
