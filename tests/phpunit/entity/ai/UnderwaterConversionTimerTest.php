<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\monster\UnderwaterConversionTimer as Timer;
use pocketmine\nbt\tag\CompoundTag;

final class UnderwaterConversionTimerTest extends TestCase{
	public function testConversionContinuesAfterLeavingWaterAndAcrossSave() : void{
		$entity = new UnderwaterEntityStub(true);
		$timer = Timer::fromNBT(CompoundTag::create());
		self::assertSame(Timer::STATE_NONE, $timer->tick($entity, 599));
		self::assertSame(Timer::STATE_STARTED, $timer->tick($entity, 1));
		$nbt = CompoundTag::create();
		$timer->saveNBT($nbt);
		$loaded = Timer::fromNBT($nbt);
		$dry = new UnderwaterEntityStub(false);
		self::assertSame(Timer::STATE_CONVERTING, $loaded->tick($dry, 299));
		self::assertSame(Timer::STATE_DONE, $loaded->tick($dry, 2));
	}

	public function testLeavingWaterBeforeConversionResetsCounter() : void{
		$wet = new UnderwaterEntityStub(true);
		$dry = new UnderwaterEntityStub(false);
		$timer = Timer::fromNBT(CompoundTag::create());
		self::assertSame(Timer::STATE_NONE, $timer->tick($wet, 500));
		self::assertSame(Timer::STATE_NONE, $timer->tick($dry, 1));
		self::assertSame(Timer::STATE_NONE, $timer->tick($wet, 100));
		self::assertSame(100, $timer->getUnderwaterTicks());
	}
}

/** A timer-only entity; it never enters a world or initializes Entity state. */
final class UnderwaterEntityStub extends Entity{
	// The timer only reads isUnderwater(); no world or entity lifecycle is initialized.
	// @phpstan-ignore constructor.missingParentCall
	public function __construct(private bool $underwater){}
	public function __destruct(){}
	public function isUnderwater() : bool{ return $this->underwater; }
	public static function getNetworkTypeId() : string{ return "minecraft:test"; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.0, 1.0); }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	protected function getInitialGravity() : float{ return 0.0; }
}
