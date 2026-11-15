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

namespace pocketmine\command;

use pocketmine\command\selector\TargetSelector;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\Server;
use pocketmine\world\Position;

/**
 * The sender of a command run by /execute: the original sender (its permissions and its output) with the
 * context /execute built: the executing entity (@s, null if none), the position and dimension the command runs at
 * (relative and local coordinates, selector distances) and the rotation (local coordinates).
 *
 * Commands that act on "the sender" when no target is given (e.g. /kill, /tp x y z) act on the executing entity.
 */
final class ExecuteCommandSender implements CommandSender{
	use PermissibleDelegateTrait;

	private CommandSender $origin;

	public function __construct(
		CommandSender $origin,
		private ?Entity $executor,
		private Position $position,
		private float $yaw,
		private float $pitch,
		private bool $anchoredAtEyes = false
	){
		//nested /execute: keep the original sender
		$this->origin = $origin instanceof self ? $origin->origin : $origin;
		$this->perm = $this->origin;
		if(!$position->isValid()){
			throw new \InvalidArgumentException("The position must have a valid world");
		}
	}

	/** The sender who ran /execute (never another ExecuteCommandSender). */
	public function getOriginalSender() : CommandSender{
		return $this->origin;
	}

	/** The entity the command runs as (@s), or null (e.g. /execute from the console without "as"). */
	public function getExecutor() : ?Entity{
		return $this->executor;
	}

	/** Where the command runs: the origin of relative coordinates and selector distances. */
	public function getExecutingPosition() : Position{
		return $this->position;
	}

	public function getYaw() : float{
		return $this->yaw;
	}

	public function getPitch() : float{
		return $this->pitch;
	}

	/**
	 * Whether "facing" and local coordinates (^) of /execute start from the executor's eyes ("anchored eyes") rather
	 * than from the position.
	 */
	public function isAnchoredAtEyes() : bool{
		return $this->anchoredAtEyes;
	}

	/** Position and rotation the command runs with. */
	public function getExecutingLocation() : Location{
		return Location::fromObject($this->position, $this->position->getWorld(), $this->yaw, $this->pitch);
	}

	public function getLanguage() : Language{
		return $this->origin->getLanguage();
	}

	public function sendMessage(Translatable|string $message) : void{
		$this->origin->sendMessage($message);
	}

	public function getServer() : Server{
		return $this->origin->getServer();
	}

	/**
	 * Name of the executing entity (like BDS: "/execute as @e run say hi" says it as each entity), or of the original
	 * sender if there is none.
	 */
	public function getName() : string{
		return $this->executor !== null ? TargetSelector::getEntityName($this->executor) : $this->origin->getName();
	}

	public function getScreenLineHeight() : int{
		return $this->origin->getScreenLineHeight();
	}

	public function setScreenLineHeight(?int $height) : void{
		$this->origin->setScreenLineHeight($height);
	}
}
