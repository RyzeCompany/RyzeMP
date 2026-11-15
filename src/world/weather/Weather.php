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

namespace pocketmine\world\weather;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Leaves;
use pocketmine\block\Liquid;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\SnowLayer;
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\SupportType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\event\world\WeatherChangeEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\WeatherCycleWorldData;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function array_keys;
use function count;
use function max;
use function min;
use function mt_rand;
use const PHP_INT_MAX;

/**
 * Weather of a world (Overworld only): clear, rain (snow in cold biomes) and thunderstorms, with the vanilla cycle.
 *
 * State (level.dat, Bedrock tags): rainLevel and lightningLevel are the current intensities (0 = off; the intensity
 * is what clients get in the START_RAIN/START_THUNDER level events), rainTime and lightningTime the ticks left until
 * the rain and the thunder toggle. Like vanilla, the timers run independently and thunder only happens while it
 * rains. A timer at 0 first draws the duration of the current state: clear 12000-180000 ticks, rain 12000-24000,
 * no thunder 12000-180000, thunder 3600-15600. The cycle can be turned off (doweathercycle).
 *
 * Effects handled here, per ticked chunk (so they follow the random tick budget): 1/16 chance per tick to pick a
 * random column, where water freezes and snow settles in cold biomes and the top block gets the precipitation
 * ({@link Block::onPrecipitation()}: cauldrons fill); and in a thunderstorm a 1/100000 chance per tick of a lightning
 * strike, aimed at a lightning rod (within {@link Weather::LIGHTNING_ROD_RANGE} blocks, top of its column) or at a
 * living entity under the open sky near the column, with a chance of a skeleton trap horse instead.
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
	/** Default duration of /weather without one (Bedrock: 300-900 seconds). */
	public const COMMAND_MIN_TICKS = 6000;
	public const COMMAND_MAX_TICKS = 18000;

	/** Intensity range of a new rain or thunderstorm (as a fraction of the 65535 sent to clients). */
	private const MIN_INTENSITY = 10000 / 65535;
	private const MAX_INTENSITY = 60000 / 65535;

	/** One strike per this many ticks per loaded chunk near a player in a thunderstorm (vanilla). */
	public const LIGHTNING_CHANCE = 100000;
	/** Distance in blocks from a player within which loaded chunks get natural lightning (~200 chunks, vanilla). */
	public const LIGHTNING_RANGE = 128;
	/** Chance per ticked chunk per tick of a precipitation update on a random column (vanilla). */
	public const PRECIPITATION_CHANCE = 16;
	/** Horizontal range in which lightning rods attract a natural strike. */
	public const LIGHTNING_ROD_RANGE = 64;
	/** Change of the sky darkening per tick while the weather fades in or out (vanilla). */
	private const STRENGTH_STEP = 0.01;

	private int $rainTime;
	private float $rainLevel;
	private int $lightningTime;
	private float $lightningLevel;
	private bool $cycle;

	/** Current darkening of the sky by rain and thunder, 0-1, fading like vanilla. */
	private float $rainStrength;
	private float $thunderStrength;

	/**
	 * @var true[]|null state IDs of lightning rods
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $lightningRodStates = null;

	public function __construct(
		private World $world,
		WorldData $data
	){
		$this->rainTime = max(0, $data->getRainTime());
		$this->rainLevel = max(0.0, min(1.0, $data->getRainLevel()));
		$this->lightningTime = max(0, $data->getLightningTime());
		$this->lightningLevel = max(0.0, min(1.0, $data->getLightningLevel()));
		$this->cycle = !$data instanceof WeatherCycleWorldData || $data->isWeatherCycleEnabled();
		$this->rainStrength = $this->isRaining() ? 1.0 : 0.0;
		$this->thunderStrength = $this->isThundering() ? 1.0 : 0.0;
	}

	public function save(WorldData $data) : void{
		$data->setRainTime($this->rainTime);
		$data->setRainLevel($this->rainLevel);
		$data->setLightningTime($this->lightningTime);
		$data->setLightningLevel($this->lightningLevel);
		if($data instanceof WeatherCycleWorldData){
			$data->setWeatherCycleEnabled($this->cycle);
		}
	}

	/**
	 * Whether the world has weather at all (only the Overworld: no rain in the Nether or the End).
	 */
	public function hasWeather() : bool{
		return $this->world->getDimension() === Dimension::OVERWORLD;
	}

	/**
	 * Whether it rains (or snows) in the world. Dry biomes stay dry: see {@link Weather::isRainingAt()}.
	 */
	public function isRaining() : bool{
		return $this->rainLevel > 0 && $this->hasWeather();
	}

	/**
	 * Whether there is a thunderstorm (rain with thunder).
	 */
	public function isThundering() : bool{
		return $this->lightningLevel > 0 && $this->isRaining();
	}

	public function isClear() : bool{
		return !$this->isRaining();
	}

	/** Rain intensity sent to clients (0 when not raining). */
	public function getRainLevel() : float{ return $this->isRaining() ? $this->rainLevel : 0.0; }

	/** Thunder intensity sent to clients (0 without a thunderstorm). */
	public function getLightningLevel() : float{ return $this->isThundering() ? $this->lightningLevel : 0.0; }

	/** Ticks until the rain starts or stops. */
	public function getRainTime() : int{ return $this->rainTime; }

	/** Ticks until the thunder starts or stops. */
	public function getLightningTime() : int{ return $this->lightningTime; }

	public function isWeatherCycleEnabled() : bool{ return $this->cycle; }

	/**
	 * Turns the natural weather cycle on or off (Bedrock gamerule doweathercycle). The current weather stays.
	 */
	public function setWeatherCycleEnabled(bool $enabled) : void{
		$this->cycle = $enabled;
	}

	/** Darkening of the sky by rain, 0-1 (fades in and out over 100 ticks). */
	public function getRainStrength() : float{ return $this->rainStrength; }

	/** Darkening of the sky by thunder, 0-1. */
	public function getThunderStrength() : float{ return $this->thunderStrength; }

	private static function randomIntensity() : float{
		return self::MIN_INTENSITY + Utils::getRandomFloat() * (self::MAX_INTENSITY - self::MIN_INTENSITY);
	}

	/**
	 * Changes the weather. Returns false if a plugin cancelled the change.
	 */
	private function change(bool $raining, bool $thundering) : bool{
		$wasRaining = $this->rainLevel > 0;
		$wasThundering = $this->lightningLevel > 0;
		if($wasRaining === $raining && $wasThundering === $thundering){
			return true;
		}
		$ev = new WeatherChangeEvent($this->world, $wasRaining && $this->hasWeather(), $wasRaining && $wasThundering && $this->hasWeather(), $raining, $raining && $thundering);
		$ev->call();
		if($ev->isCancelled()){
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

	/**
	 * Clear weather for the given number of ticks (random 6000-18000 if null), like /weather clear. Returns false if
	 * a plugin cancelled it.
	 */
	public function setClear(?int $duration = null) : bool{
		$duration ??= mt_rand(self::COMMAND_MIN_TICKS, self::COMMAND_MAX_TICKS);
		if(!$this->change(false, false)){
			return false;
		}
		$this->rainTime = max(1, $duration);
		$this->lightningTime = max(1, $duration);
		return true;
	}

	/**
	 * Rain without thunder for the given number of ticks, like /weather rain.
	 */
	public function setRain(?int $duration = null) : bool{
		$duration ??= mt_rand(self::COMMAND_MIN_TICKS, self::COMMAND_MAX_TICKS);
		if(!$this->change(true, false)){
			return false;
		}
		$this->rainTime = max(1, $duration);
		$this->lightningTime = max(1, $duration);
		return true;
	}

	/**
	 * A thunderstorm for the given number of ticks, like /weather thunder.
	 */
	public function setThunder(?int $duration = null) : bool{
		$duration ??= mt_rand(self::COMMAND_MIN_TICKS, self::COMMAND_MAX_TICKS);
		if(!$this->change(true, true)){
			return false;
		}
		$this->rainTime = max(1, $duration);
		$this->lightningTime = max(1, $duration);
		return true;
	}

	/**
	 * Stops the rain and the thunder and restarts both timers, like waking up after sleeping through the night.
	 */
	public function resetCycle() : void{
		if($this->change(false, false)){
			$this->rainTime = 0;
			$this->lightningTime = 0;
		}
	}

	/**
	 * @internal Called by the world every tick.
	 */
	public function tick() : void{
		if(!$this->hasWeather()){
			return;
		}
		if($this->cycle){
			$raining = $this->rainLevel > 0;
			$thundering = $this->lightningLevel > 0;
			if($this->lightningTime <= 0){
				$this->lightningTime = $thundering ? mt_rand(self::THUNDER_MIN_TICKS, self::THUNDER_MAX_TICKS) : mt_rand(self::NO_THUNDER_MIN_TICKS, self::NO_THUNDER_MAX_TICKS);
			}elseif(--$this->lightningTime <= 0){
				$thundering = !$thundering;
			}
			if($this->rainTime <= 0){
				$this->rainTime = $raining ? mt_rand(self::RAIN_MIN_TICKS, self::RAIN_MAX_TICKS) : mt_rand(self::CLEAR_MIN_TICKS, self::CLEAR_MAX_TICKS);
			}elseif(--$this->rainTime <= 0){
				$raining = !$raining;
			}
			if($raining !== $this->rainLevel > 0 || $thundering !== $this->lightningLevel > 0){
				$this->change($raining, $thundering);
			}
		}

		$rainTarget = $this->isRaining() ? 1.0 : 0.0;
		if($this->rainStrength !== $rainTarget){
			$this->rainStrength = $rainTarget > $this->rainStrength ? min($rainTarget, $this->rainStrength + self::STRENGTH_STEP) : max($rainTarget, $this->rainStrength - self::STRENGTH_STEP);
		}
		$thunderTarget = $this->isThundering() ? 1.0 : 0.0;
		if($this->thunderStrength !== $thunderTarget){
			$this->thunderStrength = $thunderTarget > $this->thunderStrength ? min($thunderTarget, $this->thunderStrength + self::STRENGTH_STEP) : max($thunderTarget, $this->thunderStrength - self::STRENGTH_STEP);
		}

		if($this->isThundering()){
			$this->tickLightning();
		}
	}

	/**
	 * Natural lightning: every loaded chunk within {@link Weather::LIGHTNING_RANGE} blocks of a player rolls
	 * 1/{@link Weather::LIGHTNING_CHANCE} per tick, like vanilla, independently of the chunk ticking radius (which is
	 * much smaller and would make thunderstorms almost silent). Instead of one roll per chunk, a single roll against
	 * the number of chunks picks whether a strike happens this tick and then one of the chunks: the same rate, since
	 * two strikes in the same tick are negligible (~200 chunks / 100000).
	 */
	private function tickLightning() : void{
		$radius = self::LIGHTNING_RANGE >> Chunk::COORD_BIT_SIZE;
		$chunks = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isSpectator()){
				continue;
			}
			$pos = $player->getPosition();
			$centerX = $pos->getFloorX() >> Chunk::COORD_BIT_SIZE;
			$centerZ = $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE;
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if($dx * $dx + $dz * $dz > $radius * $radius){
						continue;
					}
					$chunkX = $centerX + $dx;
					$chunkZ = $centerZ + $dz;
					if($this->world->isChunkLoaded($chunkX, $chunkZ)){
						$chunks[World::chunkHash($chunkX, $chunkZ)] = true;
					}
				}
			}
		}
		$count = count($chunks);
		if($count === 0 || mt_rand(0, self::LIGHTNING_CHANCE - 1) >= $count){
			return;
		}
		$hashes = array_keys($chunks);
		World::getXZ($hashes[mt_rand(0, $count - 1)], $chunkX, $chunkZ);
		$this->tryNaturalStrike(
			($chunkX << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1),
			($chunkZ << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1)
		);
	}

	/**
	 * Sends the weather to the given players (all the players of the world if none).
	 */
	public function broadcast(Player ...$targets) : void{
		if(count($targets) === 0){
			$targets = $this->world->getPlayers();
		}
		$rain = $this->getRainLevel();
		$lightning = $this->getLightningLevel();
		foreach($targets as $player){
			$player->getNetworkSession()->syncWorldWeather($rain, $lightning);
		}
	}

	// ---- where it rains ---------------------------------------------------------------------------------------------

	/**
	 * Returns the Y of the lowest block that rain and snow reach in the column (one above the highest solid or liquid
	 * block; flowers, grass, torches and other non-solid blocks let the rain through), or null if the chunk is not
	 * loaded.
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
		$minY = $this->world->getMinY();
		$y = $highest;
		for($i = 0; $y >= $minY && $i < 8; --$y, ++$i){
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block->isSolid() || $block instanceof Liquid){
				return $y + 1;
			}
		}
		return $y + 1;
	}

	/**
	 * Whether the given block is exposed to the sky (nothing that stops the rain above it).
	 */
	public function isExposedToSky(int $x, int $y, int $z) : bool{
		$height = $this->getPrecipitationHeight($x, $z);
		return $height !== null && $y >= $height;
	}

	/**
	 * What falls at the given position when it rains, depending on the biome and the height (whether it actually
	 * rains is not checked).
	 */
	public function getPrecipitationAt(int $x, int $y, int $z) : Precipitation{
		if(!$this->hasWeather() || !$this->world->isInWorld($x, $y, $z)){
			return Precipitation::NONE;
		}
		return BiomeClimate::getPrecipitation($this->world->getBiomeId($x, $y, $z), $y);
	}

	/**
	 * Whether rain (not snow) falls on the given block right now: it rains, the biome isn't dry or cold and the block
	 * is under the open sky. This is what puts out burning entities, wets endermen and keeps farmland moist.
	 */
	public function isRainingAt(int $x, int $y, int $z) : bool{
		return $this->isRaining() &&
			$this->isExposedToSky($x, $y, $z) &&
			$this->getPrecipitationAt($x, $y, $z) === Precipitation::RAIN;
	}

	// ---- per chunk effects ------------------------------------------------------------------------------------------

	/**
	 * @internal Called by the world for each ticked chunk, every tick.
	 */
	public function tickChunk(int $chunkX, int $chunkZ) : void{
		if(!$this->hasWeather()){
			return;
		}
		if(mt_rand(0, self::PRECIPITATION_CHANCE - 1) === 0){
			$this->tickPrecipitationInChunk($chunkX, $chunkZ);
		}
	}

	/**
	 * @internal The chance of {@link Weather::tickChunk()}: a precipitation update when mt_rand(0, chance - 1) is 0, or
	 * 0 when the world has no weather (no draw at all). Lets the world draw it natively.
	 */
	public function getPrecipitationChance() : int{
		return $this->hasWeather() ? self::PRECIPITATION_CHANCE : 0;
	}

	/**
	 * @internal What {@link Weather::tickChunk()} does once its draw hit: precipitation on a random column of the chunk.
	 */
	public function tickPrecipitationInChunk(int $chunkX, int $chunkZ) : void{
		$this->tickPrecipitation(
			($chunkX << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1),
			($chunkZ << Chunk::COORD_BIT_SIZE) + mt_rand(0, Chunk::EDGE_LENGTH - 1)
		);
	}

	/**
	 * Precipitation on one column: water freezes in cold biomes (also when it doesn't snow), snow settles while it
	 * snows, and the top block gets the rain or snow.
	 */
	public function tickPrecipitation(int $x, int $z) : void{
		$top = $this->getPrecipitationHeight($x, $z);
		if($top === null || $top - 1 < $this->world->getMinY() || $top >= $this->world->getMaxY()){
			return;
		}
		$world = $this->world;
		$belowY = $top - 1;
		$biome = $world->getBiomeId($x, $belowY, $z);
		if(!BiomeClimate::hasPrecipitation($biome)){
			return;
		}
		$below = $world->getBlockAt($x, $belowY, $z);
		if(BiomeClimate::isCold($biome, $belowY)){
			if($below instanceof Water && $below->isSource() && $world->getBlockLightAt($x, $belowY, $z) < 10){
				BlockEventHelper::form($below, VanillaBlocks::ICE(), $below);
				return;
			}
		}
		if(!$this->isRaining()){
			return;
		}
		$precipitation = BiomeClimate::getPrecipitation($biome, $top);
		if($precipitation === Precipitation::SNOW){
			$this->accumulateSnow($x, $top, $z, $below, $biome);
		}
		$at = BiomeClimate::getPrecipitation($biome, $belowY);
		if($at !== Precipitation::NONE){
			$below->onPrecipitation($at);
		}
	}

	/**
	 * Snowfall on top of a column: a layer settles on a full face or on leaves, and the layers pile up to the biome's
	 * snow accumulation (Bedrock: up to 12 layers, the ones above 8 on top of a full layer). Snow layers are not solid,
	 * so $top is the lowest snow layer of the column, if any.
	 */
	private function accumulateSnow(int $x, int $top, int $z, Block $below, int $biome) : void{
		$world = $this->world;
		$y = $top;
		$current = $world->getBlockAt($x, $y, $z);
		$depth = 0;
		if($current instanceof SnowLayer && $current->getLayers() === SnowLayer::MAX_LAYERS){
			$depth = SnowLayer::MAX_LAYERS;
			if(++$y >= $world->getMaxY()){
				return;
			}
			$current = $world->getBlockAt($x, $y, $z);
		}
		if($current instanceof SnowLayer){
			$layers = $current->getLayers() + 1;
			if($layers > SnowLayer::MAX_LAYERS){
				return;
			}
		}elseif($current->getTypeId() === BlockTypeIds::AIR && ($depth > 0 || $below instanceof Leaves || $below->getSupportType(Facing::UP) === SupportType::FULL)){
			$layers = 1;
		}else{
			return;
		}
		if($depth + $layers > BiomeClimate::getSnowAccumulationLayers($biome) || $world->getBlockLightAt($x, $y, $z) >= 10){
			return;
		}
		BlockEventHelper::form($current, VanillaBlocks::SNOW_LAYER()->setLayers($layers), $current);
	}

	// ---- lightning --------------------------------------------------------------------------------------------------

	/**
	 * A natural strike near the given column: aimed like vanilla (see the class doc); nothing happens if it doesn't
	 * rain or snow there. Returns the bolt, or null.
	 */
	public function tryNaturalStrike(int $x, int $z) : ?LightningBolt{
		$target = $this->findLightningTarget($x, $z);
		if($target === null){
			return null;
		}
		$tx = $target->getFloorX();
		$ty = $target->getFloorY();
		$tz = $target->getFloorZ();
		if(!$this->isRaining() || !$this->isExposedToSky($tx, $ty, $tz) || $this->getPrecipitationAt($tx, $ty, $tz) === Precipitation::NONE){
			return null;
		}
		$location = new Location($tx + 0.5, $ty, $tz + 0.5, $this->world, 0, 0);
		if($this->trySkeletonTrap($location)){
			return $this->strike($location, true, LightningStrikeEvent::CAUSE_TRAP);
		}
		return $this->strike($location, false, LightningStrikeEvent::CAUSE_WEATHER);
	}

	/**
	 * Spawns a lightning bolt at the given position (a visual-only bolt does no damage, sets no fire and powers no
	 * lightning rod). Returns null if a plugin cancelled the strike.
	 *
	 * @phpstan-param LightningStrikeEvent::CAUSE_* $cause
	 */
	public function strike(Vector3 $pos, bool $visualOnly = false, int $cause = LightningStrikeEvent::CAUSE_CUSTOM) : ?LightningBolt{
		return LightningBolt::strike(Location::fromObject($pos, $this->world), $visualOnly, $cause);
	}

	/**
	 * Where a natural strike near the column lands: on a lightning rod in range, else on a random living entity under
	 * the open sky within 3 blocks of the column, else on top of the column. Null if the chunk is not loaded.
	 */
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
		$candidates = [];
		foreach($this->world->getNearbyEntities($box) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive()){
				continue;
			}
			$pos = $entity->getPosition();
			if($this->isExposedToSky($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
				$candidates[] = $pos;
			}
		}
		if(count($candidates) > 0){
			$pos = $candidates[mt_rand(0, count($candidates) - 1)];
			return new Vector3($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		}
		return new Vector3($x, $top, $z);
	}

	/**
	 * @phpstan-return array<int, true>
	 */
	private static function getLightningRodStates() : array{
		if(self::$lightningRodStates === null){
			self::$lightningRodStates = [];
			foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $state){
				if($state->getTypeId() === BlockTypeIds::LIGHTNING_ROD){
					self::$lightningRodStates[$stateId] = true;
				}
			}
		}
		return self::$lightningRodStates;
	}

	/**
	 * Returns the nearest lightning rod within {@link Weather::LIGHTNING_ROD_RANGE} blocks that is the top block of
	 * its column, or null. Only the sub-chunks whose palette has a lightning rod are searched.
	 */
	public function findLightningRod(int $x, int $y, int $z) : ?Vector3{
		$rodStates = self::getLightningRodStates();
		$range = self::LIGHTNING_ROD_RANGE;
		$best = null;
		$bestDistance = PHP_INT_MAX;
		for($cx = ($x - $range) >> Chunk::COORD_BIT_SIZE; $cx <= ($x + $range) >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = ($z - $range) >> Chunk::COORD_BIT_SIZE; $cz <= ($z + $range) >> Chunk::COORD_BIT_SIZE; ++$cz){
				$chunk = $this->world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getSubChunks() as $subY => $subChunk){
					if($subChunk->isEmptyFast()){
						continue;
					}
					$layers = $subChunk->getBlockLayers();
					if(count($layers) === 0){
						continue;
					}
					$hasRod = false;
					foreach($layers[0]->getPalette() as $stateId){
						if(isset($rodStates[$stateId])){
							$hasRod = true;
							break;
						}
					}
					if(!$hasRod){
						continue;
					}
					for($bx = 0; $bx < SubChunk::EDGE_LENGTH; ++$bx){
						for($bz = 0; $bz < SubChunk::EDGE_LENGTH; ++$bz){
							for($by = 0; $by < SubChunk::EDGE_LENGTH; ++$by){
								if(!isset($rodStates[$layers[0]->get($bx, $by, $bz)])){
									continue;
								}
								$wx = ($cx << Chunk::COORD_BIT_SIZE) + $bx;
								$wy = ($subY << SubChunk::COORD_BIT_SIZE) + $by;
								$wz = ($cz << Chunk::COORD_BIT_SIZE) + $bz;
								$distance = ($wx - $x) ** 2 + ($wy - $y) ** 2 + ($wz - $z) ** 2;
								if($distance < $bestDistance && ($wx - $x) ** 2 + ($wz - $z) ** 2 <= $range ** 2 &&
									$chunk->getHighestBlockAt($bx, $bz) === $wy
								){
									$bestDistance = $distance;
									$best = new Vector3($wx, $wy, $wz);
								}
							}
						}
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Skeleton trap (vanilla): a natural strike has a chance of spawning a skeleton trap horse instead, which turns
	 * into four skeleton horsemen when a player comes close. Chance: 1% per point of regional difficulty (simplified
	 * to the world difficulty: easy 0.75%, normal 1.5%, hard 2.25%), only if there is no skeleton horse around.
	 */
	private function trySkeletonTrap(Location $location) : bool{
		$difficulty = $this->world->getDifficulty();
		if($difficulty === World::DIFFICULTY_PEACEFUL || Utils::getRandomFloat() >= $difficulty * 0.0075){
			return false;
		}
		foreach($this->world->getNearbyEntities(new AxisAlignedBB($location->x - 10, $location->y - 10, $location->z - 10, $location->x + 10, $location->y + 10, $location->z + 10)) as $entity){
			if($entity instanceof SkeletonHorse){
				return false;
			}
		}
		$horse = new SkeletonHorse($location);
		$horse->setTrap(true);
		$horse->spawnToAll();
		return true;
	}
}
