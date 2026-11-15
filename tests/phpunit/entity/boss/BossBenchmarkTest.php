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

namespace pocketmine\entity\boss;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenAnger;
use pocketmine\timings\TimingsHandler;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of one boss of each kind fighting a (very sturdy) cow. Short runs as normal tests; set BOSS_BENCH=1 for
 * 1200 ticks each and a report of the cost per boss tick (the world tick minus the same world without the boss).
 *
 * @group benchmark
 */
final class BossBenchmarkTest extends MobTestCase{

	private function ticks() : int{
		return getenv("BOSS_BENCH") === "1" ? 1200 : 60;
	}

	private function cow(float $x, float $z) : Cow{
		$cow = new Cow(new Location($x, 1, $z, $this->world, 0, 0));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$cow->setMaxHealth(1_000_000);
		$cow->setHealth(1_000_000);
		return $cow;
	}

	/**
	 * Returns the time per world tick in microseconds.
	 */
	private function measure(int $ticks) : float{
		$start = hrtime(true);
		$this->runTicks($ticks);
		return (hrtime(true) - $start) / $ticks / 1e3;
	}

	private function report(string $name, Mob $boss, float $withBoss, float $baseline) : void{
		self::assertFalse($boss->isClosed(), "$name should still be there");
		if(getenv("BOSS_BENCH") === "1"){
			fwrite(STDERR, sprintf("[boss bench] %s: %.1f us per world tick, %.1f us without it -> %.1f us per boss tick\n", $name, $withBoss, $baseline, $withBoss - $baseline));
		}
	}

	/**
	 * Returns a sturdy cow and the world tick time with it alone.
	 *
	 * @return array{Cow, float}
	 */
	private function setUpTarget() : array{
		TimingsHandler::setEnabled(false);
		mt_srand(42);
		$cow = $this->cow(6.5, 0.5);
		$this->runTicks(20);
		return [$cow, $this->measure($this->ticks())];
	}

	public function testWardenTickCost() : void{
		[$cow, $baseline] = $this->setUpTarget();
		//angry at the cow: chases and hits it
		$warden = new Warden(new Location(-10.5, 1, 0.5, $this->world, 0, 0));
		$warden->setNameTag("bench"); //named wardens don't dig back
		$this->runTicks(20);
		$this->report("warden (idle: wandering, sniffing)", $warden, $this->measure($this->ticks()), $baseline);
		$warden->increaseAnger($cow, WardenAnger::MAX_ANGER);
		$this->runTicks(20);
		$this->report("warden (fighting, incl. the knocked back cow)", $warden, $this->measure($this->ticks()), $baseline);
	}

	public function testWitherTickCost() : void{
		TimingsHandler::setEnabled(false);
		mt_srand(42);
		$this->runTicks(20);
		$empty = $this->measure($this->ticks());
		$wither = new Wither(new Location(0.5, 6, 0.5, $this->world, 0, 0));
		$this->runTicks(Wither::SPAWN_INVULNERABLE_TICKS + 2);
		//the spawn explosion blasted items out of the floor: they are not the wither's cost
		foreach($this->world->getEntities() as $entity){
			if($entity !== $wither){
				$entity->close();
			}
		}
		$this->report("wither (no target: flying around)", $wither, $this->measure($this->ticks()), $empty);
	}

	public function testWitherShootingTickCost() : void{
		[$cow, $baseline] = $this->setUpTarget();
		$wither = new Wither(new Location(0.5, 6, 0.5, $this->world, 0, 0));
		$this->runTicks(Wither::SPAWN_INVULNERABLE_TICKS + 2);
		$wither->setAttackTarget($cow);
		//the skulls' flight and explosions (and the items they blast out of the floor) count
		$this->report("wither (shooting, incl. skulls and explosions)", $wither, $this->measure($this->ticks()), $baseline);
	}

	public function testEnderDragonTickCost() : void{
		[, $baseline] = $this->setUpTarget();
		$dragon = new EnderDragon(new Location(0.5, 30, 0.5, $this->world, 0, 0));
		$this->runTicks(20);
		$this->report("ender dragon (holding pattern)", $dragon, $this->measure($this->ticks()), $baseline);
	}
}
