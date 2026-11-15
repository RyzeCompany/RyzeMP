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

use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\PiglinAi;
use pocketmine\entity\monster\PiglinBarter;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Potion;
use pocketmine\item\PotionType;
use pocketmine\item\SplashPotion;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_filter;
use function array_values;
use function mt_srand;

final class PiglinTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261014);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * An adult piglin that doesn't zombify (the test world is not the Nether) and, if frozen, doesn't move on its own.
	 */
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

	private static function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	/**
	 * A survival player standing at the position, holding the item and wearing the armor.
	 *
	 * @param Item[] $armor slot => item
	 * @phpstan-param array<int, Item> $armor
	 */
	private function player(Vector3 $pos, ?Item $held = null, array $armor = [], GameMode $mode = GameMode::SURVIVAL) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held ?? VanillaItems::AIR());
		$armorInventory = new ArmorInventory($player);
		foreach($armor as $slot => $item){
			$armorInventory->setItem($slot, $item);
		}
		$location = $this->loc($pos->x, $pos->y, $pos->z);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getArmorInventory")->willReturn($armorInventory);
		$player->method("hasFiniteResources")->willReturn($mode !== GameMode::CREATIVE);
		$player->method("isCreative")->willReturn($mode === GameMode::CREATIVE);
		$player->method("getGamemode")->willReturn($mode);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(true);
		$player->method("isAlive")->willReturn(true);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturn($pos->add(0, 1.62, 0));
		$player->method("getId")->willReturn(424242);
		$player->method("getName")->willReturn("Tester");
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
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

	private function dropOnGround(Item $item, float $x, float $z) : ItemEntity{
		$entity = new ItemEntity($this->loc($x, 1, $z), $item);
		$entity->setPickupDelay(0);
		$entity->spawnToAll();
		return $entity;
	}

	public function testBarterTableMatchesBedrock() : void{
		$entries = PiglinBarter::getEntries();
		self::assertCount(19, $entries);
		self::assertSame(469, PiglinBarter::getTotalWeight());
		$expected = [
			//type ID => [weight, min, max]
			ItemTypeIds::ENCHANTED_BOOK => [5, 1, 1],
			ItemTypeIds::IRON_BOOTS => [8, 1, 1],
			ItemTypeIds::SPLASH_POTION => [8, 1, 1],
			ItemTypeIds::IRON_NUGGET => [10, 10, 36],
			ItemTypeIds::ENDER_PEARL => [10, 2, 4],
			ItemTypeIds::STRING => [20, 3, 9],
			ItemTypeIds::NETHER_QUARTZ => [20, 5, 12],
			ItemTypeIds::FIRE_CHARGE => [40, 1, 1],
			ItemTypeIds::LEATHER => [40, 2, 4],
			ItemTypeIds::NETHER_BRICK => [40, 2, 8],
			ItemTypeIds::ARROW => [40, 6, 12],
		];
		$blocks = [
			VanillaBlocks::OBSIDIAN()->asItem()->getTypeId() => [40, 1, 1],
			VanillaBlocks::CRYING_OBSIDIAN()->asItem()->getTypeId() => [40, 1, 3],
			VanillaBlocks::SOUL_SAND()->asItem()->getTypeId() => [40, 2, 8],
			VanillaBlocks::GRAVEL()->asItem()->getTypeId() => [40, 8, 16],
			VanillaBlocks::BLACKSTONE()->asItem()->getTypeId() => [40, 8, 16],
			VanillaBlocks::DRIED_GHAST()->asItem()->getTypeId() => [10, 1, 1],
		];
		$expected += $blocks;
		$potionWeights = [];
		foreach($entries as [$weight, $make, $min, $max]){
			self::assertGreaterThan(0, $weight);
			self::assertLessThanOrEqual($max, $min);
			$item = $make();
			if($item instanceof Potion){
				$potionWeights[$item->getType()->name] = $weight;
				continue;
			}
			self::assertArrayHasKey($item->getTypeId(), $expected, $item->getName() . " is not in the table");
			self::assertSame($expected[$item->getTypeId()], [$weight, $min, $max], $item->getName());
			if($item instanceof SplashPotion){
				self::assertSame(PotionType::FIRE_RESISTANCE, $item->getType());
			}
		}
		self::assertSame([PotionType::FIRE_RESISTANCE->name => 8, PotionType::WATER->name => 10], $potionWeights);
	}

	public function testBarterRollsStayInRangeAndFollowTheWeights() : void{
		$counts = [];
		$rolls = 4690;
		for($i = 0; $i < $rolls; ++$i){
			$item = PiglinBarter::roll();
			self::assertFalse($item->isNull());
			$id = $item->getTypeId();
			$counts[$id] = ($counts[$id] ?? 0) + 1;
			if($id === ItemTypeIds::ENCHANTED_BOOK || $id === ItemTypeIds::IRON_BOOTS){
				$level = $item->getEnchantmentLevel(VanillaEnchantments::SOUL_SPEED());
				self::assertGreaterThanOrEqual(1, $level, "bartered " . $item->getName() . " has Soul Speed");
				self::assertLessThanOrEqual(3, $level);
			}elseif($id === ItemTypeIds::IRON_NUGGET){
				self::assertGreaterThanOrEqual(10, $item->getCount());
				self::assertLessThanOrEqual(36, $item->getCount());
			}elseif($id === ItemTypeIds::ENDER_PEARL){
				self::assertGreaterThanOrEqual(2, $item->getCount());
				self::assertLessThanOrEqual(4, $item->getCount());
			}
		}
		//weight 40 of 469: ~400 of 4690 rolls; weight 5: ~50
		self::assertEqualsWithDelta(400, $counts[ItemTypeIds::FIRE_CHARGE] ?? 0, 80);
		self::assertEqualsWithDelta(50, $counts[ItemTypeIds::ENCHANTED_BOOK] ?? 0, 30);
		self::assertEqualsWithDelta(100, $counts[ItemTypeIds::ENDER_PEARL] ?? 0, 40);
		self::assertSame(VanillaEnchantments::SOUL_SPEED(), StringToEnchantmentParser::getInstance()->parse("soul_speed"));
	}

	public function testGivingGoldIngotBarters() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$player = $this->player(new Vector3(0.5, 1, 3.5), VanillaItems::GOLD_INGOT()->setCount(3));
		self::assertTrue($piglin->onInteract($player, new Vector3(0, 0, 0)));
		self::assertSame(2, $player->getInventory()->getItemInHand()->getCount(), "one ingot taken");
		self::assertTrue($piglin->isAdmiring());
		self::assertSame(ItemTypeIds::GOLD_INGOT, $piglin->getOffHandItem()->getTypeId(), "held in the off hand");
		self::assertFalse($piglin->onInteract($player, new Vector3(0, 0, 0)), "busy admiring");
		$this->runTicks(Piglin::ADMIRE_TICKS - 5);
		self::assertTrue($piglin->isAdmiring());
		self::assertCount(0, $this->entitiesOf(ItemEntity::class));
		$this->runTicks(10);
		self::assertFalse($piglin->isAdmiring());
		self::assertTrue($piglin->getOffHandItem()->isNull());
		$thrown = $this->entitiesOf(ItemEntity::class);
		self::assertCount(1, $thrown, "it threw the bartered item");
		self::assertNotSame(ItemTypeIds::GOLD_INGOT, $thrown[0]->getItem()->getTypeId());
		self::assertCount(0, $piglin->getInventoryContents(), "the ingot was used up");

		$baby = $this->piglin(6.5, 0.5);
		$baby->setBaby(true);
		self::assertFalse($baby->onInteract($player, new Vector3(0, 0, 0)), "babies don't barter");
		self::assertFalse($baby->isAdmiring());
	}

	public function testHurtingStopsTheAdmiringAndKeepsTheGold() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$piglin->startAdmiring();
		$attacker = new ZombifiedPiglin($this->loc(2.5, 1, 0.5));
		self::freeze($attacker);
		$piglin->attack(new EntityDamageByEntityEvent($attacker, $piglin, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertFalse($piglin->isAdmiring());
		self::assertCount(1, $piglin->getInventoryContents());
		self::assertSame(ItemTypeIds::GOLD_INGOT, $piglin->getInventoryContents()[0]->getTypeId());
		self::assertFalse($piglin->wantsItem(VanillaItems::GOLD_INGOT()), "no admiring for 20 seconds after being hurt");
		$this->runTicks(Piglin::ADMIRE_COOLDOWN_TICKS + 5);
		self::assertTrue($piglin->wantsItem(VanillaItems::GOLD_INGOT()));
	}

	public function testPicksUpGoldAndKeepsIt() : void{
		$piglin = $this->piglin(0.5, 0.5, false);
		$piglin->getGoalSelector()->setFlagDisabled(Goal::FLAG_LOOK, true);
		$this->dropOnGround(VanillaItems::GOLDEN_HELMET(), 3.5, 0.5);
		$this->runTicks(80);
		self::assertTrue($piglin->isAdmiring(), "it walked to the helmet and picked it up");
		self::assertSame(ItemTypeIds::GOLDEN_HELMET, $piglin->getOffHandItem()->getTypeId());
		self::assertCount(0, $this->entitiesOf(ItemEntity::class));
		$this->runTicks(Piglin::ADMIRE_TICKS + 5);
		self::assertSame(ItemTypeIds::GOLDEN_HELMET, $piglin->getArmorInventory()->getHelmet()->getTypeId(), "golden armor is worn");
		self::assertTrue(Piglin::wearsGold($piglin));
		$drops = $piglin->getDrops();
		self::assertCount(1, $drops, "picked up armor always drops");

		//not loved: ignored
		$this->dropOnGround(VanillaItems::IRON_INGOT(), $piglin->getPosition()->x + 1, $piglin->getPosition()->z);
		self::assertFalse($piglin->wantsItem(VanillaItems::IRON_INGOT()));
		self::assertTrue($piglin->wantsItem(VanillaItems::CLOCK()));
		self::assertTrue($piglin->wantsItem(VanillaBlocks::GOLD()->asItem()));
	}

	public function testPicksUpThrownGoldIngotAndBarters() : void{
		$piglin = $this->piglin(0.5, 0.5, false);
		$this->dropOnGround(VanillaItems::GOLD_INGOT()->setCount(2), 1.5, 0.5);
		$this->runTicks(30);
		self::assertTrue($piglin->isAdmiring());
		$stack = $this->entitiesOf(ItemEntity::class);
		self::assertCount(1, $stack);
		self::assertSame(1, $stack[0]->getItem()->getCount(), "it takes one ingot at a time");
	}

	public function testEatsPorkchops() : void{
		$piglin = $this->piglin(0.5, 0.5);
		self::assertTrue($piglin->wantsItem(VanillaItems::COOKED_PORKCHOP()));
		$meat = $this->dropOnGround(VanillaItems::COOKED_PORKCHOP(), 1.0, 0.5);
		self::assertTrue($piglin->pickUp($meat));
		self::assertFalse($piglin->isAdmiring());
		self::assertFalse($piglin->wantsItem(VanillaItems::RAW_PORKCHOP()), "ate recently");
	}

	public function testGoldArmorNeutrality() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$plain = $this->player(new Vector3(0.5, 1, 5.5));
		$iron = $this->player(new Vector3(0.5, 1, 5.5), null, [ArmorInventory::SLOT_HEAD => VanillaItems::IRON_HELMET()]);
		$golden = $this->player(new Vector3(0.5, 1, 5.5), null, [ArmorInventory::SLOT_FEET => VanillaItems::GOLDEN_BOOTS()]);
		self::assertFalse(Piglin::wearsGold($plain));
		self::assertFalse(Piglin::wearsGold($iron));
		self::assertTrue(Piglin::wearsGold($golden));
		self::assertTrue($piglin->wouldAttack($plain));
		self::assertTrue($piglin->wouldAttack($iron));
		self::assertFalse($piglin->wouldAttack($golden), "one piece of golden armor is enough");
		self::assertTrue($piglin->wouldAttack(new WitherSkeleton($this->loc(5.5, 1, 5.5))));

		$piglin->startAdmiring();
		self::assertFalse($piglin->wouldAttack($plain), "busy admiring");
		$baby = $this->piglin(4.5, 0.5);
		$baby->setBaby(true);
		self::assertFalse($baby->wouldAttack($plain));
	}

	public function testSpawnEquipment() : void{
		$swords = 0;
		$crossbows = 0;
		$armor = 0;
		$adults = 0;
		for($i = 0; $i < 400; ++$i){
			$piglin = new Piglin($this->loc(0.5, 1, 0.5));
			if(!$piglin->isBaby()){
				++$adults;
				$id = $piglin->getMainHandItem()->getTypeId();
				if($id === ItemTypeIds::CROSSBOW){
					++$crossbows;
				}elseif($id === ItemTypeIds::GOLDEN_SWORD || $id === ItemTypeIds::GOLDEN_SPEAR){
					++$swords;
				}
				foreach($piglin->getArmorInventory()->getContents() as $item){
					self::assertTrue(PiglinAi::isLovedItem($item), "spawn armor is golden");
					++$armor;
				}
			}else{
				self::assertTrue($piglin->getMainHandItem()->isNull());
			}
			$piglin->flagForDespawn();
		}
		self::assertSame($adults, $swords + $crossbows);
		self::assertEqualsWithDelta($adults / 2, $crossbows, $adults / 8);
		//armor needs regional difficulty: none in a new Normal world
		self::assertSame(0, $armor);

		//Hard, day 64, full moon: 15% x 0.875 of the adults get a golden helmet (and maybe more pieces)
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setTime(World::TIME_FULL * 64);
		$adults = 0;
		$helmets = 0;
		for($i = 0; $i < 800; ++$i){
			$piglin = new Piglin($this->loc(0.5, 1, 0.5));
			if(!$piglin->isBaby()){
				++$adults;
				foreach($piglin->getArmorInventory()->getContents() as $item){
					self::assertTrue(PiglinAi::isLovedItem($item), "spawn armor is golden");
				}
				$helmets += $piglin->getArmorInventory()->getHelmet()->isNull() ? 0 : 1;
			}
			$piglin->flagForDespawn();
		}
		self::assertEqualsWithDelta($adults * 0.15 * 0.875, $helmets, $adults * 0.05);
		$this->runTicks(1);

		$brute = new PiglinBrute($this->loc(0.5, 1, 0.5));
		self::assertSame(ItemTypeIds::GOLDEN_AXE, $brute->getMainHandItem()->getTypeId());
	}

	public function testArmorAndInventoryAreSaved() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$piglin->startAdmiring(VanillaItems::GOLDEN_CHESTPLATE());
		$this->runTicks(Piglin::ADMIRE_TICKS + 2);
		$piglin->startAdmiring(VanillaItems::CLOCK());
		$this->runTicks(Piglin::ADMIRE_TICKS + 2);
		$copy = EntityFactory::getInstance()->createFromData($this->world, $piglin->saveNBT());
		self::assertInstanceOf(Piglin::class, $copy);
		self::assertSame(ItemTypeIds::GOLDEN_CHESTPLATE, $copy->getArmorInventory()->getChestplate()->getTypeId());
		self::assertCount(1, $copy->getInventoryContents());
		self::assertSame(ItemTypeIds::CLOCK, $copy->getInventoryContents()[0]->getTypeId());
		self::assertTrue($copy->isImmuneToZombification());
		$copy->flagForDespawn();
	}

	public function testZombificationImmunity() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$mortal = $this->piglin(6.5, 0.5);
		$mortal->setImmuneToZombification(false);
		$this->runTicks(Piglin::getZombificationTicks() + 5);
		self::assertFalse($piglin->isFlaggedForDespawn(), "immune piglins stay piglins");
		self::assertTrue($mortal->isFlaggedForDespawn() || $mortal->isClosed());
		self::assertCount(1, $this->entitiesOf(ZombifiedPiglin::class));
	}

	public function testBreakingGuardedBlocksAngersPiglins() : void{
		$near = $this->piglin(0.5, 0.5);
		$far = $this->piglin(20.5, 0.5);
		$baby = $this->piglin(-3.5, 0.5);
		$baby->setBaby(true);
		//gold armor doesn't help here
		$player = $this->player(new Vector3(0.5, 1, 6.5), null, [ArmorInventory::SLOT_HEAD => VanillaItems::GOLDEN_HELMET()]);

		self::assertSame(0, PiglinAi::onBlockBroken($player, VanillaBlocks::STONE()));
		self::assertFalse($near->isAngry());
		foreach([
			VanillaBlocks::GOLD(), VanillaBlocks::GOLD_ORE(), VanillaBlocks::DEEPSLATE_GOLD_ORE(), VanillaBlocks::NETHER_GOLD_ORE(),
			VanillaBlocks::GILDED_BLACKSTONE(), VanillaBlocks::RAW_GOLD(), VanillaBlocks::CHEST(), VanillaBlocks::BARREL(),
			VanillaBlocks::ENDER_CHEST(), VanillaBlocks::SHULKER_BOX(), VanillaBlocks::DYED_SHULKER_BOX(), VanillaBlocks::TRAPPED_CHEST(),
		] as $block){
			self::assertTrue(PiglinAi::isGuardedBlock($block), $block->getName() . " is guarded");
		}
		self::assertFalse(PiglinAi::isGuardedBlock(VanillaBlocks::IRON()));

		//behind a wall: breaking doesn't need line of sight
		for($x = -2; $x <= 3; ++$x){
			for($y = 1; $y <= 3; ++$y){
				$this->set($x, $y, 3, VanillaBlocks::STONE());
			}
		}
		self::assertSame(1, PiglinAi::onBlockBroken($player, VanillaBlocks::NETHER_GOLD_ORE()));
		self::assertTrue($near->isAngry());
		self::assertFalse($far->isAngry(), "more than 16 blocks away");
		self::assertFalse($baby->isAngry());

		$creative = $this->player(new Vector3(0.5, 1, 6.5), null, [], GameMode::CREATIVE);
		$near->setAngryAt(null, 0);
		self::assertSame(0, PiglinAi::onBlockBroken($creative, VanillaBlocks::GOLD()));
	}

	public function testOpeningContainersAngersPiglinsInSight() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$admiring = $this->piglin(3.5, 0.5);
		$admiring->startAdmiring();
		$player = $this->player(new Vector3(0.5, 1, 6.5));

		$this->set(1, 1, 7, VanillaBlocks::CHEST());
		$tile = $this->world->getTile(new Vector3(1, 1, 7));
		self::assertInstanceOf(TileChest::class, $tile);
		$tile->getInventory()->onOpen($player);
		self::assertTrue($piglin->isAngry(), "opening a chest in its sight");
		self::assertFalse($admiring->isAngry(), "busy admiring");
		$tile->getInventory()->onClose($player);

		$piglin->setAngryAt(null, 0);
		for($x = -2; $x <= 3; ++$x){
			for($y = 1; $y <= 3; ++$y){
				$this->set($x, $y, 3, VanillaBlocks::STONE());
			}
		}
		self::assertSame(0, PiglinAi::onContainerOpened($player), "out of sight");
		self::assertFalse($piglin->isAngry());
	}

	public function testHurtingAPiglinAngersTheGroup() : void{
		$a = $this->piglin(0.5, 0.5);
		$b = $this->piglin(6.5, 0.5);
		$brute = new PiglinBrute($this->loc(-5.5, 1, 0.5));
		self::freeze($brute);
		$attacker = new WitherSkeleton($this->loc(2.5, 1, 2.5));
		self::freeze($attacker);
		$a->attack(new EntityDamageByEntityEvent($attacker, $a, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame($attacker, $a->getAngerTarget());
		self::assertSame($attacker, $b->getAngerTarget(), "shared with the piglins around");
		self::assertSame($attacker, $brute->getAngerTarget(), "brutes join in");
	}

	public function testGroupsHuntHoglinsAndCelebrate() : void{
		$a = $this->piglin(0.5, 0.5);
		$b = $this->piglin(2.5, 0.5);
		$hoglin = new Hoglin($this->loc(8.5, 1, 0.5));
		$hoglin->setAge(0);
		$hoglin->setImmuneToZombification(true);
		self::freeze($hoglin);
		$a->setHuntCooldown(0);
		$this->runTicks(25);
		self::assertSame($hoglin, $a->getAngerTarget(), "hunting the hoglin");
		self::assertSame($hoglin, $b->getAngerTarget(), "the group joins the hunt");
		self::assertGreaterThan(0, $b->getHuntCooldown());

		$hoglin->attack(new EntityDamageByEntityEvent($a, $hoglin, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1000));
		self::assertFalse($hoglin->isAlive());
		self::assertTrue($a->isCelebrating());
		self::assertTrue($b->isCelebrating());
		$this->runTicks(Piglin::CELEBRATE_TICKS + 5);
		self::assertFalse($a->isCelebrating());
	}

	public function testOutnumberedPiglinsDontHunt() : void{
		$piglin = $this->piglin(0.5, 0.5);
		$hoglins = [];
		for($i = 0; $i < 3; ++$i){
			$hoglin = new Hoglin($this->loc(8.5, 1, -3.5 + $i * 3));
			$hoglin->setAge(0);
			$hoglin->setImmuneToZombification(true);
			self::freeze($hoglin);
			$hoglins[] = $hoglin;
		}
		self::assertSame([1, 3], $piglin->countAdultsAround());
		$piglin->setHuntCooldown(0);
		$this->runTicks(25);
		self::assertFalse($piglin->isAngry(), "outnumbered: no hunt");
		self::assertFalse($piglin->tryHunting());
	}

	public function testHoglinsAvoidRepellents() : void{
		$hoglin = new Hoglin($this->loc(0.5, 1, 0.5));
		$hoglin->setAge(0);
		$hoglin->setImmuneToZombification(true);
		self::freeze($hoglin);
		self::assertNull($hoglin->findRepellent());

		$this->set(3, 1, 0, VanillaBlocks::FLOWER_POT());
		$this->runTicks(25);
		self::assertNull($hoglin->findRepellent(), "an empty flower pot is fine");
		$this->set(3, 1, 0, VanillaBlocks::FLOWER_POT()->setPlant(VanillaBlocks::WARPED_FUNGUS()));
		$this->runTicks(25);
		self::assertNull($hoglin->findRepellent(), "potted warped fungus isn't in the Bedrock avoid_block list");
		//no frame: placed without block updates so that it stays
		$this->world->setBlockAt(3, 1, 0, VanillaBlocks::NETHER_PORTAL(), false);
		$this->runTicks(25);
		self::assertNotNull($hoglin->findRepellent(), "nether portal");
		$this->set(3, 1, 0, VanillaBlocks::AIR());
		$this->set(12, 1, 12, VanillaBlocks::WARPED_FUNGUS());
		$this->runTicks(25);
		self::assertNull($hoglin->findRepellent(), "out of range");
		$hoglin->flagForDespawn();

		//a free hoglin walks away from a respawn anchor
		$this->set(12, 1, 12, VanillaBlocks::AIR());
		$this->set(-15, 1, 0, VanillaBlocks::RESPAWN_ANCHOR());
		$free = new Hoglin($this->loc(-12.5, 1, 0.5));
		$free->setAge(0);
		$free->setImmuneToZombification(true);
		$this->runTicks(100);
		self::assertGreaterThan(6.0, $free->getPosition()->distance(new Vector3(-14.5, 1, 0.5)), "it moved away from the anchor");
	}

	public function testPiglinsAvoidSoulFire() : void{
		$piglin = $this->piglin(0.5, 0.5);
		self::assertNull($piglin->findRepellent());
		$this->set(2, 1, 2, VanillaBlocks::SOUL_CAMPFIRE()->setLit(false));
		$this->runTicks(25);
		self::assertNull($piglin->findRepellent(), "unlit soul campfires are fine");
		$this->set(2, 1, 2, VanillaBlocks::SOUL_TORCH());
		$this->runTicks(25);
		self::assertNotNull($piglin->findRepellent());
		$this->set(2, 1, 2, VanillaBlocks::SOUL_LANTERN());
		$this->runTicks(25);
		self::assertNotNull($piglin->findRepellent());
	}

	public function testCensusAsksOverriddenCountAdultsAround() : void{
		//the census skips the count in worlds without hoglins, but not when a subclass counts in its own way
		$piglin = new PiglinTestCensusPiglin($this->loc(0.5, 1, 0.5));
		$piglin->setBaby(false);
		$piglin->setImmuneToZombification(true);
		self::freeze($piglin);
		$this->runTicks(60);
		self::assertGreaterThanOrEqual(2, $piglin->counts, "asked once a second");
	}
}

final class PiglinTestCensusPiglin extends Piglin implements NeverSavedWithChunkEntity{
	public int $counts = 0;

	public function countAdultsAround() : array{
		++$this->counts;
		return [1, 0];
	}
}
