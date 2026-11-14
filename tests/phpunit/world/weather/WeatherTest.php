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

use pocketmine\block\Farmland;
use pocketmine\block\PowderSnowCauldron;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WaterCauldron;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\npc\VillagerActivity;
use pocketmine\entity\npc\VillagerSchedule;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\world\WeatherChangeEvent;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\data\BedrockWorldData;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Weather cycle, persistence, where it rains and what rain does, in an in-memory world (stone floor at y=0).
 */
final class WeatherTest extends MobTestCase{

	private function weather() : Weather{
		return $this->world->getWeather();
	}

	private function setBiome(int $x, int $z, int $biome, int $minY = -1, int $maxY = 8) : void{
		for($y = $minY; $y <= $maxY; ++$y){
			$this->world->setBiomeId($x, $y, $z, $biome);
		}
	}

	public function testNewWorldIsClear() : void{
		self::assertTrue($this->weather()->isClear());
		self::assertFalse($this->weather()->isThundering());
		self::assertSame(0.0, $this->weather()->getRainLevel());
	}

	public function testCycleDrawsClearDurationThenStartsRain() : void{
		$weather = $this->weather();
		$weather->setWeatherCycleEnabled(true);
		$this->runTicks(1);
		self::assertFalse($weather->isRaining());
		self::assertGreaterThanOrEqual(Weather::CLEAR_MIN_TICKS, $weather->getRainTime());
		self::assertLessThanOrEqual(Weather::CLEAR_MAX_TICKS, $weather->getRainTime());

		$weather->setClear(5);
		$this->runTicks(5);
		self::assertTrue($weather->isRaining(), "rain starts when the clear timer runs out");
		self::assertGreaterThan(0.0, $weather->getRainLevel());
		self::assertLessThanOrEqual(1.0, $weather->getRainLevel());
		$this->runTicks(1);
		self::assertGreaterThanOrEqual(Weather::RAIN_MIN_TICKS, $weather->getRainTime(), "then the rain duration is drawn");
		self::assertLessThanOrEqual(Weather::RAIN_MAX_TICKS, $weather->getRainTime());
	}

	public function testCycleOffKeepsWeather() : void{
		$weather = $this->weather();
		$weather->setWeatherCycleEnabled(false);
		$weather->setRain(3);
		$this->runTicks(20);
		self::assertTrue($weather->isRaining());
		self::assertSame(3, $weather->getRainTime());
	}

	public function testThunderNeedsRain() : void{
		$weather = $this->weather();
		$weather->setThunder(1000);
		self::assertTrue($weather->isRaining());
		self::assertTrue($weather->isThundering());
		self::assertGreaterThan(0.0, $weather->getLightningLevel());

		$weather->setRain(1000);
		self::assertTrue($weather->isRaining());
		self::assertFalse($weather->isThundering());
		self::assertSame(0.0, $weather->getLightningLevel());

		$weather->setClear(1000);
		self::assertFalse($weather->isRaining());
		self::assertFalse($weather->isThundering());
	}

	public function testThunderEndsWithTheRain() : void{
		$weather = $this->weather();
		$weather->setWeatherCycleEnabled(true);
		$weather->setThunder(10);
		$this->runTicks(10);
		self::assertFalse($weather->isRaining());
		self::assertFalse($weather->isThundering());
	}

