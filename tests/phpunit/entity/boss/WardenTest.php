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

namespace pocketmine\entity\boss;

use pocketmine\block\SculkSensor;
use pocketmine\block\SculkShrieker;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\monster\WardenAnger;
use pocketmine\entity\object\ArmorStand;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\Position;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function max;

final class WardenTest extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $fakePlayers = [];

	protected function tearDown() : void{
		foreach($this->fakePlayers as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->fakePlayers = [];
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, standing in the test world (see CrowdPushTest).
	 */
	private function fakePlayer(float $x, float $z, GameMode $mode) : Player{
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $this->loc($x, 1, $z));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		(new \ReflectionProperty(Human::class, "uuid"))->setValue($player, Uuid::uuid4());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($x - 0.3, 1, $z - 0.3, $x + 0.3, 2.8, $z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, $mode);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$this->world->addEntity($player);
		$this->fakePlayers[] = $player;
		return $player;
	}

	private function stillWarden(float $x, float $z) : Warden{
		$warden = new Warden($this->loc($x, 1, $z));
		$warden->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		return $warden;
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A cow that stays where it is (its footsteps would be vibrations too).
	 */
	private function stillCow(float $x, float $z) : Cow{
		$cow = new Cow($this->loc($x, 1, $z));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		return $cow;
	}

	public function testAngerAccumulatesCapsAndDecays() : void{
		$anger = new WardenAnger();
		self::assertSame(35, $anger->increase(1, false, WardenAnger::DEFAULT_ANNOYINGNESS));
		self::assertSame(70, $anger->increase(1, false, WardenAnger::DEFAULT_ANNOYINGNESS));
		self::assertFalse($anger->isAngryAt(1));
		self::assertNull($anger->getAngriest());
		self::assertSame(105, $anger->increase(1, false, WardenAnger::DEFAULT_ANNOYINGNESS));
		self::assertTrue($anger->isAngryAt(1));
		self::assertSame(1, $anger->getAngriest());
		self::assertSame(WardenAnger::MAX_ANGER, $anger->increase(1, false, 1000));

		$anger->decay(70);
		self::assertSame(80, $anger->get(1));
		$anger->decay(80);
		self::assertSame(0, $anger->get(1));
		self::assertSame([], $anger->getAll(), "suspects with no anger left are forgotten");
	}

	public function testAngriestPrefersPlayers() : void{
		$anger = new WardenAnger();
		$anger->increase(1, false, 150); //a mob, angriest
		$anger->increase(2, true, 90); //a player
		$anger->increase(3, true, 40); //a player, not angry enough
		self::assertSame(2, $anger->getAngriest());
		self::assertSame(1, $anger->getAngriest(fn(int $id) => $id !== 2), "invalid suspects are skipped");
		self::assertSame(150, $anger->getAngerLevel());
	}

	public function testVibrationMakesWardenSuspicious() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->stillCow(6.5, 0.5);
		$this->runTicks(2);
		$this->world->addGameEvent(new Vector3(6.5, 1, 0.5), GameEvent::BLOCK_PLACE, $cow);
		self::assertTrue($warden->isListening(), "the warden should hear a block placed 6 blocks away");
		$this->runTicks(8);
		self::assertFalse($warden->isListening());
		//anger decays by 1 every second (at a tick depending on the entity ID)
		self::assertEqualsWithDelta(WardenAnger::DEFAULT_ANNOYINGNESS, $warden->getAnger()->get($cow->getId()), 1);
		self::assertNotNull($warden->getSuspiciousLocation());
	}

	public function testVibrationBeyondRangeIsNotHeard() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->stillCow(20.5, 0.5);
		$this->runTicks(2);
		$this->world->addGameEvent(new Vector3(20.5, 1, 0.5), GameEvent::BLOCK_PLACE, $cow);
		self::assertFalse($warden->isListening());
	}

	public function testWoolBlocksVibrations() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->stillCow(6.5, 0.5);
		for($y = 1; $y <= 6; ++$y){
			for($z = -3; $z <= 3; ++$z){
				$this->set(3, $y, $z, VanillaBlocks::WOOL());
			}
		}
		$this->runTicks(2);
		$this->world->addGameEvent(new Vector3(6.5, 1, 0.5), GameEvent::BLOCK_PLACE, $cow);
		self::assertFalse($warden->isListening());
	}

	public function testRepeatedVibrationsMakeWardenAngryAndAttack() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->stillCow(4.5, 0.5);
		$this->runTicks(2);
		$roared = false;
		$maxAnger = 0;
		for($i = 0; $i < 3; ++$i){
			$this->world->addGameEvent(new Vector3(4.5, 1, 0.5), GameEvent::ENTITY_DAMAGE, $cow);
			//the warden can't hear for 2 seconds after a vibration reaches it
			for($t = 0; $t < Warden::VIBRATION_COOLDOWN + 10; ++$t){
				$this->runTicks(1);
				$roared = $roared || $warden->getState() === Warden::STATE_ROARING;
				$maxAnger = max($maxAnger, $warden->getAnger()->get($cow->getId()));
			}
		}
		self::assertGreaterThanOrEqual(WardenAnger::ANGRY_THRESHOLD, $maxAnger);
		//roars, then goes for the cow
		for($i = 0; $i < Warden::ROAR_TICKS + 40 && $warden->getAttackTarget() !== $cow; ++$i){
			$this->runTicks(1);
			$roared = $roared || $warden->getState() === Warden::STATE_ROARING;
		}
		self::assertTrue($roared, "the warden should roar at its new target");
		self::assertSame($cow, $warden->getAttackTarget());
		$health = $cow->getHealth();
		for($i = 0; $i < 100 && $cow->getHealth() >= $health; ++$i){
			$this->runTicks(1);
		}
		self::assertLessThan($health, $cow->getHealth(), "the warden should hit the cow");
	}

	public function testBeingHurtMakesWardenAngryAtAttacker() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->stillCow(3.5, 0.5);
		$warden->attack(new EntityDamageByEntityEvent($cow, $warden, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
		self::assertSame(WardenAnger::HURT_ANNOYINGNESS, $warden->getAnger()->get($cow->getId()));
		self::assertSame(WardenAnger::HURT_ANNOYINGNESS, $warden->getAngerLevel());
	}

	public function testSonicBoomGoesThroughWalls() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$cow = $this->stillCow(8.5, 0.5);
		//a closed stone box around the cow: unreachable and out of sight
		for($x = 6; $x <= 11; ++$x){
			for($z = -2; $z <= 3; ++$z){
				for($y = 1; $y <= 4; ++$y){
					if($x === 6 || $x === 11 || $z === -2 || $z === 3 || $y === 4){
						$this->set($x, $y, $z, VanillaBlocks::STONE());
					}
				}
			}
		}
		$this->runTicks(2);
		$warden->increaseAnger($cow, WardenAnger::ANGRY_THRESHOLD + 10);
		$health = $cow->getHealth();
		$boomed = false;
		$targetTick = null;
		for($i = 0; $i < 450 && $cow->getHealth() >= $health; ++$i){
			if($warden->getAttackTarget() === $cow){
				$targetTick ??= $this->tick;
			}
			if(!$boomed && $warden->getState() === Warden::STATE_SONIC_BOOM){
				$boomed = true;
				//like BDS: no sonic boom during the first 10 seconds at a new target
				self::assertNotNull($targetTick);
				self::assertGreaterThanOrEqual(Warden::SONIC_BOOM_TARGET_COOLDOWN, $this->tick - $targetTick);
			}
			$this->runTicks(1);
		}
		self::assertTrue($boomed, "the warden should use the sonic boom");
		self::assertEqualsWithDelta($health - Warden::SONIC_BOOM_DAMAGE, $cow->getHealth(), 0.01, "sonic boom deals 10 damage through walls");
	}

	public function testEmergingWardenDoesNotHear() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$warden->startEmerging();
		$cow = $this->stillCow(4.5, 0.5);
		$this->runTicks(2);
		self::assertTrue($warden->isEmerging());
		$this->world->addGameEvent(new Vector3(4.5, 1, 0.5), GameEvent::BLOCK_PLACE, $cow);
		self::assertFalse($warden->isListening());
		$this->runTicks(Warden::EMERGE_TICKS);
		self::assertFalse($warden->isEmerging());
	}

	public function testDigsBackAfterBeingIdle() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$warden->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, true);
		$this->runTicks(Warden::DIG_IDLE_TICKS + 5);
		self::assertTrue($warden->isDigging());
		$this->runTicks(Warden::DIG_TICKS + 2);
		self::assertTrue($warden->isClosed() || $warden->isFlaggedForDespawn());
	}

	public function testDropsSculkCatalyst() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$drops = $warden->getDrops();
		self::assertCount(1, $drops);
		self::assertTrue($drops[0]->equals(VanillaBlocks::SCULK_CATALYST()->asItem()));
		self::assertFalse($drops[0]->equals(VanillaItems::NETHER_STAR()));
	}

	public function testNeverDespawns() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		self::assertFalse($warden->canDespawn(), "wardens only leave by digging");
	}

	public function testSonicBoomDamageScalesWithDifficultyForPlayers() : void{
		self::assertSame(0.0, Warden::getSonicBoomDamage(true, World::DIFFICULTY_PEACEFUL));
		self::assertSame(6.0, Warden::getSonicBoomDamage(true, World::DIFFICULTY_EASY));
		self::assertSame(10.0, Warden::getSonicBoomDamage(true, World::DIFFICULTY_NORMAL));
		self::assertSame(15.0, Warden::getSonicBoomDamage(true, World::DIFFICULTY_HARD));
		self::assertSame(10.0, Warden::getSonicBoomDamage(false, World::DIFFICULTY_HARD), "mobs always take 10");
	}

	public function testEmergingWardenIgnoresHitsExplosionsAndPushes() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$warden->startEmerging();
		$cow = $this->stillCow(2.5, 0.5);
		$this->runTicks(2);
		$health = $warden->getHealth();
		$ev = new EntityDamageByEntityEvent($cow, $warden, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 20);
		$warden->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertSame(0, $warden->getAnger()->get($cow->getId()), "a cancelled hit doesn't anger it");
		(new Explosion(Position::fromObject(new Vector3(2.5, 1, 0.5), $this->world), 4))->explodeB();
		self::assertSame($health, $warden->getHealth(), "no damage while emerging");
		self::assertLessThan(1e-6, $warden->getMotion()->lengthSquared(), "explosions don't push it while emerging");
		self::assertFalse($warden->canBePushedBy($cow));

		$void = new EntityDamageEvent($warden, EntityDamageEvent::CAUSE_VOID, 4);
		$warden->attack($void);
		self::assertFalse($void->isCancelled(), "the void still hurts it");

		$this->runTicks(Warden::EMERGE_TICKS);
		self::assertFalse($warden->isEmerging());
		self::assertTrue($warden->canBePushedBy($cow));
		$hit = new EntityDamageByEntityEvent($cow, $warden, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2);
		$warden->attack($hit);
		self::assertFalse($hit->isCancelled());
	}

	public function testWardenCanDrownButNotBurn() : void{
		$warden = new Warden($this->loc(0.5, 1, 0.5));
		$ev = new EntityDamageEvent($warden, EntityDamageEvent::CAUSE_DROWNING, 2);
		$warden->attack($ev);
		self::assertFalse($ev->isCancelled());
		$fire = new EntityDamageEvent($warden, EntityDamageEvent::CAUSE_FIRE, 2);
		$warden->attack($fire);
		self::assertTrue($fire->isCancelled());
	}

	/**
	 * A shrieker on the floor with stone from the given height up to y=8 around it: a tunnel of that height.
	 */
	private function shriekerInTunnel(int $height) : SculkShrieker{
		for($x = -7; $x <= 7; ++$x){
			for($z = -7; $z <= 7; ++$z){
				for($y = 1 + $height; $y <= 8; ++$y){
					$this->set($x, $y, $z, VanillaBlocks::STONE());
				}
			}
		}
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SHRIEKER());
		$shrieker = $this->world->getBlockAt(0, 1, 0);
		self::assertInstanceOf(SculkShrieker::class, $shrieker);
		return $shrieker;
	}

	public function testSummonRejectedInTwoHighTunnel() : void{
		$shrieker = $this->shriekerInTunnel(2);
		self::assertFalse(SculkShrieker::hasRoomForWarden($this->world, new Vector3(3.5, 1, 3.5)));
		for($i = 0; $i < 5; ++$i){
			self::assertNull($shrieker->trySummonWarden(), "no warden with its head in the ceiling");
		}
	}

	public function testSummonInThreeHighTunnel() : void{
		$shrieker = $this->shriekerInTunnel(3);
		self::assertTrue(SculkShrieker::hasRoomForWarden($this->world, new Vector3(3.5, 1, 3.5)));
		$warden = $shrieker->trySummonWarden();
		self::assertNotNull($warden);
		self::assertSame(1.0, $warden->getPosition()->y);
		self::assertTrue($warden->isEmerging());
		$warden->flagForDespawn();
	}

	public function testSummonRejectsWater() : void{
		$this->set(3, 1, 3, VanillaBlocks::WATER());
		self::assertFalse(SculkShrieker::hasRoomForWarden($this->world, new Vector3(3.5, 1, 3.5)));
		self::assertTrue(SculkShrieker::hasRoomForWarden($this->world, new Vector3(5.5, 1, 5.5)));
	}

	public function testArmorStandsAndCreativePlayersAreNotHeard() : void{
		$warden = $this->stillWarden(0.5, 0.5);
		$stand = new ArmorStand($this->loc(4.5, 1, 0.5));
		$creative = $this->fakePlayer(4.5, 2.5, GameMode::CREATIVE);
		$this->runTicks(2);
		self::assertFalse($warden->isValidSuspect($stand));
		self::assertFalse($warden->isValidSuspect($creative));
		$this->world->addGameEvent(new Vector3(4.5, 1, 0.5), GameEvent::BLOCK_PLACE, $stand);
		self::assertFalse($warden->isListening(), "armor stands are inanimate");
		$this->world->addGameEvent(new Vector3(4.5, 1, 2.5), GameEvent::BLOCK_PLACE, $creative);
		self::assertFalse($warden->isListening(), "creative players are not heard");
		self::assertSame(0, $warden->increaseAnger($stand, 100));
		$survival = $this->fakePlayer(4.5, -1.5, GameMode::SURVIVAL);
		$this->world->addGameEvent(new Vector3(4.5, 1, -1.5), GameEvent::BLOCK_PLACE, $survival);
		self::assertTrue($warden->isListening());
	}

	public function testAngerIsKeptFarAwayAndDecays() : void{
		$warden = $this->stillWarden(0.5, 0.5);
		$cow = $this->stillCow(3.5, 0.5);
		$this->runTicks(2);
		$warden->increaseAnger($cow, 60);
		$cow->teleport(new Vector3(40.5, 1, 0.5));
		$this->runTicks(100);
		self::assertTrue($warden->isValidSuspect($cow));
		self::assertEqualsWithDelta(55, $warden->getAnger()->get($cow->getId()), 1, "anger only decays, by 1 every second");
	}

	public function testWardenStepsDoNotTriggerSensors() : void{
		$warden = $this->stillWarden(0.5, 0.5);
		$cow = $this->stillCow(0.5, 4.5);
		$this->set(3, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->runTicks(2);
		$this->world->addGameEvent(new Vector3(0.5, 1, 0.5), GameEvent::STEP, $warden);
		$this->runTicks(6);
		$sensor = $this->world->getBlockAt(3, 1, 0);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		self::assertSame(SculkSensor::PHASE_INACTIVE, $sensor->getPhase(), "minecraft:vibration_damper");
		$this->world->addGameEvent(new Vector3(0.5, 1, 1.5), GameEvent::STEP, $cow);
		$this->runTicks(6);
		$sensor = $this->world->getBlockAt(3, 1, 0);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		self::assertNotSame(SculkSensor::PHASE_INACTIVE, $sensor->getPhase(), "a cow's steps are heard");
	}

	private static function ambientSoundEvent(Warden $warden) : ?string{
		$property = $warden->getNetworkProperties()->getAll()[EntityMetadataProperties::AMBIENT_SOUND_EVENT] ?? null;
		return $property instanceof StringMetadataProperty ? $property->getValue() : null;
	}

	public function testAmbientSoundIntervalAndNames() : void{
		$warden = $this->stillWarden(0.5, 0.5);
		$cow = $this->stillCow(3.5, 0.5);
		$delay = new \ReflectionMethod(Warden::class, "nextAmbientSoundDelay");
		for($i = 0; $i < 50; ++$i){
			$ticks = $delay->invoke($warden);
			self::assertGreaterThanOrEqual(Warden::AMBIENT_SOUND_MIN_TICKS, $ticks);
			self::assertLessThanOrEqual(Warden::AMBIENT_SOUND_MAX_TICKS, $ticks);
		}
		$this->runTicks(2);
		self::assertSame("ambient", self::ambientSoundEvent($warden));
		$warden->increaseAnger($cow, 45);
		$this->runTicks(1);
		self::assertSame("agitated", self::ambientSoundEvent($warden));
		$warden->increaseAnger($cow, 40);
		$this->runTicks(1);
		self::assertSame("angry", self::ambientSoundEvent($warden));
	}

	public function testAngerStateAndSuspiciousLocationAreSaved() : void{
		$warden = $this->stillWarden(0.5, 0.5);
		$player = $this->fakePlayer(4.5, 0.5, GameMode::SURVIVAL);
		$this->runTicks(2);
		$warden->increaseAnger($player, 50);
		$warden->setSuspiciousLocation(new Vector3(7.2, 1, -3.4));
		$nbt = $warden->saveNBT();
		$suspects = $nbt->getTag("Suspects");
		self::assertInstanceOf(ListTag::class, $suspects);
		self::assertCount(1, $suspects);
		$entry = $suspects->get(0);
		self::assertInstanceOf(CompoundTag::class, $entry);
		$uuid = $player->getUniqueId()->toString();
		self::assertSame($uuid, $entry->getString("UUID"));
		self::assertSame(50, $entry->getInt("Anger"));

		$loaded = new Warden($this->loc(0.5, 1, 0.5), $nbt);
		$location = $loaded->getSuspiciousLocation();
		self::assertNotNull($location);
		self::assertTrue($location->equals(new Vector3(7, 1, -4)));
		self::assertSame([$uuid => 50], (new \ReflectionProperty(Warden::class, "savedPlayerAnger"))->getValue($loaded));
		$resaved = $loaded->saveNBT()->getTag("Suspects");
		self::assertInstanceOf(ListTag::class, $resaved);
		self::assertSame($suspects->toString(), $resaved->toString(), "anger towards offline players is kept");
		$loaded->flagForDespawn();
	}

	public function testDiggingStateIsSaved() : void{
		$digging = $this->stillWarden(0.5, 0.5);
		$this->runTicks(Warden::DIG_IDLE_TICKS + 5);
		self::assertTrue($digging->isDigging());
		$reloaded = new Warden($this->loc(0.5, 1, 0.5), $digging->saveNBT());
		self::assertTrue($reloaded->isDigging(), "a digging warden keeps digging after a reload");
		$reloaded->flagForDespawn();
	}
}
