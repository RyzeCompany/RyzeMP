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

use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\DangerousWitherSkull;
use pocketmine\entity\projectile\WitherSkull;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function array_filter;
use function count;
use function max;

final class WitherTest extends MobTestCase{

	private function skull() : Block{
		return VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::WITHER_SKELETON);
	}

	/**
	 * Builds a T with its stem at the given position, arms along $right and skulls towards $up.
	 *
	 * @return Vector3[] the skull positions
	 */
	private function buildT(Vector3 $stem, int $up, int $right, bool $soulSoil = false, int $skulls = 3) : array{
		$body = $soulSoil ? VanillaBlocks::SOUL_SOIL() : VanillaBlocks::SOUL_SAND();
		$this->setAt($stem, $body);
		$skullPositions = [];
		for($r = -1; $r <= 1; ++$r){
			$this->setAt($stem->getSide($up)->getSide($right, $r), $r === 0 ? VanillaBlocks::SOUL_SAND() : $body);
			$pos = $stem->getSide($up, 2)->getSide($right, $r);
			if(count($skullPositions) < $skulls){
				$this->setAt($pos, $this->skull());
			}
			$skullPositions[] = $pos;
		}
		return $skullPositions;
	}

	private function setAt(Vector3 $pos, Block $block) : void{
		$this->set($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $block);
	}

	public function testUprightPatternIsFoundFromAnySkull() : void{
		$skulls = $this->buildT(new Vector3(0, 1, 0), Facing::UP, Facing::EAST);
		foreach($skulls as $skull){
			$pattern = WitherSummonPattern::find($this->world, $skull);
			self::assertNotNull($pattern, "pattern should be found from the skull at $skull");
			self::assertCount(4, $pattern->getBody());
			self::assertEquals(new Vector3(0.5, 1, 0.5), $pattern->getSpawnPosition());
		}
	}

	public function testPatternAlongZAndWithSoulSoil() : void{
		$skulls = $this->buildT(new Vector3(5, 1, 5), Facing::UP, Facing::SOUTH, soulSoil: true);
		self::assertNotNull(WitherSummonPattern::find($this->world, $skulls[1]));
	}

	public function testSidewaysAndUpsideDownPatterns() : void{
		$skulls = $this->buildT(new Vector3(0, 3, 0), Facing::EAST, Facing::UP);
		self::assertNotNull(WitherSummonPattern::find($this->world, $skulls[0]), "lying on its side");
		$skulls = $this->buildT(new Vector3(10, 8, 10), Facing::DOWN, Facing::EAST);
		$pattern = WitherSummonPattern::find($this->world, $skulls[2]);
		self::assertNotNull($pattern, "upside down");
		self::assertEquals(new Vector3(10.5, 6, 10.5), $pattern->getSpawnPosition());
	}

	public function testIncompletePatternsAreRejected() : void{
		$skulls = $this->buildT(new Vector3(0, 1, 0), Facing::UP, Facing::EAST, skulls: 2);
		self::assertNull(WitherSummonPattern::find($this->world, $skulls[0]), "only two skulls");

		$skulls = $this->buildT(new Vector3(8, 1, 0), Facing::UP, Facing::EAST);
		$this->set(9, 1, 0, VanillaBlocks::STONE());
		self::assertNull(WitherSummonPattern::find($this->world, $skulls[0]), "a bottom corner must be empty");

		$skulls = $this->buildT(new Vector3(16, 1, 0), Facing::UP, Facing::EAST);
		$this->set(16, 1, 0, VanillaBlocks::DIRT());
		self::assertNull(WitherSummonPattern::find($this->world, $skulls[0]), "stem must be soul sand or soul soil");

		$skulls = $this->buildT(new Vector3(-8, 1, 0), Facing::UP, Facing::EAST);
		$this->set(-9, 3, 0, VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::SKELETON));
		self::assertNull(WitherSummonPattern::find($this->world, $skulls[1]), "skeleton skulls don't count");
	}

	public function testSummonClearsTheStructureAndSpawnsAWither() : void{
		$skulls = $this->buildT(new Vector3(0, 1, 0), Facing::UP, Facing::EAST);
		$wither = WitherSummonPattern::trySummon($this->world, $skulls[2]);
		self::assertInstanceOf(Wither::class, $wither);
		foreach([[0, 1, 0], [-1, 2, 0], [0, 2, 0], [1, 2, 0], [-1, 3, 0], [0, 3, 0], [1, 3, 0]] as [$x, $y, $z]){
			self::assertInstanceOf(Air::class, $this->get($x, $y, $z), "block at $x $y $z should be removed");
		}
		self::assertEqualsWithDelta(0.5, $wither->getPosition()->x, 0.001);
	}

	public function testPlacingTheLastSkullSummons() : void{
		$skulls = $this->buildT(new Vector3(0, 1, 0), Facing::UP, Facing::EAST, skulls: 2);
		$this->setAt($skulls[2], $this->skull());
		$this->get($skulls[2]->getFloorX(), $skulls[2]->getFloorY(), $skulls[2]->getFloorZ())->onPostPlace();
		$withers = array_filter($this->world->getEntities(), fn($e) => $e instanceof Wither);
		self::assertCount(1, $withers);
	}

	public function testNoSummonInPeaceful() : void{
		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		$skulls = $this->buildT(new Vector3(0, 1, 0), Facing::UP, Facing::EAST);
		self::assertNull(WitherSummonPattern::trySummon($this->world, $skulls[0]));
		self::assertNotNull(WitherSummonPattern::find($this->world, $skulls[0]));
	}

	public function testSpawnPhase() : void{
		$wither = new Wither(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		self::assertSame(450, $wither->getMaxHealth(), "normal difficulty");
		self::assertSame(Wither::SPAWN_INVULNERABLE_TICKS, $wither->getInvulnerableTicks());
		self::assertLessThan(10, $wither->getHealth());
		$wither->attack(new EntityDamageEvent($wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10));
		self::assertLessThan(10, $wither->getHealth());
		$this->runTicks(Wither::SPAWN_INVULNERABLE_TICKS / 2);
		self::assertEqualsWithDelta(225, $wither->getHealth(), 10, "health grows during the spawn phase");
		$this->runTicks(Wither::SPAWN_INVULNERABLE_TICKS / 2 + 2);
		self::assertSame(0, $wither->getInvulnerableTicks());
		self::assertSame(450.0, $wither->getHealth());
		self::assertSame(300, Wither::maxHealthForDifficulty(World::DIFFICULTY_EASY));
		self::assertSame(600, Wither::maxHealthForDifficulty(World::DIFFICULTY_HARD));
	}

	private function readyWither() : Wither{
		$wither = new Wither(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$this->runTicks(Wither::SPAWN_INVULNERABLE_TICKS + 2);
		return $wither;
	}

	public function testUndeadDealNoDamage() : void{
		$wither = $this->readyWither();
		$zombie = new Zombie(new Location(3.5, 1, 0.5, $this->world, 0, 0));
		$ev = new EntityDamageByEntityEvent($zombie, $wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5);
		$wither->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertTrue(Wither::isUndead($zombie));
		self::assertTrue(Wither::isUndead($wither));
	}

	public function testArmorBelowHalfHealthBlocksArrows() : void{
		$wither = $this->readyWither();
		$cow = new Cow(new Location(4.5, 1, 0.5, $this->world, 0, 0));
		$wither->setHealth(230);
		self::assertFalse($wither->isArmored());
		$wither->attack(new EntityDamageByEntityEvent($cow, $wither, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10));
		self::assertTrue($wither->isArmored(), "the wither gets its armour below half health");
		$this->runTicks(15); //damage cooldown
		$arrow = new Arrow(new Location(3.5, 2, 0.5, $this->world, 0, 0), $cow, false);
		$ev = new EntityDamageByChildEntityEvent($cow, $arrow, $wither, EntityDamageEvent::CAUSE_PROJECTILE, 6);
		$wither->attack($ev);
		self::assertTrue($ev->isCancelled(), "arrows can't hurt an armoured wither");
	}

	public function testShootsSkullsAtTarget() : void{
		$wither = $this->readyWither();
		$cow = new Cow(new Location(8.5, 1, 0.5, $this->world, 0, 0));
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$wither->setAttackTarget($cow);
		$normal = 0;
		$dangerous = 0;
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			foreach($this->world->getEntities() as $e){
				if($e instanceof DangerousWitherSkull){
					$dangerous = max($dangerous, $e->getId());
				}elseif($e instanceof WitherSkull){
					$normal = max($normal, $e->getId());
				}
			}
		}
		self::assertGreaterThan(0, $normal, "black skulls");
		self::assertGreaterThan(0, $dangerous, "the fourth skull of a volley is blue");
	}

	public function testDeathDropsNetherStarAfterAnimation() : void{
		$wither = $this->readyWither();
		$wither->attack(new EntityDamageEvent($wither, EntityDamageEvent::CAUSE_SUICIDE, 1000));
		self::assertFalse($wither->isAlive());
		$this->runTicks(Wither::DEATH_TICKS + 2);
		$stars = 0;
		foreach($this->world->getEntities() as $e){
			if($e instanceof ItemEntity && $e->getItem()->equals(VanillaItems::NETHER_STAR())){
				++$stars;
				self::assertSame(ItemEntity::NEVER_DESPAWN, $e->getDespawnDelay());
			}
		}
		self::assertSame(1, $stars);
		self::assertSame(50, $wither->getXpDropAmount());
	}

	public function testBossBarFollowsHealth() : void{
		$wither = $this->readyWither();
		self::assertEqualsWithDelta(1.0, $wither->getBossBar()->getPercent(), 0.001);
		$wither->setHealth(225);
		self::assertEqualsWithDelta(0.5, $wither->getBossBar()->getPercent(), 0.001);
		self::assertSame("Wither", $wither->getBossBar()->getTitle());
	}
}
