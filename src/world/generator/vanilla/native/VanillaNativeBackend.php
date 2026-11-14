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

namespace pocketmine\world\generator\vanilla\native;

use pocketmine\Server;
use pocketmine\utils\BundledBinary;
use pocketmine\YmlServerProperties as Yml;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function clearstatcache;
use function fclose;
use function file_exists;
use function file_get_contents;
use function filesize;
use function getenv;
use function getmypid;
use function is_file;
use function is_resource;
use function max;
use function microtime;
use function php_uname;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function putenv;
use function random_bytes;
use function realpath;
use function register_shutdown_function;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;
use const PHP_OS_FAMILY;

/**
 * The vanillagen helper process: native terrain core of the "vanilla" generator (Go program in native/vanillagen).
 *
 * The server's main thread starts one process (when the first vanilla world is loaded) and publishes its loopback
 * endpoint in the process environment; every generator instance, in every async worker thread, opens its own
 * connection to it (see {@link NativeTerrainFiller}). The helper exits when its stdin pipe is closed, so it never
 * outlives the server. If it cannot be started, nothing is published and the generator uses the PHP code.
 */
final class VanillaNativeBackend{
	/** Wire protocol version (must match native/vanillagen/protocol.go). */
	public const PROTOCOL_VERSION = 7;
	/**
	 * Version of the generated terrain. Must match algorithmVersion in native/vanillagen/protocol.go: bump both
	 * whenever the chunks produced by the PHP stages (terrain, material rules, carvers), and so by the native ones, change.
	 */
	public const ALGORITHM_VERSION = 4;

	public const ENV_ENDPOINT = "PM_VANILLAGEN_ENDPOINT";
	public const ENV_TOKEN = "PM_VANILLAGEN_TOKEN";

	private static ?self $instance = null;
	private static bool $attempted = false;

	/**
	 * @param resource $process
	 * @param resource $stdin
	 */
	private function __construct(
		private $process,
		private $stdin,
		public readonly string $binary,
		public readonly int $port,
		public readonly string $token
	){}

	/**
	 * Platform suffix of the shipped binaries, e.g. "windows-amd64" or "linux-arm64".
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

	public static function binaryName() : string{
		return "vanillagen-" . self::platformName() . (PHP_OS_FAMILY === "Windows" ? ".exe" : "");
	}

	/**
	 * Finds the program: the configured path if any, otherwise the binary shipped with the server for this platform
	 * (native/vanillagen/bin, copied out of the phar since a program cannot run from inside it), otherwise one placed
	 * by the server owner in <data>/bin/.
	 */
	public static function locateBinary(string $configured, string $dataPath) : ?string{
		if($configured !== ""){
			$path = Path::isAbsolute($configured) ? $configured : Path::join($dataPath, $configured);
			return is_file($path) ? $path : null;
		}
		$name = self::binaryName();
		return BundledBinary::locate(Path::join("native", "vanillagen", "bin", $name), Path::join($dataPath, "bin", $name));
	}

	/**
	 * Main thread: starts the helper once, according to pocketmine.yml, and publishes its endpoint for the worker
	 * threads. Failures are logged and leave the PHP implementation in use.
	 */
	public static function prepareForServer() : void{
		if(self::$attempted){
			return;
		}
		self::$attempted = true;
		try{
			$server = Server::getInstance();
		}catch(\RuntimeException){
			return; //not in the server thread (tools, tests): callers start the backend themselves
		}
		$logger = $server->getLogger();
		$config = $server->getConfigGroup();
		if(!$config->getPropertyBool(Yml::CHUNK_GENERATION_VANILLA_NATIVE_ENABLED, true)){
			$logger->info("Vanilla generator: native terrain core disabled in pocketmine.yml, using the PHP implementation");
			return;
		}
		$configured = $config->getPropertyString(Yml::CHUNK_GENERATION_VANILLA_NATIVE_BINARY, "");
		$binary = self::locateBinary($configured, $server->getDataPath());
		if($binary === null){
			$logger->warning("Vanilla generator: the vanillagen program for this platform (" . self::platformName() . ") was not found" . ($configured !== "" ? " at $configured" : "") . "; using the PHP implementation (slower)");
			return;
		}
		try{
			$backend = self::start($binary, Path::join($server->getDataPath(), "vanillagen.log"), goGc: $server->getResourceProfile()->getVanillagenGoGc());
		}catch(NativeBackendException $e){
			$logger->warning("Vanilla generator: could not start $binary: " . $e->getMessage() . "; using the PHP implementation (slower)");
			return;
		}
		$backend->publish();
		self::$instance = $backend;
		register_shutdown_function(static fn() => $backend->stop());
		$logger->info("Vanilla generator: native terrain core started (" . self::platformName() . ", port " . $backend->port . ")");
	}

	public static function getInstance() : ?self{
		return self::$instance;
	}

