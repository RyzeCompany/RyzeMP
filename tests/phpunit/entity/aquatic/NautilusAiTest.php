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

namespace pocketmine\entity\aquatic;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\mobs\MobDespawner;
use Ramsey\Uuid\Uuid;
use function mt_srand;

/**
 * Nautilus and zombie nautilus AI (nautilus.json, zombie_nautilus.json): panic-only babies and tamed ones, the anger
 * and charge of wild adults, the pufferfish hunt, babies, the zombie nautilus' tempt and lead, and despawning.
 */
final class NautilusAiTest extends MobTestCase{
	/**
	 * Knockback of each hit taken by the dummies of {@link NautilusAiTest::dummy()}.
	 * @var float[]
	 * @phpstan-var list<float>
	 */
	public static array $knockbacks = [];

	protected function setUp() : void{
		parent::setUp();
		self::$knockbacks = [];
		$this->pool(-12, -12, 12, 12, 6);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function pool(int $minX, int $minZ, int $maxX, int $maxZ, int $top) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = 1; $y <= $top; ++$y){
					$this->world->setBlockAt($x, $y, $z, VanillaBlocks::WATER(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
	}

	/**
	 * A cow that doesn't move by itself, hardly dies and records the knockback of the hits it takes.
	 */
	private function dummy(float $x, float $y, float $z) : Cow{
		$cow = new class($this->loc($x, $y, $z)) extends Cow implements NeverSavedWithChunkEntity{
			public function attack(EntityDamageEvent $source) : void{
				if($source instanceof EntityDamageByEntityEvent){
					NautilusAiTest::$knockbacks[] = $source->getKnockBack();
				}
				parent::attack($source);
			}

			protected function tryChangeMovement() : void{
				//stays where it is (no floating up in the water, no knockback)
				$this->motion = Vector3::zero();
			}
		};
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setHasGravity(false);
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		return $cow;
	}

	private function player(Item $held) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function hurtBy(Entity $victim, Entity $attacker) : void{
		$victim->attack(new EntityDamageByEntityEvent($attacker, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
	}

	private function adult(float $x, float $y, float $z) : Nautilus{
		$nautilus = new Nautilus($this->loc($x, $y, $z));
		$nautilus->setAge(0);
		return $nautilus;
	}

	public function testBabyAndTamedNautilusOnlyPanic() : void{
		$baby = new Nautilus($this->loc(0.5, 2, 0.5));
		$baby->setAge(Animal::BABY_AGE);
		$tamed = $this->adult(4.5, 2, 0.5);
		$tamed->setOwner($this->player(VanillaItems::AIR()));
		$cow = $this->dummy(2.5, 2, 0.5);
		$this->hurtBy($baby, $cow);
		$this->hurtBy($tamed, $cow);
		$this->runTicks(300);
		self::assertSame(100000.0, $cow->getHealth(), "nautilus_baby and nautilus_tame have no attack");
		self::assertFalse($baby->isAngry());
		self::assertFalse($tamed->isAngry());
	}

	public function testWildAdultChargesWithKnockbackAndCalmsDown() : void{
		$nautilus = $this->adult(0.5, 2, 0.5);
		$cow = $this->dummy(6.5, 2, 0.5);
		$this->hurtBy($nautilus, $cow);
		$this->runTicks(2);
		self::assertSame($cow, $nautilus->getAttackTarget());
		self::assertTrue($nautilus->isAngry());
		self::assertFalse($nautilus->canBeLeashed(), "become_angry removes nautilus_leashable");
		$player = $this->player(VanillaItems::PUFFERFISH()->setCount(10));
		self::assertFalse($nautilus->onInteract($player, Vector3::zero()), "and the tameable component");
		self::assertSame(10, $player->getInventory()->getItemInHand()->getCount());

		for($i = 0; $i < 120 && self::$knockbacks === []; ++$i){
			$this->runTicks(1);
		}
		self::assertSame([Nautilus::CHARGE_KNOCKBACK], self::$knockbacks, "one charge hit with the extra knockback");
		self::assertSame(100000.0 - 3.0, $cow->getHealth());

		$this->runTicks(Nautilus::ANGER_TICKS);
		self::assertFalse($nautilus->isAngry(), "minecraft:angry lasts 20 seconds");
		self::assertNull($nautilus->getAttackTarget());
		self::assertTrue($nautilus->canBeLeashed());
	}

	public function testWildNautilusHuntsPufferfish() : void{
		$nautilus = $this->adult(0.5, 2, 0.5);
		$tamed = $this->adult(-4.5, 2, 0.5);
		$tamed->setOwner($this->player(VanillaItems::AIR()));
		$pufferfish = new Pufferfish($this->loc(5.5, 2, 0.5));
		$this->runTicks(1);
		self::assertNull($nautilus->getAttackTarget(), "the first search waits 120 to 180 seconds");
		for($i = 0; $i < 30 && $nautilus->getAttackTarget() === null; ++$i){
			//target_acquisition_probability 0.5 per search
			$nautilus->scheduleNextPufferfishSearch(0);
			$tamed->scheduleNextPufferfishSearch(0);
			$this->runTicks(1);
		}
		self::assertSame($pufferfish, $nautilus->getAttackTarget());
		self::assertTrue($nautilus->isAngry());
		self::assertNull($tamed->getAttackTarget(), "tamed ones don't hunt");
		$pufferfish->flagForDespawn();
	}

	public function testZombieNautilusHuntsPufferfishAndCharges() : void{
		$zombie = new ZombieNautilus($this->loc(0.5, 2, 0.5));
		$pufferfish = new Pufferfish($this->loc(5.5, 2, 0.5));
		for($i = 0; $i < 30 && !$zombie->isAngry(); ++$i){
			$zombie->scheduleNextPufferfishSearch(0);
			$this->runTicks(1);
		}
		self::assertTrue($zombie->isAngry());
		self::assertSame($pufferfish, $zombie->getAngerTarget());
		$pufferfish->flagForDespawn();
		$zombie->flagForDespawn();

		//a calm one charges whoever it gets angry at
		$zombie = new ZombieNautilus($this->loc(0.5, 2, 0.5));
		$cow = $this->dummy(-5.5, 2, 0.5);
		$zombie->setAngryAt($cow, ZombieNautilus::ANGER_TICKS);
		for($i = 0; $i < 160 && self::$knockbacks === []; ++$i){
			$this->runTicks(1);
		}
		self::assertSame([ZombieNautilus::CHARGE_KNOCKBACK], self::$knockbacks);
	}

	public function testNautilusBabies() : void{
		$nautilus = new Nautilus($this->loc(0.5, 2, 0.5));
		self::assertSame(10, $nautilus->getSpawnBabyChance());
		$moveControl = $nautilus->getMoveControl();
		self::assertInstanceOf(SwimmingMoveControl::class, $moveControl);
		self::assertSame(Nautilus::SWIM_SPEED, $moveControl->getSwimSpeed());
		self::assertTrue($nautilus->makeSpawnBaby());
		self::assertTrue($nautilus->isBaby());
		self::assertSame(Nautilus::BABY_SWIM_SPEED, $moveControl->getSwimSpeed(), "nautilus_baby underwater_movement 0.15");
		$nautilus->setAge(0);
		self::assertSame(Nautilus::SWIM_SPEED, $moveControl->getSwimSpeed());
		$nautilus->flagForDespawn();

		mt_srand(1234);
		$babies = 0;
		for($i = 0; $i < 1000; ++$i){
			$n = new Nautilus($this->loc(0.5, 2, 0.5));
			if(BabySpawning::rollSpawnBaby($n)){
				++$babies;
			}
			$n->flagForDespawn();
			$n->close();
		}
		mt_srand();
		self::assertGreaterThan(60, $babies);
		self::assertLessThan(140, $babies, "entity_spawned: 10% wild babies");
	}

	public function testZombieNautilusTemptAndLead() : void{
		$zombie = new ZombieNautilus($this->loc(0.5, 2, 0.5));
		self::assertTrue($zombie->isTemptItem(VanillaItems::PUFFERFISH()));
		self::assertTrue($zombie->isTemptItem(VanillaItems::PUFFERFISH_BUCKET()));
		self::assertFalse($zombie->isTemptItem(VanillaItems::RAW_FISH()), "zombie_nautilus_tameable: pufferfish only");
		self::assertTrue($zombie->canBeLeashed());

		$leader = $this->player(VanillaItems::LEAD());
		self::assertTrue($zombie->onInteract($leader, Vector3::zero()));
		self::assertTrue($zombie->isLeashed());
		$cow = $this->dummy(4.5, 2, 0.5);
		$zombie->setAngryAt($cow, ZombieNautilus::ANGER_TICKS);
		self::assertFalse($zombie->isLeashed(), "become_angry removes zombie_nautilus_leashable");
		self::assertFalse($zombie->canBeLeashed());
		self::assertFalse($zombie->isTemptItem(VanillaItems::PUFFERFISH()));
		$zombie->setAngryAt(null, 0);

		$drowned = new Drowned($this->loc(0.5, 2, 0.5));
		$drowned->mount($zombie);
		self::assertFalse($zombie->canBeLeashed(), "zombie_nautilus_wild_mounted isn't leashable");
		$drowned->dismount();
		$drowned->flagForDespawn();

		$zombie->setOwner($this->player(VanillaItems::AIR()));
		self::assertTrue($zombie->isTemptItem(VanillaItems::RAW_FISH()), "zombie_nautilus_tame: every fish");
		self::assertTrue($zombie->isTemptItem(VanillaItems::COOKED_SALMON()));
		self::assertTrue($zombie->canBeLeashed());
	}

	public function testNautilusesDespawnAt40Blocks() : void{
		$method = new \ReflectionMethod(MobDespawner::class, "instantDistanceOf");
		$world = $this->world;
		$nautilus = new Nautilus($this->loc(0.5, 2, 0.5));
		$zombie = new ZombieNautilus($this->loc(2.5, 2, 0.5));
		$cow = new Cow($this->loc(4.5, 2, 0.5));
		self::assertSame(MobDespawner::FISH_INSTANT_DISTANCE, $method->invoke(null, $world, $nautilus));
		self::assertSame(MobDespawner::FISH_INSTANT_DISTANCE, $method->invoke(null, $world, $zombie));
		self::assertSame(MobDespawner::getInstantDistance($world), $method->invoke(null, $world, $cow));
	}
}
