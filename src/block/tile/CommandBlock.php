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

namespace pocketmine\block\tile;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\GameRules;
use function mb_substr;
use function str_starts_with;
use function substr;

/**
 * Block entity of command blocks (Bedrock ID "CommandBlock"; same fields and defaults as BDS 1.26.52, Version 52).
 * It holds the command and its settings, runs the command as a {@link CommandBlockSender} and remembers whether the
 * last run succeeded. When it runs is decided by the block ({@link \pocketmine\block\CommandBlock}).
 */
class CommandBlock extends Spawnable{
	public const TAG_COMMAND = "Command"; //TAG_String
	public const TAG_CUSTOM_NAME = "CustomName"; //TAG_String
	public const TAG_EXECUTE_ON_FIRST_TICK = "ExecuteOnFirstTick"; //TAG_Byte
	public const TAG_LP_COMMAND_MODE = "LPCommandMode"; //TAG_Int
	public const TAG_LP_CONDITIONAL_MODE = "LPCondionalMode"; //TAG_Byte (sic, as Bedrock spells it)
	public const TAG_LP_REDSTONE_MODE = "LPRedstoneMode"; //TAG_Byte
	public const TAG_LAST_EXECUTION = "LastExecution"; //TAG_Long
	public const TAG_LAST_OUTPUT = "LastOutput"; //TAG_String
	public const TAG_LAST_OUTPUT_PARAMS = "LastOutputParams"; //TAG_List<TAG_String>
	public const TAG_SUCCESS_COUNT = "SuccessCount"; //TAG_Int
	public const TAG_TICK_DELAY = "TickDelay"; //TAG_Int
	public const TAG_TRACK_OUTPUT = "TrackOutput"; //TAG_Byte
	public const TAG_VERSION = "Version"; //TAG_Int
	public const TAG_AUTO = "auto"; //TAG_Byte
	public const TAG_CONDITION_MET = "conditionMet"; //TAG_Byte
	public const TAG_CONDITIONAL_MODE = "conditionalMode"; //TAG_Byte
	public const TAG_POWERED = "powered"; //TAG_Byte

	/** Command block data version written by BDS 1.26.52 */
	public const VERSION = 52;
	public const MAX_COMMAND_LENGTH = 32767;
	public const MAX_OUTPUT_LENGTH = 1024;

	private string $command = "";
	private string $customName = "";
	private string $lastOutput = "";
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $lastOutputParams = [];
	private bool $trackOutput = true;
	private int $successCount = 0;
	private bool $auto = false;
	private bool $powered = false;
	private bool $conditionMet = false;
	private int $tickDelay = 0;
	private bool $executeOnFirstTick = false;
	private int $lastExecution = 0;
	private int $lpCommandMode = 0;
	private bool $lpConditionalMode = false;
	private bool $lpRedstoneMode = false;
	private int $version = self::VERSION;

	/** An impulse or delayed chain run waiting for its scheduled update (not saved, like vanilla) */
	private bool $pendingRun = false;
	/** Server tick of the next run of a repeating command block (not saved: after loading it runs at once) */
	private int $repeatDueTick = -1;
	/** Server tick when a scheduled update of the repeating command block ran (or skipped) a run and rescheduled */
	private int $repeatRescheduledTick = -1;
	private ?CommandBlockSender $sender = null;

