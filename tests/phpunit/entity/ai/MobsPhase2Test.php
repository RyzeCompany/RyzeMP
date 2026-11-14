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

use pocketmine\block\Beehive as BeehiveBlock;
use pocketmine\block\FrogSpawn;
use pocketmine\block\tile\Beehive as BeehiveTile;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Donkey;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\animal\Frog;
use pocketmine\entity\animal\Goat;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Mooshroom;
use pocketmine\entity\animal\Mule;
use pocketmine\entity\animal\Ocelot;
use pocketmine\entity\animal\Panda;
use pocketmine\entity\animal\Parrot;
use pocketmine\entity\animal\Rabbit;
use pocketmine\entity\animal\Sniffer;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\aquatic\Pufferfish;
use pocketmine\entity\aquatic\Salmon;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\aquatic\TropicalFish;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\MobsPhase2;
use pocketmine\entity\npc\TradeOffer;
use pocketmine\entity\npc\VillagerTrades;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\property\EntityPropertyRegistry;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\MobBucket;
use pocketmine\item\SpawnEgg;
use pocketmine\item\SuspiciousStewType;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function array_filter;
use function array_values;
use function count;
use function max;

final class MobsPhase2Test extends MobTestCase{

	private UuidInterface $playerUuid;

	protected function setUp() : void{
		parent::setUp();
		$this->playerUuid = Uuid::uuid4();
		//a pool of water: x 8..14, z 8..14, y 1..3, walled with stone
		for($x = 7; $x <= 15; ++$x){
			for($z = 7; $z <= 15; ++$z){
				for($y = 1; $y <= 4; ++$y){
					$wall = $x === 7 || $x === 15 || $z === 7 || $z === 15;
					if($wall){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
					}elseif($y <= 3){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
					}
				}
			}
		}
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function inWater() : Location{
		return $this->loc(11.5, 2, 11.5);
	}

	/**
	 * A player holding the item, in survival unless $creative.
	 */
	private function player(Item $held, bool $creative = false) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(!$creative);
		$player->method("getUniqueId")->willReturn($this->playerUuid);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(true);
		$player->method("isAlive")->willReturn(true);
		$player->method("getLocation")->willReturn($this->loc(0.5, 1, 3.5));
		$player->method("getPosition")->willReturn($this->loc(0.5, 1, 3.5));
		$player->method("getName")->willReturn("Tester");
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function interactWith(Mob $mob, Player $player) : bool{
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

	public function testEveryMobHasAWorkingSpawnEgg() : void{
		$eggs = [];
		foreach(VanillaItems::getAll() as $item){
			if($item instanceof SpawnEgg && ($id = $item->getEntityTypeId()) !== null){
				$eggs[$id] = $item;
			}
		}
		foreach(MobsPhase2::getMobs() as [$class, , ]){
			$id = $class::getNetworkTypeId();
			self::assertArrayHasKey($id, $eggs, "no spawn egg for $id");
			self::assertTrue(EntitySpawnRegistry::getInstance()->isRegistered($id));
			$entity = $eggs[$id]->spawnEntity($this->world, $this->inWater(), 0, 0);
			self::assertInstanceOf($class, $entity, "egg of $id");
			$entity->flagForDespawn();
		}
	}

	public function testEveryMobTicksAndSurvivesASaveRoundTrip() : void{
		$mobs = [];
		foreach(MobsPhase2::getMobs() as [$class, , $create]){
			$aquatic = $class === Cod::class || $class === Salmon::class || $class === Pufferfish::class || $class === TropicalFish::class ||
				$class === Tadpole::class || $class === Dolphin::class || $class === Nautilus::class;
			$entity = $create($aquatic ? $this->inWater() : $this->loc(-10.5 + count($mobs) % 10 * 2, 1, -10.5 + (int) (count($mobs) / 10) * 2), null);
			$entity->spawnToAll();
			$mobs[] = $entity;
		}
		$this->runTicks(100);
		foreach($mobs as $mob){
			if($mob->isClosed()){
				//hunted down by another mob (wolves eat rabbits...)
				continue;
			}
			$copy = EntityFactory::getInstance()->createFromData($this->world, $mob->saveNBT());
			self::assertInstanceOf($mob::class, $copy);
			$copy->flagForDespawn();
		}
	}

	public function testWolfTamingSittingAndBreeding() : void{
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		self::assertFalse($wolf->isTamed());
		self::assertSame(8, $wolf->getMaxHealth());
		$steak = VanillaItems::STEAK();
		self::assertTrue($wolf->isBreedingItem($steak));
		self::assertFalse($wolf->isBreedingItem(VanillaItems::RAW_FISH()), "wolves heal with fish but aren't bred with it");
		self::assertFalse($this->interactWith($wolf, $this->player($steak)), "untamed wolves don't fall in love");

		for($i = 0; $i < 100 && !$wolf->isTamed(); ++$i){
			$this->interactWith($wolf, $this->player(VanillaItems::BONE()->setCount(64)));
		}
		self::assertTrue($wolf->isTamed());
		self::assertTrue($wolf->getOwnerUuid()?->equals($this->playerUuid));
		self::assertTrue($wolf->isSitting(), "a wolf sits down when tamed");
		self::assertSame(40, $wolf->getMaxHealth());

		$this->interactWith($wolf, $this->player(VanillaItems::AIR()));
		self::assertFalse($wolf->isSitting(), "the owner makes it stand up");

		self::assertTrue($this->interactWith($wolf, $this->player($steak)));
		self::assertTrue($wolf->isInLove());

		$this->interactWith($wolf, $this->player(VanillaItems::DYE()->setColor(DyeColor::BLUE)));
		self::assertSame(DyeColor::BLUE, $wolf->getCollarColor());

		$copy = EntityFactory::getInstance()->createFromData($this->world, $wolf->saveNBT());
		self::assertInstanceOf(Wolf::class, $copy);
		self::assertTrue($copy->isTamed());
		self::assertSame(40, $copy->getMaxHealth());
	}

	public function testTamedWolfDefendsOwner() : void{
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		for($i = 0; $i < 100 && !$wolf->isTamed(); ++$i){
			$this->interactWith($wolf, $this->player(VanillaItems::BONE()));
		}
		self::assertTrue($wolf->isTamed());
		$wolf->setAttackTarget($this->player(VanillaItems::AIR()));
		self::assertNull($wolf->getAttackTarget(), "tamed wolves never target their owner");
	}

	public function testCatAndOcelot() : void{
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		self::assertEqualsWithDelta(0.8, $cat->getScale(), 0.001);
		for($i = 0; $i < 100 && !$cat->isTamed(); ++$i){
			$this->interactWith($cat, $this->player(VanillaItems::RAW_FISH()));
		}
		self::assertTrue($cat->isTamed());
		self::assertSame(20, $cat->getMaxHealth());
		self::assertGreaterThanOrEqual(0, $cat->getVariant());
		self::assertLessThanOrEqual(10, $cat->getVariant());

		$ocelot = new Ocelot($this->loc(3.5, 1, 0.5));
		self::assertFalse($this->interactWith($ocelot, $this->player(VanillaItems::WHEAT())));
		for($i = 0; $i < 100 && !$ocelot->isTrusting(); ++$i){
			$this->interactWith($ocelot, $this->player(VanillaItems::RAW_SALMON()));
		}
		self::assertTrue($ocelot->isTrusting());
	}

	public function testParrotTamingAndCookies() : void{
		$parrot = new Parrot($this->loc(0.5, 4, 0.5));
		self::assertFalse($parrot->hasGravity(), "parrots fly");
		self::assertFalse($parrot->isBreedingItem(VanillaItems::WHEAT_SEEDS()), "parrots aren't bred");
		for($i = 0; $i < 100 && !$parrot->isTamed(); ++$i){
			$this->interactWith($parrot, $this->player(VanillaItems::WHEAT_SEEDS()));
		}
		self::assertTrue($parrot->isTamed());
		self::assertTrue($parrot->hasGravity(), "a sitting parrot lands");
		$this->interactWith($parrot, $this->player(VanillaItems::COOKIE()));
		self::assertTrue($parrot->getEffects()->has(VanillaEffects::FATAL_POISON()));
	}

	public function testHorseTamingByTemper() : void{
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		self::assertGreaterThanOrEqual(15, $horse->getMaxHealth());
		self::assertLessThanOrEqual(30, $horse->getMaxHealth());
		self::assertGreaterThanOrEqual(0.1125, $horse->getMovementSpeed());
		self::assertLessThanOrEqual(0.3375, $horse->getMovementSpeed());
		//taming by riding is covered by RidingTest
		self::assertTrue($this->interactWith($horse, $this->player(VanillaItems::GOLDEN_CARROT())), "food raises the temper of a wild horse");
		self::assertSame(5, $horse->getTemper());
		$horse->setOwner($this->player(VanillaItems::AIR()));
		self::assertTrue($this->interactWith($horse, $this->player(VanillaItems::GOLDEN_CARROT())));
		self::assertTrue($horse->isInLove());

		$donkey = new Donkey($this->loc(2.5, 1, 0.5));
		$donkey->setOwner($this->player(VanillaItems::AIR()));
		$donkey->setInLove(Animal::LOVE_TICKS);
		self::assertTrue($horse->canMate($donkey));
		$baby = $horse->breed($donkey);
		self::assertInstanceOf(Mule::class, $baby);
		self::assertFalse($baby->isBreedingItem(VanillaItems::GOLDEN_CARROT()));
	}

	/**
	 * @return \Generator<string, array{\Closure(Location) : Animal, \Closure() : Item, bool}>
	 */
	public static function breedingFoodProvider() : \Generator{
		yield "cat cod" => [fn(Location $l) => new Cat($l), fn() => VanillaItems::RAW_FISH(), true];
		yield "cat wheat" => [fn(Location $l) => new Cat($l), fn() => VanillaItems::WHEAT(), false];
		yield "rabbit carrot" => [fn(Location $l) => new Rabbit($l), fn() => VanillaItems::CARROT(), true];
		yield "rabbit dandelion" => [fn(Location $l) => new Rabbit($l), fn() => VanillaBlocks::DANDELION()->asItem(), true];
		yield "goat wheat" => [fn(Location $l) => new Goat($l), fn() => VanillaItems::WHEAT(), true];
		yield "llama hay" => [fn(Location $l) => new Llama($l), fn() => VanillaBlocks::HAY_BALE()->asItem(), true];
		yield "llama wheat" => [fn(Location $l) => new Llama($l), fn() => VanillaItems::WHEAT(), false];
		yield "panda bamboo" => [fn(Location $l) => new Panda($l), fn() => VanillaBlocks::BAMBOO()->asItem(), true];
		yield "frog slime" => [fn(Location $l) => new Frog($l), fn() => VanillaItems::SLIMEBALL(), true];
		yield "turtle seagrass" => [fn(Location $l) => new Turtle($l), fn() => VanillaBlocks::SEAGRASS()->asItem(), true];
		yield "armadillo spider eye" => [fn(Location $l) => new Armadillo($l), fn() => VanillaItems::SPIDER_EYE(), true];
		yield "sniffer torchflower seeds" => [fn(Location $l) => new Sniffer($l), fn() => VanillaItems::TORCHFLOWER_SEEDS(), true];
		yield "bee poppy" => [fn(Location $l) => new Bee($l), fn() => VanillaBlocks::POPPY()->asItem(), true];
		yield "bee wither rose" => [fn(Location $l) => new Bee($l), fn() => VanillaBlocks::WITHER_ROSE()->asItem(), true];
		yield "bee open eyeblossom" => [fn(Location $l) => new Bee($l), fn() => VanillaBlocks::OPEN_EYEBLOSSOM()->asItem(), false];
		yield "bee golden dandelion" => [fn(Location $l) => new Bee($l), fn() => VanillaBlocks::GOLDEN_DANDELION()->asItem(), false];
		yield "axolotl fish bucket" => [fn(Location $l) => new Axolotl($l), fn() => VanillaItems::TROPICAL_FISH_BUCKET(), true];
		yield "mooshroom wheat" => [fn(Location $l) => new Mooshroom($l), fn() => VanillaItems::WHEAT(), true];
		yield "fox sweet berries" => [fn(Location $l) => new Fox($l), fn() => VanillaItems::SWEET_BERRIES(), true];
		yield "camel cactus" => [fn(Location $l) => new Camel($l), fn() => VanillaBlocks::CACTUS()->asItem(), true];
	}

	/**
	 * @dataProvider breedingFoodProvider
	 * @phpstan-param \Closure(Location) : Animal $animal
	 * @phpstan-param \Closure() : Item $item
	 */
	public function testBreedingFood(\Closure $animal, \Closure $item, bool $expected) : void{
		self::assertSame($expected, $animal($this->loc(0.5, 1, 0.5))->isBreedingItem($item()));
	}

	public function testVariants() : void{
		$fish = new TropicalFish($this->inWater());
		foreach(TropicalFish::PRESETS as [$base, $pattern, $mark, $shape]){
			$fish->setAppearance($shape, $mark, $base, $pattern);
			self::assertSame($shape, $fish->getShape());
			self::assertSame($mark, $fish->getPattern());
		}
		self::assertCount(22, TropicalFish::PRESETS);

		$sizes = [];
		for($i = 0; $i < 300; ++$i){
			$sizes[Salmon::randomSize()] = true;
		}
		self::assertCount(3, $sizes);
		$salmon = new Salmon($this->inWater());
		$salmon->setSizeVariant(Salmon::SIZE_LARGE);
		self::assertEqualsWithDelta(1.5, $salmon->getScale(), 0.001);

		//pandas: brown and weak only show when both genes match
		self::assertSame(Panda::GENE_NORMAL, Panda::personality(Panda::GENE_BROWN, Panda::GENE_LAZY));
		self::assertSame(Panda::GENE_BROWN, Panda::personality(Panda::GENE_BROWN, Panda::GENE_BROWN));
		self::assertSame(Panda::GENE_LAZY, Panda::personality(Panda::GENE_LAZY, Panda::GENE_WEAK));
		$weak = new Panda($this->loc(0.5, 1, 0.5));
		$weak->setGenes(Panda::GENE_WEAK, Panda::GENE_WEAK);
		self::assertSame(10, $weak->getMaxHealth());

		//biome variants: the test world is all ocean (biome 0)
		self::assertSame(Frog::VARIANT_TEMPERATE, (new Frog($this->loc(0.5, 1, 0.5)))->getVariant());
		self::assertSame(Wolf::VARIANT_PALE, (new Wolf($this->loc(0.5, 1, 0.5)))->getVariant());
		self::assertSame(Fox::VARIANT_RED, (new Fox($this->loc(0.5, 1, 0.5)))->getVariant());

		self::assertSame(SuspiciousStewType::TULIP, Mooshroom::stewTypeForFlower(VanillaBlocks::PINK_TULIP()->asItem()));
		self::assertNull(Mooshroom::stewTypeForFlower(VanillaItems::WHEAT()));

		$horse = new Horse($this->loc(0.5, 1, 0.5));
		self::assertLessThanOrEqual(6, $horse->getCoat());
		self::assertLessThanOrEqual(4, $horse->getMarkings());
		$llama = new Llama($this->loc(0.5, 1, 0.5));
		self::assertGreaterThanOrEqual(1, $llama->getStrength());
		self::assertLessThanOrEqual(5, $llama->getStrength());
	}

	public function testFishBucketRoundTrip() : void{
		$fish = new TropicalFish($this->inWater());
		$fish->setAppearance(1, 4, 9, 14);
		$player = $this->player(VanillaItems::WATER_BUCKET());
		self::assertTrue($this->interactWith($fish, $player), "a water bucket catches the fish");
		self::assertTrue($fish->isFlaggedForDespawn());
		$bucket = $player->getInventory()->getItemInHand();
		self::assertInstanceOf(MobBucket::class, $bucket);
		self::assertTrue($bucket->equals(VanillaItems::TROPICAL_FISH_BUCKET(), false, false));

		self::assertFalse($this->interactWith(new Cod($this->inWater()), $this->player(VanillaItems::BUCKET())), "an empty bucket doesn't catch fish (vanilla)");

		$released = $bucket->releaseMob($this->world, $this->inWater());
		self::assertInstanceOf(TropicalFish::class, $released);
		self::assertSame(1, $released->getShape());
		self::assertSame(4, $released->getPattern());
		self::assertSame(9, $released->getBaseColor());
		self::assertSame(14, $released->getPatternColor());
		self::assertTrue($released->isPersistent());

		foreach([
			[VanillaItems::COD_BUCKET(), Cod::class],
			[VanillaItems::SALMON_BUCKET(), Salmon::class],
			[VanillaItems::PUFFERFISH_BUCKET(), Pufferfish::class],
			[VanillaItems::AXOLOTL_BUCKET(), Axolotl::class],
			[VanillaItems::TADPOLE_BUCKET(), Tadpole::class],
		] as [$item, $class]){
			self::assertInstanceOf($class, $item->releaseMob($this->world, $this->inWater()));
		}

		$axolotl = new Axolotl($this->inWater());
		$axolotl->setVariant(Axolotl::VARIANT_BLUE);
		$copy = VanillaItems::AXOLOTL_BUCKET();
		$copy->setNamedTag($axolotl->createBucket()->getNamedTag());
		$back = $copy->releaseMob($this->world, $this->inWater());
		self::assertInstanceOf(Axolotl::class, $back);
		self::assertSame(Axolotl::VARIANT_BLUE, $back->getVariant());
	}

	public function testFishSwimsAndFlops() : void{
		$cod = new Cod($this->loc(9.5, 1.5, 9.5));
		$cod->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$cod->getNavigation()->moveTo(13.5, 2.5, 13.5, 1.0);
		$this->runTicks(80);
		self::assertLessThan(1.5, $cod->getPosition()->distance(new Vector3(13.5, 2.5, 13.5)), "the cod should have swum to the target");
		self::assertFalse($cod->hasGravity() && $cod->getPosition()->y < 1.0);

		$landed = new Cod($this->loc(0.5, 1, 0.5));
		$maxY = 1.0;
		for($i = 0; $i < 40; ++$i){
			$this->runTicks(1);
			$maxY = max($maxY, $landed->getPosition()->y);
		}
		self::assertGreaterThan(1.1, $maxY, "fish flop on land");
	}

	public function testFlyingMobStaysUp() : void{
		$bee = new Bee($this->loc(0.5, 8, 0.5));
		$bee->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$this->runTicks(60);
		self::assertGreaterThan(6, $bee->getPosition()->y, "bees don't fall");
		$bee->getNavigation()->moveTo(4.5, 5.5, 0.5, 1.0);
		$this->runTicks(80);
		self::assertLessThan(1.0, $bee->getPosition()->distance(new Vector3(4.5, 5.5, 0.5)));
	}

	public function testPufferfishPuffsAndPoisons() : void{
		$puffer = new Pufferfish($this->loc(11.5, 1.2, 11.5));
		$puffer->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		//axolotls make pufferfish puff up (other aquatic mobs don't); with its AI off it stays in place in the water, its
		//box a tenth of a block from the pufferfish's (within the sting's reach; overlapping boxes push each other apart)
		$axolotl = new Axolotl($this->loc(12.45, 1.2, 11.5));
		$axolotl->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$axolotl->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$this->runTicks(80);
		self::assertSame(Pufferfish::PUFF_FULL, $puffer->getPuffState());
		self::assertTrue($axolotl->getEffects()->has(VanillaEffects::POISON()));
		$axolotl->flagForDespawn();
		$this->runTicks(200);
		self::assertSame(Pufferfish::PUFF_NONE, $puffer->getPuffState());
	}

	public function testArmadilloRollsUp() : void{
		$armadillo = new Armadillo($this->loc(0.5, 1, 0.5));
		self::assertFalse($armadillo->isRolledUp());
		$this->runTicks(5); //only rolls up on the ground
		$cow = new Cow($this->loc(2.5, 1, 0.5));
		$armadillo->attack(new EntityDamageByEntityEvent($cow, $armadillo, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertTrue($armadillo->isRolledUp());
		$index = EntityPropertyRegistry::getInstance()->getIndex(Armadillo::getNetworkTypeId(), "minecraft:armadillo_state");
		self::assertNotNull($index);
		self::assertSame(1, $armadillo->getNetworkSyncedProperties()->getIntProperties()[$index]);
		$health = $armadillo->getHealth();
		$armadillo->setHealth($armadillo->getMaxHealth());
		$this->runTicks(20);
		$ev = new EntityDamageEvent($armadillo, EntityDamageEvent::CAUSE_FALL, 5);
		$armadillo->attack($ev);
		self::assertEqualsWithDelta(2.0, $ev->getFinalDamage(), 0.001, "rolled up: (5 - 1) / 2");
		self::assertGreaterThan(0, $health);
	}

	public function testEntityPropertiesAreSynced() : void{
		$packets = EntityPropertyRegistry::getInstance()->getSyncPackets();
		self::assertGreaterThanOrEqual(6, count($packets));
		$bee = new Bee($this->loc(0.5, 3, 0.5));
		$bee->setHasNectar(true);
		$index = EntityPropertyRegistry::getInstance()->getIndex(Bee::getNetworkTypeId(), "minecraft:has_nectar");
		self::assertSame(1, $bee->getNetworkSyncedProperties()->getIntProperties()[$index ?? -1] ?? null);
		self::assertSame([], (new Rabbit($this->loc(0.5, 1, 0.5)))->getNetworkSyncedProperties()->getIntProperties());
	}

	public function testTadpoleGrowsIntoFrog() : void{
		$tadpole = new Tadpole($this->inWater());
		$tadpole->setAge(Tadpole::GROW_UP_TICKS - 2);
		$this->runTicks(3);
		self::assertTrue($tadpole->isFlaggedForDespawn() || $tadpole->isClosed());
		self::assertCount(1, $this->entitiesOf(Frog::class));
	}

	public function testFrogspawnHatches() : void{
		$this->world->setBlockAt(11, 4, 11, VanillaBlocks::AIR(), false);
		$this->world->setBlockAt(11, 4, 11, VanillaBlocks::FROG_SPAWN());
		$spawn = $this->world->getBlockAt(11, 4, 11);
		self::assertInstanceOf(FrogSpawn::class, $spawn);
		$spawn->hatch();
		$tadpoles = $this->entitiesOf(Tadpole::class);
		self::assertGreaterThanOrEqual(2, count($tadpoles));
		self::assertLessThanOrEqual(6, count($tadpoles));
	}

	public function testBeeVisitsItsHive() : void{
		$this->world->setBlockAt(0, 1, 5, VanillaBlocks::BEEHIVE());
		self::assertInstanceOf(BeehiveTile::class, $this->world->getTileAt(0, 1, 5));
		$bee = new Bee($this->loc(0.5, 2, 4.5));
		$bee->setHivePosition(new Vector3(0, 1, 5));
		$bee->setHasNectar(true);
		self::assertTrue($bee->tryEnterHive());
		self::assertTrue($bee->isFlaggedForDespawn());
		$tile = $this->world->getTileAt(0, 1, 5);
		self::assertInstanceOf(BeehiveTile::class, $tile);
		self::assertSame(1, $tile->getOccupantCount());
		$this->runTicks(BeehiveBlock::TICKS_WITH_NECTAR + 40);
		self::assertSame(0, $tile->getOccupantCount());
		$hive = $this->world->getBlockAt(0, 1, 5);
		self::assertInstanceOf(BeehiveBlock::class, $hive);
		self::assertSame(1, $hive->getHoneyLevel(), "a bee coming back with nectar makes honey");
		$bees = $this->entitiesOf(Bee::class);
		self::assertCount(1, $bees);
		self::assertFalse($bees[0]->hasNectar());
	}

	public function testVillagerTradesAndLevels() : void{
		self::assertSame([], VillagerTrades::getUnknownItemNames());
		foreach(VillagerTrades::getTradingProfessions() as $profession){
			for($tier = 0; $tier <= VillagerV2::MAX_TIER; ++$tier){
				self::assertNotEmpty(VillagerTrades::createOffers($profession, $tier), "profession $profession, level $tier");
			}
		}

		$villager = new VillagerV2($this->loc(0.5, 1, 0.5));
		$villager->setAge(0);
		$villager->setProfession(VillagerV2::PROFESSION_FARMER);
		self::assertSame(0, $villager->getTradeTier());
		$offers = $villager->getTradeOffers();
		self::assertNotEmpty($offers);
		$villager->addTradeExperience(10);
		self::assertSame(1, $villager->getTradeTier());
		self::assertGreaterThan(count($offers), count($villager->getTradeOffers()));
		$villager->addTradeExperience(1000);
		self::assertSame(VillagerV2::MAX_TIER, $villager->getTradeTier());

		$copy = EntityFactory::getInstance()->createFromData($this->world, $villager->saveNBT());
		self::assertInstanceOf(VillagerV2::class, $copy);
		self::assertSame(VillagerV2::PROFESSION_FARMER, $copy->getProfession());
		self::assertSame(VillagerV2::MAX_TIER, $copy->getTradeTier());
		self::assertCount(count($villager->getTradeOffers()), $copy->getTradeOffers());

		$offer = $offers[0];
		$offer->setUses(3);
		$back = TradeOffer::fromNbt($offer->toNbt());
		self::assertTrue($back->getBuyA()->equalsExact($offer->getBuyA()));
		self::assertTrue($back->getSell()->equalsExact($offer->getSell()));
		self::assertSame(3, $back->getUses());
		self::assertNotSame($offer->getNetworkId(), $back->getNetworkId());

		$trader = new WanderingTrader($this->loc(3.5, 1, 0.5));
		self::assertCount(9, $trader->getTradeOffers());
	}

	public function testUnemployedAndBabyVillagersDontTrade() : void{
		$villager = new VillagerV2($this->loc(0.5, 1, 0.5));
		$villager->setAge(0);
		$villager->onGround = true; //never ticked: villagers only trade on the ground (or sleeping)
		$villager->setProfession(VillagerV2::PROFESSION_NITWIT);
		self::assertFalse($villager->canTradeWith($this->player(VanillaItems::AIR())));
		$villager->setProfession(VillagerV2::PROFESSION_LIBRARIAN);
		self::assertTrue($villager->canTradeWith($this->player(VanillaItems::AIR())));
		$villager->setAge(Animal::BABY_AGE);
		self::assertFalse($villager->canTradeWith($this->player(VanillaItems::AIR())));
	}

	public function testMooshroomShearsIntoCow() : void{
		$mooshroom = new Mooshroom($this->loc(0.5, 1, 0.5));
		$mooshroom->setVariant(Mooshroom::VARIANT_BROWN);
		$cow = $mooshroom->shear();
		self::assertInstanceOf(Cow::class, $cow);
		self::assertNotInstanceOf(Mooshroom::class, $cow);
		self::assertTrue($mooshroom->isFlaggedForDespawn());
	}

	public function testGoatHornsAndMilk() : void{
		$goat = new Goat($this->loc(0.5, 1, 0.5));
		$goat->setScreamer(true);
		self::assertSame(2, $goat->getHornCount());
		self::assertTrue(Goat::breaksHorn(VanillaBlocks::STONE()));
		self::assertFalse(Goat::breaksHorn(VanillaBlocks::DIRT()));
		$player = $this->player(VanillaItems::BUCKET());
		self::assertTrue($this->interactWith($goat, $player));
		self::assertTrue($player->getInventory()->getItemInHand()->equals(VanillaItems::MILK_BUCKET()));
	}

	public function testIronGolemHitsHard() : void{
		$golem = new IronGolem($this->loc(0.5, 1, 0.5));
		$cow = new Cow($this->loc(1.5, 1, 0.5));
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$golem->attackMelee($cow, 1.0);
		self::assertLessThanOrEqual(993.0, $cow->getHealth(), "7-21 damage");
		self::assertGreaterThanOrEqual(979.0, $cow->getHealth());
	}

	public function testDifficultyDoesNotRemovePassiveMobs() : void{
		$wolf = new Wolf($this->loc(0.5, 1, 0.5));
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$this->runTicks(21);
		self::assertFalse($wolf->isClosed());
		self::assertFalse($wolf->isFlaggedForDespawn(), "passive and neutral mobs stay in peaceful");
	}
}
