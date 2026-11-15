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

namespace pocketmine\entity\vehicle;

use pocketmine\inventory\MinecartCommandBlockInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use function mb_substr;

/**
 * Minecart with command block (vanilla minecraft:command_block_minecart). PocketMine has no command blocks, so this
 * cart carries a minimal one: on a powered activator rail it runs its command every 4 ticks (vanilla
 * MinecartCommandBlock.ACTIVATION_DELAY) as a console-like operator sender whose target selectors and relative
 * coordinates start from the cart (@s is the cart). Operators in creative mode edit it by interacting with it (the
 * client's command block screen); plugins use {@link MinecartCommandBlock::setCommand()}.
 */
class MinecartCommandBlock extends AbstractMinecart{
	public const TAG_COMMAND = "Command"; //TAG_String
	public const TAG_LAST_OUTPUT = "LastOutput"; //TAG_String
	public const TAG_TRACK_OUTPUT = "TrackOutput"; //TAG_Byte
	public const TAG_SUCCESS_COUNT = "SuccessCount"; //TAG_Int

	/** Minimum ticks between two runs on an activator rail. */
	public const ACTIVATION_DELAY = 4;
	public const MAX_OUTPUT_LENGTH = 1024;

	protected string $command = "";
	protected string $lastOutput = "";
	protected bool $trackOutput = true;
	protected int $successCount = 0;
	private int $lastActivated = -self::ACTIVATION_DELAY;
	private ?MinecartCommandSender $sender = null;

	public static function getNetworkTypeId() : string{ return EntityIds::COMMAND_BLOCK_MINECART; }

	public function getName() : string{
		return "Minecart with Command Block";
	}

	public function getMinecartType() : MinecartType{
		return MinecartType::COMMAND_BLOCK;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->command = $nbt->getString(self::TAG_COMMAND, "");
		$this->lastOutput = $nbt->getString(self::TAG_LAST_OUTPUT, "");
		$this->trackOutput = $nbt->getByte(self::TAG_TRACK_OUTPUT, 1) !== 0;
		$this->successCount = $nbt->getInt(self::TAG_SUCCESS_COUNT, 0);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setString(self::TAG_COMMAND, $this->command);
		$nbt->setString(self::TAG_LAST_OUTPUT, $this->lastOutput);
		$nbt->setByte(self::TAG_TRACK_OUTPUT, $this->trackOutput ? 1 : 0);
		$nbt->setInt(self::TAG_SUCCESS_COUNT, $this->successCount);
		return $nbt;
	}

	public function getCommand() : string{ return $this->command; }

	/**
	 * Sets the command run by the cart (with or without the leading slash).
	 */
	public function setCommand(string $command) : void{
		$this->command = $command;
		$this->networkPropertiesDirty = true;
	}

	public function getLastOutput() : string{ return $this->lastOutput; }

	public function setLastOutput(string $output) : void{
		$this->lastOutput = mb_substr($output, 0, self::MAX_OUTPUT_LENGTH);
		$this->networkPropertiesDirty = true;
	}

	public function isTrackingOutput() : bool{ return $this->trackOutput; }

	public function setTrackOutput(bool $trackOutput) : void{
		$this->trackOutput = $trackOutput;
		if(!$trackOutput){
			$this->lastOutput = "";
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns 1 if the last run succeeded, 0 otherwise (vanilla SuccessCount).
	 */
	public function getSuccessCount() : int{ return $this->successCount; }

	/**
	 * Returns the sender the command runs as.
	 */
	public function getCommandSender() : MinecartCommandSender{
		return $this->sender ??= new MinecartCommandSender($this);
	}

	/**
	 * Runs the command now. Returns whether it ran successfully.
	 */
	public function runCommand() : bool{
		$command = $this->command;
		if($command === "" || $this->closed || $this->getWorld()->getGameRule(GameRules::COMMAND_BLOCKS_ENABLED) === false){
			$this->successCount = 0;
			return false;
		}
		if($command[0] === "/"){
			$command = mb_substr($command, 1);
		}
		if($this->trackOutput){
			$this->setLastOutput("");
		}
		$success = $this->server->dispatchCommand($this->getCommandSender(), $command);
		$this->successCount = $success ? 1 : 0;
		return $success;
	}

	protected function onActivatorRail(bool $powered) : void{
		if($powered && $this->ticksLived - $this->lastActivated >= self::ACTIVATION_DELAY){
			$this->lastActivated = $this->ticksLived;
			$this->runCommand();
		}
	}

	/**
	 * Whether the player may edit the command (vanilla: operators in creative mode).
	 */
	public function canEdit(Player $player) : bool{
		return $player->isCreative() && $player->hasPermission(DefaultPermissions::ROOT_OPERATOR);
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if(!$this->canEdit($player)){
			return false;
		}
		return $player->setCurrentWindow(new MinecartCommandBlockInventory($this));
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setByte(EntityMetadataProperties::HAS_COMMAND_BLOCK, 1);
		$properties->setString(EntityMetadataProperties::COMMAND_BLOCK_COMMAND, $this->command);
		$properties->setString(EntityMetadataProperties::COMMAND_BLOCK_LAST_OUTPUT, $this->lastOutput);
		$properties->setByte(EntityMetadataProperties::COMMAND_BLOCK_TRACK_OUTPUT, $this->trackOutput ? 1 : 0);
		$properties->setInt(EntityMetadataProperties::COMMAND_BLOCK_TICK_DELAY, self::ACTIVATION_DELAY);
		$properties->setByte(EntityMetadataProperties::COMMAND_BLOCK_EXECUTE_ON_FIRST_TICK, 0);
	}
}
