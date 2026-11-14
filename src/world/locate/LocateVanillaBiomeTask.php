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

namespace pocketmine\world\locate;

use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\generator\vanilla\biome\VanillaBiomeSource;
use pocketmine\world\generator\vanilla\structure\LocateVanillaStructureTask;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\WorldgenData;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use const PHP_INT_MAX;

/**
 * Searches the closest biome of a vanilla-generated world on an async worker, sampling the climate noise directly
 * (no chunk is generated). The result is the matching block position, or null if none within the radius.
 */
final class LocateVanillaBiomeTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "onCompletion";
	private const RESULT_TIMEOUT = "timeout";

	private string $biomes;

	/**
	 * @param string[] $biomeNames Java Edition biome ids (without namespace) to look for
	 * @phpstan-param list<string> $biomeNames
	 * @phpstan-param \Closure(?Vector3 $position, bool $failed, bool $timedOut) : void $onCompletion
	 * @param int $timeoutSeconds the search gives up after this time (0 = never)
	 */
	public function __construct(
		private string $dataDir,
		private int $seed,
		private string $dimension,
		array $biomeNames,
		private int $originX,
		private int $originY,
		private int $originZ,
		private int $radius,
		\Closure $onCompletion,
		private int $timeoutSeconds = 0,
		private string $generatorClass = "",
		private string $generatorOptions = ""
	){
		$this->biomes = implode(",", $biomeNames);
		$this->storeLocal(self::TLS_KEY_ON_COMPLETION, $onCompletion);
	}

	/**
	 * @phpstan-param list<string> $biomeNames
	 * @throws LocateTimeoutException if the hrtime() deadline passes
	 */
	public static function search(VanillaBiomeSource $source, array $biomeNames, int $originX, int $originY, int $originZ, int $radius, ?int $deadlineNs = null) : ?Vector3{
		$wanted = [];
		foreach($source->getBiomeNames() as $index => $name){
			if(in_array($name, $biomeNames, true)){
				$wanted[$index] = true;
			}
		}
		if($wanted === []){
			return null; //not generated in this dimension
		}
		$ys = BiomeSearch::sampleHeights($originY, $source->getMinY(), $source->getMaxY(), BiomeSearch::DEFAULT_VERTICAL_STEP);
		return BiomeSearch::find(function(int $x, int $z, array $ys) use ($source, $wanted) : ?int{
			foreach($source->getBiomesInColumn($x, $z, $ys) as $i => $biome){
				if(isset($wanted[$biome])){
					return $ys[$i];
				}
			}
			return null;
		}, $originX, $originZ, $ys, $radius, deadlineNs: $deadlineNs);
	}

	public function onRun() : void{
		$deadline = LocateTimeoutException::deadlineIn($this->timeoutSeconds);
		$source = new VanillaBiomeSource(WorldgenData::shared($this->dataDir), $this->seed, $this->dimension, $this->dataDir . "/compiled");
		try{
			$found = self::search($source, explode(",", $this->biomes, PHP_INT_MAX), $this->originX, $this->originY, $this->originZ, $this->radius, $deadline);
		}catch(LocateTimeoutException){
			$this->setResult(self::RESULT_TIMEOUT);
			return;
		}
		if($found === null){
			$this->setResult(null);
			return;
		}
		$y = $found->getFloorY();
		if($this->dimension === "overworld" && $this->generatorClass !== ""){
			//BDS 1.26.60 reports the surface of the column (plains at 69, taiga at 64), not the height the biome was found at
			$manager = LocateVanillaStructureTask::getWorkerStructureManager($this->generatorClass, $this->seed, $this->generatorOptions);
			if($manager !== null){
				$y = $manager->env->terrain->getFirstFreeHeight($found->getFloorX(), $found->getFloorZ(), TerrainSampler::WORLD_SURFACE_WG);
			}
		}
		$this->setResult([$found->getFloorX(), $y, $found->getFloorZ()]);
	}

	public function onCompletion() : void{
		/** @phpstan-var \Closure(?Vector3 $position, bool $failed, bool $timedOut) : void $callback */
		$callback = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);
		$result = $this->getResult();
		$position = null;
		if(is_array($result) && is_int($result[0] ?? null) && is_int($result[1] ?? null) && is_int($result[2] ?? null)){
			$position = new Vector3($result[0], $result[1], $result[2]);
		}
		$callback($position, false, $result === self::RESULT_TIMEOUT);
	}

	public function onError() : void{
		/** @phpstan-var \Closure(?Vector3 $position, bool $failed, bool $timedOut) : void $callback */
		$callback = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);
		$callback(null, true, false);
	}
}
