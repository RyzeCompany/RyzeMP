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

namespace pocketmine\block;

use pocketmine\block\tile\Container;
use pocketmine\entity\Entity;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationListener;
use pocketmine\world\vibration\VibrationManager;
use function array_filter;
use function array_key_first;
use function count;

/**
 * Bells rung by redstone, vibrations of redstone-driven mechanisms and amethyst resonance.
 */
final class RedstoneLateTest extends RedstoneTestCase{

	/** @phpstan-var list<array{GameEvent, Vector3}> */
	private array $heard = [];

	private function listen() : void{
		$this->heard = [];
		$this->world->getVibrations()->addListener(new class(function(GameEvent $event, Vector3 $pos) : void{ $this->heard[] = [$event, $pos]; }) implements VibrationListener{
			/** @phpstan-param \Closure(GameEvent, Vector3) : void $record */
			public function __construct(private \Closure $record){}

			public function getListenerPosition() : Vector3{ return new Vector3(0, 1, 0); }

			public function getListenerRange() : int{ return 32; }

			public function onVibration(Vector3 $pos, GameEvent $event, ?Entity $source, ?Block $affected, float $distance) : void{
				($this->record)($event, $pos);
			}
		});
	}

	private function heardCount(GameEvent $event) : int{
		return count(array_filter($this->heard, fn(array $e) => $e[0] === $event));
	}

	private function sensor(int $x, int $y, int $z) : SculkSensor{
		$sensor = $this->get($x, $y, $z);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		return $sensor;
	}

