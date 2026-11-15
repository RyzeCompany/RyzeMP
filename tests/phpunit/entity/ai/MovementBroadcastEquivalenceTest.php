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

use pmmp\encoding\ByteBufferWriter;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\Zombie;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\NativeSendBuffers;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Binary;
use pocketmine\utils\Random;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use function array_intersect_key;
use function array_values;
use function class_exists;
use function count;
use function floor;
use function implode;
use function intdiv;
use function is_int;
use function max;
use function method_exists;
use function spl_object_id;
use function sqrt;
use function strlen;
use const PHP_FLOAT_EPSILON;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Player::broadcastMovement() and Mob::broadcastMovement() pick the same viewers, deliver the same bytes to the same
 * sessions and leave the same "pending" flag as the loop they replaced (** 2 distances, a list of players and one of
 * send ids, then NetworkBroadcastUtils::broadcastPackets()), on every broadcast path: native send buffers (with the
 * native viewer selection of chunkutils2 >= 0.3.10, NativeSendBuffers::selectMovement(), and with the PHP loop),
 * packets above the compression threshold, a DataPacketSendEvent handler, sessions with other broadcasters, no native
 * send buffers.
 */
final class MovementBroadcastEquivalenceTest extends MobTestCase{
	/** no native send buffers */
	private const MODE_PHP = "php";
	/** the native send buffers (and their selectMovement() when they have it) */
	private const MODE_NATIVE = "native";
	/** the native send buffers, the PHP viewer loop (selectMovement() not used) */
	private const MODE_NATIVE_LOOP = "native-loop";
	/** native send buffers, but every batch is above the compression threshold */
	private const MODE_COMPRESSED = "compressed";
	/** native send buffers, but a DataPacketSendEvent handler is registered */
	private const MODE_EVENT = "event";
	/** native send buffers, but some session has a non-standard broadcaster: the viewers' two broadcasters are used */
	private const MODE_NON_STANDARD = "non-standard";

	private const SCENARIOS = 150;

	/**
	 * Distances (blocks) of movement-broadcast: full rate, half rate.
	 */
	private const DISTANCES = [[24, 48], [0, 0], [10, 10], [24, 24], [5, 100], [48, 24], [1, 2], [16, 32]];

	/**
	 * @var array[] broadcaster calls: broadcaster, numbers of the viewers of the target sessions, packet bytes
	 * @phpstan-var list<array{string, list<int>, list<string>}>
	 */
	private array $calls = [];
	/**
	 * @var int[] session object ID => viewer number
	 * @phpstan-var array<int, int>
	 */
	private array $viewerOfSession = [];
	/**
	 * @var PacketBroadcaster[]
	 * @phpstan-var list<PacketBroadcaster>
	 */
	private array $broadcasters = [];
	private ?ZlibCompressor $compressor = null;

	/**
	 * @var int[] how often each broadcast path was taken, to check that the scenarios cover them all
	 * @phpstan-var array<string, int>
	 */
	private array $coverage = [];

	protected function setUp() : void{
		parent::setUp();
		$test = $this;
		foreach(["A", "B"] as $name){
			$this->broadcasters[] = new class($test, $name) implements PacketBroadcaster{
				public function __construct(private MovementBroadcastEquivalenceTest $test, private string $name){}

				public function broadcastPackets(array $recipients, array $packets) : void{
					$this->test->record($this->name, $recipients, $packets);
				}
			};
		}
	}

	protected function tearDown() : void{
		NetworkSession::setNativeSendBuffersEnabled(true);
		NetworkBroadcastUtils::setNativeMovementSelectionEnabled(true);
		Mob::setMovementBroadcastDistances(null);
		if($this->compressor !== null){
			ZlibCompressor::setInstance($this->compressor);
			$this->compressor = null;
		}
		(new \ReflectionProperty(NetworkSession::class, "nonStandardSessions"))->setValue(null, 0);
		parent::tearDown();
	}

	/**
	 * @internal
	 * @param NetworkSession[]    $sessions
	 * @param ClientboundPacket[] $packets
	 */
	public function record(string $broadcaster, array $sessions, array $packets) : void{
		$viewers = [];
		foreach($sessions as $session){
			$viewers[] = $this->viewerOfSession[spl_object_id($session)];
		}
		$encoded = [];
		foreach($packets as $packet){
			$writer = new ByteBufferWriter();
			$packet->encode($writer, \pocketmine\network\mcpe\protocol\ProtocolInfo::CURRENT_PROTOCOL);
			$encoded[] = $writer->getData();
		}
		$this->calls[] = [$broadcaster, $viewers, $encoded];
	}

