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

namespace pocketmine\entity\aquatic;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FindTreasureGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\RandomBreachGoal;
use pocketmine\entity\ai\goal\SwimWithEntityGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\generator\structure\StructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function max;
use function mt_srand;

/**
 * Dolphins (dolphin.json): feeding and leading to treasure, babies, guardian avoidance, breaching, anger and its
 * broadcast, swimming with players and drying out.
 */
final class DolphinTest extends MobTestCase{
	/** Calls of the test structure locator. */
	public static int $locatorCalls = 0;
	public static ?Vector3 $shipwreck = null;

	protected function setUp() : void{
		parent::setUp();
		self::$locatorCalls = 0;
		self::$shipwreck = null;
		FindTreasureGoal::clearCache();
		for($x = -30; $x <= 45; ++$x){
			for($z = -10; $z <= 10; ++$z){
				for($y = 1; $y <= 6; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
		StructureLocators::registerForWorld($this->world, new class implements StructureLocator{
			public function getStructureIds() : array{
				return ["shipwreck", "buried_treasure", "village"];
			}

			public function findNearest(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly) : ?Vector3{
				++DolphinTest::$locatorCalls;
				return match($structureId){
					"shipwreck" => DolphinTest::$shipwreck,
					"buried_treasure" => DolphinTest::$shipwreck?->add(20, 0, 5), //farther away
					default => null
				};
			}
		});
	}

	protected function tearDown() : void{
		StructureLocators::unregisterForWorld($this->world);
		FindTreasureGoal::clearCache();
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	private function player(Item $held) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * A cow that doesn't move by itself and hardly dies.
	 */
	private function dummy(float $x, float $y, float $z) : Cow{
		$cow = new class($this->loc($x, $y, $z)) extends Cow implements NeverSavedWithChunkEntity{
			protected function tryChangeMovement() : void{
				$this->motion = Vector3::zero();
			}

			public function canBreathe() : bool{
				return true; //no drowning in the pool
			}
		};
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setHasGravity(false);
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		return $cow;
	}

	private function hurtBy(Entity $victim, Entity $attacker) : void{
		$victim->attack(new EntityDamageByEntityEvent($attacker, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
	}

	public function testFedDolphinLeadsToTreasure() : void{
		self::$shipwreck = new Vector3(40, 2, 0);
		$dolphin = new Dolphin($this->loc(-20.5, 3, 0.5));
		$dolphin->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$player = $this->player(VanillaItems::RAW_FISH()->setCount(3));
		self::assertTrue($dolphin->onInteract($player, Vector3::zero()), "bribeable: raw cod");
		self::assertTrue($dolphin->hasGotFish());
		self::assertSame(2, $player->getInventory()->getItemInHand()->getCount());
		self::assertFalse($dolphin->onInteract($player, Vector3::zero()), "bribe cooldown 2 s");

		for($i = 0; $i < 1200 && $dolphin->hasGotFish(); ++$i){
			$this->runTicks(1);
		}
		self::assertFalse($dolphin->hasGotFish(), "the fish is used up when it arrives");
		$loc = $dolphin->getLocation();
		self::assertLessThan(FindTreasureGoal::ARRIVE_DISTANCE + 1.5, (new Vector3($loc->x, 0, $loc->z))->distance(new Vector3(40, 0, 0)), "led the way to the shipwreck");
		self::assertSame(2, self::$locatorCalls, "one search per structure kind the locator knows (no ocean ruins), the nearest is used");

		//a second feeding in the same area uses the cached result
		$this->runTicks(Dolphin::BRIBE_COOLDOWN_TICKS);
		$dolphin->teleport($this->loc(-20.5, 3, 0.5));
		self::assertTrue($dolphin->onInteract($player, Vector3::zero()));
		$this->runTicks(10);
		self::assertSame(2, self::$locatorCalls);
		$dolphin->flagForDespawn();
	}

	public function testNoTreasureUsesUpTheFish() : void{
		$dolphin = new Dolphin($this->loc(0.5, 3, 0.5));
		$player = $this->player(VanillaItems::RAW_SALMON());
		self::assertTrue($dolphin->onInteract($player, Vector3::zero()), "bribeable: salmon");
		$this->runTicks(10);
		self::assertFalse($dolphin->hasGotFish(), "no treasure around: stuck");
		self::assertFalse($dolphin->onInteract($this->player(VanillaItems::BREAD()), Vector3::zero()));
		$dolphin->flagForDespawn();
	}

	public function testBabies() : void{
		$dolphin = new Dolphin($this->loc(0.5, 3, 0.5));
		self::assertSame(10, $dolphin->getSpawnBabyChance());
		self::assertFalse($dolphin->isBaby());
		self::assertTrue($dolphin->makeSpawnBaby());
		self::assertTrue($dolphin->isBaby());
		self::assertSame(Dolphin::BABY_SCALE, $dolphin->getScale());
		self::assertSame(-Dolphin::BABY_GROW_TICKS, $dolphin->getAge());
		self::assertSame([], $dolphin->getDrops(), "dolphin_baby has no loot");

		$player = $this->player(VanillaItems::RAW_FISH()->setCount(2));
		self::assertTrue($dolphin->onInteract($player, Vector3::zero()), "feed_items fish");
		self::assertSame(-Dolphin::BABY_GROW_TICKS + 2400, $dolphin->getAge(), "10% of the growing time");
		self::assertFalse($dolphin->hasGotFish(), "babies aren't bribeable");

		//saved and loaded
		$copy = new Dolphin($this->loc(2.5, 3, 0.5), $dolphin->saveNBT());
		self::assertTrue($copy->isBaby());
		self::assertSame($dolphin->getAge(), $copy->getAge());
		$copy->flagForDespawn();

		$dolphin->setAge(-5);
		$this->runTicks(10);
		self::assertFalse($dolphin->isBaby(), "grew up");
		self::assertSame(1.0, $dolphin->getScale());
		$dolphin->flagForDespawn();

		mt_srand(4321);
		$babies = 0;
		for($i = 0; $i < 1000; ++$i){
			$d = new Dolphin($this->loc(0.5, 3, 0.5));
			if(BabySpawning::rollSpawnBaby($d)){
				++$babies;
			}
			$d->flagForDespawn();
			$d->close();
		}
		mt_srand();
		self::assertGreaterThan(60, $babies);
		self::assertLessThan(140, $babies, "entity_spawned: 10% babies");
	}

	public function testBabyDoesNotAttackButAdultsJoinIn() : void{
		$baby = new Dolphin($this->loc(0.5, 3, 0.5));
		$baby->makeSpawnBaby();
		$adult = new Dolphin($this->loc(10.5, 3, 0.5));
		$far = new Dolphin($this->loc(30.5, 3, 0.5));
		$cow = $this->dummy(3.5, 3, 0.5);
		$this->hurtBy($baby, $cow);
		$this->runTicks(2);
		self::assertSame($cow, $baby->getAttackTarget());
		self::assertSame($cow, $adult->getAttackTarget(), "broadcast_anger within 16 blocks");
		self::assertNull($far->getAttackTarget(), "not beyond 16 blocks");
		for($i = 0; $i < 300 && $cow->getHealth() === 100000.0; ++$i){
			$this->runTicks(1);
		}
		self::assertLessThan(100000.0, $cow->getHealth(), "the adult attacks");
		$adult->flagForDespawn();
		$this->runTicks(200);
		$health = $cow->getHealth();
		$this->runTicks(200);
		self::assertSame($health, $cow->getHealth(), "the baby has no attack");
		$baby->flagForDespawn();
		$far->flagForDespawn();
	}

	public function testAngerLasts25Seconds() : void{
		$dolphin = new Dolphin($this->loc(0.5, 3, 0.5));
		$cow = $this->dummy(20.5, 3, 0.5);
		$this->hurtBy($dolphin, $cow);
		$this->runTicks(2);
		self::assertSame($cow, $dolphin->getAttackTarget());
		$this->runTicks(Dolphin::ANGER_TICKS - 20);
		self::assertSame($cow, $dolphin->getAttackTarget());
		$this->runTicks(30);
		self::assertNull($dolphin->getAttackTarget(), "minecraft:angry duration 25");
		$dolphin->flagForDespawn();
	}

	public function testAvoidsGuardians() : void{
		//the flee picks are random: a fixed seed keeps the test independent of the tests that ran before it
		mt_srand(20261024);
		$dolphin = new Dolphin($this->loc(0.5, 3, 0.5));
		$guardian = new Guardian($this->loc(4.5, 3, 0.5));
		$guardian->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$guardian->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$guardian->setHasGravity(false);
		$maxDistance = 0.0;
		//the flee position is random (it can end up short of 8 blocks, then a later search picks another one): give it
		//enough ticks for a few searches so the test does not depend on the first pick
		for($i = 0; $i < 600 && $maxDistance <= Dolphin::GUARDIAN_AVOID_DISTANCE; ++$i){
			$this->runTicks(1);
			$maxDistance = max($maxDistance, $dolphin->getPosition()->distance($guardian->getPosition()));
		}
		self::assertGreaterThan(Dolphin::GUARDIAN_AVOID_DISTANCE, $maxDistance, "swam away from the guardian");
		$guardian->flagForDespawn();
		$dolphin->flagForDespawn();
	}

	public function testBreach() : void{
		//just below the surface (water up to y=6), facing +z (yaw 0) with open water ahead
		$dolphin = new Dolphin($this->loc(0.5, 6.1, -8.5));
		$dolphin->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$goal = new RandomBreachGoal($dolphin, 1, 6, 40, 0.6);
		$this->runTicks(1);
		self::assertTrue($goal->canStart());
		$motionY = $dolphin->getMotion()->y;
		$goal->start();
		self::assertEqualsWithDelta($motionY + 0.6, $dolphin->getMotion()->y, 1e-9, "jump.static 0.6");
		$maxY = 0.0;
		for($i = 0; $i < 80 && $goal->canContinue(); ++$i){
			$this->runTicks(1);
			$goal->tick();
			$maxY = max($maxY, $dolphin->getPosition()->y);
		}
		self::assertGreaterThan(7.0, $maxY, "out of the water");
		self::assertTrue($dolphin->isInWater(), "and back in");
		self::assertGreaterThan(-8.5, $dolphin->getPosition()->z, "forwards");
		$goal->stop();
		$dolphin->teleport($this->loc(0.5, 6.1, -8.5));
		$this->runTicks(1);
		self::assertFalse($goal->canStart(), "cooldown 2 s");
		$this->runTicks(40);
		self::assertTrue($goal->canStart());

		$dolphin->teleport($this->loc(0.5, 3.1, -8.5));
		$this->runTicks(1);
		self::assertFalse($goal->canBreachHere(), "too deep");
		$dolphin->flagForDespawn();
	}

	public function testSwimWithEntityParameters() : void{
		$goal = new SwimWithEntityGoal(new Dolphin($this->loc(0.5, 3, 0.5)));
		$values = [];
		foreach(["speedMultiplier", "searchRange", "successRate", "chanceToStop", "stateCheckInterval", "catchUpThreshold", "catchUpMultiplier", "matchDirectionThreshold", "stopDistance"] as $name){
			$values[$name] = (new \ReflectionProperty(SwimWithEntityGoal::class, $name))->getValue($goal);
		}
		self::assertSame([
			"speedMultiplier" => 1.5,
			"searchRange" => 20.0,
			"successRate" => 0.1,
			"chanceToStop" => 0.0333,
			"stateCheckInterval" => 10,
			"catchUpThreshold" => 12.0,
			"catchUpMultiplier" => 2.5,
			"matchDirectionThreshold" => 2.0,
			"stopDistance" => 5.0
		], $values);
	}

	public function testDriedOutDamage() : void{
		$dolphin = new Dolphin($this->loc(0.5, 1, 20.5));
		$dolphin->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		(new \ReflectionProperty(Dolphin::class, "moistness"))->setValue($dolphin, 0);
		$this->runTicks(40);
		self::assertSame(10.0 - 4.0, $dolphin->getHealth(), "1 damage per hurt cooldown (10 ticks)");
		$dolphin->flagForDespawn();
	}
}
