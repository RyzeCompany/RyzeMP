<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\Cancellable;
use pocketmine\event\world\LightningStrikeEvent;

/** The cancellation event's integration with real world/actor lifecycle is tested on staging. */
final class LightningStrikeEventTest extends TestCase{
	public function testLightningStrikeIsCancellableWorldEvent() : void{
		self::assertTrue(is_subclass_of(LightningStrikeEvent::class, Cancellable::class));
		self::assertSame(0, LightningStrikeEvent::CAUSE_WEATHER);
		self::assertSame(2, LightningStrikeEvent::CAUSE_CUSTOM);
	}

	public function testNetworkActorNameIsVanilla() : void{
		self::assertSame("minecraft:lightning_bolt", LightningBolt::getNetworkTypeId());
	}
}
