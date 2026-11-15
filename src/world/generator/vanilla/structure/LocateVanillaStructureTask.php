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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\scheduler\AsyncTask;
use pocketmine\world\locate\LocateTimeoutException;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_int;
use const PHP_INT_MAX;

/**
 * Searches the closest start of a structure of a vanilla world on a /locate worker (see
 * {@link \pocketmine\world\locate\LocateWorkerPool}): structure positions only depend on the seed and the generator
 * settings, so the worker builds its own structure manager (kept for the next searches of the same world).
 *
 * The result passed to the callback is [x, y, z, start chunk x, start chunk z], null if nothing was found, or
 * {@link RESULT_TIMEOUT}.
 */
final class LocateVanillaStructureTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "onCompletion";
	public const RESULT_TIMEOUT = "timeout";

	/** Structure manager of the last world searched on this worker thread. */
	private static ?StructureManager $manager = null;
	private static string $managerKey = "";

	private string $patterns;
	private string $excludedStarts;

	/**
	 * @param string[] $patterns       Java structure ids or patterns (see {@link VanillaStructureFinder::getPatterns()})
	 * @param string[] $excludedStarts start chunks to skip, as "chunkX:chunkZ"
	 * @phpstan-param list<string> $patterns
	 * @phpstan-param list<string> $excludedStarts
	 * @phpstan-param \Closure(array{int, int, int, int, int}|string|null $result, bool $failed) : void $onCompletion
	 */
	public function __construct(
		private string $generatorClass,
		private int $seed,
		private string $generatorOptions,
		array $patterns,
		private int $originX,
		private int $originZ,
		private int $radiusChunks,
		array $excludedStarts,
		private int $timeoutSeconds,
		\Closure $onCompletion
	){
		$this->patterns = implode(",", $patterns);
		$this->excludedStarts = implode(",", $excludedStarts);
		$this->storeLocal(self::TLS_KEY_ON_COMPLETION, $onCompletion);
	}

	/**
	 * Structure manager of a world on this worker thread, kept for the next searches of the same world (null if the
	 * world has no vanilla structures).
	 */
	public static function getWorkerStructureManager(string $generatorClass, int $seed, string $generatorOptions) : ?StructureManager{
		$key = $generatorClass . ":" . $seed . ":" . $generatorOptions;
		if(self::$managerKey !== $key){
			self::$manager = null; //free the previous world's caches first
			self::$manager = VanillaStructureLocator::createStructureManager($generatorClass, $seed, $generatorOptions);
			self::$managerKey = $key;
		}
		return self::$manager;
	}

	public function onRun() : void{
		$deadline = LocateTimeoutException::deadlineIn($this->timeoutSeconds);
		self::getWorkerStructureManager($this->generatorClass, $this->seed, $this->generatorOptions);
		if(self::$manager === null){
			$this->setResult(null);
			return;
		}
		$excluded = [];
		if($this->excludedStarts !== ""){
			foreach(explode(",", $this->excludedStarts, PHP_INT_MAX) as $start){
				$excluded[$start] = true;
			}
		}
		$accept = count($excluded) > 0 ? fn(int $chunkX, int $chunkZ) : bool => !isset($excluded["$chunkX:$chunkZ"]) : null;
		try{
			$found = VanillaStructureFinder::searchPatterns(self::$manager, explode(",", $this->patterns, PHP_INT_MAX), $this->originX, $this->originZ, $this->radiusChunks, $accept, $deadline);
		}catch(LocateTimeoutException){
			$this->setResult(self::RESULT_TIMEOUT);
			return;
		}
		$this->setResult($found !== null ? [$found[3], $found[4], $found[5], $found[1], $found[2]] : null);
	}

	public function onCompletion() : void{
		/** @phpstan-var \Closure(array{int, int, int, int, int}|string|null $result, bool $failed) : void $callback */
		$callback = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);
		$result = $this->getResult();
		if(is_array($result) && count($result) === 5 && is_int($result[0]) && is_int($result[1]) && is_int($result[2]) && is_int($result[3]) && is_int($result[4])){
			$callback([$result[0], $result[1], $result[2], $result[3], $result[4]], false);
		}else{
			$callback($result === self::RESULT_TIMEOUT ? self::RESULT_TIMEOUT : null, false);
		}
	}

	public function onError() : void{
		/** @phpstan-var \Closure(array{int, int, int, int, int}|string|null $result, bool $failed) : void $callback */
		$callback = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);
		$callback(null, true);
	}
}
