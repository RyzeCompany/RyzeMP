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

use pocketmine\block\inventory\CommandBlockInventory;
use pocketmine\block\tile\CommandBlock as TileCommandBlock;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\CommandBlockType;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function max;

/**
 * Impulse, repeating and chain command blocks (https://minecraft.wiki/w/Command_Block). The command and the settings
 * live in the block entity ({@link TileCommandBlock}); the block state holds the facing and the conditional flag.
 *
 * - Impulse: runs once, TickDelay ticks after a redstone signal turns on (or after "Always Active" is turned on).
 * - Repeating: runs every TickDelay ticks (at least 1) while powered or always active; with ExecuteOnFirstTick it
 *   runs at once when it becomes active.
 * - Chain: runs right after the command block pointing into it ran (after its own TickDelay if it has one), when it
 *   is powered or always active. The chain stops after maxcommandchainlength chain blocks.
 *
 * A conditional command block only runs if the command block behind it (opposite to its facing) succeeded on its
 * last run. Nothing runs when the commandblocksenabled game rule is off. Only operators in creative mode can place
 * command blocks or open their screen; they never drop.
 */
class CommandBlock extends Opaque implements AnyFacing{
	use AnyFacingTrait;

	/**
	 * Most command block runs (impulse, repeating and chain) per world and tick: the others wait for the next tick, so
	 * that a contraption of repeating command blocks can't stall the server.
	 */
	public const MAX_RUNS_PER_TICK = 4096;

	protected bool $conditional = false;

