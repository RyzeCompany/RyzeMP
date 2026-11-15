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

use pocketmine\command\EntityCommandSender;
use pocketmine\command\utils\CommandBlockPermissions;
use pocketmine\entity\Entity;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\Server;
use function trim;
use const PHP_INT_MAX;

/**
 * The sender of the commands of a command block minecart, acting as the cart, with the permissions of a command block
 * ({@link CommandBlockPermissions}). Its output becomes the cart's last output.
 */
final class MinecartCommandSender implements EntityCommandSender{
	use PermissibleDelegateTrait;

	/** @phpstan-var positive-int|null */
	private ?int $lineHeight = null;

	public function __construct(
		private MinecartCommandBlock $minecart
	){
		$this->perm = CommandBlockPermissions::create();
	}

	public function getExecutingEntity() : Entity{
		return $this->minecart;
	}

	public function getMinecart() : MinecartCommandBlock{
		return $this->minecart;
	}

	public function getServer() : Server{
		return $this->minecart->getWorld()->getServer();
	}

	public function getLanguage() : Language{
		return $this->getServer()->getLanguage();
	}

	public function sendMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$message = $this->getLanguage()->translate($message);
		}
		if($this->minecart->isTrackingOutput()){
			$this->minecart->setLastOutput(trim($message));
		}
	}

	public function getName() : string{
		$name = $this->minecart->getNameTag();
		return $name !== "" ? $name : "@";
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
