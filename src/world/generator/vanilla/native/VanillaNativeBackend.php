<?php
/*
 * RyzeMP native process manager adapted from VanillaMine (LGPL-3.0-or-later)
 * pinned revision 4dd2228b47d64c8015c28138c846e6c4acb28345.
 *
 * Opt-in: set RYZEMP_VANILLAGEN_BINARY to an ABSOLUTE executable path.
 * The Go process listens on ephemeral 127.0.0.1 only, never on the server port.
 */
declare(strict_types=1);

namespace pocketmine\world\generator\vanilla\native;

use pocketmine\Server;
use function bin2hex;
use function clearstatcache;
use function fclose;
use function file_get_contents;
use function file_exists;
use function getenv;
use function hash_equals;
use function hash_file;
use function is_executable;
use function is_file;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function putenv;
use function random_bytes;
use function register_shutdown_function;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

final class VanillaNativeBackend{
	public const PROTOCOL_VERSION = 7;
	public const ALGORITHM_VERSION = 4;
	public const ENV_ENDPOINT = "PM_VANILLAGEN_ENDPOINT";
	public const ENV_TOKEN = "PM_VANILLAGEN_TOKEN";

	private static ?self $instance = null;
	private static bool $attempted = false;

	/** @param resource $process @param resource $stdin */
	private function __construct(
		private $process,
		private $stdin,
		public readonly string $binary,
		public readonly int $port,
		public readonly string $token
	){}

	/**
	 * Launch once on the main server thread, BEFORE async workers are started.
	 * No variable set: PHP remains the default and no Go process is started.
	 */
	public static function prepareForServer() : void{
		if(self::$attempted){
			return;
		}
		self::$attempted = true;
		$path = getenv("RYZEMP_VANILLAGEN_BINARY");
		if($path === false || $path === ""){
			return;
		}
		$server = Server::getInstance();
		$logger = $server->getLogger();
		if(!str_starts_with($path, "/") || !is_file($path) || !is_executable($path)){
			$logger->warning("[Worldgen Go] RYZEMP_VANILLAGEN_BINARY must be an absolute path to an executable, using PHP");
			return;
		}
		$sha = getenv("RYZEMP_VANILLAGEN_SHA256");
		if($sha !== false && $sha !== ""){
			$actual = hash_file("sha256", $path);
			if($actual === false || !hash_equals($sha, $actual)){
				$logger->warning("[Worldgen Go] Binary SHA-256 mismatch, using PHP");
				return;
			}
		}
		try{
			$backend = self::start($path, $server->getDataPath() . "vanillagen.log");
			$backend->publish();
			self::$instance = $backend;
			register_shutdown_function(static fn() => $backend->stop());
			$logger->info("[Worldgen Go] vanillagen ready on loopback (protocol 7, algorithm 4). PHP fallback available.");
		}catch(NativeBackendException $e){
			$logger->warning("[Worldgen Go] " . $e->getMessage() . "; using PHP");
		}
	}

	/**
	 * Start a pinned, locally installed executable and wait until ready.
	 * @throws NativeBackendException
	 */
	public static function start(string $binary, string $logFile, float $timeout = 10.0) : self{
		if(!is_file($binary) || !is_executable($binary)){
			throw new NativeBackendException("Go binary missing or not executable");
		}
		$token = bin2hex(random_bytes(32));
		$portFile = sys_get_temp_dir() . "/ryzemp-vanillagen-" . bin2hex(random_bytes(12)) . ".port";
		$env = getenv();
		if(!is_array($env)){
			$env = [];
		}
		$env[self::ENV_TOKEN] = $token;
		$pipes = [];
		$proc = @proc_open([$binary, "-listen=127.0.0.1:0", "-port-file=" . $portFile, "-watch-stdin"],
			[0 => ["pipe", "r"], 1 => ["file", $logFile, "a"], 2 => ["file", $logFile, "a"]], $pipes, null, $env);
		if(!is_resource($proc) || !isset($pipes[0])){
			throw new NativeBackendException("Could not start vanillagen with proc_open");
		}
		$stdin = $pipes[0];
		$deadline = microtime(true) + $timeout;
		while(true){
			clearstatcache();
			if(is_file($portFile)){
				$raw = file_get_contents($portFile);
				@unlink($portFile);
				$port = (int) trim($raw !== false ? $raw : "");
				if($port > 0 && $port <= 65535){
					return new self($proc, $stdin, $binary, $port, $token);
				}
				break;
			}
			$status = proc_get_status($proc);
			if(!$status["running"] || microtime(true) >= $deadline){
				break;
			}
			usleep(10000);
		}
		@fclose($stdin);
		proc_terminate($proc);
		proc_close($proc);
		throw new NativeBackendException("Go binary did not become ready (see vanillagen.log)");
	}

	public function publish() : void{
		putenv(self::ENV_ENDPOINT . "=127.0.0.1:" . $this->port);
		putenv(self::ENV_TOKEN . "=" . $this->token);
	}

	/** @return array{string, int, string}|null */
	public static function endpoint() : ?array{
		$address = getenv(self::ENV_ENDPOINT);
		$token = getenv(self::ENV_TOKEN);
		if($address === false || $token === false || $address === "" || $token === ""){
			return null;
		}
		if(!preg_match('/^127\.0\.0\.1:([0-9]{1,5})$/D', $address, $match)){
			return null;
		}
		$port = (int) $match[1];
		return $port >= 1 && $port <= 65535 ? ["127.0.0.1", $port, $token] : null;
	}

	public function stop() : void{
		putenv(self::ENV_ENDPOINT);
		putenv(self::ENV_TOKEN);
		if(is_resource($this->stdin)){
			@fclose($this->stdin);
		}
		if(!is_resource($this->process)){
			return;
		}
		$deadline = microtime(true) + 2;
		while(proc_get_status($this->process)["running"] && microtime(true) < $deadline){
			usleep(20000);
		}
		if(proc_get_status($this->process)["running"]){
			proc_terminate($this->process);
		}
		proc_close($this->process);
		if(self::$instance === $this){
			self::$instance = null;
		}
	}

	public static function getInstance() : ?self{
		return self::$instance;
	}
}
