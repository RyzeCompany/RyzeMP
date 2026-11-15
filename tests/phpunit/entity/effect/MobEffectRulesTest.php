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

use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Goat;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\aquatic\Pufferfish;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\CaveSpider;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\ElderGuardian;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\monster\MagmaCube;
use pocketmine\entity\monster\Parched;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\monster\ZombieNautilus;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\entity\Squid;
use pocketmine\entity\Zombie;
use pocketmine\item\PotionType;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;

/**
 * Undead inversion of instant effects, effect immunities, the pufferfish's targets and Breath of the Nautilus.
 */
final class MobEffectRulesTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Fills a box with water (y 1 to $top).
	 */
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
	 * A mocked player with a working effect manager.
	 */
	private function player(bool $creative = false) : Player{
		$player = $this->createMock(Player::class);
		$player->method("isAlive")->willReturn(true);
		$player->method("isCreative")->willReturn($creative);
		$player->method("isSpectator")->willReturn(false);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getEffects")->willReturn(new EffectManager($player));
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	public function testBreathOfTheNautilusRegistered() : void{
		$map = EffectIdMap::getInstance();
		//BDS 1.26.52: "/effect @s breath_of_the_nautilus" sends MobEffect type 37
		self::assertSame(37, $map->toId(VanillaEffects::BREATH_OF_THE_NAUTILUS()));
		self::assertSame(VanillaEffects::BREATH_OF_THE_NAUTILUS(), $map->fromId(37));
		self::assertSame(VanillaEffects::BREATH_OF_THE_NAUTILUS(), StringToEffectParser::getInstance()->parse("breath_of_the_nautilus"));
		self::assertFalse(VanillaEffects::BREATH_OF_THE_NAUTILUS()->isBad());
	}

	public function testInstantEffectsInvertedOnUndead() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setHealth(10);
		VanillaEffects::INSTANT_DAMAGE()->applyEffect($zombie, new EffectInstance(VanillaEffects::INSTANT_DAMAGE(), 1, 0));
		self::assertEqualsWithDelta(14.0, $zombie->getHealth(), 0.01, "Instant Damage heals undead by 4");

		$skeleton = new Skeleton($this->loc(4.5, 1, 0.5));
		VanillaEffects::INSTANT_HEALTH()->applyEffect($skeleton, new EffectInstance(VanillaEffects::INSTANT_HEALTH(), 1, 1));
		self::assertEqualsWithDelta($skeleton->getMaxHealth() - 12.0, $skeleton->getHealth(), 0.01, "Instant Health II hurts undead by 12");

		$cow = new Cow($this->loc(8.5, 1, 0.5));
		VanillaEffects::INSTANT_DAMAGE()->applyEffect($cow, new EffectInstance(VanillaEffects::INSTANT_DAMAGE(), 1, 0));
		self::assertEqualsWithDelta($cow->getMaxHealth() - 6.0, $cow->getHealth(), 0.01, "Instant Damage hurts others by 6");
		VanillaEffects::INSTANT_HEALTH()->applyEffect($cow, new EffectInstance(VanillaEffects::INSTANT_HEALTH(), 1, 0));
		self::assertEqualsWithDelta($cow->getMaxHealth() - 2.0, $cow->getHealth(), 0.01, "Instant Health heals others by 4");
	}

	public function testSplashHealingHurtsZombie() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$zombie->spawnToAll();
		$potion = new SplashPotion($this->loc(0.5, 3.5, 0.5), null, PotionType::HEALING);
		$potion->setMotion(new Vector3(0, -0.5, 0));
		$potion->spawnToAll();
		$this->runTicks(10);
		self::assertTrue($potion->isClosed(), "the potion broke");
		self::assertLessThan($zombie->getMaxHealth(), $zombie->getHealth(), "splash Healing hurts a zombie");
	}

	public function testUndeadAndMobEffectImmunities() : void{
		$poison = new EffectInstance(VanillaEffects::POISON(), 200);
		$immune = [
			"zombie" => new Zombie($this->loc(0.5, 1, 0.5)),
			"drowned" => new Drowned($this->loc(2.5, 1, 0.5)),
			"wither skeleton" => new WitherSkeleton($this->loc(4.5, 1, 0.5)),
			"spider" => new Spider($this->loc(6.5, 1, 0.5)),
			"cave spider" => new CaveSpider($this->loc(8.5, 1, 0.5)),
			"nautilus" => new Nautilus($this->loc(10.5, 1, 0.5)),
			"zombie nautilus" => new ZombieNautilus($this->loc(12.5, 1, 0.5)),
		];
		foreach($immune as $name => $mob){
			self::assertFalse($mob->getEffects()->add(clone $poison), "$name can't be poisoned");
			self::assertFalse($mob->getEffects()->has(VanillaEffects::POISON()));
		}

		$skeleton = new Skeleton($this->loc(0.5, 1, 4.5));
		self::assertFalse($skeleton->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 200)), "undead: no regeneration");
		self::assertTrue($skeleton->getEffects()->add(new EffectInstance(VanillaEffects::SLOWNESS(), 200)), "other effects still work");

		$parched = new Parched($this->loc(2.5, 1, 4.5));
		self::assertFalse($parched->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 200)));
		self::assertFalse($parched->getEffects()->add(clone $poison), "parched is undead too");

		self::assertFalse((new Slime($this->loc(4.5, 1, 4.5)))->getEffects()->add(new EffectInstance(VanillaEffects::OOZING(), 200)));
		self::assertTrue((new MagmaCube($this->loc(6.5, 1, 4.5)))->getEffects()->add(new EffectInstance(VanillaEffects::OOZING(), 200)), "magma cubes have no immunity");
		self::assertFalse((new Silverfish($this->loc(8.5, 1, 4.5)))->getEffects()->add(new EffectInstance(VanillaEffects::INFESTED(), 200)));

		$cow = new Cow($this->loc(10.5, 1, 4.5));
		self::assertTrue($cow->getEffects()->add(clone $poison), "control: cows get poisoned");
		self::assertTrue($cow->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 200)));
		self::assertTrue($this->player()->getEffects()->add(clone $poison), "players are never immune");
	}

	public function testPufferfishThreats() : void{
		$this->pool(-12, -12, 12, 12, 3);
		$at = fn(float $x) : Location => $this->loc($x, 1.2, 8.5);
		$notThreats = [
			"turtle" => new Turtle($at(0.5)),
			"guardian" => new Guardian($at(2.5)),
			"elder guardian" => new ElderGuardian($at(4.5)),
			"nautilus" => new Nautilus($at(6.5)),
			"zombie nautilus" => new ZombieNautilus($at(8.5)),
			"dolphin" => new Dolphin($at(10.5)),
			"cod" => new Cod($at(-1.5)),
			"squid" => new Squid($at(-3.5)),
			"goat (no mob family)" => new Goat($at(-5.5)),
			"piglin brute (no mob family)" => new PiglinBrute($at(-7.5)),
		];
		foreach($notThreats as $name => $entity){
			self::assertFalse(Pufferfish::isThreat($entity), $name);
		}
		self::assertTrue(Pufferfish::isThreat(new Axolotl($at(-9.5))), "axolotls are threats");
		self::assertTrue(Pufferfish::isThreat(new Zombie($at(-11.5))));
		self::assertTrue(Pufferfish::isThreat(new ArmorStand($this->loc(0.5, 1.2, -8.5))), "armor_stand.json lists the mob family");
		self::assertTrue(Pufferfish::isThreat($this->player()));
		self::assertTrue(Pufferfish::isThreat($this->player(true)), "creative players make it puff up too");
	}

	private function fullyPuffed() : Pufferfish{
		$puffer = new Pufferfish($this->loc(0.5, 1.2, 0.5));
		$puffer->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$puffer->setPuffState(Pufferfish::PUFF_FULL);
		return $puffer;
	}

	public function testPufferfishStingsButDoesNotPoisonUndead() : void{
		$this->pool(-12, -12, 12, 12, 3);
		$drowned = new Drowned($this->loc(1.45, 1.2, 0.5));
		$drowned->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$drowned->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$puffer = $this->fullyPuffed();
		$this->runTicks(5);
		self::assertSame(Pufferfish::PUFF_FULL, $puffer->getPuffState());
		self::assertLessThan($drowned->getMaxHealth(), $drowned->getHealth(), "stung");
		self::assertFalse($drowned->getEffects()->has(VanillaEffects::POISON()), "undead aren't poisoned");
	}

	public function testPufferfishIgnoresTurtle() : void{
		$this->pool(-12, -12, 12, 12, 3);
		$turtle = new Turtle($this->loc(1.6, 1.2, 0.5));
		$turtle->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$puffer = new Pufferfish($this->loc(0.5, 1.2, 0.5));
		$puffer->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$this->runTicks(80);
		self::assertSame(Pufferfish::PUFF_NONE, $puffer->getPuffState(), "turtles don't make it puff up");
		self::assertSame($turtle->getMaxHealth(), (int) $turtle->getHealth());
	}

	public function testPufferfishDeflatesInSteps() : void{
		$this->pool(-12, -12, 12, 12, 3);
		$puffer = new Pufferfish($this->loc(0.5, 1.2, 0.5));
		$puffer->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE, true);
		$puffer->setPuffState(Pufferfish::PUFF_FULL);
		//nothing around: noticed within 10 ticks, then 3 s to half puffed, then 2 s to normal
		$this->runTicks(55);
		self::assertSame(Pufferfish::PUFF_FULL, $puffer->getPuffState(), "start_deflate lasts 3 s");
		$this->runTicks(20);
		self::assertSame(Pufferfish::PUFF_HALF, $puffer->getPuffState());
		$this->runTicks(45);
		self::assertSame(Pufferfish::PUFF_NONE, $puffer->getPuffState());
	}

	public function testRiderOfSaddledNautilusInWaterGetsBreath() : void{
		$this->pool(-12, -12, 12, 12, 8);
		$nautilus = new Nautilus($this->loc(0.5, 3, 0.5));
		$rider = $this->player();
		$nautilus->setOwner($rider);
		$nautilus->setSaddled(true);
		(new \ReflectionProperty(Entity::class, "passengers"))->setValue($nautilus, [$rider]);
		$this->runTicks(21);
		$effect = $rider->getEffects()->get(VanillaEffects::BREATH_OF_THE_NAUTILUS());
		self::assertNotNull($effect, "the rider gets Breath of the Nautilus");
		self::assertTrue($effect->isAmbient());
		self::assertLessThanOrEqual(40, $effect->getDuration());
		self::assertGreaterThan(20, $effect->getDuration(), "refreshed every second");

		$nautilus->setSaddled(false);
		$rider->getEffects()->clear();
		$this->runTicks(21);
		self::assertFalse($rider->getEffects()->has(VanillaEffects::BREATH_OF_THE_NAUTILUS()), "only when saddled");
	}
}
