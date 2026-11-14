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

namespace pocketmine\network\mcpe\nethernet;

use pocketmine\utils\BundledBinary;
use Symfony\Component\Filesystem\Path;
use function implode;
use function is_file;
use function php_uname;
use function strtolower;
use const PHP_OS_FAMILY;

/**
 * Settings of the NetherNet bridge, read from server.properties and pocketmine.yml.
 */
final class NetherNetConfig{
	/**
	 * @param string[] $advertiseAddresses
	 */
	public function __construct(
		public readonly string $binaryPath,
		public readonly string $bindIp,
		public readonly int $signalingPort,
		/** UDP port for WebRTC; the server port when it is shared with RakNet/Query, 0 = ephemeral ports */
		public readonly int $udpPort,
		public readonly array $advertiseAddresses,
		public readonly string $identityFile,
		public readonly string $domain,
		public readonly string $tlsCertificate,
		public readonly string $tlsPrivateKey,
		public readonly string $logLevel,
		public readonly string $logFile,
		public readonly bool $requireIdentity,
		/** Loopback port of the RakLib relay socket when the UDP port is shared with RakNet */
		public readonly int $rakNetRelayPort = 0,
		/** Whether the UDP port is shared without RakNet: Query datagrams then come over the link */
		public readonly bool $queryToLink = false,
		/** Names (or IP addresses) to get HTTPS certificates for automatically (ACME), when no certificate file is set */
		public readonly string $acmeDomains = "",
		public readonly string $acmeEmail = "",
		public readonly string $acmeDirectory = "",
		public readonly string $acmeStorage = "",
		public readonly int $acmeHttpPort = 0,
		public readonly int $acmeTlsPort = 0,
		/**
		 * Environment variables for the bridge (flood protection limits, PM_NETHERNET_*). Passed in the environment rather
		 * than as arguments so that an older bridge ignores the ones it does not know instead of failing to start.
		 * @var string[]
		 * @phpstan-var array<string, string>
		 */
		public readonly array $environment = []
	){}

	/**
	 * Platform suffix of the bridge binaries, e.g. "windows-amd64" or "linux-arm64".
	 */
	public static function platformName() : string{
		$os = match(PHP_OS_FAMILY){
			"Windows" => "windows",
			"Darwin" => "darwin",
			"BSD" => "freebsd",
			default => strtolower(PHP_OS_FAMILY)
		};
		$machine = strtolower(php_uname("m"));
		$arch = match($machine){
			"x86_64", "amd64", "x64" => "amd64",
			"aarch64", "arm64", "armv8", "armv8l" => "arm64",
			default => $machine
		};
		return "$os-$arch";
	}

	/**
	 * Finds the bridge program: the configured path if any, otherwise the binary shipped with the server for this
	 * platform (copied out of the phar, since a program cannot run from inside it), otherwise one placed by the
	 * server owner in <data>/bin/.
	 */
	public static function locateBinary(string $configured, string $dataPath) : ?string{
		if($configured !== ""){
			$path = Path::isAbsolute($configured) ? $configured : Path::join($dataPath, $configured);
			return is_file($path) ? $path : null;
		}

		$name = "pm-nethernet-" . self::platformName() . (PHP_OS_FAMILY === "Windows" ? ".exe" : "");
		return BundledBinary::locate(Path::join("native", "nethernet", "bin", $name), Path::join($dataPath, "bin", $name));
	}

	/**
	 * Whether the bridge owns the server's UDP port and shares it with RakNet or Query.
	 */
	public function isUdpPortShared() : bool{
		return $this->rakNetRelayPort !== 0 || $this->queryToLink;
	}

	/**
	 * Command-line arguments for the bridge program (without the program itself).
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function toArguments(int $linkPort) : array{
		$bind = $this->bindIp === "0.0.0.0" || $this->bindIp === "" ? ":" . $this->signalingPort : $this->bindIp . ":" . $this->signalingPort;
		$args = [
			"-link=127.0.0.1:" . $linkPort,
			"-bind=" . $bind,
			"-udp-port=" . $this->udpPort,
			"-identity=" . $this->identityFile,
			"-domain=" . ($this->domain !== "" ? $this->domain : "self"),
			"-log-level=" . $this->logLevel,
			"-watch-stdin",
		];
		if($this->advertiseAddresses !== []){
			$args[] = "-advertise=" . implode(",", $this->advertiseAddresses);
		}
		if($this->tlsCertificate !== "" && $this->tlsPrivateKey !== ""){
			$args[] = "-tls-cert=" . $this->tlsCertificate;
			$args[] = "-tls-key=" . $this->tlsPrivateKey;
		}elseif($this->acmeDomains !== ""){
			$args[] = "-acme-domains=" . $this->acmeDomains;
			if($this->acmeEmail !== ""){
				$args[] = "-acme-email=" . $this->acmeEmail;
			}
			if($this->acmeDirectory !== ""){
				$args[] = "-acme-ca=" . $this->acmeDirectory;
			}
			if($this->acmeStorage !== ""){
				$args[] = "-acme-storage=" . $this->acmeStorage;
			}
			if($this->acmeHttpPort > 0){
				$args[] = "-acme-http-port=" . $this->acmeHttpPort;
			}
			if($this->acmeTlsPort > 0){
				$args[] = "-acme-tls-port=" . $this->acmeTlsPort;
			}
		}
		if($this->logFile !== ""){
			$args[] = "-log-file=" . $this->logFile;
		}
		if($this->requireIdentity){
			$args[] = "-require-identity";
		}
		if($this->rakNetRelayPort !== 0){
			$args[] = "-raknet-relay=127.0.0.1:" . $this->rakNetRelayPort;
		}elseif($this->queryToLink){
			$args[] = "-query-to-link";
		}
		return $args;
	}
}