	public function testWeatherChangeEventCanBeCancelled() : void{
		$listener = new RegisteredListener(function(WeatherChangeEvent $ev) : void{
			self::assertFalse($ev->wasRaining());
			self::assertTrue($ev->isRaining());
			$ev->cancel();
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(WeatherChangeEvent::class)->register($listener);
		try{
			self::assertFalse($this->weather()->setRain(100));
			self::assertFalse($this->weather()->isRaining());
		}finally{
			HandlerListManager::global()->getListFor(WeatherChangeEvent::class)->unregister($listener);
		}
	}

	public function testSaveAndLoadLevelDat() : void{
		$path = sys_get_temp_dir() . "/pm-weather-test-" . uniqid();
		mkdir($path);
		try{
			BedrockWorldData::generate($path, "weather", WorldCreationOptions::create());
			$data = new BedrockWorldData($path . "/level.dat");
			self::assertTrue($data->isWeatherCycleEnabled(), "doweathercycle defaults to on");

			$weather = new Weather($this->world, $data);
			$weather->setThunder(1234);
			$weather->setWeatherCycleEnabled(false);
			$rain = $weather->getRainLevel();
			$lightning = $weather->getLightningLevel();
			$weather->save($data);
			$data->save();

			$reloaded = new BedrockWorldData($path . "/level.dat");
			self::assertSame(1234, $reloaded->getRainTime());
			self::assertSame(1234, $reloaded->getLightningTime());
			self::assertEqualsWithDelta($rain, $reloaded->getRainLevel(), 0.0001);
			self::assertEqualsWithDelta($lightning, $reloaded->getLightningLevel(), 0.0001);
			self::assertFalse($reloaded->isWeatherCycleEnabled());

			$loaded = new Weather($this->world, $reloaded);
			self::assertTrue($loaded->isThundering());
			self::assertSame(1234, $loaded->getRainTime());
			self::assertFalse($loaded->isWeatherCycleEnabled());
			self::assertSame(1.0, $loaded->getRainStrength(), "a loaded storm darkens the sky at once");
		}finally{
			Filesystem::recursiveUnlink($path);
		}
	}

	public function testBiomeClimate() : void{
		self::assertFalse(BiomeClimate::hasPrecipitation(BiomeIds::DESERT));
		self::assertFalse(BiomeClimate::hasPrecipitation(BiomeIds::HELL));
		self::assertSame(Precipitation::RAIN, BiomeClimate::getPrecipitation(BiomeIds::PLAINS, 64));
		self::assertSame(Precipitation::SNOW, BiomeClimate::getPrecipitation(BiomeIds::ICE_PLAINS, 64));
		self::assertSame(Precipitation::NONE, BiomeClimate::getPrecipitation(BiomeIds::DESERT, 64));
		//extreme hills (0.2) snow above y=120
		self::assertSame(Precipitation::RAIN, BiomeClimate::getPrecipitation(BiomeIds::EXTREME_HILLS, 100));
		self::assertSame(Precipitation::SNOW, BiomeClimate::getPrecipitation(BiomeIds::EXTREME_HILLS, 140));
	}

	public function testRainingAtNeedsOpenSkyAndRainyBiome() : void{
		$weather = $this->weather();
		self::assertFalse($weather->isRainingAt(0, 1, 0), "not raining yet");
		$weather->setRain(1000);
		self::assertTrue($weather->isRainingAt(0, 1, 0));
		self::assertFalse($weather->isRainingAt(0, 0, 0), "the floor itself is not above the floor");

		$this->set(0, 5, 0, VanillaBlocks::STONE());
		self::assertFalse($weather->isRainingAt(0, 1, 0), "a roof keeps the rain off");
		self::assertTrue($weather->isRainingAt(0, 6, 0));

		$this->set(1, 1, 0, VanillaBlocks::DANDELION());
		self::assertTrue($weather->isRainingAt(1, 1, 0), "flowers don't stop the rain");
		self::assertSame(1, $weather->getPrecipitationHeight(1, 0));

		$this->setBiome(2, 0, BiomeIds::DESERT);
		self::assertFalse($weather->isRainingAt(2, 1, 0), "no rain in deserts");
		$this->setBiome(3, 0, BiomeIds::ICE_PLAINS);
		self::assertFalse($weather->isRainingAt(3, 1, 0), "snow is not rain");
		self::assertSame(Precipitation::SNOW, $weather->getPrecipitationAt(3, 1, 0));
	}

	public function testNoWeatherOutsideTheOverworldIsCheckedByDimension() : void{
		self::assertTrue($this->weather()->hasWeather());
	}

	public function testRainAndThunderDarkenTheSky() : void{
		$this->world->setTime(World::TIME_NOON);
		$this->world->stopTime();
		$this->runTicks(1);
		$clear = $this->world->getSkyLightReduction();
		$this->weather()->setRain(100000);
		$this->runTicks(100);
		$rain = $this->world->getSkyLightReduction();
		$this->weather()->setThunder(100000);
		$this->runTicks(100);
		$thunder = $this->world->getSkyLightReduction();
		self::assertSame(0, $clear);
		self::assertSame(3, $rain);
		self::assertSame(5, $thunder);
	}

	public function testRainPutsOutBurningEntities() : void{
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->spawnToAll();
		$cow->setOnFire(100);
		$this->runTicks(20);
		self::assertTrue($cow->isOnFire(), "still burning in clear weather");
		$this->weather()->setRain(100000);
		$this->runTicks(20);
		self::assertFalse($cow->isOnFire());
		self::assertTrue($cow->isInRain());

		$pos = $cow->getPosition();
		$this->set($pos->getFloorX(), 5, $pos->getFloorZ(), VanillaBlocks::STONE());
		self::assertFalse($cow->isInRain(), "sheltered");
	}

	public function testCauldronsFillInRainAndSnow() : void{
		$this->set(0, 1, 0, VanillaBlocks::CAULDRON());
		$this->setBiome(1, 0, BiomeIds::ICE_PLAINS);
		$this->set(1, 1, 0, VanillaBlocks::CAULDRON());
		for($i = 0; $i < 2000; ++$i){
			$this->get(0, 1, 0)->onPrecipitation(Precipitation::RAIN);
			$this->get(1, 1, 0)->onPrecipitation(Precipitation::SNOW);
		}
		$water = $this->get(0, 1, 0);
		self::assertInstanceOf(WaterCauldron::class, $water);
		self::assertSame(WaterCauldron::MAX_FILL_LEVEL, $water->getFillLevel());
		$snow = $this->get(1, 1, 0);
		self::assertInstanceOf(PowderSnowCauldron::class, $snow);
		self::assertSame(PowderSnowCauldron::MAX_FILL_LEVEL, $snow->getFillLevel());
	}

	public function testPrecipitationColumnUpdates() : void{
		$weather = $this->weather();
		//cold biome: water freezes even without snow, snow settles while it snows
		$this->setBiome(5, 5, BiomeIds::ICE_PLAINS);
		$this->set(5, 0, 5, VanillaBlocks::WATER());
		$weather->tickPrecipitation(5, 5);
		self::assertSame(VanillaBlocks::ICE()->getTypeId(), $this->get(5, 0, 5)->getTypeId());

		$this->setBiome(6, 5, BiomeIds::ICE_PLAINS);
		$weather->tickPrecipitation(6, 5);
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(6, 1, 5)->getTypeId(), "no snow without snowfall");
		$weather->setRain(1000);
		$weather->tickPrecipitation(6, 5);
		self::assertSame(VanillaBlocks::SNOW_LAYER()->getTypeId(), $this->get(6, 1, 5)->getTypeId());

		//temperate biome: no snow
		$weather->tickPrecipitation(7, 5);
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(7, 1, 5)->getTypeId());
	}

