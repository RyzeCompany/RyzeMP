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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FindMountGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\ai\hostile\goal\CrossbowAttackGoal;
use pocketmine\entity\ai\hostile\goal\FleeBlockGoal;
use pocketmine\entity\ai\hostile\goal\FleeEntityGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\Zombie;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_filter;
use function array_values;
use function count;
use function mt_srand;

final class PiglinsHoglinsTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private static function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	private function piglin(float $x, float $z, bool $freeze = true) : Piglin{
		$piglin = new Piglin($this->loc($x, 1, $z));
		$piglin->setBaby(false);
		$piglin->setImmuneToZombification(true);
		$piglin->setHuntCooldown(100000);
		if($freeze){
			self::freeze($piglin);
		}
		return $piglin;
	}

	private function hoglin(float $x, float $z, bool $baby = false, bool $freeze = true) : Hoglin{
		$hoglin = new Hoglin($this->loc($x, 1, $z));
		$hoglin->setAge($baby ? Hoglin::BABY_AGE : 0);
		$hoglin->setImmuneToZombification(true);
		if($freeze){
			self::freeze($hoglin);
		}
		return $hoglin;
	}

	private function cow(float $x, float $z) : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		$cow->setAge(0);
		self::freeze($cow);
		return $cow;
	}

	private function player(Vector3 $pos, ?Item $held = null, GameMode $mode = GameMode::SURVIVAL) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held ?? VanillaItems::AIR());
		$location = $this->loc($pos->x, $pos->y, $pos->z);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("hasFiniteResources")->willReturn($mode !== GameMode::CREATIVE);
		$player->method("isCreative")->willReturn($mode === GameMode::CREATIVE);
		$player->method("getGamemode")->willReturn($mode);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(true);
		$player->method("isAlive")->willReturn(true);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturn($pos->add(0, 1.62, 0));
		$player->method("getSize")->willReturn(new EntitySizeInfo(1.8, 0.6, 1.62));
		$player->method("getId")->willReturn(434343);
		$player->method("getName")->willReturn("Tester");
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function dropOnGround(Item $item, float $x, float $z) : ItemEntity{
		$entity = new ItemEntity($this->loc($x, 1, $z), $item);
		$entity->setPickupDelay(0);
		$entity->spawnToAll();
		return $entity;
	}

	/**
	 * @phpstan-template T of Goal
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return T
	 */
	private static function goalOf(GoalSelector $selector, string $class) : Goal{
		return self::findGoal($selector, $class) ?? self::fail("no $class goal");
	}

	/**
	 * @phpstan-template T of Goal
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return T|null
	 */
	private static function findGoal(GoalSelector $selector, string $class) : ?Goal{
		/** @var list<\pocketmine\entity\ai\goal\PrioritizedGoal> $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($selector);
		foreach($goals as $entry){
			if($entry->goal instanceof $class){
				return $entry->goal;
			}
		}
		return null;
	}

	/**
	 * Whether the hoglin's player target goal may look for players now (its extra condition).
	 */
	private static function looksForPlayers(Hoglin $hoglin) : bool{
		$goal = self::goalOf($hoglin->getTargetSelector(), NearestAttackableTargetGoal::class);
		$condition = (new \ReflectionProperty(NearestAttackableTargetGoal::class, "condition"))->getValue($goal);
		self::assertInstanceOf(\Closure::class, $condition);
		return $condition();
	}

	private static function canFallInLove(Hoglin $hoglin, Player $player) : bool{
		$result = (new \ReflectionMethod(Hoglin::class, "canFallInLove"))->invoke($hoglin, $player);
		self::assertIsBool($result);
		return $result;
	}

	public function testPiglinsStayInPeaceful() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$piglin = $this->piglin(0.5, 0.5);
		$brute = new PiglinBrute($this->loc(3.5, 1, 0.5));
		$brute->setImmuneToZombification(true);
		self::freeze($brute);
		$zombie = new Zombie($this->loc(-3.5, 1, 0.5));
		self::freeze($zombie);
		$this->runTicks(45);
		self::assertTrue($zombie->isFlaggedForDespawn() || $zombie->isClosed(), "other monsters are removed");
		self::assertFalse($piglin->isFlaggedForDespawn() || $piglin->isClosed(), "piglins spawn in peaceful and stay");
		self::assertFalse($brute->isFlaggedForDespawn() || $brute->isClosed());
		self::assertSame(0.0, Mob::scaleDamageForDifficulty(Piglin::ATTACK_DAMAGE, World::DIFFICULTY_PEACEFUL), "harmless");
	}

	public function testHoglinFleesOutnumberingPiglinsWithATarget() : void{
		$hoglin = $this->hoglin(0.5, 0.5, false, false);
		$hoglin->getGoalSelector()->setFlagDisabled(Goal::FLAG_LOOK, true);
		$prey = $this->cow(20.5, 20.5);
		$piglins = [];
		for($i = 0; $i < 3; ++$i){
			$piglins[] = $piglin = $this->piglin(6.5, -1.5 + $i * 1.5);
			$piglin->setAttackTarget($prey);
		}
		$this->runTicks(2);
		self::assertSame($piglins[1], $hoglin->findOutnumberingPiglin(), "3 hunting piglins outnumber one hoglin");
		$this->runTicks(60);
		self::assertGreaterThan(9.0, $hoglin->getPosition()->distance(new Vector3(6.5, 1, 0.5)), "it ran away");
		self::assertNull($hoglin->getAttackTarget());
	}

	public function testPennedHoglinDoesntRestartFleeingEveryFewTicks() : void{
		//2x2 pen, 3 blocks high: no way out
		for($x = -1; $x <= 2; ++$x){
			for($z = -1; $z <= 2; ++$z){
				if($x === -1 || $x === 2 || $z === -1 || $z === 2){
					for($y = 1; $y <= 3; ++$y){
						$this->set($x, $y, $z, VanillaBlocks::STONE());
					}
				}
			}
		}
		$hoglin = $this->hoglin(0.5, 0.5, false, false);
		$hoglin->getGoalSelector()->setFlagDisabled(Goal::FLAG_LOOK, true);
		$prey = $this->cow(20.5, 20.5);
		for($i = 0; $i < 3; ++$i){
			$this->piglin(6.5, -1.5 + $i * 1.5)->setAttackTarget($prey);
		}
		$flee = self::goalOf($hoglin->getGoalSelector(), FleeEntityGoal::class);
		$startTick = new \ReflectionProperty($flee, "startTick");
		$nextSoundTick = new \ReflectionProperty($flee, "nextSoundTick");
		$starts = 0;
		$sounds = 0;
		$lastStart = $startTick->getValue($flee);
		$lastSound = $nextSoundTick->getValue($flee);
		for($i = 0; $i < 200; ++$i){
			$this->runTicks(1);
			if($startTick->getValue($flee) !== $lastStart){
				++$starts;
				$lastStart = $startTick->getValue($flee);
			}
			if($nextSoundTick->getValue($flee) !== $lastSound){
				++$sounds;
				$lastSound = $nextSoundTick->getValue($flee);
			}
		}
		self::assertGreaterThan(0, $starts, "it tries to get away");
		self::assertLessThanOrEqual(11, $starts, "at most one destination search a second when it can't get out");
		self::assertLessThanOrEqual(5, $sounds, "retreat sound every 2-5 s across restarts");
	}

	public function testCrossbowPiglinDoesntHurtPlayersInPeaceful() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$piglin->setMainHandItem(VanillaItems::CROSSBOW());
		$player = $this->player(new Vector3(8.5, 1, 0.5));
		$arrows = fn() => count(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Arrow));

		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$piglin->shootCrossbow($player, 8.0);
		self::assertSame(0, $arrows(), "no arrows at players in peaceful");

		$this->difficulty = World::DIFFICULTY_NORMAL;
		$piglin->shootCrossbow($player, 8.0);
		self::assertSame(1, $arrows(), "shoots them otherwise");
	}

	public function testHoglinDoesntFleeOnePiglinOrPiglinsWithoutTarget() : void{
		$hoglin = $this->hoglin(0.5, 0.5);
		$prey = $this->cow(20.5, 20.5);
		$hunter = $this->piglin(5.5, 0.5);
		$hunter->setAttackTarget($prey);
		$this->piglin(5.5, 2.5);
		$this->piglin(5.5, -1.5);
		$this->runTicks(25);
		self::assertNull($hoglin->findOutnumberingPiglin(), "one piglin with a target, the others idle");

		//two hoglins against two hunting piglins: not outnumbered
		$this->hoglin(1.5, 2.5);
		$second = $this->piglin(5.5, 3.5);
		$second->setAttackTarget($prey);
		$this->runTicks(25);
		self::assertNull($hoglin->findOutnumberingPiglin());
		//a third one
		$third = $this->piglin(4.5, 4.5);
		$third->setAttackTarget($prey);
		$this->runTicks(25);
		self::assertNotNull($hoglin->findOutnumberingPiglin());
	}

	public function testBabyHoglinsLookForPlayersAndHitEvery15Ticks() : void{
		$baby = $this->hoglin(0.5, 0.5, true);
		self::assertTrue(self::looksForPlayers($baby), "babies attack players too");

		$baby->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, false);
		$victim = $this->cow(1.5, 0.5);
		$victim->setMaxHealth(100);
		$victim->setHealth(100);
		$baby->setAttackTarget($victim);
		$this->runTicks(62);
		$hits = (int) (100 - $victim->getHealth());
		self::assertGreaterThanOrEqual(3, $hits, "1 damage every 0.75 s");
		self::assertLessThanOrEqual(5, $hits);
	}

	public function testHoglinAttackCooldownAfterEscapingARepellent() : void{
		$hoglin = $this->hoglin(0.5, 0.5, false, false);
		$player = $this->player(new Vector3(20.5, 1, 20.5), VanillaBlocks::CRIMSON_FUNGUS()->asItem());
		$hoglin->setAngryAt($this->cow(-10.5, -10.5), Hoglin::ANGER_TICKS);
		$this->set(3, 1, 0, VanillaBlocks::WARPED_FUNGUS());
		self::assertNotNull($hoglin->findRepellent());
		self::assertFalse(self::looksForPlayers($hoglin), "not while the repellent is near");
		$this->runTicks(6);
		self::assertTrue($hoglin->getGoalSelector()->isRunning(self::goalOf($hoglin->getGoalSelector(), FleeBlockGoal::class)), "walking away");
		self::assertSame(0, $hoglin->getAttackCooldown());

		//the fungus is gone (or it got away): escaped
		$this->set(3, 1, 0, VanillaBlocks::AIR());
		//noticed on the next look around once it stopped walking away
		for($i = 0; $i < 150 && $hoglin->getAttackCooldown() === 0; ++$i){
			$this->runTicks(1);
		}
		self::assertNull($hoglin->findRepellent());
		$cooldown = $hoglin->getAttackCooldown();
		self::assertGreaterThan(Hoglin::ATTACK_COOLDOWN_MIN_TICKS - 5, $cooldown);
		self::assertLessThanOrEqual(Hoglin::ATTACK_COOLDOWN_MAX_TICKS, $cooldown);
		self::assertFalse($hoglin->isAngry(), "escaped_event removes angry_hoglin");
		self::assertFalse(self::looksForPlayers($hoglin), "no targeting players for 10-15 s");
		self::assertFalse(self::canFallInLove($hoglin, $player), "love_filters");

		$this->runTicks($cooldown + 2);
		self::assertSame(0, $hoglin->getAttackCooldown());
		self::assertTrue(self::looksForPlayers($hoglin));
		self::assertTrue(self::canFallInLove($hoglin, $player));
	}

	public function testHoglinNoCooldownWithoutFleeing() : void{
		$hoglin = $this->hoglin(0.5, 0.5);
		$this->set(3, 1, 0, VanillaBlocks::WARPED_FUNGUS());
		$this->runTicks(25);
		$this->set(3, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(25);
		self::assertSame(0, $hoglin->getAttackCooldown(), "a frozen hoglin never walked away, so it didn't escape");
	}

	public function testFightingPiglinDoesntFleeSoulFire() : void{
		$piglin = $this->piglin(0.5, 0.5, false);
		$piglin->setMainHandItem(VanillaItems::GOLDEN_SWORD());
		$this->set(2, 1, 2, VanillaBlocks::SOUL_TORCH());
		$this->runTicks(25);
		$flee = self::goalOf($piglin->getGoalSelector(), FleeBlockGoal::class);
		self::assertTrue($piglin->getGoalSelector()->isRunning($flee), "an idle piglin walks away from soul fire");

		$fighter = $this->piglin(-0.5, -1.5, false);
		$fighter->setMainHandItem(VanillaItems::GOLDEN_SWORD());
		$enemy = new WitherSkeleton($this->loc(-2.5, 1, -1.5));
		self::freeze($enemy);
		$enemy->setMaxHealth(1000);
		$enemy->setHealth(1000);
		$fighter->setAttackTarget($enemy);
		$this->runTicks(30);
		$melee = self::goalOf($fighter->getGoalSelector(), MeleeAttackGoal::class);
		self::assertTrue($fighter->getGoalSelector()->isRunning($melee), "keeps fighting");
		self::assertFalse($fighter->getGoalSelector()->isRunning(self::goalOf($fighter->getGoalSelector(), FleeBlockGoal::class)));
	}

	public function testGoldNuggetsAreStoredAndGoldenDandelionsAdmired() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$nugget = VanillaItems::GOLD_NUGGET();
		self::assertFalse(PiglinAi::isLovedItem($nugget), "nuggets aren't admired");
		self::assertTrue($piglin->wantsItem($nugget));
		self::assertTrue($piglin->pickUp($this->dropOnGround((clone $nugget)->setCount(5), 1.0, 0.5)));
		self::assertFalse($piglin->isAdmiring());
		self::assertCount(1, $piglin->getInventoryContents());
		self::assertSame(1, $piglin->getInventoryContents()[0]->getCount(), "one at a time");
		self::assertTrue($piglin->pickUp($this->dropOnGround(clone $nugget, 1.0, 0.5)));
		self::assertCount(1, $piglin->getInventoryContents(), "stacked");
		self::assertSame(2, $piglin->getInventoryContents()[0]->getCount());

		$dandelion = VanillaBlocks::GOLDEN_DANDELION()->asItem();
		self::assertTrue(PiglinAi::isLovedItem($dandelion));
		self::assertTrue($piglin->pickUp($this->dropOnGround($dandelion, 1.0, 0.5)));
		self::assertTrue($piglin->isAdmiring());
		self::assertSame($dandelion->getTypeId(), $piglin->getOffHandItem()->getTypeId());
		self::assertFalse($piglin->wantsItem($nugget), "busy admiring");
		$this->runTicks(Piglin::ADMIRE_TICKS + 5);
		self::assertCount(2, $piglin->getInventoryContents(), "the dandelion is kept");
		$drops = $piglin->getDrops();
		self::assertCount(2, array_values(array_filter($drops, fn(Item $i) => $i->getTypeId() === ItemTypeIds::GOLD_NUGGET || $i->getTypeId() === $dandelion->getTypeId())));
	}

	public function testBreakingCopperChestsAngersPiglins() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$player = $this->player(new Vector3(0.5, 1, 6.5));
		$chest = VanillaBlocks::COPPER_CHEST();
		self::assertTrue(PiglinAi::isGuardedBlock($chest));
		self::assertSame(1, PiglinAi::onBlockBroken($player, $chest));
		self::assertTrue($piglin->isAngry());
	}

	public function testHoglinRepellentsFollowBedrock() : void{
		$repellents = PiglinAi::getHoglinRepellents();
		self::assertCount(3, $repellents);
		self::assertArrayHasKey(VanillaBlocks::WARPED_FUNGUS()->getTypeId(), $repellents);
		self::assertArrayHasKey(VanillaBlocks::NETHER_PORTAL()->getTypeId(), $repellents);
		self::assertArrayHasKey(VanillaBlocks::RESPAWN_ANCHOR()->getTypeId(), $repellents);
	}

	public function testBabyPiglinsAdmireGoldWithoutBartering() : void{
		$baby = $this->piglin(0.5, 0.5);
		$baby->setBaby(true);
		self::assertTrue($baby->wantsItem(VanillaItems::GOLD_INGOT()));
		self::assertTrue($baby->pickUp($this->dropOnGround(VanillaItems::GOLD_INGOT(), 1.0, 0.5)));
		self::assertTrue($baby->isAdmiring());
		$this->runTicks(Piglin::ADMIRE_TICKS + 5);
		self::assertFalse($baby->isAdmiring());
		self::assertCount(0, array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn()), "no barter");
		self::assertCount(1, $baby->getInventoryContents(), "it keeps the ingot");

		$player = $this->player(new Vector3(0.5, 1, 3.5), VanillaItems::GOLD_INGOT());
		self::assertFalse($baby->onInteract($player, new Vector3(0, 0, 0)), "babies can't be given gold (interactable_piglin)");
	}

	public function testBabyPiglinJockeyRidesABabyHoglin() : void{
		$babyHoglin = $this->hoglin(4.5, 0.5, true);
		$adultHoglin = $this->hoglin(-6.5, 0.5);
		$baby = $this->piglin(0.5, 0.5, false);
		$baby->setBaby(true);
		self::assertTrue($babyHoglin->canAddPassenger($baby));
		self::assertFalse($adultHoglin->canAddPassenger($baby), "only babies are rideable");
		self::assertFalse($babyHoglin->canAddPassenger($this->cow(8.5, 8.5)), "family piglin only");
		self::assertCount(3, $babyHoglin->getRideableSeats());

		Jockeys::makeMountSeeker($baby);
		$this->runTicks(120);
		self::assertSame($babyHoglin, $baby->getVehicle(), "the jockey found its mount");

		$babyHoglin->setAge(0);
		self::assertNull($baby->getVehicle(), "grown up: riders get off");
	}

	public function testOneBabyPiglinInTenIsAJockey() : void{
		$jockeys = 0;
		for($i = 0; $i < 300; ++$i){
			$baby = $this->piglin(0.5, 0.5);
			$baby->setBaby(true);
			Jockeys::onMobSpawned($baby);
			if(self::findGoal($baby->getGoalSelector(), FindMountGoal::class) !== null){
				++$jockeys;
			}
			$baby->close();
		}
		self::assertGreaterThan(12, $jockeys);
		self::assertLessThan(50, $jockeys);

		$adult = $this->piglin(0.5, 0.5);
		Jockeys::onMobSpawned($adult);
		self::assertNull(self::findGoal($adult->getGoalSelector(), FindMountGoal::class), "adults never");
	}

	public function testPiglinsChaseUpTo64Blocks() : void{
		$melee = $this->piglin(0.5, 0.5);
		$melee->setMainHandItem(VanillaItems::GOLDEN_SWORD());
		$archer = $this->piglin(0.5, 2.5);
		$archer->setMainHandItem(VanillaItems::CROSSBOW());
		$brute = new PiglinBrute($this->loc(0.5, 1, -2.5));
		$brute->setImmuneToZombification(true);
		self::freeze($brute);
		$far = $this->cow(20.5, 20.5);
		//50 blocks away, out of the test floor: moved there without ticking
		$far->teleport(new Vector3(50.5, 1, 0.5));
		foreach([$melee, $archer, $brute] as $mob){
			$mob->setAttackTarget($far);
			$mob->setAngryAt($far, 600);
		}
		self::assertTrue(self::goalOf($melee->getGoalSelector(), MeleeAttackGoal::class)->canStart());
		self::assertTrue(self::goalOf($archer->getGoalSelector(), CrossbowAttackGoal::class)->canStart());
		self::assertTrue(self::goalOf($brute->getGoalSelector(), MeleeAttackGoal::class)->canStart());
		self::assertTrue(self::goalOf($melee->getTargetSelector(), AngerTargetGoal::class)->canStart());
		self::assertTrue(self::goalOf($brute->getTargetSelector(), AngerTargetGoal::class)->canStart());

		$far->teleport(new Vector3(70.5, 1, 0.5));
		self::assertFalse(self::goalOf($melee->getGoalSelector(), MeleeAttackGoal::class)->canStart(), "beyond follow_range 64");
	}

	public function testAngryPiglinAcceptsGold() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$player = $this->player(new Vector3(0.5, 1, 3.5), VanillaItems::GOLD_INGOT()->setCount(2));
		$piglin->setAngryAt($player, Piglin::ANGER_TICKS);
		self::assertTrue($piglin->isAngry());
		self::assertTrue($piglin->onInteract($player, new Vector3(0, 0, 0)));
		self::assertTrue($piglin->isAdmiring());
		self::assertSame(1, $player->getInventory()->getItemInHand()->getCount());
	}

	public function testZoglinSpawnBabyChance() : void{
		$zoglin = new Zoglin($this->loc(0.5, 1, 0.5));
		self::assertSame(5, $zoglin->getSpawnBabyChance(), "entity_spawned: 95 adult / 5 baby");
		self::assertFalse($zoglin->isBaby());
		self::assertTrue($zoglin->makeSpawnBaby());
		self::assertTrue($zoglin->isBaby());
	}
}
