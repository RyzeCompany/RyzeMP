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

namespace pocketmine\event\server;

/**
 * Called when another server of the pm-proxy network sent this server a plugin message with
 * {@link \pocketmine\network\proxy\ProxyNetwork::sendMessage()}. Plugins tell their messages apart by the channel.
 */
class ProxyMessageReceiveEvent extends ServerEvent{
	public function __construct(
		private string $sourceServer,
		private string $channel,
		private string $data
	){}

	/**
	 * Name of the server that sent the message.
	 */
	public function getSourceServer() : string{
		return $this->sourceServer;
	}

	public function getChannel() : string{
		return $this->channel;
	}

	public function getData() : string{
		return $this->data;
	}
}
