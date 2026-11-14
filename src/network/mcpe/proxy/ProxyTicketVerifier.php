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

use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\Utils;
use function base64_decode;
use function count;
use function openssl_pkey_get_public;
use function openssl_verify;
use function strlen;
use function trim;
use const OPENSSL_ALGO_SHA256;

/**
 * @internal
 * Checks identity tickets and pm-link handshakes signed by trusted proxies (ECDSA P-256, SHA-256, DER signatures).
 */
final class ProxyTicketVerifier{
	/** Tolerance for clock differences between the proxy and this server. */
	public const CLOCK_DRIFT = 30;
	private const MAX_LIFETIME = 120;

	/**
	 * @var \OpenSSLAsymmetricKey[]
	 * @phpstan-var list<\OpenSSLAsymmetricKey>
	 */
	private array $keys = [];

	/**
	 * Nonces of accepted tickets until they expire, so that a ticket can be used only once.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $usedNonces = [];

	/**
	 * @param string[] $publicKeys base64 DER SubjectPublicKeyInfo keys, as printed by pm-proxy
	 *
	 * @throws \InvalidArgumentException if a key cannot be parsed
	 */
	public function __construct(
		array $publicKeys,
		private string $serverName
	){
		foreach($publicKeys as $b64){
			$this->keys[] = self::parsePublicKey($b64);
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function parsePublicKey(string $b64) : \OpenSSLAsymmetricKey{
		$der = base64_decode(trim($b64), true);
		if($der === false || $der === ""){
			throw new \InvalidArgumentException("Proxy public key is not valid base64");
		}
		$key = openssl_pkey_get_public(JwtUtils::derPublicKeyToPem($der));
		if($key === false){
			throw new \InvalidArgumentException("Proxy public key could not be parsed");
		}
		return $key;
	}

	public function hasKeys() : bool{
		return count($this->keys) > 0;
	}

	public function getServerName() : string{
		return $this->serverName;
	}

	/**
	 * Whether a trusted proxy key made this signature.
	 */
	public function verifySignature(string $data, string $signature) : bool{
		if(strlen($signature) === 0){
			return false;
		}
		foreach($this->keys as $key){
			if(openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1){
				return true;
			}
		}
		return false;
	}

	/**
	 * @throws ProxyTicketException
	 */
	public function verify(string $payload, string $signature, int $now) : ProxyTicket{
		if(!$this->verifySignature($payload, $signature)){
			throw new ProxyTicketException("Ticket signature is not from a trusted proxy");
		}
		$ticket = ProxyTicket::fromJson($payload);
		if($ticket->target !== $this->serverName){
			throw new ProxyTicketException("Ticket is for server \"$ticket->target\", this is \"$this->serverName\"");
		}
		if($ticket->issuedAt > $now + self::CLOCK_DRIFT || $ticket->expiresAt < $now - self::CLOCK_DRIFT || $ticket->expiresAt - $ticket->issuedAt > self::MAX_LIFETIME){
			throw new ProxyTicketException("Ticket expired or not yet valid (check the clocks of the proxy and this server)");
		}
		if($ticket->protocol !== ProtocolInfo::CURRENT_PROTOCOL){
			throw new ProxyTicketException("Ticket protocol $ticket->protocol, this server speaks " . ProtocolInfo::CURRENT_PROTOCOL);
		}

		foreach(Utils::stringifyKeys($this->usedNonces) as $nonce => $expiry){
			if($expiry < $now - self::CLOCK_DRIFT){
				unset($this->usedNonces[$nonce]);
			}
		}
		if(isset($this->usedNonces[$ticket->nonce])){
			throw new ProxyTicketException("Ticket already used");
		}
		$this->usedNonces[$ticket->nonce] = $ticket->expiresAt;

		return $ticket;
	}
}
