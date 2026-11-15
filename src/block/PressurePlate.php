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

use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\entity\Entity;
use pocketmine\event\block\PressurePlateUpdateEvent;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\world\sound\PressurePlateActivateSound;
use pocketmine\world\sound\PressurePlateDeactivateSound;
use pocketmine\world\vibration\GameEvent;
use function count;

abstract class PressurePlate extends Transparent{
	use StaticSupportTrait;

	private readonly int $deactivationDelayTicks;

	public function __construct(
		BlockIdentifier $idInfo,
		string $name,
		BlockTypeInfo $typeInfo,
		int $deactivationDelayTicks = 20 //TODO: make this mandatory in PM6
	){
		$this->deactivationDelayTicks = $deactivationDelayTicks;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$this->hasOutputSignal() && !self::ignoresPlates($entity)){
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 0);
		}
		return true;
	}

	/**
	 * Returns the AABB that entities must intersect to activate the pressure plate.
	 * Note that this is not the same as the collision box (pressure plate doesn't have one), nor the visual bounding
	 * box. The activation area has a height of 0.25 blocks.
	 */
	protected function getActivationBox() : AxisAlignedBB{
		return AxisAlignedBB::one()
			->squash(Axis::X, 1 / 8)
			->squash(Axis::Z, 1 / 8)
			->trim(Facing::UP, 3 / 4)
			->offset($this->position->x, $this->position->y, $this->position->z);
	}

	/**
	 * TODO: make this abstract in PM6
	 */
	protected function hasOutputSignal() : bool{
		return false;
	}

	/**
	 * Returns the redstone power emitted by the plate in its current state.
	 */
	protected function getOutputPower() : int{
		return $this->hasOutputSignal() ? 15 : 0;
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

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if($this->hasOutputSignal()){
			$this->position->getWorld()->getRedstone()?->updateAroundSide($this->position, Facing::DOWN);
		}
		return $result;
	}

	/**
	 * TODO: make this abstract in PM6
	 *
	 * @param Entity[] $entities
	 *
	 * @return mixed[]
	 * @phpstan-return array{Block, ?bool}
	 */
	protected function calculatePlateState(array $entities) : array{
		return [$this, null];
	}

	/**
	 * Filters entities which don't affect the pressure plate state from the given list.
	 *
	 * @param Entity[] $entities
	 * @return Entity[]
	 */
	protected function filterIrrelevantEntities(array $entities) : array{
		return $entities;
	}

	/**
	 * Spectators don't press plates, and don't count for weighted plates (vanilla).
	 */
	private static function ignoresPlates(Entity $entity) : bool{
		return $entity instanceof Player && $entity->isSpectator();
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();

		$intersectionAABB = $this->getActivationBox();
		$activatingEntities = [];
		foreach($this->filterIrrelevantEntities($world->getNearbyEntities($intersectionAABB)) as $entity){
			if(!self::ignoresPlates($entity)){
				$activatingEntities[] = $entity;
			}
		}

		//if an irrelevant entity is inside the full cube space of the pressure plate but not activating the plate,
		//it will cause scheduled updates on the plate every tick. We don't want to fire events in this case if the
		//plate is already deactivated.
		if(count($activatingEntities) > 0 || $this->hasOutputSignal()){
			[$newState, $pressedChange] = $this->calculatePlateState($activatingEntities);

			//always call this, in case there are new entities on the plate
			if(PressurePlateUpdateEvent::hasHandlers()){
				$ev = new PressurePlateUpdateEvent($this, $newState, $activatingEntities);
				$ev->call();
				$newState = $ev->isCancelled() ? null : $ev->getNewState();
			}
			if($newState !== null){
				$world->setBlock($this->position, $newState);
				$world->getRedstone()?->updateAroundSide($this->position, Facing::DOWN);
				if($pressedChange !== null){
					$world->addSound($this->position, $pressedChange ?
						new PressurePlateActivateSound($this) :
						new PressurePlateDeactivateSound($this)
					);
					$world->addGameEvent($this->position, $pressedChange ? GameEvent::BLOCK_ACTIVATE : GameEvent::BLOCK_DEACTIVATE);
				}
			}
			if($pressedChange ?? $this->hasOutputSignal()){
				$world->scheduleDelayedBlockUpdate($this->position, $this->deactivationDelayTicks);
			}
		}
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
