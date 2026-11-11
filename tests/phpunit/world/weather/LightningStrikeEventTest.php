<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\world\World;

final class LightningStrikeEventTest extends TestCase{
	public function testPluginCanCancelWeatherLightning() : void{
		$world = $this->createMock(World::class);
		$bolt = $this->getMockBuilder(LightningBolt::class)
			->disableOriginalConstructor()
			->onlyMethods(["getWorld", "__destruct"])
			->getMock();
		$bolt->method("getWorld")->willReturn($world);
		$event = new LightningStrikeEvent($bolt, LightningStrikeEvent::CAUSE_WEATHER);
		self::assertSame($world, $event->getWorld());
		self::assertSame($bolt, $event->getBolt());
		self::assertSame(LightningStrikeEvent::CAUSE_WEATHER, $event->getCause());
		self::assertFalse($event->isCancelled());
		$event->cancel();
		self::assertTrue($event->isCancelled());
	}

	public function testNetworkActorNameIsVanilla() : void{
		self::assertSame("minecraft:lightning_bolt", LightningBolt::getNetworkTypeId());
	}
}
