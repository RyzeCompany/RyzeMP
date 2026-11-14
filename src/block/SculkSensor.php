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

namespace pocketmine\block;

use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\world\sound\SculkSensorPowerOffSound;
use pocketmine\world\sound\SculkSensorPowerOnSound;
use pocketmine\world\vibration\GameEvent;
use function abs;

/**
 * Detects vibrations (see {@link \pocketmine\world\vibration\VibrationManager}) and outputs a redstone signal which
 * gets weaker with the distance of the vibration. Phases: inactive, active (outputting), cooldown (deaf).
 *
 * Bedrock has no block state for the output power, so it lives in the world's vibration manager while the sensor is
 * active.
 */
class SculkSensor extends Transparent implements ComparatorReadable{
	public const PHASE_INACTIVE = 0;
	public const PHASE_ACTIVE = 1;
	public const PHASE_COOLDOWN = 2;

	public const RANGE = 8;
	public const ACTIVE_TICKS = 30;
	public const COOLDOWN_TICKS = 10;

	protected int $phase = self::PHASE_INACTIVE;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::PHASE_INACTIVE, self::PHASE_COOLDOWN, $this->phase);
	}

	public function getPhase() : int{ return $this->phase; }

	/** @return $this */
	public function setPhase(int $phase) : self{
		if($phase < self::PHASE_INACTIVE || $phase > self::PHASE_COOLDOWN){
			throw new \InvalidArgumentException("Phase must be in range " . self::PHASE_INACTIVE . " ... " . self::PHASE_COOLDOWN);
		}
		$this->phase = $phase;
		return $this;
	}

	public function isActive() : bool{ return $this->phase === self::PHASE_ACTIVE; }

	/**
	 * Returns the maximum distance (in blocks) of the vibrations this sensor can detect.
	 */
	public function getRange() : int{
		return self::RANGE;
	}

	protected function getActiveTicks() : int{
		return self::ACTIVE_TICKS;
	}

	/**
	 * Returns whether this sensor can start listening to a vibration of the given frequency right now.
	 */
	public function canDetect(int $frequency) : bool{
		return $this->phase === self::PHASE_INACTIVE;
	}

	public function getLightLevel() : int{
		return 1;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 0.5)];
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::DOWN ? SupportType::FULL : SupportType::NONE;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		//vanilla: the sensor only drops itself with Silk Touch, otherwise experience
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getXpDropAmount() : int{
		return 5;
	}

	/**
	 * @return int[]|null [power, frequency, phase end tick]
	 * @phpstan-return array{int, int, int}|null
	 */
	private function getRuntimeState() : ?array{
		return $this->position->getWorld()->getVibrations()->getSensorState($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	/**
	 * Returns the redstone power this sensor outputs (0 unless active).
	 */
	public function getOutputPower() : int{
		return $this->phase === self::PHASE_ACTIVE ? ($this->getRuntimeState()[0] ?? 0) : 0;
	}

	public function getComparatorOutput() : int{
		return $this->phase === self::PHASE_ACTIVE ? ($this->getRuntimeState()[1] ?? 0) : 0;
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		return $this->getOutputPower();
	}

	public function getStrongPower(int $face) : int{
		return $face === Facing::DOWN ? $this->getOutputPower() : 0;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		//stepping on a sensor always activates it at full power, even when sneaking
		if($this->phase === self::PHASE_INACTIVE && $entity->isOnGround() && abs($entity->getBoundingBox()->minY - ($this->position->y + 0.5)) < 0.01){
			$this->activate(15, GameEvent::STEP->getFrequency(), $entity->getId());
		}
		return true;
	}

	/**
	 * Starts the active phase with the given output. The sculk shriekers within 8 blocks hear the sensor if a player
	 * caused the vibration.
	 *
	 * @param int|null $sourceEntityId ID of the entity which caused the vibration, if any
	 */
	public function activate(int $power, int $frequency, ?int $sourceEntityId = null) : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$activeTicks = $this->getActiveTicks();
		$world->getVibrations()->clearPendingVibration($x, $y, $z);
		$world->getVibrations()->setSensorState($x, $y, $z, $power, $frequency, $world->getServer()->getTick() + $activeTicks);
		$this->phase = self::PHASE_ACTIVE;
		$world->setBlock($this->position, $this);
		$world->scheduleDelayedBlockUpdate($this->position, $activeTicks);
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new SculkSensorPowerOnSound());
		$this->notifyOutputChange();
		$source = $sourceEntityId !== null ? $world->getEntity($sourceEntityId) : null;
		$this->resonate($frequency, $source);
		if($sourceEntityId !== null){
			$world->getVibrations()->onSensorActivated($x, $y, $z, $source);
		}
	}

	/**
	 * Makes the amethyst blocks touching this sensor repeat the detected frequency (Java
	 * SculkSensorBlock.tryResonateVibration()), so that calibrated sensors further away can pick it up.
	 */
	private function resonate(int $frequency, ?Entity $source) : void{
		if($frequency < 1 || $frequency > 15){
			return;
		}
		$world = $this->position->getWorld();
		$event = null;
		foreach(Facing::ALL as $face){
			$side = $this->getSide($face);
			if($side->getTypeId() === BlockTypeIds::AMETHYST){
				$world->addGameEvent($side->getPosition(), $event ??= GameEvent::resonance($frequency), $source);
			}
		}
	}

	private function notifyOutputChange() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone === null){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$redstone->updateAround($x, $y, $z);
		$redstone->updateAround($x, $y - 1, $z); //strongly powered block below
		$redstone->updateComparatorsReading($x, $y, $z);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$vibrations = $world->getVibrations();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$now = $world->getServer()->getTick();

		if($this->phase === self::PHASE_INACTIVE){
			$vibration = $vibrations->takeArrivedVibration($x, $y, $z);
			if($vibration !== null){
				[$power, $frequency, $sourceId] = $vibration;
				$this->activate($power, $frequency, $sourceId);
			}
			return;
		}

		$state = $vibrations->getSensorState($x, $y, $z);
		if($state !== null && $state[2] > $now){
			//stale update (from a vibration replaced by a closer one): the phase isn't over yet
			$world->scheduleDelayedBlockUpdate($this->position, $state[2] - $now);
			return;
		}
		if($this->phase === self::PHASE_ACTIVE){
			$vibrations->setSensorState($x, $y, $z, 0, 0, $now + self::COOLDOWN_TICKS);
			$this->phase = self::PHASE_COOLDOWN;
			$world->setBlock($this->position, $this);
			$world->scheduleDelayedBlockUpdate($this->position, self::COOLDOWN_TICKS);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new SculkSensorPowerOffSound());
			$this->notifyOutputChange();
		}else{
			$vibrations->clearSensorState($x, $y, $z);
			$this->phase = self::PHASE_INACTIVE;
			$world->setBlock($this->position, $this);
		}
	}

	public function onNearbyBlockChange() : void{
		parent::onNearbyBlockChange();
		//scheduled updates are not saved: a sensor reloaded while active or cooling down must still go back to rest
		if($this->phase !== self::PHASE_INACTIVE){
			$world = $this->position->getWorld();
			if(!$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
				$world->scheduleDelayedBlockUpdate($this->position, 1);
			}
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$wasActive = $this->getOutputPower() > 0;
		$result = parent::onBreak($item, $player, $returnedItems);
		if($wasActive){
			$this->notifyOutputChange();
		}
		return $result;
	}
}
