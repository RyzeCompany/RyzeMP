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

use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\MobEquipment;
use pocketmine\entity\monster\MobShareables;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Pillager;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function mt_srand;

final class MobPickupTest extends MobTestCase{

	/** World time with the highest regional difficulty without inhabited time (day 63, full moon). */
	private const LATE_FULL_MOON = World::TIME_FULL * 64 + World::TIME_NOON;

	/** Whether the test listener cancels {@link EntityItemPickupEvent} */
	private bool $cancelPickups = false;

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
	}

	private function loc(float $x, float $z) : Location{
		return new Location($x, 1, $z, $this->world, 0, 0);
	}

	/**
	 * A mob made by $make which picks up loot, without any equipment, not persistent (but kept from despawning in the
	 * test world, which has no players, by a name tag set without making it persistent).
	 *
	 * @phpstan-template T of Zombie|Skeleton|WitherSkeleton
	 * @phpstan-param \Closure(Location, CompoundTag) : T $make
	 * @phpstan-return T
	 */
	private function looter(\Closure $make, float $x, float $z) : Zombie|Skeleton|WitherSkeleton{
		$nbt = CompoundTag::create()
			->setByte(MobEquipment::TAG_CAN_PICK_UP_LOOT, 1)
			->setByte(Mob::TAG_PERSISTENT, 0);
		$mob = $make($this->loc($x, $z), $nbt);
		(new \ReflectionProperty(Entity::class, "nameTag"))->setValue($mob, "looter");
		$mob->setMainHandItem(VanillaItems::AIR());
		$mob->setOffHandItem(VanillaItems::AIR());
		$mob->getArmorInventory()->clearAll();
		return $mob;
	}

	private function dropOnGround(Item $item, float $x, float $z, int $delay = 0) : ItemEntity{
		$entity = new ItemEntity($this->loc($x, $z), $item);
		$entity->setPickupDelay($delay);
		$entity->spawnToAll();
		return $entity;
	}

	/**
	 * @return ItemEntity[]
	 * @phpstan-return list<ItemEntity>
	 */
	private function itemsOnGround() : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	/**
	 * @return int[]
	 */
	private static function typeIds(Item ...$items) : array{
		return array_map(fn(Item $i) => $i->getTypeId(), $items);
	}

	public function testShareablesPriorities() : void{
		$zombie = MobShareables::zombie();
		self::assertTrue($zombie->isBetter(VanillaItems::DIAMOND_SWORD(), VanillaItems::IRON_SWORD()));
		self::assertFalse($zombie->isBetter(VanillaItems::STONE_SWORD(), VanillaItems::IRON_SWORD()));
		self::assertFalse($zombie->isBetter(VanillaItems::IRON_SWORD(), VanillaItems::IRON_SWORD()));
		self::assertTrue($zombie->isBetter(VanillaItems::WOODEN_SWORD(), VanillaItems::IRON_SHOVEL()), "a listed item beats an unlisted one");
		self::assertTrue($zombie->isBetter(VanillaItems::IRON_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS())), VanillaItems::IRON_SWORD()), "enchanted beats plain");
		self::assertTrue($zombie->isBetter(VanillaItems::IRON_HELMET(), VanillaItems::LEATHER_CAP()));
		self::assertTrue($zombie->isBetter(VanillaItems::LEATHER_CAP(), VanillaBlocks::CARVED_PUMPKIN()->asItem()));
		self::assertSame(8, $zombie->getPriority(VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::WITHER_SKELETON)->asItem()));
		self::assertNull($zombie->getPriority(VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::CREEPER)->asItem()));
		self::assertSame(MobEquipment::SLOT_HEAD, $zombie->getSlotFor(VanillaBlocks::CARVED_PUMPKIN()->asItem()));
		self::assertSame(MobEquipment::SLOT_MAINHAND, $zombie->getSlotFor(VanillaItems::TOTEM()), "unlisted items go to the main hand");
		self::assertTrue($zombie->isExcluded(VanillaItems::GLOW_INK_SAC()));
		self::assertFalse($zombie->isExcluded(VanillaItems::IRON_SPEAR()));

		$drowned = MobShareables::drowned();
		self::assertTrue($drowned->isBetter(VanillaItems::TRIDENT(), VanillaItems::NETHERITE_SWORD()), "drowned prefer tridents");
		self::assertFalse($drowned->isBetter(VanillaItems::NETHERITE_SWORD(), VanillaItems::TRIDENT()));
		self::assertTrue($drowned->isExcluded(VanillaItems::IRON_SPEAR()));
		self::assertSame(MobEquipment::SLOT_OFFHAND, $drowned->getSlotFor(VanillaItems::NAUTILUS_SHELL()));

		$skeleton = MobShareables::skeleton();
		self::assertTrue($skeleton->isExcluded(VanillaItems::DIAMOND_SPEAR()));
		self::assertTrue($skeleton->isBetter(VanillaItems::WOODEN_SWORD(), VanillaItems::BOW()), "skeletons take a sword for their bow");
		self::assertFalse(MobShareables::bogged()->isBetter(VanillaItems::WOODEN_SWORD(), VanillaItems::BOW()), "bogged: bow = wooden sword");

		$wither = MobShareables::witherSkeleton();
		self::assertFalse($wither->canPickUpAnyItem);
		self::assertNull($wither->getPriority(VanillaBlocks::DIRT()->asItem()));
		self::assertSame(7, $wither->getPriority(VanillaItems::NETHERITE_PICKAXE()));
		self::assertTrue($wither->isBetter(VanillaItems::IRON_SWORD(), VanillaItems::STONE_SWORD()));

		$piglin = MobShareables::piglin();
		self::assertFalse($piglin->isBetter(VanillaItems::DIAMOND_HELMET(), VanillaItems::GOLDEN_HELMET()), "golden armor is the best for piglins");
		self::assertFalse($piglin->isBetter(VanillaItems::NETHERITE_SWORD(), VanillaItems::CROSSBOW()));
		self::assertTrue($piglin->isBetter(VanillaItems::DIAMOND_CHESTPLATE(), VanillaItems::ELYTRA()));
		self::assertSame(MobEquipment::SLOT_OFFHAND, $piglin->getSlotFor(VanillaItems::SHIELD()));

		self::assertSame(MobEquipment::SLOT_CHEST, MobEquipment::getSlotFor(VanillaItems::OMINOUS_BANNER()));
	}

	public function testZombiePicksUpASwordWhichAlwaysDrops() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		self::assertFalse($zombie->isPersistent());
		$this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 2.5, 0.5);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::DIAMOND_SWORD, $zombie->getMainHandItem()->getTypeId(), "it walked to the sword and took it");
		self::assertCount(0, $this->itemsOnGround());
		self::assertTrue($zombie->isPersistent(), "picking up makes it persistent");
		self::assertContains(ItemTypeIds::DIAMOND_SWORD, self::typeIds(...$zombie->getDrops()), "picked items always drop, intact");

		//saved and loaded
		$copy = EntityFactory::getInstance()->createFromData($this->world, $zombie->saveNBT());
		self::assertInstanceOf(Zombie::class, $copy);
		self::assertContains(ItemTypeIds::DIAMOND_SWORD, self::typeIds(...$copy->getDrops()));
		$copy->flagForDespawn();
	}

	public function testBetterItemsReplaceWorseOnesWhichAreDropped() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$zombie->setMainHandItem(VanillaItems::IRON_SWORD());
		$this->dropOnGround(VanillaItems::STONE_SWORD(), 1.5, 0.5);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::IRON_SWORD, $zombie->getMainHandItem()->getTypeId(), "a worse sword is left alone");
		self::assertCount(1, $this->itemsOnGround());

		$this->dropOnGround(VanillaItems::NETHERITE_SWORD(), $zombie->getPosition()->x - 1, $zombie->getPosition()->z);
		$this->dropOnGround(VanillaItems::IRON_HELMET(), $zombie->getPosition()->x, $zombie->getPosition()->z + 1);
		$this->runTicks(80);
		self::assertSame(ItemTypeIds::NETHERITE_SWORD, $zombie->getMainHandItem()->getTypeId());
		self::assertSame(ItemTypeIds::IRON_HELMET, $zombie->getArmorInventory()->getHelmet()->getTypeId(), "armor is worn");
		$onGround = self::typeIds(...array_map(fn(ItemEntity $e) => $e->getItem(), $this->itemsOnGround()));
		self::assertContains(ItemTypeIds::IRON_SWORD, $onGround, "the replaced sword is dropped");
		self::assertContains(ItemTypeIds::STONE_SWORD, $onGround);
	}

	public function testAnyItemOnlyIntoAnEmptyHand() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$this->dropOnGround(VanillaItems::GLOW_INK_SAC(), 1.5, 0.5);
		$this->runTicks(30);
		self::assertTrue($zombie->getMainHandItem()->isNull(), "zombies never take glow ink sacs");
		$this->dropOnGround(VanillaItems::ROTTEN_FLESH()->setCount(5), $zombie->getPosition()->x, $zombie->getPosition()->z + 1);
		$this->runTicks(40);
		$held = $zombie->getMainHandItem();
		self::assertSame(ItemTypeIds::ROTTEN_FLESH, $held->getTypeId());
		self::assertSame(1, $held->getCount(), "singular_pickup: one of the stack");
		self::assertCount(2, $this->itemsOnGround());
	}

	public function testZombifiedPiglinTakesWholeStacks() : void{
		$pigman = $this->looter(fn(Location $l, CompoundTag $n) => new ZombifiedPiglin($l, $n), 0.5, 0.5);
		$this->dropOnGround(VanillaItems::GOLD_NUGGET()->setCount(7), 1.5, 0.5);
		$this->runTicks(40);
		self::assertSame(7, $pigman->getMainHandItem()->getCount());
		self::assertCount(0, $this->itemsOnGround());
	}

	public function testSkeletonIgnoresSpearsAndSwapsItsBowForASword() : void{
		$skeleton = $this->looter(fn(Location $l, CompoundTag $n) => new Skeleton($l, $n), 0.5, 0.5);
		$skeleton->setMainHandItem(VanillaItems::BOW());
		$this->dropOnGround(VanillaItems::NETHERITE_SPEAR(), 1.5, 0.5);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::BOW, $skeleton->getMainHandItem()->getTypeId(), "skeletons never take spears");
		$this->dropOnGround(VanillaItems::STONE_SWORD(), $skeleton->getPosition()->x, $skeleton->getPosition()->z - 1);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::STONE_SWORD, $skeleton->getMainHandItem()->getTypeId());
		self::assertContains(ItemTypeIds::BOW, self::typeIds(...array_map(fn(ItemEntity $e) => $e->getItem(), $this->itemsOnGround())));
	}

	public function testDrownedPrefersTridents() : void{
		$drowned = $this->looter(fn(Location $l, CompoundTag $n) => new Drowned($l, $n), 0.5, 0.5);
		$drowned->setMainHandItem(VanillaItems::DIAMOND_SWORD());
		$this->dropOnGround(VanillaItems::TRIDENT(), 1.5, 0.5);
		$this->runTicks(40);
		self::assertTrue($drowned->hasTrident());
		$this->dropOnGround(VanillaItems::NAUTILUS_SHELL(), $drowned->getPosition()->x, $drowned->getPosition()->z + 1);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::NAUTILUS_SHELL, $drowned->getOffHandItem()->getTypeId());
		self::assertTrue($drowned->hasTrident());
	}

	public function testWitherSkeletonSwapsStoneForIronSword() : void{
		$wither = $this->looter(fn(Location $l, CompoundTag $n) => new WitherSkeleton($l, $n), 0.5, 0.5);
		$wither->setMainHandItem(VanillaItems::STONE_SWORD());
		$this->dropOnGround(VanillaBlocks::DIRT()->asItem(), 1.0, 1.5);
		$this->dropOnGround(VanillaItems::IRON_SWORD(), 1.5, 0.5);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::IRON_SWORD, $wither->getMainHandItem()->getTypeId());
		$onGround = self::typeIds(...array_map(fn(ItemEntity $e) => $e->getItem(), $this->itemsOnGround()));
		self::assertContains(ItemTypeIds::STONE_SWORD, $onGround);
		self::assertTrue(in_array(VanillaBlocks::DIRT()->asItem()->getTypeId(), $onGround, true), "wither skeletons only take what they list");
	}

	public function testItemsNobodyMayTakeAreIgnored() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$display = $this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 1.5, 0.5, ItemEntity::NEVER_DESPAWN);
		$this->runTicks(60);
		self::assertTrue($zombie->getMainHandItem()->isNull(), "items with an infinite pickup delay are never taken");
		self::assertFalse($display->isFlaggedForDespawn());
	}

	public function testMobGriefingOffDisablesPickup() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 1.5, 0.5);
		$this->runTicks(60);
		self::assertTrue($zombie->getMainHandItem()->isNull());
		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::DIAMOND_SWORD, $zombie->getMainHandItem()->getTypeId());
	}

	public function testMobsWhichCantPickUpLootIgnoreItems() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$nbt = $zombie->saveNBT();
		$nbt->setByte(MobEquipment::TAG_CAN_PICK_UP_LOOT, 0);
		$zombie->flagForDespawn();
		$copy = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(Zombie::class, $copy);
		$this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 1.5, 0.5);
		$this->runTicks(60);
		self::assertTrue($copy->getMainHandItem()->isNull());
	}

	public function testPickupChanceFollowsRegionalDifficulty() : void{
		$this->difficulty = World::DIFFICULTY_EASY;
		$this->world->setTime(self::LATE_FULL_MOON);
		for($i = 0; $i < 50; ++$i){
			$zombie = new Zombie($this->loc(0.5, 0.5));
			self::assertSame(0, $zombie->saveNBT()->getByte(MobEquipment::TAG_CAN_PICK_UP_LOOT), "never on Easy");
			$zombie->flagForDespawn();
		}
		$this->difficulty = World::DIFFICULTY_HARD;
		$looters = 0;
		$runs = 1000;
		for($i = 0; $i < $runs; ++$i){
			$skeleton = new Skeleton($this->loc(0.5, 0.5));
			$looters += $skeleton->saveNBT()->getByte(MobEquipment::TAG_CAN_PICK_UP_LOOT);
			$skeleton->flagForDespawn();
		}
		//0.55 x special multiplier 0.875
		self::assertEqualsWithDelta(0.55 * 0.875, $looters / $runs, 0.05);
	}

	public function testPiglinTakesBetterGearButKeepsItsGold() : void{
		$piglin = new Piglin($this->loc(0.5, 0.5));
		$piglin->setBaby(false);
		$piglin->setImmuneToZombification(true);
		$piglin->setHuntCooldown(100000);
		$piglin->setMainHandItem(VanillaItems::CROSSBOW());
		$piglin->getArmorInventory()->clearAll();
		$piglin->getArmorInventory()->setChestplate(VanillaItems::GOLDEN_CHESTPLATE());
		self::assertTrue($piglin->wantsItem(VanillaItems::IRON_HELMET()));
		self::assertFalse($piglin->wantsItem(VanillaItems::DIAMOND_CHESTPLATE()), "golden armor is the best");
		self::assertFalse($piglin->wantsItem(VanillaItems::NETHERITE_SWORD()), "a crossbow is better than any sword");
		self::assertFalse($piglin->wantsItem(VanillaBlocks::DIRT()->asItem()));

		$this->dropOnGround(VanillaItems::IRON_HELMET(), 2.5, 0.5);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::IRON_HELMET, $piglin->getArmorInventory()->getHelmet()->getTypeId());
		self::assertFalse($piglin->isAdmiring(), "gear is equipped, not admired");
		self::assertContains(ItemTypeIds::IRON_HELMET, self::typeIds(...$piglin->getDrops()), "picked gear always drops");
	}

	public function testRaidersPickUpTheOminousBannerAndLeadTheWave() : void{
		$pillager = new Pillager($this->loc(0.5, 0.5));
		$banner = $this->dropOnGround(VanillaItems::OMINOUS_BANNER(), 1.5, 0.5);
		$this->runTicks(40);
		self::assertFalse($pillager->isPatrolLeader(), "outside raids the banner is left alone");
		self::assertFalse($banner->isFlaggedForDespawn());

		$pillager->joinRaid(1, 1, $pillager->getPosition());
		$this->runTicks(60);
		self::assertTrue($pillager->isPatrolLeader());
		self::assertSame(ItemTypeIds::OMINOUS_BANNER, $pillager->getArmorInventory()->getChestplate()->getTypeId());
		self::assertCount(0, $this->itemsOnGround());
		self::assertTrue($pillager->isPersistent());
		$pillager->leaveRaid();
	}

	public function testOnlyZombifiedPiglinsTakeTheOminousBanner() : void{
		self::assertTrue(MobShareables::zombie()->isExcluded(VanillaItems::OMINOUS_BANNER()), "equip_item excluded_items banner:15");
		self::assertTrue(MobShareables::skeleton()->isExcluded(VanillaItems::OMINOUS_BANNER()));
		self::assertFalse(MobShareables::zombifiedPiglin()->isExcluded(VanillaItems::OMINOUS_BANNER()));

		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$this->dropOnGround(VanillaItems::OMINOUS_BANNER(), 1.5, 0.5);
		$this->runTicks(40);
		self::assertTrue($zombie->getMainHandItem()->isNull());

		$pigman = $this->looter(fn(Location $l, CompoundTag $n) => new ZombifiedPiglin($l, $n), 1.5, 2.0);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::OMINOUS_BANNER, $pigman->getMainHandItem()->getTypeId());
		self::assertTrue($zombie->getMainHandItem()->isNull());
	}

	public function testPickupEventCanBeCancelledOrChangeTheItem() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$this->cancelPickups = true;
		$listener = new RegisteredListener(function(EntityItemPickupEvent $ev) use ($zombie) : void{
			self::assertSame($zombie, $ev->getEntity());
			if($this->cancelPickups){
				$ev->cancel();
			}else{
				$ev->setItem(VanillaItems::IRON_HELMET());
			}
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(EntityItemPickupEvent::class)->register($listener);
		try{
			$sword = $this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 1.5, 0.5);
			$this->runTicks(40);
			self::assertTrue($zombie->getMainHandItem()->isNull(), "cancelled");
			self::assertFalse($sword->isFlaggedForDespawn());
			self::assertFalse($zombie->isPersistent());

			$this->cancelPickups = false;
			$this->runTicks(40);
			self::assertTrue($sword->isFlaggedForDespawn() || $sword->isClosed());
			self::assertTrue($zombie->getMainHandItem()->isNull());
			self::assertSame(ItemTypeIds::IRON_HELMET, $zombie->getArmorInventory()->getHelmet()->getTypeId(), "the item set by the plugin goes to its own slot");
		}finally{
			HandlerListManager::global()->getListFor(EntityItemPickupEvent::class)->unregister($listener);
		}
	}

	public function testConvertedMobsKeepPickedItemsAndLooting() : void{
		$husk = $this->looter(fn(Location $l, CompoundTag $n) => new Husk($l, $n), 0.5, 0.5);
		$this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 1.5, 0.5);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::DIAMOND_SWORD, $husk->getMainHandItem()->getTypeId());

		(new \ReflectionMethod(Husk::class, "convertToZombie"))->invoke($husk);
		$zombies = array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Zombie && !$e instanceof Husk && !$e->isClosed()));
		self::assertCount(1, $zombies);
		$zombie = $zombies[0];
		self::assertSame(ItemTypeIds::DIAMOND_SWORD, $zombie->getMainHandItem()->getTypeId());
		self::assertContains(ItemTypeIds::DIAMOND_SWORD, self::typeIds(...$zombie->getDrops()), "the picked sword still always drops");
		self::assertSame(1, $zombie->saveNBT()->getByte(MobEquipment::TAG_CAN_PICK_UP_LOOT), "it still picks up items");
		self::assertTrue($zombie->isPersistent());
		$zombie->flagForDespawn();

		$piglin = new Piglin($this->loc(4.5, 4.5));
		$piglin->setBaby(false);
		$piglin->setHuntCooldown(100000);
		$piglin->getArmorInventory()->clearAll();
		$piglin->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		self::assertFalse(in_array(ItemTypeIds::IRON_HELMET, self::typeIds(...$piglin->getDrops()), true), "spawned armor drops only on player kills");
		$this->dropOnGround(VanillaItems::DIAMOND_HELMET(), 5.5, 4.5);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::DIAMOND_HELMET, $piglin->getArmorInventory()->getHelmet()->getTypeId());
		(new \ReflectionMethod(Piglin::class, "zombify"))->invoke($piglin);
		$zombified = array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ZombifiedPiglin && !$e->isClosed()));
		self::assertCount(1, $zombified);
		self::assertContains(ItemTypeIds::DIAMOND_HELMET, self::typeIds(...$zombified[0]->getDrops()));
	}

	public function testCuredZombieVillagerDropsWhatItPickedUp() : void{
		$zombieVillager = $this->looter(fn(Location $l, CompoundTag $n) => new ZombieVillager($l, $n), 0.5, 0.5);
		$this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 1.5, 0.5);
		$this->dropOnGround(VanillaItems::IRON_HELMET(), 0.5, 1.5);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::DIAMOND_SWORD, $zombieVillager->getMainHandItem()->getTypeId());
		self::assertSame(ItemTypeIds::IRON_HELMET, $zombieVillager->getArmorInventory()->getHelmet()->getTypeId());
		self::assertCount(0, $this->itemsOnGround());

		self::assertNotNull($zombieVillager->finishConversion());
		$onGround = self::typeIds(...array_map(fn(ItemEntity $e) => $e->getItem(), $this->itemsOnGround()));
		self::assertContains(ItemTypeIds::DIAMOND_SWORD, $onGround, "transformation drop_equipment");
		self::assertContains(ItemTypeIds::IRON_HELMET, $onGround);
		self::assertTrue($zombieVillager->getMainHandItem()->isNull());
	}

	public function testZombifiedPiglinKeepsItsShieldAndDropsTheInventory() : void{
		$piglin = new Piglin($this->loc(0.5, 0.5));
		$piglin->setBaby(false);
		$piglin->setHuntCooldown(100000);
		$piglin->setMainHandItem(VanillaItems::CROSSBOW());
		$piglin->getArmorInventory()->clearAll();
		$this->dropOnGround(VanillaItems::SHIELD(), 1.5, 0.5);
		$this->runTicks(60);
		self::assertSame(ItemTypeIds::SHIELD, $piglin->getOffHandItem()->getTypeId());
		(new \ReflectionProperty(Piglin::class, "inventory"))->setValue($piglin, [VanillaItems::GOLDEN_APPLE()]);
		(new \ReflectionMethod(Piglin::class, "zombify"))->invoke($piglin);
		$zombified = array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ZombifiedPiglin && !$e->isClosed()));
		self::assertCount(1, $zombified);
		self::assertSame(ItemTypeIds::SHIELD, $zombified[0]->getOffHandItem()->getTypeId(), "preserve_equipment: both hands");
		self::assertSame(ItemTypeIds::CROSSBOW, $zombified[0]->getMainHandItem()->getTypeId());
		self::assertContains(ItemTypeIds::SHIELD, self::typeIds(...$zombified[0]->getDrops()), "the picked shield still always drops");
		self::assertContains(ItemTypeIds::GOLDEN_APPLE, self::typeIds(...array_map(fn(ItemEntity $e) => $e->getItem(), $this->itemsOnGround())), "drop_inventory");
		$zombified[0]->flagForDespawn();

		$admiring = new Piglin($this->loc(4.5, 4.5));
		$admiring->setBaby(false);
		$admiring->startAdmiring();
		(new \ReflectionMethod(Piglin::class, "zombify"))->invoke($admiring);
		$zombified = array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ZombifiedPiglin && !$e->isClosed() && !$e->isFlaggedForDespawn()));
		self::assertCount(1, $zombified);
		self::assertTrue($zombified[0]->getOffHandItem()->isNull(), "the gold being admired is dropped, not held");
		self::assertContains(ItemTypeIds::GOLD_INGOT, self::typeIds(...array_map(fn(ItemEntity $e) => $e->getItem(), $this->itemsOnGround())));
	}

	public function testPiglinGoldPickupFiresTheEventAndNothingIsTakenAfterAnAttack() : void{
		$piglin = new Piglin($this->loc(0.5, 0.5));
		$piglin->setBaby(false);
		$piglin->setImmuneToZombification(true);
		$piglin->setHuntCooldown(100000);
		$piglin->setMainHandItem(VanillaItems::CROSSBOW());
		$piglin->getArmorInventory()->clearAll();
		$listener = new RegisteredListener(function(EntityItemPickupEvent $ev) use ($piglin) : void{
			if($ev->getEntity() === $piglin && $this->cancelPickups){
				$ev->cancel();
			}
		}, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(EntityItemPickupEvent::class)->register($listener);
		try{
			$this->cancelPickups = true;
			$gold = $this->dropOnGround(VanillaItems::GOLD_INGOT(), 1.0, 0.5);
			$meat = $this->dropOnGround(VanillaItems::COOKED_PORKCHOP(), 1.0, 0.5);
			self::assertFalse($piglin->pickUp($gold), "a plugin keeps piglins from taking gold");
			self::assertFalse($piglin->pickUp($meat));
			self::assertFalse($piglin->isAdmiring());
			self::assertFalse($gold->isFlaggedForDespawn());
			self::assertFalse($meat->isFlaggedForDespawn());
			$this->cancelPickups = false;
			self::assertTrue($piglin->pickUp($gold));
			self::assertTrue($piglin->isAdmiring());
		}finally{
			HandlerListManager::global()->getListFor(EntityItemPickupEvent::class)->unregister($listener);
		}

		//behavior.pickup_items cooldown_after_being_attacked: 20 seconds, gear and food too
		$attacker = new ZombifiedPiglin($this->loc(3.5, 0.5));
		$piglin->attack(new EntityDamageByEntityEvent($attacker, $piglin, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$attacker->flagForDespawn();
		self::assertFalse($piglin->wantsItem(VanillaItems::IRON_HELMET()));
		self::assertFalse($piglin->wantsItem(VanillaItems::COOKED_PORKCHOP()));
		$piglin->setHealth($piglin->getMaxHealth());
		$this->runTicks(Piglin::ADMIRE_COOLDOWN_TICKS + 5);
		self::assertTrue($piglin->wantsItem(VanillaItems::IRON_HELMET()));
	}

	public function testUnreachableItemsAreLeftAlone() : void{
		//a sword walled in and roofed with stone, next to the zombie
		$stone = VanillaBlocks::STONE();
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				for($y = 1; $y <= 3; ++$y){
					if($dx !== 0 || $dz !== 0 || $y === 3){
						$this->world->setBlockAt(4 + $dx, $y, $dz, $stone, false);
					}
				}
			}
		}
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 2.5, 0.5);
		$this->dropOnGround(VanillaItems::DIAMOND_SWORD(), 4.5, 0.5);
		$running = 0;
		for($i = 0; $i < 300; ++$i){
			$this->runTicks(1);
			foreach($zombie->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof PickupItemsGoal){
					++$running;
				}
			}
		}
		self::assertTrue($zombie->getMainHandItem()->isNull());
		self::assertLessThan(30, $running, "it gives up on the sword at once and leaves it alone");

		//a reachable item is still taken
		$this->dropOnGround(VanillaItems::IRON_SWORD(), $zombie->getPosition()->x - 1, $zombie->getPosition()->z);
		$this->runTicks(40);
		self::assertSame(ItemTypeIds::IRON_SWORD, $zombie->getMainHandItem()->getTypeId());
	}

	public function testPickupSearchIsThrottled() : void{
		$zombie = $this->looter(fn(Location $l, CompoundTag $n) => new Zombie($l, $n), 0.5, 0.5);
		$this->runTicks(5);
		//dropped right after a search: found on the next one, at most a second later
		$this->dropOnGround(VanillaItems::IRON_SWORD(), $zombie->getPosition()->x + 0.5, $zombie->getPosition()->z);
		$this->runTicks(30);
		self::assertSame(ItemTypeIds::IRON_SWORD, $zombie->getMainHandItem()->getTypeId());
	}
}
