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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RangedAttackGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use Ramsey\Uuid\Uuid;
use function mt_srand;

/**
 * Skeleton family and spiders (Bedrock rules): target lists, wolf and armadillo avoidance, stray conversion in powder
 * snow, melee mode and bogged shearing.
 */
final class SkeletonsTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Stops the mob from moving, looking around and picking targets.
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private static function freeze(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		return $mob;
	}

	private function dummy(float $x, float $z) : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		self::freeze($cow);
		$cow->setMaxHealth(10000);
		$cow->setHealth(10000);
		return $cow;
	}

	/**
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function tickUntil(\Closure $condition, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return true;
			}
			$this->runTicks(1);
		}
		return $condition();
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * Returns whether the mob runs a goal of the class.
	 *
	 * @phpstan-param class-string<Goal> $class
	 */
	private static function runs(Mob $mob, string $class) : bool{
		foreach($mob->getGoalSelector()->getRunningGoals() as $goal){
			if($goal instanceof $class){
				return true;
			}
		}
		return false;
	}

	public function testSpiderTargetsGolems() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$golem = self::freeze(new IronGolem($this->loc(4.5, 1, 0.5)));
		self::assertTrue($this->tickUntil(fn() => $spider->getAttackTarget() === $golem, 600), "spiders target iron golems in the dark");
		$spider->flagForDespawn();
		$golem->flagForDespawn();

		$spider = new Spider($this->loc(0.5, 1, 10.5));
		$snowGolem = self::freeze(new SnowGolem($this->loc(4.5, 1, 10.5)));
		self::assertTrue($this->tickUntil(fn() => $spider->getAttackTarget() === $snowGolem, 600), "and snow golems");
	}

	public function testSilverfishTargetsGolems() : void{
		$silverfish = new Silverfish($this->loc(0.5, 1, 0.5));
		$golem = self::freeze(new IronGolem($this->loc(3.5, 1, 0.5)));
		self::assertTrue($this->tickUntil(fn() => $silverfish->getAttackTarget() === $golem, 1200));
	}

	public function testSkeletonTargetsBabyTurtlesOnLandOnly() : void{
		$skeleton = self::freezeMoves(new Skeleton($this->loc(0.5, 1, 0.5)));
		$turtle = new Turtle($this->loc(5.5, 1, 0.5));
		$turtle->makeSpawnBaby();
		self::freeze($turtle);
		self::assertTrue($this->tickUntil(fn() => $skeleton->getAttackTarget() === $turtle, 100), "baby turtles on land are targeted");
		$skeleton->flagForDespawn();
		$turtle->flagForDespawn();

		for($x = 4; $x <= 6; ++$x){
			for($z = 9; $z <= 11; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::WATER());
			}
		}
		$skeleton = self::freezeMoves(new Skeleton($this->loc(0.5, 1, 10.5)));
		$turtle = new Turtle($this->loc(5.5, 1, 10.5));
		$turtle->makeSpawnBaby();
		self::freeze($turtle);
		$this->runTicks(100);
		self::assertNotSame($turtle, $skeleton->getAttackTarget(), "not in water");
	}

	public function testSkeletonTargetsIronGolems() : void{
		$skeleton = self::freezeMoves(new Bogged($this->loc(0.5, 1, 0.5)));
		$golem = self::freeze(new IronGolem($this->loc(6.5, 1, 0.5)));
		self::assertTrue($this->tickUntil(fn() => $skeleton->getAttackTarget() === $golem, 100));
	}

	public function testWitherSkeletonTargetsPiglinBrutesExceptOnPeaceful() : void{
		$wither = self::freezeMoves(new WitherSkeleton($this->loc(0.5, 1, 0.5)));
		$brute = self::freeze(new PiglinBrute($this->loc(5.5, 1, 0.5)));
		self::assertTrue($this->tickUntil(fn() => $wither->getAttackTarget() === $brute, 100), "piglin brutes are targeted on Normal");
		self::assertTrue(MonsterTargets::isWitherSkeletonTarget($brute));
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		self::assertFalse(MonsterTargets::isWitherSkeletonTarget($brute), "not on Peaceful");
		self::assertTrue(MonsterTargets::isWitherSkeletonTarget(self::freeze(new IronGolem($this->loc(-5.5, 1, 0.5)))), "iron golems on any difficulty");
	}

	/**
	 * A mocked survival player standing at the location, made the only player of the world (what the player target
	 * goals look at; it isn't in the world's entity lists).
	 */
	private function player(Location $location) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturnCallback(fn() => $location->add(0, 1.62, 0));
		$player->method("getBoundingBox")->willReturnCallback(fn() => new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		$player->method("getId")->willReturn(7100000);
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, [$player]);
		$this->player = $player;
		return $player;
	}

	private ?Player $player = null;

	protected function tearDown() : void{
		if($this->player !== null){
			//keep the mock from running Player::onDispose() when it is destroyed
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($this->player, true);
			$this->player = null;
		}
		parent::tearDown();
	}

	/**
	 * Bedrock picks the nearest of all the entity types: an iron golem closer than the player is always the target, not
	 * only when the search for it happens to come first.
	 */
	public function testSkeletonPicksGolemCloserThanPlayer() : void{
		$this->player($this->loc(0.5, 1, 9.5));
		$golem = self::freeze(new IronGolem($this->loc(4.5, 1, 0.5)));
		for($i = 0; $i < 12; ++$i){
			$skeleton = self::freezeMoves(new Skeleton($this->loc(0.5, 1, 0.5)));
			self::assertTrue($this->tickUntil(fn() => $skeleton->getAttackTarget() !== null, 100));
			self::assertSame($golem, $skeleton->getAttackTarget(), "trial $i: the golem is closer than the player");
			$skeleton->flagForDespawn();
			$this->runTicks(1);
		}
	}

	/**
	 * Iron golems out of range don't change the player search.
	 */
	public function testFarGolemLeavesPlayerSearch() : void{
		$player = $this->player($this->loc(0.5, 1, 6.5));
		self::freeze(new IronGolem($this->loc(30.5, 1, 0.5)));
		$skeleton = self::freezeMoves(new Skeleton($this->loc(0.5, 1, 0.5)));
		self::assertTrue($this->tickUntil(fn() => $skeleton->getAttackTarget() === $player, 100));
	}

	/**
	 * Stops the mob from moving and looking around (it still picks targets).
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private static function freezeMoves(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		return $mob;
	}

	public function testSkeletonFleesWolves() : void{
		$skeleton = new Skeleton($this->loc(0.5, 1, 0.5));
		$skeleton->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$wolf = self::freeze(new Wolf($this->loc(3.5, 1, 0.5)));
		self::assertTrue($this->tickUntil(fn() => self::runs($skeleton, AvoidEntityGoal::class), 60), "it runs from the wolf");
		self::assertTrue($this->tickUntil(fn() => $skeleton->getPosition()->distance($wolf->getPosition()) > 6, 200));
	}

	public function testSpiderFleesUnrolledArmadillosOnly() : void{
		$spider = new Spider($this->loc(0.5, 1, 0.5));
		$spider->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$armadillo = self::freeze(new Armadillo($this->loc(2.5, 1, 0.5)));
		$armadillo->setState(Armadillo::STATE_ROLLED_UP);
		self::assertFalse($this->tickUntil(fn() => self::runs($spider, AvoidEntityGoal::class), 40), "a rolled up armadillo isn't avoided");

		$armadillo->setState(Armadillo::STATE_UNROLLED);
		self::assertTrue($this->tickUntil(fn() => self::runs($spider, AvoidEntityGoal::class), 60), "an unrolled one is");
	}

	public function testFreezingImmunity() : void{
		self::assertFalse((new Skeleton($this->loc(0.5, 1, 0.5)))->canFreeze());
		self::assertFalse((new Stray($this->loc(0.5, 1, 2.5)))->canFreeze());
		self::assertTrue((new Bogged($this->loc(0.5, 1, 4.5)))->canFreeze());
		self::assertFalse((new Creaking($this->loc(0.5, 1, 6.5)))->canFreeze());
	}

	public function testSkeletonInPowderSnowBecomesStray() : void{
		$this->set(0, 1, 0, VanillaBlocks::POWDER_SNOW());
		$this->set(0, 2, 0, VanillaBlocks::POWDER_SNOW());
		$skeleton = self::freeze(new Skeleton($this->loc(0.5, 1, 0.5)));
		$skeleton->setMainHandItem(VanillaItems::BOW());
		$skeleton->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$this->runTicks(5);
		self::assertTrue($skeleton->isConvertingToStray(), "it shakes in powder snow");
		$this->runTicks(Skeleton::STRAY_CONVERSION_TICKS - 20);
		self::assertCount(0, $this->entitiesOf(Stray::class));
		$this->runTicks(30);
		$strays = $this->entitiesOf(Stray::class);
		self::assertCount(1, $strays, "it became a stray after 20 seconds");
		self::assertTrue($skeleton->isFlaggedForDespawn() || $skeleton->isClosed());
		self::assertSame(VanillaItems::BOW()->getTypeId(), $strays[0]->getMainHandItem()->getTypeId(), "with its equipment");
		self::assertSame(VanillaItems::IRON_HELMET()->getTypeId(), $strays[0]->getArmorInventory()->getHelmet()->getTypeId());
	}

	public function testLeavingPowderSnowCancelsConversion() : void{
		$this->set(0, 1, 0, VanillaBlocks::POWDER_SNOW());
		$this->set(0, 2, 0, VanillaBlocks::POWDER_SNOW());
		$skeleton = self::freeze(new Skeleton($this->loc(0.5, 1, 0.5)));
		$this->runTicks(200);
		self::assertTrue($skeleton->isConvertingToStray());
		$this->set(0, 1, 0, VanillaBlocks::AIR());
		$this->set(0, 2, 0, VanillaBlocks::AIR());
		$this->runTicks(5);
		self::assertFalse($skeleton->isConvertingToStray(), "the timer stops out of the snow");
		$this->runTicks(300);
		self::assertCount(0, $this->entitiesOf(Stray::class));
		self::assertFalse((new Stray($this->loc(5.5, 1, 0.5)))->isConvertingToStray(), "strays don't convert");
	}

	public function testSkeletonWithoutBowFightsInMelee() : void{
		$skeleton = new Skeleton($this->loc(0.5, 1, 0.5));
		$skeleton->setMainHandItem(VanillaItems::IRON_SWORD());
		$cow = $this->dummy(3.5, 0.5);
		$skeleton->setAttackTarget($cow);
		$health = $cow->getHealth();
		self::assertTrue($this->tickUntil(fn() => self::runs($skeleton, MeleeAttackGoal::class), 20), "melee mode");
		self::assertTrue($this->tickUntil(fn() => $cow->getHealth() < $health, 200), "it hits in melee");
		self::assertFalse(self::runs($skeleton, RangedAttackGoal::class));
		$skeleton->setMainHandItem(VanillaItems::BOW());
		self::assertTrue($this->tickUntil(fn() => self::runs($skeleton, RangedAttackGoal::class), 20), "back to ranged mode with a bow");
		self::assertCount(0, $this->entitiesOf(Arrow::class), "without shooting");
	}

	public function testUnderwaterSkeletonFightsInMelee() : void{
		for($x = -3; $x <= 5; ++$x){
			for($z = -3; $z <= 3; ++$z){
				for($y = 1; $y <= 3; ++$y){
					$this->set($x, $y, $z, VanillaBlocks::WATER());
				}
			}
		}
		$stray = new Stray($this->loc(0.5, 1, 0.5));
		$stray->setMainHandItem(VanillaItems::BOW());
		$cow = $this->dummy(2.5, 0.5);
		$stray->setAttackTarget($cow);
		$health = $cow->getHealth();
		self::assertTrue($this->tickUntil(fn() => self::runs($stray, MeleeAttackGoal::class), 20), "melee mode underwater");
		self::assertTrue($this->tickUntil(fn() => $cow->getHealth() < $health, 200), "it hits in melee underwater");
		self::assertCount(0, $this->entitiesOf(Arrow::class), "without shooting");
		self::assertTrue($cow->getEffects()->has(VanillaEffects::SLOWNESS()), "stray melee hits slow down");
	}

	public function testBoggedShearingRollsTwice() : void{
		$sameKind = 0;
		for($i = 0; $i < 12; ++$i){
			$bogged = new Bogged($this->loc(0.5 + $i * 3, 1, 0.5));
			foreach($this->entitiesOf(ItemEntity::class) as $item){
				$item->flagForDespawn();
			}
			self::assertTrue($bogged->shear());
			$red = $brown = 0;
			foreach($this->entitiesOf(ItemEntity::class) as $item){
				$typeId = $item->getItem()->getTypeId();
				if($typeId === VanillaBlocks::RED_MUSHROOM()->asItem()->getTypeId()){
					$red += $item->getItem()->getCount();
				}elseif($typeId === VanillaBlocks::BROWN_MUSHROOM()->asItem()->getTypeId()){
					$brown += $item->getItem()->getCount();
				}
			}
			self::assertSame(2, $red + $brown, "two mushrooms");
			if($red === 2 || $brown === 2){
				++$sameKind;
			}
			self::assertFalse($bogged->shear(), "only once");
			$bogged->flagForDespawn();
		}
		self::assertGreaterThan(0, $sameKind, "each roll is independent: sometimes two of a kind");
	}
}
