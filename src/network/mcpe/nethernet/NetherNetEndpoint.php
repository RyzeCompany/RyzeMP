<?php

/*
 * This file is part of RyzeMP.
 * Copyright (C) RyzeCompany contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */
declare(strict_types=1);

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\Server;

/**
 * Shared external Bedrock endpoint for both transports.
 *
 * NetherNet uses TCP for signaling and UDP for its ICE/DTLS game data.
 * RakNet uses UDP. The future multiplexing transport must own the UDP socket
 * once and route packet types; independent same-port UDP listeners are unsafe.
 *
 * @internal
 */
final class NetherNetEndpoint{

	public function __construct(
		private string $address,
		private int $port
	){
		if($address === ""){
			throw new \InvalidArgumentException("NetherNet bind IP cannot be empty");
		}
		if($port < 1 || $port > 65535){
			throw new \InvalidArgumentException("NetherNet bind port must be within 1..65535");
		}
	}

	public static function fromServer(Server $server) : self{
		// Always derive NetherNet from the existing server-ip/server-port.
		// Do not introduce a second public IP/port, so player bookmarks survive.
		return new self($server->getIp(), $server->getPort());
	}

	public function getAddress() : string{ return $this->address; }
	public function getPort() : int{ return $this->port; }

	/** The signaling listener will use this TCP endpoint. */
	public function signalingAddress() : string{
		return $this->address . ":" . $this->port;
	}
}
