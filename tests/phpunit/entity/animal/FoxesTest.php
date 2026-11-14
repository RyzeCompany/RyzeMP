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

namespace pocketmine\entity\animal;

use pocketmine\block\Carrot;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\DefendTrustedTargetGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\ai\goal\RaidGardenGoal;
use pocketmine\entity\ai\goal\StrollTowardsVillageGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function mt_srand;

/**
 * Foxes (item pickup and eating, raiding berry bushes, napping under cover, defending trusted players, strolling
 * towards villages, stalking flag) and rabbits raiding carrot crops.
 */
final class FoxesTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		$this->world->setTime(World::TIME_NIGHT + 2000);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function runUntil(\Closure $condition, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return true;
			}
			$this->runTicks(1);
		}
		return $condition();
	}

	private static function raidGoal(Mob $mob) : RaidGardenGoal{
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($mob->getGoalSelector());
		foreach($goals as $entry){
			if($entry->goal instanceof RaidGardenGoal){
				return $entry->goal;
			}
		}
		throw new \AssertionError("no raid_garden goal");
	}

	public function testFoxPicksUpSweetBerriesAndEatsThem() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$fox->setHeldItem(null);
		$fox->setMaxHealth(10);
		$fox->setHealth(5);
		$item = $this->world->dropItem(new Vector3(2.5, 1, 0.5), VanillaItems::SWEET_BERRIES()->setCount(3), new Vector3(0, 0, 0));
		self::assertInstanceOf(ItemEntity::class, $item);
		self::assertTrue($this->runUntil(fn() => $fox->getHeldItem() !== null, 200), "picks up the berries");
		self::assertTrue(VanillaItems::SWEET_BERRIES()->equals($fox->getHeldItem() ?? VanillaItems::AIR(), false, false));
		self::assertSame(2, $item->getItem()->getCount(), "takes one in its mouth");
		self::assertTrue($this->runUntil(fn() => $fox->getHeldItem() === null, 80), "eats it after 28 ticks");
		self::assertGreaterThan(5.0, $fox->getHealth(), "eating heals");
	}

	public function testFoxPrefersFoodOverOtherItems() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$fox->setHeldItem(VanillaItems::FEATHER());
		$this->world->dropItem(new Vector3(2.5, 1, 0.5), VanillaItems::STICK(), new Vector3(0, 0, 0));
		$this->runTicks(100);
		self::assertTrue(VanillaItems::FEATHER()->equals($fox->getHeldItem() ?? VanillaItems::AIR(), false, false), "keeps its feather over a stick");
		$apple = $this->world->dropItem($fox->getPosition()->add(1.5, 0, 0), VanillaItems::APPLE(), new Vector3(0, 0, 0));
		self::assertInstanceOf(ItemEntity::class, $apple);
		//the fox may be wandering off: keep the apple within its pickup range (searches are staggered by entity ID)
		self::assertTrue($this->runUntil(function() use ($fox, $apple) : bool{
			if(!$apple->isClosed() && $apple->getPosition()->distanceSquared($fox->getPosition()) > 2.25){
				$apple->teleport($fox->getPosition()->add(1, 0, 0));
			}
			return VanillaItems::APPLE()->equals($fox->getHeldItem() ?? VanillaItems::AIR(), false, false);
		}, 200), "swaps it for an apple");
	}

	public function testFoxDoesNotPickUpItemsWithoutMobGriefing() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$fox->setHeldItem(null);
		$this->world->dropItem(new Vector3(2.5, 1, 0.5), VanillaItems::SWEET_BERRIES(), new Vector3(0, 0, 0));
		$this->runTicks(100);
		self::assertNull($fox->getHeldItem());
	}

	public function testFoxRaidsARipeBerryBush() : void{
		$this->set(4, 0, 0, VanillaBlocks::GRASS());
		$this->set(4, 1, 0, VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE));
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$fox->setHeldItem(VanillaItems::FEATHER()); //nothing to eat afterwards
		self::raidGoal($fox)->resetSearchDelay();
		self::assertTrue($this->runUntil(function() : bool{
			$bush = $this->get(4, 1, 0);
			return $bush instanceof SweetBerryBush && $bush->getAge() === SweetBerryBush::STAGE_BUSH_NO_BERRIES;
		}, 600), "eats the berries");
		$dropped = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && VanillaItems::SWEET_BERRIES()->equals($entity->getItem(), false, false)){
				$dropped += $entity->getItem()->getCount();
			}
		}
		self::assertGreaterThanOrEqual(2, $dropped, "a fox with a full mouth drops the berries of a mature bush (2-3)");
		self::assertLessThanOrEqual(3, $dropped);
	}

	public function testRaidGardenSearchFindsTheNearestBlock() : void{
		$bush = VanillaBlocks::SWEET_BERRY_BUSH()->setAge(SweetBerryBush::STAGE_MATURE);
		$this->set(9, 1, -9, $bush);
		$this->set(-3, 1, 2, $bush);
		$this->set(1, 2, 1, $bush); //one up: searched after the fox's own level
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$goal = new RaidGardenGoal($fox, fn(int $stateId) => $stateId === $bush->getStateId(), fn() => true, searchRange: 12, searchHeight: 2);
		$found = $goal->findNearest($this->world, 0, 1, 0);
		self::assertNotNull($found);
		self::assertSame([-3, 1, 2], [(int) $found[0]->x, (int) $found[0]->y, (int) $found[0]->z]);
		$this->set(-3, 1, 2, VanillaBlocks::AIR());
		$this->set(9, 1, -9, VanillaBlocks::AIR());
		$found = $goal->findNearest($this->world, 0, 1, 0);
		self::assertNotNull($found);
		self::assertSame([1, 2, 1], [(int) $found[0]->x, (int) $found[0]->y, (int) $found[0]->z]);
	}

	public function testRaidGardenSearchSkipsEmptySubChunksAndFindsCaveVinesFromBelow() : void{
		$this->set(3, 2, 0, VanillaBlocks::CAVE_VINES()->setBerries(true)->setHead(true));
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$goal = new RaidGardenGoal($fox, fn(int $stateId) => $stateId === VanillaBlocks::CAVE_VINES()->setBerries(true)->setHead(true)->getStateId(), fn() => true, searchRange: 12, searchHeight: 2);
		$found = $goal->findNearest($this->world, 0, 1, 0);
		self::assertNotNull($found);
		self::assertSame([3, 2, 0], [(int) $found[0]->x, (int) $found[0]->y, (int) $found[0]->z]);
		self::assertSame(1, $found[1], "eaten standing below it");
		self::assertNull($goal->findNearest($this->world, 0, 40, 0), "out of the search height");
	}

	public function testRabbitEatsCarrotsOnlyWithMobGriefing() : void{
		for($x = 2; $x <= 4; ++$x){
			$this->set($x, 0, 0, VanillaBlocks::FARMLAND());
			$this->set($x, 1, 0, VanillaBlocks::CARROTS()->setAge(Carrot::MAX_AGE));
		}
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$rabbit = new Rabbit($this->loc(0.5, 1, 0.5));
		$eaten = function() : bool{
			for($x = 2; $x <= 4; ++$x){
				$carrot = $this->get($x, 1, 0);
				if(!$carrot instanceof Carrot || $carrot->getAge() < Carrot::MAX_AGE){
					return true;
				}
			}
			return false;
		};
		$this->runTicks(450);
		self::assertFalse($eaten(), "mobGriefing off");
		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		self::raidGoal($rabbit)->resetSearchDelay();
		self::assertTrue($this->runUntil($eaten, 900), "bites a carrot");
		self::assertTrue($rabbit->isAlive());
	}

	public function testRabbitRemovesCarrotWithoutGrowthLeft() : void{
		$this->set(2, 0, 0, VanillaBlocks::FARMLAND());
		$this->set(2, 1, 0, VanillaBlocks::CARROTS()->setAge(0));
		self::raidGoal(new Rabbit($this->loc(0.5, 1, 0.5)))->resetSearchDelay();
		self::assertTrue($this->runUntil(fn() => !$this->get(2, 1, 0) instanceof Carrot, 900));
	}

	public function testFoxNapsOnlyUnderCover() : void{
		$this->world->setTime(1000);
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$this->runTicks(2);
		self::assertTrue($fox->isOnGround());
		self::assertFalse($fox->canNap(), "under the open sky");
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				$this->set($x, 3, $z, VanillaBlocks::OAK_LEAVES());
			}
		}
		self::assertTrue($fox->canNap(), "under a tree");
		$this->world->getWeather()->setThunder(2000);
		self::assertFalse($fox->canNap(), "not in thunderstorms");
		$this->world->getWeather()->setClear(2000);
		$this->world->setTime(World::TIME_NIGHT);
		self::assertFalse($fox->canNap(), "only by day");
	}

	public function testSneakingAndTrustedPlayersDoNotScareFoxes() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$isThreat = new \ReflectionMethod(Fox::class, "isThreat");
		$sneaking = $this->createMock(Player::class);
		$sneaking->method("isSneaking")->willReturn(true);
		self::assertFalse($isThreat->invoke($fox, $sneaking));
		$walking = $this->createMock(Player::class);
		$walking->method("isSneaking")->willReturn(false);
		$walking->method("getUniqueId")->willReturn(\Ramsey\Uuid\Uuid::uuid4());
		self::assertTrue($isThreat->invoke($fox, $walking));
		$goals = $fox->getGoalSelector()->count();
		$targetGoals = $fox->getTargetSelector()->count();
		$fox->addTrustedPlayer($walking);
		self::assertFalse($isThreat->invoke($fox, $walking));
		self::assertSame($goals + 1, $fox->getGoalSelector()->count(), "defending_fox melee, added once the fox trusts someone");
		self::assertSame($targetGoals + 1, $fox->getTargetSelector()->count(), "defend_trusted_target");
		foreach([$sneaking, $walking] as $player){
			(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
	}

	public function testDefendTrustedTargetAttacksWhoeverHurtTheTrustedEntity() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$fox->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$trusted = new Cow($this->loc(3.5, 1, 0.5));
		$trusted->setMaxHealth(1000);
		$trusted->setHealth(1000);
		$zombie = new Zombie($this->loc(20.5, 1, 0.5));
		$defending = false;
		$goal = new DefendTrustedTargetGoal($fox, fn() => [$trusted], onDefend: function() use (&$defending) : void{ $defending = true; });
		self::assertFalse($goal->canStart(), "nobody hurt it");
		$trusted->attack(new EntityDamageByEntityEvent($zombie, $trusted, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1.0));
		$this->runTicks(10);
		self::assertTrue($goal->canStart());
		$goal->start();
		self::assertSame($zombie, $fox->getAttackTarget());
		self::assertTrue($defending);
		$goal->stop();
		$this->runTicks(10);
		self::assertFalse($goal->canStart(), "the same hit is answered once");
	}

	public function testDefendTrustedTargetIgnoresOldDamageAndTrustedAttackers() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$fox->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$trusted = new Cow($this->loc(3.5, 1, 0.5));
		$trusted->setMaxHealth(1000);
		$trusted->setHealth(1000);
		$zombie = new Zombie($this->loc(20.5, 1, 0.5));
		$trusted->attack(new EntityDamageByEntityEvent($zombie, $trusted, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1.0));
		$this->runTicks(10);
		//a fox just bred or loaded: the hit happened before it looked
		$goal = new DefendTrustedTargetGoal($fox, fn() => [$trusted], canTarget: fn(Living $attacker) => $attacker !== $zombie);
		self::assertFalse($goal->canStart(), "old damage");
		$this->runTicks(10);
		self::assertFalse($goal->canStart(), "still the old damage");
		$trusted->attack(new EntityDamageByEntityEvent($zombie, $trusted, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1.0));
		$this->runTicks(10);
		self::assertFalse($goal->canStart(), "an attacker it may not target (another trusted player)");
	}

	public function testStalkingFlagWhileHuntingPrey() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$chicken = new Chicken($this->loc(5.5, 1, 0.5));
		self::assertFalse($fox->isStalking());
		$fox->setAttackTarget($chicken);
		self::assertTrue($fox->isStalking());
		$fox->setAttackTarget(null);
		self::assertFalse($fox->isStalking());
	}

	public function testNightStrollPicksAVillageBellWithinRange() : void{
		$fox = new Fox($this->loc(0.5, 1, 0.5));
		$goal = new StrollTowardsVillageGoal($fox);
		self::assertNull($goal->pickTarget($this->world, $fox->getPosition()), "no village");
		$this->set(20, 1, 0, VanillaBlocks::BELL());
		$this->world->getPoiManager()->invalidateChunk(1, 0);
		$target = $goal->pickTarget($this->world, $fox->getPosition());
		self::assertNotNull($target);
		self::assertSame([20, 1, 0], [(int) $target->x, (int) $target->y, (int) $target->z]);
		$goal->forceNextTry();
		self::assertTrue($goal->canStart());
		$goal->start();
		self::assertTrue($goal->canContinue(), "walks there");
	}
}
