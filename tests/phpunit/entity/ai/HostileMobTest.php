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

namespace pocketmine\entity\ai;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\CaveSpider;
use pocketmine\entity\monster\ElderGuardian;
use pocketmine\entity\monster\Enderman;
use pocketmine\entity\monster\Ghast;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\HostileMobs;
use pocketmine\entity\monster\MagmaCube;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\projectile\LargeFireball;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use function array_filter;
use function array_values;
use function count;
use function max;
use function mt_srand;

final class HostileMobTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261013);
		//damage events look their damager up through the world manager
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
	}

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && !$entity->isClosed() && !$entity->isFlaggedForDespawn() && $entity->isAlive()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * Keeps the mob's goals from moving it around (test setups).
	 */
	private function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	public function testEveryMobSpawnsFromItsEggAndSurvivesTicking() : void{
		$registry = EntitySpawnRegistry::getInstance();
		$mobs = [];
		$i = 0;
		foreach(Utils::stringifyKeys(HostileMobs::getMobs()) as $identifier => [$class, , ]){
			self::assertTrue($registry->isRegistered($identifier), "$identifier should have a spawn egg factory");
			$entity = $registry->create($identifier, $this->loc(-20 + ($i % 8) * 5 + 0.5, 1, -20 + (int) ($i / 8) * 5 + 0.5));
			self::assertInstanceOf($class, $entity);
			self::assertSame($identifier, $entity::getNetworkTypeId());
			self::assertTrue(EntityFactory::getInstance()->isRegistered($class), "$class should have a save ID");
			$mobs[] = $entity;
			++$i;
		}
		$this->runTicks(100);
		foreach($mobs as $mob){
			if($mob->isClosed()){
				continue; //e.g. a silverfish hiding in the stone floor
			}
			//saving and loading keeps the type
			$copy = EntityFactory::getInstance()->createFromData($this->world, $mob->saveNBT());
			self::assertInstanceOf($mob::class, $copy);
			$copy->flagForDespawn();
		}
		self::assertGreaterThan(0, count($mobs));
	}

	public function testGenericEggsSpawnTheNewMobs() : void{
		foreach([
			[VanillaItems::HUSK_SPAWN_EGG(), "minecraft:husk"],
			[VanillaItems::ZOMBIE_VILLAGER_SPAWN_EGG(), "minecraft:zombie_villager_v2"],
			[VanillaItems::EVOKER_SPAWN_EGG(), "minecraft:evocation_illager"],
			[VanillaItems::ZOMBIE_PIGMAN_SPAWN_EGG(), "minecraft:zombie_pigman"],
			[VanillaItems::SULFUR_CUBE_SPAWN_EGG(), "minecraft:sulfur_cube"],
		] as [$egg, $identifier]){
			$entity = $egg->spawnEntity($this->world, new Vector3(0.5, 1, 0.5), 0, 0);
			self::assertNotNull($entity);
			self::assertSame($identifier, $entity::getNetworkTypeId());
		}
	}

	public function testSlimeSplitsOnDeath() : void{
		mt_srand(42);
		foreach([[Slime::class, 4, 2], [Slime::class, 2, 1], [MagmaCube::class, 4, 2]] as [$class, $size, $childSize]){
			$slime = $class === MagmaCube::class ? new MagmaCube($this->loc(0.5, 1, 0.5), null, $size) : new Slime($this->loc(0.5, 1, 0.5), null, $size);
			$slime->setNameTag("Bob");
			$this->runTicks(1); //entities that haven't ticked yet can't die
			$before = count($this->entitiesOf(Slime::class));
			$slime->attack(new EntityDamageEvent($slime, EntityDamageEvent::CAUSE_MAGIC, 1000));
			self::assertFalse($slime->isAlive());
			$this->runTicks(25); //it splits when the body is removed, after the death animation
			$children = array_values(array_filter($this->entitiesOf(Slime::class), fn(Slime $s) => $s !== $slime));
			$new = count($children) - ($before - 1);
			self::assertGreaterThanOrEqual(2, $new, "a size $size slime splits into 2-4");
			self::assertLessThanOrEqual(4, $new);
			foreach($children as $child){
				self::assertSame($childSize, $child->getSlimeSize());
				self::assertInstanceOf($class, $child);
				self::assertSame("Bob", $child->getNameTag());
				self::assertSame((float) $child->getMaxHealth(), $child->getHealth());
				$child->flagForDespawn();
			}
			$this->runTicks(1);
		}

		//the smallest slimes don't split
		$small = new Slime($this->loc(0.5, 1, 0.5), null, 1);
		self::assertSame([], $small->split());
	}

	public function testSlimeSizesFollowBedrock() : void{
		$large = new Slime($this->loc(0.5, 1, 0.5), null, 4);
		self::assertSame(16, $large->getMaxHealth());
		self::assertEqualsWithDelta(2.08, $large->getSize()->getWidth(), 0.001);
		self::assertSame(4.0, $large->getContactDamage());
		$small = new Slime($this->loc(3.5, 1, 0.5), null, 1);
		self::assertSame(1, $small->getMaxHealth());
		self::assertSame(0.0, $small->getContactDamage());
		$magma = new MagmaCube($this->loc(6.5, 1, 0.5), null, 1);
		self::assertSame(3.0, $magma->getContactDamage(), "even small magma cubes hurt");

		//sizes are saved
		$loaded = new Slime($this->loc(9.5, 1, 0.5), $large->saveNBT());
		self::assertSame(4, $loaded->getSlimeSize());
	}

	public function testSlimeHops() : void{
		$slime = new Slime($this->loc(0.5, 1, 0.5), null, 2);
		$start = $slime->getPosition();
		$maxY = 1.0;
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			$maxY = max($maxY, $slime->getPosition()->y);
		}
		self::assertGreaterThan(1.5, $maxY, "slimes move by jumping");
		self::assertGreaterThan(0.5, $slime->getPosition()->distance($start), "slimes hop around");
	}

	public function testEndermanTeleportDestinations() : void{
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		$this->freeze($enderman);

		//falls straight down to the floor
		$dest = $enderman->findTeleportDestination(5.5, 12.3, 5.5);
		self::assertNotNull($dest);
		self::assertEqualsWithDelta(1.0, $dest->y, 0.0001);
		self::assertEqualsWithDelta(5.5, $dest->x, 0.0001);

		//not into water
		$this->set(8, 1, 8, VanillaBlocks::WATER());
		self::assertNull($enderman->findTeleportDestination(8.5, 6, 8.5), "endermen don't teleport onto water");

		//not where it doesn't fit (2.9 blocks tall)
		$this->set(10, 3, 10, VanillaBlocks::STONE());
		self::assertNull($enderman->findTeleportDestination(10.5, 2, 10.5), "endermen need 3 blocks of room");

		//not next to water either (no liquid inside its body)
		$this->set(12, 2, 12, VanillaBlocks::WATER());
		self::assertNull($enderman->findTeleportDestination(12.3, 1, 12.5));

		//not over the void
		self::assertNull($enderman->findTeleportDestination(0.5, 5, 200.5), "unloaded or bottomless spots are refused");

		//random teleports always end up somewhere valid
		mt_srand(7);
		$moved = 0;
		for($i = 0; $i < 20; ++$i){
			$before = $enderman->getPosition();
			if($enderman->teleportRandomly()){
				++$moved;
				$pos = $enderman->getPosition();
				self::assertFalse($pos->equals($before));
				self::assertNotNull($enderman->findTeleportDestination($pos->x, $pos->y, $pos->z), "landed on a valid spot: $pos");
				self::assertEqualsWithDelta(1.0, $pos->y, 0.0001, "the only floor is at y=0");
			}
		}
		self::assertGreaterThan(0, $moved);
	}

	public function testEndermanDodgesProjectiles() : void{
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		$health = $enderman->getHealth();
		$before = $enderman->getPosition();
		$ev = new EntityDamageEvent($enderman, EntityDamageEvent::CAUSE_PROJECTILE, 5);
		$enderman->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertSame($health, $enderman->getHealth());
		self::assertFalse($enderman->getPosition()->equals($before), "it teleports away");
	}

	/**
	 * Fills a pool of water for guardians, and returns a frozen cow standing next to it as their target.
	 */
	private function poolWithTarget() : Cow{
		for($x = -6; $x <= 0; ++$x){
			for($z = -3; $z <= 3; ++$z){
				for($y = 1; $y <= 4; ++$y){
					$this->set($x, $y, $z, VanillaBlocks::WATER());
				}
			}
		}
		$cow = new Cow($this->loc(5.5, 1, 0.5));
		$this->freeze($cow);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		return $cow;
	}

	/**
	 * Returns the number of ticks between the guardian locking its beam on the target and the target being hurt.
	 */
	private function measureBeam(Guardian $guardian, Cow $cow) : int{
		$guardian->setAttackTarget($cow);
		$start = null;
		$health = $cow->getHealth();
		for($i = 1; $i <= 300; ++$i){
			$this->runTicks(1);
			if($start === null && $guardian->getBeamTarget() === $cow){
				$start = $i;
				self::assertSame($cow->getId(), $guardian->getTargetEntityId(), "the client draws the beam to TARGET_EID");
			}
			if($cow->getHealth() < $health){
				self::assertNotNull($start, "the beam locks on before hurting");
				//the beam only shows after the 10 tick warm-up (Java GuardianAttackGoal.tick, attackTime 0)
				self::assertGreaterThanOrEqual(10, $start, "no beam during the warm-up");
				return $i - $start;
			}
			if($start === null){
				self::assertNull($guardian->getTargetEntityId(), "no beam before the attack starts");
			}
		}
		self::fail("the guardian never fired");
	}

	public function testGuardianBeamTiming() : void{
		$cow = $this->poolWithTarget();
		$guardian = new Guardian($this->loc(-2.5, 2, 0.5));
		//damage on the 90th tick of the attack: 10 ticks of warm-up without beam + 80 of charge with it (Java
		//GuardianAttackGoal)
		self::assertSame(80, $this->measureBeam($guardian, $cow));
		self::assertNull($guardian->getTargetEntityId(), "the beam goes away after the hit");
		$guardian->flagForDespawn();
		$this->runTicks(1);

		$elder = new ElderGuardian($this->loc(-3.5, 1.5, 0.5));
		self::assertSame(60, $this->measureBeam($elder, $cow), "elder guardians charge in 60 ticks");
	}

	public function testGuardianBeamDamage() : void{
		$guardian = new Guardian($this->loc(0.5, 1, 0.5));
		$this->difficulty = World::DIFFICULTY_NORMAL;
		self::assertSame(1.0, $guardian->getBeamMagicDamage());
		$this->difficulty = World::DIFFICULTY_HARD;
		self::assertSame(3.0, $guardian->getBeamMagicDamage());
		$elder = new ElderGuardian($this->loc(3.5, 1, 0.5));
		self::assertSame(5.0, $elder->getBeamMagicDamage());
	}

	public function testGhastFireballDeflection() : void{
		$ghast = new Ghast($this->loc(-10.5, 6, 0.5));
		$this->freeze($ghast);
		//a mob standing east of the ghast, looking back west at it
		$batter = new Zombie($this->loc(6.5, 1, 0.5, 90.0));
		$this->freeze($batter);

		$fireball = new LargeFireball($this->loc(2.5, 7, 0.5), $ghast, new Vector3(1, 0, 0));
		$fireball->setMotion(new Vector3(0.5, 0, 0));
		self::assertSame($ghast->getId(), $fireball->getOwningEntityId());
		self::assertFalse($fireball->isDeflected());

		$ev = new EntityDamageByEntityEvent($batter, $fireball, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3);
		$fireball->attack($ev);
		self::assertTrue($ev->isCancelled(), "hitting a fireball doesn't hurt it");
		self::assertTrue($fireball->isDeflected());
		self::assertSame($batter->getId(), $fireball->getOwningEntityId(), "the attacker owns the fireball");
		self::assertLessThan(0, $fireball->getAcceleration()->x, "it now flies where the attacker looks");
		self::assertLessThan(0, $fireball->getMotion()->x);

		$health = $ghast->getHealth();
		for($i = 0; $i < 60 && !$fireball->isClosed() && !$fireball->isFlaggedForDespawn(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($fireball->isClosed() || $fireball->isFlaggedForDespawn(), "the fireball reached the ghast");
		self::assertLessThan($health, $ghast->getHealth(), "the deflected fireball hurts the ghast");
	}

	public function testGhastShootsFireballs() : void{
		$ghast = new Ghast($this->loc(0.5, 8, 0.5));
		$cow = new Cow($this->loc(12.5, 1, 0.5));
		$this->freeze($cow);
		$ghast->setAttackTarget($cow);
		$fireballs = 0;
		for($i = 0; $i < 80 && $fireballs === 0; ++$i){
			$this->runTicks(1);
			$fireballs = count($this->entitiesOf(LargeFireball::class));
		}
		self::assertGreaterThan(0, $fireballs, "the ghast shot a fireball");
	}

	public function testCaveSpiderPoison() : void{
		self::assertSame(0, CaveSpider::getPoisonTicks(World::DIFFICULTY_EASY));
		self::assertSame(140, CaveSpider::getPoisonTicks(World::DIFFICULTY_NORMAL));
		self::assertSame(300, CaveSpider::getPoisonTicks(World::DIFFICULTY_HARD));
		$spider = new CaveSpider($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(1.5, 1, 0.5));
		self::assertTrue($spider->attackMelee($cow, 2));
		self::assertTrue($cow->getEffects()->has(\pocketmine\entity\effect\VanillaEffects::POISON()));
	}

	public function testZombieVillagerCure() : void{
		$zombie = new ZombieVillager($this->loc(0.5, 1, 0.5));
		$this->freeze($zombie);
		$zombie->setProfession(7);
		$zombie->startConversion(40);
		self::assertTrue($zombie->isConverting());
		$this->runTicks(45);
		self::assertTrue($zombie->isFlaggedForDespawn() || $zombie->isClosed(), "the zombie villager turned into a villager");
		$villagers = $this->entitiesOf(\pocketmine\entity\npc\VillagerV2::class);
		self::assertCount(1, $villagers);
		self::assertSame(\pocketmine\entity\npc\VillagerV2::PROFESSION_CLERIC, $villagers[0]->getProfession());
	}

	public function testHoglinZombifiesOutsideTheNether() : void{
		$hoglin = new Hoglin($this->loc(0.5, 1, 0.5));
		$this->freeze($hoglin);
		$this->runTicks(Hoglin::getZombificationTicks() + 5);
		self::assertTrue($hoglin->isFlaggedForDespawn() || $hoglin->isClosed());
		self::assertCount(1, $this->entitiesOf(Zoglin::class));
	}

	public function testPiglinBarters() : void{
		mt_srand(3);
		for($i = 0; $i < 50; ++$i){
			self::assertFalse(Piglin::getBarterLoot()->isNull());
		}
		$piglin = new Piglin($this->loc(0.5, 1, 0.5));
		$this->freeze($piglin);
		$piglin->startAdmiring();
		self::assertTrue($piglin->isAdmiring());
		self::assertSame(VanillaItems::GOLD_INGOT()->getTypeId(), $piglin->getOffHandItem()->getTypeId());
		$this->runTicks(Piglin::ADMIRE_TICKS + 2);
		self::assertFalse($piglin->isAdmiring());
		self::assertTrue($piglin->getOffHandItem()->isNull());
		self::assertGreaterThan(0, count($this->entitiesOf(\pocketmine\entity\object\ItemEntity::class)), "it threw the bartered item");
	}
}
