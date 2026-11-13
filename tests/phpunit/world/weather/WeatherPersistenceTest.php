<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\World;

/** The vanilla timers and intensity state must survive an existing level.dat round trip. */
final class WeatherPersistenceTest extends TestCase{
	public function testExistingWorldWeatherStateIsLoadedAndSavedWithoutChangingFormat() : void{
		$world = $this->createMock(World::class);
		$data = $this->createMock(WorldData::class);
		$data->method("getRainTime")->willReturn(250);
		$data->method("getRainLevel")->willReturn(0.6);
		$data->method("getLightningTime")->willReturn(400);
		$data->method("getLightningLevel")->willReturn(0.2);

		$weather = new Weather($world, $data);
		self::assertSame(250, $weather->getRainTime());
		self::assertSame(400, $weather->getLightningTime());

		$copy = $this->createMock(WorldData::class);
		$copy->expects(self::once())->method("setRainTime")->with(250);
		$copy->expects(self::once())->method("setRainLevel")->with(0.6);
		$copy->expects(self::once())->method("setLightningTime")->with(400);
		$copy->expects(self::once())->method("setLightningLevel")->with(0.2);
		$weather->save($copy);
	}

	public function testCorruptWeatherLevelsAreBounded() : void{
		$world = $this->createMock(World::class);
		$data = $this->createMock(WorldData::class);
		$data->method("getRainTime")->willReturn(-12);
		$data->method("getRainLevel")->willReturn(3.0);
		$data->method("getLightningTime")->willReturn(-5);
		$data->method("getLightningLevel")->willReturn(-0.2);
		$weather = new Weather($world, $data);

		self::assertSame(0, $weather->getRainTime());
		self::assertSame(0, $weather->getLightningTime());
		$copy = $this->createMock(WorldData::class);
		$copy->expects(self::once())->method("setRainLevel")->with(1.0);
		$copy->expects(self::once())->method("setLightningLevel")->with(0.0);
		$weather->save($copy);
	}

	/**
	 * Verify that the vanilla weather cycle only advances when enabled, using RyzeMP's actual
	 * dimension lookup rather than VanillaMine's incompatible World::getDimension() API.
	 */
	public function testWeatherTimerRespectsGameRuleAndTransitionsToRain() : void{
		$rules = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-weather-unused-" . bin2hex(random_bytes(8)) . ".yml");
		$world = $this->makeWorld("world", $rules);
		$data = $this->createMock(WorldData::class);
		$data->method("getRainTime")->willReturn(2);
		$data->method("getLightningTime")->willReturn(10000);
		$weather = new Weather($world, $data);

		$rules->setBool(\pocketmine\world\GameRules::DO_WEATHER_CYCLE, false);
		self::assertFalse($weather->isWeatherCycleEnabled());
		$weather->tick();
		self::assertSame(2, $weather->getRainTime());

		$rules->setBool(\pocketmine\world\GameRules::DO_WEATHER_CYCLE, true);
		$weather->tick();
		self::assertSame(1, $weather->getRainTime());
		$weather->tick();
		self::assertTrue($weather->isRaining());
		// Keep the temporary game rule YAML out of the repository and remove after the test.
		// On Windows unlinking an empty path is harmless when the file was never written.
	}

	public function testWeatherCommandRainIsVisiblyStrongWithoutThunder() : void{
		$rules = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-weather-visible-" . bin2hex(random_bytes(8)) . ".yml");
		$world = $this->makeWorld("world", $rules);
		$weather = new Weather($world, $this->createMock(WorldData::class));
		self::assertTrue($weather->setRain(600));
		self::assertSame(1.0, $weather->getRainLevel());
		self::assertSame(0.0, $weather->getLightningLevel());
		//Repeating the command must resync the client and keep rain fully visible.
		self::assertTrue($weather->setRain(600));
		self::assertSame(1.0, $weather->getRainLevel());
	}

	public function testThunderWeatherRemainsActiveWhenWeatherCycleIsDisabled() : void{
		$rules = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-thunder-cycle-" . bin2hex(random_bytes(8)) . ".yml");
		$world = $this->makeWorld("world", $rules);
		$weather = new Weather($world, $this->createMock(WorldData::class));
		$weather->setThunder(600);
		$rules->setBool(\pocketmine\world\GameRules::DO_WEATHER_CYCLE, false);
		$weather->tick();
		self::assertTrue($weather->isThundering());
		self::assertSame(600, $weather->getRainTime());
		self::assertSame(600, $weather->getLightningTime());
	}

	public function testNetherWorldNeverTicksOrBroadcastsStoredRain() : void{
		$rules = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-weather-nether-unused-" . bin2hex(random_bytes(8)) . ".yml");
		$world = $this->makeWorld("world_nether", $rules);
		$data = $this->createMock(WorldData::class);
		$data->method("getRainTime")->willReturn(6);
		$data->method("getLightningTime")->willReturn(12);
		$data->method("getRainLevel")->willReturn(0.8);
		$data->method("getLightningLevel")->willReturn(0.7);
		$weather = new Weather($world, $data);
		self::assertFalse($weather->hasWeather());
		self::assertSame(0.0, $weather->getRainLevel());
		self::assertSame(0.0, $weather->getLightningLevel());
		$weather->tick();
		self::assertSame(6, $weather->getRainTime());
		self::assertSame(12, $weather->getLightningTime());
	}

	public function testIndependentWorldsKeepIndependentWeatherStates() : void{
		$rulesA = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-weather-a-" . bin2hex(random_bytes(8)) . ".yml");
		$rulesB = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-weather-b-" . bin2hex(random_bytes(8)) . ".yml");
		$weatherA = new Weather($this->makeWorld("overworld_a", $rulesA), $this->createMock(WorldData::class));
		$weatherB = new Weather($this->makeWorld("overworld_b", $rulesB), $this->createMock(WorldData::class));

		self::assertTrue($weatherA->setThunder(120));
		self::assertTrue($weatherA->isThundering());
		self::assertTrue($weatherB->isClear());
		self::assertSame(120, $weatherA->getRainTime());
		self::assertSame(0, $weatherB->getRainTime());
	}

	public function testEndDimensionIgnoresStoredStorm() : void{
		$rules = new \pocketmine\world\GameRules(sys_get_temp_dir() . "/ryze-weather-end-" . bin2hex(random_bytes(8)) . ".yml");
		$world = $this->makeWorld("world_the_end", $rules);
		$data = $this->createMock(WorldData::class);
		$data->method("getRainLevel")->willReturn(0.7);
		$data->method("getLightningLevel")->willReturn(0.9);
		$weather = new Weather($world, $data);
		self::assertFalse($weather->hasWeather());
		self::assertTrue($weather->isClear());
		self::assertSame(0.0, $weather->getLightningLevel());
		self::assertSame(0.0, $weather->getRainLevel());
	}

	private function makeWorld(string $name, \pocketmine\world\GameRules $rules) : World{
		$server = $this->createMock(\pocketmine\Server::class);
		$manager = (new \ReflectionClass(\pocketmine\world\dimension\DimensionManager::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(\pocketmine\world\dimension\DimensionManager::class, "dimensions"))->setValue(
			$manager,
			new \pocketmine\world\dimension\Dimensions($server, [1 => "world_nether", 2 => "world_the_end"])
		);
		$server->method("getDimensionManager")->willReturn($manager);

		$world = $this->createMock(World::class);
		$world->method("getServer")->willReturn($server);
		$world->method("getFolderName")->willReturn($name);
		$world->method("getGameRules")->willReturn($rules);
		$world->method("getPlayers")->willReturn([]);
		return $world;
	}
}
