<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\player\ChunkSelector;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\dimension\DimensionManager;
use pocketmine\world\dimension\Dimensions;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldTimings;

final class DimensionTransitionTest extends TestCase{

	private NetworkSession $session;
	private World $world;
	private Player $player;
	private Position $position;
	private array $wire = [];

	protected function setUp() : void{
		$this->world = $this->world("world_nether");
		$this->position = new Position(-0.5, 36, -3.5, $this->world);
		$this->player = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($this->player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($this->player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$this->player->method("getWorld")->willReturnCallback(fn() => $this->world);
		$this->player->method("getPosition")->willReturnCallback(fn() => $this->position);
		$this->player->method("getLocation")->willReturnCallback(fn() => Location::fromObject($this->position, $this->world));
		$this->player->method("getId")->willReturn(123);
		$server = $this->createMock(Server::class);
		$manager = (new \ReflectionClass(DimensionManager::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(DimensionManager::class, "dimensions"))->setValue($manager, new Dimensions($server, [1 => "world_nether", 2 => "world_the_end"]));
		$server->method("getDimensionManager")->willReturn($manager);
		$this->session = $this->createPartialMock(NetworkSession::class, ["sendDataPacket", "queueCompressed", "syncWorldTime", "syncGameRules", "syncWorldDifficulty", "syncWorldSpawnPoint"]);
		(new \ReflectionProperty(NetworkSession::class, "player"))->setValue($this->session, $this->player);
		(new \ReflectionProperty(NetworkSession::class, "server"))->setValue($this->session, $server);
		(new \ReflectionProperty(NetworkSession::class, "logger"))->setValue($this->session, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		(new \ReflectionProperty(NetworkSession::class, "compressor"))->setValue($this->session, ZlibCompressor::getInstance());
		\GlobalLogger::set($this->createMock(\Logger::class));
		$this->session->method("sendDataPacket")->willReturnCallback(function($packet) : bool{
			$this->wire[] = $packet;
			return true;
		});
		$this->session->method("queueCompressed")->willReturnCallback(function($bytes) : void{ $this->wire[] = $bytes; });
	}

	private function world(string $name) : World{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getFolderName")->willReturn($name);
		$world->method("getSpawnLocation")->willReturn(new Position(0, 64, 0, $world));
		$world->timings = new WorldTimings($world);
		return $world;
	}

	private function begin(int $dimension) : void{
		$this->session->onEnterWorld();
		self::assertInstanceOf(ChangeDimensionPacket::class, $this->wire[count($this->wire) - 1]);
	}

	private function send(World $world, int $x, int $z) : void{
		(new \ReflectionMethod(NetworkSession::class, "sendChunkPacket"))->invoke($this->session, "$x:$z", static function() : void{}, $world, $x, $z);
	}

	private function ackCount() : int{
		return count(array_filter($this->wire, fn($p) => $p instanceof PlayerActionPacket && $p->action === PlayerAction::DIMENSION_CHANGE_ACK));
	}

	public function testAcknowledgesOnlyAfterEntireArrivalTerrainIsQueued() : void{
		$this->begin(1);
		self::assertSame(0, $this->ackCount());
		$chunks = [];
		for($x = -2; $x <= 0; ++$x){
			for($z = -2; $z <= 0; ++$z){ $chunks[] = [$x, $z]; }
		}
		$last = array_pop($chunks);
		foreach($chunks as [$x, $z]){ $this->send($this->world, $x, $z); }
		$this->send($this->world, -2, -2); // duplicate cannot complete the footprint
		$this->send($this->world, 10, 10); // unrelated chunk cannot complete it
		$this->send($this->world("old_world"), ...$last); // stale-world completion cannot complete it
		self::assertSame(0, $this->ackCount());
		$this->send($this->world, ...$last);
		self::assertSame(1, $this->ackCount());
		self::assertSame("0:0", $this->wire[count($this->wire) - 2]);
		self::assertInstanceOf(PlayerActionPacket::class, $this->wire[count($this->wire) - 1]);
		$this->send($this->world, ...$last);
		self::assertSame(1, $this->ackCount());
	}

	public function testNewTransitionDiscardsOldFootprintAndWorksForEndAndReturn() : void{
		$this->begin(1);
		$old = $this->world;
		$this->send($old, -1, -1);
		foreach([2, 0] as $dimension){
			$this->world = $this->world($dimension === 2 ? "world_the_end" : "world");
			$this->position = new Position(32.5, 70, 48.5, $this->world);
			$this->begin($dimension);
			foreach([[-2, -2], [-2, -1], [-2, 0], [-1, -2], [-1, 0], [0, -2], [0, -1], [0, 0]] as [$x, $z]){ $this->send($old, $x, $z); }
			self::assertSame($dimension === 2 ? 0 : 1, $this->ackCount());
			for($x = 1; $x <= 3; ++$x){ for($z = 2; $z <= 4; ++$z){ $this->send($this->world, $x, $z); } }
			self::assertSame($dimension === 2 ? 1 : 2, $this->ackCount());
		}
	}

	public function testMinimumViewDistanceIncludesTheEntireRequiredFootprint() : void{
		$selected = array_fill_keys(iterator_to_array((new ChunkSelector())->selectChunks(2, -1, -1), false), true);
		for($x = -2; $x <= 0; ++$x){ for($z = -2; $z <= 0; ++$z){ self::assertArrayHasKey(World::chunkHash($x, $z), $selected); } }
	}

	public function testAnotherTeleportReplacesCancelledArrivalRequests() : void{
		$this->begin(1);
		$this->position = new Position(32.5, 70, 48.5, $this->world);
		$this->session->syncMovement($this->position, 0, 0, MovePlayerPacket::MODE_TELEPORT);
		for($x = -2; $x <= 0; ++$x){ for($z = -2; $z <= 0; ++$z){ $this->send($this->world, $x, $z); } }
		self::assertSame(0, $this->ackCount());
		for($x = 1; $x <= 3; ++$x){ for($z = 2; $z <= 4; ++$z){ $this->send($this->world, $x, $z); } }
		self::assertSame(1, $this->ackCount());
	}
}