	/**
	 * A fake viewer: a Player that is never ticked or spawned, whose session's packets go to a recording broadcaster
	 * (or to the native send buffer $sendId).
	 */
	private function viewer(int $number, int $id, Vector3 $pos, int $sendId, bool $connected, bool $overridesGetId, PacketBroadcaster $broadcaster) : Player{
		$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(NetworkSession::class, "broadcaster"))->setValue($session, $broadcaster);
		(new \ReflectionProperty(NetworkSession::class, "sendId"))->setValue($session, $sendId);
		(new \ReflectionProperty(NetworkSession::class, "connected"))->setValue($session, $connected);
		if($overridesGetId){
			//a Player class overriding getId() (a plugin's, or a mock): its ID is the one spreading the skipped ticks
			$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(["getId"])->getMock();
			$player->method("getId")->willReturn($id * 7 + 3);
		}else{
			$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		}
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, $id);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, self::location($pos, $this->world));
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true); //never disposed like a real player
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$this->viewerOfSession[spl_object_id($session)] = $number;
		return $player;
	}

	/**
	 * A Location with exactly the coordinates of $pos, ints included (the constructor makes them floats; the loops
	 * compute with whatever the properties hold).
	 */
	private static function location(Vector3 $pos, World $world, float $yaw = 0.0, float $pitch = 0.0) : Location{
		$location = Location::fromObject($pos, $world, $yaw, $pitch);
		$location->x = $pos->x;
		$location->y = $pos->y;
		$location->z = $pos->z;
		return $location;
	}

	/**
	 * $pos with int coordinates (floored; huge ones stay floats).
	 */
	private static function intPosition(Vector3 $pos) : Vector3{
		$int = static fn(float|int $v) : float|int => is_int($v) || $v < -9.0e18 || $v > 9.0e18 ? $v : (int) floor($v);
		return new Vector3($int($pos->x), $int($pos->y), $int($pos->z));
	}

	/**
	 * Int viewer positions far enough from $pos for int arithmetic to overflow: a subtraction beyond PHP_INT_MAX, or
	 * squares (and their sums) beyond it, which become floats in the loops.
	 */
	private static function hugeIntPosition(Random $random, Vector3 $pos) : Vector3{
		$values = [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 3, PHP_INT_MIN + 7, 3037000499, 3037000500, -3037000500, 2147483648, -2147483648, 4294967296];
		$pick = static fn() : int => $values[$random->nextBoundedInt(count($values))];
		return match($random->nextBoundedInt(4)){
			0 => new Vector3($pick(), 64, 0),
			1 => new Vector3(0, $pick(), $pick()),
			2 => new Vector3($pick(), $pick(), $pick()),
			default => new Vector3(is_int($pos->x) ? $pos->x + 3037000499 : 3037000499, 2147483648, 0)
		};
	}

	/**
	 * The selection loop which Mob::broadcastMovement() and Player::broadcastMovement() ran before, verbatim apart
	 * from reading the viewers' location property by reflection (the test is outside Entity's scope; getLocation()
	 * would make a copy with float coordinates): ** 2 distances, the interval from Mob::movementBroadcastInterval()
	 * for mobs, a list of players and a list of send ids.
	 *
	 * @param Player[] $hasSpawned
	 * @param int[]    $viewerSendIds
	 * @phpstan-param array<int, Player> $hasSpawned
	 * @phpstan-param array<int, int>    $viewerSendIds
	 * @phpstan-return array{list<Player>, list<int>, bool}
	 */
	private static function oldSelection(bool $mob, array $hasSpawned, array $viewerSendIds, Vector3 $pos, int $tick, int $fullRateSquared, int $halfRateSquared) : array{
		$locationProperty = new \ReflectionProperty(Entity::class, "location");
		$recipients = [];
		$sendIds = [];
		$skipped = false;
		$x = $pos->x;
		$y = $pos->y;
		$z = $pos->z;
		foreach($hasSpawned as $key => $viewer){
			$viewerLocation = $locationProperty->getValue($viewer);
			self::assertInstanceOf(Location::class, $viewerLocation);
			$distanceSquared = ($viewerLocation->x - $x) ** 2 + ($viewerLocation->y - $y) ** 2 + ($viewerLocation->z - $z) ** 2;
			if($distanceSquared <= $fullRateSquared){
				$recipients[] = $viewer;
				$sendIds[] = $viewerSendIds[$key] ?? 0;
				continue;
			}
			if($mob){
				$interval = Mob::movementBroadcastInterval($distanceSquared, $fullRateSquared, $halfRateSquared);
			}else{
				$interval = $distanceSquared <= $halfRateSquared ? 2 : 4;
			}
			if((($tick + $viewer->getId()) % $interval) === 0){
				$recipients[] = $viewer;
				$sendIds[] = $viewerSendIds[$key] ?? 0;
			}else{
				$skipped = true;
			}
		}
		return [$recipients, $sendIds, $skipped];
	}

	/**
	 * NetworkBroadcastUtils::broadcastPackets() as the old loop called it, verbatim apart from the timings.
	 *
	 * @param Player[]            $recipients
	 * @param ClientboundPacket[] $packets
	 * @param int[]|null          $sendIds
	 * @phpstan-param array<int, int>|null $sendIds
	 */
	private static function oldBroadcastPackets(array $recipients, array $packets, ?array $sendIds = null) : bool{
		$native = NetworkSession::getNativeSendBuffers();
		if($native !== null && NetworkSession::hasOnlyStandardSessions() && !DataPacketSendEvent::hasHandlers()){
			$totalLength = 0;
			$packetBuffers = [];
			$writer = new ByteBufferWriter();
			foreach($packets as $packet){
				$writer->clear();
				$buffer = NetworkSession::prefixPacketBuffer(NetworkSession::encodePacketTimed($writer, $packet));
				$totalLength += strlen($buffer);
				$packetBuffers[] = $buffer;
			}
			$threshold = ZlibCompressor::getInstance()->getCompressionThreshold();
			if($threshold === null || $totalLength < $threshold || count($recipients) <= 1){
				if($sendIds === null){
					$sendIds = [];
					foreach($recipients as $player){
						$session = $player->getConnectedNetworkSession();
						if($session !== null){
							$sendIds[] = $session->getSendId();
						}
					}
				}
				if(count($sendIds) === 0){
					return false;
				}
				$native->appendMany($sendIds, count($packetBuffers) === 1 ? $packetBuffers[0] : implode("", $packetBuffers));
				return true;
			}
		}

		if(NetworkSession::hasOnlyStandardSessions()){
			$targets = [];
			$first = null;
			foreach($recipients as $player){
				$session = $player->getConnectedNetworkSession();
				if($session !== null){
					$targets[spl_object_id($session)] = $session;
					$first ??= $session;
				}
			}
			if($first === null){
				return false;
			}
			$first->getBroadcaster()->broadcastPackets($targets, $packets);
			return true;
		}

		$broadcaster = null;
		$mixed = false;
		$targets = [];
		foreach($recipients as $player){
			if($player->isConnected()){
				$recipient = $player->getNetworkSession();
				$recipientBroadcaster = $recipient->getBroadcaster();
				if($broadcaster === null){
					$broadcaster = $recipientBroadcaster;
				}elseif($recipientBroadcaster !== $broadcaster){
					$mixed = true;
				}
				$targets[spl_object_id($recipient)] = $recipient;
			}
		}
		if($broadcaster === null){
			return false;
		}
		if(!$mixed){
			$broadcaster->broadcastPackets($targets, $packets);
			return true;
		}

		$uniqueBroadcasters = [];
		$broadcasterTargets = [];
		foreach($targets as $recipientId => $recipient){
			$recipientBroadcaster = $recipient->getBroadcaster();
			$broadcasterId = spl_object_id($recipientBroadcaster);
			$uniqueBroadcasters[$broadcasterId] = $recipientBroadcaster;
			$broadcasterTargets[$broadcasterId][$recipientId] = $recipient;
		}
		foreach($uniqueBroadcasters as $broadcasterId => $uniqueBroadcaster){
			$uniqueBroadcaster->broadcastPackets($broadcasterTargets[$broadcasterId], $packets);
		}

		return true;
	}

	/**
	 * An offset from the mover: mostly random around the rate distances, often exactly on (or one rounding step
	 * around) a distance limit, sometimes nothing or very far.
	 */
	private static function offset(Random $random, int $full, int $half) : Vector3{
		$kind = $random->nextBoundedInt(12);
		$limit = $random->nextBoundedInt(2) === 0 ? $full : $half;
		$sign = $random->nextBoundedInt(2) === 0 ? -1.0 : 1.0;
		switch($kind){
			case 0: //on an axis, exactly at a limit
				$d = $sign * $limit;
				return match($random->nextBoundedInt(3)){
					0 => new Vector3($d, 0.0, 0.0),
					1 => new Vector3(0.0, $d, 0.0),
					default => new Vector3(0.0, 0.0, $d)
				};
			case 1: //on an axis, one rounding step around a limit
				$d = $sign * $limit * (1 + ($random->nextBoundedInt(2) === 0 ? -0.5 : 1.0) * PHP_FLOAT_EPSILON);
				return new Vector3(0.0, $d, 0.0);
			case 2: //at a limit along a diagonal: the squares don't add up exactly
				return new Vector3($sign * $limit * 0.48, $limit * 0.6, $limit * 0.64);
			case 3:
				return new Vector3($limit * 0.6, 0.0, $sign * $limit * 0.8);
			case 4: //the same place, or almost
				return new Vector3(0.0, $random->nextBoundedInt(2) * 1e-9, 0.0);
			case 5: //far away
				return new Vector3(($random->nextFloat() - 0.5) * 2000, ($random->nextFloat() - 0.5) * 300, ($random->nextFloat() - 0.5) * 2000);
			default:
				$range = max($full, $half) * 1.6 + 5;
				return new Vector3(($random->nextFloat() - 0.5) * 2 * $range, ($random->nextFloat() - 0.5) * $range, ($random->nextFloat() - 0.5) * 2 * $range);
		}
	}

	/**
	 * @phpstan-return array{bool, list<array{string, list<int>, list<string>}>, array<int, string>}
	 * @param int[] $openSendIds
	 * @phpstan-param list<int> $openSendIds
	 */
	private function outcome(bool $pending, array $openSendIds) : array{
		$native = [];
		$buffers = NetworkSession::getNativeSendBuffers();
		if($buffers !== null){
			foreach($openSendIds as $sendId){
				$native[$sendId] = $buffers->take($sendId);
			}
		}
		$calls = $this->calls;
		$this->calls = [];
		return [$pending, $calls, $native];
	}

	/**
	 * Broadcasts the movement of $mover (already at $pos, with $hasSpawned and $viewerSendIds) the old way (the old
	 * loop, then the old broadcastPackets() with the players and their send ids), then with broadcastMovement(), and
	 * checks that both delivered the same packets to the same sessions and left the same pending flag.
	 *
	 * @param Player[] $hasSpawned
	 * @param int[]    $viewerSendIds
	 * @param int[]    $openSendIds
	 * @phpstan-param array<int, Player> $hasSpawned
	 * @phpstan-param array<int, int>    $viewerSendIds
	 * @phpstan-param list<int>          $openSendIds
	 *
	 * @phpstan-return array{array{bool, list<array{string, list<int>, list<string>}>, array<int, string>}, bool} the
	 * outcome, and whether the old loop skipped a viewer
	 */
	private function assertSameAsOldLoop(Entity $mover, bool $mob, array $hasSpawned, array $viewerSendIds, array $openSendIds, Vector3 $pos, int $fullRateSquared, int $halfRateSquared, bool $pendingBefore, string $mode, string $message) : array{
		$pendingProperty = new \ReflectionProperty($mob ? Mob::class : Player::class, "movementBroadcastPending");
		$broadcastMovement = new \ReflectionMethod($mover, "broadcastMovement");
		$createMovementPacket = new \ReflectionMethod($mover, "createMovementPacket");

		//before: the old loop, then broadcastPackets() with the players and their send ids
		[$recipients, $sendIds, $skipped] = self::oldSelection($mob, $hasSpawned, $viewerSendIds, $pos, $this->tick, $fullRateSquared, $halfRateSquared);

		//the native selection broadcastMovement() is about to use (it keeps nothing, calling it twice changes nothing):
		//the same recipients, send ids and skipped flag as the old loop, or -1 and nothing (the PHP loop runs then)
		$selector = NetworkBroadcastUtils::getNativeMovementSelector();
		if($selector !== null){
			$selectedIds = ["junk"];
			$selected = $selector->selectMovement($hasSpawned, $viewerSendIds, $pos->x, $pos->y, $pos->z, $this->tick, $fullRateSquared, $halfRateSquared, $selectedIds);
			if($selected >= 0){
				self::assertSame($sendIds, array_values($selectedIds), $message);
				self::assertSame($recipients, array_values(array_intersect_key($hasSpawned, $selectedIds)), $message);
				self::assertSame((count($recipients) << 1) | ($skipped ? 1 : 0), $selected, $message);
				$this->coverage[$mode . " selected"] = 1;
			}else{
				self::assertSame(-1, $selected, $message);
				self::assertSame([], $selectedIds, $message);
				$this->coverage[$mode . " selection fallback"] = 1;
			}
		}

		if(count($recipients) > 0){
			$packet = $createMovementPacket->invoke($mover);
			self::assertInstanceOf(ClientboundPacket::class, $packet);
			self::oldBroadcastPackets($recipients, [$packet], $sendIds);
		}
		$old = $this->outcome($pendingBefore || $skipped, $openSendIds);

		//now
		$pendingProperty->setValue($mover, $pendingBefore);
		$broadcastMovement->invoke($mover, false);
		$pendingAfter = $pendingProperty->getValue($mover);
		self::assertIsBool($pendingAfter);
		$new = $this->outcome($pendingAfter, $openSendIds);

		self::assertSame($old, $new, $message);
		return [$old, $skipped];
	}

	/**
	 * $value moved by $steps representable floats (positive values).
	 */
	private static function nextFloat(float $value, int $steps) : float{
		return Binary::readLDouble(Binary::writeLLong(Binary::readLLong(Binary::writeLDouble($value)) + $steps));
	}

	/**
	 * Limits and offsets from the mover whose squared distance is the limit or one rounding step around it, on an axis
	 * next to the square root of the limit and in a plane. Among them the ones where pow(d, 2) (what ** 2 computes, the
	 * old loop's formula) and d * d round differently, on both sides of the limit: Windows' C library has such cases,
	 * e.g. 23.69 ** 2 + 41.746663339720939 ** 2 > 2304 while 23.69 * 23.69 + 41.746663339720939 * 41.746663339720939
	 * is exactly 2304, and 48.651824220680567 ** 2 is exactly 2367 while the product is above. A correctly rounded pow()
	 * (glibc's is close to one) gives the product's result: then the searches find nothing and only the fixed cases are
	 * left, which still have to give the same outcome.
	 *
	 * @phpstan-return list<array{int, int, list<Vector3>}> full rate squared, half rate squared, offsets
	 */
	private static function tieCases() : array{
		$cases = [
			[576, 2304, [new Vector3(23.69, 0.0, 41.746663339720939)]],
			[2367, 4 * 2367, [new Vector3(48.651824220680567, 0.0, 0.0)]],
		];
		foreach([576, 2304, 2367, 3319, 6031, 16952] as $limit){
			$offsets = [];
			for($steps = -2; $steps <= 2; ++$steps){
				$offsets[] = new Vector3(self::nextFloat(sqrt($limit), $steps), 0.0, 0.0);
				$offsets[] = new Vector3(0.0, 0.0, -self::nextFloat(sqrt($limit), $steps));
			}
			$cases[] = [$limit, 4 * $limit, $offsets];
			$cases[] = [intdiv($limit, 4), $limit, $offsets];
		}

		//on an axis: the limits whose square root rounds to a float whose pow() and product land on both sides
		$found = 0;
		for($limit = 2; $limit <= 20000 && $found < 16; ++$limit){
			for($steps = -1; $steps <= 1; ++$steps){
				$d = self::nextFloat(sqrt($limit), $steps);
				if(($d ** 2 <= $limit) !== ($d * $d <= $limit)){
					$cases[] = [$limit, 4 * $limit, [new Vector3($d, 0.0, 0.0)]];
					$cases[] = [intdiv($limit, 4), $limit, [new Vector3(0.0, 0.0, $d)]];
					$found++;
				}
			}
		}

		//in a plane, at the default limits
		$random = new Random(2304);
		foreach([[576, 2304, 576], [576, 2304, 2304]] as [$full, $half, $limit]){
			$offsets = [];
			for($try = 0; $try < 50000 && count($offsets) < 8; ++$try){
				$dx = $random->nextFloat() * sqrt($limit);
				$dz = self::nextFloat(sqrt($limit - $dx * $dx), $random->nextBoundedInt(3) - 1);
				if(($dx ** 2 + $dz ** 2 <= $limit) !== ($dx * $dx + $dz * $dz <= $limit)){
					$offsets[] = new Vector3($dx, 0.0, $dz);
				}
			}
			if(count($offsets) > 0){
				$cases[] = [$full, $half, $offsets];
			}
		}
		return $cases;
	}

	/**
	 * The tie cases with a mover at (0, 64, 0), so that the viewers' offsets are exactly the differences of the loop, on
	 * tick 0: each offset has a viewer with ID 1 (beyond the full rate distance it is skipped) and one with ID 2 (it
	 * gets every 2nd tick: skipped beyond the half rate distance).
	 */
	private function runTieCases(Entity $mover, bool $mob, string $mode) : void{
		$location = new \ReflectionProperty(Entity::class, "location");
		$hasSpawnedProperty = new \ReflectionProperty(Entity::class, "hasSpawned");
		$viewerSendIdsProperty = new \ReflectionProperty(Entity::class, "viewerSendIds");
		$pos = new Vector3(0.0, 64.0, 0.0);
		$location->setValue($mover, Location::fromObject($pos, $this->world));
		$this->tick = 0;

		$cases = self::tieCases();
		//the 14 fixed cases; how many the searches add depends on the C library's pow(): some with Windows' UCRT (which
		//picks its pow() by CPU), none with a correctly rounded one (glibc's is close to it)
		self::assertGreaterThanOrEqual(14, count($cases));
		foreach($cases as $case => [$fullRateSquared, $halfRateSquared, $offsets]){
			if($mob){
				(new \ReflectionProperty(Mob::class, "movementBroadcastDistances"))->setValue(null, [$fullRateSquared, $halfRateSquared]);
			}else{
				(new \ReflectionProperty(Player::class, "movementFullRateDistanceSquared"))->setValue($mover, $fullRateSquared);
				(new \ReflectionProperty(Player::class, "movementHalfRateDistanceSquared"))->setValue($mover, $halfRateSquared);
			}
			NetworkSession::setNativeSendBuffersEnabled($mode !== self::MODE_PHP);
			$buffers = NetworkSession::getNativeSendBuffers();
			$hasSpawned = [];
			$viewerSendIds = [];
			$openSendIds = [];
			$this->viewerOfSession = [];
			foreach($offsets as $offset){
				foreach([1, 2] as $id){
					$number = count($hasSpawned);
					$sendId = 1000 + $number;
					$viewer = $this->viewer($number, $id, $pos->addVector($offset), $sendId, true, false, $this->broadcasters[0]);
					$hasSpawned[spl_object_id($viewer)] = $viewer;
					$viewerSendIds[spl_object_id($viewer)] = $sendId;
					if($buffers !== null){
						$buffers->open($sendId);
						$openSendIds[] = $sendId;
					}
				}
			}
			$hasSpawnedProperty->setValue($mover, $hasSpawned);
			$viewerSendIdsProperty->setValue($mover, $viewerSendIds);
			$this->assertSameAsOldLoop($mover, $mob, $hasSpawned, $viewerSendIds, $openSendIds, $pos, $fullRateSquared, $halfRateSquared, false, $mode . " tie", "$mode tie case $case ($fullRateSquared, $halfRateSquared)");
		}
		$hasSpawnedProperty->setValue($mover, []);
		$viewerSendIdsProperty->setValue($mover, []);
	}

	private function runScenarios(Entity $mover, bool $mob, string $mode, int $seed) : void{
		$random = new Random($seed);
		$location = new \ReflectionProperty(Entity::class, "location");
		$hasSpawnedProperty = new \ReflectionProperty(Entity::class, "hasSpawned");
		$viewerSendIdsProperty = new \ReflectionProperty(Entity::class, "viewerSendIds");

		for($scenario = 0; $scenario < self::SCENARIOS; ++$scenario){
			[$full, $half] = self::DISTANCES[$random->nextBoundedInt(count(self::DISTANCES))];
			$fullRateSquared = $full ** 2;
			$halfRateSquared = max($full, $half) ** 2;
			if($mob){
				Mob::setMovementBroadcastDistances($full, $half);
			}else{
				(new \ReflectionProperty(Player::class, "movementFullRateDistanceSquared"))->setValue($mover, $fullRateSquared);
				(new \ReflectionProperty(Player::class, "movementHalfRateDistanceSquared"))->setValue($mover, $halfRateSquared);
			}
			$pos = new Vector3(
				($random->nextFloat() - 0.5) * 400,
				$random->nextFloat() * 300 - 60,
				($random->nextFloat() - 0.5) * 400
			);
			//int coordinates (a plugin may write them into the Location): the loops then compute with ints
			$intMover = $random->nextBoundedInt(4) === 0;
			if($intMover){
				$pos = self::intPosition($pos);
			}
			$moverLocation = self::location($pos, $this->world, $random->nextFloat() * 360, $random->nextFloat() * 180 - 90);
			$location->setValue($mover, $moverLocation);
			$this->tick = $random->nextBoundedInt(1_000_000);

			//fresh native send buffers for each scenario (none in MODE_PHP)
			NetworkSession::setNativeSendBuffersEnabled($mode !== self::MODE_PHP);
			$buffers = NetworkSession::getNativeSendBuffers();
			$hasSpawned = [];
			$viewerSendIds = [];
			$openSendIds = [];
			$this->viewerOfSession = [];
			$viewerCount = match($random->nextBoundedInt(8)){
				0, 1 => $random->nextBoundedInt(3),
				2 => 100 + $random->nextBoundedInt(150), //a crowd
				default => $random->nextBoundedInt(40)
			};
			//viewer classes overriding getId() (plugins, mocks): the native selection leaves those scenarios to the loop
			$overridingShare = $random->nextBoundedInt(3) === 0 ? 5 : 0;
			for($i = 0; $i < $viewerCount; ++$i){
				$sendId = 1000 + $i;
				$viewerPos = $pos->addVector(self::offset($random, $full, $half));
				$kind = $random->nextBoundedInt(12);
				if($kind === 0){
					$viewerPos = self::hugeIntPosition($random, $pos);
				}elseif($kind <= 3 || ($intMover && $kind <= 6)){
					$viewerPos = self::intPosition($viewerPos);
				}
				$viewer = $this->viewer(
					$i,
					$random->nextBoundedInt(5) === 0 ? $i + 1 : $random->nextBoundedInt(1_000_000) + 1,
					$viewerPos,
					$sendId,
					$random->nextBoundedInt(10) !== 0,
					$overridingShare > 0 && $random->nextBoundedInt($overridingShare) === 0,
					$this->broadcasters[$random->nextBoundedInt(4) === 0 ? 1 : 0]
				);
				$hasSpawned[spl_object_id($viewer)] = $viewer;
				if($random->nextBoundedInt(10) !== 0){
					$viewerSendIds[spl_object_id($viewer)] = $sendId;
				} //else: a plugin wrote hasSpawned without the send id
				if($buffers !== null && $random->nextBoundedInt(10) !== 0){
					$buffers->open($sendId);
					$openSendIds[] = $sendId;
				} //else: the session's buffer is closed
			}
			$hasSpawnedProperty->setValue($mover, $hasSpawned);
			$viewerSendIdsProperty->setValue($mover, $viewerSendIds);
			$pendingBefore = $random->nextBoundedInt(3) === 0;

			[$old, $skipped] = $this->assertSameAsOldLoop($mover, $mob, $hasSpawned, $viewerSendIds, $openSendIds, $moverLocation, $fullRateSquared, $halfRateSquared, $pendingBefore, $mode, "$mode scenario $scenario (seed $seed)");

			$this->coverage[$mode . ($skipped ? " skipped" : " all")] = 1;
			if(count($old[1]) > 0){
				$this->coverage[$mode . " broadcaster"] = 1;
			}
			if(count($old[1]) > 1){
				$this->coverage[$mode . " broadcasters"] = 1;
			}
			foreach($old[2] as $buffer){
				if($buffer !== ""){
					$this->coverage[$mode . " native"] = 1;
				}
			}
		}
		$hasSpawnedProperty->setValue($mover, []);
		$viewerSendIdsProperty->setValue($mover, []);
	}

	private function runAllModes(Entity $mover, bool $mob) : void{
		$this->runScenarios($mover, $mob, self::MODE_PHP, 1);
		$this->runTieCases($mover, $mob, self::MODE_PHP);
		if(!class_exists(NativeSendBuffers::class)){
			return;
		}
		$this->runScenarios($mover, $mob, self::MODE_NATIVE, 2);
		$this->runTieCases($mover, $mob, self::MODE_NATIVE);

		NetworkBroadcastUtils::setNativeMovementSelectionEnabled(false);
		try{
			self::assertNull(NetworkBroadcastUtils::getNativeMovementSelector());
			$this->runScenarios($mover, $mob, self::MODE_NATIVE_LOOP, 2);
			$this->runTieCases($mover, $mob, self::MODE_NATIVE_LOOP);
		}finally{
			NetworkBroadcastUtils::setNativeMovementSelectionEnabled(true);
		}

		$compressor = $this->compressor = ZlibCompressor::getInstance();
		ZlibCompressor::setInstance(new ZlibCompressor(ZlibCompressor::DEFAULT_LEVEL, 1, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		try{
			$this->runScenarios($mover, $mob, self::MODE_COMPRESSED, 3);
		}finally{
			ZlibCompressor::setInstance($compressor);
			$this->compressor = null;
		}

		$listener = new RegisteredListener(
			function(DataPacketSendEvent $ev) : void{},
			EventPriority::MONITOR,
			$this->createConfiguredMock(Plugin::class, ["isEnabled" => true]),
			false,
			new TimingsHandler("test")
		);
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->register($listener);
		try{
			self::assertTrue(DataPacketSendEvent::hasHandlers());
			$this->runScenarios($mover, $mob, self::MODE_EVENT, 4);
		}finally{
			HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->unregister($listener);
		}

		$nonStandardSessions = new \ReflectionProperty(NetworkSession::class, "nonStandardSessions");
		$nonStandardSessions->setValue(null, 1);
		try{
			self::assertFalse(NetworkSession::hasOnlyStandardSessions());
			$this->runScenarios($mover, $mob, self::MODE_NON_STANDARD, 5);
		}finally{
			$nonStandardSessions->setValue(null, 0);
		}

		//every path was taken: the broadcaster (no native buffers, big batches, event handlers, sessions with each
		//their broadcaster), the native buffers (also for a single recipient of a big batch), with and without skipped
		//viewers
		foreach([
			self::MODE_PHP . " broadcaster", self::MODE_PHP . " skipped", self::MODE_PHP . " all",
			self::MODE_NATIVE . " native", self::MODE_NATIVE . " skipped", self::MODE_NATIVE . " all",
			self::MODE_NATIVE_LOOP . " native", self::MODE_NATIVE_LOOP . " skipped", self::MODE_NATIVE_LOOP . " all",
			self::MODE_COMPRESSED . " native", self::MODE_COMPRESSED . " broadcaster",
			self::MODE_EVENT . " broadcaster",
			self::MODE_NON_STANDARD . " broadcaster", self::MODE_NON_STANDARD . " broadcasters"
		] as $path){
			self::assertArrayHasKey($path, $this->coverage);
		}
		foreach([self::MODE_PHP, self::MODE_NATIVE, self::MODE_NATIVE_LOOP, self::MODE_COMPRESSED, self::MODE_EVENT] as $mode){
			self::assertArrayNotHasKey($mode . " broadcasters", $this->coverage, "the first session's broadcaster for everyone");
		}
		self::assertArrayNotHasKey(self::MODE_NATIVE . " broadcaster", $this->coverage);
		self::assertArrayNotHasKey(self::MODE_NATIVE_LOOP . " broadcaster", $this->coverage);
		self::assertArrayNotHasKey(self::MODE_EVENT . " native", $this->coverage);
		self::assertArrayNotHasKey(self::MODE_NON_STANDARD . " native", $this->coverage);

		//the native selection: used with the native send buffers (also for big batches), not otherwise, and left to the
		//loop for viewers overriding getId()
		/** @phpstan-ignore function.alreadyNarrowedType (chunkutils2 < 0.3.10 lacks it; the PHPStan stub always has it) */
		if(method_exists(NativeSendBuffers::class, "selectMovement")){
			foreach([
				self::MODE_NATIVE . " selected", self::MODE_NATIVE . " selection fallback", self::MODE_NATIVE . " tie selected",
				self::MODE_COMPRESSED . " selected"
			] as $path){
				self::assertArrayHasKey($path, $this->coverage);
			}
		}
		foreach([self::MODE_PHP, self::MODE_NATIVE_LOOP, self::MODE_EVENT, self::MODE_NON_STANDARD] as $mode){
			self::assertArrayNotHasKey($mode . " selected", $this->coverage);
			self::assertArrayNotHasKey($mode . " tie selected", $this->coverage);
			self::assertArrayNotHasKey($mode . " selection fallback", $this->coverage);
		}
	}

	public function testMobs() : void{
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		try{
			$this->runAllModes($zombie, true);
		}finally{
			//the fake viewers can't receive despawn packets
			(new \ReflectionProperty(Entity::class, "hasSpawned"))->setValue($zombie, []);
			(new \ReflectionProperty(Entity::class, "viewerSendIds"))->setValue($zombie, []);
			$zombie->close();
		}
	}

	/**
	 * NativeSendBuffers::selectMovement() against a verbatim copy of the viewer loop of Player::broadcastMovement()
	 * and Mob::broadcastMovement() (native send buffers branch), on real Player objects: the same send ids (keys,
	 * order, values) and skipped flag over random layouts (float, int and overflowing int coordinates, limits hit
	 * exactly or one rounding step away, missing send ids, crowds), or -1 and nothing when a viewer class overrides
	 * getId(), which the loop calls.
	 */
	public function testNativeSelectionMatchesLoop() : void{
		/** @phpstan-ignore function.alreadyNarrowedType (chunkutils2 < 0.3.10 lacks it; the PHPStan stub always has it) */
		if(!class_exists(NativeSendBuffers::class) || !method_exists(NativeSendBuffers::class, "selectMovement")){
			self::markTestSkipped("chunkutils2 >= 0.3.10 is not loaded");
		}
		$native = new NativeSendBuffers();

		$loop = \Closure::bind(static function(array $viewers, array $viewerSendIds, int|float $x, int|float $y, int|float $z, int $tick, int $fullRateSquared, int $halfRateSquared) : array{
			/**
			 * @phpstan-var array<int, Player> $viewers
			 * @phpstan-var array<int, int>    $viewerSendIds
			 */
			$skipped = false;
			$sendIds = [];
			foreach($viewers as $key => $viewer){
				$viewerLocation = $viewer->location;
				$distanceSquared = ($viewerLocation->x - $x) ** 2 + ($viewerLocation->y - $y) ** 2 + ($viewerLocation->z - $z) ** 2;
				if($distanceSquared <= $fullRateSquared || (($tick + $viewer->getId()) % ($distanceSquared <= $halfRateSquared ? 2 : 4)) === 0){
					$sendIds[$key] = $viewerSendIds[$key] ?? 0;
				}else{
					$skipped = true;
				}
			}
			return [$sendIds, $skipped];
		}, null, Entity::class);
		self::assertNotNull($loop);

		$idProperty = new \ReflectionProperty(Entity::class, "id");
		$locationProperty = new \ReflectionProperty(Entity::class, "location");
		$newPlayer = function(bool $overridesGetId) : Player{
			if($overridesGetId){
				$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(["getId"])->getMock();
				$player->method("getId")->willReturn(5);
			}else{
				$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true); //never disposed like a real player
			(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
			return $player;
		};
		$plain = [];
		for($i = 0; $i < 300; ++$i){
			$plain[] = $newPlayer(false);
		}
		$overriding = [];
		for($i = 0; $i < 10; ++$i){
			$overriding[] = $newPlayer(true);
		}
		$ties = self::tieCases();

		$random = new Random(10);
		$selectedScenes = 0;
		$fallbackScenes = 0;
		$skippedScenes = 0;
		$pairs = 0;
		for($scene = 0; $scene < 2500; ++$scene){
			$tie = $random->nextBoundedInt(6) === 0 ? $ties[$random->nextBoundedInt(count($ties))] : null;
			if($tie !== null){
				[$fullRateSquared, $halfRateSquared, $tieOffsets] = $tie;
				$pos = new Vector3(0.0, 64.0, 0.0);
				$tick = $random->nextBoundedInt(4);
			}else{
				[$full, $half] = self::DISTANCES[$random->nextBoundedInt(count(self::DISTANCES))];
				$fullRateSquared = $full ** 2;
				$halfRateSquared = max($full, $half) ** 2;
				if($random->nextBoundedInt(10) === 0){ //unusual limits, up to the largest the native selection takes
					$fullRateSquared = $random->nextBoundedInt(2) === 0 ? 0 : (1 << $random->nextBoundedInt(53)) - 1;
					$halfRateSquared = $random->nextBoundedInt(3) === 0 ? $fullRateSquared : (1 << $random->nextBoundedInt(53)) - 1;
				}
				$tieOffsets = [];
				$pos = new Vector3(($random->nextFloat() - 0.5) * 400, $random->nextFloat() * 300 - 60, ($random->nextFloat() - 0.5) * 400);
				if($random->nextBoundedInt(4) === 0){
					$pos = self::intPosition($pos);
				}
				$tick = $random->nextBoundedInt(4) === 0 ? $random->nextBoundedInt(16) : $random->nextBoundedInt(1 << 30) * 1024 + $random->nextBoundedInt(1024);
			}

			$withOverriding = $random->nextBoundedInt(5) === 0;
			$count = match($random->nextBoundedInt(6)){
				0 => $random->nextBoundedInt(3),
				1 => 100 + $random->nextBoundedInt(200),
				default => $random->nextBoundedInt(60)
			};
			$count = max($count, count($tieOffsets) * 2);
			$viewers = [];
			$viewerSendIds = [];
			$start = $random->nextBoundedInt(count($plain));
			for($i = 0; $i < $count; ++$i){
				$viewer = $withOverriding && $random->nextBoundedInt(8) === 0 ? $overriding[$random->nextBoundedInt(count($overriding))] : $plain[($start + $i) % count($plain)];
				if($i < count($tieOffsets) * 2){
					$viewerPos = $pos->addVector($tieOffsets[$i >> 1]);
					$id = ($i & 1) + 1; //1 and 2, see runTieCases()
				}else{
					$viewerPos = $pos->addVector(self::offset($random, $fullRateSquared > 10000 ? 100 : (int) sqrt($fullRateSquared), $halfRateSquared > 10000 ? 100 : (int) sqrt($halfRateSquared)));
					$kind = $random->nextBoundedInt(12);
					if($kind === 0){
						$viewerPos = self::hugeIntPosition($random, $pos);
					}elseif($kind <= 3){
						$viewerPos = self::intPosition($viewerPos);
					}
					$id = $random->nextBoundedInt(5) === 0 ? $i + 1 : $random->nextBoundedInt(1_000_000_000) + 1;
				}
				$idProperty->setValue($viewer, $id);
				$locationProperty->setValue($viewer, self::location($viewerPos, $this->world));
				$key = $random->nextBoundedInt(3) === 0 ? $random->nextBoundedInt(1 << 30) - (1 << 29) : spl_object_id($viewer) + ($i << 20);
				$viewers[$key] = $viewer;
				if($random->nextBoundedInt(10) !== 0){
					$viewerSendIds[$key] = $random->nextBoundedInt(3) === 0 ? $random->nextBoundedInt(1 << 30) : (1 << 62) | $i;
				} //else: a plugin wrote hasSpawned without the send id
			}
			$pairs += count($viewers);

			[$expected, $skipped] = $loop($viewers, $viewerSendIds, $pos->x, $pos->y, $pos->z, $tick, $fullRateSquared, $halfRateSquared);
			$sendIds = ["junk"];
			$selected = $native->selectMovement($viewers, $viewerSendIds, $pos->x, $pos->y, $pos->z, $tick, $fullRateSquared, $halfRateSquared, $sendIds);
			$hasOverriding = false;
			foreach($viewers as $viewer){
				$hasOverriding = $hasOverriding || (new \ReflectionMethod($viewer, "getId"))->getDeclaringClass()->getName() !== Entity::class;
			}
			if($selected === -1){
				self::assertTrue($hasOverriding, "scene $scene: only viewers overriding getId() need the loop");
				self::assertSame([], $sendIds, "scene $scene");
				$fallbackScenes++;
				continue;
			}
			self::assertSame($expected, $sendIds, "scene $scene");
			self::assertSame((count($expected) << 1) | ($skipped ? 1 : 0), $selected, "scene $scene");
			$selectedScenes++;
			if($skipped){
				$skippedScenes++;
			}
		}
		self::assertGreaterThan(1500, $selectedScenes);
		self::assertGreaterThan(50, $fallbackScenes);
		self::assertGreaterThan(500, $skippedScenes);
		self::assertGreaterThan(50000, $pairs);
	}

	public function testPlayers() : void{
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, 77);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true); //never disposed like a real player
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$this->runAllModes($player, false);
	}
}
