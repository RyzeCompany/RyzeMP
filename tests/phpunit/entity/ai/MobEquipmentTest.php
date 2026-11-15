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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\MobEquipment;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Armor;
use pocketmine\item\Bow;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\loot\bedrock\BedrockEquipmentTables;
use pocketmine\world\format\LightArray;
use pocketmine\world\RegionalDifficulty;
use pocketmine\world\World;
use function count;
use function mt_srand;

final class MobEquipmentTest extends MobTestCase{

	/** World time with the highest regional difficulty without inhabited time (day 63, full moon). */
	private const LATE_FULL_MOON = World::TIME_FULL * 64 + World::TIME_NOON;

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
	}

	private function loc(float $x, float $z) : Location{
		return new Location($x, 1, $z, $this->world, 0, 0);
	}

	/**
	 * Open sky over the test floor (test chunks have no light computed).
	 */
	private function openSky() : void{
		foreach($this->world->getLoadedChunks() as $chunk){
			$chunk->getSubChunk(0)->setBlockSkyLightArray(LightArray::fill(15));
			$chunk->setLightPopulated();
		}
		$this->world->setTime(World::TIME_NOON);
		$this->runTicks(2);
	}

	public function testRegionalDifficultyFollowsJava() : void{
		self::assertSame(0.0, RegionalDifficulty::calculate(World::DIFFICULTY_PEACEFUL, 10_000_000, 0, 1.0));
		//a new world: 0.75 x difficulty
		self::assertEqualsWithDelta(0.75, RegionalDifficulty::calculate(World::DIFFICULTY_EASY, 0, 0, 1.0), 1e-9);
		self::assertEqualsWithDelta(1.5, RegionalDifficulty::calculate(World::DIFFICULTY_NORMAL, 0, 0, 1.0), 1e-9);
		self::assertEqualsWithDelta(2.25, RegionalDifficulty::calculate(World::DIFFICULTY_HARD, 0, 0, 1.0), 1e-9);
		//everything at its maximum: Normal 4.0, Hard 6.75
		self::assertEqualsWithDelta(4.0, RegionalDifficulty::calculate(World::DIFFICULTY_NORMAL, 2_000_000, 4_000_000, 1.0), 1e-9);
		self::assertEqualsWithDelta(6.75, RegionalDifficulty::calculate(World::DIFFICULTY_HARD, 2_000_000, 4_000_000, 1.0), 1e-9);
		//the moon part never exceeds the world age part
		self::assertEqualsWithDelta(1.5, RegionalDifficulty::calculate(World::DIFFICULTY_NORMAL, 72000, 0, 1.0), 1e-9);

		self::assertSame(0.0, RegionalDifficulty::getSpecialMultiplier(1.99));
		self::assertSame(0.5, RegionalDifficulty::getSpecialMultiplier(3.0));
		self::assertSame(1.0, RegionalDifficulty::getSpecialMultiplier(4.5));

		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setTime(self::LATE_FULL_MOON);
		//3 x (0.75 + 0.25 + 0.25) = 3.75
		self::assertEqualsWithDelta(0.875, RegionalDifficulty::getSpecialMultiplierAt($this->world, $this->loc(0, 0)), 1e-9);
	}

	public function testEveryEquipmentTableItemExists() : void{
		foreach(["zombie_equipment", "zombie_rider_equipment", "skeleton_gear", "drowned_equipment", "drowned_ranged_equipment",
			"drowned_rider_equipment", "piglin_gear_melee", "piglin_gear_ranged", "pillager_gear", "vindicator_gear",
			"zombie_pigman_gear", "armor_set_leather", "armor_set_copper", "armor_set_gold", "armor_set_chain",
			"armor_set_iron", "armor_set_diamond"] as $table){
			self::assertTrue(BedrockEquipmentTables::has($table), $table);
		}
		//the armor sets always give at least a helmet
		foreach(["leather", "copper", "gold", "chain", "iron", "diamond"] as $tier){
			$items = BedrockEquipmentTables::roll("armor_set_" . $tier, World::DIFFICULTY_HARD, 1.0);
			self::assertGreaterThan(0, count($items), $tier);
			self::assertInstanceOf(Armor::class, $items[0]);
		}
	}

	public function testZombieEquipmentDistribution() : void{
		$weapons = [];
		$armored = 0;
		$runs = 4000;
		for($i = 0; $i < $runs; ++$i){
			$hasArmor = false;
			foreach(BedrockEquipmentTables::roll("zombie_equipment", World::DIFFICULTY_HARD, 1.0) as $item){
				if($item instanceof Armor){
					$hasArmor = true;
				}else{
					$weapons[$item->getTypeId()] = ($weapons[$item->getTypeId()] ?? 0) + 1;
				}
			}
			$armored += $hasArmor ? 1 : 0;
		}
		//weapon 5%: spear, sword, shovel 1:2:3; armor 15%
		$total = ($weapons[ItemTypeIds::IRON_SPEAR] ?? 0) + ($weapons[ItemTypeIds::IRON_SWORD] ?? 0) + ($weapons[ItemTypeIds::IRON_SHOVEL] ?? 0);
		self::assertEqualsWithDelta(0.05, $total / $runs, 0.015);
		self::assertGreaterThan($weapons[ItemTypeIds::IRON_SPEAR] ?? 0, $weapons[ItemTypeIds::IRON_SHOVEL] ?? 0);
		self::assertEqualsWithDelta(0.15, $armored / $runs, 0.025);

		//no regional difficulty, no equipment
		for($i = 0; $i < 500; ++$i){
			self::assertSame([], BedrockEquipmentTables::roll("zombie_equipment", World::DIFFICULTY_HARD, 0.0));
		}
	}

	public function testPiglinArmorOnlyOnNormalAndHard() : void{
		$helmets = 0;
		$chestplates = 0;
		for($i = 0; $i < 3000; ++$i){
			foreach(BedrockEquipmentTables::roll("piglin_gear_melee", World::DIFFICULTY_EASY, 1.0) as $item){
				self::assertNotInstanceOf(Armor::class, $item, "no piglin armor on Easy");
			}
			foreach(BedrockEquipmentTables::roll("piglin_gear_ranged", World::DIFFICULTY_HARD, 1.0) as $item){
				if($item->getTypeId() === ItemTypeIds::GOLDEN_HELMET){
					++$helmets;
				}elseif($item->getTypeId() === ItemTypeIds::GOLDEN_CHESTPLATE){
					++$chestplates;
				}
			}
		}
		//armor 15% x regional difficulty; then the chestplate 60% of the time on Hard
		self::assertEqualsWithDelta(0.15, $helmets / 3000, 0.03);
		self::assertEqualsWithDelta(0.6, $chestplates / $helmets, 0.12);
	}

	public function testRandomGearEnchantmentScalesWithRegionalDifficulty() : void{
		$enchanted = 0;
		for($i = 0; $i < 2000; ++$i){
			$items = BedrockEquipmentTables::roll("skeleton_gear", World::DIFFICULTY_NORMAL, 1.0);
			self::assertInstanceOf(Bow::class, $items[0]);
			$enchanted += $items[0]->hasEnchantments() ? 1 : 0;
			self::assertFalse(BedrockEquipmentTables::roll("pillager_gear", World::DIFFICULTY_NORMAL, 0.0)[0]->hasEnchantments());
		}
		self::assertEqualsWithDelta(0.10, $enchanted / 2000, 0.03);
	}

	public function testSpawnedMobsWearAndKeepTheirEquipment() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setTime(self::LATE_FULL_MOON);
		$armored = null;
		for($i = 0; $i < 200 && $armored === null; ++$i){
			$zombie = new Zombie($this->loc(0.5, 0.5));
			foreach($zombie->getArmorInventory()->getContents() as $item){
				$armored = $zombie;
			}
			if($armored === null){
				$zombie->flagForDespawn();
			}
		}
		self::assertNotNull($armored, "some zombies spawn with armor on Hard");
		$copy = EntityFactory::getInstance()->createFromData($this->world, $armored->saveNBT());
		self::assertInstanceOf(Zombie::class, $copy);
		foreach($armored->getArmorInventory()->getContents() as $slot => $item){
			self::assertTrue($item->equalsExact($copy->getArmorInventory()->getItem($slot)));
		}
		self::assertTrue($armored->getMainHandItem()->equalsExact($copy->getMainHandItem()));

		$skeleton = new Skeleton($this->loc(2.5, 0.5));
		self::assertSame(ItemTypeIds::BOW, $skeleton->getMainHandItem()->getTypeId());
		self::assertTrue((new ZombieVillager($this->loc(4.5, 0.5)))->getMainHandItem()->isNull());
		$pigman = new ZombifiedPiglin($this->loc(6.5, 0.5));
		self::assertContains($pigman->getMainHandItem()->getTypeId(), [ItemTypeIds::GOLDEN_SWORD, ItemTypeIds::GOLDEN_SPEAR]);
	}

	public function testOldSavedSkeletonsGetABow() : void{
		$skeleton = new Skeleton($this->loc(0.5, 0.5));
		$nbt = $skeleton->saveNBT();
		$nbt->removeTag("Mainhand");
		$copy = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(Skeleton::class, $copy);
		self::assertSame(ItemTypeIds::BOW, $copy->getMainHandItem()->getTypeId());
	}

	public function testRidersGetTheirEquipment() : void{
		$this->difficulty = World::DIFFICULTY_HARD;
		$horse = new ZombieHorse($this->loc(0.5, 0.5));
		$horse->spawnToAll();
		$rider = Jockeys::spawnZombieHorseRider($horse);
		self::assertNotNull($rider);
		self::assertSame(ItemTypeIds::IRON_SPEAR, $rider->getMainHandItem()->getTypeId());

		$husk = new Husk($this->loc(3.5, 0.5));
		$husk->setCamelHuskRider(true);
		self::assertSame(ItemTypeIds::IRON_SPEAR, $husk->getMainHandItem()->getTypeId());

		$drowned = new Drowned($this->loc(6.5, 0.5));
		$drowned->spawnToAll();
		self::assertNotNull(Jockeys::spawnDrownedRider($drowned));
		$trident = $drowned->getMainHandItem();
		self::assertSame(ItemTypeIds::TRIDENT, $trident->getTypeId());
		self::assertFalse($trident->hasEnchantments());
		self::assertTrue($drowned->getOffHandItem()->isNull(), "riders have no nautilus shell");
	}

	public function testDrownedModeSwitcher() : void{
		$drowned = new Drowned($this->loc(0.5, 0.5));
		$target = new Zombie($this->loc(0.5, 8.5));
		self::assertTrue($drowned->isInRangedMode($target));
		$target->teleport($this->loc(0.5, 2.5)->asVector3());
		self::assertFalse($drowned->isInRangedMode($target), "melee within 3 blocks");
		$target->teleport($this->loc(0.5, 5.0)->asVector3());
		self::assertFalse($drowned->isInRangedMode($target), "still melee up to 5 blocks");
		$target->teleport($this->loc(0.5, 6.0)->asVector3());
		self::assertTrue($drowned->isInRangedMode($target), "ranged again beyond 5 blocks");
	}

	public function testDrownedSpawnEquipmentRates() : void{
		$tridents = 0;
		$rods = 0;
		$runs = 3000;
		for($i = 0; $i < $runs; ++$i){
			$drowned = new Drowned($this->loc(0.5, 0.5));
			$id = $drowned->getMainHandItem()->getTypeId();
			$tridents += $id === ItemTypeIds::TRIDENT ? 1 : 0;
			$rods += $id === ItemTypeIds::FISHING_ROD ? 1 : 0;
			$drowned->flagForDespawn();
		}
		self::assertEqualsWithDelta(0.0625, $tridents / $runs, 0.015);
		self::assertEqualsWithDelta(0.0375 * 0.9375, $rods / $runs, 0.012);
	}

	public function testBowEnchantmentsApplyToSkeletonArrows() : void{
		$this->difficulty = World::DIFFICULTY_NORMAL;
		$target = new Zombie($this->loc(0.5, 10.5));
		$target->spawnToAll();
		$damage = [];
		foreach([null, 5] as $power){
			$skeleton = new Skeleton($this->loc(0.5, 0.5));
			$bow = VanillaItems::BOW();
			if($power !== null){
				$bow->addEnchantment(new EnchantmentInstance(VanillaEnchantments::POWER(), $power));
				$bow->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FLAME(), 1));
			}
			$skeleton->setMainHandItem($bow);
			$skeleton->spawnToAll();
			$skeleton->shootArrow($target, 10.0);
			$arrow = null;
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof Arrow && $entity->getOwningEntity() === $skeleton){
					$arrow = $entity;
				}
			}
			self::assertNotNull($arrow);
			$damage[] = $arrow->getBaseDamage();
			self::assertSame($power !== null, $arrow->isOnFire());
		}
		//Power V: +3
		self::assertGreaterThan($damage[0] + 1.5, $damage[1]);
	}

	/**
	 * Runs the ticks and returns, for each entity, whether it was on fire at some point.
	 *
	 * @param Living[] $entities
	 * @phpstan-param list<Living> $entities
	 * @return bool[]
	 * @phpstan-return array<int, bool>
	 */
	private function watchFire(array $entities, int $ticks) : array{
		$burnt = [];
		for($i = 0; $i < $ticks; ++$i){
			$this->runTicks(1);
			foreach($entities as $k => $entity){
				$burnt[$k] = ($burnt[$k] ?? false) || $entity->isOnFire();
			}
		}
		return $burnt;
	}

	public function testHelmetBlocksTheSunAndWearsOut() : void{
		$this->openSky();
		$bare = new Zombie($this->loc(0.5, 0.5));
		$bare->getArmorInventory()->clearAll();
		$bare->setMainHandItem(VanillaItems::AIR());
		$bare->spawnToAll();
		$helmeted = new Zombie($this->loc(4.5, 0.5));
		$helmeted->getArmorInventory()->clearAll();
		$helmeted->getArmorInventory()->setHelmet(VanillaItems::LEATHER_CAP());
		$helmeted->spawnToAll();
		foreach([$bare, $helmeted] as $zombie){
			$zombie->getGoalSelector()->setFlagDisabled(goal\Goal::FLAG_MOVE | goal\Goal::FLAG_LOOK | goal\Goal::FLAG_JUMP, true);
		}
		self::assertTrue($bare->isInDaylight());
		[$bareBurnt, $helmetedBurnt] = $this->watchFire([$bare, $helmeted], 400);
		self::assertTrue($bareBurnt, "a zombie without helmet burns");
		self::assertFalse($helmetedBurnt, "a helmet keeps the zombie from burning");
		$helmet = $helmeted->getArmorInventory()->getHelmet();
		self::assertInstanceOf(Durable::class, $helmet);
		self::assertGreaterThan(0, $helmet->getDamage(), "the helmet wears out in the sun");

		//a carved pumpkin protects forever
		$pumpkin = new Zombie($this->loc(8.5, 0.5));
		$pumpkin->getArmorInventory()->clearAll();
		$pumpkin->getArmorInventory()->setHelmet(VanillaBlocks::CARVED_PUMPKIN()->asItem());
		$pumpkin->spawnToAll();
		$this->runTicks(200);
		self::assertFalse($pumpkin->isOnFire());
	}

	public function testZombieHorseBurnsWithoutHorseArmor() : void{
		$this->openSky();
		$bare = new ZombieHorse($this->loc(0.5, 0.5));
		$bare->spawnToAll();
		$armored = new ZombieHorse($this->loc(6.5, 0.5));
		$armored->setHorseArmor(VanillaItems::IRON_HORSE_ARMOR());
		$armored->spawnToAll();
		[$bareBurnt, $armoredBurnt] = $this->watchFire([$bare, $armored], 400);
		self::assertTrue($bareBurnt, "zombie horses burn in daylight");
		self::assertFalse($armoredBurnt, "horse armor protects them");
	}

	public function testEquipmentDrops() : void{
		$zombie = new Zombie($this->loc(0.5, 0.5));
		$zombie->setMainHandItem(VanillaItems::DIAMOND_SWORD());
		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$getDrops = \Closure::bind(fn(bool $always = false) : array => $this->getEquipmentDrops($always), $zombie, Zombie::class);
		$guarantee = \Closure::bind(fn(int $slot) => $this->setGuaranteedDrop($slot), $zombie, Zombie::class);
		//not killed by a player: spawned equipment never drops
		for($i = 0; $i < 200; ++$i){
			self::assertSame([], $getDrops());
		}
		//guaranteed slots always drop, intact
		$guarantee(MobEquipment::SLOT_MAINHAND);
		/** @var Item[] $drops */
		$drops = $getDrops();
		self::assertCount(1, $drops);
		self::assertSame(ItemTypeIds::DIAMOND_SWORD, $drops[0]->getTypeId());
		self::assertInstanceOf(Durable::class, $drops[0]);
		self::assertSame(0, $drops[0]->getDamage());
		//and stay guaranteed after saving
		$copy = EntityFactory::getInstance()->createFromData($this->world, $zombie->saveNBT());
		self::assertInstanceOf(Zombie::class, $copy);
		$copyDrops = \Closure::bind(fn() : array => $this->getEquipmentDrops(), $copy, Zombie::class);
		self::assertCount(1, $copyDrops());
	}

	public function testPlayerKillsDropSkeletonBows() : void{
		$skeleton = new Skeleton($this->loc(0.5, 0.5));
		$skeleton->getArmorInventory()->clearAll();
		//a player hit it just now (no Looting)
		(\Closure::bind(function() : void{ $this->lastHurtByPlayerTick = $this->server->getTick(); }, $skeleton, Skeleton::class))();
		self::assertTrue($skeleton->wasRecentlyHitByPlayer());
		$bows = 0;
		$runs = 4000;
		for($i = 0; $i < $runs; ++$i){
			foreach($skeleton->getDrops() as $drop){
				if($drop->getTypeId() === ItemTypeIds::BOW){
					++$bows;
					self::assertInstanceOf(Durable::class, $drop);
					self::assertGreaterThan(0, $drop->getDamage(), "dropped spawn equipment is worn");
				}
			}
		}
		self::assertEqualsWithDelta(0.085, $bows / $runs, 0.015);
	}

	public function testWornArmorReducesDamage() : void{
		$health = [];
		foreach([false, true] as $armored){
			$zombie = new Zombie($this->loc($armored ? 4.5 : 0.5, 0.5));
			$zombie->getArmorInventory()->clearAll();
			if($armored){
				$zombie->getArmorInventory()->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
			}
			$zombie->spawnToAll();
			$zombie->attack(new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10));
			$health[] = $zombie->getHealth();
		}
		self::assertGreaterThan($health[0], $health[1]);
	}

	public function testDroppedEquipmentIsDamaged() : void{
		$sword = MobEquipment::randomizeDropDamage(VanillaItems::IRON_SWORD());
		self::assertInstanceOf(Durable::class, $sword);
		self::assertGreaterThan(0, $sword->getDamage());
		self::assertLessThan($sword->getMaxDurability(), $sword->getDamage());
	}

	public function testPiglinSpawnGearFollowsTheTables() : void{
		$this->difficulty = World::DIFFICULTY_EASY;
		$this->world->setTime(self::LATE_FULL_MOON);
		for($i = 0; $i < 300; ++$i){
			$piglin = new Piglin($this->loc(0.5, 0.5));
			self::assertSame(!$piglin->isBaby(), !$piglin->getMainHandItem()->isNull());
			foreach($piglin->getArmorInventory()->getContents() as $item){
				self::fail("piglins get no armor on Easy");
			}
			$piglin->flagForDespawn();
		}
	}
}
