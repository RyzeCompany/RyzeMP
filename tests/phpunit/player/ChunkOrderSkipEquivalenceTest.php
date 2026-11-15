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

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Random;
use pocketmine\utils\Utils;
use pocketmine\world\ChunkLoader;
use pocketmine\world\ChunkTicker;
use pocketmine\world\format\Chunk;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;
use function array_keys;
use function cos;
use function count;
use function is_array;
use function max;
use function min;
use function sin;
use function sort;
use function spl_object_id;
use const M_PI;

/**
 * A walking player's chunks are ordered again about 20 ticks after it moves. Player::doChunkRequests() skips that
 * order when it would do nothing (same world, centre chunk and radii, nothing invalidated, nothing queued). A plain
 * Player and a Player whose class overrides orderChunks() (which always gets its orders, as before) go through the
 * same random walk, chunk sends, forced unloads, chunk changes, forced orders, view distance, tick radius and
 * simulation distance changes and disconnections: after every tick they use, queue, tick and simulate the same chunks,
 * hold the same world registrations and made the same network calls in the same order.
 */
final class ChunkOrderSkipEquivalenceTest extends RedstoneTestCase{
	private const TICKS = 6000;
	/** Players stay within this distance of the origin (the loaded chunks reach the view distance beyond it) */
	private const BOUND = 48.0;

	/** View distance allowed by the server (the memory manager lowers it under memory pressure) */
	private int $viewCap = 4;
	private bool $connected = true;

	/**
	 * @var string[][] player number => network calls
	 * @phpstan-var array<int, list<string>>
	 */
	private array $calls = [];
	/**
	 * @var \Closure[][] player number => chunk sends waiting for completion: [chunkX, chunkZ, onCompletion]
	 * @phpstan-var array<int, list<array{int, int, \Closure() : void}>>
	 */
	private array $pendingSends = [];

	/**
	 * @var ChunkLoader[] player number => its chunk loader
	 * @phpstan-var array<int, ChunkLoader>
	 */
	private array $chunkLoaders = [];
	/**
	 * @var ChunkTicker[] player number => its chunk ticker
	 * @phpstan-var array<int, ChunkTicker>
	 */
	private array $chunkTickers = [];

