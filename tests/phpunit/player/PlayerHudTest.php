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
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PlayerLocationPacket;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\PlayerLocationType;
use pocketmine\promise\Promise;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\redstone\NullThreadSafeLogger;
use Ramsey\Uuid\Uuid;
use function fwrite;
use function getenv;
use function hrtime;
use function intdiv;
use function is_int;
use function min;
use function phpversion;
use function spl_object_id;
use function sprintf;
use function strlen;
use function version_compare;
use const PHP_INT_MAX;
use const STDERR;

/**
 * Player state and HUD: crawling, the locator bar and hardcore deaths.
 */
final class PlayerHudTest extends MobTestCase{

	/**
	 * Packets sent to each fake player, by entity ID.
	 *
	 * @var ClientboundPacket[][]
	 * @phpstan-var array<int, list<ClientboundPacket>>
	 */
	private array $packets = [];
	/** @var Player[] */
	private array $players = [];
	/**
	 * Entity ID of each fake player, by spl_object_id() of its session.
	 * @var int[]
	 */
	private array $sessionOwners = [];
	private int $savedNonStandardSessions = 0;

	protected function setUp() : void{
		parent::setUp();
		Timings::init();
		//shared broadcasts go through the sessions' broadcasters (recorded below) rather than the native send buffers
		$property = new \ReflectionProperty(NetworkSession::class, "nonStandardSessions");
		$value = $property->getValue();
		$this->savedNonStandardSessions = is_int($value) ? $value : 0;
		$property->setValue(null, $this->savedNonStandardSessions + 1);
	}

