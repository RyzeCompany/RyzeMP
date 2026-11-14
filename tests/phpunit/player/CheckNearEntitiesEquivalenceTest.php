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

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\utils\Random;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use function array_keys;
use function count;
use function max;
use function min;

/**
 * Player::checkNearEntities() returns at once when every entity its scan would find is one on which the scan does
 * nothing (an entity of a class keeping Entity's isAlive(), isFlaggedForDespawn(), onCollideWithPlayer() and
 * getWorld(), already scheduled for updates), and runs the scan as before otherwise. On random crowds around chunk
 * borders and the edges of the touch box, it calls the same overridable methods on the same entities in the same
 * order, and leaves the same update schedule, as the scan it replaced.
 */
final class CheckNearEntitiesEquivalenceTest extends MobTestCase{
	private const SCENARIOS = 600;

	/**
	 * Calls made on the recording entities, in order
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public static array $log = [];

	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $fakePlayers = [];

	protected function getChunkRadius() : int{
		return 3;
	}

	protected function tearDown() : void{
		$this->removeFakePlayers();
		self::$log = [];
		parent::tearDown();
	}

	private function removeFakePlayers() : void{
		foreach($this->fakePlayers as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			//never disposed like a real player
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->fakePlayers = [];
	}

	/**
	 * A Player built without its constructor (no session, no inventory), standing in the test world.
	 *
	 * @phpstan-param class-string<Player> $class
	 */
	private function fakePlayer(string $class, float $x, float $y, float $z) : Player{
		$player = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($x, $y, $z, $this->world, 0, 0));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		$this->world->addEntity($player);
		$this->fakePlayers[] = $player;
		return $player;
	}

	/**
	 * Player::checkNearEntities() before the change, verbatim.
	 */
	private static function oldCheckNearEntities(Player $player) : void{
		foreach($player->getWorld()->getNearbyEntities($player->boundingBox->expandedCopy(1, 0.5, 1), $player) as $entity){
			$entity->scheduleUpdate();

			if(!$entity->isAlive() || $entity->isFlaggedForDespawn()){
				continue;
			}

			$entity->onCollideWithPlayer($player);
		}
	}

	private static function newCheckNearEntities(Player $player) : void{
		(fn() => $this->checkNearEntities())->call($player);
	}

	/**
	 * A coordinate offset from the toucher: random, or right at the given edge of the touch box (where rounding
	 * decides).
	 */
	private static function offset(Random $random, float $edge) : float{
		return match($random->nextBoundedInt(4)){
			0 => ($random->nextFloat() * 2 - 1) * ($edge + 2),
			1 => ($random->nextBoolean() ? 1 : -1) * ($edge + [0.0, 0.00001, -0.00001, 0.000011, 0.000009, 1e-12][$random->nextBoundedInt(6)]),
			default => ($random->nextFloat() * 2 - 1) * $edge,
		};
	}

	/**
	 * Whether the scan does nothing to the entity: its class keeps Entity's methods and it is scheduled already.
	 */
	private function isInert(Entity $entity) : bool{
		return ($entity::class === Player::class || $entity::class === Zombie::class) && isset($this->world->updateEntities[$entity->getId()]);
	}

	public function testSameCallsAsTheOldScan() : void{
		$random = new Random(20261018);
		//positions near chunk borders, so that the scan covers one to four chunks, positive and negative
		$baseCoordinates = [0.0, 0.5, 13.7, 15.7, 16.0, 16.3, 17.99, -0.2, -2.7, -15.7, -16.0, -16.3, 29.3, 30.6, -29.3];

		$skipped = 0;
		$scanned = 0;
		$rescheduled = 0;
		for($scenario = 0; $scenario < self::SCENARIOS; ++$scenario){
			$x = $baseCoordinates[$random->nextBoundedInt(count($baseCoordinates))] + ($random->nextBoolean() ? 0.0 : $random->nextFloat() * 0.6 - 0.3);
			$z = $baseCoordinates[$random->nextBoundedInt(count($baseCoordinates))] + ($random->nextBoolean() ? 0.0 : $random->nextFloat() * 0.6 - 0.3);
			$y = 1.0 + ($random->nextBoolean() ? 0.0 : $random->nextFloat() * 3);
			$toucher = $this->fakePlayer($random->nextBoundedInt(4) === 0 ? TouchRecordingPlayer::class : Player::class, $x, $y, $z);

			//a crowd of players and plain mobs (where the scan is skipped unless one is not scheduled), or a mix
			$crowd = $random->nextBoundedInt(5) < 2;
			$entities = [];
			for($i = 0, $count = $random->nextBoundedInt(14); $i < $count; ++$i){
				//player boxes are 0.6 wide and 1.8 high, the touch box 1 block wider on each side and 0.5 higher/lower
				$ex = $x + self::offset($random, 1.6);
				$ey = $y + self::offset($random, 2.3);
				$ez = $z + self::offset($random, 1.6);
				$location = new Location($ex, $ey, $ez, $this->world, 0, 0);
				$entity = match($crowd ? [0, 5][$random->nextBoundedInt(2)] : $random->nextBoundedInt(10)){
					0, 1, 2 => $this->fakePlayer(Player::class, $ex, $ey, $ez),
					3 => $this->fakePlayer(TouchRecordingPlayer::class, $ex, $ey, $ez),
					4 => $this->fakePlayer(DespawnFlagRecordingPlayer::class, $ex, $ey, $ez),
					5 => new Zombie($location),
					6 => new TouchRecordingZombie($location),
					7 => $random->nextBoolean() ? new AliveRecordingZombie($location) : new WorldRecordingZombie($location),
					8 => new ItemEntity($location, VanillaItems::STICK()),
					default => new Arrow($location, null, false),
				};
				if($entity instanceof ItemEntity){
					$entity->setPickupDelay(1000); //touching it does nothing, but it is an entity which may react
				}
				if($random->nextBoundedInt(3) === 0){
					//a box which does not match the position (nor the chunk): the scan reads both as they are
					$minX = $x + self::offset($random, 1.3) - 0.3;
					$maxX = $x + self::offset($random, 1.3) + 0.3;
					$minZ = $z + self::offset($random, 1.3) - 0.3;
					$maxZ = $z + self::offset($random, 1.3) + 0.3;
					$minY = $y + self::offset($random, 2.3);
					$entity->boundingBox = new AxisAlignedBB(min($minX, $maxX), $minY, min($minZ, $maxZ), max($minX, $maxX), max($minY, $y + 3), max($minZ, $maxZ));
				}
				$state = $random->nextBoundedInt(8);
				if($state === 0){
					$entity->flagForDespawn();
				}elseif($state === 1){
					(new \ReflectionProperty(Entity::class, "health"))->setValue($entity, 0.0);
				}
				if($random->nextBoundedInt($crowd ? 12 : 4) === 0){
					unset($this->world->updateEntities[$entity->getId()]);
				}
				$entities[] = $entity;
			}
			if($random->nextBoolean()){
				//the order of the schedule matters: shuffle it
				$schedule = $this->world->updateEntities;
				$keys = array_keys($schedule);
				for($i = count($keys) - 1; $i > 0; --$i){
					$j = $random->nextBoundedInt($i + 1);
					[$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
				}
				$this->world->updateEntities = [];
				foreach($keys as $key){
					$this->world->updateEntities[$key] = $schedule[$key];
				}
			}

			$near = $this->world->getNearbyEntities($toucher->boundingBox->expandedCopy(1, 0.5, 1), $toucher);
			if(count($near) > 0){
				$allInert = true;
				foreach($near as $entity){
					if(!isset($this->world->updateEntities[$entity->getId()])){
						++$rescheduled;
					}
					$allInert = $allInert && $this->isInert($entity);
				}
				if($allInert){
					++$skipped;
				}else{
					++$scanned;
				}
			}

			$schedule = $this->world->updateEntities;
			self::$log = [];
			self::oldCheckNearEntities($toucher);
			$oldLog = self::$log;
			$oldSchedule = array_keys($this->world->updateEntities);

			$this->world->updateEntities = $schedule;
			self::$log = [];
			self::newCheckNearEntities($toucher);
			self::assertSame($oldLog, self::$log, "scenario $scenario: calls");
			self::assertSame($oldSchedule, array_keys($this->world->updateEntities), "scenario $scenario: update schedule");

			foreach($entities as $entity){
				if(!$entity instanceof Player){
					$entity->close();
				}
			}
			$this->removeFakePlayers();
		}

		//the scenarios cover both ways: scans skipped, and scans run because of a reacting or unscheduled entity
		self::assertGreaterThan(50, $skipped, "scans skipped");
		self::assertGreaterThan(50, $scanned, "scans run");
		self::assertGreaterThan(20, $rescheduled, "unscheduled entities touched");
	}

	public function testOnlyEntitiesWhichMayReactAreTouched() : void{
		$isTouchInert = new \ReflectionMethod(Player::class, "isTouchInert");
		$location = new Location(0.5, 1, 0.5, $this->world, 0, 0);
		self::assertTrue($isTouchInert->invoke(null, $this->fakePlayer(Player::class, 0.5, 1, 0.5)));
		self::assertTrue($isTouchInert->invoke(null, new Zombie($location)));
		self::assertFalse($isTouchInert->invoke(null, $this->fakePlayer(TouchRecordingPlayer::class, 0.5, 1, 0.5)));
		self::assertFalse($isTouchInert->invoke(null, $this->fakePlayer(DespawnFlagRecordingPlayer::class, 0.5, 1, 0.5)));
		self::assertFalse($isTouchInert->invoke(null, new AliveRecordingZombie($location)));
		self::assertFalse($isTouchInert->invoke(null, new WorldRecordingZombie($location)));
		self::assertFalse($isTouchInert->invoke(null, new ItemEntity($location, VanillaItems::STICK())));
		self::assertFalse($isTouchInert->invoke(null, new Arrow($location, null, false)));

		//a PHPUnit mock overrides every method
		$mock = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($mock, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($mock, new NullThreadSafeLogger());
		self::assertFalse($isTouchInert->invoke(null, $mock));
	}
}

/**
 * A player reacting to touches (overrides onCollideWithPlayer()).
 */
final class TouchRecordingPlayer extends Player{
	public function onCollideWithPlayer(Player $player) : void{
		CheckNearEntitiesEquivalenceTest::$log[] = "touch player " . $this->getId() . " by " . $player->getId();
	}
}

/**
 * A player overriding isFlaggedForDespawn().
 */
final class DespawnFlagRecordingPlayer extends Player{
	public function isFlaggedForDespawn() : bool{
		CheckNearEntitiesEquivalenceTest::$log[] = "flag " . $this->getId();
		return parent::isFlaggedForDespawn();
	}
}

final class TouchRecordingZombie extends Zombie implements NeverSavedWithChunkEntity{
	public function onCollideWithPlayer(Player $player) : void{
		CheckNearEntitiesEquivalenceTest::$log[] = "touch zombie " . $this->getId() . " by " . $player->getId();
	}
}

final class AliveRecordingZombie extends Zombie implements NeverSavedWithChunkEntity{
	public function isAlive() : bool{
		CheckNearEntitiesEquivalenceTest::$log[] = "alive " . $this->getId();
		return parent::isAlive();
	}
}

final class WorldRecordingZombie extends Zombie implements NeverSavedWithChunkEntity{
	public function getWorld() : World{
		CheckNearEntitiesEquivalenceTest::$log[] = "world " . $this->id;
		return parent::getWorld();
	}
}
