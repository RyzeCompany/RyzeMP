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

namespace pocketmine\world\map;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\network\mcpe\handler\DeathPacketHandler;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\redstone\NullThreadSafeLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use function count;
use function sys_get_temp_dir;
use function uniqid;

/**
 * A client that joins with a map in its inventory asks for the map data while it is still loading (its inventory
 * ticks before it spawns) and doesn't ask again. The request used to be dropped by the spawn phase handlers, so the
 * map showed nothing after a rejoin; it is now kept and answered once the player has spawned.
 */
final class MapRejoinRequestTest extends TestCase{
	private string $path;
	private ?Server $serverInstance = null;
	private MapManager $manager;
	private int $tick = 1;

	/**
	 * @var ClientboundPacket[][]
	 * @phpstan-var array<int, list<ClientboundPacket>>
	 */
	private array $sent = [];

	protected function setUp() : void{
		$this->path = Path::join(sys_get_temp_dir(), uniqid("pm_map_rejoin_", true));
		$instance = (new \ReflectionProperty(Server::class, "instance"))->getValue();
		$this->serverInstance = $instance instanceof Server ? $instance : null;
		$server = $this->createMock(Server::class);
		$server->method("getOnlinePlayers")->willReturn([]);
		(new \ReflectionProperty(Server::class, "instance"))->setValue(null, $server);

		$store = new MapDataStore($this->path);
		self::assertSame(1, $store->allocateId());
		$pixels = "";
		for($i = 0; $i < MapData::SIZE * MapData::SIZE; ++$i){
			$pixels .= "\x7f\xb2\x38\xff";
		}
		$store->save(new MapData(1, "world", 0, 0, 0, false, -1, $pixels));
		$this->manager = new MapManager($store);
		MapManager::setInstance($this->manager);
	}

	protected function tearDown() : void{
		MapManager::reset();
		(new \ReflectionProperty(Server::class, "instance"))->setValue(null, $this->serverInstance);
		(new Filesystem())->remove($this->path);
	}

	private function createPlayer(bool $spawned, bool &$connected) : Player{
		$player = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->spawned = $spawned;
		$player->method("isConnected")->willReturnCallback(function() use (&$connected) : bool{ return $connected; });
		$session = $this->createMock(NetworkSession::class);
		$id = count($this->sent);
		$this->sent[$id] = [];
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) use ($id) : bool{
			$this->sent[$id][] = $packet;
			return true;
		});
		$player->method("getNetworkSession")->willReturn($session);
		return $player;
	}

	private function tick(int $count = 1) : void{
		for($i = 0; $i < $count; ++$i){
			$this->manager->tick($this->tick++);
		}
	}

	/**
	 * @param ClientboundPacket[] $packets
	 * @phpstan-param list<ClientboundPacket> $packets
	 */
	private static function assertFullMap(array $packets, int $mapId) : void{
		self::assertCount(2, $packets);
		[$creation, $data] = $packets;
		self::assertInstanceOf(ClientboundMapItemDataPacket::class, $creation);
		self::assertSame([$mapId], $creation->parentMapIds);
		self::assertInstanceOf(ClientboundMapItemDataPacket::class, $data);
		self::assertSame($mapId, $data->mapId);
		self::assertNotNull($data->colors);
		self::assertSame(MapData::SIZE, $data->colors->getWidth());
		self::assertSame(MapData::SIZE, $data->colors->getHeight());
	}

	public function testRequestWhileSpawningIsAnsweredAfterSpawn() : void{
		$connected = true;
		$player = $this->createPlayer(false, $connected);
		$this->manager->handleMapInfoRequest($player, 1);
		$this->tick(5);
		self::assertSame([], $this->sent[0], "nothing is sent before the player has spawned");

		$player->spawned = true;
		$this->tick(2);
		self::assertSame([], $this->sent[0], "answered a little after the spawn, not right away");
		$this->tick();
		self::assertFullMap($this->sent[0], 1);

		$this->tick(20);
		self::assertCount(2, $this->sent[0], "answered once");
	}

	public function testRequestOfSpawnedPlayerIsAnsweredRightAway() : void{
		$connected = true;
		$player = $this->createPlayer(true, $connected);
		$this->manager->handleMapInfoRequest($player, 1);
		self::assertFullMap($this->sent[0], 1);
	}

	public function testUnknownMapIsNotKept() : void{
		$connected = true;
		$player = $this->createPlayer(false, $connected);
		$this->manager->handleMapInfoRequest($player, 2);
		$this->manager->handleMapInfoRequest($player, -5);
		self::assertSame([], (fn() => $this->pendingRequests)->call($this->manager));
	}

	public function testRequestsOfPlayersThatLeftAreDropped() : void{
		$connected = true;
		$player = $this->createPlayer(false, $connected);
		$this->manager->handleMapInfoRequest($player, 1);
		self::assertCount(1, (fn() => $this->pendingRequests)->call($this->manager));
		$connected = false;
		$this->tick();
		self::assertSame([], (fn() => $this->pendingRequests)->call($this->manager));
		self::assertSame([], $this->sent[0]);
	}

	public function testViewersThatLeftAreForgotten() : void{
		$connected = true;
		$player = $this->createPlayer(true, $connected);
		$this->manager->handleMapInfoRequest($player, 1);
		self::assertCount(1, (fn() => $this->viewers[1])->call($this->manager));
		$connected = false;
		$this->tick(10); //decorations are updated every 5 ticks
		self::assertSame([], (fn() => $this->viewers[1])->call($this->manager));
		self::assertSame([], (fn() => $this->lastFullSend[1] ?? [])->call($this->manager));
	}

	public function testSpawnPhaseHandlersAcceptMapRequests() : void{
		$connected = true;
		$player = $this->createPlayer(false, $connected);
		$packet = MapInfoRequestPacket::create(1, []);

		$spawnResponse = new SpawnResponsePacketHandler(function() : void{}, $player);
		self::assertTrue($packet->handle($spawnResponse));
		self::assertCount(1, (fn() => $this->pendingRequests)->call($this->manager));
		//without the player (constructed the old way) the request is not handled, as before
		self::assertFalse($packet->handle(new SpawnResponsePacketHandler(function() : void{})));

		foreach([PreSpawnPacketHandler::class, SpawnResponsePacketHandler::class, DeathPacketHandler::class] as $class){
			self::assertSame($class, (new \ReflectionMethod($class, "handleMapInfoRequest"))->getDeclaringClass()->getName(), "$class handles MapInfoRequestPacket");
		}
	}
}