	public function readSaveData(CompoundTag $nbt) : void{
		$this->command = $nbt->getString(self::TAG_COMMAND, "");
		$this->customName = $nbt->getString(self::TAG_CUSTOM_NAME, "");
		$this->lastOutput = $nbt->getString(self::TAG_LAST_OUTPUT, "");
		$this->lastOutputParams = [];
		$params = $nbt->getListTag(self::TAG_LAST_OUTPUT_PARAMS, StringTag::class);
		if($params !== null){
			foreach($params as $param){
				$this->lastOutputParams[] = $param->getValue();
			}
		}
		$this->trackOutput = $nbt->getByte(self::TAG_TRACK_OUTPUT, 1) !== 0;
		$this->successCount = $nbt->getInt(self::TAG_SUCCESS_COUNT, 0);
		$this->auto = $nbt->getByte(self::TAG_AUTO, 0) !== 0;
		$this->powered = $nbt->getByte(self::TAG_POWERED, 0) !== 0;
		$this->conditionMet = $nbt->getByte(self::TAG_CONDITION_MET, 0) !== 0;
		$this->tickDelay = $nbt->getInt(self::TAG_TICK_DELAY, 0);
		$this->executeOnFirstTick = $nbt->getByte(self::TAG_EXECUTE_ON_FIRST_TICK, 0) !== 0;
		$this->lastExecution = $nbt->getLong(self::TAG_LAST_EXECUTION, 0);
		$this->lpCommandMode = $nbt->getInt(self::TAG_LP_COMMAND_MODE, 0);
		$this->lpConditionalMode = $nbt->getByte(self::TAG_LP_CONDITIONAL_MODE, 0) !== 0;
		$this->lpRedstoneMode = $nbt->getByte(self::TAG_LP_REDSTONE_MODE, 0) !== 0;
		$this->version = $nbt->getInt(self::TAG_VERSION, self::VERSION);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->writeCommandBlockData($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		//the command block screen reads its fields from the block entity data
		$this->writeCommandBlockData($nbt);
	}

	private function writeCommandBlockData(CompoundTag $nbt) : void{
		$nbt->setString(self::TAG_COMMAND, $this->command);
		$nbt->setString(self::TAG_CUSTOM_NAME, $this->customName);
		$nbt->setByte(self::TAG_EXECUTE_ON_FIRST_TICK, $this->executeOnFirstTick ? 1 : 0);
		$nbt->setInt(self::TAG_LP_COMMAND_MODE, $this->lpCommandMode);
		$nbt->setByte(self::TAG_LP_CONDITIONAL_MODE, $this->lpConditionalMode ? 1 : 0);
		$nbt->setByte(self::TAG_LP_REDSTONE_MODE, $this->lpRedstoneMode ? 1 : 0);
		$nbt->setLong(self::TAG_LAST_EXECUTION, $this->lastExecution);
		$nbt->setString(self::TAG_LAST_OUTPUT, $this->lastOutput);
		$params = new ListTag([], NBT::TAG_String);
		foreach($this->lastOutputParams as $param){
			$params->push(new StringTag($param));
		}
		$nbt->setTag(self::TAG_LAST_OUTPUT_PARAMS, $params);
		$nbt->setInt(self::TAG_SUCCESS_COUNT, $this->successCount);
		$nbt->setInt(self::TAG_TICK_DELAY, $this->tickDelay);
		$nbt->setByte(self::TAG_TRACK_OUTPUT, $this->trackOutput ? 1 : 0);
		$nbt->setInt(self::TAG_VERSION, $this->version);
		$nbt->setByte(self::TAG_AUTO, $this->auto ? 1 : 0);
		$nbt->setByte(self::TAG_CONDITION_MET, $this->conditionMet ? 1 : 0);
		$nbt->setByte(self::TAG_POWERED, $this->powered ? 1 : 0);
	}

	public function getCommand() : string{ return $this->command; }

	/**
	 * Sets the command (with or without the leading slash). Longer commands are cut to {@link self::MAX_COMMAND_LENGTH}.
	 */
	public function setCommand(string $command) : void{
		$this->command = mb_substr($command, 0, self::MAX_COMMAND_LENGTH);
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Name of the command block, used as the name of its command sender ("" = "!").
	 */
	public function getCustomName() : string{ return $this->customName; }

	public function setCustomName(string $name) : void{
		$this->customName = $name;
		$this->clearSpawnCompoundCache();
	}

	public function getLastOutput() : string{ return $this->lastOutput; }

	public function setLastOutput(string $output) : void{
		$this->lastOutput = mb_substr($output, 0, self::MAX_OUTPUT_LENGTH);
		$this->lastOutputParams = [];
		$this->clearSpawnCompoundCache();
	}

	public function isTrackingOutput() : bool{ return $this->trackOutput; }

	public function setTrackOutput(bool $trackOutput) : void{
		$this->trackOutput = $trackOutput;
		if(!$trackOutput){
			$this->lastOutput = "";
			$this->lastOutputParams = [];
		}
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Returns 1 if the last run succeeded, 0 otherwise (conditional command blocks check it on the block behind them).
	 */
	public function getSuccessCount() : int{ return $this->successCount; }

	public function setSuccessCount(int $successCount) : void{
		$this->successCount = $successCount;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Whether the block is "Always Active" (otherwise it needs redstone). New chain command blocks are.
	 */
	public function isAuto() : bool{ return $this->auto; }

	public function setAuto(bool $auto) : void{
		$this->auto = $auto;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Whether the block received redstone power at the last neighbour update.
	 */
	public function isPowered() : bool{ return $this->powered; }

	public function setPowered(bool $powered) : void{
		$this->powered = $powered;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Whether the block may run: always active or powered.
	 */
	public function isActive() : bool{
		return $this->auto || $this->powered;
	}

	/**
	 * Whether the condition of a conditional command block was met the last time it was checked.
	 */
	public function isConditionMet() : bool{ return $this->conditionMet; }

	public function setConditionMet(bool $conditionMet) : void{
		$this->conditionMet = $conditionMet;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Ticks to wait before running (impulse, chain) or between two runs (repeating).
	 */
	public function getTickDelay() : int{ return $this->tickDelay; }

	public function setTickDelay(int $tickDelay) : void{
		$this->tickDelay = $tickDelay < 0 ? 0 : $tickDelay;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * Whether a repeating command block runs as soon as it becomes active, instead of waiting for its delay first.
	 * New repeating command blocks do.
	 */
	public function isExecuteOnFirstTick() : bool{ return $this->executeOnFirstTick; }

	public function setExecuteOnFirstTick(bool $executeOnFirstTick) : void{
		$this->executeOnFirstTick = $executeOnFirstTick;
		$this->clearSpawnCompoundCache();
	}

	/**
	 * World tick of the last run.
	 */
	public function getLastExecution() : int{ return $this->lastExecution; }

	/**
	 * @internal
	 */
	public function hasPendingRun() : bool{ return $this->pendingRun; }

	/**
	 * @internal
	 */
	public function setPendingRun(bool $pendingRun) : void{
		$this->pendingRun = $pendingRun;
	}

	/**
	 * @internal
	 */
	public function getRepeatDueTick() : int{ return $this->repeatDueTick; }

	/**
	 * @internal
	 */
	public function setRepeatDueTick(int $dueTick) : void{
		$this->repeatDueTick = $dueTick;
	}

	/**
	 * @internal
	 */
	public function getRepeatRescheduledTick() : int{ return $this->repeatRescheduledTick; }

	/**
	 * @internal
	 */
	public function setRepeatRescheduledTick(int $tick) : void{
		$this->repeatRescheduledTick = $tick;
	}

	/**
	 * Returns the sender the command runs as.
	 */
	public function getCommandSender() : CommandBlockSender{
		return $this->sender ??= new CommandBlockSender($this);
	}

	/**
	 * Runs the command now, unless command blocks are disabled by the commandblocksenabled game rule. Returns whether
	 * it ran successfully.
	 */
	public function runCommand() : bool{
		$world = $this->position->getWorld();
		$command = $this->command;
		if(str_starts_with($command, "/")){
			$command = substr($command, 1);
		}
		if($command === "" || $this->closed || $world->getGameRule(GameRules::COMMAND_BLOCKS_ENABLED) === false){
			$this->setSuccessCount(0);
			return false;
		}
		if($this->trackOutput){
			$this->setLastOutput("");
		}
		$this->lastExecution = $world->getServer()->getTick();
		$success = $world->getServer()->dispatchCommand($this->getCommandSender(), $command);
		$this->setSuccessCount($success ? 1 : 0);
		return $success;
	}
}
