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

use pocketmine\block\dispenser\DispenseBehaviorRegistry;
use pocketmine\block\dispenser\DispenseContext;
use pocketmine\block\tile\Dispenser as TileDispenser;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\ClickFailSound;
use pocketmine\world\vibration\GameEvent;
use function abs;
use function array_keys;
use function count;
use function mt_rand;

/**
 * Dispenser: on a rising redstone edge, waits {@link Dispenser::DISPENSE_DELAY_TICKS} game ticks, then uses a random
 * non-empty slot (Minecraft Wiki "Dispenser"). What happens to the item is decided by
 * {@link DispenseBehaviorRegistry}.
 */
class Dispenser extends Opaque implements AnyFacing{
	use AnyFacingTrait;

	/** 2 redstone ticks */
	public const DISPENSE_DELAY_TICKS = 4;

	protected bool $triggered = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->triggered);
	}

	/**
	 * Whether the dispenser is powered (Bedrock "triggered_bit"). Only a rising edge makes it dispense.
	 */
	public function isTriggered() : bool{ return $this->triggered; }

	/** @return $this */
	public function setTriggered(bool $triggered) : self{
		$this->triggered = $triggered;
		return $this;
	}

	/**
	 * Facing of a dispenser-like block placed by the given player: towards the player, or up/down when the player
	 * stands close and above/below it (same rule as barrels).
	 */
	public static function getPlacementFacing(Vector3 $blockPos, Player $player) : int{
		$playerPos = $player->getPosition();
		if(abs($playerPos->x - $blockPos->x) < 2 && abs($playerPos->z - $blockPos->z) < 2){
			$y = $player->getEyePos()->y;
			if($y - $blockPos->y > 2){
				return Facing::UP;
			}
			if($blockPos->y - $y > 0){
				return Facing::DOWN;
			}
		}
		return Facing::opposite($player->getHorizontalFacing());
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = self::getPlacementFacing($this->position, $player);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$tile = $this->position->getWorld()->getTile($this->position);
			if($tile instanceof TileDispenser && $tile->canOpenWith($item->getCustomName())){
				$tile->unpackLootTable($player);
				$player->setCurrentWindow($tile->getInventory());
			}
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$powered = $world->isBlockPowered($this->position);
		if($powered && !$this->triggered){
			$this->triggered = true;
			$world->setBlock($this->position, $this);
			$tile = $world->getTile($this->position);
			if($tile instanceof TileDispenser && $tile->getPendingDispenseTick() < 0){
				$tile->setPendingDispenseTick($world->getServer()->getTick() + self::DISPENSE_DELAY_TICKS);
			}
			if(!$world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
				$world->scheduleDelayedBlockUpdate($this->position, self::DISPENSE_DELAY_TICKS);
			}
		}elseif(!$powered && $this->triggered){
			$this->triggered = false;
			$world->setBlock($this->position, $this);
		}
	}

	public function onScheduledUpdate() : void{
		//block updates are also scheduled when the tile is added to the world (chunk load, generation): dispense only
		//when a redstone rising edge asked for it
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileDispenser){
			return;
		}
		$due = $tile->getPendingDispenseTick();
		if($due < 0){
			return;
		}
		$now = $world->getServer()->getTick();
		if($now < $due){
			//an earlier update (e.g. the tile's) came first: wait for the dispense delay
			$world->scheduleDelayedBlockUpdate($this->position, $due - $now);
			return;
		}
		$tile->setPendingDispenseTick(-1);
		$this->dispense();
	}

	/**
	 * Dispenses one item from a random non-empty slot. The slot is chosen now, not when the dispenser was powered.
	 */
	public function dispense() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileDispenser){
			return;
		}
		$inventory = $tile->getInventory();
		$slots = array_keys($inventory->getContents());
		if(count($slots) === 0){
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new ClickFailSound());
			//only an empty dispenser or dropper vibrates (Java DispenserBlock.dispenseFrom(), checked on BDS 1.26.52)
			$world->addGameEvent($this->position, GameEvent::BLOCK_ACTIVATE);
			return;
		}
		$slot = $slots[mt_rand(0, count($slots) - 1)];
		$context = new DispenseContext($world, $this->position, $this->facing, $inventory);
		$result = $this->dispenseItem($context, $inventory->getItem($slot));
		if($result === null){
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new ClickFailSound());
			return;
		}
		$inventory->setItem($slot, $result);
	}

	/**
	 * Returns the new contents of the slot, or null if nothing happened.
	 */
	protected function dispenseItem(DispenseContext $context, Item $stack) : ?Item{
		return DispenseBehaviorRegistry::getInstance()->get($stack)->dispense($context, $stack);
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //redstone components only update in ticking chunks, like vanilla block ticks
	}
}
