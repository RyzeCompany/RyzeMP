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

namespace pocketmine\entity\effect;

use pocketmine\color\Color;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\monster\Enderman;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\PotionType;
use pocketmine\lang\Translatable;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;
use function array_filter;
use function array_values;
use function count;
use function intdiv;
use function mt_srand;

/**
 * Oozing, Infested, the wither immunity of wither skeletons and the Wither, and splash water bottles on mobs.
 */
class EffectsExtraTest extends RedstoneTestCase{

	private function spawnZombie(float $x, float $y, float $z) : Zombie{
		$zombie = new Zombie(new Location($x, $y, $z, $this->world, 0, 0));
		$zombie->spawnToAll();
		return $zombie;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @return Entity[]
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed()));
	}

	public function testOozingReleasesTwoMediumSlimesOnDeath() : void{
		$this->floor(-5, -5, 5, 5);
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::OOZING(), 600));
		$zombie->kill();

		$slimes = $this->entitiesOf(Slime::class);
		self::assertCount(OozingEffect::SLIME_COUNT, $slimes);
		foreach($slimes as $slime){
			self::assertSame(OozingEffect::SLIME_SIZE, $slime->getSlimeSize());
			self::assertEqualsWithDelta(1.5, $slime->getPosition()->y, 0.001, "half a block above the dead entity");
			self::assertFalse($slime->isPersistent());
		}
	}

	public function testOozingAmplifierDoesNotChangeTheCount() : void{
		$this->floor(-5, -5, 5, 5);
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::OOZING(), 600, 3));
		$zombie->kill();
		self::assertCount(2, $this->entitiesOf(Slime::class));
	}

	public function testOozingSpawnsNothingInPeaceful() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$this->floor(-5, -5, 5, 5);
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::OOZING(), 600));
		$zombie->kill();
		self::assertCount(0, $this->entitiesOf(Slime::class));
	}

	public function testSlimesAreImmuneToOozing() : void{
		$slime = new Slime(new Location(0.5, 1, 0.5, $this->world, 0, 0), null, 2);
		self::assertFalse($slime->getEffects()->add(new EffectInstance(VanillaEffects::OOZING(), 600)));
		$slime->close();
	}

	public function testInfestedRollChance() : void{
		mt_srand(12345);
		$hits = 0;
		$trials = 20000;
		for($i = 0; $i < $trials; ++$i){
			$count = InfestedEffect::rollSilverfishCount();
			self::assertContains($count, [0, 1, 2]);
			if($count > 0){
				++$hits;
			}
		}
		mt_srand();
		self::assertEqualsWithDelta(InfestedEffect::SPAWN_CHANCE, $hits / $trials, 0.01);
	}

	public function testInfestedSilverfishArePushedOut() : void{
		$this->floor(-5, -5, 5, 5);
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		$spawned = InfestedEffect::spawnSilverfish($zombie, 2);
		self::assertCount(2, $spawned);
		foreach($spawned as $silverfish){
			self::assertEqualsWithDelta(1 + $zombie->getSize()->getHeight() / 2, $silverfish->getPosition()->y, 0.001);
			self::assertGreaterThan(0.0, $silverfish->getMotion()->lengthSquared());
			self::assertFalse($silverfish->isPersistent());
			self::assertFalse($silverfish->getEffects()->add(new EffectInstance(VanillaEffects::INFESTED(), 600)), "silverfish are immune");
		}
	}

	public function testInfestedSpawnsSilverfishWhenHurt() : void{
		$this->floor(-20, -20, 20, 20);
		mt_srand(42);
		for($i = 0; $i < 100; ++$i){
			$zombie = $this->spawnZombie(-15.5 + ($i % 10) * 3, 1, -15.5 + intdiv($i, 10) * 3);
			$zombie->getEffects()->add(new EffectInstance(VanillaEffects::INFESTED(), 600));
			$zombie->attack(new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_MAGIC, 1));
		}
		mt_srand();
		//about 10 hits out of 100, 1-2 silverfish each
		$silverfish = count($this->entitiesOf(Silverfish::class));
		self::assertGreaterThan(0, $silverfish);
		self::assertLessThan(60, $silverfish);
	}

	public function testInfestedSpawnsNothingInPeaceful() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		self::assertSame([], InfestedEffect::spawnSilverfish($zombie, 2));
	}

	public function testDamageAwareEffectHook() : void{
		$effect = new class(new Translatable("test"), new Color(0, 0, 0)) extends Effect implements DamageAwareEffect{
			public int $calls = 0;

			public function onEntityDamaged(Living $entity, EffectInstance $instance, EntityDamageEvent $source) : void{
				++$this->calls;
			}
		};
		$this->floor(-5, -5, 5, 5);
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		$zombie->getEffects()->add(new EffectInstance($effect, 600));

		$cancelled = new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_MAGIC, 1);
		$cancelled->cancel();
		$zombie->attack($cancelled);
		self::assertSame(0, $effect->calls, "no damage, no call");

		$zombie->attack(new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_MAGIC, 1));
		self::assertSame(1, $effect->calls);
	}

	public function testWitherSkeletonIsImmuneToWither() : void{
		$skeleton = new WitherSkeleton(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		self::assertFalse($skeleton->getEffects()->add(new EffectInstance(VanillaEffects::WITHER(), 200)));
		self::assertFalse($skeleton->getEffects()->has(VanillaEffects::WITHER()));
		self::assertTrue($skeleton->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 200)), "other effects still apply");
		self::assertFalse($skeleton->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), 200)), "still undead");
		$skeleton->close();
	}

	public function testWitherIsImmuneToWither() : void{
		$wither = new Wither(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		self::assertFalse($wither->getEffects()->add(new EffectInstance(VanillaEffects::WITHER(), 200)));
		$wither->close();
	}

	public function testZombieStillTakesWither() : void{
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		self::assertTrue($zombie->getEffects()->add(new EffectInstance(VanillaEffects::WITHER(), 200)));
	}

	private function splashWater(float $x, float $y, float $z) : void{
		$potion = new SplashPotion(new Location($x, $y, $z, $this->world, 0, 0), null, PotionType::WATER);
		(new \ReflectionMethod($potion, "splashWaterOnEntities"))->invoke($potion);
		$potion->close();
	}

	public function testSplashWaterPutsOutBurningMobs() : void{
		$this->floor(-5, -5, 5, 5);
		$zombie = $this->spawnZombie(0.5, 1, 0.5);
		$far = $this->spawnZombie(4.5, 1, 4.5);
		$zombie->setOnFire(10);
		$far->setOnFire(10);
		$health = $zombie->getHealth();

		$this->splashWater(1.5, 1, 0.5);
		self::assertFalse($zombie->isOnFire());
		self::assertSame($health, $zombie->getHealth(), "zombies aren't hurt by water");
		self::assertTrue($far->isOnFire(), "more than 4 blocks away");
	}

	public function testSplashWaterHurtsWaterSensitiveMobs() : void{
		$this->floor(-5, -5, 5, 5);
		$enderman = new Enderman(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$enderman->spawnToAll();
		$blaze = new Blaze(new Location(0.5, 1, 2.5, $this->world, 0, 0));
		$blaze->spawnToAll();
		$endermanHealth = $enderman->getHealth();
		$blazeHealth = $blaze->getHealth();

		$this->splashWater(0.5, 1, 1.5);
		self::assertSame($endermanHealth - 1, $enderman->getHealth());
		self::assertSame($blazeHealth - 1, $blaze->getHealth());
		self::assertSame(EntityDamageEvent::CAUSE_MAGIC, $blaze->getLastDamageCause()?->getCause());
	}

	public function testSplashWaterRehydratesAxolotls() : void{
		$this->floor(-5, -5, 5, 5);
		$axolotl = new Axolotl(new Location(0.5, 1, 0.5, $this->world, 0, 0), CompoundTag::create()->setInt(Axolotl::TAG_MOISTNESS, 100));
		$axolotl->spawnToAll();
		self::assertSame(100, $axolotl->getMoistness());

		$this->splashWater(1.5, 1, 0.5);
		self::assertGreaterThan(100, $axolotl->getMoistness());
		self::assertSame($axolotl->getMaxHealth(), (int) $axolotl->getHealth(), "not hurt");
	}
}
