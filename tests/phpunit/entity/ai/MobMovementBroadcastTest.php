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

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NativeSendBuffers;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\player\Player;
use pocketmine\utils\Binary;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function class_exists;
use function method_exists;
use function spl_object_id;
use function strlen;

/**
 * mobs.movement-broadcast: far viewers get a moving mob's movement every 2nd/4th tick, and everyone gets the position
 * once it stops.
 */
final class MobMovementBroadcastTest extends MobTestCase{
	/** @var array<int, int> viewer entity ID => movement packets received */
	private array $received = [];
	/** @var Player[] */
	private array $viewers = [];
	/**
	 * @var int[] viewer entity ID => native send buffer id, for the viewers whose packets go to the native send buffers
	 * @phpstan-var array<int, int>
	 */
	private array $sendIds = [];

	protected function setUp() : void{
		parent::setUp();
		//the fake viewers below have no real session: packets must go through their fake broadcaster, not the native
		//send buffers (but see testFarViewersWithNativeSendBuffers())
		NetworkSession::setNativeSendBuffersEnabled(false);
	}

	protected function tearDown() : void{
		NetworkSession::setNativeSendBuffersEnabled(true);
		NetworkBroadcastUtils::setNativeMovementSelectionEnabled(true);
		Mob::setMovementBroadcastDistances(null);
		//the fake viewers can't receive despawn packets
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Mob){
				$this->watch($entity, []);
			}
		}
		parent::tearDown();
	}

	/**
	 * A fake viewer: a Player that is never ticked or spawned, whose session records the movement packets for this mob.
	 */
	private function viewer(int $id, Vector3 $pos, ?int $sendId = null) : Player{
		$test = $this;
		$broadcaster = new class($test) implements PacketBroadcaster{
			public function __construct(private MobMovementBroadcastTest $test){}

			public function broadcastPackets(array $recipients, array $packets) : void{
				foreach($recipients as $session){
					foreach($packets as $packet){
						if($packet instanceof MoveActorAbsolutePacket){
							$this->test->record($session);
						}
					}
				}
			}
		};
		$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(NetworkSession::class, "broadcaster"))->setValue($session, $broadcaster);
		if($sendId !== null){
			//its packets go to this native send buffer (see collect())
			(new \ReflectionProperty(NetworkSession::class, "sendId"))->setValue($session, $sendId);
			$buffers = NetworkSession::getNativeSendBuffers();
			self::assertNotNull($buffers);
			$buffers->open($sendId);
			$this->sendIds[$id] = $sendId;
		}
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, $id);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, Location::fromObject($pos, $this->world));
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true); //never disposed like a real player
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$this->viewers[spl_object_id($session)] = $player;
		return $player;
	}

	/** @internal */
	public function record(NetworkSession $session) : void{
		$id = $this->viewers[spl_object_id($session)]->getId();
		$this->received[$id] = ($this->received[$id] ?? 0) + 1;
	}

	/**
	 * @param Player[] $viewers
	 */
	private function watch(Mob $mob, array $viewers) : void{
		$hasSpawned = new \ReflectionProperty(Entity::class, "hasSpawned");
		$list = [];
		$sendIds = [];
		foreach($viewers as $viewer){
			$list[spl_object_id($viewer)] = $viewer;
			if(isset($this->sendIds[$viewer->getId()])){
				$sendIds[spl_object_id($viewer)] = $this->sendIds[$viewer->getId()];
			}
		}
		$hasSpawned->setValue($mob, $list);
		(new \ReflectionProperty(Entity::class, "viewerSendIds"))->setValue($mob, $sendIds);
	}

	/**
	 * Counts the movement packets the viewers got in their native send buffers since the last call.
	 */
	private function collect() : void{
		$buffers = NetworkSession::getNativeSendBuffers();
		if($buffers === null){
			return;
		}
		foreach($this->sendIds as $id => $sendId){
			$batch = $buffers->take($sendId);
			$offset = 0;
			while($offset < strlen($batch)){
				$length = Binary::readUnsignedVarInt($batch, $offset);
				$headerOffset = $offset;
				if((Binary::readUnsignedVarInt($batch, $headerOffset) & 0x3ff) === MoveActorAbsolutePacket::NETWORK_ID){
					$this->received[$id] = ($this->received[$id] ?? 0) + 1;
				}
				$offset += $length;
			}
		}
	}

	public function testIntervals() : void{
		self::assertSame(1, Mob::movementBroadcastInterval(24 ** 2, 24 ** 2, 48 ** 2));
		self::assertSame(2, Mob::movementBroadcastInterval(25 ** 2, 24 ** 2, 48 ** 2));
		self::assertSame(2, Mob::movementBroadcastInterval(48 ** 2, 24 ** 2, 48 ** 2));
		self::assertSame(4, Mob::movementBroadcastInterval(49 ** 2, 24 ** 2, 48 ** 2));
		self::assertSame(1, Mob::movementBroadcastInterval(1000 ** 2, -1, 48 ** 2));
	}

	public function testFarViewersGetFewerUpdatesAndTheFinalPosition() : void{
		$this->runFarViewers(false);
	}

	/**
	 * The same with the native send buffers (chunkutils2 >= 0.3.9): the viewers are picked by
	 * NativeSendBuffers::selectMovement() (chunkutils2 >= 0.3.10), then by the PHP loop.
	 */
	public function testFarViewersWithNativeSendBuffers() : void{
		if(!class_exists(NativeSendBuffers::class)){
			self::markTestSkipped("chunkutils2 >= 0.3.9 is not loaded");
		}
		foreach([true, false] as $selection){
			NetworkSession::setNativeSendBuffersEnabled(true);
			NetworkBroadcastUtils::setNativeMovementSelectionEnabled($selection);
			/** @phpstan-ignore function.alreadyNarrowedType (chunkutils2 < 0.3.10 lacks it; the PHPStan stub always has it) */
			self::assertSame($selection && method_exists(NativeSendBuffers::class, "selectMovement"), NetworkBroadcastUtils::getNativeMovementSelector() !== null);
			$this->received = [];
			$this->viewers = [];
			$this->sendIds = [];
			$this->runFarViewers(true);
		}
	}

	private function runFarViewers(bool $native) : void{
		Mob::setMovementBroadcastDistances(24, 48);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$near = $this->viewer(1_000_001, new Vector3(5, 1, 5), $native ? 11 : null);
		$mid = $this->viewer(1_000_002, new Vector3(35, 1, 0), $native ? 12 : null);
		$far = $this->viewer(1_000_003, new Vector3(0, 1, 100), $native ? 13 : null);
		$this->watch($zombie, [$near, $mid, $far]);
		$broadcast = new \ReflectionMethod(Zombie::class, "broadcastMovement");
		$update = new \ReflectionMethod(Zombie::class, "updateMovement");
		$lastLocation = new \ReflectionProperty(Entity::class, "lastLocation");
		$location = new \ReflectionProperty(Entity::class, "location");

		//40 ticks of movement: a position change every tick
		for($i = 0; $i < 40; ++$i){
			++$this->tick;
			$location->setValue($zombie, Location::fromObject(new Vector3(0.6 + $i * 0.1, 1, 0.5), $this->world));
			$update->invoke($zombie);
			$this->collect();
		}
		self::assertSame(40, $this->received[$near->getId()] ?? 0, "near viewers get every movement");
		self::assertSame(20, $this->received[$mid->getId()] ?? 0, "every 2nd tick within the half rate distance");
		self::assertSame(10, $this->received[$far->getId()] ?? 0, "every 4th tick beyond");

		//it stops: next tick everyone gets the final position once
		$this->received = [];
		++$this->tick;
		$update->invoke($zombie);
		$this->collect();
		self::assertSame([$near->getId() => 1, $mid->getId() => 1, $far->getId() => 1], $this->received);
		++$this->tick;
		$update->invoke($zombie);
		$this->collect();
		self::assertSame([$near->getId() => 1, $mid->getId() => 1, $far->getId() => 1], $this->received, "only once");

		//teleports always go to everyone
		$this->received = [];
		$broadcast->invoke($zombie, true);
		$this->collect();
		self::assertSame([$near->getId() => 1, $mid->getId() => 1, $far->getId() => 1], $this->received);
		self::assertNotNull($lastLocation->getValue($zombie));
		$this->watch($zombie, []);
		$zombie->close();
	}

	public function testDisabled() : void{
		Mob::setMovementBroadcastDistances(-1);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$far = $this->viewer(1_000_003, new Vector3(0, 1, 100));
		$this->watch($zombie, [$far]);
		$update = new \ReflectionMethod(Zombie::class, "updateMovement");
		$location = new \ReflectionProperty(Entity::class, "location");
		for($i = 0; $i < 12; ++$i){
			++$this->tick;
			$location->setValue($zombie, Location::fromObject(new Vector3(0.6 + $i * 0.1, 1, 0.5), $this->world));
			$update->invoke($zombie);
		}
		self::assertSame(12, $this->received[$far->getId()] ?? 0);
		$this->watch($zombie, []);
		$zombie->close();
	}
}
