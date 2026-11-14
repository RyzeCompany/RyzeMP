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
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\animal\Frog;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Rabbit;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\Sniffer;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Zombie;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function count;
use function mt_srand;

/**
 * Ageable features shared by the baby mobs: golden dandelion age lock, spawn eggs used on a mob, baby chance on
 * spawn, baby collision boxes and baby feeding.
 */
final class AgeableTest extends MobTestCase{

	/**
	 * @phpstan-return Player&MockObject
	 */
	private function player(Item $held, bool $survival = true) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn($survival);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(Position::fromObject(new Vector3(0, 1, 0), $this->world));
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		$player->method("getName")->willReturn("Tester");
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function loc(float $x = 2.5, float $z = 2.5) : Location{
		return new Location($x, 1, $z, $this->world, 0, 0);
	}

	private static function age(Mob $mob, int $ticks) : void{
		(new \ReflectionMethod($mob, "entityBaseTick"))->invoke($mob, $ticks);
	}

	private static function dandelion(int $count = 1) : Item{
		return VanillaBlocks::GOLDEN_DANDELION()->asItem()->setCount($count);
	}

	/**
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	private function entitiesOf(string $class) : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof $class && !$entity->isClosed()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	public function testGoldenDandelionLocksAndUnlocksGrowth() : void{
		$calf = new Cow($this->loc());
		$calf->setAge(Animal::BABY_AGE + 6000);
		$calf->setPersistent(false);
		$player = $this->player(self::dandelion(3));

		self::assertTrue($calf->onInteract($player, Vector3::zero()));
		self::assertTrue($calf->isAgeLocked());
		self::assertSame(Animal::BABY_AGE, $calf->getAge(), "locking restarts the growth");
		self::assertTrue($calf->isPersistent(), "a locked baby never despawns");
		self::assertSame(2, $player->getInventory()->getItemInHand()->getCount());

		self::age($calf, 36000);
		self::assertTrue($calf->isBaby(), "no growth while locked (30 minutes)");
		self::assertSame(Animal::BABY_AGE, $calf->getAge());
		self::assertSame(0, $calf->getAgeLockCooldown(), "particles and cooldown are over");

		//feeding doesn't age a locked baby and the food is kept
		$wheat = $this->player(VanillaItems::WHEAT()->setCount(5));
		self::assertFalse($calf->onInteract($wheat, Vector3::zero()));
		self::assertSame(5, $wheat->getInventory()->getItemInHand()->getCount());
		self::assertSame(Animal::BABY_AGE, $calf->getAge());

		self::assertTrue($calf->onInteract($player, Vector3::zero()));
		self::assertFalse($calf->isAgeLocked());
		self::assertSame(Animal::BABY_AGE, $calf->getAge(), "unlocking restarts the growth from the beginning");
		self::age($calf, 1000);
		self::assertSame(Animal::BABY_AGE + 1000, $calf->getAge());
	}

	public function testGoldenDandelionCooldownAndRules() : void{
		$calf = new Cow($this->loc());
		$calf->setAge(Animal::BABY_AGE);
		$player = $this->player(self::dandelion(5));
		self::assertTrue($calf->onInteract($player, Vector3::zero()));
		self::assertSame(40, $calf->getAgeLockCooldown());
		self::assertFalse($calf->onInteract($player, Vector3::zero()), "2 second cooldown");
		self::age($calf, 39);
		self::assertFalse($calf->onInteract($player, Vector3::zero()));
		self::age($calf, 1);
		self::assertTrue($calf->onInteract($player, Vector3::zero()));
		self::assertFalse($calf->isAgeLocked());

		$adult = new Cow($this->loc(5.5));
		self::assertFalse($adult->onInteract($player, Vector3::zero()), "adults can't be locked");
		self::assertFalse($adult->isAgeLocked());

		$foal = new SkeletonHorse($this->loc(8.5));
		$foal->setAge(Animal::BABY_AGE);
		self::assertFalse($foal->onInteract($player, Vector3::zero()), "skeleton horses have no pause_growth_items");

		$creative = $this->player(self::dandelion(1), false);
		$lamb = new Sheep($this->loc(11.5));
		$lamb->setAge(Animal::BABY_AGE);
		self::assertTrue($lamb->onInteract($creative, Vector3::zero()));
		self::assertSame(1, $creative->getInventory()->getItemInHand()->getCount(), "creative keeps the dandelion");
	}

	public function testAgeLockIsSaved() : void{
		$calf = new Cow($this->loc());
		$calf->setAge(Animal::BABY_AGE);
		$calf->setAgeLocked(true);
		$nbt = $calf->saveNBT();
		self::assertSame(1, $nbt->getByte("AgeLocked"));
		$loaded = new Cow($this->loc(5.5), $nbt);
		self::assertTrue($loaded->isAgeLocked());
		self::age($loaded, 30000);
		self::assertTrue($loaded->isBaby());
	}

	public function testTadpoleAgeLock() : void{
		$tadpole = new Tadpole($this->loc());
		$tadpole->setAge(20000);
		$player = $this->player(self::dandelion());
		self::assertTrue($tadpole->onInteract($player, Vector3::zero()));
		self::assertTrue($tadpole->isAgeLocked());
		self::assertSame(0, $tadpole->getAge());
		self::age($tadpole, Tadpole::GROW_UP_TICKS + 10);
		self::assertFalse($tadpole->isClosed() || $tadpole->isFlaggedForDespawn(), "a locked tadpole never becomes a frog");
		self::assertSame(0, $tadpole->getAge());

		$unlocked = new Tadpole($this->loc(5.5));
		$slime = $this->player(VanillaItems::SLIMEBALL());
		self::assertTrue($unlocked->onInteract($slime, Vector3::zero()));
		self::assertSame(2400, $unlocked->getAge(), "a slime ball is 10% of the whole growing time");
	}

	public function testFeedingGrowsByATenthOfTheWholeTime() : void{
		$calf = new Cow($this->loc());
		$calf->setAge(-2000);
		self::assertTrue($calf->onInteract($this->player(VanillaItems::WHEAT()), Vector3::zero()));
		self::assertSame(0, $calf->getAge(), "2 minutes per feeding, capped at growing up");

		$calf2 = new Cow($this->loc(5.5));
		$calf2->setAge(Animal::BABY_AGE);
		self::assertTrue($calf2->onInteract($this->player(VanillaItems::WHEAT()), Vector3::zero()));
		self::assertSame(Animal::BABY_AGE + 2400, $calf2->getAge());

		$snifflet = new Sniffer($this->loc(8.5));
		$snifflet->setAge(Sniffer::SNIFFLET_AGE);
		self::assertTrue($snifflet->onInteract($this->player(VanillaItems::TORCHFLOWER_SEEDS()), Vector3::zero()));
		self::assertSame(Sniffer::SNIFFLET_AGE + 4800, $snifflet->getAge(), "10% of the 40 minutes of a snifflet");
	}

	public function testOwnSpawnEggSpawnsABaby() : void{
		$cow = new Cow($this->loc());
		$egg = VanillaItems::COW_SPAWN_EGG()->setCount(2)->setCustomName("Daisy");
		$player = $this->player($egg);
		self::assertTrue($cow->onInteract($player, Vector3::zero()));
		$cows = $this->entitiesOf(Cow::class);
		self::assertCount(2, $cows);
		$calf = $cows[0] === $cow ? $cows[1] : $cows[0];
		self::assertInstanceOf(Cow::class, $calf);
		self::assertTrue($calf->isBaby());
		self::assertSame(Animal::BABY_AGE, $calf->getAge());
		self::assertSame("Daisy", $calf->getNameTag());
		self::assertSame(SpawnReason::SPAWN_EGG, $calf->getSpawnReason());
		self::assertSame(1, $player->getInventory()->getItemInHand()->getCount());

		$sheep = new Sheep($this->loc(6.5));
		self::assertFalse($sheep->onInteract($this->player(VanillaItems::COW_SPAWN_EGG()), Vector3::zero()), "another mob's egg does nothing");
		self::assertCount(1, $this->entitiesOf(Sheep::class));

		$frog = new Frog($this->loc(9.5));
		self::assertFalse($frog->onInteract($this->player(VanillaItems::FROG_SPAWN_EGG()), Vector3::zero()), "frogs have no baby");
		self::assertCount(1, $this->entitiesOf(Frog::class));
	}

	public function testSpawnEggOnVillagerZombieAndPiglin() : void{
		$villager = new VillagerV2($this->loc());
		$villager->setAge(0); //5% of new villagers are babies
		self::assertTrue($villager->onInteract($this->player(VanillaItems::VILLAGER_SPAWN_EGG()), Vector3::zero()), "no trading window");
		$babies = array_filter($this->entitiesOf(VillagerV2::class), fn(Entity $e) => $e instanceof VillagerV2 && $e->isBaby() && $e !== $villager);
		self::assertCount(1, $babies);
		foreach($babies as $baby){
			self::assertInstanceOf(VillagerV2::class, $baby);
			self::assertSame(VillagerV2::PROFESSION_UNEMPLOYED, $baby->getProfession());
		}

		$zombie = new Zombie($this->loc(6.5));
		self::assertTrue($zombie->onInteract($this->player(VanillaItems::ZOMBIE_SPAWN_EGG()), Vector3::zero()));
		self::assertCount(1, array_filter($this->entitiesOf(Zombie::class), fn(Entity $e) => $e instanceof Zombie && $e->isBaby()));

		$piglin = new Piglin($this->loc(10.5));
		self::assertTrue($piglin->onInteract($this->player(VanillaItems::PIGLIN_SPAWN_EGG()), Vector3::zero()));
		foreach($this->entitiesOf(Piglin::class) as $p){
			if($p instanceof Piglin && $p->isBaby()){
				self::assertTrue($p->getMainHandItem()->isNull(), "baby piglins hold nothing");
			}
		}
	}

	public function testBabyChanceOnSpawn() : void{
		mt_srand(12345);
		$counts = [];
		foreach([
			Cow::class => [2000, fn() => new Cow($this->loc())],
			Rabbit::class => [1000, fn() => new Rabbit($this->loc())],
			Horse::class => [1000, fn() => new Horse($this->loc())]
		] as $class => [$n, $create]){
			$babies = 0;
			for($i = 0; $i < $n; ++$i){
				$mob = $create();
				if(BabySpawning::rollSpawnBaby($mob)){
					++$babies;
					self::assertSame($mob->getBabyStartAge(), $mob->getAge());
				}
				$mob->close();
			}
			$counts[$class] = $babies / $n;
		}
		self::assertEqualsWithDelta(0.05, $counts[Cow::class], 0.02, "cows: 95/5");
		self::assertEqualsWithDelta(0.25, $counts[Rabbit::class], 0.04, "rabbits: 3/1");
		self::assertEqualsWithDelta(0.20, $counts[Horse::class], 0.04, "horses: 36/9");

		//already a baby or no baby chance: nothing changes
		$calf = new Cow($this->loc());
		$calf->setAge(-100);
		self::assertFalse(BabySpawning::rollSpawnBaby($calf));
		self::assertSame(-100, $calf->getAge());
		$frog = new Frog($this->loc(5.5));
		self::assertSame(0, $frog->getSpawnBabyChance());
	}

	public function testSpawnEggRollsTheBabyChance() : void{
		mt_srand(777);
		$babies = 0;
		for($i = 0; $i < 400; ++$i){
			$entity = VanillaItems::RABBIT_SPAWN_EGG()->spawnEntity($this->world, new Vector3(2.5, 1, 2.5), 0, 0);
			self::assertInstanceOf(Rabbit::class, $entity);
			if($entity->isBaby()){
				++$babies;
			}
			$entity->close();
		}
		self::assertEqualsWithDelta(100, $babies, 30, "25% of rabbit eggs hatch babies");
	}

	public function testBabyCollisionBoxes() : void{
		$turtle = new Turtle($this->loc());
		$turtle->setAge(Animal::BABY_AGE);
		self::assertEqualsWithDelta(0.64, $turtle->getSize()->getWidth(), 0.001);
		self::assertEqualsWithDelta(0.2133, $turtle->getSize()->getHeight(), 0.001);
		$turtle->setAge(0);
		self::assertEqualsWithDelta(1.2, $turtle->getSize()->getWidth(), 0.001, "adult box back");
		self::assertEqualsWithDelta(0.4, $turtle->getSize()->getHeight(), 0.001);

		foreach([
			[new Horse($this->loc()), 0.98, 1.12],
			[new Fox($this->loc()), 0.36, 0.42],
			[new Rabbit($this->loc()), 0.24, 0.4],
			[new Camel($this->loc()), 0.9495, 1.3995],
			[new VillagerV2($this->loc()), 0.49, 0.98],
		] as [$animal, $width, $height]){
			$animal->setAge($animal->getBabyStartAge());
			self::assertEqualsWithDelta($width, $animal->getSize()->getWidth(), 0.001, $animal->getName());
			self::assertEqualsWithDelta($height, $animal->getSize()->getHeight(), 0.001, $animal->getName());
		}

		$cow = new Cow($this->loc());
		$cow->setAge(Animal::BABY_AGE);
		self::assertEqualsWithDelta(0.45, $cow->getSize()->getWidth(), 0.001, "scaled adult box without a baby box");

		$zombie = new Zombie($this->loc(), CompoundTag::create());
		$zombie->setBaby(true);
		self::assertEqualsWithDelta(0.49, $zombie->getSize()->getWidth(), 0.001);
		self::assertEqualsWithDelta(0.98, $zombie->getSize()->getHeight(), 0.001);
		$zombie->setBaby(false);
		self::assertEqualsWithDelta(0.6, $zombie->getSize()->getWidth(), 0.001);

		$camel = new Camel($this->loc(6.5));
		$camel->setAge(Animal::BABY_AGE);
		$camel->setSitting(true);
		self::assertEqualsWithDelta(0.4248, $camel->getSize()->getHeight(), 0.001, "sitting baby camel 0.944 x 0.45");
		$camel->setSitting(false);
		self::assertEqualsWithDelta(1.3995, $camel->getSize()->getHeight(), 0.001);
	}

	public function testEntityCountDoesNotLeak() : void{
		$before = count($this->world->getEntities());
		$cow = new Cow($this->loc());
		$sheep = new Sheep($this->loc());
		self::assertFalse($sheep->onInteract($this->player(VanillaItems::COW_SPAWN_EGG()), Vector3::zero()));
		self::assertCount($before + 2, $this->world->getEntities(), "a refused egg creates nothing");
		$cow->close();
		$sheep->close();
	}
}
