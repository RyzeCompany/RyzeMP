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

namespace pocketmine\world\vibration;

use pocketmine\block\Bell;
use pocketmine\block\Block;
use pocketmine\block\Composter;
use pocketmine\block\SculkSensor;
use pocketmine\block\SculkShrieker;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Bat;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\vehicle\Boat;
use pocketmine\entity\Zombie;
use pocketmine\item\Durable;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use function array_filter;
use function array_map;
use function count;

/**
 * Vibrations that used to be missing (M7-31): swimming, flapping, teleports, mounting, armour, the shrieker's shriek
 * heard by wardens only, and block changes.
 */
final class MissingVibrationsTest extends MobTestCase{

	/** @var Entity[] */
	private array $spawned = [];
	private RecordingVibrationListener $listener;

	protected function setUp() : void{
		parent::setUp();
		$this->listener = new RecordingVibrationListener(new Vector3(0.5, 3, 0.5));
		$this->world->getVibrations()->addListener($this->listener);
	}

	protected function tearDown() : void{
		$this->world->getVibrations()->removeListener($this->listener);
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

	private function moveBy(Living $entity, float $dx, float $dz, int $times) : void{
		$move = new \ReflectionMethod($entity, "move");
		for($i = 0; $i < $times; ++$i){
			$move->invoke($entity, $dx, 0.0, $dz);
		}
	}

	public function testSwimmingTriggersASensor() : void{
		for($x = 2; $x <= 10; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::WATER());
			$this->set($x, 2, 0, VanillaBlocks::WATER());
		}
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$zombie = $this->spawn(new Zombie($this->loc(2.5, 1.2, 0.5)));
		$this->runTicks(2);
		$this->listener->events = [];

		$this->moveBy($zombie, 0.25, 0, 12);
		self::assertContains(GameEvent::SWIM, $this->listener->eventTypes(), "swimming makes vibrations");
		self::assertNotContains(GameEvent::STEP, $this->listener->eventTypes(), "not footsteps in water");
		self::assertLessThanOrEqual(2, $this->listener->count(GameEvent::SWIM), "one every 1/0.6 blocks, not every move");

		$this->runTicks(10);
		$sensor = $this->get(0, 1, 0);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		self::assertTrue($sensor->isActive());
		self::assertSame(GameEvent::SWIM->getFrequency(), $sensor->getComparatorOutput());
	}

	public function testFlyingMobsFlap() : void{
		$bat = $this->spawn(new Bat($this->loc(0.5, 5, 0.5)));
		$this->listener->events = [];
		$this->moveBy($bat, 0.3, 0, 8);
		self::assertContains(GameEvent::FLAP, $this->listener->eventTypes());
	}

