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
use pocketmine\entity\object\DragonBreathCloud;
use pocketmine\entity\projectile\DragonFireball;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use function floor;
use function max;

final class EnderDragonTest extends MobTestCase{

	private function dragon(float $x = 0.5, float $y = 20, float $z = 0.5) : EnderDragon{
		return new EnderDragon(new Location($x, $y, $z, $this->world, 0, 0));
	}

	private static function isGone(EnderDragon $dragon) : bool{
		return $dragon->isClosed() || $dragon->isFlaggedForDespawn();
	}

	public function testBasics() : void{
		$dragon = $this->dragon();
		self::assertSame(200, $dragon->getMaxHealth());
		self::assertSame(13.0, $dragon->getSize()->getWidth());
		self::assertFalse($dragon->canDespawn());
		self::assertNull($dragon->getPerchPosition(), "no perching outside the End");
		self::assertSame(EnderDragon::XP, $dragon->getXpDropAmount());
		self::assertEqualsWithDelta(1.0, $dragon->getBossBar()->getPercent(), 0.001);
		self::assertSame("Ender Dragon", $dragon->getBossBar()->getTitle());
	}

	public function testFliesAroundItsSpawnPoint() : void{
		$dragon = $this->dragon(0.5, 20, 0.5);
		$maxDist = 0.0;
		for($i = 0; $i < 400; ++$i){
			$this->runTicks(1);
			$maxDist = max($maxDist, $dragon->getPosition()->distance(new Vector3(0.5, 20, 0.5)));
		}
		self::assertGreaterThan(10, $maxDist, "the dragon should fly away from its spawn point");
		self::assertLessThan(70, $dragon->getPosition()->distance(new Vector3(0.5, 20, 0.5)), "and keep circling around it");
		self::assertGreaterThan(20, $dragon->getPosition()->y, "it flies above its spawn point");
	}

	public function testOnlyPlayersAndExplosionsHurt() : void{
		$dragon = $this->dragon();
		$cow = new Cow(new Location(3.5, 1, 0.5, $this->world, 0, 0));
		$ev = new EntityDamageByEntityEvent($cow, $dragon, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10);
		$dragon->attack($ev);
		self::assertTrue($ev->isCancelled());
		foreach([EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_FALL, EntityDamageEvent::CAUSE_MAGIC, EntityDamageEvent::CAUSE_VOID] as $cause){
			$ev = new EntityDamageEvent($dragon, $cause, 10);
			$dragon->attack($ev);
			self::assertTrue($ev->isCancelled(), "cause $cause");
		}
	}

	public function testHitsAwayFromTheHeadAreReduced() : void{
		$dragon = $this->dragon();
		$ev = new EntityDamageEvent($dragon, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 20);
		$dragon->attack($ev);
		self::assertFalse($ev->isCancelled());
		self::assertEqualsWithDelta(20 / 4 + 1, $ev->getFinalDamage(), 0.001, "a quarter plus one");
		self::assertEqualsWithDelta(200 - 6, $dragon->getHealth(), 0.001);
	}

	public function testBedExplosionAtTheHeadDoesFullDamage() : void{
		$dragon = $this->dragon();
		$head = $dragon->getHeadPosition();
		$bed = $this->world->getBlockAt((int) floor($head->x), (int) floor($head->y), (int) floor($head->z));
		$ev = new EntityDamageByBlockEvent($bed, $dragon, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 20);
		$dragon->attack($ev);
		self::assertFalse($ev->isCancelled());
		self::assertEqualsWithDelta(20, $ev->getFinalDamage(), 0.001, "a bed exploding at the head hits the head");

		$this->runTicks(20);
		$head = $dragon->getHeadPosition();
		$farBed = $this->world->getBlockAt((int) floor($head->x) + 12, (int) floor($head->y), (int) floor($head->z));
		$ev = new EntityDamageByBlockEvent($farBed, $dragon, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 20);
		$dragon->attack($ev);
		self::assertEqualsWithDelta(20 / 4 + 1, $ev->getFinalDamage(), 0.001, "away from the head it is a body hit");
	}

	public function testStrafeShootsAFireballThatLeavesBreath() : void{
		$dragon = $this->dragon(0.5, 25, 0.5);
		$cow = new Cow(new Location(20.5, 1, 0.5, $this->world, 0, 0));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$dragon->startStrafing($cow);
		$fireball = null;
		for($i = 0; $i < 200 && $fireball === null; ++$i){
			$this->runTicks(1);
			foreach($this->world->getEntities() as $e){
				if($e instanceof DragonFireball){
					$fireball = $e;
				}
			}
		}
		self::assertNotNull($fireball, "the dragon should shoot a fireball while strafing");
		self::assertSame(EnderDragon::PHASE_HOLDING, $dragon->getPhase());
		$cloud = null;
		for($i = 0; $i < 100 && $cloud === null; ++$i){
			$this->runTicks(1);
			foreach($this->world->getEntities() as $e){
				if($e instanceof DragonBreathCloud){
					$cloud = $e;
				}
			}
		}
		self::assertNotNull($cloud, "the fireball should leave a cloud of dragon's breath");
	}

	public function testDestroysBlocksButNotEndStone() : void{
		//a wall of stone with a layer of End stone, wide enough for wherever the dragon flies in 12 ticks
		for($x = -6; $x <= 6; ++$x){
			for($y = 18; $y <= 28; ++$y){
				$this->set($x, $y, 0, $y === 23 ? VanillaBlocks::END_STONE() : VanillaBlocks::STONE());
			}
		}
		$dragon = $this->dragon(0.5, 20.5, 0.5);
		$dragon->setPhase(EnderDragon::PHASE_HOLDING);
		$this->runTicks(12);
		$stone = 0;
		for($x = -6; $x <= 6; ++$x){
			self::assertSame(BlockTypeIds::END_STONE, $this->get($x, 23, 0)->getTypeId(), "End stone survives");
			for($y = 18; $y <= 28; ++$y){
				if($this->get($x, $y, 0)->getTypeId() === BlockTypeIds::STONE){
					++$stone;
				}
			}
		}
		self::assertLessThan(13 * 10, $stone, "stone in the dragon's body should be destroyed");
	}

	public function testDeathDropsExperienceOverTheAnimation() : void{
		$dragon = $this->dragon();
		$dragon->attack(new EntityDamageEvent($dragon, EntityDamageEvent::CAUSE_SUICIDE, 1000));
		self::assertFalse($dragon->isAlive());
		$this->runTicks(100);
		self::assertFalse(self::isGone($dragon), "the death animation lasts 10 seconds");
		$this->runTicks(EnderDragon::DEATH_TICKS - 100 + 2);
		self::assertTrue(self::isGone($dragon));
		self::assertSame([], $dragon->getDrops());
		self::assertFalse(VanillaItems::DRAGON_BREATH()->isNull());
	}
}
