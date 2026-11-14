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

namespace pocketmine\event\player;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\player\Player;

/**
 * Called when a player of this server is about to be moved to another server of the pm-proxy network. The player stays
 * connected: the proxy moves it without a reconnection. Cancel it to keep the player here (combat loggers, minigames).
 *
 * It is called:
 * - when the move is asked on this server (/server, /hub, {@link \pocketmine\network\proxy\ProxyNetwork::transfer()},
 *   or {@link Player::transfer()} with the name of a server of the network), before the request goes to the proxy;
 * - when the move comes from elsewhere (/send or a plugin on another server, the proxy's admin API), when the proxy is
 *   about to take the player. The target is then the server the proxy chose, and changing it has no effect.
 *
 * Not called when the server stops and the proxy moves its players to the lobby. A player who is dead, or who gets hurt
 * while the camera of a cinematic transfer flies up, stays here.
 */
class PlayerProxyTransferEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		protected string $target,
		protected string $style
	){
		$this->player = $player;
	}

	/**
	 * Server or group of the network the player goes to, or {@link \pocketmine\network\proxy\ProxyNetwork::TARGET_HUB}.
	 */
	public function getTarget() : string{
		return $this->target;
	}

	public function setTarget(string $target) : void{
		$this->target = $target;
	}

	/**
	 * One of the STYLE_ constants of {@link \pocketmine\network\proxy\ProxyNetwork}, or "" for the proxy's default.
	 */
	public function getStyle() : string{
		return $this->style;
	}

	public function setStyle(string $style) : void{
		$this->style = $style;
	}
}