	public function testLevitatingMobsDoNotFlap() : void{
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$zombie = $this->spawn(new Zombie($this->loc(2.5, 5, 0.5)));
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::LEVITATION(), 200));
		self::assertFalse($zombie->hasGravity());
		$this->runTicks(1);
		$this->listener->events = [];
		$this->moveBy($zombie, 0.3, 0, 8);
		self::assertSame([], $this->listener->eventTypes(), "only wing-flapping mobs flap (Java isFlapping())");
		$this->runTicks(1);
		$sensor = $this->get(0, 1, 0);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		self::assertFalse($sensor->isActive());
	}

	public function testEnderPearlTeleportIsHeard() : void{
		$zombie = $this->spawn(new Zombie($this->loc(5.5, 1, 0.5)));
		$pearl = $this->spawn(new EnderPearl($this->loc(0.5, 3, 2.5), $zombie));
		$pearl->setMotion(new Vector3(0, -0.6, 0));
		$this->listener->events = [];
		for($i = 0; $i < 20 && !$pearl->isClosed() && !$pearl->isFlaggedForDespawn(); ++$i){
			$this->runTicks(1);
		}
		self::assertContains(GameEvent::TELEPORT, $this->listener->eventTypes());
		self::assertSame($zombie, $this->listener->sourceOf(GameEvent::TELEPORT));
	}

	public function testMountingAndArmourAreHeard() : void{
		$zombie = $this->spawn(new Zombie($this->loc(2.5, 1, 0.5)));
		$boat = $this->spawn(new Boat($this->loc(2.5, 1, 2.5)));
		$this->runTicks(2);
		$this->listener->events = [];

		self::assertTrue($zombie->mount($boat));
		self::assertSame($zombie, $this->listener->sourceOf(GameEvent::ENTITY_MOUNT));
		self::assertTrue($zombie->dismount());
		self::assertSame($zombie, $this->listener->sourceOf(GameEvent::ENTITY_DISMOUNT));

		$zombie->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		self::assertContains(GameEvent::EQUIP, $this->listener->eventTypes());
		$this->listener->events = [];
		$helmet = $zombie->getArmorInventory()->getHelmet();
		self::assertInstanceOf(Durable::class, $helmet);
		$helmet->applyDamage(1);
		$zombie->getArmorInventory()->setHelmet($helmet);
		self::assertNotContains(GameEvent::EQUIP, $this->listener->eventTypes(), "wear is not an equip");
		$zombie->getArmorInventory()->setHelmet(VanillaItems::AIR());
		self::assertContains(GameEvent::UNEQUIP, $this->listener->eventTypes());
	}

	public function testShriekIsHeardByListenersOnly() : void{
		$this->set(0, 1, 0, VanillaBlocks::SCULK_SENSOR());
		$this->set(3, 1, 0, VanillaBlocks::SCULK_SHRIEKER());
		$this->runTicks(1);
		$this->listener->events = [];
		$shrieker = $this->get(3, 1, 0);
		self::assertInstanceOf(SculkShrieker::class, $shrieker);
		self::assertTrue($shrieker->tryShriekWithTrackers([]));
		self::assertContains(GameEvent::SHRIEK, $this->listener->eventTypes(), "wardens hear shrieks");
		$this->runTicks(6);
		$sensor = $this->get(0, 1, 0);
		self::assertInstanceOf(SculkSensor::class, $sensor);
		self::assertFalse($sensor->isActive(), "sculk sensors don't");
	}

	public function testBlockChangesAreHeard() : void{
		$this->set(2, 1, 0, VanillaBlocks::CAULDRON());
		$this->set(-2, 1, 0, VanillaBlocks::BELL());
		$this->listener->events = [];
		$this->get(2, 1, 0)->onInteract(VanillaItems::WATER_BUCKET(), 1, new Vector3(0.5, 1, 0.5));
		self::assertContains(GameEvent::BLOCK_CHANGE, $this->listener->eventTypes(), "a cauldron filling");
		$this->listener->events = [];
		$bell = $this->get(-2, 1, 0);
		self::assertInstanceOf(Bell::class, $bell);
		$bell->ring($bell->getFacing());
		self::assertContains(GameEvent::BLOCK_CHANGE, $this->listener->eventTypes(), "a bell ringing");

		$this->set(0, 1, 2, VanillaBlocks::CAKE());
		$this->listener->events = [];
		$this->get(0, 1, 2)->onInteract(VanillaBlocks::CANDLE()->asItem(), 1, new Vector3(0.5, 1, 0.5));
		self::assertContains(GameEvent::BLOCK_CHANGE, $this->listener->eventTypes(), "a candle put on a cake");

		$this->set(0, 1, -2, VanillaBlocks::COMPOSTER()->setLayers(Composter::MAX_COMPOST_LAYERS));
		$this->listener->events = [];
		$composter = $this->get(0, 1, -2);
		self::assertInstanceOf(Composter::class, $composter);
		self::assertNotNull(ItemTransfer::extractFromComposter($composter));
		self::assertContains(GameEvent::BLOCK_CHANGE, $this->listener->eventTypes(), "a hopper emptying a composter");
	}
}

final class RecordingVibrationListener implements VibrationListener{
	/**
	 * @var GameEvent[][]|Entity[][]|null[][]
	 * @phpstan-var list<array{GameEvent, Entity|null}>
	 */
	public array $events = [];

	public function __construct(private Vector3 $position){}

	public function getListenerPosition() : Vector3{
		return $this->position;
	}

	public function getListenerRange() : int{
		return 16;
	}

	public function onVibration(Vector3 $pos, GameEvent $event, ?Entity $source, ?Block $affected, float $distance) : void{
		$this->events[] = [$event, $source];
	}

	/**
	 * @return GameEvent[]
	 */
	public function eventTypes() : array{
		return array_map(fn(array $e) => $e[0], $this->events);
	}

	public function count(GameEvent $event) : int{
		return count(array_filter($this->events, fn(array $e) => $e[0] === $event));
	}

	public function sourceOf(GameEvent $event) : ?Entity{
		foreach($this->events as [$type, $source]){
			if($type === $event){
				return $source;
			}
		}
		return null;
	}
}
