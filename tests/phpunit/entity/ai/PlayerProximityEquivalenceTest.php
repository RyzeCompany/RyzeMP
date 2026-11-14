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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\ai\hostile\LookDetection;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Enderman;
use pocketmine\entity\monster\Endermite;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Stray;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\mobs\MobDespawner;
use pocketmine\world\Position;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function array_values;
use function asin;
use function atan2;
use function count;
use function max;
use function mt_rand;
use function mt_srand;
use function rad2deg;
use function sqrt;

/**
 * The player proximity scans (despawn, activation, nearest player, target searches, enderman stares, slime contact)
 * read the live location of players whose class keeps Entity::getLocation() and test the distance before the other
 * checks, which have no side effects. They must give exactly what the previous code gave, for players of plain and of
 * overriding classes, in the same order (first match, ties, filter calls).
 */
final class PlayerProximityEquivalenceTest extends MobTestCase{
	private const SCENARIOS = 250;

	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $fakePlayers = [];

	protected function getChunkRadius() : int{
		return 5; //x/z -80..95 loaded
	}

	protected function tearDown() : void{
		$this->removeFakePlayers();
		ProximityTestPlayer::$attacked = [];
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		parent::tearDown();
	}

	private function removeFakePlayers() : void{
		foreach($this->fakePlayers as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->fakePlayers = [];
	}

	private function loc(float $x, float $y, float $z, float $yaw = 0.0, float $pitch = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, $pitch);
	}

