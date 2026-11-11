<?php

/*
 * Adapted from VanillaMine's pocketmine\world\weather\Weather.
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace pocketmine\world\weather;

use pocketmine\event\world\WeatherChangeEvent;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function count;
use function max;
use function min;
use function mt_rand;

/**
 * First VanillaMine weather slice: independent per-world rain/thunder timers, persistence and
 * client synchronization. Physical lightning, precipitation and snow accumulation are deliberately
 * not yet enabled; they will be ported separately after block/mob integration.
 */
final class Weather{
	public const CLEAR_MIN_TICKS = 12000;
	public const CLEAR_MAX_TICKS = 180000;
	public const RAIN_MIN_TICKS = 12000;
	public const RAIN_MAX_TICKS = 24000;
	public const NO_THUNDER_MIN_TICKS = 12000;
	public const NO_THUNDER_MAX_TICKS = 180000;
	public const THUNDER_MIN_TICKS = 3600;
	public const THUNDER_MAX_TICKS = 15600;
	public const COMMAND_MIN_TICKS = 6000;
	public const COMMAND_MAX_TICKS = 18000;

	private const MIN_INTENSITY = 10000 / 65535;
	private const MAX_INTENSITY = 60000 / 65535;

	private int $rainTime;
	private float $rainLevel;
	private int $lightningTime;
	private float $lightningLevel;

	public function __construct(private World $world, WorldData $data){
		$this->rainTime = max(0, $data->getRainTime());
		$this->rainLevel = max(0.0, min(1.0, $data->getRainLevel()));
		$this->lightningTime = max(0, $data->getLightningTime());
		$this->lightningLevel = max(0.0, min(1.0, $data->getLightningLevel()));
	}

	public function save(WorldData $data) : void{
		$data->setRainTime($this->rainTime);
		$data->setRainLevel($this->rainLevel);
		$data->setLightningTime($this->lightningTime);
		$data->setLightningLevel($this->lightningLevel);
	}

	/** Nether and End worlds always report clear weather, even if old NBT holds rain values. */
	public function hasWeather() : bool{
		return $this->world->getServer()->getDimensionManager()->getDimension($this->world) === DimensionIds::OVERWORLD;
	}

	public function isRaining() : bool{
		return $this->rainLevel > 0.0 && $this->hasWeather();
	}

	public function isThundering() : bool{
		return $this->lightningLevel > 0.0 && $this->isRaining();
	}

	public function isClear() : bool{
		return !$this->isRaining();
	}

	public function getRainLevel() : float{
		return $this->isRaining() ? $this->rainLevel : 0.0;
	}

	public function getLightningLevel() : float{
		return $this->isThundering() ? $this->lightningLevel : 0.0;
	}

	public function getRainTime() : int{
		return $this->rainTime;
	}

	public function getLightningTime() : int{
		return $this->lightningTime;
	}

	public function isWeatherCycleEnabled() : bool{
		return $this->world->getGameRules()->getBool(GameRules::DO_WEATHER_CYCLE);
	}

	public function setWeatherCycleEnabled(bool $enabled) : void{
		$this->world->setGameRule(GameRules::DO_WEATHER_CYCLE, $enabled);
	}

	private static function randomIntensity() : float{
		return self::MIN_INTENSITY + Utils::getRandomFloat() * (self::MAX_INTENSITY - self::MIN_INTENSITY);
	}

	/** Returns false when a plugin cancels the pending transition. */
	private function change(bool $raining, bool $thundering) : bool{
		$wasRaining = $this->rainLevel > 0.0;
		$wasThundering = $this->lightningLevel > 0.0;
		if($wasRaining === $raining && $wasThundering === $thundering){
			return true;
		}

		$hasWeather = $this->hasWeather();
		$event = new WeatherChangeEvent(
			$this->world,
			$wasRaining && $hasWeather,
			$wasRaining && $wasThundering && $hasWeather,
			$raining,
			$raining && $thundering
		);
		$event->call();
		if($event->isCancelled()){
			return false;
		}

		if($raining !== $wasRaining){
			$this->rainLevel = $raining ? self::randomIntensity() : 0.0;
		}
		if($thundering !== $wasThundering){
			$this->lightningLevel = $thundering ? self::randomIntensity() : 0.0;
		}
		$this->broadcast();
		return true;
	}

	public function setClear(?int $duration = null) : bool{
		$duration ??= mt_rand(self::COMMAND_MIN_TICKS, self::COMMAND_MAX_TICKS);
		if(!$this->change(false, false)){
			return false;
		}
		$this->rainTime = max(1, $duration);
		$this->lightningTime = max(1, $duration);
		return true;
	}

	public function setRain(?int $duration = null) : bool{
		$duration ??= mt_rand(self::COMMAND_MIN_TICKS, self::COMMAND_MAX_TICKS);
		if(!$this->change(true, false)){
			return false;
		}
		$this->rainTime = max(1, $duration);
		$this->lightningTime = max(1, $duration);
		return true;
	}

	public function setThunder(?int $duration = null) : bool{
		$duration ??= mt_rand(self::COMMAND_MIN_TICKS, self::COMMAND_MAX_TICKS);
		if(!$this->change(true, true)){
			return false;
		}
		$this->rainTime = max(1, $duration);
		$this->lightningTime = max(1, $duration);
		return true;
	}

	public function resetCycle() : void{
		if($this->change(false, false)){
			$this->rainTime = 0;
			$this->lightningTime = 0;
		}
	}

	/** Tick the two independent Vanilla-style weather timers. */
	public function tick() : void{
		if(!$this->hasWeather() || !$this->isWeatherCycleEnabled()){
			return;
		}

		$raining = $this->rainLevel > 0.0;
		$thundering = $this->lightningLevel > 0.0;
		if($this->lightningTime <= 0){
			$this->lightningTime = $thundering ?
				mt_rand(self::THUNDER_MIN_TICKS, self::THUNDER_MAX_TICKS) :
				mt_rand(self::NO_THUNDER_MIN_TICKS, self::NO_THUNDER_MAX_TICKS);
		}elseif(--$this->lightningTime <= 0){
			$thundering = !$thundering;
		}

		if($this->rainTime <= 0){
			$this->rainTime = $raining ?
				mt_rand(self::RAIN_MIN_TICKS, self::RAIN_MAX_TICKS) :
				mt_rand(self::CLEAR_MIN_TICKS, self::CLEAR_MAX_TICKS);
		}elseif(--$this->rainTime <= 0){
			$raining = !$raining;
		}

		if($raining !== ($this->rainLevel > 0.0) || $thundering !== ($this->lightningLevel > 0.0)){
			$this->change($raining, $thundering);
		}
	}

	/** Broadcast through each player's own version-aware Bedrock session. */
	public function broadcast(Player ...$targets) : void{
		if(count($targets) === 0){
			$targets = $this->world->getPlayers();
		}
		$rain = $this->getRainLevel();
		$thunder = $this->getLightningLevel();
		foreach($targets as $player){
			$player->getNetworkSession()->syncWorldWeather($rain, $thunder);
		}
	}
}
