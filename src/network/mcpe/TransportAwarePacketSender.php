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

namespace pocketmine\network\mcpe;

/**
 * A PacketSender that can describe the transport carrying the session (RakNet, NetherNet...).
 * Senders that do not implement this are treated as unencrypted RakNet-like transports.
 */
interface TransportAwarePacketSender extends PacketSender{

	/**
	 * Short lowercase name of the transport, e.g. "raknet" or "nethernet".
	 */
	public function getTransportName() : string;

	/**
	 * Whether the transport itself encrypts and authenticates the traffic (NetherNet uses DTLS). Vanilla clients do
	 * not perform the Minecraft-level encryption handshake on such transports, so the server must skip it.
	 */
	public function isTransportEncrypted() : bool;

	/**
	 * Base64-encoded DER public key that the peer proved possession of at the transport level (NetherNet identity
	 * assertion), or null if the transport does not authenticate the peer.
	 */
	public function getPeerPublicKey() : ?string;
}
