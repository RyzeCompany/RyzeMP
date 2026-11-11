<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Facing;
use pocketmine\world\World;

final class LightningRodPowerTest extends TestCase{
	public function testPowerAndDirection() : void{
		$rod = VanillaBlocks::LIGHTNING_ROD()->setFacing(Facing::UP);
		self::assertTrue($rod->isRedstoneSource());
		self::assertFalse($rod->isPowered());
		self::assertSame(0, $rod->getWeakPower(Facing::UP));
		$rod->setPowered(true);
		foreach(Facing::ALL as $face){
			self::assertSame(15, $rod->getWeakPower($face));
			self::assertSame($face === Facing::DOWN ? 15 : 0, $rod->getStrongPower($face));
		}
		self::assertSame($rod->getStateId(), RuntimeBlockStateRegistry::getInstance()->fromStateId($rod->getStateId())->getStateId());
	}

	public function testLightningPulseAndExpirationNotifyNeighbors() : void{
		$world = $this->createMock(World::class);
		$rod = VanillaBlocks::LIGHTNING_ROD()->setFacing(Facing::NORTH);
		$rod->position($world, 5, 70, 8);
		$world->expects(self::exactly(2))->method("setBlock");
		$world->expects(self::once())->method("scheduleDelayedBlockUpdate")->with($rod->getPosition(), LightningRod::STRUCK_POWER_TICKS, true);
		$rod->onLightningStrike();
		self::assertSame(15, $rod->getWeakPower(Facing::UP));
		$rod->onScheduledUpdate();
		self::assertFalse($rod->isPowered());
	}
}
