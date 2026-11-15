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

namespace pocketmine\entity\monster;

use pocketmine\block\TurtleEgg;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\KineticWeaponGoal;
use pocketmine\entity\ai\goal\StompTurtleEggGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\Zombie;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function mt_srand;

/**
 * The zombie family's Bedrock behaviours: drowned conversion, turtle egg stomping, drowned targets and knockback, baby
 * zombie villagers, the Lava Chicken disc, the cure's Strength and spear charges.
 */
final class ZombiesTest extends MobTestCase{
	private const NIGHT = 18000;
	private const NOON = 6000;

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		$this->world->setTime(self::NOON);
		$this->world->stopTime();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	/**
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function runUntil(\Closure $condition, int $maxTicks) : int{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return $i;
			}
			$this->runTicks(1);
		}
		return $condition() ? $maxTicks : -1;
	}

	/**
	 * A 5x5 pool of water 4 deep (y 1-4) walled with glass, centred on x/z 0.5.
	 */
	private function pool() : void{
		for($x = -3; $x <= 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				for($y = 1; $y <= 5; ++$y){
					$edge = $x === -3 || $x === 3 || $z === -3 || $z === 3;
					$this->set($x, $y, $z, $edge ? VanillaBlocks::GLASS() : ($y <= 4 ? VanillaBlocks::WATER() : VanillaBlocks::AIR()));
				}
			}
		}
	}

	private function underwaterTicksOf(Zombie $zombie) : int{
		return $zombie->saveNBT()->getInt(UnderwaterConversionTimer::TAG_UNDERWATER_TICKS, -1);
	}

	public function testZombieUnderwaterShakesThenBecomesDrowned() : void{
		$this->pool();
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setNameTag("Bob");
		$zombie->setPersistent(true);
		$zombie->setMainHandItem(VanillaItems::IRON_SWORD());
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->spawnToAll();

		$this->runTicks(UnderwaterConversionTimer::UNDERWATER_TICKS - 5);
		self::assertGreaterThan(UnderwaterConversionTimer::UNDERWATER_TICKS - 20, $this->underwaterTicksOf($zombie), "the timer runs underwater");
		self::assertSame(-1, $zombie->saveNBT()->getInt(UnderwaterConversionTimer::TAG_CONVERSION_TICKS), "not shaking yet");
		$this->runTicks(10);
		self::assertGreaterThan(UnderwaterConversionTimer::CONVERSION_TICKS - 20, $zombie->saveNBT()->getInt(UnderwaterConversionTimer::TAG_CONVERSION_TICKS), "shaking");
		self::assertTrue($this->isShaking($zombie));
		self::assertCount(0, $this->entitiesOf(Drowned::class));

		$this->runTicks(UnderwaterConversionTimer::CONVERSION_TICKS + 5);
		self::assertTrue($zombie->isFlaggedForDespawn() || $zombie->isClosed());
		$drowned = $this->entitiesOf(Drowned::class);
		self::assertCount(1, $drowned);
		$d = $drowned[0];
		self::assertSame("Bob", $d->getNameTag());
		self::assertTrue($d->isPersistent());
		self::assertFalse($d->isBaby());
		self::assertSame(ItemTypeIds::IRON_SWORD, $d->getMainHandItem()->getTypeId(), "keeps its held item");
		self::assertSame(ItemTypeIds::IRON_HELMET, $d->getArmorInventory()->getHelmet()->getTypeId(), "keeps its armor");
		self::assertSame(0, $d->saveNBT()->getByte("CanBreakDoors"), "no door breaking roll");
	}

	private function isShaking(Mob $mob) : bool{
		$flags = $mob->getNetworkProperties()->getAll()[EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << EntityMetadataFlags::VIBRATING)) !== 0;
	}

	public function testBabyZombieStaysBabyAndLeavingWaterResets() : void{
		$this->pool();
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setBaby(true);
		$zombie->spawnToAll();
		$this->runTicks(200);
		self::assertGreaterThan(150, $this->underwaterTicksOf($zombie));
		$zombie->teleport(new Vector3(10.5, 1, 10.5));
		$this->runTicks(2);
		self::assertSame(0, $this->underwaterTicksOf($zombie), "leaving the water resets the timer");
		$zombie->teleport(new Vector3(0.5, 1, 0.5));
		$this->runTicks(UnderwaterConversionTimer::UNDERWATER_TICKS + UnderwaterConversionTimer::CONVERSION_TICKS + 10);
		$drowned = $this->entitiesOf(Drowned::class);
		self::assertCount(1, $drowned);
		self::assertTrue($drowned[0]->isBaby(), "a baby stays a baby");
	}

	public function testHuskStillConvertsAndZombieVillagerDoesNot() : void{
		$this->pool();
		$husk = new Husk($this->loc(0.5, 1, 0.5));
		$husk->spawnToAll();
		$villager = new ZombieVillager($this->loc(1.5, 1, 1.5));
		$villager->spawnToAll();
		self::assertSame(-1, $this->underwaterTicksOf($villager), "zombie villagers have no drowned timer");
		$this->runTicks(UnderwaterConversionTimer::UNDERWATER_TICKS + 5);
		self::assertTrue($husk->isConverting());
		$this->runTicks(UnderwaterConversionTimer::CONVERSION_TICKS + 5);
		$zombies = array_values(array_filter($this->entitiesOf(Zombie::class), fn(Zombie $z) => $z::class === Zombie::class));
		self::assertCount(1, $zombies, "the husk became a zombie");
		self::assertFalse($villager->isFlaggedForDespawn());
		self::assertCount(0, $this->entitiesOf(Drowned::class));
		self::assertGreaterThan(0, $this->underwaterTicksOf($zombies[0]), "the new zombie starts its own timer");
	}

	private function egg(int $x, int $z, int $count = 3) : void{
		$this->set($x, 1, $z, VanillaBlocks::TURTLE_EGG()->setCount($count));
	}

	public function testZombieStompsTurtleEgg() : void{
		$this->egg(6, 0);
		self::assertNotNull(StompTurtleEggGoal::findNearestEgg($this->world, 0, 1, 0, 10, 2));
		self::assertNull(StompTurtleEggGoal::findNearestEgg($this->world, 0, 1, 20, 10, 2), "out of range");
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET()); //no burning
		$zombie->spawnToAll();
		$ticks = $this->runUntil(fn() => !$this->get(6, 1, 0) instanceof TurtleEgg, 600);
		self::assertGreaterThan(StompTurtleEggGoal::STOMP_TICKS, $ticks, "stomps on it a while first");
	}

	public function testNoStompingWithoutMobGriefing() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$this->egg(4, 0);
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->spawnToAll();
		$this->runTicks(400);
		$egg = $this->get(4, 1, 0);
		self::assertInstanceOf(TurtleEgg::class, $egg);
		self::assertSame(3, $egg->getCount());
	}

	public function testDrownedStompsTurtleEggToo() : void{
		$this->egg(5, 0);
		$drowned = new Drowned($this->loc(0.5, 1, 0.5));
		$drowned->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$drowned->spawnToAll();
		self::assertGreaterThan(0, $this->runUntil(fn() => !$this->get(5, 1, 0) instanceof TurtleEgg, 600));
	}

	public function testDrownedTargetsVillagersOnlyInWaterOrAtNight() : void{
		$villager = new VillagerV2($this->loc(6.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$villager->setMovementSpeed(0); //both stay within the 20 block range
		$drowned = new Drowned($this->loc(0.5, 1, 0.5));
		$drowned->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$drowned->spawnToAll();
		$this->runTicks(100);
		self::assertNull($drowned->getAttackTarget(), "a villager on land by day is left alone");

		$drowned->teleport(new Vector3(0.5, 1, 0.5));
		$this->world->setTime(self::NIGHT);
		self::assertGreaterThanOrEqual(0, $this->runUntil(fn() => $drowned->getAttackTarget() === $villager, 100), "targeted at night");
	}

	public function testZombieTargetsBabyTurtleOnLand() : void{
		$turtle = new Turtle($this->loc(5.5, 1, 0.5));
		$turtle->setAge(Turtle::BABY_AGE);
		$turtle->spawnToAll();
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->spawnToAll();
		self::assertGreaterThanOrEqual(0, $this->runUntil(fn() => $zombie->getAttackTarget() === $turtle, 100), "zombies hunt baby turtles on land");
	}

	public function testDrownedHitPushesSwimmerDown() : void{
		$this->pool();
		$cow = new Cow($this->loc(1.5, 1, 0.5));
		$cow->spawnToAll();
		$drowned = new Drowned($this->loc(-0.5, 1, 0.5));
		$drowned->spawnToAll();
		$this->runTicks(1);
		$cow->setMotion(new Vector3(0, 0, 0));
		self::assertTrue($drowned->attackMelee($cow, 3.0));
		$motion = $cow->getMotion();
		self::assertEqualsWithDelta(-0.4, $motion->y, 0.0001, "pushed down");
		self::assertEqualsWithDelta(0.6, $motion->x, 0.05, "0.6 sideways");

		//on land the usual knockback
		$cow->teleport(new Vector3(10.5, 1, 10.5));
		$drowned->teleport(new Vector3(9.5, 1, 10.5));
		$cow->setMotion(new Vector3(0, 0, 0));
		$this->runTicks(10); //hurt cooldown
		$cow->setMotion(new Vector3(0, 0, 0));
		self::assertTrue($drowned->attackMelee($cow, 3.0));
		self::assertGreaterThan(0, $cow->getMotion()->y);
	}

	public function testZombieVillagersSpawnAsBabies() : void{
		$babies = 0;
		$total = 4000;
		for($i = 0; $i < $total; ++$i){
			$zv = new ZombieVillager($this->loc(0.5, 1, 0.5));
			Jockeys::onMobSpawned($zv);
			if($zv->isBaby()){
				++$babies;
			}
			$zv->close();
		}
		//4.25% + 0.75%
		self::assertGreaterThan($total * 0.03, $babies);
		self::assertLessThan($total * 0.07, $babies);
	}

	public function testBabyChickenJockeyDropsLavaChickenDisc() : void{
		$chicken = Jockeys::spawnChickenJockey($this->loc(0.5, 1, 0.5));
		$riders = $chicken->getPassengers();
		self::assertCount(1, $riders);
		$baby = $riders[0];
		self::assertInstanceOf(Zombie::class, $baby);
		$hasDisc = fn(Zombie $z) => in_array(ItemTypeIds::RECORD_LAVA_CHICKEN, array_map(fn($i) => $i->getTypeId(), $z->getDrops()), true);
		self::assertFalse($hasDisc($baby), "not killed by a player");
		(new \ReflectionProperty(Mob::class, "lastHurtByPlayerTick"))->setValue($baby, $this->world->getServer()->getTick());
		self::assertTrue($hasDisc($baby), "killed by a player");
		$baby->dismount(true);
		self::assertFalse($hasDisc($baby), "not riding a chicken");

		$adult = new Zombie($this->loc(3.5, 1, 0.5));
		$adult->spawnToAll();
		(new \ReflectionProperty(Mob::class, "lastHurtByPlayerTick"))->setValue($adult, $this->world->getServer()->getTick());
		$chicken2 = new Chicken($this->loc(3.5, 1, 0.5));
		$chicken2->spawnToAll();
		$adult->mount($chicken2);
		self::assertFalse($hasDisc($adult), "adults don't drop it");
	}

	public function testCureGivesStrengthOneForFiveMinutes() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$zv = new ZombieVillager($this->loc(0.5, 1, 0.5));
		$zv->spawnToAll();
		$zv->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 1800));
		$zv->startConversion(ZombieVillager::CURE_MAX_TICKS);
		$strength = $zv->getEffects()->get(VanillaEffects::STRENGTH());
		self::assertNotNull($strength);
		self::assertSame(0, $strength->getAmplifier());
		self::assertSame(6000, $strength->getDuration());
		self::assertFalse($zv->getEffects()->has(VanillaEffects::WEAKNESS()));
	}

	public function testZombieWithSpearCharges() : void{
		$villager = new VillagerV2($this->loc(8.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$villager->setMovementSpeed(0); //no running away
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->setMainHandItem(VanillaItems::IRON_SPEAR());
		$zombie->spawnToAll();
		$health = $villager->getHealth();
		$charged = false;
		$this->runUntil(function() use ($zombie, &$charged) : bool{
			foreach($zombie->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof KineticWeaponGoal && $goal->isCharging()){
					$charged = true;
				}
			}
			return $charged;
		}, 200);
		self::assertTrue($charged, "lowers the spear");
		$this->runUntil(fn() => $villager->getHealth() < $health || !$villager->isAlive(), 300);
		self::assertLessThan($health, $villager->isAlive() ? $villager->getHealth() : 0.0, "the charge hits");
		$cause = $villager->getLastDamageCause();
		self::assertNotNull($cause);
		//the zombie's attack damage (3) plus the speed damage: more than a melee hit
		self::assertGreaterThan(Zombie::ATTACK_DAMAGE, $cause->getBaseDamage(), "a spear hit, not a melee hit");
	}

	public function testZombieVillagerWithSpearCharges() : void{
		$villager = new VillagerV2($this->loc(8.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$villager->setMovementSpeed(0);
		$zv = new ZombieVillager($this->loc(0.5, 1, 0.5));
		$zv->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zv->setMainHandItem(VanillaItems::IRON_SPEAR());
		$zv->spawnToAll();
		//use_kinetic_weapon (6) comes before melee_box_attack (7) for zombie villagers
		$charged = $this->runUntil(function() use ($zv) : bool{
			foreach($zv->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof KineticWeaponGoal && $goal->isCharging()){
					return true;
				}
			}
			return false;
		}, 200);
		self::assertNotSame(-1, $charged, "lowers the spear");
	}

	public function testClosingTheMountDuringASpearChargeIsSafe() : void{
		$villager = new VillagerV2($this->loc(12.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$villager->setMovementSpeed(0);
		$horse = new ZombieHorse($this->loc(0.5, 1, 0.5));
		$horse->spawnToAll();
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->setMainHandItem(VanillaItems::IRON_SPEAR());
		$zombie->spawnToAll();
		self::assertTrue($zombie->mount($horse));
		$running = $this->runUntil(function() use ($zombie) : bool{
			foreach($zombie->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof KineticWeaponGoal){
					return true;
				}
			}
			return false;
		}, 200);
		self::assertNotSame(-1, $running, "the mounted spear goal runs");
		$horse->close();
		$this->runTicks(5);
		$zombie->close();
		self::assertTrue($zombie->isClosed());
	}

	public function testZombieWithoutSpearDoesNotUseKineticGoal() : void{
		$villager = new VillagerV2($this->loc(6.5, 1, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$zombie->setMainHandItem(VanillaItems::IRON_SWORD());
		$zombie->spawnToAll();
		for($i = 0; $i < 100; ++$i){
			$this->runTicks(1);
			foreach($zombie->getGoalSelector()->getRunningGoals() as $goal){
				self::assertNotInstanceOf(KineticWeaponGoal::class, $goal);
			}
		}
	}
}
