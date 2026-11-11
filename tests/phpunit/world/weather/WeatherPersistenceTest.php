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
}
