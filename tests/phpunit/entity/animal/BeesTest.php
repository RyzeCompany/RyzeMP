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

use pocketmine\block\Beehive as BeehiveBlock;
use pocketmine\block\tile\Beehive as BeehiveTile;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function mt_srand;

/**
 * Bees (bee.json): anger duration and broadcast, perish countdown with panic, hive disturbance by harvesting and
 * breaking, sheltering at night and in the rain, home radius, flower list and feeding effects.
 */
final class BeesTest extends MobTestCase{
	private int $nextPlayerId = 7300000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
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
	 * A mocked survival player standing at the location, holding the item, made the only player of the world.
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
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, [$player]);
		return $player;
	}

	/**
	 * A bee hovering in place (no movement goals), so that only its targeting is tested.
	 */
	private function still(Bee $bee) : Bee{
		$bee->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		return $bee;
	}

	/**
	 * @return Bee[]
	 * @phpstan-return list<Bee>
	 */
	private function bees() : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Bee && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	private function placeHive(int $x, int $y, int $z, int $honey, int $bees) : BeehiveBlock{
		$this->world->setBlockAt($x, $y, $z, VanillaBlocks::BEEHIVE()->setFacing(Facing::NORTH)->setHoneyLevel($honey));
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(BeehiveTile::class, $tile);
		for($i = 0; $i < $bees; ++$i){
			$tile->addOccupant(Bee::getNetworkTypeId(), (new Bee($this->loc($x + 0.5, $y + 2, $z + 0.5)))->saveNBT(), BeehiveBlock::TICKS_WITH_NECTAR);
		}
		foreach($this->bees() as $bee){
			$bee->flagForDespawn();
		}
		$this->runTicks(1);
		$hive = $this->world->getBlockAt($x, $y, $z);
		self::assertInstanceOf(BeehiveBlock::class, $hive);
		return $hive;
	}

	private function makeStung(Bee $bee) : void{
		(new \ReflectionProperty(Bee::class, "stung"))->setValue($bee, true);
	}

	public function testAngerLasts25SecondsAndSpreadsWithin20Blocks() : void{
		$cow = new Cow($this->loc(0.5, 1, 3.5));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$hit = $this->still(new Bee($this->loc(0.5, 3, 0.5)));
		$near = $this->still(new Bee($this->loc(15.5, 3, 0.5)));
		$far = $this->still(new Bee($this->loc(0.5, 3, 22.5)));
		$stung = $this->still(new Bee($this->loc(5.5, 3, 0.5)));
		$this->makeStung($stung);

		$hit->attack(new EntityDamageByEntityEvent($cow, $hit, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertTrue($hit->isAngry());
		self::assertSame($cow, $hit->getAttackTarget());
		self::assertTrue($near->isAngry(), "bees within 20 blocks join in");
		self::assertSame($cow, $near->getAttackTarget());
		self::assertFalse($far->isAngry(), "bees farther than 20 blocks don't");
		self::assertFalse($stung->isAngry(), "pacified bees (they have stung) aren't alerted");

		$this->runTicks(Bee::ANGER_TICKS - 30);
		self::assertTrue($hit->isAngry(), "still angry before 25 seconds");
		$this->runTicks(40);
		self::assertFalse($hit->isAngry(), "calm after 25 seconds");
		self::assertNull($hit->getAttackTarget());
		self::assertNull($near->getAttackTarget());
	}

	public function testStungBeePanicsAndPerishesWithin60Seconds() : void{
		$cow = new Cow($this->loc(1.5, 1, 0.5));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$bee = new Bee($this->loc(0.5, 1.5, 0.5));
		$bee->becomeAngryAt($cow);
		self::assertTrue($bee->attackMelee($cow, 2.0));
		self::assertTrue($bee->hasStung());
		self::assertFalse($bee->isAngry());
		self::assertGreaterThanOrEqual(Bee::PERISH_MIN_TICKS, $bee->getPerishTicks());
		self::assertLessThanOrEqual(Bee::PERISH_MAX_TICKS, $bee->getPerishTicks());

		$panicking = false;
		for($i = 0; $i < 20 && !$panicking; ++$i){
			$this->runTicks(1);
			foreach($bee->getGoalSelector()->getRunningGoals() as $goal){
				$panicking = $panicking || $goal instanceof PanicGoal;
			}
		}
		self::assertTrue($panicking, "countdown_to_perish: behavior.panic (force)");

		$this->runTicks($bee->getPerishTicks() + 5);
		self::assertFalse($bee->isAlive());
	}

	public function testHarvestingWithoutCampfireReleasesAngryBees() : void{
		$hive = $this->placeHive(0, 3, 0, BeehiveBlock::MAX_HONEY_LEVEL, 2);
		$player = $this->player($this->loc(0.5, 1, -2.5), VanillaItems::GLASS_BOTTLE());
		$returned = [];
		self::assertTrue($hive->onInteract(VanillaItems::GLASS_BOTTLE(), Facing::NORTH, new Vector3(0.5, 0.5, 0), $player, $returned));
		self::assertCount(1, $returned);
		$tile = $this->world->getTileAt(0, 3, 0);
		self::assertInstanceOf(BeehiveTile::class, $tile);
		self::assertSame(0, $tile->getOccupantCount(), "the bees leave the disturbed hive");
		$bees = $this->bees();
		self::assertCount(2, $bees);
		foreach($bees as $bee){
			self::assertTrue($bee->isAngry());
			self::assertSame($player, $bee->getAttackTarget());
		}
	}

	public function testHarvestingWithCampfireBelowKeepsBeesCalm() : void{
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::CAMPFIRE()->setLit(true));
		$hive = $this->placeHive(0, 4, 0, BeehiveBlock::MAX_HONEY_LEVEL, 2);
		self::assertTrue($hive->isSmoked());
		$player = $this->player($this->loc(0.5, 1, -2.5), VanillaItems::SHEARS());
		self::assertTrue($hive->onInteract(VanillaItems::SHEARS(), Facing::NORTH, new Vector3(0.5, 0.5, 0), $player));
		$tile = $this->world->getTileAt(0, 4, 0);
		self::assertInstanceOf(BeehiveTile::class, $tile);
		self::assertSame(2, $tile->getOccupantCount());
		self::assertSame([], $this->bees());

		//a full block between the campfire and the hive stops the smoke
		$this->world->setBlockAt(0, 2, 0, VanillaBlocks::STONE());
		$hive = $this->world->getBlockAt(0, 4, 0);
		self::assertInstanceOf(BeehiveBlock::class, $hive);
		self::assertFalse($hive->isSmoked());
	}

	public function testBreakingHiveAngersBeesOutsideWithin16() : void{
		$hive = $this->placeHive(0, 1, 0, 0, 1);
		$player = $this->player($this->loc(0.5, 1, -2.5), VanillaItems::WOODEN_AXE());
		$outside = $this->still(new Bee($this->loc(6.5, 3, 0.5)));
		$outsideFar = $this->still(new Bee($this->loc(0.5, 3, 20.5)));
		$hive->onBreak(VanillaItems::WOODEN_AXE(), $player);
		self::assertTrue($outside->isAngry());
		self::assertSame($player, $outside->getAttackTarget());
		self::assertFalse($outsideFar->isAngry());
		$released = array_values(array_filter($this->bees(), fn(Bee $b) => $b !== $outside && $b !== $outsideFar));
		self::assertCount(1, $released);
		self::assertTrue($released[0]->isAngry());
	}

	public function testHiveKeepsBeesInsideAtNightAndInRain() : void{
		$this->placeHive(0, 1, 0, 0, 1);
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(BeehiveTile::class, $tile);

		$this->world->setTime(World::TIME_NIGHT + 100);
		$this->runTicks(BeehiveBlock::TICKS_WITH_NECTAR + 60);
		self::assertSame(1, $tile->getOccupantCount(), "no bee leaves at night");

		$this->world->setTime(1000);
		$this->world->getWeather()->setRain(100000);
		self::assertTrue(Bee::isShelterTime($this->world));
		$this->runTicks(60);
		self::assertSame(1, $tile->getOccupantCount(), "no bee leaves in the rain");

		$this->world->getWeather()->setClear(100000);
		$this->runTicks(BeehiveBlock::BEE_UPDATE_INTERVAL + 2);
		self::assertSame(0, $tile->getOccupantCount(), "it leaves as soon as it is clear day");
	}

	public function testBeeShelters() : void{
		$this->world->setBlockAt(0, 1, 5, VanillaBlocks::BEEHIVE());
		$bee = new Bee($this->loc(0.5, 2, 4.5));
		$bee->setHivePosition(new Vector3(0, 1, 5));
		(new \ReflectionProperty(Bee::class, "stayOutTicks"))->setValue($bee, 0);
		self::assertFalse($bee->wantsToGoHome());
		$this->world->getWeather()->setRain(100000);
		self::assertTrue($bee->wantsToGoHome(), "rain sends bees home");
		$this->world->getWeather()->setClear(100000);
		$this->world->setTime(World::TIME_NIGHT + 100);
		self::assertTrue($bee->wantsToGoHome(), "so does the night");
	}

	public function testWanderingStaysWithinHomeRadius() : void{
		$bee = new Bee($this->loc(0.5, 3, 0.5));
		self::assertSame(0, $bee->getHomeRadius());
		$bee->setHivePosition(new Vector3(0, 1, 0));
		self::assertSame(Bee::HOME_RADIUS, $bee->getHomeRadius());
		$home = $bee->getHomePos();
		self::assertNotNull($home);
		self::assertTrue($home->equals(new Vector3(0, 1, 0)));

		//at the edge of its home, its random targets lean back inside
		$bee->teleport(new Vector3(20.5, 3, 0.5));
		for($i = 0; $i < 40; ++$i){
			$pos = $bee->findWanderPos(8, 2);
			if($pos !== null){
				self::assertTrue($bee->isWithinHome($pos->x, $pos->y, $pos->z), "wander target $pos within 22 blocks");
			}
		}
	}

	public function testFlowerList() : void{
		foreach([
			VanillaBlocks::POPPY(), VanillaBlocks::WITHER_ROSE(), VanillaBlocks::FLOWERING_AZALEA(),
			VanillaBlocks::FLOWERING_AZALEA_LEAVES(), VanillaBlocks::MANGROVE_PROPAGULE(), VanillaBlocks::CHERRY_LEAVES(),
			VanillaBlocks::PINK_PETALS(), VanillaBlocks::WILDFLOWERS(), VanillaBlocks::CACTUS_FLOWER(),
			VanillaBlocks::CHORUS_FLOWER(), VanillaBlocks::SPORE_BLOSSOM(), VanillaBlocks::PITCHER_PLANT(),
			VanillaBlocks::TORCHFLOWER(), VanillaBlocks::SUNFLOWER(), VanillaBlocks::OPEN_EYEBLOSSOM()
		] as $block){
			self::assertTrue(Bee::isPollinatable($block), $block->getName());
		}
		foreach([
			VanillaBlocks::GOLDEN_DANDELION(), VanillaBlocks::CLOSED_EYEBLOSSOM(), VanillaBlocks::DOUBLE_TALLGRASS(),
			VanillaBlocks::LARGE_FERN(), VanillaBlocks::AZALEA(), VanillaBlocks::AZALEA_LEAVES(), VanillaBlocks::OAK_LEAVES()
		] as $block){
			self::assertFalse(Bee::isPollinatable($block), $block->getName());
		}
	}

	public function testFeedingEyeblossomPoisonsAndWitherRoseWithers() : void{
		$bee = new Bee($this->loc(0.5, 2, 0.5));
		$player = $this->player($this->loc(1.5, 1, 0.5), VanillaBlocks::OPEN_EYEBLOSSOM()->asItem()->setCount(2));
		self::assertTrue($bee->onInteract($player, new Vector3(0, 0, 0)));
		$poison = $bee->getEffects()->get(VanillaEffects::POISON());
		self::assertNotNull($poison);
		self::assertSame(500, $poison->getDuration());
		self::assertSame(1, $player->getInventory()->getItemInHand()->getCount());

		$player->getInventory()->setItemInHand(VanillaBlocks::WITHER_ROSE()->asItem()->setCount(2));
		self::assertTrue($bee->onInteract($player, new Vector3(0, 0, 0)));
		$wither = $bee->getEffects()->get(VanillaEffects::WITHER());
		self::assertNotNull($wither);
		self::assertSame(800, $wither->getDuration());
		self::assertSame(1, $player->getInventory()->getItemInHand()->getCount(), "one rose: wither and love");
	}
}