	/**
	 * A Player built without its constructor, standing in the test world.
	 *
	 * @phpstan-param class-string<ProximityTestPlayer> $class
	 */
	private function fakePlayer(string $class, Vector3 $pos, float $yaw, float $pitch, GameMode $mode, bool $alive, bool $connected, int $helmet) : Player{
		$player = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $this->loc($pos->x, $pos->y, $pos->z, $yaw, $pitch));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($pos->x - 0.3, $pos->y, $pos->z - 0.3, $pos->x + 0.3, $pos->y + 1.8, $pos->z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, $mode);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn($connected);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		if(!$alive){
			(new \ReflectionProperty(Entity::class, "health"))->setValue($player, 0.0);
		}
		$armor = new ArmorInventory($player);
		if($helmet === 1){
			$armor->setHelmet(VanillaBlocks::CARVED_PUMPKIN()->asItem());
		}elseif($helmet === 2){
			$armor->setHelmet(VanillaItems::DIAMOND_HELMET());
		}
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, $armor);
		$this->world->addEntity($player);
		$this->fakePlayers[] = $player;
		return $player;
	}

	/**
	 * A coordinate offset: random within the range, or right at it (where rounding decides).
	 */
	private static function offset(Random $random, float $range) : float{
		return match($random->nextBoundedInt(4)){
			0 => ($random->nextBoolean() ? 1 : -1) * $range,
			1 => ($random->nextBoolean() ? 1 : -1) * ($range + [0.0, 0.00001, -0.00001, 1e-12][$random->nextBoundedInt(4)]),
			default => ($random->nextFloat() * 2 - 1) * ($range + 8)
		};
	}

	/**
	 * Fills the world with random players around the centre: plain and overriding classes, every game mode, some dead
	 * or disconnected, some wearing a carved pumpkin, some looking at $lookAt (a point, like an enderman's eyes).
	 */
	private function randomPlayers(Random $random, Vector3 $centre, float $range, ?Vector3 $lookAt = null) : void{
		$this->removeFakePlayers();
		$count = $random->nextBoundedInt(14);
		for($i = 0; $i < $count; ++$i){
			$pos = $random->nextBoundedInt(5) === 0 && $i > 0 ?
				$this->fakePlayers[$random->nextBoundedInt(count($this->fakePlayers))]->getPosition() : //ties
				new Vector3(
					$centre->x + self::offset($random, $range),
					max(1, $centre->y + ($random->nextBoundedInt(3) === 0 ? self::offset($random, $range) : $random->nextBoundedInt(5) - 2)),
					$centre->z + self::offset($random, $range)
				);
			$yaw = $random->nextFloat() * 360;
			$pitch = $random->nextFloat() * 180 - 90;
			if($lookAt !== null && $random->nextBoundedInt(3) !== 0){
				$dx = $lookAt->x - $pos->x;
				$dy = $lookAt->y - ($pos->y + 1.62);
				$dz = $lookAt->z - $pos->z;
				$len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
				if($len > 0){
					$yaw = rad2deg(atan2(-$dx, $dz)) + ($random->nextBoolean() ? 0 : ($random->nextFloat() - 0.5) * 3);
					$pitch = rad2deg(-asin($dy / $len)) + ($random->nextBoolean() ? 0 : ($random->nextFloat() - 0.5) * 3);
				}
			}
			$this->fakePlayer(
				$random->nextBoundedInt(3) === 0 ? ProximityTestOverridingPlayer::class : ProximityTestPlayer::class,
				$pos,
				$yaw,
				$pitch,
				[GameMode::SURVIVAL, GameMode::SURVIVAL, GameMode::ADVENTURE, GameMode::CREATIVE, GameMode::SPECTATOR][$random->nextBoundedInt(5)],
				$random->nextBoundedInt(6) !== 0,
				$random->nextBoundedInt(6) !== 0,
				$random->nextBoundedInt(3)
			);
		}
	}

	public function testLiveLocationDetection() : void{
		$plain = $this->fakePlayer(ProximityTestPlayer::class, new Vector3(1, 1, 1), 0, 0, GameMode::SURVIVAL, true, true, 0);
		$overriding = $this->fakePlayer(ProximityTestOverridingPlayer::class, new Vector3(2, 1, 2), 0, 0, GameMode::SURVIVAL, true, true, 0);
		$mock = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($mock, true); //never disposed like a real player
		(new \ReflectionProperty(Player::class, "logger"))->setValue($mock, new NullThreadSafeLogger());
		$zombie = new Zombie($this->loc(3, 1, 3));

		self::assertSame((fn() => $this->location)->call($plain), Mob::getLiveLocationOf($plain));
		self::assertSame($plain->boundingBox, Mob::getLiveBoundingBoxOf($plain));
		self::assertSame($zombie->getLiveLocation(), Mob::getLiveLocationOf($zombie));
		self::assertNull(Mob::getLiveLocationOf($overriding));
		self::assertNull(Mob::getLiveBoundingBoxOf($overriding));
		self::assertNull(Mob::getLiveLocationOf($mock));
		self::assertNull(Mob::getLiveBoundingBoxOf($mock));
		$zombie->close();
	}

	/**
	 * MobDespawner before the change, verbatim.
	 */
	private static function oldNearest(Living $mob) : ?float{
		$world = $mob->getWorld();
		$pos = $mob->getLocation();
		$nearest = null;
		foreach($world->getPlayers() as $player){
			if($player->isSpectator()){
				continue;
			}
			$d = $player->getLocation()->distanceSquared($pos);
			if($nearest === null || $d < $nearest){
				$nearest = $d;
			}
		}
		return $nearest;
	}

	private static function oldShouldDespawn(Living $mob, int &$farTicks, int $interval) : bool{
		$world = $mob->getWorld();
		$nearest = self::oldNearest($mob);
		$instant = MobDespawner::getInstantDistance($world);
		if($mob instanceof \pocketmine\entity\aquatic\AbstractFish){
			$instant = max(MobDespawner::RANDOM_DISTANCE, $instant < MobDespawner::FISH_INSTANT_DISTANCE ? $instant : MobDespawner::FISH_INSTANT_DISTANCE);
		}
		if($nearest === null || $nearest > $instant ** 2){
			return true;
		}
		if($nearest > MobDespawner::RANDOM_DISTANCE ** 2){
			$farTicks += $interval;
			return $farTicks > MobDespawner::RANDOM_DELAY && mt_rand(0, MobDespawner::RANDOM_CHANCE_PER_TICK - 1) < $interval;
		}
		$farTicks = 0;
		return false;
	}

	public function testDespawnDistances() : void{
		$random = new Random(1001);
		$cod = new Cod($this->loc(0.5, 2, 0.5));
		$zombie = new Zombie($this->loc(-3.25, 1, 7.75));
		$nearest = new \ReflectionMethod(MobDespawner::class, "nearestPlayerDistanceSquared");
		for($i = 0; $i < self::SCENARIOS; ++$i){
			$mob = $random->nextBoolean() ? $cod : $zombie;
			$this->randomPlayers($random, $mob->getPosition(), [32.0, 40.0, 44.0, 128.0][$random->nextBoundedInt(4)]);
			self::assertSame(self::oldNearest($mob), $nearest->invoke(null, $this->world, $mob), "scenario $i");

			$oldFar = $newFar = $random->nextBoundedInt(800);
			mt_srand($i);
			$old = self::oldShouldDespawn($mob, $oldFar, 20);
			$oldNext = mt_rand();
			mt_srand($i);
			$new = MobDespawner::shouldDespawn($mob, $newFar, 20);
			self::assertSame($old, $new, "scenario $i");
			self::assertSame($oldFar, $newFar, "scenario $i");
			self::assertSame($oldNext, mt_rand(), "scenario $i: same randomness used");

			$oldNearest = self::oldNearest($mob);
			$instant = MobDespawner::getInstantDistance($this->world);
			if($mob instanceof Cod){
				$instant = max(MobDespawner::RANDOM_DISTANCE, $instant < MobDespawner::FISH_INSTANT_DISTANCE ? $instant : MobDespawner::FISH_INSTANT_DISTANCE);
			}
			self::assertSame($oldNearest === null || $oldNearest > $instant ** 2, MobDespawner::shouldDespawnWhileFrozen($mob), "scenario $i");
		}
		$cod->close();
		$zombie->close();
	}

	public function testActivationAndValidTarget() : void{
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: true);
		$random = new Random(77);
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$sensing = $zombie->getSensing();
		$activation = new \ReflectionMethod(Mob::class, "updateActivation");
		$noActionTicks = new \ReflectionProperty(Mob::class, "noActionTicks");
		for($i = 0; $i < self::SCENARIOS; ++$i){
			$this->randomPlayers($random, $zombie->getPosition(), Mob::ACTIVATION_RANGE);
			$old = false;
			foreach($this->world->getPlayers() as $player){
				if($player->getLocation()->distanceSquared($zombie->getLocation()) <= Mob::ACTIVATION_RANGE ** 2){
					$old = true;
					break;
				}
			}
			$ticks = $noActionTicks->getValue($zombie);
			self::assertIsInt($ticks);
			self::assertSame($old, $activation->invoke($zombie), "scenario $i");
			self::assertSame($old ? 0 : $ticks + 20, $noActionTicks->getValue($zombie), "scenario $i");

			$range = [16.0, 24.0, 64.0][$random->nextBoundedInt(3)];
			foreach($this->world->getPlayers() as $player){
				$oldValid = $player->isAlive() && !$player->isClosed() && $player->getWorld() === $zombie->getWorld() &&
					Sensing::isTargetablePlayer($player) && !Sensing::isPluginNpc($player) &&
					$player->getLocation()->distanceSquared($zombie->getLiveLocation()) <= $range * $range;
				self::assertSame($oldValid, $sensing->isValidTarget($player, $range), "scenario $i");
			}
		}
		$zombie->close();
	}

	/**
	 * Sensing::getNearestPlayer() before the change, verbatim.
	 *
	 * @phpstan-param (\Closure(Player) : bool)|null $filter
	 */
	private static function oldNearestPlayer(Mob $mob, float $range, ?\Closure $filter) : ?Player{
		$loc = $mob->getLiveLocation();
		$best = null;
		$bestDist = $range * $range;
		foreach($mob->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator()){
				continue;
			}
			$d = $player->getLocation()->distanceSquared($loc);
			if($d <= $bestDist && ($filter === null || $filter($player))){
				$best = $player;
				$bestDist = $d;
			}
		}
		return $best;
	}

	public function testNearestPlayer() : void{
		$random = new Random(2024);
		$zombie = new Zombie($this->loc(4.5, 1, -2.5));
		for($i = 0; $i < self::SCENARIOS; ++$i){
			$range = [6.0, 8.0, 10.0, 16.0][$random->nextBoundedInt(4)];
			$this->randomPlayers($random, $zombie->getPosition(), $range);
			self::assertSame(self::oldNearestPlayer($zombie, $range, null), $zombie->getSensing()->getNearestPlayer($range), "scenario $i");

			$seed = $random->nextBoundedInt(1000);
			$log = new ProximityTestLog();
			$filter = function(Player $p) use ($log, $seed) : bool{
				$log->ids[] = $p->getId();
				return (($p->getId() * 31 + $seed) & 3) !== 0;
			};
			$old = self::oldNearestPlayer($zombie, $range, $filter);
			$oldLog = $log->ids;
			$log->ids = [];
			$new = $zombie->getSensing()->getNearestPlayer($range, $filter);
			self::assertSame($old, $new, "scenario $i");
			self::assertSame($oldLog, $log->ids, "scenario $i: same filter calls");
		}
		$zombie->close();
	}

	/**
	 * NearestLivingTargetGoal::findTarget() before the change, verbatim (run bound to the goal).
	 */
	private static function oldFindLivingTarget(NearestLivingTargetGoal $goal) : ?Living{
		return (function() : ?Living{
			$loc = $this->mob->getLiveLocation();
			$r = $this->range;
			$best = null;
			$bestDist = $r * $r;
			$sensing = $this->mob->getSensing();
			$world = $this->mob->getWorld();
			$candidates = $this->playersOnly ?
				$world->getPlayers() :
				$world->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this->mob);
			foreach($candidates as $entity){
				if(!$entity instanceof Living || !$entity->isAlive() || ($entity instanceof Player && !Sensing::isTargetablePlayer($entity))){
					continue;
				}
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d > $bestDist || !($this->filter)($entity) || ($this->mustSee && !$sensing->canSee($entity))){
					continue;
				}
				$best = $entity;
				$bestDist = $d;
			}
			return $best;
		})->call($goal);
	}

	private static function newFindLivingTarget(NearestLivingTargetGoal $goal) : ?Living{
		return (fn() : ?Living => $this->findTarget())->call($goal);
	}

	public function testNearestLivingTarget() : void{
		$random = new Random(4242);
		$drowned = new Drowned($this->loc(0.5, 1, 0.5));
		$calls = new ProximityTestLog();
		$filter = function(Living $e) use ($calls) : bool{
			$calls->ids[] = $e->getId();
			return ($e->getId() % 3) !== 0;
		};
		$others = [];
		for($i = 0; $i < 12; ++$i){
			$others[] = new Skeleton($this->loc($random->nextFloat() * 40 - 20, 1, $random->nextFloat() * 40 - 20));
			$others[] = new ProximityTestOverridingZombie($this->loc($random->nextFloat() * 40 - 20, 1, $random->nextFloat() * 40 - 20));
		}
		for($i = 0; $i < 60; ++$i){
			$this->world->setBlockAt($random->nextBoundedInt(41) - 20, 1 + $random->nextBoundedInt(3), $random->nextBoundedInt(41) - 20, VanillaBlocks::STONE(), false);
		}
		for($i = 0; $i < self::SCENARIOS; ++$i){
			$range = [8.0, 16.0, 20.0][$random->nextBoundedInt(3)];
			$this->randomPlayers($random, $drowned->getPosition(), $range);
			$goal = $random->nextBoolean() ?
				NearestLivingTargetGoal::players($drowned, fn(Player $p) => $filter($p), $range, $random->nextBoolean()) :
				new NearestLivingTargetGoal($drowned, $filter, $range, $random->nextBoolean());
			$drowned->getSensing()->reset();
			$calls->ids = [];
			$old = self::oldFindLivingTarget($goal);
			$oldCalls = $calls->ids;
			$drowned->getSensing()->reset();
			$calls->ids = [];
			$new = self::newFindLivingTarget($goal);
			self::assertSame($old, $new, "scenario $i");
			self::assertSame($oldCalls, $calls->ids, "scenario $i: same filter calls");
		}
		foreach($others as $other){
			$other->close();
		}
		$drowned->close();
	}

	/**
	 * LookDetection::isStaringAt() before the change, verbatim (public for the old findStarer() bound to the enderman).
	 */
	public static function oldIsStaringAt(Living $viewer, Entity $entity) : bool{
		if($viewer->getArmorInventory()->getHelmet()->getTypeId() === VanillaBlocks::CARVED_PUMPKIN()->asItem()->getTypeId()){
			return false;
		}
		[$cos, $dist] = LookDetection::lookCosine($viewer, $entity->getEyePos());
		return $dist > 0 && $cos > 1 - 0.025 / $dist && Sensing::hasLineOfSight($viewer, $entity);
	}

	public function testEndermanStare() : void{
		$random = new Random(1234);
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		for($i = 0; $i < 80; ++$i){
			$this->world->setBlockAt($random->nextBoundedInt(61) - 30, 1 + $random->nextBoundedInt(6), $random->nextBoundedInt(61) - 30, VanillaBlocks::STONE(), false);
		}
		$staring = 0;
		for($i = 0; $i < self::SCENARIOS * 2; ++$i){
			$this->randomPlayers($random, $enderman->getPosition(), [8.0, 32.0, 64.0][$random->nextBoundedInt(3)], $enderman->getEyePos());
			foreach($this->world->getPlayers() as $player){
				if(!$player->isClosed()){
					$old = self::oldIsStaringAt($player, $enderman);
					self::assertSame($old, LookDetection::isStaringAt($player, $enderman), "scenario $i");
					$staring += $old ? 1 : 0;
				}
			}
			$old = (function() : ?Player{
				foreach($this->getWorld()->getPlayers() as $player){
					if(Sensing::isTargetablePlayer($player) && $player->getLocation()->distanceSquared($this->location) <= 64 * 64 && PlayerProximityEquivalenceTest::oldIsStaringAt($player, $this)){
						return $player;
					}
				}
				return null;
			})->call($enderman);
			self::assertSame($old, (fn() : ?Player => $this->findStarer())->call($enderman), "scenario $i");
		}
		self::assertGreaterThan(20, $staring, "the scenarios include players staring at the enderman");
		$enderman->close();
	}

	public function testSlimeContact() : void{
		$random = new Random(99);
		$slime = new Slime($this->loc(0.5, 1, 0.5), null, 4);
		$hurt = new \ReflectionMethod(Slime::class, "hurtTouchingPlayers");
		$touching = 0;
		for($i = 0; $i < self::SCENARIOS * 2; ++$i){
			$this->randomPlayers($random, $slime->getPosition(), [1.0, 1.5, 2.2][$random->nextBoundedInt(3)]);
			$box = $slime->getBoundingBox()->expandedCopy(0.15, 0.15, 0.15);
			$expected = [];
			foreach($this->world->getPlayers() as $player){
				if(Sensing::isTargetablePlayer($player) && $player->getBoundingBox()->intersectsWith($box)){
					$expected[] = $player->getId();
				}
			}
			ProximityTestPlayer::$attacked = [];
			$hurt->invoke($slime);
			self::assertSame($expected, ProximityTestPlayer::$attacked, "scenario $i");
			$touching += count($expected);
		}
		self::assertGreaterThan(20, $touching, "the scenarios include touching players");
		$slime->close();
	}

	public function testEntityClassCounts() : void{
		$random = new Random(5);
		/** @var Entity[] $entities */
		$entities = [];
		$classes = [Endermite::class, Skeleton::class, Zombie::class, Living::class, Player::class];
		$asked = [];
		for($step = 0; $step < 400; ++$step){
			if(count($entities) > 0 && $random->nextBoundedInt(3) === 0){
				$key = $random->nextBoundedInt(count($entities));
				$entities[$key]->close();
				unset($entities[$key]);
				$entities = array_values($entities);
			}else{
				$loc = $this->loc($random->nextFloat() * 60 - 30, 1, $random->nextFloat() * 60 - 30);
				$entities[] = match($random->nextBoundedInt(5)){
					0 => new Endermite($loc),
					1 => new Skeleton($loc),
					2 => new Stray($loc),
					3 => new Drowned($loc),
					default => new Zombie($loc)
				};
			}
			$class = $classes[$random->nextBoundedInt(count($classes))];
			if($random->nextBoundedInt(4) === 0 || isset($asked[$class])){
				$asked[$class] = true;
				$expected = false;
				foreach($this->world->getEntities() as $entity){
					if($entity instanceof $class){
						$expected = true;
						break;
					}
				}
				self::assertSame($expected, $this->world->hasEntitiesOfClass($class), "step $step, $class");
			}
		}
		foreach($entities as $entity){
			$entity->close();
		}
		foreach($classes as $class){
			self::assertFalse($this->world->hasEntitiesOfClass($class), $class);
		}
	}

	public function testRequiredClassTargetSearch() : void{
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		$filter = fn(Living $e) => $e instanceof Endermite;
		$old = new NearestLivingTargetGoal($enderman, $filter, 64.0, false);
		$new = new NearestLivingTargetGoal($enderman, $filter, 64.0, false, requiredClass: Endermite::class);
		$zombie = new Zombie($this->loc(5, 1, 5));
		self::assertNull(self::oldFindLivingTarget($old));
		self::assertNull(self::newFindLivingTarget($new));
		$far = new Endermite($this->loc(70, 1, 0.5));
		self::assertNull(self::oldFindLivingTarget($old));
		self::assertNull(self::newFindLivingTarget($new));
		$near = new Endermite($this->loc(10, 1, 0.5));
		self::assertSame($near, self::oldFindLivingTarget($old));
		self::assertSame($near, self::newFindLivingTarget($new));
		$near->close();
		$far->close();
		self::assertNull(self::newFindLivingTarget($new));

		//NearestAttackableEntityGoal: the search is skipped after the condition, as before
		$conditionCalls = new ProximityTestLog();
		$goal = new NearestAttackableEntityGoal($enderman, fn(Living $e) => $e instanceof Skeleton, 16.0, false, 3, function() use ($conditionCalls) : bool{
			$conditionCalls->ids[] = 1;
			return true;
		}, Skeleton::class);
		self::assertFalse($goal->canStart());
		self::assertCount(1, $conditionCalls->ids);
		$stray = new Stray($this->loc(6, 1, 0.5));
		self::assertTrue($goal->canStart());
		self::assertCount(2, $conditionCalls->ids);
		$stray->close();
		$zombie->close();
		$enderman->close();
	}
}

/**
 * A player class keeping Entity's location methods (the fast path), recording the attacks it gets.
 */
class ProximityTestPlayer extends Player{
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	public static array $attacked = [];

	public function attack(EntityDamageEvent $source) : void{
		self::$attacked[] = $this->getId();
	}
}

/**
 * A player class overriding the location methods (plugins, test doubles): the scans keep the original order for it.
 */
final class ProximityTestOverridingPlayer extends ProximityTestPlayer{
	public function getLocation() : Location{
		return parent::getLocation();
	}

	public function getPosition() : Position{
		return parent::getPosition();
	}

	public function getBoundingBox() : AxisAlignedBB{
		return parent::getBoundingBox();
	}
}

/**
 * Calls recorded by the closures of the tests.
 */
final class ProximityTestLog{
	/**
	 * @var int[]
	 * @phpstan-var list<int>
	 */
	public array $ids = [];
}

/**
 * A plugin-like zombie overriding getLocation().
 */
final class ProximityTestOverridingZombie extends Zombie implements NeverSavedWithChunkEntity{
	public function getLocation() : Location{
		return parent::getLocation();
	}
}
