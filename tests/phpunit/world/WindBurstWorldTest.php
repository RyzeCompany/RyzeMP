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

namespace pocketmine\world;

use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\Candle;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\Lever;
use pocketmine\block\Trapdoor;
use pocketmine\block\utils\LeverFacing;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\BreezeWindCharge;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\entity\vehicle\Boat;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\particle\BreezeWindExplosionParticle;
use pocketmine\world\particle\WindExplosionParticle;
use pocketmine\world\sound\BreezeWindChargeBurstSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationListener;
use function getenv;
use function hrtime;
use function mt_srand;
use function sprintf;

/**
 * Wind charge bursts in a world: block toggling, pushing non-living entities, exposure, the breeze charge numbers,
 * end crystals and reflection.
 */
final class WindBurstWorldTest extends MobTestCase{

	/** @var Entity[] */
	private array $spawned = [];

	protected function getPocketmineYml() : array{
		return ["redstone" => ["enabled" => true]] + parent::getPocketmineYml();
	}

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
	}

	protected function tearDown() : void{
		foreach($this->spawned as $entity){
			if(!$entity->isClosed()){
				$entity->close();
			}
		}
		$this->spawned = [];
		parent::tearDown();
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param T $entity
	 * @phpstan-return T
	 */
	private function spawn(Entity $entity) : Entity{
		$entity->spawnToAll();
		$this->spawned[] = $entity;
		return $entity;
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function door(int $x, int $z, Door $door) : void{
		$door = $door->setFacing(Facing::EAST);
		$this->set($x, 1, $z, (clone $door)->setTop(false));
		$this->set($x, 2, $z, (clone $door)->setTop(true));
	}

	private function playerBurst(Vector3 $center) : void{
		WindBurst::apply($this->world, $center, WindCharge::BURST_RADIUS, WindCharge::BURST_KNOCKBACK_MULTIPLIER, true, null, null, new WindExplosionParticle(), true);
	}

	public function testBurstOperatesBlocks() : void{
		$this->door(1, 0, VanillaBlocks::OAK_DOOR());
		$this->door(-1, 0, VanillaBlocks::IRON_DOOR());
		$this->set(0, 1, 1, VanillaBlocks::LEVER()->setFacing(LeverFacing::UP_AXIS_X));
		$this->set(0, 1, -1, VanillaBlocks::CANDLE()->setLit(true));
		//the other blocks are a burst away, where nothing stands in the way of the rays
		$this->set(10, 1, 1, VanillaBlocks::OAK_TRAPDOOR());
		$this->set(9, 1, 0, VanillaBlocks::OAK_FENCE_GATE());
		$this->set(11, 1, 0, VanillaBlocks::OAK_BUTTON()->setFacing(Facing::UP));
		//bells resist 5 (Java), more than a player charge's rays carry: only the stronger breeze bursts ring them
		$this->set(20, 1, -1, VanillaBlocks::BELL());

		//Bell::ring() only sends a sound, a packet and a BLOCK_CHANGE vibration: record that vibration
		$bellRings = new class implements VibrationListener{
			public int $rings = 0;

			public function getListenerPosition() : Vector3{
				return new Vector3(20.5, 3, 0.5);
			}

			public function getListenerRange() : int{
				return 16;
			}

			public function onVibration(Vector3 $pos, GameEvent $event, ?Entity $source, ?Block $affected, float $distance) : void{
				if($event === GameEvent::BLOCK_CHANGE && $pos->floor()->equals(new Vector3(20, 1, -1))){
					++$this->rings;
				}
			}
		};
		$this->playerBurst(new Vector3(0.5, 1.5, 0.5));
		$this->playerBurst(new Vector3(10.5, 1.5, 0.5));
		$this->world->getVibrations()->addListener($bellRings);
		WindBurst::apply($this->world, new Vector3(20.5, 1.5, 0.5), BreezeWindCharge::BURST_RADIUS, BreezeWindCharge::BURST_KNOCKBACK_MULTIPLIER, false, null, new BreezeWindChargeBurstSound(), new BreezeWindExplosionParticle(), true);
		$this->world->getVibrations()->removeListener($bellRings);
		self::assertSame(1, $bellRings->rings, "bells ring");

		$door = $this->get(1, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		self::assertTrue($door->isOpen(), "wooden doors open");
		$top = $this->get(1, 2, 0);
		self::assertInstanceOf(Door::class, $top);
		self::assertTrue($top->isOpen(), "the whole door toggles once");
		$iron = $this->get(-1, 1, 0);
		self::assertInstanceOf(Door::class, $iron);
		self::assertFalse($iron->isOpen(), "iron doors don't react");
		$lever = $this->get(0, 1, 1);
		self::assertInstanceOf(Lever::class, $lever);
		self::assertTrue($lever->isActivated(), "levers flip");
		$candle = $this->get(0, 1, -1);
		self::assertInstanceOf(Candle::class, $candle);
		self::assertFalse($candle->isLit(), "candles go out");
		$trapdoor = $this->get(10, 1, 1);
		self::assertInstanceOf(Trapdoor::class, $trapdoor);
		self::assertTrue($trapdoor->isOpen());
		$gate = $this->get(9, 1, 0);
		self::assertInstanceOf(FenceGate::class, $gate);
		self::assertTrue($gate->isOpen());
		$button = $this->get(11, 1, 0);
		self::assertInstanceOf(Button::class, $button);
		self::assertTrue($button->isPressed());

		//a second burst closes the door again
		$this->playerBurst(new Vector3(0.5, 1.5, 0.5));
		$door = $this->get(1, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		self::assertFalse($door->isOpen());
	}

	public function testBurstDoesNotReachThroughWalls() : void{
		for($y = 1; $y <= 3; ++$y){
			for($z = -2; $z <= 2; ++$z){
				$this->set(2, $y, $z, VanillaBlocks::OBSIDIAN());
			}
		}
		$this->set(3, 1, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::UP_AXIS_X));
		WindBurst::apply($this->world, new Vector3(1.5, 1.5, 0.5), BreezeWindCharge::BURST_RADIUS, BreezeWindCharge::BURST_KNOCKBACK_MULTIPLIER, false, null, new BreezeWindChargeBurstSound(), new BreezeWindExplosionParticle(), true);
		$lever = $this->get(3, 1, 0);
		self::assertInstanceOf(Lever::class, $lever);
		self::assertFalse($lever->isActivated(), "obsidian stops the burst");
	}

	public function testPoweredDoorIsNotToggled() : void{
		$this->door(1, 0, VanillaBlocks::OAK_DOOR());
		$this->set(2, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		$door = $this->get(1, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		self::assertTrue($door->isOpen(), "opened by the redstone block");
		$this->playerBurst(new Vector3(0.5, 1.5, 0.5));
		$door = $this->get(1, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		self::assertTrue($door->isOpen(), "held open by redstone");
	}

	public function testBurstPushesItemsAndBoatsButNotThroughWalls() : void{
		$item = $this->spawn(new ItemEntity($this->loc(1.5, 1, 0.5), VanillaItems::STICK()));
		$boat = $this->spawn(new Boat($this->loc(-0.5, 1, 0.5)));
		$hidden = $this->spawn(new ItemEntity($this->loc(0.5, 1, 3.5), VanillaItems::STICK()));
		for($y = 1; $y <= 3; ++$y){
			for($x = -1; $x <= 2; ++$x){
				$this->set($x, $y, 2, VanillaBlocks::STONE());
			}
		}

		$pushed = WindBurst::apply($this->world, new Vector3(0.5, 1.2, 0.5), 3.0, 0.6, false);
		self::assertContains($item, $pushed);
		self::assertContains($boat, $pushed);
		self::assertNotContains($hidden, $pushed, "the wall shields it");
		self::assertGreaterThan(0.0, $item->getMotion()->x, "pushed away from the center");
		self::assertLessThan(0.0, $boat->getMotion()->x);
	}

	public function testExposureIsPartialBehindAHalfWall() : void{
		$zombie = $this->spawn(new Zombie($this->loc(2.5, 1, 0.5)));
		self::assertEqualsWithDelta(1.0, WindBurst::getExposure($this->world, new Vector3(0.5, 1.5, 0.5), $zombie), 0.0001);
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		$exposure = WindBurst::getExposure($this->world, new Vector3(0.5, 2.2, 0.5), $zombie);
		self::assertGreaterThan(0.0, $exposure);
		self::assertLessThan(1.0, $exposure);
	}

	public function testExposureOnSlabsAndBehindWalls() : void{
		for($x = -1; $x <= 4; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::OAK_SLAB());
		}
		$zombie = $this->spawn(new Zombie($this->loc(2.5, 1.5, 0.5)));
		//a charge landing on a slab bursts a quarter block above it: the slabs touch the rays' area but block nothing
		$center = new Vector3(0.5, 1.75, 0.5);
		self::assertEqualsWithDelta(1.0, WindBurst::getExposure($this->world, $center, $zombie), 0.0001);

		//a fence post blocks some of the rays (only its post, not its whole block)
		$this->set(1, 2, 0, VanillaBlocks::OAK_FENCE());
		$exposure = WindBurst::getExposure($this->world, $center, $zombie);
		self::assertGreaterThan(0.0, $exposure);
		self::assertLessThan(1.0, $exposure);

		//a full wall up to above the head blocks all of them
		for($y = 2; $y <= 4; ++$y){
			for($z = -2; $z <= 2; ++$z){
				$this->set(1, $y, $z, VanillaBlocks::STONE());
			}
		}
		$this->set(1, 1, 0, VanillaBlocks::STONE());
		self::assertSame(0.0, WindBurst::getExposure($this->world, $center, $zombie));
	}

	public function testBreezeBurstKeepsFallDamageAndPushesLess() : void{
		$center = new Vector3(0.5, 1.2, 0.5);
		$zombie = $this->spawn(new Zombie($this->loc(1.5, 1, 0.5)));
		WindBurst::apply($this->world, $center, BreezeWindCharge::BURST_RADIUS, BreezeWindCharge::BURST_KNOCKBACK_MULTIPLIER, false);
		self::assertNull($zombie->getFallDamageCutoffY(), "negates_fall_damage false");
		$expected = WindBurst::calculateImpulse($center, new Vector3(1.5, 1, 0.5), $zombie->getEyeHeight(), 3.0, 0.6);
		self::assertNotNull($expected);
		self::assertEqualsWithDelta($expected->length(), $zombie->getMotion()->length(), 0.02);

		$other = $this->spawn(new Zombie($this->loc(-1.5, 1, 0.5)));
		WindBurst::apply($this->world, $center, WindCharge::BURST_RADIUS, WindCharge::BURST_KNOCKBACK_MULTIPLIER, true);
		self::assertSame(1.2, $other->getFallDamageCutoffY(), "player charges negate fall damage");
	}

	public function testWindChargesIgnoreEndCrystals() : void{
		$charge = $this->spawn(new WindCharge($this->loc(0.5, 2, 0.5), null));
		$crystal = $this->spawn(new EndCrystal($this->loc(3.5, 1, 0.5)));
		$zombie = $this->spawn(new Zombie($this->loc(-3.5, 1, 0.5)));
		self::assertFalse($charge->canCollideWith($crystal));
		self::assertTrue($charge->canCollideWith($zombie));
		$breezeCharge = $this->spawn(new BreezeWindCharge($this->loc(0.5, 2, 0.5), null));
		self::assertFalse($breezeCharge->canCollideWith($crystal));

		WindBurst::apply($this->world, new Vector3(3.5, 1.5, 0.5), WindCharge::BURST_RADIUS, WindCharge::BURST_KNOCKBACK_MULTIPLIER, true);
		self::assertTrue($crystal->isAlive() && !$crystal->isClosed(), "bursts don't hurt crystals");
	}

	/**
	 * Cost of a breeze burst (MOB_BENCH=1): in the open, next to a door (blocks to trigger) and next to mobs.
	 */
	public function testBurstBenchmark() : void{
		if(getenv("MOB_BENCH") === false){
			self::markTestSkipped("set MOB_BENCH=1 to run the wind burst benchmark");
		}
		$center = new Vector3(0.5, 1.2, 0.5);
		$time = function(int $runs) use ($center) : float{
			$start = hrtime(true);
			for($i = 0; $i < $runs; ++$i){
				WindBurst::apply($this->world, $center, BreezeWindCharge::BURST_RADIUS, BreezeWindCharge::BURST_KNOCKBACK_MULTIPLIER, false, null, new BreezeWindChargeBurstSound(), new BreezeWindExplosionParticle(), true);
			}
			return (hrtime(true) - $start) / 1e6 / $runs;
		};
		$open = $time(200);
		$this->door(3, 0, VanillaBlocks::OAK_DOOR());
		$this->set(-2, 1, 2, VanillaBlocks::CANDLE()->setLit(true));
		$door = $time(200);
		for($i = 0; $i < 5; ++$i){
			$this->spawn(new Zombie($this->loc(-2.5 + $i, 1, -2.5)));
		}
		$mobs = $time(200);
		//a floor of bottom slabs (a charge landing on one bursts inside the slab's cell) and items
		for($x = -5; $x <= 5; ++$x){
			for($z = -5; $z <= 5; ++$z){
				$this->set($x, 1, $z, VanillaBlocks::OAK_SLAB());
			}
		}
		foreach($this->spawned as $entity){
			$entity->teleport($entity->getPosition()->add(0, 0.5, 0));
		}
		for($i = 0; $i < 20; ++$i){
			$this->spawn(new ItemEntity($this->loc(-2.5 + ($i % 5), 1.5, 1.5 + (int) ($i / 5) * 0.5), VanillaItems::STICK()));
		}
		$slabCenter = new Vector3(0.5, 1.75, 0.5);
		$timeAt = function(Vector3 $at, int $runs) : float{
			$start = hrtime(true);
			for($i = 0; $i < $runs; ++$i){
				WindBurst::apply($this->world, $at, BreezeWindCharge::BURST_RADIUS, BreezeWindCharge::BURST_KNOCKBACK_MULTIPLIER, false, null, new BreezeWindChargeBurstSound(), new BreezeWindExplosionParticle(), true);
			}
			return (hrtime(true) - $start) / 1e6 / $runs;
		};
		$slabs = $timeAt($slabCenter, 100);
		//stairs under the zombies
		for($i = 0; $i < 5; ++$i){
			$this->set(-3 + $i, 1, -3, VanillaBlocks::OAK_STAIRS());
		}
		$stairs = $timeAt($slabCenter, 100);
		//a wall really in the way of some of the rays: these have to be traced
		for($x = -4; $x <= 4; ++$x){
			$this->set($x, 2, -1, VanillaBlocks::OBSIDIAN());
		}
		$wall = $timeAt($slabCenter, 100);
		echo sprintf("\n[wind bench] breeze burst: %.3f ms in the open, %.3f ms next to a door and a candle, %.3f ms with 5 zombies too, %.3f ms on slabs with 20 items, %.3f ms with stairs, %.3f ms behind a wall\n", $open, $door, $mobs, $slabs, $stairs, $wall);
		self::assertLessThan(5.0, $door);
	}

	public function testPlayerChargeIsReflectedAfterImmunity() : void{
		$zombie = $this->spawn(new Zombie($this->loc(0.5, 1, 0.5)));
		$charge = $this->spawn(new WindCharge($this->loc(0.5, 2, 6.5), null));
		$charge->setMotion(new Vector3(0, 0, 0));

		$hit = new EntityDamageByEntityEvent($zombie, $charge, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1);
		$charge->attack($hit);
		self::assertTrue($hit->isCancelled());
		self::assertNull($charge->getOwningEntity(), "immune for 0.5 s after being thrown");

		$this->runTicks(10);
		$zombie->setRotation(0, 0); //looking south (+z)
		$charge->attack(new EntityDamageByEntityEvent($zombie, $charge, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame($zombie, $charge->getOwningEntity(), "reflected: the attacker owns it");
		self::assertGreaterThan(0.0, $charge->getMotion()->z, "sent where the attacker looks");

		$zombie->setRotation(180, 0);
		$charge->attack(new EntityDamageByEntityEvent($zombie, $charge, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertGreaterThan(0.0, $charge->getMotion()->z, "immune again right after a reflection");
	}
}