	private ?TimingsHandler $orderTimings = null;
	private ?CountingTimingsHandler $orderCounter = null;

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => false]];
	}

	protected function getChunkRadius() : int{
		return 8;
	}

	protected function setUp() : void{
		parent::setUp();
		Timings::init();
		$this->orderTimings = Timings::$playerChunkOrder;
		Timings::$playerChunkOrder = $this->orderCounter = new CountingTimingsHandler("test");
	}

	protected function tearDown() : void{
		if($this->orderTimings !== null){
			Timings::$playerChunkOrder = $this->orderTimings;
		}
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, with a recording network session, set up the way the constructor sets
	 * up the chunk fields, and a view distance.
	 *
	 * @phpstan-param class-string<Player> $class
	 */
	private function player(string $class, int $number, Vector3 $spawn) : Player{
		$this->calls[$number] = [];
		$this->pendingSends[$number] = [];
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturnCallback(fn() => $this->connected);
		$session->method("syncViewAreaCenterPoint")->willReturnCallback(function(Vector3 $pos, int $viewDistance) use ($number) : void{
			$this->calls[$number][] = "center $pos->x $pos->y $pos->z $viewDistance";
		});
		$session->method("syncViewAreaRadius")->willReturnCallback(function(int $distance) use ($number) : void{
			$this->calls[$number][] = "radius $distance";
		});
		$session->method("stopUsingChunk")->willReturnCallback(function(int $x, int $z) use ($number) : void{
			$this->calls[$number][] = "stop $x $z";
		});
		$session->method("startUsingChunk")->willReturnCallback(function(int $x, int $z, \Closure $onCompletion) use ($number) : void{
			$this->calls[$number][] = "start $x $z";
			$this->pendingSends[$number][] = [$x, $z, $onCompletion];
		});
		$session->method("notifyTerrainReady")->willReturnCallback(function() use ($number) : void{
			$this->calls[$number][] = "terrain ready";
		});

		$server = $this->createMock(Server::class);
		$server->method("getAllowedViewDistance")->willReturnCallback(fn(int $distance) => max(2, min($this->viewCap, $distance)));
		$server->method("getConfigGroup")->willReturn($this->world->getServer()->getConfigGroup());

		$player = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		self::setProperty($player, Entity::class, "id", Entity::nextRuntimeId());
		self::setProperty($player, Entity::class, "server", $server);
		self::setProperty($player, Entity::class, "location", Location::fromObject($spawn, $this->world));
		self::setProperty($player, Entity::class, "closed", true); //never disposed like a real player
		self::setProperty($player, Player::class, "networkSession", $session);
		self::setProperty($player, Player::class, "logger", new NullThreadSafeLogger());
		self::setProperty($player, Player::class, "chunksPerTick", 4);
		self::setProperty($player, Player::class, "spawnThreshold", 5);
		//the per-tick chunk sending is compared, not the faster sending while joining
		self::setProperty($player, Player::class, "fastSpawn", false);
		self::setProperty($player, Player::class, "chunkSelector", new ChunkSelector());
		self::setProperty($player, Player::class, "chunkLoader", $this->chunkLoaders[$number] = new class implements ChunkLoader{});
		self::setProperty($player, Player::class, "chunkTicker", $this->chunkTickers[$number] = new ChunkTicker());
		//like the constructor: the spawn chunk is loaded and needed
		$spawnChunkX = $spawn->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$spawnChunkZ = $spawn->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		$this->world->registerChunkLoader($this->chunkLoaders[$number], $spawnChunkX, $spawnChunkZ, true);
		$this->world->registerChunkListener($player, $spawnChunkX, $spawnChunkZ);
		self::setProperty($player, Player::class, "usedChunks", [World::chunkHash($spawnChunkX, $spawnChunkZ) => UsedChunkStatus::NEEDED]);

		$player->setViewDistance(3);
		return $player;
	}

	/**
	 * @phpstan-param class-string $class
	 */
	private static function setProperty(object $object, string $class, string $property, mixed $value) : void{
		(new \ReflectionProperty($class, $property))->setValue($object, $value);
	}

	/**
	 * @phpstan-param class-string $class
	 */
	private static function getProperty(object $object, string $class, string $property) : mixed{
		return (new \ReflectionProperty($class, $property))->getValue($object);
	}

	/**
	 * What the player does when processMostRecentMovements() sees it moved horizontally.
	 */
	private static function moved(Player $player, Vector3 $to) : void{
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, Location::fromObject($to, $player->getWorld()));
		\Closure::bind(fn() => $this->scheduleChunkOrderAfterMove(), $player, Player::class)();
	}

	private static function forceOrder(Player $player) : void{
		(new \ReflectionProperty(Player::class, "nextChunkOrderRun"))->setValue($player, 0);
	}

	/**
	 * Runs doChunkRequests() and returns how many times orderChunks() did its work.
	 */
	private function tick(Player $player) : int{
		$counter = $this->orderCounter;
		self::assertNotNull($counter);
		$before = $counter->starts;
		$player->doChunkRequests();
		return $counter->starts - $before;
	}

	/**
	 * @return mixed[]
	 */
	private function state(Player $player, int $number) : array{
		$worldRegistrations = function(string $property, object $registered) : array{
			$hashes = [];
			$id = spl_object_id($registered);
			$registrations = self::getProperty($this->world, World::class, $property);
			self::assertIsArray($registrations);
			foreach(Utils::promoteKeys($registrations) as $hash => $objects){
				if(is_array($objects) && isset($objects[$id])){
					$hashes[] = $hash;
				}
			}
			sort($hashes);
			return $hashes;
		};
		return [
			"usedChunks" => $player->getUsedChunks(),
			"loadQueue" => self::getProperty($player, Player::class, "loadQueue"),
			"tickingChunks" => self::getProperty($player, Player::class, "tickingChunks"),
			"simulatingChunks" => self::getProperty($player, Player::class, "simulatingChunks"),
			"activeChunkGenerationRequests" => self::getProperty($player, Player::class, "activeChunkGenerationRequests"),
			"nextChunkOrderRun" => self::getProperty($player, Player::class, "nextChunkOrderRun"),
			"calls" => $this->calls[$number],
			"pendingSends" => count($this->pendingSends[$number]),
			"world ticking" => $worldRegistrations("registeredTickingChunks", $this->chunkTickers[$number]),
			"world simulating" => $worldRegistrations("registeredSimulatingChunks", $this->chunkTickers[$number]),
			"world loaders" => $worldRegistrations("chunkLoaders", $this->chunkLoaders[$number]),
			"world listeners" => $worldRegistrations("chunkListeners", $player),
		];
	}

	/**
	 * @phpstan-param class-string<Player> $class
	 * @return int[] orders run by the player of the given class, by the reference player
	 * @phpstan-return array{int, int}
	 */
	private function walk(int $seed, string $class) : array{
		$random = new Random($seed);
		$spawn = new Vector3(0.5, 1, 0.5);
		//player 0 has the skip, player 1 is the reference: its class overrides orderChunks(), so it orders as before
		$players = [$this->player($class, 0, $spawn), $this->player(OrderChunksOverridingPlayer::class, 1, $spawn)];
		$orders = [0, 0];

		$x = $spawn->x;
		$z = $spawn->z;
		$angle = 0.0;
		for($tick = 0; $tick < self::TICKS; ++$tick){
			//about one event every 45 ticks
			$event = $random->nextBoundedInt(3000);
			$usedChunks = array_keys($players[0]->getUsedChunks());
			$chunkHash = count($usedChunks) > 0 ? $usedChunks[$random->nextBoundedInt(count($usedChunks))] : null;
			$value = $random->nextBoundedInt(5);

			if($event < 15 && $chunkHash !== null){
				World::getXZ($chunkHash, $chunkX, $chunkZ);
				$chunk = $this->world->getChunk($chunkX, $chunkZ);
				self::assertNotNull($chunk);
				foreach($players as $player){
					$player->onChunkUnloaded($chunkX, $chunkZ, $chunk);
				}
			}elseif($event < 30 && $chunkHash !== null){
				World::getXZ($chunkHash, $chunkX, $chunkZ);
				$chunk = $this->world->getChunk($chunkX, $chunkZ);
				self::assertNotNull($chunk);
				foreach($players as $player){
					$player->onChunkChanged($chunkX, $chunkZ, $chunk);
				}
			}elseif($event < 37){
				//a plugin asking for an order
				foreach($players as $player){
					self::forceOrder($player);
				}
			}elseif($event < 42){
				//a teleport
				$x = max(-self::BOUND, min(self::BOUND, $x + ($random->nextFloat() * 2 - 1) * 40));
				$z = max(-self::BOUND, min(self::BOUND, $z + ($random->nextFloat() * 2 - 1) * 40));
				foreach($players as $player){
					(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($x, 1, $z, $this->world, 0, 0));
					self::forceOrder($player);
				}
			}elseif($event < 47){
				foreach($players as $player){
					$player->setViewDistance(2 + $value % 3);
				}
			}elseif($event < 52){
				$this->viewCap = 2 + $value % 3;
			}elseif($event < 57){
				$this->world->setChunkTickRadius($value);
			}elseif($event < 62){
				$this->world->setSimulationDistance($value);
			}elseif($event < 66){
				$this->connected = !$this->connected;
			}

			//walking like a player: 0.2-0.3 blocks per tick, turning now and then, standing still sometimes
			if($random->nextBoundedInt(4) !== 0){
				if($random->nextBoundedInt(20) === 0){
					$angle = $random->nextFloat() * 2 * M_PI;
				}
				$speed = 0.2 + $random->nextFloat() * 0.1;
				$x += cos($angle) * $speed;
				$z += sin($angle) * $speed;
				if($x < -self::BOUND || $x > self::BOUND || $z < -self::BOUND || $z > self::BOUND){
					$angle += M_PI;
					$x = max(-self::BOUND, min(self::BOUND, $x));
					$z = max(-self::BOUND, min(self::BOUND, $z));
				}
				foreach($players as $player){
					self::moved($player, new Vector3($x, 1, $z));
				}
			}

			//chunk sends complete in their own time, only while the chunk is still waiting for it (like NetworkSession)
			$deliveries = [];
			foreach($this->pendingSends[0] as $index => $_){
				$deliveries[$index] = $random->nextBoundedInt(3) === 0;
			}
			foreach($players as $number => $player){
				$pending = [];
				foreach($this->pendingSends[$number] as $index => [$chunkX, $chunkZ, $onCompletion]){
					if(!($deliveries[$index] ?? false)){
						$pending[] = [$chunkX, $chunkZ, $onCompletion];
					}elseif($this->connected && $player->getUsedChunkStatus($chunkX, $chunkZ) === UsedChunkStatus::REQUESTED_SENDING){
						$onCompletion();
					}
				}
				$this->pendingSends[$number] = $pending;
			}

			foreach($players as $number => $player){
				$orders[$number] += $this->tick($player);
			}

			self::assertSame($this->state($players[1], 1), $this->state($players[0], 0), "seed $seed, tick $tick");
		}
		return $orders;
	}

	public function testSameChunksAsOrderingEveryTime() : void{
		$skipped = 0;
		$reference = 0;
		for($seed = 1; $seed <= 3; ++$seed){
			[$orders, $referenceOrders] = $this->walk($seed, Player::class);
			self::assertLessThanOrEqual($referenceOrders, $orders);
			$skipped += $referenceOrders - $orders;
			$reference += $referenceOrders;
		}
		//most orders of a walking player change nothing
		self::assertGreaterThan($reference / 3, $skipped, "orders skipped out of $reference");
	}

	public function testClassesOverridingWhatTheOrderCallsAreNotSkipped() : void{
		[$orders, $referenceOrders] = $this->walk(4, IsConnectedOverridingPlayer::class);
		self::assertSame($referenceOrders, $orders);
	}
}

/**
 * A Player class overriding orderChunks(): its orders are never skipped, so it orders chunks like before.
 */
final class OrderChunksOverridingPlayer extends Player{
	protected function orderChunks() : void{
		parent::orderChunks();
	}
}

final class IsConnectedOverridingPlayer extends Player{
	public function isConnected() : bool{
		return parent::isConnected();
	}
}

/**
 * Counts how many times the timer is started.
 */
final class CountingTimingsHandler extends TimingsHandler{
	public int $starts = 0;

	public function startTiming() : void{
		++$this->starts;
	}

	public function stopTiming() : void{

	}
}
