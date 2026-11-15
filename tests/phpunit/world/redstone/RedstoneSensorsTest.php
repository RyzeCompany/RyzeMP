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

namespace pocketmine\world\redstone;

use pocketmine\block\DetectorRail;
use pocketmine\block\Jukebox;
use pocketmine\block\LightningRod;
use pocketmine\block\Observer;
use pocketmine\block\Tripwire;
use pocketmine\block\TripwireHook;
use pocketmine\block\utils\DetectorRailActivator;
use pocketmine\block\utils\RecordType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WoodenButton;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function count;

class RedstoneSensorsTest extends RedstoneTestCase{

	// ---------------------------------------------------------------------------------------------------------------
	// Observer
	// ---------------------------------------------------------------------------------------------------------------

	private function observerPowered(int $x, int $y, int $z) : bool{
		$observer = $this->get($x, $y, $z);
		self::assertInstanceOf(Observer::class, $observer);
		return $observer->isPowered();
	}

	public function testObserverPulsesWhenWatchedBlockChanges() : void{
		$this->floor(-2, -2, 6, 2);
		//watches west (x=1), outputs east into the wire at x=3
		$this->set(2, 1, 0, VanillaBlocks::OBSERVER()->setFacing(Facing::WEST));
		$this->set(3, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->runTicks(5);
		self::assertFalse($this->observerPowered(2, 1, 0), "placing the observer or its output wire must not trigger it");
		self::assertSame(0, $this->wirePower(3, 1, 0));

		$this->set(1, 1, 0, VanillaBlocks::OAK_PLANKS());
		$this->runTicks(1);
		self::assertSame(0, $this->wirePower(3, 1, 0), "the observer waits one redstone tick");
		$this->runTicks(1);
		self::assertTrue($this->observerPowered(2, 1, 0));
		self::assertSame(15, $this->wirePower(3, 1, 0));
		$this->runTicks(2);
		self::assertFalse($this->observerPowered(2, 1, 0), "the pulse lasts one redstone tick");
		self::assertSame(0, $this->wirePower(3, 1, 0));

		//changes on other sides are ignored
		$this->set(2, 1, 1, VanillaBlocks::OAK_PLANKS());
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(3, 1, 0));
	}

	public function testObserverStronglyPowersOutputBlock() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(2, 1, 0, VanillaBlocks::OBSERVER()->setFacing(Facing::WEST));
		$this->set(3, 1, 0, VanillaBlocks::STONE());
		$this->set(4, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->set(1, 1, 0, VanillaBlocks::OAK_PLANKS());
		$this->runTicks(2);
		self::assertTrue($this->world->isBlockPowered(new Vector3(4, 1, 0)));
	}

