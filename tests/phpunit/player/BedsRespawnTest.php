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

namespace pocketmine\player;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\Bed;
use pocketmine\block\RespawnAnchor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\lang\Translatable;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\promise\Promise;
use pocketmine\utils\Random;
use pocketmine\utils\TextFormat;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\SleepRules;
use pocketmine\world\SpawnLocator;
use pocketmine\world\WorldManager;
use function abs;
use function count;
use function ltrim;

/**
 * Beds, respawn anchors and respawning: stand-up spots, used up or broken spawn blocks, sleeping rules, spawn radius.
 */
final class BedsRespawnTest extends MobTestCase{

	/**
	 * Translation keys sent to each fake player, by entity ID.
	 *
	 * @var string[][]
	 * @phpstan-var array<int, list<string>>
	 */
	private array $messages = [];
	/** @var Player[] */
	private array $players = [];

	protected function tearDown() : void{
		foreach($this->players as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, standing in the test world, recording the messages it gets.
	 */
	private function player(Vector3 $pos, GameMode $mode = GameMode::SURVIVAL, float $yaw = 0.0) : Player{
		$player = (new \ReflectionClass(BedsRespawnTestPlayer::class))->newInstanceWithoutConstructor();
		$id = Entity::nextRuntimeId();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, $id);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($pos->x, $pos->y, $pos->z, $this->world, $yaw, 0));
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($pos->x - 0.3, $pos->y, $pos->z - 0.3, $pos->x + 0.3, $pos->y + 1.8, $pos->z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, $mode);
		$this->messages[$id] = [];
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		$session->method("onChatMessage")->willReturnCallback(function(Translatable|string $message) use ($id) : void{
			$this->messages[$id][] = ltrim(TextFormat::clean($message instanceof Translatable ? $message->getText() : $message), "%"); //key without the colour prefix
		});
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$this->world->addEntity($player);
		$this->players[] = $player;
		return $player;
	}

	/**
	 * @return string[]
	 */
	private function messagesOf(Player $player) : array{
		return $this->messages[$player->getId()] ?? [];
	}

	/**
	 * Places a bed whose foot is at the position and whose head is north of it. Returns the head.
	 */
	private function bed(int $x, int $y, int $z) : Bed{
		$this->set($x, $y, $z, VanillaBlocks::BED()->setFacing(Facing::NORTH)->setHead(false));
		$this->set($x, $y, $z - 1, VanillaBlocks::BED()->setFacing(Facing::NORTH)->setHead(true));
		$head = $this->get($x, $y, $z - 1);
		self::assertInstanceOf(Bed::class, $head);
		return $head;
	}

	private function anchor(int $x, int $y, int $z, int $charges) : void{
		$this->set($x, $y, $z, VanillaBlocks::RESPAWN_ANCHOR()->setCharges($charges));
	}

	private function anchorCharges(int $x, int $y, int $z) : int{
		$anchor = $this->get($x, $y, $z);
		self::assertInstanceOf(RespawnAnchor::class, $anchor);
		return $anchor->getCharges();
	}

	/**
	 * @phpstan-param Promise<Position> $promise
	 */
	private static function resolved(Promise $promise) : Position{
		$result = null;
		$promise->onCompletion(function(Position $value) use (&$result) : void{
			$result = $value;
		}, function() : void{
			self::fail("the promise was rejected");
		});
		self::assertInstanceOf(Position::class, $result, "the promise resolves at once in loaded terrain");
		return $result;
	}

	/**
	 * Where the player would respawn now (uses anchor charges and clears invalid spawn points like a respawn).
	 */
	private static function respawnPosition(Player $player) : Position{
		$promise = (new \ReflectionMethod(Player::class, "requestRespawnPosition"))->invoke($player);
		self::assertInstanceOf(Promise::class, $promise);
		/** @phpstan-var Promise<Position> $promise */
		return self::resolved($promise);
	}

