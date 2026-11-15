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

use pocketmine\block\utils\DyeColor;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function count;
use function mt_srand;

/**
 * Wolves, cats and ocelots: wolf armor, begging, anger on target, healing by anyone, puppies' colours, leash rules,
 * wild cats fleeing players, cat damage and fall damage, cat coats by spawn reason, ocelot twins, sitting cats
 * breeding, cat sound variants.
 */
final class WolvesCatsTest extends MobTestCase{
	private int $nextPlayerId = 7100000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
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
	 * A mocked survival player standing at the location, holding the item.
	 */
	private function player(Location $location, Item $held) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturnCallback(fn() => $location->add(0, 1.62, 0));
		$player->method("getBoundingBox")->willReturnCallback(fn() => new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		$player->method("getId")->willReturn(++$this->nextPlayerId);
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		return $player;
	}

	/**
	 * Makes the players the players of the world (what the mobs' player searches look at).
	 */
	private function setPlayers(Player ...$players) : void{
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, $players);
	}

	private function useOn(Mob $mob, Player $player) : bool{
		return $mob->onInteract($player, new Vector3(0, 0, 0));
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
	 * @phpstan-param class-string<Goal> $class
	 */
	private static function findGoal(Mob $mob, string $class) : ?Goal{
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($mob->getGoalSelector());
		foreach($goals as $entry){
			if($entry->goal instanceof $class){
				return $entry->goal;
			}
		}
		return null;
	}

	/**
	 * @phpstan-param class-string<Goal> $class
	 */
	private function runUntilRunning(Mob $mob, string $class, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			foreach($mob->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof $class){
					return true;
				}
			}
			$this->runTicks(1);
		}
		return false;
	}

	private static function fallDamage(Living $mob, float $distance) : float{
		$damage = (new \ReflectionMethod($mob, "calculateFallDamage"))->invoke($mob, $distance);
		self::assertIsNumeric($damage);
		return (float) $damage;
	}

	private function tamedWolf(Player $owner) : Wolf{
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		$wolf->setOwner($owner);
		$wolf->setSitting(false);
		$wolf->spawnToAll();
		return $wolf;
	}

	// -------------------------------------------------------------------------------------------------------------
	// Wolf armor
	// -------------------------------------------------------------------------------------------------------------

	public function testWolfArmorEquipAbsorbShearAndRepair() : void{
		$owner = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::WOLF_ARMOR());
		$wolf = $this->tamedWolf($owner);
		self::assertSame(40, $wolf->getMaxHealth());

		//a stranger can't put it on
		$stranger = $this->player($this->loc(1.5, 1, 2.5), VanillaItems::WOLF_ARMOR());
		$this->useOn($wolf, $stranger);
		self::assertNull($wolf->getWolfArmor());

		self::assertTrue($this->useOn($wolf, $owner));
		$armor = $wolf->getWolfArmor();
		self::assertNotNull($armor);
		self::assertSame(ItemTypeIds::WOLF_ARMOR, $armor->getTypeId());
		self::assertTrue($owner->getInventory()->getItemInHand()->isNull(), "the armor was used");
		self::assertFalse($wolf->isSitting(), "putting armor on doesn't make it sit");

		//a zombie hit goes to the armor
		$zombie = new Zombie($this->loc(1.5, 1, 0.5));
		$health = $wolf->getHealth();
		$wolf->attack(new EntityDamageByEntityEvent($zombie, $wolf, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3));
		self::assertSame($health, $wolf->getHealth(), "the armor took the hit");
		$armor = $wolf->getWolfArmor();
		self::assertInstanceOf(Durable::class, $armor);
		self::assertSame(3, $armor->getDamage());

		//drowning goes through
		(new \ReflectionProperty(Living::class, "attackTime"))->setValue($wolf, 0);
		(new \ReflectionProperty(Living::class, "noDamageTicks"))->setValue($wolf, 0);
		$wolf->attack(new EntityDamageEvent($wolf, EntityDamageEvent::CAUSE_DROWNING, 2));
		self::assertSame($health - 2, $wolf->getHealth(), "drowning bypasses wolf armor");

		//so do the wither effect and thorns
		(new \ReflectionProperty(Living::class, "attackTime"))->setValue($wolf, 0);
		(new \ReflectionProperty(Living::class, "noDamageTicks"))->setValue($wolf, 0);
		$wolf->attack(new EntityDamageEvent($wolf, EntityDamageEvent::CAUSE_WITHER, 1));
		self::assertSame($health - 3, $wolf->getHealth(), "wither bypasses wolf armor");
		(new \ReflectionProperty(Living::class, "attackTime"))->setValue($wolf, 0);
		(new \ReflectionProperty(Living::class, "noDamageTicks"))->setValue($wolf, 0);
		$wolf->attack(new EntityDamageByEntityEvent($zombie, $wolf, EntityDamageEvent::CAUSE_THORNS, 1));
		self::assertSame($health - 4, $wolf->getHealth(), "thorns bypasses wolf armor");
		self::assertSame(3, self::durable($wolf->getWolfArmor())->getDamage(), "the armor took none of these");

		//saved with the wolf
		$copy = new Wolf($this->loc(0.5, 1, 0.5), $wolf->saveNBT());
		$copied = $copy->getWolfArmor();
		self::assertInstanceOf(Durable::class, $copied);
		self::assertSame(3, $copied->getDamage());
		$copy->close();

		//an armadillo scute repairs it, on a sitting wolf only
		$owner->getInventory()->setItemInHand(VanillaItems::ARMADILLO_SCUTE()->setCount(2));
		$wolf->setSitting(false);
		$this->useOn($wolf, $owner);
		self::assertSame(3, self::durable($wolf->getWolfArmor())->getDamage(), "not sitting: no repair");
		self::assertSame(2, $owner->getInventory()->getItemInHand()->getCount());
		$wolf->setSitting(true);
		self::assertTrue($this->useOn($wolf, $owner));
		self::assertSame(0, self::durable($wolf->getWolfArmor())->getDamage(), "repaired by up to 8");
		self::assertSame(1, $owner->getInventory()->getItemInHand()->getCount());
		self::assertTrue($wolf->isSitting());

		//shears take it off
		$owner->getInventory()->setItemInHand(VanillaItems::SHEARS());
		self::assertTrue($this->useOn($wolf, $owner));
		self::assertNull($wolf->getWolfArmor());
		$dropped = array_filter($this->entitiesOf(ItemEntity::class), fn(ItemEntity $e) => $e->getItem()->getTypeId() === ItemTypeIds::WOLF_ARMOR);
		self::assertCount(1, $dropped, "the armor is dropped");
		self::assertSame(1, self::durable($owner->getInventory()->getItemInHand())->getDamage(), "the shears are used");
		$wolf->close();
		$zombie->close();
	}

	private static function durable(?Item $item) : Durable{
		self::assertInstanceOf(Durable::class, $item);
		return $item;
	}

	public function testWolfArmorBreaksAndOnlyOnTamedAdults() : void{
		$owner = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::WOLF_ARMOR());
		$wolf = $this->tamedWolf($owner);
		$armor = VanillaItems::WOLF_ARMOR();
		$armor->setDamage($armor->getMaxDurability() - 2);
		$wolf->setWolfArmor($armor);
		$zombie = new Zombie($this->loc(1.5, 1, 0.5));
		$health = $wolf->getHealth();
		$wolf->attack(new EntityDamageByEntityEvent($zombie, $wolf, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
		self::assertNull($wolf->getWolfArmor(), "broken");
		self::assertSame($health, $wolf->getHealth(), "the breaking armor still took the whole hit");
		$wolf->close();
		$zombie->close();

		//wild wolves and puppies can't wear it
		$wild = new Wolf($this->loc(0.5, 1, 0.5));
		$this->useOn($wild, $owner);
		self::assertNull($wild->getWolfArmor());
		$puppy = $this->tamedWolf($owner);
		$puppy->setAge(Animal::BABY_AGE);
		$this->useOn($puppy, $owner);
		self::assertNull($puppy->getWolfArmor());
		$wild->close();
		$puppy->close();
	}

	public function testWolfArmorDropsOnDeath() : void{
		$owner = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::AIR());
		$wolf = $this->tamedWolf($owner);
		$wolf->setWolfArmor(VanillaItems::WOLF_ARMOR());
		$types = [];
		foreach($wolf->getDrops() as $drop){
			$types[] = $drop->getTypeId();
		}
		self::assertContains(ItemTypeIds::WOLF_ARMOR, $types);
		$wolf->close();
	}

	// -------------------------------------------------------------------------------------------------------------
	// Wolf behaviour
	// -------------------------------------------------------------------------------------------------------------

	public function testWolfBegsForBones() : void{
		$player = $this->player($this->loc(0.5, 1, 4.5), VanillaItems::BONE());
		$this->setPlayers($player);
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		$wolf->spawnToAll();
		$begging = false;
		for($i = 0; $i < 60 && !$begging; ++$i){
			$this->runTicks(1);
			$begging = $wolf->isInterested();
		}
		self::assertTrue($begging, "INTERESTED while a player holds a bone nearby");

		$player->getInventory()->setItemInHand(VanillaItems::STICK());
		for($i = 0; $i < 60 && $wolf->isInterested(); ++$i){
			$this->runTicks(1);
		}
		self::assertFalse($wolf->isInterested(), "stops when the player no longer holds it");
		self::assertTrue(Wolf::isBegItem(VanillaItems::RAW_BEEF()));
		self::assertFalse(Wolf::isBegItem(VanillaItems::RAW_FISH()), "fish isn't begged for");
		$this->setPlayers();
		$wolf->close();
	}

	public function testWildWolfGetsAngryOnTargetAndThePackJoins() : void{
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		$packmate = new Wolf($this->loc(5.5, 1, 0.5));
		$far = new Wolf($this->loc(-23.5, 1, 0.5));
		$sheep = new Sheep($this->loc(2.5, 1, 2.5));
		self::assertTrue($wolf->canBeLeashed());
		$wolf->setAttackTarget($sheep);
		self::assertTrue($wolf->isAngry(), "on_target_acquired: become_angry");
		self::assertFalse($wolf->canBeLeashed(), "angry wolves lose wolf_leashable");
		self::assertTrue($packmate->isAngry());
		self::assertSame($sheep, $packmate->getAttackTarget(), "broadcast_anger within 20 blocks");
		self::assertFalse($far->isAngry());

		//an angry wolf can't be tamed
		$player = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::BONE()->setCount(64));
		for($i = 0; $i < 20; ++$i){
			$this->useOn($wolf, $player);
		}
		self::assertFalse($wolf->isTamed());

		//tamed wolves don't get angry
		$tamed = $this->tamedWolf($player);
		$tamed->setAttackTarget($sheep);
		self::assertFalse($tamed->isAngry());
		foreach([$wolf, $packmate, $far, $sheep, $tamed] as $e){
			$e->close();
		}
	}

	public function testAnyPlayerHealsAWolf() : void{
		$owner = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::AIR());
		$stranger = $this->player($this->loc(1.5, 1, 2.5), VanillaItems::RAW_BEEF()->setCount(4));
		$tamed = $this->tamedWolf($owner);
		$tamed->setHealth(10);
		self::assertTrue($this->useOn($tamed, $stranger));
		self::assertSame(16.0, $tamed->getHealth(), "raw beef heals 6");
		self::assertFalse($tamed->isSitting(), "a stranger doesn't order it to sit");

		$wild = new Wolf($this->loc(3.5, 1, 0.5));
		$wild->setHealth(2);
		self::assertTrue($this->useOn($wild, $stranger));
		self::assertSame(8.0, $wild->getHealth());
		self::assertSame(2, $stranger->getInventory()->getItemInHand()->getCount());
		$tamed->close();
		$wild->close();
	}

	public function testPuppyCollarAndVariant() : void{
		self::assertSame(DyeColor::ORANGE, Wolf::mixCollarColors(DyeColor::RED, DyeColor::YELLOW));
		self::assertSame(DyeColor::PURPLE, Wolf::mixCollarColors(DyeColor::RED, DyeColor::BLUE));
		self::assertSame(DyeColor::GREEN, Wolf::mixCollarColors(DyeColor::GREEN, DyeColor::GREEN));
		$mixed = Wolf::mixCollarColors(DyeColor::BROWN, DyeColor::LIME);
		self::assertTrue($mixed === DyeColor::BROWN || $mixed === DyeColor::LIME, "no recipe: a parent's colour");

		$owner = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::AIR());
		$a = $this->tamedWolf($owner);
		$b = $this->tamedWolf($owner);
		$a->setVariant(Wolf::VARIANT_PALE);
		$b->setVariant(Wolf::VARIANT_SNOWY);
		$a->setCollarColor(DyeColor::BLUE);
		$b->setCollarColor(DyeColor::WHITE);
		$variants = [];
		for($i = 0; $i < 40; ++$i){
			$puppy = (new \ReflectionMethod($a, "createBaby"))->invoke($a, $b, $this->loc(0.5, 1, 0.5));
			self::assertInstanceOf(Wolf::class, $puppy);
			self::assertSame(DyeColor::LIGHT_BLUE, $puppy->getCollarColor());
			$variants[$puppy->getVariant()] = true;
			$puppy->close();
		}
		self::assertCount(2, $variants, "the variant of a random parent");
		$a->close();
		$b->close();
	}

	// -------------------------------------------------------------------------------------------------------------
	// Leads
	// -------------------------------------------------------------------------------------------------------------

	public function testLeashRules() : void{
		$turtle = new Turtle($this->loc(0.5, 1, 0.5));
		$panda = new Panda($this->loc(2.5, 1, 0.5));
		$husk = new CamelHusk($this->loc(5.5, 1, 0.5));
		self::assertFalse($turtle->canBeLeashed());
		self::assertFalse($panda->canBeLeashed());
		self::assertTrue($husk->canBeLeashed(), "camel_husk_with_no_hostile_rider");
		self::assertTrue((new Cat($this->loc(8.5, 1, 0.5)))->canBeLeashed());

		$player = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::LEAD());
		$this->useOn($turtle, $player);
		self::assertFalse($turtle->isLeashed());
		foreach($this->entitiesOf(Mob::class) as $mob){
			$mob->close();
		}
	}

	public function testAngryWolfDropsItsLead() : void{
		$player = $this->player($this->loc(0.5, 1, 2.5), VanillaItems::LEAD());
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		$wolf->spawnToAll();
		self::assertTrue($this->useOn($wolf, $player));
		self::assertTrue($wolf->isLeashed());
		$sheep = new Sheep($this->loc(2.5, 1, 2.5));
		$wolf->setAttackTarget($sheep);
		self::assertFalse($wolf->isLeashed(), "become_angry removes wolf_leashable");
		$wolf->close();
		$sheep->close();
	}

	// -------------------------------------------------------------------------------------------------------------
	// Cats and ocelots
	// -------------------------------------------------------------------------------------------------------------

	public function testWildCatFleesPlayers() : void{
		$player = $this->player($this->loc(0.5, 1, 4.5), VanillaItems::AIR());
		$this->setPlayers($player);
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$cat->spawnToAll();
		self::assertTrue($this->runUntilRunning($cat, AvoidEntityGoal::class, 60), "wild cats avoid players within 10 blocks");
		$cat->close();

		$tamed = new Cat($this->loc(0.5, 1, 0.5));
		$tamed->setOwner($player);
		$tamed->setSitting(false);
		$tamed->spawnToAll();
		self::assertFalse($this->runUntilRunning($tamed, AvoidEntityGoal::class, 60), "tamed cats don't");
		$tamed->close();
		$this->setPlayers();
	}

	public function testCatDamageAndFallDamage() : void{
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$melee = self::findGoal($cat, MeleeAttackGoal::class);
		self::assertNotNull($melee);
		self::assertSame(4.0, (new \ReflectionProperty(MeleeAttackGoal::class, "damage"))->getValue($melee), "cat.json attack_damage 4");

		self::assertSame(0.0, self::fallDamage($cat, 20));
		$ocelot = new Ocelot($this->loc(2.5, 1, 0.5));
		self::assertSame(0.0, self::fallDamage($ocelot, 20));
		$frog = new Frog($this->loc(4.5, 1, 0.5));
		self::assertSame(2.0, self::fallDamage($frog, 10), "7 - 5");
		self::assertSame(0.0, self::fallDamage($frog, 7));
		$cow = new Cow($this->loc(6.5, 1, 0.5));
		self::assertSame(7.0, self::fallDamage($cow, 10), "control");
		foreach([$cat, $ocelot, $frog, $cow] as $e){
			$e->close();
		}
	}

	public function testCatCoats() : void{
		$black = 0;
		$villageBlack = 0;
		for($i = 0; $i < 220; ++$i){
			$cat = new Cat($this->loc(0.5, 1, 0.5));
			if($cat->getVariant() === Cat::VARIANT_BLACK){
				++$black;
			}
			$cat->close();
			$village = new Cat($this->loc(0.5, 1, 0.5));
			$village->setSpawnReason(SpawnReason::VILLAGE_CAT);
			if($village->getVariant() === Cat::VARIANT_BLACK){
				++$villageBlack;
			}
			$village->close();
		}
		self::assertGreaterThan(0, $black, "entity_spawned includes the all black coat");
		self::assertSame(0, $villageBlack, "spawn_from_village doesn't");

		//the witch hut cat: an all black adult
		$hut = new Cat($this->loc(0.5, 1, 0.5));
		$hut->setAge(Animal::BABY_AGE);
		$hut->setSpawnReason(SpawnReason::STRUCTURE);
		self::assertSame(Cat::VARIANT_BLACK, $hut->getVariant());
		self::assertFalse($hut->isBaby());
		//saved black cats stay black
		$copy = new Cat($this->loc(0.5, 1, 0.5), $hut->saveNBT());
		self::assertSame(Cat::VARIANT_BLACK, $copy->getVariant());
		$hut->close();
		$copy->close();
	}

	public function testCatSoundVariants() : void{
		$seen = [];
		for($i = 0; $i < 40; ++$i){
			$cat = new Cat($this->loc(0.5, 1, 0.5));
			$seen[$cat->getActorProperty("minecraft:sound_variant")] = true;
			$cat->close();
		}
		self::assertCount(2, $seen, "default and royal");

		$kitten = new Cat($this->loc(0.5, 1, 0.5));
		$kitten->setAge(Animal::BABY_AGE);
		$kitten->setSpawnReason(SpawnReason::BREEDING);
		self::assertSame(0, $kitten->getActorProperty("minecraft:sound_variant"), "kittens: default");
		$copy = new Cat($this->loc(0.5, 1, 0.5), $kitten->saveNBT());
		self::assertSame(0, $copy->getActorProperty("minecraft:sound_variant"), "saved");
		$copy->close();
		$grown = [];
		for($j = 0; $j < 30; ++$j){
			$kitten->setAge(Animal::BABY_AGE);
			$kitten->setAge(0);
			$grown[$kitten->getActorProperty("minecraft:sound_variant")] = true;
		}
		self::assertCount(2, $grown, "randomized when growing up");
		$kitten->close();
	}

	public function testSittingCatsBreed() : void{
		$owner = $this->player($this->loc(10.5, 1, 10.5), VanillaItems::AIR());
		$a = new Cat($this->loc(0.5, 1, 0.5));
		$b = new Cat($this->loc(2.5, 1, 0.5));
		foreach([$a, $b] as $cat){
			$cat->setOwner($owner);
			$cat->setSitting(true);
			$cat->setInLove(600);
			$cat->spawnToAll();
		}
		$before = count($this->entitiesOf(Cat::class));
		for($i = 0; $i < 300 && count($this->entitiesOf(Cat::class)) === $before; ++$i){
			$this->runTicks(1);
		}
		$cats = $this->entitiesOf(Cat::class);
		self::assertCount($before + 1, $cats, "breedable allow_sitting");
		foreach($cats as $cat){
			if($cat->isBaby()){
				self::assertSame(0, $cat->getActorProperty("minecraft:sound_variant"));
			}
			$cat->close();
		}
	}

	public function testOcelotTwins() : void{
		$adults = 0;
		for($i = 0; $i < 140; ++$i){
			$ocelot = new Ocelot($this->loc(0.5 + ($i % 10) * 2, 1, 0.5 + (int) ($i / 10)));
			$ocelot->setSpawnReason(SpawnReason::NATURAL);
			$ocelot->spawnToAll();
			++$adults;
		}
		//loaded or plugin-made ocelots never get twins
		$plugin = new Ocelot($this->loc(-10.5, 1, -10.5));
		$plugin->spawnToAll();
		$this->runTicks(1);
		$babies = array_filter($this->entitiesOf(Ocelot::class), fn(Ocelot $o) => $o->isBaby());
		self::assertSame(0, count($babies) % 2, "two at a time");
		self::assertGreaterThan(10, count($babies), "1 in 7 of 140 adults: about 20 pairs");
		self::assertLessThan(80, count($babies));
		foreach($babies as $baby){
			self::assertTrue($baby->isTrusting(), "entity_born: trusting kittens");
		}
		foreach($this->entitiesOf(Ocelot::class) as $ocelot){
			$ocelot->close();
		}
		self::assertSame(140, $adults);
	}
}