	/** @phpstan-var \WeakMap<World, array{int, int}>|null world => [tick, runs] */
	private static ?\WeakMap $runsThisTick = null;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private CommandBlockType $commandBlockType){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->conditional);
	}

	public function getCommandBlockType() : CommandBlockType{ return $this->commandBlockType; }

	/**
	 * Whether it only runs when the command block behind it succeeded (Bedrock "conditional_bit").
	 */
	public function isConditional() : bool{ return $this->conditional; }

	/** @return $this */
	public function setConditional(bool $conditional) : self{
		$this->conditional = $conditional;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if(!GameMasterBlocks::canUse($player)){
				return false;
			}
			$this->facing = Dispenser::getPlacementFacing($this->position, $player);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function writeStateToWorld() : void{
		$world = $this->position->getWorld();
		$isNew = !$world->getTile($this->position) instanceof TileCommandBlock;
		parent::writeStateToWorld();
		if($isNew){
			//BDS defaults: chain command blocks are always active, repeating ones run on their first tick
			$tile = $world->getTile($this->position);
			if($tile instanceof TileCommandBlock){
				$tile->setAuto($this->commandBlockType === CommandBlockType::CHAIN);
				$tile->setExecuteOnFirstTick($this->commandBlockType === CommandBlockType::REPEATING);
			}
		}
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null || !GameMasterBlocks::canUse($player)){
			return false;
		}
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if($tile instanceof TileCommandBlock){
			if($tile->isDirty()){
				//the screen shows the last output and the settings from the block entity data the client has
				$world->setBlock($this->position, $this, false);
			}
			$player->setCurrentWindow(new CommandBlockInventory($this->position));
		}
		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileCommandBlock){
			return;
		}
		$powered = $world->isBlockPowered($this->position);
		if($powered === $tile->isPowered()){
			return;
		}
		$tile->setPowered($powered);
		if($powered && !$tile->isAuto()){
			$this->activate($tile);
		}
	}

	/**
	 * Called when the block becomes active (redstone signal turning on, or "Always Active" turned on): an impulse
	 * command block runs after its delay, a repeating one starts running. Chain command blocks wait for their chain.
	 *
	 * @internal
	 */
	public function activate(TileCommandBlock $tile) : void{
		$world = $this->position->getWorld();
		switch($this->commandBlockType){
			case CommandBlockType::IMPULSE:
				$tile->setPendingRun(true);
				$world->scheduleDelayedBlockUpdate($this->position, max(1, $tile->getTickDelay()));
				break;
			case CommandBlockType::REPEATING:
				$delay = $tile->isExecuteOnFirstTick() ? 1 : max(1, $tile->getTickDelay());
				$tile->setRepeatDueTick($world->getServer()->getTick() + $delay);
				$world->scheduleDelayedBlockUpdate($this->position, $delay);
				break;
			case CommandBlockType::CHAIN:
				break;
		}
	}

	/**
	 * Scheduled update of a repeating command block. Updates scheduled before it was turned off and on again may still
	 * come: only the one due at the recorded tick runs, the others reschedule for that tick (in case the request for
	 * it was merged into theirs) unless a run already rescheduled during this tick, so one loop is left.
	 */
	private function onRepeatingUpdate(World $world, TileCommandBlock $tile) : void{
		$now = $world->getServer()->getTick();
		$due = $tile->getRepeatDueTick();
		if($now < $due){
			if($tile->getRepeatRescheduledTick() !== $now){
				$world->scheduleDelayedBlockUpdate($this->position, $due - $now);
			}
			return;
		}
		if(self::takeRun($world)){
			$this->runAndContinueChain($tile);
		}
		$delay = max(1, $tile->getTickDelay());
		$tile->setRepeatDueTick($now + $delay);
		$tile->setRepeatRescheduledTick($now);
		$world->scheduleDelayedBlockUpdate($this->position, $delay);
	}

	public function onScheduledUpdate() : void{
		//block updates are also scheduled when the block entity is added to the world (chunk load, placement)
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileCommandBlock){
			return;
		}
		switch($this->commandBlockType){
			case CommandBlockType::IMPULSE:
			case CommandBlockType::CHAIN:
				if(!$tile->hasPendingRun()){
					return;
				}
				if(!self::takeRun($world)){
					$world->scheduleDelayedBlockUpdate($this->position, 1);
					return;
				}
				$tile->setPendingRun(false);
				if($this->commandBlockType === CommandBlockType::CHAIN && !$tile->isActive()){
					return;
				}
				$this->runAndContinueChain($tile);
				break;
			case CommandBlockType::REPEATING:
				if($tile->isActive()){
					$this->onRepeatingUpdate($world, $tile);
				}
				break;
		}
	}

	/**
	 * Counts a run against {@link self::MAX_RUNS_PER_TICK}. Returns false if the world has no runs left this tick.
	 */
	private static function takeRun(World $world) : bool{
		self::$runsThisTick ??= new \WeakMap();
		$tick = $world->getServer()->getTick();
		[$lastTick, $runs] = self::$runsThisTick[$world] ?? [-1, 0];
		if($lastTick !== $tick){
			$runs = 0;
		}
		if($runs >= self::MAX_RUNS_PER_TICK){
			return false;
		}
		self::$runsThisTick[$world] = [$tick, $runs + 1];
		return true;
	}

	/**
	 * Runs this command block (if its condition is met), then the chain command blocks it points into.
	 */
	private function runAndContinueChain(TileCommandBlock $tile) : void{
		$world = $this->position->getWorld();
		$this->runIfConditionMet($tile);

		$maxChain = (int) $world->getGameRule(GameRules::MAX_COMMAND_CHAIN_LENGTH);
		$current = $this;
		for($length = 0; $length < $maxChain; ++$length){
			$next = $world->getBlock($current->position->getSide($current->facing));
			if(!$next instanceof CommandBlock || $next->commandBlockType !== CommandBlockType::CHAIN){
				return;
			}
			$nextTile = $world->getTile($next->position);
			if(!$nextTile instanceof TileCommandBlock || !$nextTile->isActive()){
				return;
			}
			if($nextTile->getTickDelay() > 0){
				//the chain goes on from there after its delay
				$nextTile->setPendingRun(true);
				$world->scheduleDelayedBlockUpdate($next->position, $nextTile->getTickDelay());
				return;
			}
			if(!self::takeRun($world)){
				return;
			}
			$next->runIfConditionMet($nextTile);
			$current = $next;
		}
	}

	/**
	 * Runs the command, unless the block is conditional and the command block behind it didn't succeed.
	 */
	private function runIfConditionMet(TileCommandBlock $tile) : void{
		if($this->conditional){
			$behind = $this->position->getWorld()->getTile($this->position->getSide(Facing::opposite($this->facing)));
			$met = $behind instanceof TileCommandBlock && $behind->getSuccessCount() > 0;
			$tile->setConditionMet($met);
			if(!$met){
				$tile->setSuccessCount(0);
				return;
			}
		}
		$tile->runCommand();
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function pausesScheduledUpdatesOutsideTickingChunks() : bool{
		return true; //like other redstone components, command blocks only run in ticking chunks
	}
}