	public function testFarmlandStaysWetInTheRain() : void{
		$this->set(0, 0, 0, VanillaBlocks::FARMLAND()->setWetness(Farmland::MAX_WETNESS));
		$this->weather()->setRain(100000);
		for($i = 0; $i < 20; ++$i){
			$this->get(0, 0, 0)->onRandomTick();
		}
		$farmland = $this->get(0, 0, 0);
		self::assertInstanceOf(Farmland::class, $farmland);
		self::assertSame(Farmland::MAX_WETNESS, $farmland->getWetness());

		$this->weather()->setClear(100000);
		$this->get(0, 0, 0)->onRandomTick();
		$farmland = $this->get(0, 0, 0);
		self::assertInstanceOf(Farmland::class, $farmland);
		self::assertSame(Farmland::MAX_WETNESS - 1, $farmland->getWetness(), "dries out without water or rain");
	}

	/**
	 * Lights 20 fires (age 0, on stone, nothing flammable around) and gives each 3 random ticks. Without rain such a
	 * fire can't go out that fast (it needs an age above 3); in the rain each has a 20-30% chance per tick.
	 */
	private function countFiresPutOut() : int{
		$out = 0;
		for($i = 0; $i < 20; ++$i){
			$x = -20 + $i * 2;
			$this->set($x, 1, 10, VanillaBlocks::FIRE()->setAge(0));
			for($t = 0; $t < 3 && $this->get($x, 1, 10)->getTypeId() === VanillaBlocks::FIRE()->getTypeId(); ++$t){
				$this->get($x, 1, 10)->onRandomTick();
			}
			if($this->get($x, 1, 10)->getTypeId() !== VanillaBlocks::FIRE()->getTypeId()){
				++$out;
			}
			$this->set($x, 1, 10, VanillaBlocks::AIR());
		}
		return $out;
	}

	public function testFireGoesOutInTheRain() : void{
		self::assertSame(0, $this->countFiresPutOut());
		$this->weather()->setRain(100000);
		self::assertGreaterThan(0, $this->countFiresPutOut());
	}

	public function testVillagersGoHomeInTheRain() : void{
		self::assertSame(VillagerActivity::WORK, VillagerSchedule::getActivity(1000, VillagerV2::PROFESSION_FARMER, false));
		self::assertSame(VillagerActivity::HOME, VillagerSchedule::getActivity(1000, VillagerV2::PROFESSION_FARMER, false, true));
		self::assertSame(VillagerActivity::HOME, VillagerSchedule::getActivity(1000, VillagerV2::PROFESSION_FARMER, true, true));
		self::assertSame(VillagerActivity::SLEEP, VillagerSchedule::getActivity(18000, VillagerV2::PROFESSION_FARMER, false, true));

		$this->world->setTime(1000);
		$villager = new VillagerV2(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$villager->setAge(0); //a new villager is a baby 5% of the time, and babies play instead of working
		$villager->setProfession(VillagerV2::PROFESSION_FARMER);
		self::assertSame(VillagerActivity::WORK, $villager->getActivity());
		$this->weather()->setRain(100000);
		self::assertSame(VillagerActivity::HOME, $villager->getActivity());
		$villager->close();
	}

	public function testSleepingClearsTheWeather() : void{
		$weather = $this->weather();
		$weather->setWeatherCycleEnabled(true);
		$weather->setThunder(100000);
		$weather->resetCycle();
		self::assertTrue($weather->isClear());
		self::assertSame(0, $weather->getRainTime(), "the cycle starts over");
	}
}
