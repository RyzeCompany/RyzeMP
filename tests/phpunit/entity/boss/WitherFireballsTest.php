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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\monster\Ghast;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\projectile\DangerousWitherSkull;
use pocketmine\entity\projectile\LargeFireball;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\WitherSkull;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\Explosion;
use pocketmine\world\GameRules;
use pocketmine\world\Position;

final class WitherFireballsTest extends MobTestCase{

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	private function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	private function sturdyCow(float $x, float $y, float $z) : Cow{
		$cow = new Cow($this->loc($x, $y, $z));
		$this->freeze($cow);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		return $cow;
	}

	private function readyWither(float $x = 0.5, float $y = 1, float $z = 0.5) : Wither{
		$wither = new Wither($this->loc($x, $y, $z));
		$this->runTicks(Wither::SPAWN_INVULNERABLE_TICKS + 2);
		return $wither;
	}

	private function flyFireball(SmallFireball $fireball) : void{
		for($i = 0; $i < 20 && !$fireball->isClosed() && !$fireball->isFlaggedForDespawn(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($fireball->isClosed() || $fireball->isFlaggedForDespawn(), "the fireball hit something");
	}

	public function testBlazeFireballSparesFireImmuneMobs() : void{
		$shooter = new Blaze($this->loc(-12.5, 1, 0.5));
		$this->freeze($shooter);
		$blaze = new Blaze($this->loc(0.5, 1, 0.5));
		$this->freeze($blaze);
		$health = $blaze->getHealth();

		$fireball = new SmallFireball($this->loc(-2.5, 1.9, 0.5), $shooter);
		$fireball->setMotion(new Vector3(1.3, 0, 0));
		$this->flyFireball($fireball);
		self::assertSame($health, $blaze->getHealth(), "fire immune mobs take no fireball damage");
		self::assertNull($blaze->getTargetEntityId(), "and don't fight back");

		$cow = $this->sturdyCow(10.5, 1, 0.5);
		$fireball = new SmallFireball($this->loc(7.5, 1.6, 0.5), $shooter);
		$fireball->setMotion(new Vector3(1.3, 0, 0));
		$this->flyFireball($fireball);
		self::assertLessThan(1000, $cow->getHealth(), "other mobs are hurt");
		self::assertTrue($cow->isOnFire());
	}

	public function testGhastFireballFliesAtConstantSpeed() : void{
		$ghast = new Ghast($this->loc(0.5, 12, 0.5));
		$cow = $this->sturdyCow(40.5, 1, 0.5);
		$fireball = $ghast->shootFireball($cow);
		self::assertEqualsWithDelta(LargeFireball::SPEED, $fireball->getMotion()->length(), 0.0001, "launched at 1.6 blocks per tick");
		$this->runTicks(2);
		self::assertFalse($fireball->isFlaggedForDespawn());
		self::assertEqualsWithDelta(LargeFireball::SPEED, $fireball->getMotion()->length(), 0.0001, "no acceleration or drag");
	}

	public function testSmallFireballAndBlueSkullAreDeflected() : void{
		$blaze = new Blaze($this->loc(-10.5, 1, 0.5));
		$this->freeze($blaze);
		$batter = new Zombie($this->loc(6.5, 1, 0.5, 90.0)); //looking west
		$this->freeze($batter);

		$fireball = new SmallFireball($this->loc(4.5, 2, 0.5), $blaze);
		$fireball->setMotion(new Vector3(1.3, 0, 0));
		$ev = new EntityDamageByEntityEvent($batter, $fireball, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3);
		$fireball->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertLessThan(0, $fireball->getMotion()->x, "sent back where the attacker looks");
		self::assertEqualsWithDelta(1.3, $fireball->getMotion()->length(), 0.0001);
		self::assertSame($batter->getId(), $fireball->getOwningEntityId());

		$skull = new DangerousWitherSkull($this->loc(4.5, 2, 0.5), $blaze);
		$skull->setMotion(new Vector3(DangerousWitherSkull::SPEED, 0, 0));
		$skull->attack(new EntityDamageByEntityEvent($batter, $skull, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3));
		self::assertLessThan(0, $skull->getMotion()->x);
		self::assertSame($batter->getId(), $skull->getOwningEntityId());
	}

	public function testBlazeFireDependsOnMobGriefing() : void{
		$blaze = new Blaze($this->loc(-10.5, 1, 0.5));
		$this->freeze($blaze);
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$fireball = new SmallFireball($this->loc(0.5, 3.5, 0.5), $blaze);
		$fireball->setMotion(new Vector3(0, -1.3, 0));
		$this->flyFireball($fireball);
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId(), "no fire with mobGriefing off");

		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		$fireball = new SmallFireball($this->loc(4.5, 3.5, 0.5), $blaze);
		$fireball->setMotion(new Vector3(0, -1.3, 0));
		$this->flyFireball($fireball);
		self::assertSame(BlockTypeIds::FIRE, $this->get(4, 1, 0)->getTypeId(), "fire with mobGriefing on");
	}

	public function testWitherRegenerates() : void{
		$wither = $this->readyWither();
		$wither->setHealth(100);
		$this->runTicks(Wither::REGEN_INTERVAL);
		self::assertEqualsWithDelta(100 + Wither::REGEN_HEALTH, $wither->getHealth(), 0.001);
	}

	public function testWitherHealsWhenItsSkullKills() : void{
		$wither = $this->readyWither();
		$wither->setHealth(100);
		$cow = new Cow($this->loc(15.5, 1, 0.5));
		$this->freeze($cow);
		$cow->setHealth(1);
		$skull = new WitherSkull($this->loc(12.5, 1.5, 0.5), $wither);
		$skull->setMotion(new Vector3(WitherSkull::SPEED, 0, 0));
		for($i = 0; $i < 10 && $cow->isAlive(); ++$i){
			$this->runTicks(1);
		}
		self::assertFalse($cow->isAlive());
		self::assertGreaterThanOrEqual(100 + WitherSkull::KILL_HEAL, $wither->getHealth(), "+5 for the kill");
		self::assertLessThanOrEqual(100 + WitherSkull::KILL_HEAL + Wither::REGEN_HEALTH, $wither->getHealth());
	}

	public function testWitherDoesNotBreakBlocksWithoutMobGriefing() : void{
		foreach([false, true] as $griefing){
			$this->world->setGameRule(GameRules::MOB_GRIEFING, $griefing);
			$x = $griefing ? 12 : -12;
			$wither = $this->readyWither($x + 0.5, 3, 0.5);
			$wither->teleport(new Vector3($x + 0.5, 3, 0.5));
			$wither->setMotion(Vector3::zero());
			//a stone cage around its body
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					for($y = 2; $y <= 7; ++$y){
						if($dx !== 0 || $dz !== 0 || $y === 2 || $y === 7){
							$this->set($x + $dx, $y, $dz, VanillaBlocks::STONE());
						}
					}
				}
			}
			$wither->attack(new EntityDamageEvent($wither, EntityDamageEvent::CAUSE_CUSTOM, 5));
			$this->runTicks(25);
			$stone = 0;
			for($dx = -1; $dx <= 1; ++$dx){
				for($dz = -1; $dz <= 1; ++$dz){
					for($y = 2; $y <= 7; ++$y){
						if($this->get($x + $dx, $y, $dz)->getTypeId() === BlockTypeIds::STONE){
							++$stone;
						}
					}
				}
			}
			if($griefing){
				self::assertLessThan(8 * 6 + 2, $stone, "the hurt wither breaks the blocks around it");
			}else{
				self::assertSame(8 * 6 + 2, $stone, "no block breaking with mobGriefing off");
			}
			$wither->flagForDespawn();
		}
	}

	public function testDragonDoesNotDestroyBlocksWithoutMobGriefing() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		for($x = -6; $x <= 6; ++$x){
			for($y = 18; $y <= 28; ++$y){
				$this->set($x, $y, 0, VanillaBlocks::STONE());
			}
		}
		$dragon = new EnderDragon($this->loc(0.5, 20.5, 0.5));
		$dragon->setPhase(EnderDragon::PHASE_HOLDING);
		$this->runTicks(12);
		for($x = -6; $x <= 6; ++$x){
			for($y = 18; $y <= 28; ++$y){
				self::assertSame(BlockTypeIds::STONE, $this->get($x, $y, 0)->getTypeId(), "block at $x $y 0");
			}
		}
	}

	public function testNetherStarSurvivesExplosions() : void{
		$star = $this->world->dropItem(new Vector3(0.5, 1, 0.5), VanillaItems::NETHER_STAR(), Vector3::zero());
		$dirt = $this->world->dropItem(new Vector3(1.5, 1, 0.5), VanillaItems::DIAMOND(), Vector3::zero());
		self::assertNotNull($star);
		self::assertNotNull($dirt);
		$explosion = new Explosion(new Position(1, 1.5, 0.5, $this->world), 4.0);
		$explosion->explodeB();
		self::assertTrue($star->isAlive() && !$star->isClosed() && !$star->isFlaggedForDespawn(), "the nether star survives");
		self::assertFalse($dirt->isAlive() && !$dirt->isFlaggedForDespawn(), "other items are destroyed");
	}

	public function testBlueSkullBreaksObsidian() : void{
		$breaks = ["blue" => 0, "black" => 0];
		for($i = 0; $i < 10; ++$i){
			foreach([true, false] as $dangerous){
				$x = $dangerous ? 8 : -8;
				$this->set($x, 2, $i - 5, VanillaBlocks::OBSIDIAN());
				$location = $this->loc($x - 0.5, 2.5, $i - 5 + 0.5);
				$skull = $dangerous ? new DangerousWitherSkull($location, null) : new WitherSkull($location, null);
				$skull->explode();
				if($this->get($x, 2, $i - 5)->getTypeId() !== BlockTypeIds::OBSIDIAN){
					++$breaks[$dangerous ? "blue" : "black"];
				}
			}
		}
		self::assertSame(0, $breaks["black"], "black skulls keep the normal resistance");
		self::assertGreaterThan(5, $breaks["blue"], "blue skulls treat obsidian as resistance 4");
	}

	public function testWitherTargetsItsTopDamager() : void{
		$wither = $this->readyWither();
		$a = $this->sturdyCow(10.5, 1, 0.5);
		$b = $this->sturdyCow(-10.5, 1, 0.5);
		$wither->attack(new EntityDamageByEntityEvent($a, $wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
		$this->runTicks(15);
		self::assertSame($a->getId(), $wither->getTargetEntityId());
		$wither->attack(new EntityDamageByEntityEvent($b, $wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 20));
		$this->runTicks(15);
		self::assertSame($b->getId(), $wither->getTargetEntityId(), "switches to whoever dealt the most damage");
		$wither->attack(new EntityDamageByEntityEvent($a, $wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 30));
		$this->runTicks(15);
		self::assertSame($a->getId(), $wither->getTargetEntityId());
	}

	public function testArmoredWitherStopsHovering() : void{
		foreach([false, true] as $armored){
			$z = $armored ? 15.5 : -15.5;
			$wither = $this->readyWither(-6.5, 2, $z);
			$wither->teleport(new Vector3(-6.5, 2, $z));
			$cow = $this->sturdyCow(6.5, 1, $z);
			if($armored){
				$wither->setHealth($wither->getMaxHealth() / 2 + 1);
				$wither->attack(new EntityDamageByEntityEvent($cow, $wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
				self::assertTrue($wither->isArmored());
			}
			$wither->setAttackTarget($cow);
			$this->runTicks(60);
			if($armored){
				self::assertLessThan(3.5, $wither->getPosition()->y, "the armoured wither stays level with its target");
			}else{
				self::assertGreaterThan(4.0, $wither->getPosition()->y, "the wither hovers above its target");
			}
			$wither->flagForDespawn();
			$cow->flagForDespawn();
		}
	}

	public function testWitherFamilyTargeting() : void{
		$wither = $this->readyWither();
		$ghast = new Ghast($this->loc(10.5, 6, 0.5));
		self::assertFalse($wither->isValidTarget($ghast), "ghasts can't be attacked");
		self::assertTrue($wither->isValidTarget($this->sturdyCow(-8.5, 1, 0.5)));
		$piglin = new Piglin($this->loc(5.5, 1, 5.5));
		$piglin->setBaby(false); //5% spawn as babies, which never attack (the random state depends on the tests run before)
		self::assertTrue($piglin->wouldAttack($wither), "piglins attack the wither");
	}
}
