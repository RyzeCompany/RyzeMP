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

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function count;

/**
 * Zombies hunting villagers, villagers killed by zombies turning into zombie villagers, and curing them.
 */
final class ZombieVillageTest extends MobTestCase{

	private function loc(float $x, float $z) : Location{
		return new Location($x, 1, $z, $this->world, 0, 0);
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
	private function runUntil(\Closure $condition, int $maxTicks) : void{
		for($i = 0; $i < $maxTicks && !$condition(); ++$i){
			$this->runTicks(1);
		}
	}

	public function testZombiesHuntVillagersAndTraders() : void{
		$villager = new VillagerV2($this->loc(8.5, 0.5));
		$villager->setAge(0);
		$villager->spawnToAll();
		$zombies = [
			Zombie::class => fn(Location $l) => new Zombie($l),
			Husk::class => fn(Location $l) => new Husk($l),
			Drowned::class => fn(Location $l) => new Drowned($l),
			ZombieVillager::class => fn(Location $l) => new ZombieVillager($l)
		];
		foreach($zombies as $class => $create){
			if($class === Drowned::class){
				//drowned only go after villagers in water or at night
				$this->world->setTime(18000);
			}
			$zombie = $create($this->loc(0.5, 0.5));
			$zombie->spawnToAll();
			$this->runUntil(fn() => $zombie->getAttackTarget() !== null, 100);
			self::assertSame($villager, $zombie->getAttackTarget(), "$class targets the villager");
			$zombie->close();
		}
		$piglin = new ZombifiedPiglin($this->loc(0.5, 0.5));
		$piglin->spawnToAll();
		$this->runTicks(60);
		self::assertNull($piglin->getAttackTarget(), "zombified piglins leave villagers alone");
		$piglin->close();
		self::assertTrue(Zombie::isVillageTarget(new WanderingTrader($this->loc(12.5, 12.5))));
		$villager->close();
	}

	public function testVillagerKilledByAZombieOnHardBecomesAZombieVillager() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$villager = new VillagerV2($this->loc(4.5, 4.5));
		$villager->setAge(0);
		$villager->setProfession(VillagerV2::PROFESSION_LIBRARIAN);
		$villager->setNameTag("Bob");
		$villager->addTradeExperience(20);
		$offers = $villager->getTradeOffers();
		$villager->spawnToAll();
		$zombie = new Zombie($this->loc(5.5, 4.5));
		$this->runTicks(1); //a mob dying before its first tick skips onDeath()
		$villager->attack(new EntityDamageByEntityEvent($zombie, $villager, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 100));
		self::assertFalse($villager->isAlive());
		$zombieVillagers = $this->entitiesOf(ZombieVillager::class);
		self::assertCount(1, $zombieVillagers);
		$zv = $zombieVillagers[0];
		self::assertSame(VillagerV2::PROFESSION_LIBRARIAN, $zv->getProfession());
		self::assertSame("Bob", $zv->getNameTag());

		//survives a save, then is cured back into the same villager
		$copy = new ZombieVillager($this->loc(4.5, 4.5), $zv->saveNBT());
		$zv->close();
		$zombie->close();
		$cured = $copy->finishConversion();
		self::assertInstanceOf(VillagerV2::class, $cured);
		self::assertSame(VillagerV2::PROFESSION_LIBRARIAN, $cured->getProfession());
		self::assertSame(20, $cured->getTradeExperience());
		self::assertCount(count($offers), $cured->getTradeOffers());
		self::assertTrue($cured->isAlive());
		self::assertEqualsWithDelta($cured->getMaxHealth(), $cured->getHealth(), 0.001);
		self::assertSame("Bob", $cured->getNameTag());
		$cured->close();
		$copy->close();
	}

	public function testVillagerKilledByAPlayerStaysDead() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$villager = new VillagerV2($this->loc(4.5, 4.5));
		$villager->spawnToAll();
		$this->runTicks(1);
		$villager->attack(new EntityDamageEvent($villager, EntityDamageEvent::CAUSE_MAGIC, 100));
		self::assertFalse($villager->isAlive());
		self::assertCount(0, $this->entitiesOf(ZombieVillager::class));
	}

	public function testCureWithWeaknessAndGoldenApple() : void{
		$zv = new ZombieVillager($this->loc(0.5, 0.5));
		$zv->spawnToAll();
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand(VanillaItems::GOLDEN_APPLE()->setCount(2));
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));

		self::assertSame("", $zv->getInteractButtonText($player), "no Weakness yet");
		self::assertFalse($zv->onInteract($player, new Vector3(0, 0, 0)));
		self::assertFalse($zv->isConverting());

		$zv->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 1800));
		self::assertSame("action.interact.cure", $zv->getInteractButtonText($player));
		self::assertTrue($zv->onInteract($player, new Vector3(0, 0, 0)));
		self::assertTrue($zv->isConverting());
		self::assertGreaterThanOrEqual(ZombieVillager::CURE_MIN_TICKS - 1, $zv->getConversionTicks());
		self::assertLessThanOrEqual(ZombieVillager::CURE_MAX_TICKS, $zv->getConversionTicks());
		self::assertSame(1, $inventory->getItemInHand()->getCount(), "one apple eaten");
		self::assertFalse($zv->getEffects()->has(VanillaEffects::WEAKNESS()));
		$zv->close();
	}
}