	public function testBellRingsOncePerRisingEdge() : void{
		$this->floor(-4, -4, 4, 4);
		$this->set(0, 1, 0, VanillaBlocks::BELL()->setFacing(Facing::NORTH));
		$this->runTicks(1);
		$this->listen();

		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		$bell = $this->get(0, 1, 0);
		self::assertInstanceOf(Bell::class, $bell);
		self::assertTrue($bell->isPowered(), "toggle_bit remembers the power");
		self::assertSame(1, $this->heardCount(GameEvent::BLOCK_CHANGE), "rung by the rising edge");

		//more neighbour updates while powered: no ring
		$this->set(0, 1, 1, VanillaBlocks::STONE());
		$this->set(0, 1, 1, VanillaBlocks::AIR());
		$this->runTicks(2);
		self::assertSame(1, $this->heardCount(GameEvent::BLOCK_CHANGE));

		//falling edge: no ring
		$this->set(-1, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(2);
		$bell = $this->get(0, 1, 0);
		self::assertInstanceOf(Bell::class, $bell);
		self::assertFalse($bell->isPowered());
		self::assertSame(1, $this->heardCount(GameEvent::BLOCK_CHANGE));

		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		self::assertSame(2, $this->heardCount(GameEvent::BLOCK_CHANGE), "the next rising edge rings again");
	}

	public function testPistonIsHeardEightBlocksAway() : void{
		$this->floor(-2, -2, 4, 10);
		$this->set(0, 1, 0, VanillaBlocks::PISTON()->setFacing(Facing::EAST));
		$this->set(0, 1, 8, VanillaBlocks::SCULK_SENSOR());
		$this->runTicks(2);
		$this->listen();

		$this->set(-1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(4); //start delay + travel
		self::assertSame(1, $this->heardCount(GameEvent::BLOCK_ACTIVATE));
		$this->runTicks(8);
		$sensor = $this->sensor(0, 1, 8);
		self::assertTrue($sensor->isActive());
		self::assertSame(GameEvent::BLOCK_ACTIVATE->getFrequency(), $sensor->getComparatorOutput());

		$this->set(-1, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(8);
		self::assertSame(1, $this->heardCount(GameEvent::BLOCK_DEACTIVATE), "retracting");
		self::assertSame(1, $this->heardCount(GameEvent::BLOCK_ACTIVATE));
	}

	public function testRedstoneOpensWithVibrations() : void{
		$this->floor(-2, -2, 14, 2);
		$this->set(0, 1, 0, VanillaBlocks::OAK_DOOR());
		$this->set(0, 2, 0, VanillaBlocks::OAK_DOOR()->setTop(true));
		$this->set(5, 1, 0, VanillaBlocks::OAK_TRAPDOOR());
		$this->set(10, 1, 0, VanillaBlocks::OAK_FENCE_GATE());
		$this->runTicks(1);
		$this->listen();

		foreach([1, 6, 11] as $x){
			$this->set($x, 1, 0, VanillaBlocks::REDSTONE());
		}
		$this->runTicks(2);
		self::assertSame(3, $this->heardCount(GameEvent::BLOCK_OPEN), "door, trapdoor and fence gate");
		self::assertSame(0, $this->heardCount(GameEvent::BLOCK_CLOSE));

		foreach([1, 6, 11] as $x){
			$this->set($x, 1, 0, VanillaBlocks::AIR());
		}
		$this->runTicks(2);
		self::assertSame(3, $this->heardCount(GameEvent::BLOCK_CLOSE));
		self::assertSame(3, $this->heardCount(GameEvent::BLOCK_OPEN), "no event for repeated updates");
	}

	public function testOnlyEmptyDispenserVibrates() : void{
		$this->floor(-2, -2, 8, 2);
		$this->set(0, 1, 0, VanillaBlocks::DISPENSER()->setFacing(Facing::UP));
		$this->set(5, 1, 0, VanillaBlocks::DROPPER()->setFacing(Facing::UP));
		$this->runTicks(1);
		$tile = $this->world->getTileAt(5, 1, 0);
		self::assertInstanceOf(Container::class, $tile);
		$tile->getInventory()->addItem(VanillaItems::STICK());
		$this->listen();

		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->set(5, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 2);
		$activations = array_filter($this->heard, fn(array $e) => $e[0] === GameEvent::BLOCK_ACTIVATE);
		self::assertCount(1, $activations, "BDS 1.26.52: only the empty dispenser vibrates");
		self::assertSame(0, $activations[array_key_first($activations)][1]->getFloorX());
	}

	public function testAmethystResonanceReachesCalibratedSensor() : void{
		$this->floor(-6, -2, 16, 2);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		//calibrated sensor 13 blocks from the amethyst, listening to frequency 13 only (input on its west side)
		$this->set(14, 1, 0, VanillaBlocks::CALIBRATED_SCULK_SENSOR()->setFacing(Facing::WEST));
		$this->set(13, 1, 0, VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT()->setOutputSignalStrength(GameEvent::BLOCK_PLACE->getFrequency()));
		$this->runTicks(2);

		//without amethyst the calibrated sensor is too far from the vibration (17 blocks)
		$this->world->addGameEvent(new Vector3(-3, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(20);
		self::assertTrue($this->sensor(0, 1, 0)->getPhase() !== SculkSensor::PHASE_INACTIVE);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(14, 1, 0)->getPhase());
		$this->runTicks(SculkSensor::ACTIVE_TICKS + SculkSensor::COOLDOWN_TICKS);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(0, 1, 0)->getPhase());

		//a different frequency resonates too, but the calibrated sensor filters it out
		$this->set(1, 1, 0, VanillaBlocks::AMETHYST());
		$this->listen();
		$this->world->addGameEvent(new Vector3(-3, 1, 0), GameEvent::BLOCK_DESTROY);
		$this->runTicks(20);
		self::assertSame(1, $this->heardCount(GameEvent::RESONATE_12));
		self::assertSame(SculkSensor::PHASE_INACTIVE, $this->sensor(14, 1, 0)->getPhase());
		$this->runTicks(SculkSensor::ACTIVE_TICKS + SculkSensor::COOLDOWN_TICKS);

		$this->world->addGameEvent(new Vector3(-3, 1, 0), GameEvent::BLOCK_PLACE);
		$this->runTicks(3 + 13);
		self::assertSame(1, $this->heardCount(GameEvent::RESONATE_13));
		$sensor = $this->sensor(14, 1, 0);
		self::assertSame(SculkSensor::PHASE_ACTIVE, $sensor->getPhase(), "wireless redstone");
		self::assertSame(13, $sensor->getComparatorOutput());
		self::assertSame(VibrationManager::getPowerForDistance(13, 16), $sensor->getOutputPower());
	}

	public function testResonanceEvents() : void{
		for($frequency = 1; $frequency <= 15; ++$frequency){
			self::assertSame($frequency, GameEvent::resonance($frequency)->getFrequency());
			self::assertTrue(GameEvent::resonance($frequency)->isHeardBySensors());
			self::assertFalse(GameEvent::resonance($frequency)->isIgnoredWhenSneaking());
		}
	}
}
