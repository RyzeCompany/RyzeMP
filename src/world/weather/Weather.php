<?php

/*
 * Adapted from VanillaMine's pocketmine\world\weather\Weather.
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace pocketmine\world\weather;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Leaves;
use pocketmine\block\LightningRod;
use pocketmine\block\Liquid;
use pocketmine\block\SnowLayer;
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\SupportType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\math\Facing;
use pocketmine\world\format\Chunk;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\event\world\WeatherChangeEvent;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\LightningBolt;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
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
use const PHP_INT_MAX;

/**
 * Vanilla-style weather timers, biome-aware precipitation and snow/ice.
 * Lightning actor mechanics require separate multiversion validation.
 */
class Weather{
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

	/** One column in 16 per simulated chunk tick; never loads chunks. */
	public const PRECIPITATION_CHANCE = 16;
	/** Natural strike probability per simulated chunk tick during a thunderstorm. */
	public const LIGHTNING_CHANCE = 100000;
	/** Vanilla rod attraction radius in horizontal blocks. */
	public const LIGHTNING_ROD_RANGE = 64;

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


	/**
	 * Lowest height reached by precipitation in an already loaded column.
	 * Scans at most 8 pass-through blocks without loading or generating chunks.
	 */
	public function getPrecipitationHeight(int $x, int $z) : ?int{
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return null;
		}
		$highest = $chunk->getHighestBlockAt($x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK);
		if($highest === null){
			return $this->world->getMinY();
		}
		$y = $highest;
		for($i = 0; $y >= $this->world->getMinY() && $i < 8; --$y, ++$i){
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block->isSolid() || $block instanceof Liquid){
				return $y + 1;
			}
		}
		return $y + 1;
	}

	public function isExposedToSky(int $x, int $y, int $z) : bool{
		$top = $this->getPrecipitationHeight($x, $z);
		return $top !== null && $y >= $top;
	}

	public function getPrecipitationAt(int $x, int $y, int $z) : Precipitation{
		if(!$this->hasWeather() || !$this->world->isInWorld($x, $y, $z)){
			return Precipitation::NONE;
		}
		return BiomeClimate::getPrecipitation($this->world->getBiomeId($x, $y, $z), $y);
	}

	public function isRainingAt(int $x, int $y, int $z) : bool{
		return $this->isRaining() &&
			$this->isExposedToSky($x, $y, $z) &&
			$this->getPrecipitationAt($x, $y, $z) === Precipitation::RAIN;
	}

	/** @internal Called by World only for an already simulated chunk. */
	public function tickChunk(int $chunkX, int $chunkZ) : void{
		if(!$this->hasWeather()){
			return;
		}
		if(mt_rand(0, self::PRECIPITATION_CHANCE - 1) === 0){
			$this->tickPrecipitationInChunk($chunkX, $chunkZ);
		}
		if($this->isThundering() && mt_rand(0, self::LIGHTNING_CHANCE - 1) === 0){
			$this->tryNaturalStrike(
				($chunkX << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1),
				($chunkZ << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1)
			);
		}
	}

	public function tickPrecipitationInChunk(int $chunkX, int $chunkZ) : void{
		$this->tickPrecipitation(
			($chunkX << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1),
			($chunkZ << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1)
		);
	}

	/** Ice formation is independent of rainfall; snow layers require rain state. */
	public function tickPrecipitation(int $x, int $z) : void{
		if(!$this->hasWeather()){
			return;
		}
		$top = $this->getPrecipitationHeight($x, $z);
		if($top === null || $top <= $this->world->getMinY() || $top >= $this->world->getMaxY()){
			return;
		}
		$belowY = $top - 1;
		$biome = $this->world->getBiomeId($x, $belowY, $z);
		if(!BiomeClimate::hasPrecipitation($biome)){
			return;
		}
		$below = $this->world->getBlockAt($x, $belowY, $z);
		if(BiomeClimate::isCold($biome, $belowY) &&
			$below instanceof Water && $below->isSource() &&
			$this->world->getBlockLightAt($x, $belowY, $z) < 10
		){
			BlockEventHelper::form($below, VanillaBlocks::ICE(), $below);
			return;
		}
		if(!$this->isRaining()){
			return;
		}
		if(BiomeClimate::getPrecipitation($biome, $top) === Precipitation::SNOW){
			$this->accumulateSnow($x, $top, $z, $below, $biome);
		}
		$fall = BiomeClimate::getPrecipitation($biome, $belowY);
		if($fall !== Precipitation::NONE){
			//The uppermost pass-through block (e.g. an open cauldron) also receives rain.
			$this->world->getBlockAt($x, $top, $z)->onPrecipitation($fall);
			$below->onPrecipitation($fall);
		}
	}

	private function accumulateSnow(int $x, int $top, int $z, Block $below, int $biome) : void{
		$y = $top;
		$current = $this->world->getBlockAt($x, $y, $z);
		$depth = 0;
		if($current instanceof SnowLayer && $current->getLayers() === SnowLayer::MAX_LAYERS){
			$depth = SnowLayer::MAX_LAYERS;
			if(++$y >= $this->world->getMaxY()){
				return;
			}
			$current = $this->world->getBlockAt($x, $y, $z);
		}
		if($current instanceof SnowLayer){
			$layers = $current->getLayers() + 1;
			if($layers > SnowLayer::MAX_LAYERS){
				return;
			}
		}elseif($current->getTypeId() === BlockTypeIds::AIR &&
			($depth > 0 || $below instanceof Leaves || $below->getSupportType(Facing::UP) === SupportType::FULL)
		){
			$layers = 1;
		}else{
			return;
		}
		if($depth + $layers > BiomeClimate::getSnowAccumulationLayers($biome) ||
			$this->world->getBlockLightAt($x, $y, $z) >= 10
		){
			return;
		}
		BlockEventHelper::form($current, VanillaBlocks::SNOW_LAYER()->setLayers($layers), $current);
	}


	/** Spawn a cancellable, short-lived lightning actor from a plugin or server system. */
	public function strike(Vector3 $position, bool $visualOnly = false, int $cause = LightningStrikeEvent::CAUSE_CUSTOM) : ?LightningBolt{
		return LightningBolt::strike(Location::fromObject($position, $this->world), $visualOnly, $cause);
	}

	/** Attempts a natural strike in a previously loaded, simulated chunk. */
	public function tryNaturalStrike(int $x, int $z) : ?LightningBolt{
		if(!$this->isThundering()){
			return null;
		}
		$target = $this->findLightningTarget($x, $z);
		if($target === null || !$this->isExposedToSky($target->getFloorX(), $target->getFloorY(), $target->getFloorZ()) ||
			$this->getPrecipitationAt($target->getFloorX(), $target->getFloorY(), $target->getFloorZ()) === Precipitation::NONE
		){
			return null;
		}
		return $this->strike($target->add(0.5, 0.0, 0.5), false, LightningStrikeEvent::CAUSE_WEATHER);
	}

	/** A lightning rod has priority over exposed living entities or the surface of the original column. */
	public function findLightningTarget(int $x, int $z) : ?Vector3{
		$top = $this->getPrecipitationHeight($x, $z);
		if($top === null){
			return null;
		}
		$rod = $this->findLightningRod($x, $top, $z);
		if($rod !== null){
			return $rod->up();
		}
		$box = new AxisAlignedBB($x - 3, $top - 3, $z - 3, $x + 4, $this->world->getMaxY() + 3, $z + 4);
		$entities = [];
		foreach($this->world->getNearbyEntities($box) as $entity){
			if($entity instanceof Living && $entity->isAlive()){
				$pos = $entity->getPosition();
				if($this->isExposedToSky($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
					$entities[] = $pos;
				}
			}
		}
		if(count($entities) > 0){
			$pos = $entities[mt_rand(0, count($entities) - 1)];
			return new Vector3($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		}
		return new Vector3($x, $top, $z);
	}

	/**
	 * Scans only loaded columns for the top-most lightning rods in the 64-block horizontal radius.
	 * Called on attempted strikes, never on every tick. Does not generate or load terrain.
	 */
	public function findLightningRod(int $x, int $y, int $z) : ?Vector3{
		$range = self::LIGHTNING_ROD_RANGE;
		$bestDistance = PHP_INT_MAX;
		$best = null;
		for($cx = ($x - $range) >> Chunk::COORD_BIT_SIZE; $cx <= (($x + $range) >> Chunk::COORD_BIT_SIZE); ++$cx){
			for($cz = ($z - $range) >> Chunk::COORD_BIT_SIZE; $cz <= (($z + $range) >> Chunk::COORD_BIT_SIZE); ++$cz){
				$chunk = $this->world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				for($bx = 0; $bx < Chunk::EDGE_LENGTH; ++$bx){
					$wx = ($cx << Chunk::COORD_BIT_SIZE) + $bx;
					for($bz = 0; $bz < Chunk::EDGE_LENGTH; ++$bz){
						$wz = ($cz << Chunk::COORD_BIT_SIZE) + $bz;
						$horizontalSq = ($wx - $x) ** 2 + ($wz - $z) ** 2;
						if($horizontalSq > $range ** 2){
							continue;
						}
						$wy = $chunk->getHighestBlockAt($bx, $bz);
						if($wy === null){
							continue;
						}
						$distance = $horizontalSq + ($wy - $y) ** 2;
						if($distance >= $bestDistance){
							continue;
						}
						if($this->world->getBlockAt($wx, $wy, $wz) instanceof LightningRod){
							$bestDistance = $distance;
							$best = new Vector3($wx, $wy, $wz);
						}
					}
				}
			}
		}
		return $best;
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