	private function useDefaultWorld() : void{
		$worldManager = $this->world->getServer()->getWorldManager();
		self::assertInstanceOf(MockObject::class, $worldManager);
		/** @var WorldManager&MockObject $worldManager */
		$worldManager->method("getDefaultWorld")->willReturn($this->world);
	}

	public function testRulesAndDefaults() : void{
		self::assertSame(100, $this->world->getGameRule(GameRules::PLAYERS_SLEEPING_PERCENTAGE));
		self::assertSame(10, $this->world->getGameRule(GameRules::SPAWN_RADIUS));
		self::assertTrue($this->world->getGameRule(GameRules::RESPAWN_BLOCKS_EXPLODE));
		self::assertSame(GameRules::SPAWN_RADIUS, GameRules::normalizeName("spawnRadius"));
	}

	public function testAnchorRespawnUsesAChargeAndStandsBesideIt() : void{
		$this->anchor(5, 1, 5, 2);
		$standUp = SpawnLocator::useSpawnBlock($this->world, new Vector3(5, 1, 5), 0);
		self::assertNotNull($standUp);
		self::assertSame(1, $this->anchorCharges(5, 1, 5));
		self::assertEquals(new Vector3(5, 1, 4), $standUp, "first free spot: north of the anchor, on the floor");
	}

	public function testEmptyOrBrokenAnchorIsNotValid() : void{
		$this->anchor(5, 1, 5, 0);
		self::assertNull(SpawnLocator::useSpawnBlock($this->world, new Vector3(5, 1, 5), 0));
		$this->set(5, 1, 5, VanillaBlocks::AIR());
		self::assertNull(SpawnLocator::useSpawnBlock($this->world, new Vector3(5, 1, 5), 0));
	}

	public function testObstructedAnchorKeepsItsCharges() : void{
		for($x = 4; $x <= 6; ++$x){
			for($y = 0; $y <= 3; ++$y){
				for($z = 4; $z <= 6; ++$z){
					$this->set($x, $y, $z, VanillaBlocks::STONE());
				}
			}
		}
		$this->anchor(5, 1, 5, 3);
		self::assertNull(SpawnLocator::useSpawnBlock($this->world, new Vector3(5, 1, 5), 0));
		self::assertSame(3, $this->anchorCharges(5, 1, 5));
	}

	public function testHarmfulSpotsComeLast() : void{
		$this->anchor(5, 1, 5, 1);
		$this->set(5, 0, 4, VanillaBlocks::MAGMA());
		$standUp = SpawnLocator::useSpawnBlock($this->world, new Vector3(5, 1, 5), 0);
		self::assertEquals(new Vector3(4, 1, 5), $standUp, "the magma spot north of the anchor is skipped");
	}

	public function testBedStandUpBesideTheBed() : void{
		$head = $this->bed(0, 1, 0);
		//looking south: the east side comes first
		self::assertEquals(new Vector3(1, 1, -1), SpawnLocator::findBedStandUpPosition($this->world, $head, 0));
		//looking east: the west side
		self::assertEquals(new Vector3(-1, 1, -1), SpawnLocator::findBedStandUpPosition($this->world, $head, -90));
		//the foot half gives the same answer
		$foot = $this->get(0, 1, 0);
		self::assertInstanceOf(Bed::class, $foot);
		self::assertEquals(new Vector3(1, 1, -1), SpawnLocator::findBedStandUpPosition($this->world, $foot, 0));
	}

	public function testBrokenBedIsNotValid() : void{
		$this->bed(0, 1, 0);
		self::assertNotNull(SpawnLocator::useSpawnBlock($this->world, new Vector3(0, 1, -1), 0));
		$this->set(0, 1, -1, VanillaBlocks::AIR());
		$this->set(0, 1, 0, VanillaBlocks::AIR());
		self::assertNull(SpawnLocator::useSpawnBlock($this->world, new Vector3(0, 1, -1), 0));
	}

