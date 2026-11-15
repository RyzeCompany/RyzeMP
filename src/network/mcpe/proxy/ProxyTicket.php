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

use function base64_decode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * @internal
 * Identity of a player as verified by pm-proxy (Xbox Live, client key), signed by the proxy. A proxied server trusts it
 * instead of checking the login with Xbox Live itself. See PROXY-DISEÑO.md, section 4.
 */
final class ProxyTicket{
	public const VERSION = 1;

	public function __construct(
		public readonly string $proxyId,
		public readonly string $target,
		public readonly int $sessionId,
		public readonly string $name,
		public readonly string $xuid,
		public readonly string $uuid,
		public readonly string $ip,
		public readonly int $port,
		public readonly int $protocol,
		/** Hex SHA-256 of the client data JWT of the Login packet the ticket was issued for */
		public readonly string $clientDataSha256,
		/** Base64 DER public key of the login ("cpk") */
		public readonly string $clientKey,
		/** Raw DER of $clientKey */
		public readonly string $clientKeyDer,
		public readonly bool $xbox,
		public readonly int $issuedAt,
		public readonly int $expiresAt,
		public readonly string $nonce,
		/**
		 * The player comes from another server of the network and is already in game: no StartGame, no resource
		 * packs; the client is moved into this server's world with a dimension change.
		 */
		public readonly bool $transfer = false,
		/** Transfers: the entity runtime ID the client already has for itself (from its first StartGame) */
		public readonly int $runtimeId = 0,
		/** Transfers: network ID of the dimension the client is in */
		public readonly int $dimension = 0,
		/** Transfers: the view distance the client asked for (it does not send RequestChunkRadius again) */
		public readonly int $viewDistance = 0,
		/** How the player sees the change of server, one of ProxyTransferStyle */
		public readonly string $style = ProxyTransferStyle::DIMENSION,
		/** The proxy already sent LOGIN_SUCCESS and the resource packs: go straight to StartGame */
		public readonly bool $packs = false
	){}

	/**
	 * @throws ProxyTicketException
	 */
	public static function fromJson(string $json) : self{
		try{
			$d = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
		}catch(\JsonException $e){
			throw new ProxyTicketException("Invalid ticket JSON: " . $e->getMessage(), 0, $e);
		}
		if(!is_array($d)){
			throw new ProxyTicketException("Ticket is not a JSON object");
		}
		$str = static function(string $key) use ($d) : string{
			$v = $d[$key] ?? null;
			if(!is_string($v)){
				throw new ProxyTicketException("Ticket field \"$key\" must be a string");
			}
			return $v;
		};
		$int = static function(string $key) use ($d) : int{
			$v = $d[$key] ?? null;
			if(!is_int($v)){
				throw new ProxyTicketException("Ticket field \"$key\" must be an integer");
			}
			return $v;
		};
		if($int("v") !== self::VERSION){
			throw new ProxyTicketException("Unsupported ticket version " . $int("v"));
		}
		$xbox = $d["xbox"] ?? null;
		if(!is_bool($xbox)){
			throw new ProxyTicketException("Ticket field \"xbox\" must be a boolean");
		}
		$style = $d["style"] ?? ProxyTransferStyle::DIMENSION;
		if(!is_string($style) || !in_array($style, ProxyTransferStyle::ALL, true)){
			$style = ProxyTransferStyle::DIMENSION;
		}
		$packs = $d["packs"] ?? false;
		if(!is_bool($packs)){
			throw new ProxyTicketException("Ticket field \"packs\" must be a boolean");
		}
		$transfer = $d["transfer"] ?? false;
		if(!is_bool($transfer)){
			throw new ProxyTicketException("Ticket field \"transfer\" must be a boolean");
		}
		$optionalInt = static function(string $key) use ($d, $int) : int{
			return isset($d[$key]) ? $int($key) : 0;
		};
		if($transfer && $optionalInt("runtimeId") <= 0){
			throw new ProxyTicketException("Transfer ticket without the player's runtime ID");
		}
		$clientKey = $str("clientKey");
		$clientKeyDer = base64_decode($clientKey, true);
		if($clientKeyDer === false || $clientKeyDer === ""){
			throw new ProxyTicketException("Ticket client key is not valid base64");
		}
		return new self(
			proxyId: $str("proxy"),
			target: $str("target"),
			sessionId: $int("session"),
			name: $str("name"),
			xuid: $str("xuid"),
			uuid: $str("uuid"),
			ip: $str("ip"),
			port: $int("port"),
			protocol: $int("protocol"),
			clientDataSha256: $str("clientData"),
			clientKey: $clientKey,
			clientKeyDer: $clientKeyDer,
			xbox: $xbox,
			issuedAt: $int("iat"),
			expiresAt: $int("exp"),
			nonce: $str("nonce"),
			transfer: $transfer,
			runtimeId: $optionalInt("runtimeId"),
			dimension: $optionalInt("dimension"),
			viewDistance: $optionalInt("viewDistance"),
			style: $style,
			packs: $packs
		);
	}
}
