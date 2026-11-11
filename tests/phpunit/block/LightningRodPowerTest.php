<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\utils\ReversePriorityQueue;
use pocketmine\world\World;

final class LightningRodPowerTest extends TestCase{
	public function testNeighborsCanReadRealPowerFromWorld() : void{
		$world = $this->getMockBuilder(World::class)->disableOriginalConstructor()
			->onlyMethods(["getBlockAt", "isChunkLoaded", "isInWorld"])->getMock();
		$world->method("isInWorld")->willReturn(true);
		$world->method("isChunkLoaded")->willReturn(true);
		$rod = VanillaBlocks::LIGHTNING_ROD()->setFacing(Facing::UP)->setPowered(true);
		$world->method("getBlockAt")->willReturnCallback(
			static fn(int $x, int $y, int $z) : Block => $x === 0 && $y === 65 && $z === 0 ?
				$rod : VanillaBlocks::AIR()
		);
		self::assertSame(15, $world->getRedstonePowerAt(0, 64, 0));
		$rod->setPowered(false);
		self::assertSame(0, $world->getRedstonePowerAt(0, 64, 0));
	}

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

	public function testReloadedPoweredRodRearmsTransientShutdownTick() : void{
		$world = $this->createMock(World::class);
		$rod = VanillaBlocks::LIGHTNING_ROD()->setPowered(true);
		$rod->position($world, 5, 70, 8);
		$world->method("isBlockUpdateScheduled")->willReturn(false);
		$world->expects(self::once())->method("scheduleDelayedBlockUpdate")
			->with($rod->getPosition(), LightningRod::STRUCK_POWER_TICKS);
		self::assertSame($rod, $rod->readStateFromWorld());
	}

	public function testRepeatStrikeRestartsPulseWithoutRewritingAlreadyPoweredState() : void{
		$world = $this->createMock(World::class);
		$rod = VanillaBlocks::LIGHTNING_ROD();
		$rod->position($world, 5, 70, 8);
		$world->expects(self::once())->method("setBlock");
		$world->expects(self::exactly(2))->method("scheduleDelayedBlockUpdate")
			->with($rod->getPosition(), LightningRod::STRUCK_POWER_TICKS, true);
		$rod->onLightningStrike();
		$rod->onLightningStrike();
		self::assertTrue($rod->isPowered());
	}

	public function testScheduledTickCanBeReplacedAtAbsoluteDeadline() : void{
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturnOnConsecutiveCalls(100, 105);
		$queue = new ReversePriorityQueue();
		$queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		foreach(["server" => $server, "minY" => -64, "maxY" => 320, "scheduledBlockUpdateQueue" => $queue] as $name => $value){
			(new \ReflectionProperty(World::class, $name))->setValue($world, $value);
		}
		$pos = new Vector3(0, 64, 0);
		$world->scheduleDelayedBlockUpdate($pos, 8, true);
		$world->scheduleDelayedBlockUpdate($pos, 8, true);
		$index = (new \ReflectionProperty(World::class, "scheduledBlockUpdateQueueIndex"))->getValue($world);
		self::assertSame(113, $index[World::blockHash(0, 64, 0)]);
		self::assertSame(2, $queue->count());
		self::assertTrue($world->isBlockUpdateScheduled($pos));
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
