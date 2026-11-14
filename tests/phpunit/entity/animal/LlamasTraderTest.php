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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\FairTickBudget;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\LeashKnot;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function mt_srand;

/**
 * Llamas and the wandering trader against llama.json, trader_llama.json and wandering_trader.json: temper cap, strength
 * odds, the trader's leashed llamas and their taming, the trader's despawn rules, what it runs from, its potions and
 * milk, caravans, a tamed llama defending its owner.
 */
final class LlamasTraderTest extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		$this->world->setTime(1000);
	}

	protected function tearDown() : void{
		foreach($this->players as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-return Player&MockObject
	 */
	private function player(Location $location) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getBoundingBox")->willReturnCallback(fn() => new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		return $player;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	private function trader(float $x, float $z) : WanderingTrader{
		$trader = new WanderingTrader($this->loc($x, 1, $z));
		$trader->spawnToAll();
		$this->runTicks(1);
		return $trader;
	}

	public function testLlamaTemperCapIs30() : void{
		$llama = new Llama($this->loc(0.5, 1, 0.5));
		$llama->setTemper(100);
		self::assertSame(30, $llama->getTemper(), "tamemount max_temper 30");
		$horse = new Horse($this->loc(4.5, 1, 0.5));
		$horse->setTemper(100);
		self::assertSame(100, $horse->getTemper());
		//at the cap a break-in always succeeds: a random number below 30 is under 30
		$llama->close();
		$horse->close();
	}

	public function testStrengthOdds() : void{
		$counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
		$n = 20000;
		for($i = 0; $i < $n; ++$i){
			++$counts[Llama::randomStrength()];
		}
		foreach([1, 2, 3] as $s){
			self::assertEqualsWithDelta(0.32, $counts[$s] / $n, 0.02, "strength $s: weight 32");
		}
		foreach([4, 5] as $s){
			self::assertEqualsWithDelta(0.02, $counts[$s] / $n, 0.006, "strength $s: weight 2");
		}
	}

	public function testTraderBringsTwoLeashedLlamas() : void{
		$trader = $this->trader(0.5, 0.5);
		$llamas = $this->entitiesOf(TraderLlama::class);
		self::assertCount(2, $llamas, "spawn_entity num_to_spawn 2");
		self::assertCount(2, $trader->getLeashedLlamas());
		$player = $this->player($this->loc(2.5, 1, 0.5));
		foreach($llamas as $llama){
			self::assertSame($trader, $llama->getLeashHolder());
			self::assertTrue($llama->isFromWanderingTrader());
			self::assertFalse($llama->isBaby(), "from_wandering_trader: adults");
			self::assertFalse($llama->isTamed());
			self::assertFalse($llama->canDespawn(), "persistent while leashed");
			self::assertFalse($llama->canBeRiddenBy($player), "no rideable while it is the trader's");
		}
		self::assertGreaterThan(0, $trader->getDespawnDelay(), "the timer is a base component");

		//no more llamas later, nor for a loaded trader
		$this->runTicks(5);
		self::assertCount(2, $this->entitiesOf(TraderLlama::class));
		$copy = new WanderingTrader($this->loc(10.5, 1, 10.5), $trader->saveNBT());
		$copy->spawnToAll();
		$this->runTicks(2);
		self::assertCount(2, $this->entitiesOf(TraderLlama::class));
		$copy->close();
	}

	public function testLeashToTraderIsSavedAndFoundAgain() : void{
		$trader = $this->trader(0.5, 0.5);
		$llama = $this->entitiesOf(TraderLlama::class)[0];
		$nbt = $llama->saveNBT();
		self::assertSame($trader->getPersistentUniqueId(), $nbt->getLong(Mob::TAG_LEASH_ENTITY));
		self::assertNotNull($trader->saveNBT()->getTag(Entity::TAG_UNIQUE_ID), "the holder saves its ID");
		$llama->close();

		$loaded = new TraderLlama($this->loc(2.5, 1, 0.5), $nbt);
		$loaded->spawnToAll();
		self::assertTrue($loaded->isLeashed());
		self::assertFalse($loaded->canDespawn());
		$this->runTicks(25);
		self::assertSame($trader, $loaded->getLeashHolder(), "found again by its persistent ID");

		//a holder that is gone is given up after 5 searches, once the terrain within the lead's length is loaded
		$trader->close();
		$lost = new TraderLlama($this->loc(2.5, 1, 0.5), $nbt);
		$lost->spawnToAll();
		$this->runTicks(80);
		self::assertTrue($lost->isLeashed());
		$this->runTicks(40);
		self::assertFalse($lost->isLeashed());
		self::assertTrue($lost->isTamed(), "off the lead: tamed");
	}

	public function testKilledTraderLeavesTamedLlamas() : void{
		$trader = $this->trader(0.5, 0.5);
		$llamas = $this->entitiesOf(TraderLlama::class);
		$trader->kill();
		$this->runTicks(3);
		$leads = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && $entity->getItem()->getTypeId() === ItemTypeIds::LEAD){
				$leads += $entity->getItem()->getCount();
			}
		}
		self::assertSame(2, $leads, "the leads drop");
		foreach($llamas as $llama){
			self::assertFalse($llama->isLeashed());
			self::assertTrue($llama->isTamed(), "environment_sensor on_tame");
			self::assertNull($llama->getOwnerUuid(), "tamed without an owner");
			self::assertFalse($llama->isPersistent(), "remove_persistence");
			self::assertTrue($llama->canBeRiddenBy($this->player($this->loc(4.5, 1, 0.5))));

			$copy = new TraderLlama($this->loc(6.5, 1, 6.5), $llama->saveNBT());
			self::assertTrue($copy->isTamed(), "saved tamed");
			$copy->close();
		}
	}

	public function testTraderDespawnsWithItsLlamasAfterItsTimer() : void{
		$trader = $this->trader(0.5, 0.5);
		$llamas = $this->entitiesOf(TraderLlama::class);
		$trader->setDespawnDelay(100);
		$this->runTicks(60);
		self::assertFalse($trader->isFlaggedForDespawn() || $trader->isClosed(), "timer not run out");
		$this->runTicks(60);
		self::assertTrue($trader->isClosed() || $trader->isFlaggedForDespawn());
		foreach($llamas as $llama){
			self::assertTrue($llama->isClosed() || $llama->isFlaggedForDespawn(), "remove_child_entities");
		}
	}

	public function testSoldOutTraderDespawns() : void{
		$trader = $this->trader(0.5, 0.5);
		$trader->setDespawnDelay(1000000);
		$this->runTicks(25);
		self::assertFalse($trader->isFlaggedForDespawn() || $trader->isClosed());
		foreach($trader->getTradeOffers() as $offer){
			$offer->setUses($offer->getMaxUses());
		}
		self::assertTrue($trader->isSoldOut());
		$this->runTicks(25);
		self::assertTrue($trader->isClosed() || $trader->isFlaggedForDespawn(), "has_trade_supply false");
	}

	public function testLoadedSoldOutTraderTakesItsLlamasBeforeTheyReattach() : void{
		$trader = $this->trader(0.5, 0.5);
		$trader->setDespawnDelay(1000000);
		foreach($trader->getTradeOffers() as $offer){
			$offer->setUses($offer->getMaxUses());
		}
		$traderNbt = $trader->saveNBT();
		$llamaNbts = [];
		foreach($this->entitiesOf(TraderLlama::class) as $llama){
			$llamaNbts[] = $llama->saveNBT();
			$llama->close();
		}
		$trader->close();

		//the chunk loads again: the trader may check for despawning before its llamas look for it
		$loaded = new WanderingTrader($this->loc(0.5, 1, 0.5), $traderNbt);
		$loaded->spawnToAll();
		$llamas = [];
		foreach($llamaNbts as $i => $nbt){
			$llamas[] = $llama = new TraderLlama($this->loc(1.5 + $i, 1, 0.5), $nbt);
			$llama->spawnToAll();
			self::assertNull($llama->getLeashHolder());
		}
		self::assertCount(2, $loaded->getLeashedLlamas(), "llamas still looking for it are its children");
		$this->runTicks(25);
		self::assertTrue($loaded->isClosed() || $loaded->isFlaggedForDespawn());
		foreach($llamas as $llama){
			self::assertTrue($llama->isClosed() || $llama->isFlaggedForDespawn(), "remove_child_entities");
		}
		foreach($this->world->getEntities() as $entity){
			self::assertFalse($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn(), "no lead dropped");
		}
	}

	public function testTraderDoesNotDrinkWhileTrading() : void{
		$trader = $this->trader(0.5, 0.5);
		$player = $this->player($this->loc(2.5, 1, 0.5));
		$player->method("getCurrentWindow")->willReturn(new TradeInventory($trader, $player));
		$trader->setTradingPlayer($player);
		$trader->getEffects()->add(new EffectInstance(VanillaEffects::INVISIBILITY(), 1000));
		$this->runTicks(60);
		self::assertFalse($trader->isDrinking(), "trade_with_player keeps drink_milk from starting");
		self::assertTrue($trader->getEffects()->has(VanillaEffects::INVISIBILITY()));
	}

	public function testIdleCaravanDoesNotSearchPathsEveryTick() : void{
		$leader = new Llama($this->loc(0.5, 1, 0.5));
		$leader->spawnToAll();
		$this->set(0, 1, 3, VanillaBlocks::OAK_FENCE());
		$leader->setLeashHolder(LeashKnot::getOrCreate($this->world, new Vector3(0, 1, 3)));
		$followers = [];
		for($i = 0; $i < 4; ++$i){
			$followers[] = $llama = new Llama($this->loc(5.5 + $i, 1, -4.5 + 3 * $i));
			$llama->spawnToAll();
		}
		$this->runTicks(300);
		self::assertCount(4, array_filter($followers, fn(Llama $l) => $l->inCaravan()), "all in the caravan");

		$budget = (new \ReflectionProperty(AiBudget::class, "pathSearches"))->getValue();
		self::assertInstanceOf(FairTickBudget::class, $budget);
		$taken = new \ReflectionProperty(FairTickBudget::class, "taken");
		$tick = new \ReflectionProperty(FairTickBudget::class, "tick");
		$searches = 0;
		$lastTick = $tick->getValue($budget);
		for($i = 0; $i < 200; ++$i){
			$this->runTicks(1);
			if($tick->getValue($budget) !== $lastTick){ //some search asked for budget on this tick
				$lastTick = $tick->getValue($budget);
				$taking = $taken->getValue($budget);
				self::assertIsInt($taking);
				$searches += $taking;
			}
		}
		//(0-25 over 200 ticks depending on the random numbers; every tick would be 200 or more)
		self::assertLessThan(50, $searches, "a caravan standing still doesn't ask for paths every tick");

		//once the llama ahead goes somewhere else, its follower walks after it again
		$first = null;
		foreach($followers as $llama){
			if($llama->getCaravanHead() === $leader){
				$first = $llama;
			}
		}
		self::assertNotNull($first);
		$leader->teleport(new Vector3(6.5, 1, 4.5));
		$this->runTicks(100);
		self::assertLessThan(3.5, $first->getPosition()->distance($leader->getPosition()), "follows 2 blocks behind");
	}

	public function testTraderRunsFromZombiesAndIllagersOnly() : void{
		self::assertTrue(WanderingTrader::isAvoided(new Zombie($this->loc(0.5, 1, 0.5))));
		self::assertTrue(WanderingTrader::isAvoided(new ZombifiedPiglin($this->loc(0.5, 1, 0.5))), "zombie_pigman");
		self::assertTrue(WanderingTrader::isAvoided(new Pillager($this->loc(0.5, 1, 0.5))));
		self::assertTrue(WanderingTrader::isAvoided(new Vex($this->loc(0.5, 1, 0.5))));
		self::assertFalse(WanderingTrader::isAvoided(new Skeleton($this->loc(0.5, 1, 0.5))));
		self::assertFalse(WanderingTrader::isAvoided(new Creeper($this->loc(0.5, 1, 0.5))));
		foreach($this->world->getEntities() as $entity){
			$entity->close();
		}
	}

	public function testTraderDrinksInvisibilityThenMilk() : void{
		$trader = $this->trader(0.5, 0.5);
		foreach($this->entitiesOf(TraderLlama::class) as $llama){
			$llama->close();
		}
		$zombie = new Zombie($this->loc(4.5, 1, 0.5));
		$zombie->spawnToAll();
		$drank = false;
		for($i = 0; $i < 200 && !$trader->getEffects()->has(VanillaEffects::INVISIBILITY()); ++$i){
			$drank = $drank || $trader->isDrinking();
			$this->runTicks(1);
		}
		self::assertTrue($drank, "drink_potion when avoiding mobs");
		self::assertTrue($trader->getEffects()->has(VanillaEffects::INVISIBILITY()));
		self::assertLessThanOrEqual(3600, $trader->getEffects()->get(VanillaEffects::INVISIBILITY())?->getDuration(), "daytime: short invisibility");
		$zombie->close();

		//day, invisible, safe: milk
		for($i = 0; $i < 300 && $trader->getEffects()->has(VanillaEffects::INVISIBILITY()); ++$i){
			$this->runTicks(1);
		}
		self::assertFalse($trader->getEffects()->has(VanillaEffects::INVISIBILITY()), "drink_milk");
	}

	public function testHurtTraderDrinksLongInvisibilityAtDusk() : void{
		$this->world->setTime(World::TIME_SUNSET + 500);
		$trader = $this->trader(0.5, 0.5);
		$player = $this->player($this->loc(2.5, 1, 0.5));
		$trader->attack(new EntityDamageByEntityEvent($player, $trader, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(60);
		self::assertGreaterThan(3600, $trader->getEffects()->get(VanillaEffects::INVISIBILITY())?->getDuration() ?? 0, "long invisibility from dusk to midnight, even when hurt by a player");
	}

	public function testCaravanLinesUpBehindALedLlama() : void{
		$leader = new Llama($this->loc(0.5, 1, 0.5));
		$leader->spawnToAll();
		$this->set(0, 1, 3, VanillaBlocks::OAK_FENCE());
		$leader->setLeashHolder(LeashKnot::getOrCreate($this->world, new Vector3(0, 1, 3)));
		$second = new Llama($this->loc(4.5, 1, 0.5));
		$second->spawnToAll();
		$third = new Llama($this->loc(-4.5, 1, 0.5));
		$third->spawnToAll();
		$loose = new Llama($this->loc(20.5, 1, 20.5));
		$loose->spawnToAll();
		//searches happen on ticks staggered by entity ID: wait for both rather than a fixed time, and keep the llamas
		//close enough that wandering can't take them out of range first
		for($i = 0; $i < 200 && !($second->inCaravan() && $third->inCaravan()); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($second->inCaravan() && $third->inCaravan(), "follow_caravan");
		$heads = [$second->getCaravanHead(), $third->getCaravanHead()];
		self::assertContains($leader, $heads, "one follows the led llama");
		self::assertTrue($second->getCaravanHead() === $third || $third->getCaravanHead() === $second, "the other one follows it");
		self::assertFalse($loose->inCaravan(), "too far");

		//in a caravan, being hurt doesn't make it angry
		$zombie = new Zombie($this->loc(8.5, 1, 4.5));
		$zombie->spawnToAll();
		$follower = $second->getCaravanHead() === $leader ? $second : $third;
		$follower->attack(new EntityDamageByEntityEvent($zombie, $follower, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertNotSame($zombie, $follower->getAttackTarget());

		//off the lead, the caravan breaks up
		$leader->unleash(false);
		$this->runTicks(10);
		self::assertFalse($second->inCaravan() || $third->inCaravan());
		self::assertCount(0, array_filter([$leader, $second, $third], fn(Llama $l) => $l->hasCaravanTail()));
	}

	public function testTamedLlamaSpitsAtItsOwnersAttacker() : void{
		$owner = $this->player($this->loc(2.5, 1, 0.5));
		$llama = new Llama($this->loc(0.5, 1, 0.5));
		$llama->setOwner($owner);
		$llama->spawnToAll();
		$zombie = new Zombie($this->loc(8.5, 1, 0.5));
		$zombie->spawnToAll();
		$owner->method("getLastDamageCause")->willReturn(new EntityDamageByEntityEvent($zombie, $owner, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertSame($zombie, $llama->getAttackTarget(), "hurt_by_target hurt_owner");
	}
}
