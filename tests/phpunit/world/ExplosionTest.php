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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\TNT;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Silverfish;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\vehicle\MinecartTNT;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\player\Player;
use pocketmine\world\particle\ExplosionParticle;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function ceil;
use function count;
use function max;
use function min;
use function mt_srand;

/**
 * Explosions as BDS 1.26.52 does them: TNT drops every block, entity damage and push, the TNT game rules, the fuse of
 * TNT lit by an explosion, the particle and infested blocks.
 */
final class ExplosionTest extends MobTestCase{

	/** @var Entity[] */
	private array $spawned = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261020);
	}

	protected function tearDown() : void{
		foreach($this->spawned as $entity){
			if($entity instanceof ExplosionTestPlayer){
				if($this->world->getEntity($entity->getId()) === $entity){
					$this->world->removeEntity($entity);
				}
				(new \ReflectionProperty(Entity::class, "closed"))->setValue($entity, true);
			}elseif(!$entity->isClosed()){
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
	private function track(Entity $entity) : Entity{
		$this->spawned[] = $entity;
		return $entity;
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A 7x5x7 stone box resting on the floor, hollow in the middle (where the explosion goes).
	 */
	private function stoneBox() : void{
		for($x = -3; $x <= 3; ++$x){
			for($y = 1; $y <= 5; ++$y){
				for($z = -3; $z <= 3; ++$z){
					$this->world->setBlockAt($x, $y, $z, $x === 0 && $y === 3 && $z === 0 ? VanillaBlocks::AIR() : VanillaBlocks::STONE(), false);
				}
			}
		}
	}

	private function droppedItems() : int{
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				$count += $entity->getItem()->getCount();
				$this->spawned[] = $entity;
			}
		}
		return $count;
	}

	/**
	 * @return int[] blocks broken, items dropped
	 * @phpstan-return array{int, int}
	 */
	private function blowUpBox(Entity $what, float $radius) : array{
		$this->stoneBox();
		$explosion = new Explosion(new Position(0.5, 3.5, 0.5, $this->world), $radius, $what);
		$explosion->explodeA();
		$broken = count($explosion->affectedBlocks);
		$explosion->explodeB();
		return [$broken, $this->droppedItems()];
	}

	public function testTntDropsEveryBlock() : void{
		//several seeds: a yield of 100 must never lose a block to the drop roll
		for($seed = 1; $seed <= 8; ++$seed){
			mt_srand($seed);
			$tnt = $this->track(new PrimedTNT($this->loc(0.5, 3, 0.5)));
			[$broken, $dropped] = $this->blowUpBox($tnt, 4);
			self::assertGreaterThan(20, $broken);
			self::assertSame($broken, $dropped, "tntexplosiondropdecay is off by default (seed $seed)");
			$this->removeItems();
		}
	}

	private function removeItems() : void{
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				$entity->close();
			}
		}
	}

	public function testDropsAreMergedIntoStacksOfSixteen() : void{
		$tnt = $this->track(new PrimedTNT($this->loc(0.5, 3, 0.5)));
		[$broken] = $this->blowUpBox($tnt, 4);
		$stacks = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				$stacks[] = $entity->getItem()->getCount();
			}
		}
		self::assertGreaterThan(16, $broken);
		self::assertCount((int) ceil($broken / 16), $stacks, "$broken cobblestone in stacks of up to 16");
		foreach($stacks as $count){
			self::assertLessThanOrEqual(16, $count);
		}
	}

	public function testUnstableTntBreaksNormallyWhenTntExplodesIsOff() : void{
		$this->world->setGameRule(GameRules::TNT_EXPLODES, false);
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::TNT()->setUnstable(true), false);
		$this->breakBlock(0, 1, 0);
		self::assertSame(BlockTypeIds::AIR, $this->world->getBlockAt(0, 1, 0)->getTypeId());
		foreach($this->world->getEntities() as $entity){
			self::assertNotInstanceOf(PrimedTNT::class, $entity);
		}
	}

	public function testTntMinecartDropsEveryBlock() : void{
		$cart = $this->track(new MinecartTNT($this->loc(0.5, 3, 0.5)));
		[$broken, $dropped] = $this->blowUpBox($cart, 3);
		self::assertGreaterThan(10, $broken);
		self::assertSame($broken, $dropped);
	}

	public function testTntDropDecayRule() : void{
		$this->world->setGameRule(GameRules::TNT_EXPLOSION_DROP_DECAY, true);
		$tnt = $this->track(new PrimedTNT($this->loc(0.5, 3, 0.5)));
		[$broken, $dropped] = $this->blowUpBox($tnt, 4);
		self::assertGreaterThan(0, $dropped);
		self::assertLessThan($broken * 0.5, $dropped, "1 in 4 blocks drops with the rule on");
	}

	public function testCreeperKeepsDropDecay() : void{
		$creeper = $this->track(new Creeper($this->loc(0.5, 3, 0.5)));
		[$broken, $dropped] = $this->blowUpBox($creeper, 3);
		self::assertGreaterThan(10, $broken);
		self::assertGreaterThan(0, $dropped);
		self::assertLessThan($broken * 0.7, $dropped, "1 in 3 blocks drops");
	}

	/**
	 * A cow floating in the air that never resists the push and can take a lot of damage.
	 */
	private function dummy(float $x, float $y, float $z) : Cow{
		$cow = $this->track(new Cow($this->loc($x, $y, $z)));
		$cow->setMaxHealth(100000);
		$cow->setHealth(100000);
		$cow->setMotion(new Vector3(0, 0, 0));
		return $cow;
	}

	/**
	 * @return float[] damage taken, push received
	 * @phpstan-return array{float, Vector3}
	 */
	private function hit(Living $entity, Vector3 $center, float $radius, Entity|null $what = null) : array{
		$health = $entity->getHealth();
		$motion = $entity->getMotion();
		(new Explosion(Position::fromObject($center, $this->world), $radius, $what))->explodeB();
		return [$health - $entity->getHealth(), $entity->getMotion()->subtractVector($motion)];
	}

	public function testDamageFormula() : void{
		//vanilla ((e*e + e) / 2 * 7 * 2r) + 1 with e = 1 - distance / 2r and full exposure (open air)
		foreach([0 => 57.0, 2 => 37.75, 4 => 22.0] as $distance => $expected){
			$cow = $this->dummy(0.5 + $distance, 20, 0.5);
			$cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
			[$damage, $push] = $this->hit($cow, new Vector3(0.5, 20, 0.5), 4);
			self::assertEqualsWithDelta($expected, $damage, 0.001, "TNT at distance $distance");
			$cow->close();
		}
	}

	public function testStandingOnTheFloorIsFullyExposed() : void{
		//a TNT on the floor blasts from just above it: the feet of a mob standing on the same floor are in plain view
		for($x = -1; $x <= 6; ++$x){
			for($z = -1; $z <= 2; ++$z){
				$this->world->setBlockAt($x, 19, $z, VanillaBlocks::STONE(), false);
			}
		}
		$cow = $this->dummy(4.5, 20, 0.5);
		$cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		[$damage] = $this->hit($cow, new Vector3(0.5, 20.06125, 0.5), 4);
		self::assertEqualsWithDelta(22.0, $damage, 0.01, "full exposure at distance 4");
	}

	public function testEntityAtTheCentreIsThrownUp() : void{
		$cow = $this->dummy(0.5, 20, 0.5);
		$cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		[, $push] = $this->hit($cow, new Vector3(0.5, 20, 0.5), 3);
		self::assertEqualsWithDelta(1.0, $push->y, 0.001, "pushed towards its eyes: straight up");
		self::assertEqualsWithDelta(0.0, $push->x, 0.001);
		self::assertEqualsWithDelta(0.0, $push->z, 0.001);
	}

	public function testBlastProtectionDampensThePush() : void{
		$plain = $this->dummy(2.5, 20, 0.5);
		$plain->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		[, $plainPush] = $this->hit($plain, new Vector3(0.5, 20, 0.5), 4);
		$plain->close();

		$armoured = $this->dummy(2.5, 20, 0.5);
		$armoured->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		$armoured->getArmorInventory()->setChestplate(VanillaItems::DIAMOND_CHESTPLATE()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::BLAST_PROTECTION(), 4)));
		[, $armouredPush] = $this->hit($armoured, new Vector3(0.5, 20, 0.5), 4);

		self::assertGreaterThan(0.1, $plainPush->length());
		self::assertEqualsWithDelta($plainPush->length() * 0.4, $armouredPush->length(), 0.001, "Blast Protection IV: 60% less push");
	}

	public function testHurtKnockbackStays() : void{
		//BDS 1.26.52 pushes a player hit by TNT twice: the 0.4 hurt knockback, then the explosion impulse
		$cow = $this->dummy(2.5, 20, 0.5);
		$cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(0.0);
		$tnt = $this->track(new PrimedTNT($this->loc(0.5, 20, 0.5)));
		[, $withKnockback] = $this->hit($cow, $tnt->getPosition(), 4, $tnt);
		$cow->close();

		$cow = $this->dummy(2.5, 20, 0.5);
		$cow->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		[, $impulseOnly] = $this->hit($cow, $tnt->getPosition(), 4, $tnt);
		self::assertGreaterThan($impulseOnly->x + 0.3, $withKnockback->x);
	}

	private function player(float $x, float $y, float $z) : ExplosionTestPlayer{
		$player = (new \ReflectionClass(ExplosionTestPlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $this->loc($x, $y, $z));
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Entity::class, "motion"))->setValue($player, new Vector3(0, 0, 0));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		$this->world->addEntity($player);
		$this->spawned[] = $player;
		return $player;
	}

	public function testPlayerDamageScalesWithDifficultyForMobExplosions() : void{
		//BDS 1.26.52: a creeper at point blank hurts like a mob's attack (easy d/2 + 1, hard 1.5 d), TNT doesn't scale
		$expected = [
			World::DIFFICULTY_EASY => [22.5, 57.0],
			World::DIFFICULTY_NORMAL => [43.0, 57.0],
			World::DIFFICULTY_HARD => [64.5, 57.0],
		];
		foreach($expected as $difficulty => [$creeperDamage, $tntDamage]){
			$this->difficulty = $difficulty;
			$player = $this->player(0.5, 20, 0.5);

			$creeper = $this->track(new Creeper($this->loc(0.5, 20, 0.5)));
			(new Explosion(new Position(0.5, 20, 0.5, $this->world), 3, $creeper))->explodeB();
			self::assertNotNull($player->hit);
			self::assertEqualsWithDelta($creeperDamage, $player->hit->getBaseDamage(), 0.001, "creeper, difficulty $difficulty");
			$creeper->close();

			$tnt = $this->track(new PrimedTNT($this->loc(0.5, 20, 0.5)));
			(new Explosion(new Position(0.5, 20, 0.5, $this->world), 4, $tnt))->explodeB();
			self::assertEqualsWithDelta($tntDamage, $player->hit->getBaseDamage(), 0.001, "TNT, difficulty $difficulty");
			$tnt->close();

			$this->world->removeEntity($player);
		}
	}

	public function testTntBlockStaysWhenTntExplodesIsOff() : void{
		$this->world->setGameRule(GameRules::TNT_EXPLODES, false);
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::TNT(), false);
		$tnt = $this->world->getBlockAt(0, 1, 0);
		self::assertInstanceOf(TNT::class, $tnt);
		$tnt->ignite();
		self::assertSame(BlockTypeIds::TNT, $this->world->getBlockAt(0, 1, 0)->getTypeId());
		foreach($this->world->getEntities() as $entity){
			self::assertNotInstanceOf(PrimedTNT::class, $entity);
		}

		$this->world->setGameRule(GameRules::TNT_EXPLODES, true);
		$tnt->ignite();
		self::assertSame(BlockTypeIds::AIR, $this->world->getBlockAt(0, 1, 0)->getTypeId());
		$primed = null;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof PrimedTNT){
				$primed = $this->track($entity);
			}
		}
		self::assertNotNull($primed);
	}

	public function testTntMinecartBreaksBlocksWithoutMobGriefing() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$this->stoneBox();
		$cart = $this->track(new MinecartTNT($this->loc(0.5, 3, 0.5)));
		$cart->explodeWithSpeed(0);
		self::assertTrue($cart->hasExploded());
		self::assertSame(BlockTypeIds::AIR, $this->world->getBlockAt(1, 3, 0)->getTypeId(), "mobgriefing doesn't stop a TNT minecart");
	}

	public function testTntMinecartDoesNothingWhenTntExplodesIsOff() : void{
		$this->world->setGameRule(GameRules::TNT_EXPLODES, false);
		$this->stoneBox();
		$cart = $this->track(new MinecartTNT($this->loc(0.5, 3, 0.5)));
		$cart->prime();
		self::assertFalse($cart->isPrimed());
		$cart->explodeWithSpeed(0);
		self::assertFalse($cart->hasExploded());
		self::assertFalse($cart->isFlaggedForDespawn());
		self::assertSame(BlockTypeIds::STONE, $this->world->getBlockAt(1, 3, 0)->getTypeId());
	}

	public function testTntLitByAnExplosionUsesTheVanillaFuse() : void{
		$min = 1000;
		$max = 0;
		for($i = 0; $i < 40; ++$i){
			$this->world->setBlockAt(1, 1, 0, VanillaBlocks::TNT(), false);
			$explosion = new Explosion(new Position(0.5, 1.5, 0.5, $this->world), 2);
			$explosion->explodeA();
			$explosion->explodeB();
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof PrimedTNT){
					//PrimedTNT::getFuse() is the fuse set by ignite()
					$min = min($min, $entity->getFuse());
					$max = max($max, $entity->getFuse());
					$entity->close();
				}elseif($entity instanceof ItemEntity){
					$entity->close();
				}
			}
		}
		self::assertGreaterThanOrEqual(10, $min);
		self::assertLessThanOrEqual(40, $max);
		self::assertGreaterThan(30, $max, "tnt.json from_explosion: up to 2 seconds");
	}

	public function testExplodedInfestedStoneReleasesASilverfish() : void{
		foreach([[true, 1], [false, 0]] as [$tileDrops, $expected]){
			$this->world->setGameRule(GameRules::DO_TILE_DROPS, $tileDrops);
			$this->world->setBlockAt(1, 1, 0, VanillaBlocks::INFESTED_STONE(), false);
			$explosion = new Explosion(new Position(0.5, 1.5, 0.5, $this->world), 3);
			$explosion->explodeA();
			$explosion->explodeB();
			self::assertSame(BlockTypeIds::AIR, $this->world->getBlockAt(1, 1, 0)->getTypeId());
			$silverfish = 0;
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof Silverfish){
					++$silverfish;
					$entity->close();
				}elseif($entity instanceof ItemEntity){
					$entity->close();
				}
			}
			self::assertSame($expected, $silverfish, $tileDrops ? "dotiledrops on" : "dotiledrops off");
		}
	}

	public function testParticleCarriesTheRadius() : void{
		foreach([[4.0, 4], [1.0, 1], [3.7, 3]] as [$radius, $data]){
			$packets = (new ExplosionParticle($radius))->encode(new Vector3(1.5, 2.25, 3.5));
			self::assertCount(1, $packets);
			$packet = $packets[0];
			self::assertInstanceOf(LevelEventPacket::class, $packet);
			self::assertSame(LevelEvent::PARTICLE_EXPLODE, $packet->eventId);
			self::assertSame($data, $packet->eventData, "BDS: data >= 2 shows the huge explosion emitter");
		}
	}
}

/**
 * A player that only records the damage it would take.
 */
final class ExplosionTestPlayer extends Player{
	public ?EntityDamageEvent $hit = null;

	public function attack(EntityDamageEvent $source) : void{
		$this->hit = $source;
	}

	public function setMotion(Vector3 $motion) : bool{
		$this->motion = $motion;
		return true;
	}
}