	public function testSleepWindow() : void{
		self::assertFalse(SleepRules::canSleepAtTime(12541, false, false));
		self::assertTrue(SleepRules::canSleepAtTime(12542, false, false));
		self::assertTrue(SleepRules::canSleepAtTime(23459, false, false));
		self::assertFalse(SleepRules::canSleepAtTime(23460, false, false));
		self::assertTrue(SleepRules::canSleepAtTime(12010, true, false));
		self::assertTrue(SleepRules::canSleepAtTime(23991, true, false));
		self::assertFalse(SleepRules::canSleepAtTime(23992, true, false));
		self::assertTrue(SleepRules::canSleepAtTime(6000, true, true), "any time in a thunderstorm");
	}

	public function testRequiredSleepers() : void{
		self::assertSame(1, SleepRules::getRequiredSleepers(2, 50));
		self::assertSame(2, SleepRules::getRequiredSleepers(3, 50));
		self::assertSame(3, SleepRules::getRequiredSleepers(3, 100));
		self::assertSame(1, SleepRules::getRequiredSleepers(3, 0), "at least one sleeper");
		self::assertSame(4, SleepRules::getRequiredSleepers(3, 101), "over 100: never enough");
	}

	public function testMonstersNearby() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$bed = new Vector3(0, 1, 0);
		$zombie = new Zombie(new Location(9.5, 1, 0.5, $this->world, 0, 0));
		self::assertNull(SleepRules::findMonsterPreventingSleep($this->world, $bed, $player), "9 blocks away");
		$zombie->teleport(new Vector3(7.5, 1, 0.5));
		self::assertSame($zombie, SleepRules::findMonsterPreventingSleep($this->world, $bed, $player));
		$zombie->teleport(new Vector3(0.5, 7, 0.5));
		self::assertNull(SleepRules::findMonsterPreventingSleep($this->world, $bed, $player), "6 blocks above");
		$zombie->close();

