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

use pocketmine\block\Bed;
use pocketmine\block\Froglight;
use pocketmine\block\Jukebox;
use pocketmine\block\Note;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\FroglightType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\DropGiftForOwnerGoal;
use pocketmine\entity\ai\hostile\goal\EvokerWololoGoal;
use pocketmine\entity\animal\Allay;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Frog;
use pocketmine\entity\animal\Goat;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Endermite;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\MagmaCube;
use pocketmine\entity\monster\Phantom;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\Zombie;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function mt_srand;

/**
 * Ravager block breaking, endermites from ender pearls, the evoker's wololo, cats (sleeping with their owner, gifts,
 * sitting on blocks, scaring creepers and phantoms), allay dancing, duplication and note blocks, frogs eating small
 * slimes and magma cubes, and goats jumping onto high blocks.
 */
final class MobsMiscTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261013);
	}

	protected function tearDown() : void{
		EnderPearl::setEndermiteChance(null);
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A survival player mock holding the item, standing at the given position.
	 *
	 * @phpstan-param (\Closure() : bool)|null $sleeping
	 */
	private function player(Item $held, Vector3 $pos, ?\Closure $sleeping = null, ?Vector3 $bed = null) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$uuid = Uuid::uuid4();
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn($uuid);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(true);
		$player->method("isAlive")->willReturn(true);
		$player->method("getLocation")->willReturn($this->loc($pos->x, $pos->y, $pos->z));
		$player->method("getPosition")->willReturn($this->loc($pos->x, $pos->y, $pos->z));
		$player->method("getName")->willReturn("Tester");
		$player->method("getId")->willReturn(424242);
		$player->method("getSize")->willReturn(new EntitySizeInfo(1.8, 0.6, 1.62));
		$sleeping ??= fn() => false;
		$player->method("isSleeping")->willReturnCallback($sleeping);
		$player->method("getSleepingPosition")->willReturnCallback(fn() => $sleeping() ? $bed : null);
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

	/**
	 * @return int[] type IDs of the dropped items lying around
	 */
	private function droppedItemTypes() : array{
		return array_map(fn(ItemEntity $e) => $e->getItem()->getTypeId(), $this->entitiesOf(ItemEntity::class));
	}

	// -------------------------------------------------------------------------------------------------------------
	// Ravager
	// -------------------------------------------------------------------------------------------------------------

	public function testRavagerBreakableBlocks() : void{
		foreach([
			VanillaBlocks::OAK_LEAVES(), VanillaBlocks::AZALEA_LEAVES(), VanillaBlocks::WHEAT(), VanillaBlocks::CARROTS(),
			VanillaBlocks::POTATOES(), VanillaBlocks::BEETROOTS(), VanillaBlocks::MELON_STEM(), VanillaBlocks::PUMPKIN(),
			VanillaBlocks::CARVED_PUMPKIN(), VanillaBlocks::LIT_PUMPKIN(), VanillaBlocks::MELON(), VanillaBlocks::DANDELION(),
			VanillaBlocks::POPPY(), VanillaBlocks::SUNFLOWER(), VanillaBlocks::TALL_GRASS(), VanillaBlocks::FERN(),
			VanillaBlocks::OAK_SAPLING(), VanillaBlocks::SUGARCANE(), VanillaBlocks::VINES(), VanillaBlocks::LILY_PAD(),
			VanillaBlocks::SNOW_LAYER(), VanillaBlocks::SWEET_BERRY_BUSH(), VanillaBlocks::BAMBOO(), VanillaBlocks::DEAD_BUSH(),
			VanillaBlocks::RED_MUSHROOM(), VanillaBlocks::BROWN_MUSHROOM(), VanillaBlocks::CRIMSON_FUNGUS(), VanillaBlocks::AZALEA(),
		] as $block){
			self::assertTrue(Ravager::canBreak($block), $block->getName() . " should be breakable");
		}
		foreach([
			VanillaBlocks::STONE(), VanillaBlocks::OAK_LOG(), VanillaBlocks::DIRT(), VanillaBlocks::FARMLAND(), VanillaBlocks::GLASS(),
			VanillaBlocks::TORCHFLOWER(), VanillaBlocks::PITCHER_PLANT(), VanillaBlocks::WITHER_ROSE(), VanillaBlocks::CACTUS(),
		] as $block){
			self::assertFalse(Ravager::canBreak($block), $block->getName() . " should not be breakable");
		}
	}

	public function testRavagerBreaksLeavesAndCropsItTouches() : void{
		//the ravager (1.95 wide) stands against leaves at x=2: its box touches them
		$this->set(2, 1, 0, VanillaBlocks::OAK_LEAVES());
		$this->set(2, 2, 0, VanillaBlocks::OAK_LEAVES());
		$this->set(0, 1, 1, VanillaBlocks::POPPY());
		$this->set(-1, 1, 0, VanillaBlocks::STONE());
		$this->set(5, 1, 5, VanillaBlocks::OAK_LEAVES()); //out of reach
		$ravager = new Ravager($this->loc(1.0, 1, 0.5));
		$ravager->spawnToAll();
		self::assertSame(3, $ravager->breakBlocksInTheWay());
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(2, 1, 0)->getTypeId());
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(2, 2, 0)->getTypeId());
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 1, 1)->getTypeId());
		self::assertSame(VanillaBlocks::STONE()->getTypeId(), $this->get(-1, 1, 0)->getTypeId());
		self::assertSame(VanillaBlocks::OAK_LEAVES()->getTypeId(), $this->get(5, 1, 5)->getTypeId());
		$this->runTicks(2);
		self::assertContains(VanillaBlocks::POPPY()->asItem()->getTypeId(), $this->droppedItemTypes(), "the poppy drops");

		//stunned ravagers don't break anything
		$this->set(2, 1, 0, VanillaBlocks::OAK_LEAVES());
		$ravager->teleport(new Vector3(1.0, 1, 0.5));
		$ravager->stun();
		self::assertSame(0, $ravager->breakBlocksInTheWay());
		self::assertSame(VanillaBlocks::OAK_LEAVES()->getTypeId(), $this->get(2, 1, 0)->getTypeId());
	}

	public function testRavagerBreaksBlocksWhileTicking() : void{
		$this->set(0, 1, 0, VanillaBlocks::WHEAT());
		$ravager = new Ravager($this->loc(0.5, 1, 0.5));
		$ravager->spawnToAll();
		$this->runTicks(3);
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 1, 0)->getTypeId(), "the ravager tramples the wheat it stands in");
	}

	// -------------------------------------------------------------------------------------------------------------
	// Ender pearl -> endermite
	// -------------------------------------------------------------------------------------------------------------

	public function testEnderPearlEndermiteChance() : void{
		self::assertSame(0.05, EnderPearl::getEndermiteChance());
		$pearl = new EnderPearl($this->loc(0.5, 3, 0.5), null);
		EnderPearl::setEndermiteChance(0.0);
		self::assertNull($pearl->trySpawnEndermite(new Vector3(0.5, 1, 0.5)));

		EnderPearl::setEndermiteChance(1.0);
		$endermite = $pearl->trySpawnEndermite(new Vector3(0.5, 1, 0.5));
		self::assertInstanceOf(Endermite::class, $endermite);
		self::assertFalse($endermite->isPersistent(), "pearl endermites vanish after 2 minutes");

		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		self::assertNull($pearl->trySpawnEndermite(new Vector3(0.5, 1, 0.5)), "no endermites in peaceful");
		$this->difficulty = World::DIFFICULTY_NORMAL;
		$pearl->flagForDespawn();

		//about 5% of pearls
		EnderPearl::setEndermiteChance(null);
		$spawned = 0;
		for($i = 0; $i < 2000; ++$i){
			if($pearl->trySpawnEndermite(new Vector3(0.5, 1, 0.5)) !== null){
				++$spawned;
			}
		}
		self::assertGreaterThan(60, $spawned);
		self::assertLessThan(140, $spawned);
	}

	public function testLandingPearlSpawnsEndermite() : void{
		EnderPearl::setEndermiteChance(1.0);
		$pearl = new EnderPearl($this->loc(0.5, 3, 0.5), null);
		$pearl->setMotion(new Vector3(0, -0.5, 0));
		$pearl->spawnToAll();
		$endermites = [];
		//(with no player around, the endermite despawns soon after: look for it right after the landing)
		for($i = 0; $i < 20 && count($endermites) === 0; ++$i){
			$this->runTicks(1);
			$endermites = array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Endermite));
		}
		self::assertTrue($pearl->isClosed() || $pearl->isFlaggedForDespawn(), "the pearl landed");
		self::assertCount(1, $endermites);
		self::assertEqualsWithDelta(0.5, $endermites[0]->getPosition()->x, 1.0);
		self::assertEqualsWithDelta(1.0, $endermites[0]->getPosition()->y, 0.5);
	}

	// -------------------------------------------------------------------------------------------------------------
	// Evoker wololo
	// -------------------------------------------------------------------------------------------------------------

	public function testEvokerTurnsBlueSheepRed() : void{
		$evoker = new Evoker($this->loc(0.5, 1, 0.5));
		$evoker->spawnToAll();
		$blue = new Sheep($this->loc(6.5, 1, 0.5));
		$blue->setColor(DyeColor::BLUE);
		$blue->spawnToAll();
		$white = new Sheep($this->loc(-6.5, 1, 0.5));
		$white->setColor(DyeColor::WHITE);
		$white->spawnToAll();
		$far = new Sheep($this->loc(0.5, 1, 20.5));
		$far->setColor(DyeColor::BLUE);
		$far->spawnToAll();

		$sawCasting = false;
		for($i = 0; $i < 100 && $blue->getColor() === DyeColor::BLUE; ++$i){
			$this->runTicks(1);
			$sawCasting = $sawCasting || $evoker->getCastingSpell() === EvokerWololoGoal::SPELL_WOLOLO;
		}
		self::assertSame(DyeColor::RED, $blue->getColor(), "wololo");
		self::assertTrue($sawCasting, "the evoker casts the spell first");
		self::assertSame(DyeColor::WHITE, $white->getColor());
		self::assertSame(DyeColor::BLUE, $far->getColor(), "out of range (16 blocks)");
		$this->runTicks(40);
		self::assertFalse($evoker->isCasting(), "the cast lasts 3 seconds");
	}

	public function testEvokerWithTargetDoesntWololo() : void{
		$evoker = new Evoker($this->loc(0.5, 1, 0.5));
		$evoker->spawnToAll();
		$zombie = new Zombie($this->loc(-10.5, 1, 0.5));
		$zombie->spawnToAll();
		$evoker->setAttackTarget($zombie);
		self::assertFalse($evoker->castWololo(null));
		$sheep = new Sheep($this->loc(6.5, 1, 0.5));
		$sheep->setColor(DyeColor::BLUE);
		$sheep->spawnToAll();
		$goal = new EvokerWololoGoal($evoker);
		self::assertFalse($goal->canStart(), "busy with its target");
		$evoker->setAttackTarget(null);
		self::assertTrue($goal->canStart());
		self::assertTrue($evoker->castWololo($sheep));
		self::assertSame(DyeColor::RED, $sheep->getColor());
		self::assertFalse($evoker->castWololo($sheep), "only blue sheep");
	}

	// -------------------------------------------------------------------------------------------------------------
	// Cats: creepers, phantoms
	// -------------------------------------------------------------------------------------------------------------

	public function testCreeperRunsFromCats() : void{
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$creeper->spawnToAll();
		$cat = new Cat($this->loc(3.5, 1, 0.5));
		$cat->spawnToAll();
		$fled = false;
		for($i = 0; $i < 40 && !$fled; ++$i){
			$this->runTicks(1);
			foreach($creeper->getGoalSelector()->getRunningGoals() as $goal){
				$fled = $fled || $goal instanceof AvoidEntityGoal;
			}
		}
		self::assertTrue($fled, "the creeper runs away from the cat");
	}

	public function testPhantomsAreScaredOfCats() : void{
		$phantom = new Phantom($this->loc(0.5, 10, 0.5));
		$phantom->spawnToAll();
		self::assertFalse($phantom->isScaredByCats());
		$cat = new Cat($this->loc(10.5, 1, 0.5));
		$cat->spawnToAll();
		self::assertTrue($phantom->isScaredByCats());
		$cat->teleport(new Vector3(20.5, 1, 0.5));
		self::assertFalse($phantom->isScaredByCats(), "beyond 16 blocks");
	}

	// -------------------------------------------------------------------------------------------------------------
	// Cats: sleeping with their owner, morning gifts, sitting on blocks
	// -------------------------------------------------------------------------------------------------------------

	/**
	 * A bed with its head at (0, 1, 0) and its foot at (0, 1, 1).
	 */
	private function placeBed() : void{
		$this->set(0, 1, 1, VanillaBlocks::BED()->setFacing(Facing::NORTH)->setHead(false));
		$this->set(0, 1, 0, VanillaBlocks::BED()->setFacing(Facing::NORTH)->setHead(true));
		$head = $this->get(0, 1, 0);
		self::assertInstanceOf(Bed::class, $head);
		self::assertNotNull($head->getOtherHalf(), "a complete bed");
	}

	public function testCatSleepsWithItsOwnerAndBringsAGift() : void{
		$this->placeBed();
		$sleeping = true;
		$owner = $this->player(VanillaItems::AIR(), new Vector3(0.5, 1.2, 0.5), function() use (&$sleeping) : bool{ return $sleeping; }, new Vector3(0, 1, 0));
		$cat = new Cat($this->loc(6.5, 1, 4.5));
		$cat->setOwner($owner);
		$cat->spawnToAll();
		$this->world->setTime(World::TIME_NIGHT + 2000);

		for($i = 0; $i < 200 && !$cat->isSleepingWithOwner(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($cat->isSleepingWithOwner(), "the cat goes to sleep on the bed");
		self::assertSame(0, $cat->getPosition()->getFloorX());
		self::assertSame(1, $cat->getPosition()->getFloorZ(), "at the foot of the bed");
		self::assertGreaterThan(1.5, $cat->getPosition()->y, "on top of the bed");
		self::assertFalse($cat->hasGiftForOwner());

		//the owner wakes up (after the gift window, so that the gift waits for the next morning)
		$this->world->setTime(World::TIME_FULL * 2 + 2000);
		$sleeping = false;
		$this->runTicks(4);
		self::assertFalse($cat->isSleepingWithOwner());
		self::assertTrue($cat->hasGiftForOwner(), "on_wake_with_owner");
		$this->runTicks(20);
		self::assertTrue($cat->hasGiftForOwner(), "not the time for gifts");
		$this->world->setTime(World::TIME_FULL * 3);
		self::assertTrue(DropGiftForOwnerGoal::isGiftTime($this->world));
		for($i = 0; $i < 100 && $cat->hasGiftForOwner(); ++$i){
			$this->runTicks(1);
		}
		self::assertFalse($cat->hasGiftForOwner(), "one drop attempt");
	}

	public function testCatGiftOnlyInTheMorning() : void{
		$this->world->setTime(World::TIME_FULL + 600);
		self::assertTrue(DropGiftForOwnerGoal::isGiftTime($this->world));
		$this->world->setTime(World::TIME_FULL + 1201);
		self::assertFalse(DropGiftForOwnerGoal::isGiftTime($this->world));
		$this->world->setTime(World::TIME_NIGHT);
		self::assertFalse(DropGiftForOwnerGoal::isGiftTime($this->world));

		$owner = $this->player(VanillaItems::AIR(), new Vector3(1.5, 1, 0.5));
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$cat->setOwner($owner);
		$cat->setGiftForOwner(true);
		$cat->spawnToAll();
		$this->runTicks(40);
		self::assertTrue($cat->hasGiftForOwner(), "no gift at night: the cat keeps it for the morning");
		$copy = new Cat($this->loc(0.5, 1, 0.5), $cat->saveNBT());
		self::assertTrue($copy->hasGiftForOwner(), "saved");
		$copy->flagForDespawn();
	}

	public function testCatGiftLootAndChance() : void{
		$allowed = [
			ItemTypeIds::RABBIT_HIDE, ItemTypeIds::RABBIT_FOOT, ItemTypeIds::RAW_CHICKEN, ItemTypeIds::FEATHER,
			ItemTypeIds::ROTTEN_FLESH, ItemTypeIds::STRING, ItemTypeIds::PHANTOM_MEMBRANE
		];
		$seen = [];
		for($i = 0; $i < 500; ++$i){
			$type = Cat::rollGift()->getTypeId();
			self::assertTrue(in_array($type, $allowed, true));
			$seen[$type] = true;
		}
		self::assertCount(7, $seen, "every item of cat_gift.json shows up");

		$owner = $this->player(VanillaItems::AIR(), new Vector3(1.5, 1, 0.5));
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$cat->setOwner($owner);
		$cat->spawnToAll();
		$gifts = 0;
		for($attempt = 0; $attempt < 400; ++$attempt){
			$cat->setGiftForOwner(true);
			$entity = $cat->offerGift($owner);
			self::assertFalse($cat->hasGiftForOwner());
			if($entity !== null){
				++$gifts;
				$entity->flagForDespawn();
			}
		}
		self::assertGreaterThan(240, $gifts, "70% drop chance");
		self::assertLessThan(320, $gifts, "70% drop chance");
		self::assertNull($cat->offerGift($owner), "no gift pending");
	}

	public function testOrderedToSitCatDoesntGoToBed() : void{
		$this->placeBed();
		$owner = $this->player(VanillaItems::AIR(), new Vector3(0.5, 1.2, 0.5), fn() => true, new Vector3(0, 1, 0));
		$cat = new Cat($this->loc(4.5, 1, 4.5));
		$cat->setOwner($owner);
		$cat->setSitting(true);
		$cat->spawnToAll();
		$this->runTicks(100);
		self::assertFalse($cat->isSleepingWithOwner());
	}

	public function testCatSitsOnChestAndBlocksIt() : void{
		$this->set(3, 1, 0, VanillaBlocks::CHEST());
		self::assertTrue(Cat::isSeat($this->world, 3, 1, 0));
		$this->set(3, 1, 4, VanillaBlocks::FURNACE());
		self::assertFalse(Cat::isSeat($this->world, 3, 1, 4), "only lit furnaces");
		$this->set(3, 1, 4, VanillaBlocks::FURNACE()->setLit(true));
		self::assertTrue(Cat::isSeat($this->world, 3, 1, 4));
		self::assertFalse(Cat::isSeat($this->world, 0, 0, 0), "stone");

		$owner = $this->player(VanillaItems::AIR(), new Vector3(0.5, 1, -3.5));
		$cat = new Cat($this->loc(0.5, 1, 0.5));
		$cat->setOwner($owner);
		$cat->spawnToAll();
		for($i = 0; $i < 3000 && $cat->getSeat() === null; ++$i){
			$this->runTicks(1);		}
		$seat = $cat->getSeat();
		self::assertNotNull($seat, "the cat sits on the chest or the furnace");
		self::assertGreaterThan(1.8, $cat->getPosition()->y, "on top of the block");
		$chestCat = $cat;
		if(!$seat->equals(new Vector3(3, 1, 0))){
			$cat->setSittingOnBlock(new Vector3(3, 1, 0));
		}
		self::assertTrue(Cat::isCatSittingOn($this->world, 3, 1, 0));
		self::assertFalse(Cat::isCatSittingOn($this->world, 3, 1, 0, $chestCat), "except itself");

		$player = $this->createMock(Player::class);
		$player->expects(self::never())->method("setCurrentWindow");
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->get(3, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 1, 0.5), $player);

		$cat->setSittingOnBlock(null);
		self::assertFalse(Cat::isCatSittingOn($this->world, 3, 1, 0));
	}

	// -------------------------------------------------------------------------------------------------------------
	// Allay
	// -------------------------------------------------------------------------------------------------------------

	private function jukeboxAt(int $x, int $y, int $z) : Jukebox{
		$this->set($x, $y, $z, VanillaBlocks::JUKEBOX());
		$jukebox = $this->get($x, $y, $z);
		self::assertInstanceOf(Jukebox::class, $jukebox);
		return $jukebox;
	}

	public function testAllayDancesToAPlayingJukebox() : void{
		$near = new Allay($this->loc(3.5, 2, 0.5));
		$near->spawnToAll();
		$far = new Allay($this->loc(15.5, 2, 0.5));
		$far->spawnToAll();
		$jukebox = $this->jukeboxAt(0, 1, 0);
		$jukebox->insertRecord(VanillaItems::RECORD_CAT());
		$this->world->setBlock($jukebox->getPosition(), $jukebox);
		self::assertTrue($near->isDancing());
		self::assertFalse($far->isDancing(), "beyond 10 blocks");
		$this->runTicks(40);
		self::assertTrue($near->isDancing(), "still playing");

		$jukebox = $this->jukeboxAt(0, 1, 0);
		$jukebox->ejectRecord();
		$this->world->setBlock($jukebox->getPosition(), $jukebox);
		$this->runTicks(25);
		self::assertFalse($near->isDancing(), "the music stopped");
	}

	public function testDancingAllayDuplicatesWithAmethystShard() : void{
		$allay = new Allay($this->loc(3.5, 2, 0.5));
		$allay->spawnToAll();
		$player = $this->player(VanillaItems::AMETHYST_SHARD()->setCount(3), new Vector3(4.5, 1, 0.5));

		//not dancing: the shard is just given to it
		self::assertFalse($allay->canDuplicate());

		$allay->startDancing(new Vector3(0, 1, 0));
		$this->jukeboxAt(0, 1, 0);
		self::assertTrue($allay->canDuplicate());
		self::assertTrue($allay->onInteract($player, new Vector3(0, 0, 0)));
		$allays = $this->entitiesOf(Allay::class);
		self::assertCount(2, $allays, "duplicated");
		self::assertSame(2, $player->getInventory()->getItemInHand()->getCount(), "one shard used");
		self::assertNull($allay->getHeldItem(), "the shard isn't given to it");
		foreach($allays as $a){
			self::assertSame(Allay::DUPLICATION_COOLDOWN, $a->getDuplicationCooldown());
			self::assertTrue($a->isPersistent());
		}
		self::assertFalse($allay->canDuplicate(), "5 minute cooldown");

		//during the cooldown the shard is given to it like any item
		self::assertTrue($allay->onInteract($player, new Vector3(0, 0, 0)));
		self::assertCount(2, $this->entitiesOf(Allay::class));
		self::assertSame(ItemTypeIds::AMETHYST_SHARD, $allay->getHeldItem()?->getTypeId());

		$copy = new Allay($this->loc(0.5, 2, 0.5), $allay->saveNBT());
		self::assertSame(Allay::DUPLICATION_COOLDOWN, $copy->getDuplicationCooldown(), "saved");
		$copy->flagForDespawn();

		$allay->setDuplicationCooldown(10);
		$this->runTicks(12);
		self::assertSame(0, $allay->getDuplicationCooldown());
	}

	public function testAllayRemembersNoteBlocks() : void{
		$player = $this->player(VanillaItems::DIAMOND(), new Vector3(-6.5, 1, 0.5));
		$allay = new Allay($this->loc(5.5, 2, 0.5));
		$allay->spawnToAll();
		$idle = new Allay($this->loc(5.5, 2, 3.5)); //holds nothing
		$idle->spawnToAll();
		self::assertTrue($allay->onInteract($player, new Vector3(0, 0, 0)));
		self::assertNotNull($allay->getHeldItem());

		$this->set(0, 1, 0, VanillaBlocks::NOTE_BLOCK());
		$note = $this->get(0, 1, 0);
		self::assertInstanceOf(Note::class, $note);
		self::assertTrue($note->play());
		$heard = $allay->getNoteBlock();
		self::assertNotNull($heard, "heard the note block");
		self::assertTrue($heard->equals(new Vector3(0, 1, 0)));
		self::assertNull($idle->getNoteBlock(), "allays without an item don't care");

		//it stays near the note block rather than following the player
		$this->runTicks(80);
		self::assertLessThan(Allay::NOTE_BLOCK_STAY_DISTANCE + 1.5, $allay->getPosition()->distance(new Vector3(0.5, 2.5, 0.5)));

		//forgets it 30 seconds after the last note
		$this->runTicks(Allay::NOTE_BLOCK_MEMORY_TICKS);
		self::assertNull($allay->getNoteBlock());

		//wool in the way muffles the note
		for($y = 1; $y <= 4; ++$y){
			for($z = -2; $z <= 2; ++$z){
				$this->set(2, $y, $z, VanillaBlocks::WOOL());
			}
		}
		$allay->teleport(new Vector3(5.5, 2, 0.5));
		self::assertTrue($note->play());
		self::assertNull($allay->getNoteBlock(), "occluded by wool");
	}

	public function testAllayBringsItemsToTheNoteBlock() : void{
		$player = $this->player(VanillaItems::DIAMOND(), new Vector3(-12.5, 1, 0.5));
		$allay = new Allay($this->loc(3.5, 2, 0.5));
		$allay->spawnToAll();
		self::assertTrue($allay->onInteract($player, new Vector3(0, 0, 0)));
		$this->set(0, 1, 0, VanillaBlocks::NOTE_BLOCK());
		$note = $this->get(0, 1, 0);
		self::assertInstanceOf(Note::class, $note);
		$note->play();
		$this->world->dropItem(new Vector3(6.5, 1, 4.5), VanillaItems::DIAMOND()->setCount(3), new Vector3(0, 0, 0), 0);
		$delivered = false;
		for($i = 0; $i < 400 && !$delivered; ++$i){
			$this->runTicks(1);
			if($i % 100 === 0){
				$note->play();
			}
			foreach($this->entitiesOf(ItemEntity::class) as $item){
				if($item->getItem()->getTypeId() === ItemTypeIds::DIAMOND && $item->getPosition()->distance(new Vector3(0.5, 2, 0.5)) < 3.5 && $allay->getCollectedItem() === null){
					$delivered = true;
				}
			}
		}
		self::assertTrue($delivered, "the diamonds end up at the note block, not with the player 13 blocks away");
	}

	// -------------------------------------------------------------------------------------------------------------
	// Frogs
	// -------------------------------------------------------------------------------------------------------------

	public function testFrogFood() : void{
		$small = new Slime($this->loc(3.5, 1, 0.5), null, 1);
		$big = new Slime($this->loc(3.5, 1, 4.5), null, 2);
		$magma = new MagmaCube($this->loc(3.5, 1, 8.5), null, 1);
		self::assertTrue(Frog::isFood($small));
		self::assertFalse(Frog::isFood($big), "only size 1");
		self::assertTrue(Frog::isFood($magma));
		self::assertFalse(Frog::isFood(new Zombie($this->loc(0.5, 1, 0.5))));

		foreach([Frog::VARIANT_TEMPERATE => FroglightType::OCHRE, Frog::VARIANT_COLD => FroglightType::VERDANT, Frog::VARIANT_WARM => FroglightType::PEARLESCENT] as $variant => $type){
			$frog = new Frog($this->loc(0.5, 1, 0.5));
			$frog->setVariant($variant);
			$drop = $frog->getEatDrop($magma);
			self::assertNotNull($drop);
			$block = $drop->getBlock();
			self::assertInstanceOf(Froglight::class, $block);
			self::assertSame($type, $block->getFroglightType());
			self::assertSame(ItemTypeIds::SLIMEBALL, $frog->getEatDrop($small)?->getTypeId());
			$frog->flagForDespawn();
		}
		foreach([$small, $big, $magma] as $e){
			$e->flagForDespawn();
		}
	}

	public function testFrogEatsSmallMagmaCube() : void{
		$frog = new Frog($this->loc(0.5, 1, 0.5));
		$frog->setVariant(Frog::VARIANT_WARM);
		$frog->spawnToAll();
		$magma = new MagmaCube($this->loc(4.5, 1, 0.5), null, 1);
		$magma->spawnToAll();
		$sawTongue = false;
		for($i = 0; $i < 300 && $magma->isAlive() && !$magma->isFlaggedForDespawn() && !$magma->isClosed(); ++$i){
			$this->runTicks(1);
			$sawTongue = $sawTongue || $frog->isEatingMob();
		}
		self::assertTrue($magma->isClosed() || $magma->isFlaggedForDespawn(), "eaten");
		self::assertTrue($sawTongue, "EAT_MOB while the tongue is out");
		self::assertFalse($frog->isEatingMob());
		$this->runTicks(2);
		$froglights = array_filter($this->entitiesOf(ItemEntity::class), function(ItemEntity $e) : bool{
			$block = $e->getItem()->getBlock();
			return $block instanceof Froglight && $block->getFroglightType() === FroglightType::PEARLESCENT;
		});
		self::assertCount(1, $froglights, "a warm frog makes pearlescent froglights");
	}

	public function testFrogEatsSmallSlime() : void{
		$frog = new Frog($this->loc(0.5, 1, 0.5));
		$frog->spawnToAll();
		$slime = new Slime($this->loc(-3.5, 1, 0.5), null, 1);
		$slime->spawnToAll();
		for($i = 0; $i < 300 && !$slime->isClosed() && !$slime->isFlaggedForDespawn(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($slime->isClosed() || $slime->isFlaggedForDespawn(), "eaten");
		$this->runTicks(2);
		self::assertContains(ItemTypeIds::SLIMEBALL, $this->droppedItemTypes());
	}

	// -------------------------------------------------------------------------------------------------------------
	// Goats
	// -------------------------------------------------------------------------------------------------------------

	public function testGoatJumpComputation() : void{
		$goat = new Goat($this->loc(0.5, 1, 0.5));
		$goat->spawnToAll();
		$this->runTicks(2);
		$goal = $goat->getLongJumpGoal();
		self::assertNotNull($goal->computeJump($goat->getLocation(), new Vector3(5.5, 4, 0.5)));
		//a wall in the way
		for($y = 1; $y <= 12; ++$y){
			for($z = -3; $z <= 3; ++$z){
				$this->set(2, $y, $z, VanillaBlocks::STONE());
			}
		}
		self::assertNull($goal->computeJump($goat->getLocation(), new Vector3(5.5, 4, 0.5)), "blocked by the wall");
	}

	public function testGoatJumpsOntoAHighBlock() : void{
		//a plateau 3 blocks high for x >= 4
		for($x = 4; $x <= 14; ++$x){
			for($z = -12; $z <= 12; ++$z){
				for($y = 1; $y <= 3; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
				}
			}
		}
		$goat = new Goat($this->loc(0.5, 1, 0.5));
		$goat->spawnToAll();
		$sawJump = false;
		for($i = 0; $i < 600 && (!$sawJump || $goat->isLongJumping()); ++$i){
			if($i % 20 === 0 && !$sawJump){
				$goat->getLongJumpGoal()->resetCooldown();
			}
			$this->runTicks(1);
			$sawJump = $sawJump || $goat->isLongJumping();		}
		self::assertTrue($sawJump, "the goat leaps (JUMP_GOAL_JUMP)");
		self::assertFalse($goat->isLongJumping(), "landed");
		self::assertEqualsWithDelta(4.0, $goat->getPosition()->y, 0.01, "the goat stands on the plateau");
		self::assertGreaterThanOrEqual(3.5, $goat->getPosition()->x);
		self::assertTrue($goat->isOnGround());
		self::assertSame(10.0, $goat->getHealth(), "no fall damage");
	}
}
