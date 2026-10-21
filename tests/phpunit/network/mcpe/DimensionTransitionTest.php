<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\world\dimension\DimensionSettings;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\hud\LoadingScreenType;
use pocketmine\event\player\PlayerDimensionChangeEvent;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\EventPriority;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
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
	private array $states = [];
	private RegisteredListener $listener;
	private ?\Closure $onSend = null;
	private array $ackResolvers = [];
	private DimensionManager $dimensionManager;

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
		$manager = $this->dimensionManager = (new \ReflectionClass(DimensionManager::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(DimensionManager::class, "dimensions"))->setValue($manager, new Dimensions($server, [1 => "world_nether", 2 => "world_the_end"]));
		(new \ReflectionProperty(DimensionManager::class, "settings"))->setValue($manager, DimensionSettings::fromArray([]));
		$server->method("getDimensionManager")->willReturn($manager);
		$this->listener = new RegisteredListener(function(PlayerDimensionChangeEvent $event) : void{ $this->states[] = $event; }, EventPriority::NORMAL, $this->createMock(Plugin::class), true, new TimingsHandler("dimension test"));
		HandlerListManager::global()->getListFor(PlayerDimensionChangeEvent::class)->register($this->listener);
		$this->session = $this->createPartialMock(NetworkSession::class, ["sendDataPacket", "sendDataPacketWithReceipt", "queueCompressed", "syncWorldTime", "syncGameRules", "syncWorldDifficulty", "syncWorldSpawnPoint"]);
		(new \ReflectionProperty(NetworkSession::class, "player"))->setValue($this->session, $this->player);
		(new \ReflectionProperty(NetworkSession::class, "server"))->setValue($this->session, $server);
		(new \ReflectionProperty(NetworkSession::class, "logger"))->setValue($this->session, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		(new \ReflectionProperty(NetworkSession::class, "compressor"))->setValue($this->session, ZlibCompressor::getInstance());
		\GlobalLogger::set($this->createMock(\Logger::class));
		$this->session->method("sendDataPacket")->willReturnCallback(function($packet) : bool{
			$this->wire[] = $packet;
			if($this->onSend !== null && ($this->onSend)($packet) === false){ return false; }
			return true;
		});
		$this->session->method("sendDataPacketWithReceipt")->willReturnCallback(function($packet){
			$this->wire[] = $packet;
			$this->ackResolvers[] = $resolver = new \pocketmine\promise\PromiseResolver();
			return $resolver->getPromise();
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
	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll($this->listener);
	}

	private function sendArrival() : void{
		$cx = $this->position->getFloorX() >> 4;
		$cz = $this->position->getFloorZ() >> 4;
		for($x = $cx - 1; $x <= $cx + 1; ++$x){ for($z = $cz - 1; $z <= $cz + 1; ++$z){ $this->send($this->world, $x, $z); } }
	}

	public function testModernTransitionNeedsMatchingScreenStopAfterServerAck() : void{
		$this->begin(1);
		$id = $this->session->getDimensionTransition()->getLoadingScreenId();
		self::assertNotNull($id);
		$this->session->onClientLoadingScreen(LoadingScreenType::START_LOADING_SCREEN, $id);
		$this->sendArrival();
		self::assertNotNull($this->session->getDimensionTransition());
		$this->session->onClientDimensionChangeAck();
		self::assertNotNull($this->session->getDimensionTransition());
		$this->session->onClientLoadingScreen(LoadingScreenType::STOP_LOADING_SCREEN, $id + 1);
		$this->session->onClientLoadingScreen(LoadingScreenType::STOP_LOADING_SCREEN, null);
		self::assertNotNull($this->session->getDimensionTransition());
		$this->session->onClientLoadingScreen(LoadingScreenType::STOP_LOADING_SCREEN, $id);
		self::assertNull($this->session->getDimensionTransition());
		self::assertSame(["started", "screen_stopped"], array_map(fn($e) => $e->getState(), $this->states));
		self::assertNotNull($this->states[1]->getTransition()->getScreenStoppedAt());
		$this->session->onClientLoadingScreen(LoadingScreenType::STOP_LOADING_SCREEN, $id);
		self::assertCount(2, $this->states);
	}

	public function testLegacyAckDoesNotCompleteBeforeServerAckAndIsLabelledAsAckOnly() : void{
		(new \ReflectionProperty(NetworkSession::class, "protocolId"))->setValue($this->session, ProtocolInfo::PROTOCOL_1_20_0);
		$this->begin(1);
		self::assertNull($this->session->getDimensionTransition()->getLoadingScreenId());
		$this->session->onClientDimensionChangeAck();
		$this->sendArrival();
		self::assertNotNull($this->session->getDimensionTransition());
		$this->session->onClientDimensionChangeAck();
		self::assertNull($this->session->getDimensionTransition());
		self::assertSame("client_acknowledged", $this->states[1]->getState());
		self::assertNull($this->states[1]->getTransition()->getScreenStoppedAt());
	}

	public function testReplacementRejectsOldScreenIdAndSnapshotMutations() : void{
		$this->begin(1);
		$old = $this->session->getDimensionTransition();
		$old->finish("tampered");
		self::assertNull($this->session->getDimensionTransition()->getResult());
		$this->world = $this->world("world_the_end");
		$this->position = new Position(32.5, 70, 48.5, $this->world);
		$this->begin(2);
		$new = $this->session->getDimensionTransition();
		self::assertNotSame($old->getId(), $new->getId());
		self::assertSame("replaced", $this->states[1]->getState());
		$this->sendArrival();
		$this->session->onClientLoadingScreen(LoadingScreenType::STOP_LOADING_SCREEN, $old->getLoadingScreenId());
		self::assertNotNull($this->session->getDimensionTransition());
		$this->session->onClientLoadingScreen(LoadingScreenType::STOP_LOADING_SCREEN, $new->getLoadingScreenId());
		self::assertNull($this->session->getDimensionTransition());
	}

	public function testTrackingDeadlineDoesNotForceAckOrDiscardUnsentTerrain() : void{
		$this->begin(1);
		$started = $this->session->getDimensionTransition()->getStartedAt();
		(new \ReflectionMethod(NetworkSession::class, "checkDimensionTransitionTimeout"))->invoke($this->session, $started + 120);
		self::assertNull($this->session->getDimensionTransition());
		self::assertSame("timed_out", $this->states[1]->getState());
		self::assertSame(0, $this->ackCount());
		self::assertCount(9, $this->session->getDimensionArrivalChunks());
		$this->sendArrival();
		self::assertSame(1, $this->ackCount());
		self::assertCount(2, $this->states);
	}

	public function testReceiptCountsOnlyUniqueArrivalChunks() : void{
		$transition = new DimensionTransition(1, 5, "world", "nether", 1, 0.0, [10 => true, 20 => true], true);
		$transition->recordReceipt(10);
		$transition->recordReceipt(10);
		$transition->recordReceipt(99);
		self::assertSame(1, $transition->getReceivedChunkCount());
		$transition->recordReceipt(20);
		self::assertSame(2, $transition->getReceivedChunkCount());
	}

	public function testSendingOldAckCannotAcknowledgeReentrantNewTransition() : void{
		$this->begin(1);
		$this->onSend = function($packet) : void{
			if($packet instanceof PlayerActionPacket && $packet->action === PlayerAction::DIMENSION_CHANGE_ACK){
				$this->onSend = null;
				$this->world = $this->world("world_the_end");
				$this->position = new Position(32.5, 70, 48.5, $this->world);
				$this->session->onEnterWorld();
			}
		};
		$this->sendArrival();
		self::assertSame("world_the_end", $this->session->getDimensionTransition()->getTargetWorld());
		self::assertNull($this->session->getDimensionTransition()->getServerAckAt());
		self::assertCount(9, $this->session->getDimensionArrivalChunks());
	}

	public function testOptInReceiptsUseRealQueueAndDoNotLeakIntoReplacement() : void{
		(new \ReflectionProperty(DimensionManager::class, "settings"))->setValue($this->dimensionManager, DimensionSettings::fromArray(["portal" => ["transition-diagnostics" => true]]));
		(new \ReflectionProperty(NetworkSession::class, "compressedQueue"))->setValue($this->session, new \SplQueue());
		$sender = $this->createMock(PacketSender::class);
		$receipts = [];
		$sender->method("send")->willReturnCallback(function($payload, $immediate, $receipt) use(&$receipts) : void{ $receipts[] = $receipt; });
		(new \ReflectionProperty(NetworkSession::class, "sender"))->setValue($this->session, $sender);
		$this->begin(1);
		$this->send($this->world, -1, -1);
		self::assertCount(1, $receipts);
		$this->world = $this->world("world_the_end");
		$this->position = new Position(32.5, 70, 48.5, $this->world);
		$this->begin(2);
		$this->session->handleAckReceipt($receipts[0]);
		self::assertSame(0, $this->session->getDimensionTransition()->getReceivedChunkCount());
		$this->send($this->world, 2, 3);
		$this->session->handleAckReceipt($receipts[1]);
		$this->session->handleAckReceipt($receipts[1]);
		self::assertSame(1, $this->session->getDimensionTransition()->getReceivedChunkCount());
		$this->sendArrival();
		self::assertCount(1, $this->ackResolvers);
		self::assertNotNull($this->session->getDimensionTransition()->getServerAckAt());
		$this->ackResolvers[0]->resolve(true);
		self::assertNotNull($this->session->getDimensionTransition());
	}

	public function testCancelledServerAckDoesNotReportItAsQueued() : void{
		$this->begin(1);
		$this->onSend = fn($packet) => !($packet instanceof PlayerActionPacket);
		$this->sendArrival();
		self::assertNull($this->session->getDimensionTransition());
		self::assertSame("send_cancelled", $this->states[1]->getState());
		self::assertNull($this->states[1]->getTransition()->getServerAckAt());
	}

	public function testDisconnectCannotStartReentrantTransitionFromPlugin() : void{
		$this->begin(1);
		$listener = new RegisteredListener(function(PlayerDimensionChangeEvent $event) : void{
			if($event->getState() === PlayerDimensionChangeEvent::DISCONNECTED){
				$this->world = $this->world("world_the_end");
				$this->session->onEnterWorld();
			}
		}, EventPriority::NORMAL, $this->createMock(Plugin::class), true, new TimingsHandler("disconnect test"));
		HandlerListManager::global()->getListFor(PlayerDimensionChangeEvent::class)->register($listener);
		try{
			(new \ReflectionProperty(NetworkSession::class, "disconnectGuard"))->setValue($this->session, true);
			(new \ReflectionMethod(NetworkSession::class, "finishDimensionTransition"))->invoke($this->session, PlayerDimensionChangeEvent::DISCONNECTED);
			self::assertNull($this->session->getDimensionTransition());
			self::assertSame(["started", "disconnected"], array_map(fn($e) => $e->getState(), $this->states));
		}finally{
			HandlerListManager::global()->unregisterAll($listener);
		}
	}

}


