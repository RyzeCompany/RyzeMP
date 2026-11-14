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

use pocketmine\block\utils\PotentSulfurState;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Potent sulfur states, noxious gas and geysers.
 */
final class PotentSulfurTest extends RedstoneTestCase{
	private const Y = 10;

	private function sulfur() : PotentSulfur{
		$block = $this->get(0, self::Y, 0);
		self::assertInstanceOf(PotentSulfur::class, $block);
		return $block;
	}

	private function state() : PotentSulfurState{
		return $this->sulfur()->getSulfurState();
	}

	/**
	 * Builds potent sulfur on the given block, with the given number of water blocks above it (in a 7x7 pool walled
	 * by stone below) and air above them.
	 */
	private function buildSpring(Block $below, int $waterBlocks) : void{
		$this->set(0, self::Y - 1, 0, $below);
		for($x = -3; $x <= 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				if($x !== 0 || $z !== 0){
					$this->set($x, self::Y, $z, VanillaBlocks::STONE());
				}
				for($i = 1; $i <= $waterBlocks; ++$i){
					$this->set($x, self::Y + $i, $z, VanillaBlocks::WATER());
				}
			}
		}
		$this->set(0, self::Y, 0, VanillaBlocks::POTENT_SULFUR());
		$this->runTicks(1);
	}

	public function testStates() : void{
		$this->set(0, self::Y, 0, VanillaBlocks::POTENT_SULFUR());
		$calc = fn(PotentSulfurState $current = PotentSulfurState::DRY) => PotentSulfur::calculateState($this->world, 0, self::Y, 0, $current);
		self::assertSame(PotentSulfurState::DRY, $calc());

		$this->set(0, self::Y + 1, 0, VanillaBlocks::WATER());
		self::assertSame(PotentSulfurState::WET, $calc());
		$this->set(0, self::Y - 1, 0, VanillaBlocks::MAGMA());
		self::assertSame(PotentSulfurState::DORMANT, $calc());
		self::assertSame(PotentSulfurState::ERUPTING, $calc(PotentSulfurState::ERUPTING), "an eruption isn't interrupted");
		$this->set(0, self::Y - 1, 0, VanillaBlocks::LAVA());
		self::assertSame(PotentSulfurState::CONTINUOUS, $calc());
		$this->set(0, self::Y - 1, 0, VanillaBlocks::LAVA()->setDecay(2));
		self::assertSame(PotentSulfurState::WET, $calc(), "flowing lava");

		$this->set(0, self::Y + 1, 0, VanillaBlocks::WATER()->setDecay(1));
		self::assertSame(PotentSulfurState::DRY, $calc(), "flowing water");
	}

	public function testStateFollowsNeighbours() : void{
		$this->set(0, self::Y - 1, 0, VanillaBlocks::MAGMA());
		$this->set(0, self::Y, 0, VanillaBlocks::POTENT_SULFUR());
		$this->runTicks(1);
		self::assertSame(PotentSulfurState::DRY, $this->state());
		self::assertFalse($this->world->isBlockUpdateScheduled(0, self::Y, 0), "dry sulfur does nothing");

		$this->set(0, self::Y + 1, 0, VanillaBlocks::WATER());
		$this->set(1, self::Y + 1, 0, VanillaBlocks::STONE());
		$this->set(-1, self::Y + 1, 0, VanillaBlocks::STONE());
		$this->set(0, self::Y + 1, 1, VanillaBlocks::STONE());
		$this->set(0, self::Y + 1, -1, VanillaBlocks::STONE());
		$this->runTicks(1);
		self::assertSame(PotentSulfurState::DORMANT, $this->state());
		self::assertTrue($this->world->isBlockUpdateScheduled(0, self::Y, 0));

		$this->set(0, self::Y + 1, 0, VanillaBlocks::AIR());
		$this->runTicks(1);
		self::assertSame(PotentSulfurState::DRY, $this->state());
	}

	public function testCountdownSteps() : void{
		for($x = 0; $x < 20; ++$x){
			$dormant = PotentSulfur::getCountdownSteps(1234, $x, 5, -$x, PotentSulfurState::DORMANT, 1);
			self::assertGreaterThanOrEqual(15, $dormant);
			self::assertLessThanOrEqual(30, $dormant);
			self::assertSame($dormant + 30, PotentSulfur::getCountdownSteps(1234, $x, 5, -$x, PotentSulfurState::DORMANT, 4), "10 more steps per water block");
			self::assertSame($dormant, PotentSulfur::getCountdownSteps(1234, $x, 5, -$x, PotentSulfurState::DORMANT, 1), "the same every time for a block");
			$erupting = PotentSulfur::getCountdownSteps(1234, $x, 5, -$x, PotentSulfurState::ERUPTING, 2);
			self::assertGreaterThanOrEqual(2, $erupting);
			self::assertLessThanOrEqual(3, $erupting);
		}
	}

	public function testDormantErupts() : void{
		$this->buildSpring(VanillaBlocks::MAGMA(), 1);
		self::assertSame(PotentSulfurState::DORMANT, $this->state());
		$dormantTicks = PotentSulfur::getCountdownSteps($this->world->getSeed(), 0, self::Y, 0, PotentSulfurState::DORMANT, 1) * PotentSulfur::COUNTDOWN_STEP_TICKS;
		$eruptingTicks = PotentSulfur::getCountdownSteps($this->world->getSeed(), 0, self::Y, 0, PotentSulfurState::ERUPTING, 1) * PotentSulfur::COUNTDOWN_STEP_TICKS;

		$ticks = 0;
		while($this->state() === PotentSulfurState::DORMANT && $ticks < 1000){
			$this->runTicks(1);
			++$ticks;
		}
		self::assertSame(PotentSulfurState::ERUPTING, $this->state());
		self::assertEqualsWithDelta($dormantTicks, $ticks, PotentSulfur::GAS_INTERVAL_TICKS + 1);

		$ticks = 0;
		while($this->state() === PotentSulfurState::ERUPTING && $ticks < 1000){
			$this->runTicks(1);
			++$ticks;
		}
		self::assertSame(PotentSulfurState::DORMANT, $this->state());
		self::assertEqualsWithDelta($eruptingTicks, $ticks, 2);
	}

	public function testGasGivesNauseaNearTheSource() : void{
		$this->buildSpring(VanillaBlocks::STONE(), 1);
		self::assertSame(PotentSulfurState::WET, $this->state());
		self::assertSame(self::Y + 2, PotentSulfur::findGasSourceY($this->world, 0, self::Y, 0));

		//swimming at the surface: eyes in the air, the block below them is water
		$near = new Zombie(Location::fromObject(new Vector3(1.5, self::Y + 1, 0.5), $this->world));
		$far = new Zombie(Location::fromObject(new Vector3(-2.7, self::Y + 1, 2.5), $this->world));
		$under = new Zombie(Location::fromObject(new Vector3(0.5, self::Y + 1, -1.5), $this->world));
		$under->teleport(new Vector3(0.5, self::Y + 0.2, -1.5));
		$this->sulfur()->onScheduledUpdate();

		self::assertTrue($near->getEffects()->has(VanillaEffects::NAUSEA()));
		self::assertSame(PotentSulfur::NAUSEA_DURATION_TICKS, $near->getEffects()->get(VanillaEffects::NAUSEA())?->getDuration());
		self::assertFalse($far->getEffects()->has(VanillaEffects::NAUSEA()), "more than 3 blocks away");
		self::assertFalse($under->getEffects()->has(VanillaEffects::NAUSEA()), "eyes under water");
	}

	public function testDrySulfurGivesNoGas() : void{
		$this->set(0, self::Y, 0, VanillaBlocks::POTENT_SULFUR());
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, self::Y + 1, 0.5), $this->world));
		$this->sulfur()->onScheduledUpdate();
		self::assertFalse($zombie->getEffects()->has(VanillaEffects::NAUSEA()));
	}

	public function testGeyserLaunchesEntitiesUpToTheCap() : void{
		$this->buildSpring(VanillaBlocks::LAVA(), 1);
		self::assertSame(PotentSulfurState::CONTINUOUS, $this->state());
		$this->set(0, self::Y + 5, 0, VanillaBlocks::STONE());

		$inside = new Zombie(Location::fromObject(new Vector3(0.5, self::Y + 2, 0.5), $this->world));
		$beside = new Zombie(Location::fromObject(new Vector3(2.5, self::Y + 2, 2.5), $this->world));
		$aboveTheRoof = new Zombie(Location::fromObject(new Vector3(0.5, self::Y + 6, 0.5), $this->world));
		$inside->setMotion(Vector3::zero());
		$inside->setFallDistance(8);

		$this->sulfur()->onScheduledUpdate();
		self::assertEqualsWithDelta(PotentSulfur::LAUNCH_FORCE, $inside->getMotion()->y, 0.0001);
		self::assertEqualsWithDelta(1.0, $inside->getFallDistance(), 0.0001, "rising entities forget their fall");
		self::assertEqualsWithDelta(0.0, $beside->getMotion()->y, 0.0001);
		self::assertEqualsWithDelta(0.0, $aboveTheRoof->getMotion()->y, 0.0001, "the column stops at the first solid block");

		$cap = PotentSulfur::LAUNCH_BASE_SPEED + PotentSulfur::LAUNCH_SPEED_PER_WATER_BLOCK;
		for($i = 0; $i < 5; ++$i){
			$this->sulfur()->onScheduledUpdate();
		}
		self::assertGreaterThanOrEqual($cap, $inside->getMotion()->y);
		self::assertLessThan($cap + PotentSulfur::LAUNCH_FORCE, $inside->getMotion()->y, "no push once fast enough");
	}

	public function testContinuousGeyserStaysScheduled() : void{
		$this->buildSpring(VanillaBlocks::LAVA(), 2);
		self::assertSame(PotentSulfurState::CONTINUOUS, $this->state());
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, self::Y + 8, 0.5), $this->world));
		$this->runTicks(1);
		self::assertTrue($this->world->isBlockUpdateScheduled(0, self::Y, 0));
		self::assertGreaterThan(-0.1, $zombie->getMotion()->y, "12 blocks of column with 2 water blocks");
	}
}
