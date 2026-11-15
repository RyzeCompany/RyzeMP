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

namespace pocketmine\entity\object;

use pocketmine\block\LightningRod;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Mooshroom;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\projectile\Trident;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\ItemEnchantmentTags;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function array_filter;
use function array_map;
use function array_values;

/**
 * Lightning bolts, what they do to mobs and blocks, natural strikes and Channeling, in an in-memory world (stone floor
 * at y=0, mobs at y=1).
 */
final class LightningTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @return Entity[]
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isFlaggedForDespawn() && !$e->isClosed()));
	}

	public function testBoltDamagesAndIgnitesOnce() : void{
		$this->difficulty = World::DIFFICULTY_EASY;
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->spawnToAll();
		$max = $cow->getMaxHealth();
		$bolt = LightningBolt::strike($this->loc(1.5, 1, 1.5));
		self::assertNotNull($bolt);
		$this->runTicks(2);
		self::assertSame($max - (int) LightningBolt::DAMAGE, (int) $cow->getHealth());
		self::assertTrue($cow->isOnFire());
		self::assertSame(EntityDamageEvent::CAUSE_LIGHTNING, $cow->getLastDamageCause()?->getCause());
		$cow->extinguish();
		$this->runTicks(40);
		self::assertSame($max - (int) LightningBolt::DAMAGE, (int) $cow->getHealth(), "struck once per bolt");
		self::assertTrue($bolt->isClosed(), "the bolt is gone after its flashes");
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(1, 1, 1)->getTypeId(), "no fire on Easy");
	}

	public function testFarEntitiesAreNotStruck() : void{
		$cow = new Cow($this->loc(10.5, 1, 10.5));
		$cow->spawnToAll();
		LightningBolt::strike($this->loc(0.5, 1, 0.5));
		$this->runTicks(2);
		self::assertSame($cow->getMaxHealth(), (int) $cow->getHealth());
	}

	public function testFireOnNormal() : void{
		$this->difficulty = World::DIFFICULTY_NORMAL;
		LightningBolt::strike($this->loc(0.5, 1, 0.5));
		$this->runTicks(1);
		self::assertSame(VanillaBlocks::FIRE()->getTypeId(), $this->get(0, 1, 0)->getTypeId());
	}

	public function testNoFireWithoutFireTick() : void{
		$this->difficulty = World::DIFFICULTY_NORMAL;
		$this->world->setGameRule(GameRules::DO_FIRE_TICK, false);
		LightningBolt::strike($this->loc(0.5, 1, 0.5));
		$this->runTicks(1);
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 1, 0)->getTypeId());
	}

	public function testVisualOnlyBoltDoesNothing() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->spawnToAll();
		LightningBolt::strike($this->loc(0.5, 1, 0.5), true);
		$this->runTicks(2);
		self::assertSame($cow->getMaxHealth(), (int) $cow->getHealth());
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 1, 0)->getTypeId());
	}

	public function testPigBecomesZombifiedPiglin() : void{
		$pig = new Pig($this->loc(0.5, 1, 0.5));
		$pig->spawnToAll();
		$pig->setNameTag("Bacon");
		$pig->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertTrue($pig->isFlaggedForDespawn());
		$zombified = $this->entitiesOf(ZombifiedPiglin::class);
		self::assertCount(1, $zombified);
		self::assertSame(ItemTypeIds::GOLDEN_SWORD, $zombified[0]->getMainHandItem()->getTypeId());
		self::assertSame("Bacon", $zombified[0]->getNameTag());
		self::assertTrue($zombified[0]->isPersistent());
	}

	public function testPigOnlyHurtOnPeaceful() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$pig = new Pig($this->loc(0.5, 1, 0.5));
		$pig->spawnToAll();
		$pig->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertFalse($pig->isFlaggedForDespawn());
		self::assertCount(0, $this->entitiesOf(ZombifiedPiglin::class));
		self::assertSame($pig->getMaxHealth() - (int) LightningBolt::DAMAGE, (int) $pig->getHealth());
	}

	public function testStrikeOnPigConvertsIt() : void{
		$pig = new Pig($this->loc(0.5, 1, 0.5));
		$pig->spawnToAll();
		LightningBolt::strike($this->loc(0.5, 1, 0.5));
		$this->runTicks(2);
		self::assertTrue($pig->isClosed() || $pig->isFlaggedForDespawn());
		self::assertCount(1, $this->entitiesOf(ZombifiedPiglin::class));
	}

	public function testVillagerBecomesWitch() : void{
		$villager = new VillagerV2($this->loc(0.5, 1, 0.5));
		$villager->spawnToAll();
		$villager->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertTrue($villager->isFlaggedForDespawn());
		$witches = $this->entitiesOf(Witch::class);
		self::assertCount(1, $witches);
		self::assertTrue($witches[0]->isPersistent());
	}

	public function testCreeperIsCharged() : void{
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$creeper->spawnToAll();
		self::assertFalse($creeper->isPowered());
		$creeper->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertTrue($creeper->isPowered());
		//Bedrock damage_sensor: lightning deals no damage to creepers
		self::assertSame($creeper->getMaxHealth(), (int) $creeper->getHealth(), "not hurt");
		self::assertFalse($creeper->isOnFire(), "not set on fire");
	}

	public function testMooshroomSwitchesColour() : void{
		$mooshroom = new Mooshroom($this->loc(0.5, 1, 0.5));
		$mooshroom->spawnToAll();
		self::assertSame(Mooshroom::VARIANT_RED, $mooshroom->getVariant());
		$mooshroom->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertSame(Mooshroom::VARIANT_BROWN, $mooshroom->getVariant());
		self::assertSame($mooshroom->getMaxHealth(), (int) $mooshroom->getHealth(), "not hurt");
		$mooshroom->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertSame(Mooshroom::VARIANT_RED, $mooshroom->getVariant());
	}

	public function testTurtleDiesAndDropsBowl() : void{
		$turtle = new Turtle($this->loc(0.5, 1, 0.5));
		$turtle->spawnToAll();
		$turtle->onStruckByLightning(new LightningBolt($this->loc(0.5, 1, 0.5)));
		self::assertFalse($turtle->isAlive());
		$drops = array_map(fn(Item $i) => $i->getTypeId(), $turtle->getDrops());
		self::assertContains(ItemTypeIds::BOWL, $drops);
	}

	public function testLightningRodIsPoweredAndAttractsStrikes() : void{
		$this->set(8, 1, 8, VanillaBlocks::LIGHTNING_ROD()->setFacing(Facing::UP));
		$target = $this->world->getWeather()->findLightningTarget(2, 2);
		self::assertNotNull($target);
		self::assertTrue($target->equals(new Vector3(8, 2, 8)), "aimed at the rod: " . $target);

		LightningBolt::strike($this->loc(8.5, 2, 8.5));
		$this->runTicks(1);
		$rod = $this->get(8, 1, 8);
		self::assertInstanceOf(LightningRod::class, $rod);
		self::assertTrue($rod->isPowered());
		$this->runTicks(LightningRod::STRUCK_POWER_TICKS + 2);
		$rod = $this->get(8, 1, 8);
		self::assertInstanceOf(LightningRod::class, $rod);
		self::assertFalse($rod->isPowered());

		//a rod under a roof doesn't count
		$this->set(8, 3, 8, VanillaBlocks::STONE());
		$target = $this->world->getWeather()->findLightningTarget(2, 2);
		self::assertNotNull($target);
		self::assertTrue($target->equals(new Vector3(2, 1, 2)), "top of the column: " . $target);
	}

	public function testStrikeCleansCopper() : void{
		$this->set(10, 0, 10, VanillaBlocks::COPPER()->setOxidation(CopperOxidation::OXIDIZED));
		LightningBolt::strike($this->loc(10.5, 1, 10.5));
		$this->runTicks(1);
		$copper = $this->get(10, 0, 10);
		self::assertInstanceOf(CopperMaterial::class, $copper);
		self::assertSame(CopperOxidation::NONE, $copper->getOxidation());

		$this->set(12, 0, 12, VanillaBlocks::COPPER()->setOxidation(CopperOxidation::OXIDIZED)->setWaxed(true));
		LightningBolt::strike($this->loc(12.5, 1, 12.5));
		$this->runTicks(1);
		$copper = $this->get(12, 0, 12);
		self::assertInstanceOf(CopperMaterial::class, $copper);
		self::assertSame(CopperOxidation::OXIDIZED, $copper->getOxidation(), "waxed copper keeps its oxidation");
	}

	public function testNaturalStrikesPreferExposedEntitiesAndNeedRain() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL; //no skeleton trap
		$weather = $this->world->getWeather();
		$cow = new Cow($this->loc(4.5, 1, 4.5));
		$cow->spawnToAll();
		$target = $weather->findLightningTarget(5, 5);
		self::assertNotNull($target);
		self::assertTrue($target->equals(new Vector3(4, 1, 4)), "aimed at the cow: " . $target);

		self::assertNull($weather->tryNaturalStrike(-10, -10), "no strike without a storm");
		$weather->setThunder(100000);
		self::assertNotNull($weather->tryNaturalStrike(-10, -10));
		for($y = -1; $y <= 8; ++$y){
			$this->world->setBiomeId(-12, $y, -12, BiomeIds::DESERT);
		}
		self::assertNull($weather->tryNaturalStrike(-12, -12), "deserts are dry");
	}

	public function testSkeletonTrapBecomesFourHorsemen() : void{
		$horse = new SkeletonHorse($this->loc(0.5, 1, 0.5));
		$horse->setTrap(true);
		$horse->spawnToAll();
		self::assertTrue($horse->isTrap());
		$horse->springTrap();
		self::assertFalse($horse->isTrap());
		$horses = $this->entitiesOf(SkeletonHorse::class);
		self::assertCount(SkeletonHorse::TRAP_HORSEMEN, $horses);
		foreach($horses as $h){
			$riders = $h->getPassengers();
			self::assertCount(1, $riders);
			self::assertInstanceOf(Skeleton::class, $riders[0]);
			self::assertSame(ItemTypeIds::IRON_HELMET, $riders[0]->getArmorInventory()->getHelmet()->getTypeId());
		}
		$bolts = $this->entitiesOf(LightningBolt::class);
		self::assertCount(1, $bolts);
		self::assertTrue($bolts[0]->isVisualOnly());
	}

	public function testChannelingTridentCallsLightning() : void{
		$this->difficulty = World::DIFFICULTY_EASY;
		$cow = new Cow($this->loc(6.5, 1, 6.5));
		$cow->spawnToAll();
		$item = VanillaItems::TRIDENT()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::CHANNELING()));
		$hit = function(Trident $trident) use ($cow) : void{
			//projectile collisions are protected: call the hit handler directly
			(fn(Entity $e, RayTraceResult $r) => $this->onHitEntity($e, $r))->call($trident, $cow, new RayTraceResult(new AxisAlignedBB(0, 0, 0, 1, 1, 1), Facing::UP, $cow->getPosition()));
		};

		$hit(new Trident($this->loc(6.5, 3, 6.5), $item, null));
		self::assertCount(0, $this->entitiesOf(LightningBolt::class), "no thunderstorm");

		$this->world->getWeather()->setThunder(100000);
		$hit(new Trident($this->loc(6.5, 3, 6.5), VanillaItems::TRIDENT(), null));
		self::assertCount(0, $this->entitiesOf(LightningBolt::class), "no Channeling");

		$hit(new Trident($this->loc(6.5, 3, 6.5), $item, null));
		self::assertCount(1, $this->entitiesOf(LightningBolt::class));
	}

	public function testChannelingIsATridentEnchantment() : void{
		self::assertContains(ItemEnchantmentTags::TRIDENT, VanillaItems::TRIDENT()->getEnchantmentTags());
		self::assertSame(1, VanillaEnchantments::CHANNELING()->getMaxLevel());
		self::assertContains(
			VanillaEnchantments::CHANNELING(),
			AvailableEnchantmentRegistry::getInstance()->getPrimaryEnchantmentsForItem(VanillaItems::TRIDENT())
		);
	}
}
