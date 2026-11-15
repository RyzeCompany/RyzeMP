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

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\AssumptionFailedError;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function hash;
use function json_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function random_bytes;
use function str_replace;
use function trim;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

class ProxyTicketVerifierTest extends TestCase{
	private const NOW = 1_800_000_000;

	private \OpenSSLAsymmetricKey $key;
	private string $publicKey;

	public function setUp() : void{
		$this->key = self::newKey();
		$this->publicKey = self::publicKeyBase64($this->key);
	}

	private static function newKey() : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_new(["ec" => ["curve_name" => "prime256v1"]]);
		if($key === false){
			throw new AssumptionFailedError("could not create an EC key");
		}
		return $key;
	}

	/**
	 * The form pm-proxy prints: base64 DER SubjectPublicKeyInfo.
	 */
	private static function publicKeyBase64(\OpenSSLAsymmetricKey $key) : string{
		$details = openssl_pkey_get_details($key);
		if($details === false){
			throw new AssumptionFailedError("no key details");
		}
		$pem = $details["key"];
		return trim(str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"], "", $pem));
	}

	/**
	 * @param mixed[] $override
	 * @return string[] [payload, signature]
	 */
	private function sign(array $override = [], ?\OpenSSLAsymmetricKey $key = null) : array{
		$clientKey = self::publicKeyBase64(self::newKey());
		$ticket = $override + [
			"v" => 1,
			"proxy" => "proxy-1",
			"target" => "lobby-1",
			"session" => 7,
			"name" => "Steve",
			"xuid" => "2535412345678901",
			"uuid" => "6f90271b-f9e0-3d59-b057-14076b34db63",
			"titleId" => "1739947436",
			"ip" => "203.0.113.7",
			"port" => 50000,
			"protocol" => ProtocolInfo::CURRENT_PROTOCOL,
			"clientData" => hash("sha256", "a.b.c"),
			"clientKey" => $clientKey,
			"xbox" => true,
			"iat" => self::NOW,
			"exp" => self::NOW + 30,
			"nonce" => bin2hex(random_bytes(16)),
		];
		$payload = json_encode($ticket, JSON_THROW_ON_ERROR);
		if(!openssl_sign($payload, $signature, $key ?? $this->key, OPENSSL_ALGO_SHA256)){
			throw new AssumptionFailedError("could not sign");
		}
		return [$payload, $signature];
	}

	public function testValidTicket() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign();
		$ticket = $verifier->verify($payload, $signature, self::NOW + 5);
		self::assertSame("Steve", $ticket->name);
		self::assertSame(7, $ticket->sessionId);
		self::assertTrue($ticket->xbox);
		self::assertSame(base64_decode($ticket->clientKey, true), $ticket->clientKeyDer);
	}

	public function testReplayRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign();
		$verifier->verify($payload, $signature, self::NOW);
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("already used");
		$verifier->verify($payload, $signature, self::NOW + 1);
	}

	public function testUntrustedKeyRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign([], self::newKey());
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("trusted proxy");
		$verifier->verify($payload, $signature, self::NOW);
	}

	public function testTamperedTicketRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign();
		$this->expectException(ProxyTicketException::class);
		$verifier->verify(str_replace("Steve", "Alex", $payload), $signature, self::NOW);
	}

	public function testOtherServerRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "survival");
		[$payload, $signature] = $this->sign();
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("is for server \"lobby-1\"");
		$verifier->verify($payload, $signature, self::NOW);
	}

	public function testExpiredRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign();
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("expired");
		$verifier->verify($payload, $signature, self::NOW + 30 + ProxyTicketVerifier::CLOCK_DRIFT + 1);
	}

	public function testOtherProtocolRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign(["protocol" => ProtocolInfo::CURRENT_PROTOCOL - 1]);
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("protocol");
		$verifier->verify($payload, $signature, self::NOW);
	}

	public function testMalformedTicketRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign(["xbox" => "yes"]);
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("xbox");
		$verifier->verify($payload, $signature, self::NOW);
	}

	public function testTransferTicket() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign(["transfer" => true, "runtimeId" => 4880525957267469, "dimension" => 1, "viewDistance" => 12]);
		$ticket = $verifier->verify($payload, $signature, self::NOW);
		self::assertTrue($ticket->transfer);
		self::assertSame(4880525957267469, $ticket->runtimeId);
		self::assertSame(1, $ticket->dimension);
		self::assertSame(12, $ticket->viewDistance);

		[$payload, $signature] = $this->sign();
		self::assertFalse($verifier->verify($payload, $signature, self::NOW)->transfer, "a login ticket is not a transfer");
	}

	public function testTransferWithoutRuntimeIdRefused() : void{
		$verifier = new ProxyTicketVerifier([$this->publicKey], "lobby-1");
		[$payload, $signature] = $this->sign(["transfer" => true]);
		$this->expectException(ProxyTicketException::class);
		$this->expectExceptionMessage("runtime ID");
		$verifier->verify($payload, $signature, self::NOW);
	}

	public function testInvalidPublicKey() : void{
		$this->expectException(\InvalidArgumentException::class);
		new ProxyTicketVerifier([base64_encode("not a key")], "lobby-1");
	}
}
