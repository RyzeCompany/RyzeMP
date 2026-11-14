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

use pocketmine\command\BlockCommandSender;
use pocketmine\command\utils\CommandBlockPermissions;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\Server;
use pocketmine\world\Position;
use function trim;
use const PHP_INT_MAX;

/**
 * The sender of the commands of a command block, named after the block's custom name, "!" by default like Bedrock.
 * It has the operator permissions except the admin ones ({@link CommandBlockPermissions}). Its output becomes the
 * block's last output.
 */
final class CommandBlockSender implements BlockCommandSender{
	use PermissibleDelegateTrait;

	public const DEFAULT_NAME = "!";

	/** @phpstan-var positive-int|null */
	private ?int $lineHeight = null;

	public function __construct(
		private CommandBlock $commandBlock
	){
		$this->perm = CommandBlockPermissions::create();
	}

	public function getCommandBlock() : CommandBlock{
		return $this->commandBlock;
	}

	public function getExecutingPosition() : Position{
		return Position::fromObject($this->commandBlock->getPosition()->add(0.5, 0.5, 0.5), $this->commandBlock->getPosition()->getWorld());
	}

	public function getServer() : Server{
		return $this->commandBlock->getPosition()->getWorld()->getServer();
	}

	public function getLanguage() : Language{
		return $this->getServer()->getLanguage();
	}

	public function sendMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$message = $this->getLanguage()->translate($message);
		}
		if($this->commandBlock->isTrackingOutput()){
			$this->commandBlock->setLastOutput(trim($message));
		}
	}

	public function getName() : string{
		$name = $this->commandBlock->getCustomName();
		return $name !== "" ? $name : self::DEFAULT_NAME;
	}

	public function getScreenLineHeight() : int{
		return $this->lineHeight ?? PHP_INT_MAX;
	}

	public function setScreenLineHeight(?int $height) : void{
		if($height !== null && $height < 1){
			throw new \InvalidArgumentException("Line height must be at least 1");
		}
		$this->lineHeight = $height;
	}
}