	/**
	 * Environment of the helper process: this process's environment, the connection token and, when $goGc is not null
	 * and the environment has no GOGC of its own, GOGC=$goGc (Go collects when the heap has grown by that percentage of
	 * the live data; lower = smaller heap, more CPU).
	 *
	 * @param string[] $env
	 * @phpstan-param array<string, string> $env
	 * @return string[]
	 * @phpstan-return array<string, string>
	 */
	public static function buildEnvironment(array $env, string $token, ?int $goGc) : array{
		$env[self::ENV_TOKEN] = $token;
		if($goGc !== null && !isset($env["GOGC"])){
			$env["GOGC"] = (string) $goGc;
		}
		return $env;
	}

	/**
	 * Starts the helper and waits until it listens.
	 * @param int|null $goGc GOGC for the helper (see {@link buildEnvironment()}), null = Go's default
	 * @throws NativeBackendException
	 */
	public static function start(string $binary, string $logFile, float $timeout = 10.0, ?int $goGc = null) : self{
		if(!is_file($binary)){
			throw new NativeBackendException("program not found");
		}
		//CreateProcess does not search relative paths with forward slashes
		$real = realpath($binary);
		if($real !== false){
			$binary = $real;
		}
		$token = bin2hex(random_bytes(16));
		$portFile = Path::join(sys_get_temp_dir(), "pocketmine-vanillagen-" . getmypid() . "-" . bin2hex(random_bytes(4)) . ".port");
		clearstatcache();
		$logOffset = file_exists($logFile) ? (int) filesize($logFile) : 0;

		$env = self::buildEnvironment(getenv(), $token, $goGc);
		$pipes = [];
		$process = @proc_open(
			[$binary, "-port-file=" . $portFile, "-watch-stdin"],
			[
				0 => ["pipe", "r"],
				1 => ["file", $logFile, "a"],
				2 => ["file", $logFile, "a"],
			],
			$pipes,
			null,
			$env
		);
		if(!is_resource($process) || !isset($pipes[0])){
			throw new NativeBackendException("proc_open failed");
		}
		$stdin = $pipes[0];

		$deadline = microtime(true) + $timeout;
		$port = 0;
		while(true){
			clearstatcache();
			if(is_file($portFile)){
				$port = (int) trim((string) @file_get_contents($portFile));
				@unlink($portFile);
				break;
			}
			$status = proc_get_status($process);
			if(!$status["running"] || microtime(true) > $deadline){
				if($status["running"]){
					proc_terminate($process);
				}
				@fclose($stdin);
				proc_close($process);
				$tail = self::logTail($logFile, $logOffset);
				throw new NativeBackendException(($status["running"] ? "timed out waiting for it to start" : "it exited with code " . $status["exitcode"]) . ($tail !== "" ? ": $tail" : ""));
			}
			usleep(10_000);
		}
		if($port <= 0 || $port > 65535){
			@fclose($stdin);
			proc_terminate($process);
			proc_close($process);
			throw new NativeBackendException("invalid port file");
		}
		return new self($process, $stdin, $binary, $port, $token);
	}

	private static function logTail(string $logFile, int $offset) : string{
		clearstatcache();
		if(!file_exists($logFile)){
			return "";
		}
		$data = @file_get_contents($logFile, false, null, $offset);
		if($data === false){
			return "";
		}
		return trim(substr($data, max(0, strlen($data) - 1024)));
	}

	/**
	 * Makes the endpoint visible to every thread of this process (worker threads read it with {@link endpoint()}).
	 */
	public function publish() : void{
		putenv(self::ENV_ENDPOINT . "=127.0.0.1:" . $this->port);
		putenv(self::ENV_TOKEN . "=" . $this->token);
	}

	/**
	 * Endpoint published by the main thread, if the helper is running.
	 * @return array{string, int, string}|null host, port, token
	 */
	public static function endpoint() : ?array{
		$endpoint = getenv(self::ENV_ENDPOINT);
		$token = getenv(self::ENV_TOKEN);
		if($endpoint === false || $endpoint === "" || $token === false){
			return null;
		}
		$pos = strrpos($endpoint, ":");
		if($pos === false){
			return null;
		}
		$port = (int) substr($endpoint, $pos + 1);
		return $port > 0 ? [substr($endpoint, 0, $pos), $port, $token] : null;
	}

	public function isRunning() : bool{
		return proc_get_status($this->process)["running"];
	}

	/**
	 * Closes the helper's stdin (it exits), then kills it if it does not exit within two seconds.
	 */
	public function stop() : void{
		putenv(self::ENV_ENDPOINT);
		if(is_resource($this->stdin)){
			@fclose($this->stdin);
		}
		if(!is_resource($this->process)){
			return;
		}
		$deadline = microtime(true) + 2;
		while($this->isRunning() && microtime(true) < $deadline){
			usleep(20_000);
		}
		if($this->isRunning()){
			proc_terminate($this->process);
		}
		proc_close($this->process);
		if(self::$instance === $this){
			self::$instance = null;
		}
	}
}