	protected function tearDown() : void{
		(new \ReflectionProperty(NetworkSession::class, "nonStandardSessions"))->setValue(null, $this->savedNonStandardSessions);
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
	 * A Player built without its constructor, standing in the test world, recording the packets it gets.
	 */
	private function player(Vector3 $pos, GameMode $mode = GameMode::SURVIVAL) : PlayerHudTestPlayer{
		$player = (new \ReflectionClass(PlayerHudTestPlayer::class))->newInstanceWithoutConstructor();
		$id = Entity::nextRuntimeId();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, $id);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($pos->x, $pos->y, $pos->z, $this->world, 0, 0));
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($pos->x - 0.3, $pos->y, $pos->z - 0.3, $pos->x + 0.3, $pos->y + 1.8, $pos->z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, $mode);
		(new \ReflectionProperty(Human::class, "uuid"))->setValue($player, Uuid::uuid4());
		$this->packets[$id] = [];
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) use ($id) : bool{
			$this->packets[$id][] = $packet;
			return true;
		});
		$this->sessionOwners[spl_object_id($session)] = $id;
		$session->method("getBroadcaster")->willReturn(new PlayerHudTestBroadcaster(function(NetworkSession $session, ClientboundPacket $packet) : void{
			$this->packets[$this->sessionOwners[spl_object_id($session)]][] = $packet;
		}));
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$this->world->addEntity($player);
		$this->players[] = $player;
		return $player;
	}

	/**
	 * Locator packets the player got since the last call, as "C<id>" (coordinates) or "H<id>" (hide).
	 *
	 * @return string[]
	 */
	private function takeLocations(Player $player) : array{
		$result = [];
		foreach($this->packets[$player->getId()] as $packet){
			if($packet instanceof PlayerLocationPacket){
				$result[] = ($packet->getType() === PlayerLocationType::PLAYER_LOCATION_COORDINATES ? "C" : "H") . $packet->getActorUniqueId();
			}
		}
		$this->packets[$player->getId()] = [];
		return $result;
	}

	/**
	 * Marks the target as spawned for the viewer (within its entity view distance), keyed like Entity::spawnTo().
	 */
	private static function spawnFor(Player $target, Player $viewer) : void{
		$property = new \ReflectionProperty(Entity::class, "hasSpawned");
		$viewers = $property->getValue($target);
		self::assertIsArray($viewers);
		$viewers[spl_object_id($viewer)] = $viewer;
		$property->setValue($target, $viewers);
		self::assertArrayHasKey(spl_object_id($viewer), $target->getViewers());
	}

	private static function despawnFor(Player $target, Player $viewer) : void{
		$property = new \ReflectionProperty(Entity::class, "hasSpawned");
		$viewers = $property->getValue($target);
		self::assertIsArray($viewers);
		unset($viewers[spl_object_id($viewer)]);
		$property->setValue($target, $viewers);
		self::assertArrayNotHasKey(spl_object_id($viewer), $target->getViewers());
	}

	private static function flags2(Player $player) : int{
		$collection = new EntityMetadataCollection();
		(new \ReflectionMethod(Living::class, "syncNetworkData"))->invoke($player, $collection);
		$property = $collection->getAll()[EntityMetadataProperties::FLAGS2] ?? null;
		return $property instanceof LongMetadataProperty ? $property->getValue() : 0;
	}

	public function testCrawlFlagsChangeTheSizeAndMetadata() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		self::assertFalse($player->isCrawling());
		self::assertSame(0, self::flags2($player) & (1 << (EntityMetadataFlags::CRAWLING - 64)));

		self::assertTrue($player->toggleCrawl(true));
		self::assertTrue($player->isCrawling());
		self::assertEqualsWithDelta(0.6, $player->size->getHeight(), 0.0001);
		self::assertEqualsWithDelta(0.6, $player->getBoundingBox()->maxY - $player->getBoundingBox()->minY, 0.0001);
		self::assertNotSame(0, self::flags2($player) & (1 << (EntityMetadataFlags::CRAWLING - 64)), "other players see the crawl");

		self::assertTrue($player->toggleCrawl(false));
		self::assertFalse($player->isCrawling());
		self::assertEqualsWithDelta(1.8, $player->size->getHeight(), 0.0001);
		self::assertEqualsWithDelta(1.62, $player->size->getEyeHeight(), 0.0001);
	}

	public function testCrawlingStopsSuffocationInAOneBlockGap() : void{
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$this->set($x, 2, $z, VanillaBlocks::STONE());
			}
		}
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		self::assertTrue($player->isInsideOfSolid(), "standing: the eyes are in the ceiling");
		$player->toggleCrawl(true);
		self::assertFalse($player->isInsideOfSolid(), "crawling: the eyes are in the gap");
	}

	public function testLocatorBarRuleIsOnAndSentToClients() : void{
		self::assertTrue(GameRules::getDefault(GameRules::LOCATOR_BAR));
		self::assertTrue(GameRules::isSentToClients(GameRules::LOCATOR_BAR));
		self::assertSame(GameRules::LOCATOR_BAR, GameRules::normalizeName("locatorBar"));
		$rules = NetworkSession::getClientGameRules($this->world);
		self::assertArrayHasKey("locatorbar", $rules);
		self::assertInstanceOf(BoolGameRule::class, $rules["locatorbar"]);
		self::assertTrue($rules["locatorbar"]->getValue());
	}

	public function testFarPlayersGetCoordinatesAndNearOnesDoNot() : void{
		$updater = new LocatorBarUpdater();
		$a = $this->player(new Vector3(0.5, 1, 0.5));
		$b = $this->player(new Vector3(20.5, 1, 0.5));
		$c = $this->player(new Vector3(-20.5, 1, 0.5));
		self::spawnFor($c, $a); //c is in a's view: a's client tracks it itself
		self::spawnFor($a, $c);

		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));
		self::assertEqualsCanonicalizing(["C" . $a->getId(), "C" . $c->getId()], $this->takeLocations($b));
		self::assertSame(["C" . $b->getId()], $this->takeLocations($c));

		//nobody moved: nothing is sent again
		$updater->update([$this->world]);
		self::assertSame([], $this->takeLocations($a));
		self::assertSame([], $this->takeLocations($b));

		//b moves a little (no update), then far enough
		$b->moveTo(new Vector3(20.9, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame([], $this->takeLocations($a));
		$b->moveTo(new Vector3(23.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));
		self::assertSame(["C" . $b->getId()], $this->takeLocations($c));

		//b comes into a's view: no packet (the client takes over), and none hiding it either
		self::spawnFor($b, $a);
		$updater->update([$this->world]);
		self::assertSame([], $this->takeLocations($a));
		//and out of view again, without moving: its coordinates are sent again
		self::despawnFor($b, $a);
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));
	}

	public function testHiddenPlayersAndRuleOff() : void{
		$updater = new LocatorBarUpdater();
		$a = $this->player(new Vector3(0.5, 1, 0.5));
		$b = $this->player(new Vector3(30.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));

		$b->setSneaking(true);
		$updater->update([$this->world]);
		self::assertSame(["H" . $b->getId()], $this->takeLocations($a), "sneaking players are hidden");
		$b->setSneaking(false);
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));

		$b->getArmorInventory()->setHelmet(VanillaBlocks::CARVED_PUMPKIN()->asItem());
		$updater->update([$this->world]);
		self::assertSame(["H" . $b->getId()], $this->takeLocations($a), "a carved pumpkin hides the player");
		$b->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a), "a helmet does not");
		$b->getArmorInventory()->setHelmet(VanillaBlocks::MOB_HEAD()->asItem());
		self::assertTrue(LocatorBarUpdater::isHidden($b));
		$b->getArmorInventory()->setHelmet(VanillaItems::AIR());

		$this->world->setGameRule(GameRules::LOCATOR_BAR, false);
		$updater->update([$this->world]);
		self::assertSame(["H" . $b->getId()], $this->takeLocations($a), "turning the rule off hides the waypoints");
		self::assertSame(["C" . $a->getId(), "H" . $a->getId()], $this->takeLocations($b), "a stood still the whole time: one waypoint, hidden with the rule");
		$b->moveTo(new Vector3(40.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame([], $this->takeLocations($a));
		self::assertSame([], $this->takeLocations($b));

		//leaving the world hides the waypoint
		$this->world->setGameRule(GameRules::LOCATOR_BAR, true);
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));
		$this->world->removeEntity($b);
		$updater->update([$this->world]);
		self::assertSame(["H" . $b->getId()], $this->takeLocations($a));
	}

	public function testPlayersHiddenWithHidePlayer() : void{
		$updater = new LocatorBarUpdater();
		$a = $this->player(new Vector3(0.5, 1, 0.5));
		$b = $this->player(new Vector3(30.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));
		self::assertSame(["C" . $a->getId()], $this->takeLocations($b));

		$a->hidePlayer($b);
		$updater->update([$this->world]);
		self::assertSame(["H" . $b->getId()], $this->takeLocations($a), "a vanished player leaves the bar of who hid it");
		self::assertSame([], $this->takeLocations($b), "the other way round is unchanged");
		$b->moveTo(new Vector3(40.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame([], $this->takeLocations($a), "and its moves are not sent");

		$a->showPlayer($b);
		$updater->update([$this->world]);
		self::assertSame(["C" . $b->getId()], $this->takeLocations($a));
	}

	public function testFarPlayersAreOnlyResentInAnotherChunk() : void{
		$updater = new LocatorBarUpdater();
		$a = $this->player(new Vector3(0.5, 1, 0.5));
		$near = $this->player(new Vector3(100.5, 1, 0.5));
		$far = $this->player(new Vector3(1000.5, 1, 0.5)); //chunk 62 spans x 992..1007
		$updater->update([$this->world]);
		self::assertEqualsCanonicalizing(["C" . $near->getId(), "C" . $far->getId()], $this->takeLocations($a));
		$this->takeLocations($near);
		$this->takeLocations($far);

		//both walk a few blocks: the near one is re-sent, the far one stayed in its chunk
		$near->moveTo(new Vector3(104.5, 1, 0.5));
		$far->moveTo(new Vector3(1004.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame(["C" . $near->getId()], $this->takeLocations($a));
		self::assertSame([], $this->takeLocations($near), "the far one is far from it too");

		//the far one enters another chunk
		$far->moveTo(new Vector3(1008.5, 1, 0.5));
		$updater->update([$this->world]);
		self::assertSame(["C" . $far->getId()], $this->takeLocations($a));
	}

	/**
	 * Every player within the far distance gets every other one at the start and after each move (the worst case for
	 * traffic).
	 */
	public function testManyPlayersAllMoving() : void{
		$players = [];
		for($i = 0; $i < 10; ++$i){
			$players[] = $this->player(new Vector3(($i % 5) * 50 + 0.5, 1, intdiv($i, 5) * 50 + 0.5));
		}
		$updater = new LocatorBarUpdater();
		for($round = 0; $round <= 3; ++$round){
			foreach($players as $i => $player){
				$player->moveTo(new Vector3(($i % 5) * 50 + 0.5 + $round * 2, 1, intdiv($i, 5) * 50 + 0.5));
			}
			$updater->update([$this->world]);
		}
		self::assertCount(4 * 9, $this->takeLocations($players[1]));
	}

	/**
	 * Cost of one locator update with 100 players far from each other and all moving into another chunk each time
	 * (every player gets a packet about every other one), through the real broadcast path: native send buffers,
	 * packets encoded once. MOB_BENCH=1.
	 */
	public function testUpdateCostWithManyPlayers() : void{
		if(getenv("MOB_BENCH") !== "1"){
			self::markTestSkipped("set MOB_BENCH=1");
		}
		$native = NetworkSession::getNativeSendBuffers();
		$version = phpversion("chunkutils2");
		if($native === null || $version === false || version_compare($version, "0.3.10", "<")){
			self::markTestSkipped("needs the native send buffers (chunkutils2 >= 0.3.10)");
		}
		(new \ReflectionProperty(NetworkSession::class, "nonStandardSessions"))->setValue(null, $this->savedNonStandardSessions);
		if(!NetworkSession::hasOnlyStandardSessions()){
			self::markTestSkipped("other sessions are open");
		}
		$count = 100;
		$players = [];
		$sendIds = [];
		for($i = 0; $i < $count; ++$i){
			$player = $this->player(new Vector3(($i % 10) * 200 + 0.5, 1, intdiv($i, 10) * 200 + 0.5));
			//a real session object (no constructor): cheap getSendId() and isConnected() like on a server
			$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
			$sendId = $native->openNew();
			(new \ReflectionProperty(NetworkSession::class, "sendId"))->setValue($session, $sendId);
			(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
			$players[] = $player;
			$sendIds[] = $sendId;
		}
		try{
			$updater = new LocatorBarUpdater();
			$updater->update([$this->world]);
			$best = PHP_INT_MAX;
			for($round = 1; $round <= 10; ++$round){
				foreach($players as $i => $player){
					$player->moveTo(new Vector3(($i % 10) * 200 + 0.5 + $round * 16, 1, intdiv($i, 10) * 200 + 0.5));
				}
				$start = hrtime(true);
				$updater->update([$this->world]);
				$best = min($best, hrtime(true) - $start);
			}
			self::assertNotSame("", $native->take($sendIds[0]), "the coordinates reached the send buffers");
			fwrite(STDERR, sprintf("[locator bench] %d far players all moving: %.3f ms per update (every %d ticks)\n", $count, $best / 1e6, LocatorBarUpdater::UPDATE_INTERVAL));

			//walking speed: about 4 blocks per update
			foreach($sendIds as $sendId){
				$native->take($sendId);
			}
			$walk = PHP_INT_MAX;
			$walkTotal = 0;
			for($round = 1; $round <= 10; ++$round){
				foreach($players as $i => $player){
					$player->moveTo(new Vector3(($i % 10) * 200 + 200.5 + $round * 4, 1, intdiv($i, 10) * 200 + 0.5));
				}
				$start = hrtime(true);
				$updater->update([$this->world]);
				$elapsed = hrtime(true) - $start;
				$walk = min($walk, $elapsed);
				$walkTotal += $elapsed;
			}
			$bytes = 0;
			foreach($sendIds as $sendId){
				$bytes += strlen($native->take($sendId));
			}
			fwrite(STDERR, sprintf("[locator bench] %d spread players walking: %.3f ms per update (mean %.3f ms), %d bytes queued per update\n", $count, $walk / 1e6, $walkTotal / 1e7, intdiv($bytes, 10)));
		}finally{
			foreach($sendIds as $sendId){
				$native->close($sendId);
			}
			foreach($players as $player){
				(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, null);
			}
		}
	}

	public function testLonePlayerGetsNothing() : void{
		$updater = new LocatorBarUpdater();
		$a = $this->player(new Vector3(0.5, 1, 0.5));
		for($i = 0; $i < 3; ++$i){
			$a->moveTo(new Vector3(0.5 + $i * 10, 1, 0.5));
			$updater->update([$this->world]);
		}
		self::assertSame([], $this->takeLocations($a));
	}

	public function testHardcoreDeathMakesASpectatorWhereThePlayerDied() : void{
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		/** @var Server&MockObject $server */
		$server->method("isHardcore")->willReturn(true);

		$player = $this->player(new Vector3(7.3, 1, -3.6));
		$player->setDeathPosition(new Vector3(7.3, 1.5, -3.6));
		$player->respawn();
		self::assertSame(GameMode::SPECTATOR, $player->getGamemode());
		self::assertNotNull($player->respawnedAt, "respawned instead of kicked and banned");
		self::assertSame([7.0, 1.5, -4.0], [$player->respawnedAt->x, $player->respawnedAt->y, $player->respawnedAt->z]);
		self::assertSame($this->world, $player->respawnedAt->getWorld());

		//the next respawn (not a hardcore death) uses the normal rules again
		self::assertFalse((new \ReflectionProperty(Player::class, "hardcoreRespawn"))->getValue($player));
	}
}

/**
 * Records where the player would respawn instead of running the whole respawn (teleport, network sync).
 */
final class PlayerHudTestPlayer extends Player{
	public ?Position $respawnedAt = null;

	public function moveTo(Vector3 $pos) : void{
		$this->setPosition($pos);
	}

	public function setGamemode(GameMode $gm) : bool{
		if($this->gamemode === $gm){
			return false;
		}
		$this->gamemode = $gm; //without the abilities and network sync
		return true;
	}

	protected function actuallyRespawn() : void{
		$promise = (new \ReflectionMethod(Player::class, "requestRespawnPosition"))->invoke($this);
		if(!$promise instanceof Promise){
			throw new \AssertionError("requestRespawnPosition() returns a promise");
		}
		/** @phpstan-var Promise<Position> $promise */
		$promise->onCompletion(function(Position $pos) : void{
			$this->respawnedAt = $pos;
		}, function() : void{});
	}
}

/**
 * Hands every broadcast packet to a callback, per recipient session.
 */
final class PlayerHudTestBroadcaster implements PacketBroadcaster{
	/**
	 * @phpstan-param \Closure(NetworkSession, ClientboundPacket) : void $onPacket
	 */
	public function __construct(private \Closure $onPacket){}

	public function broadcastPackets(array $recipients, array $packets) : void{
		foreach($recipients as $session){
			foreach($packets as $packet){
				($this->onPacket)($session, $packet);
			}
		}
	}
}