	public function testObserverSeesWirePowerChanges() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(0, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(0, 1, 1, VanillaBlocks::OBSERVER()->setFacing(Facing::NORTH)); //watches the wire
		$this->set(0, 1, 2, VanillaBlocks::REDSTONE_LAMP());
		$this->runTicks(4);
		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(0, 1, 0));
		$this->runTicks(1);
		self::assertFalse($this->observerPowered(0, 1, 1), "one redstone tick after the wire changed");
		$this->runTicks(1);
		self::assertTrue($this->observerPowered(0, 1, 1));
	}

	public function testObserverClock() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::OBSERVER()->setFacing(Facing::EAST));
		$this->set(2, 1, 0, VanillaBlocks::OBSERVER()->setFacing(Facing::WEST)); //seen by the first one: starts the clock
		$on1 = [];
		$on2 = [];
		$last1 = $last2 = false;
		for($i = 0; $i < 200; ++$i){
			$this->runTicks(1);
			$p1 = $this->observerPowered(1, 1, 0);
			$p2 = $this->observerPowered(2, 1, 0);
			if($p1 && !$last1){
				$on1[] = $i;
			}
			if($p2 && !$last2){
				$on2[] = $i;
			}
			$last1 = $p1;
			$last2 = $p2;
		}
		//BDS 1.26.60 (and Java): a pulse of 2 ticks every 6 ticks per observer, the second one 2 ticks after the first
		//(a powered observer doesn't react to the other one turning on, only to it turning off)
		self::assertGreaterThanOrEqual(30, count($on1), "the observer clock must keep running");
		for($k = 1; $k < count($on1); ++$k){
			self::assertSame(6, $on1[$k] - $on1[$k - 1], "period");
		}
		$first2 = null;
		foreach($on2 as $tick){
			if($tick > $on1[0]){
				$first2 = $tick;
				break;
			}
		}
		self::assertSame($on1[0] + 2, $first2);
		self::assertSame(0, $this->redstone()->getStatistics()["pendingWires"]);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Tripwire
	// ---------------------------------------------------------------------------------------------------------------

	private function hook(int $x, int $y, int $z) : TripwireHook{
		$hook = $this->get($x, $y, $z);
		self::assertInstanceOf(TripwireHook::class, $hook);
		return $hook;
	}

	/**
	 * Hooks at x=0 (on stone at x=-1) and x=4 (on stone at x=5), wire from x=1 to x=3. Wire next to the first hook's
	 * support reads its power.
	 */
	private function buildTripwireLine() : void{
		$this->floor(-3, -2, 7, 2);
		$this->set(-1, 1, 0, VanillaBlocks::STONE());
		$this->set(5, 1, 0, VanillaBlocks::STONE());
		$this->set(-2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(0, 1, 0, VanillaBlocks::TRIPWIRE_HOOK()->setFacing(Facing::EAST));
		$this->get(0, 1, 0)->onPostPlace();
		for($x = 1; $x <= 3; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::TRIPWIRE());
			$this->get($x, 1, 0)->onPostPlace();
		}
		self::assertFalse($this->hook(0, 1, 0)->isConnected(), "no hook at the other end yet");
		$this->set(4, 1, 0, VanillaBlocks::TRIPWIRE_HOOK()->setFacing(Facing::WEST));
		$this->get(4, 1, 0)->onPostPlace();
		$this->runTicks(2);
	}

	public function testTripwireHooksAttach() : void{
		$this->buildTripwireLine();
		self::assertTrue($this->hook(0, 1, 0)->isConnected());
		self::assertTrue($this->hook(4, 1, 0)->isConnected());
		for($x = 1; $x <= 3; ++$x){
			$wire = $this->get($x, 1, 0);
			self::assertInstanceOf(Tripwire::class, $wire);
			self::assertTrue($wire->isConnected(), "wire at x=$x must be attached");
		}
		self::assertFalse($this->hook(0, 1, 0)->isPowered());
		self::assertSame(0, $this->wirePower(-2, 1, 0));
	}

	public function testTripwireTriggeredByEntity() : void{
		$this->buildTripwireLine();
		$item = $this->world->dropItem(new Vector3(2.5, 1.05, 0.5), VanillaItems::STICK(), Vector3::zero());
		self::assertNotNull($item);
		$this->runTicks(2);
		self::assertTrue($this->hook(0, 1, 0)->isPowered());
		self::assertTrue($this->hook(4, 1, 0)->isPowered());
		self::assertSame(15, $this->wirePower(-2, 1, 0), "the hook strongly powers its support");

		$item->flagForDespawn();
		$item->close();
		$this->runTicks(TripwireHook::RECHECK_TICKS + 2);
		self::assertFalse($this->hook(0, 1, 0)->isPowered());
		self::assertSame(0, $this->wirePower(-2, 1, 0));
	}

	public function testTripwireCutWithShearsDisarms() : void{
		$this->buildTripwireLine();
		$shears = VanillaItems::SHEARS();
		$this->world->useBreakOn(new Vector3(2, 1, 0), $shears);
		$this->runTicks(1);
		self::assertFalse($this->hook(0, 1, 0)->isConnected());
		self::assertFalse($this->hook(0, 1, 0)->isPowered(), "cutting the wire with shears must not trigger the hooks");
		self::assertSame(0, $this->wirePower(-2, 1, 0));
	}

	public function testTripwireBrokenWithoutShearsPulses() : void{
		$this->buildTripwireLine();
		$this->breakBlock(2, 1, 0);
		$this->runTicks(1);
		self::assertTrue($this->hook(0, 1, 0)->isPowered(), "breaking an armed wire triggers the hooks");
		$this->runTicks(TripwireHook::RECHECK_TICKS + 1);
		self::assertFalse($this->hook(0, 1, 0)->isPowered());
		self::assertFalse($this->hook(0, 1, 0)->isConnected());
		self::assertFalse($this->hook(4, 1, 0)->isConnected());
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Projectiles on wooden buttons and plates
	// ---------------------------------------------------------------------------------------------------------------

	public function testArrowPressesWoodenButton() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_BUTTON()->setFacing(Facing::UP));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$arrow = new Arrow(Location::fromObject(new Vector3(1.5, 1.1, 0.5), $this->world), null, false);
		$this->runTicks(3);
		$button = $this->get(1, 1, 0);
		self::assertInstanceOf(WoodenButton::class, $button);
		self::assertTrue($button->isPressed(), "an arrow landing on a wooden button presses it");
		self::assertSame(15, $this->wirePower(2, 1, 0));

		$this->runTicks(60);
		$button = $this->get(1, 1, 0);
		self::assertInstanceOf(WoodenButton::class, $button);
		self::assertTrue($button->isPressed(), "the button stays pressed while the arrow is in it");

		$arrow->flagForDespawn();
		$arrow->close();
		$this->runTicks(31);
		$button = $this->get(1, 1, 0);
		self::assertInstanceOf(WoodenButton::class, $button);
		self::assertFalse($button->isPressed());
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}

	public function testArrowDoesNotPressStoneButton() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP));
		new Arrow(Location::fromObject(new Vector3(1.5, 1.1, 0.5), $this->world), null, false);
		$this->runTicks(3);
		self::assertSame(0, $this->world->getBlockPower(new Vector3(2, 1, 0)));
	}

	public function testArrowPressesWoodenPressurePlate() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::OAK_PRESSURE_PLATE());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		new Arrow(Location::fromObject(new Vector3(1.5, 1.1, 0.5), $this->world), null, false);
		$this->runTicks(3);
		self::assertSame(15, $this->wirePower(2, 1, 0));
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Detector rail
	// ---------------------------------------------------------------------------------------------------------------

	public function testDetectorRailIgnoresOtherEntities() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::DETECTOR_RAIL());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->world->dropItem(new Vector3(1.5, 1.05, 0.5), VanillaItems::STICK(), Vector3::zero());
		$this->runTicks(3);
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}

	public function testDetectorRailPoweredByMinecart() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::DETECTOR_RAIL());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$this->set(1, 1, 1, VanillaBlocks::REDSTONE_LAMP());
		$cart = new TestMinecart(Location::fromObject(new Vector3(1.5, 1.0, 0.5), $this->world));
		$this->runTicks(2);
		$rail = $this->get(1, 1, 0);
		self::assertInstanceOf(DetectorRail::class, $rail);
		self::assertTrue($rail->isActivated());
		self::assertSame(15, $this->wirePower(2, 1, 0));
		self::assertSame(7, $rail->getComparatorOutput());
		self::assertTrue($this->world->isBlockPowered(new Vector3(1, -1, 0)), "the block below is strongly powered");

		$cart->flagForDespawn();
		$cart->close();
		$this->runTicks(DetectorRail::RECHECK_TICKS + 1);
		$rail = $this->get(1, 1, 0);
		self::assertInstanceOf(DetectorRail::class, $rail);
		self::assertFalse($rail->isActivated());
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Jukebox and lightning rod
	// ---------------------------------------------------------------------------------------------------------------

	public function testJukeboxPowerAndComparatorOutput() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::JUKEBOX());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$jukebox = $this->get(1, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		self::assertSame(0, $jukebox->getComparatorOutput());

		$jukebox->insertRecord(VanillaItems::RECORD_11());
		$this->world->setBlock($jukebox->getPosition(), $jukebox);
		$this->runTicks(2);
		self::assertSame(15, $this->wirePower(2, 1, 0), "a playing jukebox emits a signal of 15");
		$jukebox = $this->get(1, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		self::assertSame(RecordType::DISK_11->getComparatorSignal(), $jukebox->getComparatorOutput());

		$this->runTicks(RecordType::DISK_11->getDurationSeconds() * 20);
		self::assertSame(0, $this->wirePower(2, 1, 0), "the signal stops when the track ends");
		$jukebox = $this->get(1, 1, 0);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		self::assertSame(11, $jukebox->getComparatorOutput(), "the comparator reads the disc while it is inside");
	}

	public function testRecordComparatorSignals() : void{
		self::assertSame(1, RecordType::DISK_13->getComparatorSignal());
		self::assertSame(15, RecordType::DISK_5->getComparatorSignal());
		self::assertSame(14, RecordType::DISK_RELIC->getComparatorSignal());
		foreach(RecordType::cases() as $type){
			self::assertGreaterThan(0, $type->getDurationSeconds());
		}
	}

	public function testLightningRodPulse() : void{
		$this->floor(-2, -2, 6, 2);
		$this->set(1, 1, 0, VanillaBlocks::LIGHTNING_ROD()->setFacing(Facing::UP));
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		$rod = $this->get(1, 1, 0);
		self::assertInstanceOf(LightningRod::class, $rod);
		$rod->onLightningStrike();
		$this->runTicks(1);
		self::assertSame(15, $this->wirePower(2, 1, 0));
		self::assertTrue($this->world->isBlockPowered(new Vector3(1, -1, 0)), "the rod strongly powers the block it stands on");
		$this->runTicks(LightningRod::STRUCK_POWER_TICKS);
		self::assertSame(0, $this->wirePower(2, 1, 0));
	}
}

/**
 * Stand-in for a minecart (PocketMine has none yet).
 */
class TestMinecart extends Entity implements DetectorRailActivator, NeverSavedWithChunkEntity{
	public static function getNetworkTypeId() : string{ return "minecraft:minecart"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.7, 0.98); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function getDetectorRailComparatorOutput() : int{ return 7; }
}
