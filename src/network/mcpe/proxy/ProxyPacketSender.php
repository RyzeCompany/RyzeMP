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

namespace pocketmine\network\mcpe\proxy;

use pocketmine\network\mcpe\TransportAwarePacketSender;

/**
 * @internal
 * Sends a proxied session's batches over pm-link. The proxy owns the client connection (and its encryption), and
 * vouches for the player with a ticket.
 */
final class ProxyPacketSender implements TransportAwarePacketSender{
	private bool $closed = false;

	public function __construct(
		private int $sessionId,
		private ProxyLinkInterface $handler,
		private ProxyTicket $ticket
	){}

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		if(!$this->closed){
			$this->handler->putPacket($this->sessionId, $payload, $receiptId);
		}
	}

	public function close(string $reason = "unknown reason") : void{
		if(!$this->closed){
			$this->closed = true;
			$this->handler->close($this->sessionId, $reason);
		}
	}

	public function getTransportName() : string{
		return "proxy";
	}

	public function isTransportEncrypted() : bool{
		//the proxy encrypts the client connection (DTLS or Minecraft encryption) and the link is private
		return true;
	}

	public function getPeerPublicKey() : string{
		//the proxy checked that the client's connection is signed with this key
		return $this->ticket->clientKey;
	}

	public function getTicket() : ProxyTicket{
		return $this->ticket;
	}

	public function getInterface() : ProxyLinkInterface{
		return $this->handler;
	}
}
