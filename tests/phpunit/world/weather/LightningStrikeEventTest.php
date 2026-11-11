<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\world\LightningStrikeEvent;

/** The cancellation event's integration with real world/actor lifecycle is tested on staging. */
final class LightningStrikeEventTest extends TestCase{
	public function testPluginCancellationFlag() : void{
		// Tests the cancellable event directly without creating an uninitialized Entity mock.
		$event = (new \ReflectionClass(LightningStrikeEvent::class))->newInstanceWithoutConstructor();
		self::assertFalse($event->isCancelled());
		$event->cancel();
		self::assertTrue($event->isCancelled());
	}

	public function testNetworkActorNameIsVanilla() : void{
		self::assertSame("minecraft:lightning_bolt", LightningBolt::getNetworkTypeId());
	}
}