		$piglin = new ZombifiedPiglin(new Location(3.5, 1, 0.5, $this->world, 0, 0));
		self::assertNull(SleepRules::findMonsterPreventingSleep($this->world, $bed, $player), "calm zombified piglin");
		$piglin->setAngryAt($player, 400);
		self::assertSame($piglin, SleepRules::findMonsterPreventingSleep($this->world, $bed, $player));
		$piglin->close();
	}

	public function testUsingABedByDaySetsTheSpawn() : void{
		$this->world->setTime(6000);
		$head = $this->bed(0, 1, 0);
		$player = $this->player(new Vector3(1.5, 1, 0.5));
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $player);
		self::assertTrue($player->hasValidCustomSpawn());
		self::assertTrue($player->getSpawn()->floor()->equals(new Vector3(0, 1, -1)));
		self::assertFalse($player->isSleeping());
		self::assertSame([KnownTranslationKeys::TILE_BED_RESPAWNSET, KnownTranslationKeys::TILE_BED_NOSLEEP], $this->messagesOf($player));

		//using it again doesn't repeat the message
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $player);
		self::assertSame([KnownTranslationKeys::TILE_BED_RESPAWNSET, KnownTranslationKeys::TILE_BED_NOSLEEP, KnownTranslationKeys::TILE_BED_NOSLEEP], $this->messagesOf($player));
	}

	public function testMonstersKeepSurvivalPlayersAwake() : void{
		$this->world->setTime(18000);
		$head = $this->bed(0, 1, 0);
		$zombie = new Zombie(new Location(5.5, 1, 0.5, $this->world, 0, 0));
		$player = $this->player(new Vector3(1.5, 1, 0.5));
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $player);
		self::assertFalse($player->isSleeping());
		self::assertTrue($player->hasValidCustomSpawn(), "the spawn point is still set");
		self::assertSame([KnownTranslationKeys::TILE_BED_RESPAWNSET, KnownTranslationKeys::TILE_BED_NOTSAFE], $this->messagesOf($player));

		$creative = $this->player(new Vector3(1.5, 1, -0.5), GameMode::CREATIVE);
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $creative);
		self::assertTrue($creative->isSleeping(), "creative players ignore monsters");
		$zombie->close();
	}

	public function testSleepingPercentageAndSpectators() : void{
		$this->world->setTime(18000);
		$head = $this->bed(0, 1, 0);
		$sleeper = $this->player(new Vector3(1.5, 1, 0.5));
		$awake = $this->player(new Vector3(-1.5, 1, 0.5));
		$this->player(new Vector3(-1.5, 1, 2.5), GameMode::SPECTATOR);

		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $sleeper);
		self::assertTrue($sleeper->isSleeping());
		$this->world->checkSleep();
		self::assertTrue($sleeper->isSleeping(), "1 of 2 players asleep is not enough at 100%");
		self::assertSame(18000, $this->world->getTimeOfDay());

		$this->world->setGameRule(GameRules::PLAYERS_SLEEPING_PERCENTAGE, 50);
		$this->world->checkSleep();
		self::assertFalse($sleeper->isSleeping(), "the night is skipped and the sleeper wakes up");
		self::assertSame(0, $this->world->getTimeOfDay());
		self::assertFalse($awake->isSleeping());
	}

	public function testNoTimeChangeWithoutDaylightCycle() : void{
		$this->world->setTime(18000);
		$this->world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, false);
		$head = $this->bed(0, 1, 0);
		$sleeper = $this->player(new Vector3(1.5, 1, 0.5));
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $sleeper);
		$this->world->checkSleep();
		self::assertFalse($sleeper->isSleeping());
		self::assertSame(18000, $this->world->getTimeOfDay());
		$this->world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, true);
	}

	public function testRespawnAtBrokenBedGoesToWorldSpawn() : void{
		$this->useDefaultWorld();
		$this->world->setGameRule(GameRules::SPAWN_RADIUS, 0);
		$this->world->setTime(6000);
		$head = $this->bed(0, 1, 4);
		$player = $this->player(new Vector3(1.5, 1, 4.5));
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $player);

		$pos = self::respawnPosition($player);
		self::assertEquals(new Vector3(1, 1, 3), $pos->asVector3(), "next to the bed");
		self::assertTrue($player->hasValidCustomSpawn());

		$this->set(0, 1, 3, VanillaBlocks::AIR());
		$this->set(0, 1, 4, VanillaBlocks::AIR());
		$pos = self::respawnPosition($player);
		self::assertSame(0, $pos->getFloorX());
		self::assertSame(0, $pos->getFloorZ());
		self::assertFalse($player->hasValidCustomSpawn(), "the spawn point is cleared");
		self::assertNotContains(KnownTranslationKeys::TILE_BED_NOTVALID, $this->messagesOf($player), "BDS sends no message");
	}

	public function testRespawnAtAnchorUsesCharges() : void{
		$this->useDefaultWorld();
		$this->world->setGameRule(GameRules::SPAWN_RADIUS, 0);
		$this->anchor(6, 1, 6, 1);
		$player = $this->player(new Vector3(6.5, 1, 7.5));
		$player->setSpawn(new Vector3(6, 1, 6));

		$pos = self::respawnPosition($player);
		self::assertEquals(new Vector3(6, 1, 5), $pos->asVector3());
		self::assertSame(0, $this->anchorCharges(6, 1, 6));

		$pos = self::respawnPosition($player);
		self::assertSame(0, $pos->getFloorX(), "out of charges: world spawn");
		self::assertFalse($player->hasValidCustomSpawn());
		self::assertContains(KnownTranslationKeys::TILE_RESPAWN_ANCHOR_NOTVALID, $this->messagesOf($player));
	}

	public function testPluginSpawnPointIsUsedAsItIs() : void{
		$this->useDefaultWorld();
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$player->setSpawn(new Vector3(8, 1, 8));
		$pos = self::respawnPosition($player);
		self::assertEquals(new Vector3(8, 1, 8), $pos->asVector3());
		self::assertTrue($player->hasValidCustomSpawn());
	}

	public function testSpawnRadius() : void{
		$this->world->setGameRule(GameRules::SPAWN_RADIUS, 0);
		$pos = self::resolved(SpawnLocator::requestWorldSpawn($this->world));
		self::assertEquals(new Vector3(0, 1, 0), $pos->asVector3());

		$this->world->setGameRule(GameRules::SPAWN_RADIUS, 6);
		$random = new Random(1234);
		$seen = [];
		for($i = 0; $i < 40; ++$i){
			$pos = self::resolved(SpawnLocator::requestWorldSpawn($this->world, $random));
			self::assertLessThanOrEqual(6, abs($pos->getFloorX()));
			self::assertLessThanOrEqual(6, abs($pos->getFloorZ()));
			self::assertSame(1.0, $pos->y, "on the floor");
			$seen[$pos->getFloorX() . ":" . $pos->getFloorZ()] = true;
		}
		self::assertGreaterThan(5, count($seen), "random spots");
	}

	public function testSurfaceSpawnAvoidsWaterAndHarm() : void{
		$this->set(10, 1, 10, VanillaBlocks::WATER());
		self::assertNull(SpawnLocator::findSurfaceSpawn($this->world, 10, 10));
		$this->set(11, 0, 10, VanillaBlocks::MAGMA());
		self::assertNull(SpawnLocator::findSurfaceSpawn($this->world, 11, 10));
		$this->set(12, 1, 10, VanillaBlocks::TALL_GRASS());
		self::assertEquals(new Vector3(12, 1, 10), SpawnLocator::findSurfaceSpawn($this->world, 12, 10));
	}

	public function testDamageWakesTheSleeper() : void{
		$this->world->setTime(18000);
		$head = $this->bed(0, 1, 0);
		$sleeper = $this->player(new Vector3(1.5, 1, 0.5));
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $sleeper);
		self::assertTrue($sleeper->isSleeping());
		$sleeper->attack(new EntityDamageEvent($sleeper, EntityDamageEvent::CAUSE_MAGIC, 1));
		self::assertFalse($sleeper->isSleeping());
	}

	public function testSleeperLiesInTheBedAndGetsUpBesideIt() : void{
		$this->world->setTime(18000);
		$head = $this->bed(0, 1, 0);
		$sleeper = $this->player(new Vector3(1.5, 1, 0.5));
		$head->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $sleeper);
		self::assertTrue($sleeper->isSleeping());
		//in the middle of the head block, with the eyes 0.90625 above it on the wire (BDS)
		$lying = $sleeper->getPosition();
		self::assertEqualsWithDelta(0.5, $lying->x, 0.0001);
		self::assertEqualsWithDelta(-0.5, $lying->z, 0.0001);
		self::assertEqualsWithDelta(1 + 0.90625, $sleeper->getOffsetPosition($lying)->y, 0.0001);

		//the client can't walk out of the bed
		$sleeper->handleMovement(new Vector3(0.5, $lying->y - 0.5, -0.5));
		self::assertEqualsWithDelta($lying->y, $sleeper->getPosition()->y, 0.0001);

		$sleeper->stopSleep();
		self::assertFalse($sleeper->isSleeping());
		//looking south: the east side of the head
		self::assertEquals(new Vector3(1.5, 1, -0.5), $sleeper->getPosition()->asVector3());
	}

	public function testRespawnBlocksExplodeOff() : void{
		$this->world->setGameRule(GameRules::RESPAWN_BLOCKS_EXPLODE, false);
		$this->anchor(3, 1, 3, 2);
		$player = $this->player(new Vector3(3.5, 1, 4.5));
		$anchor = $this->get(3, 1, 3);
		$anchor->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5), $player);
		self::assertSame(2, $this->anchorCharges(3, 1, 3), "the anchor outside the Nether doesn't explode");
		self::assertSame([KnownTranslationKeys::TILE_RESPAWN_ANCHOR_NOTVALID], $this->messagesOf($player));
	}
}

/**
 * A player without the hunger and attribute side effects of damage (not set up by the test).
 */
final class BedsRespawnTestPlayer extends Player{
	public function applyDamageModifiers(EntityDamageEvent $source) : void{
		//NOOP
	}

	protected function applyPostDamageEffects(EntityDamageEvent $source) : void{
		//NOOP
	}

	public function setHealth(float $amount) : void{
		//NOOP
	}
}
