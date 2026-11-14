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

use pocketmine\network\NetworkInterfaceStartException;
use pocketmine\utils\Utils;
use function array_merge;
use function clearstatcache;
use function fclose;
use function file_exists;
use function file_get_contents;
use function filesize;
use function getenv;
use function is_file;
use function is_resource;
use function max;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function strlen;
use function substr;
use function trim;
use function usleep;

/**
 * The pm-nethernet bridge child process. Its stdout and stderr go to a log file; its stdin is a pipe that is closed
 * when the server stops (the bridge exits when stdin is closed, so it never outlives a crashed server).
 */
final class NetherNetProcess{
	/** @var resource|null */
	private $process = null;
	/** @var resource|null */
	private $stdin = null;
	private ?int $exitCode = null;
	private int $logOffset = 0;

	/**
	 * @param string[] $arguments
	 * @phpstan-param list<string> $arguments
	 * @param string[] $environment extra environment variables of the bridge (an empty value removes the variable)
	 * @phpstan-param array<string, string> $environment
	 */
	public function __construct(
		private string $binary,
		private array $arguments,
		private string $token,
		private string $logFile,
		private array $environment = []
	){}

	/**
	 * @throws NetworkInterfaceStartException
	 */
	public function start() : void{
		if(!is_file($this->binary)){
			throw new NetworkInterfaceStartException("bridge program not found at " . $this->binary);
		}
		clearstatcache();
		$this->logOffset = file_exists($this->logFile) ? (int) filesize($this->logFile) : 0;

		$env = getenv();
		foreach(Utils::stringifyKeys($this->environment) as $name => $value){
			if($value === ""){
				unset($env[$name]);
			}else{
				$env[$name] = $value;
			}
		}
		$env["PM_NETHERNET_TOKEN"] = $this->token;
		$pipes = [];
		$process = @proc_open(
			array_merge([$this->binary], $this->arguments),
			[
				0 => ["pipe", "r"],
				1 => ["file", $this->logFile, "a"],
				2 => ["file", $this->logFile, "a"],
			],
			$pipes,
			null,
			$env
		);
		if(!is_resource($process)){
			throw new NetworkInterfaceStartException("could not start " . $this->binary);
		}
		$this->process = $process;
		$this->stdin = $pipes[0] ?? null;
		$this->exitCode = null;
	}

	public function isRunning() : bool{
		if($this->process === null){
			return false;
		}
		if($this->exitCode !== null){
			return false;
		}
		$status = proc_get_status($this->process);
		if($status["running"]){
			return true;
		}
		$this->exitCode = $status["exitcode"];
		return false;
	}

	public function getExitCode() : int{
		$this->isRunning();
		return $this->exitCode ?? -1;
	}

	/**
	 * Returns the output the bridge wrote since it was started (at most the last 2 KB).
	 */
	public function getLogTail() : string{
		clearstatcache();
		if(!file_exists($this->logFile)){
			return "";
		}
		$data = @file_get_contents($this->logFile, false, null, $this->logOffset);
		if($data === false){
			return "";
		}
		return trim(substr($data, max(0, strlen($data) - 2048)));
	}

	/**
	 * Asks the bridge to exit by closing its stdin, then kills it if it does not exit within two seconds.
	 */
	public function stop() : void{
		if($this->stdin !== null){
			@fclose($this->stdin);
			$this->stdin = null;
		}
		if($this->process === null){
			return;
		}
		$deadline = microtime(true) + 2;
		while($this->isRunning() && microtime(true) < $deadline){
			usleep(20_000);
		}
		if($this->isRunning()){
			proc_terminate($this->process);
		}
		$this->close();
	}

	/**
	 * Releases the process handle. Only call this once the process has exited or was terminated.
	 */
	public function close() : void{
		if($this->stdin !== null){
			@fclose($this->stdin);
			$this->stdin = null;
		}
		if($this->process !== null){
			proc_close($this->process);
			$this->process = null;
		}
	}
}
