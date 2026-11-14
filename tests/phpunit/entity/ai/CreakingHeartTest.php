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

use pocketmine\block\CreakingHeart;
use pocketmine\block\ResinClump;
use pocketmine\block\tile\CreakingHeart as TileCreakingHeart;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\CreakingHeartState;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creaking;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;
use function abs;
use function floor;
use function mt_srand;

final class CreakingHeartTest extends MobTestCase{
	private const HEART_Y = 2;

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261013);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A vertical heart at (0, 2, 0) between two pale oak logs.
	 */
	private function buildHeart(bool $natural = false) : CreakingHeart{
		$this->set(0, 1, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
		$this->set(0, 3, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
		$this->set(0, self::HEART_Y, 0, VanillaBlocks::CREAKING_HEART()->setAxis(Axis::Y)->setNatural($natural));
		$this->runTicks(2);
		return $this->heart();
	}

	private function heart() : CreakingHeart{
		$heart = $this->get(0, self::HEART_Y, 0);
		self::assertInstanceOf(CreakingHeart::class, $heart);
		return $heart;
	}

	private function tile() : TileCreakingHeart{
		$tile = $this->world->getTileAt(0, self::HEART_Y, 0);
		self::assertInstanceOf(TileCreakingHeart::class, $tile);
		return $tile;
	}

	/**
	 * An awake heart (at night) with its creaking.
	 */
	private function heartWithCreaking() : Creaking{
		$this->world->setTime(World::TIME_MIDNIGHT);
		$heart = $this->buildHeart();
		self::assertSame(CreakingHeartState::AWAKE, $heart->getHeartState());
		$creaking = $heart->summonCreaking();
		self::assertNotNull($creaking, "no spawn position found");
		return $creaking;
	}

	private function player() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("getId")->willReturn(424242);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function countResinAround() : int{
		$count = 0;
		for($x = -3; $x <= 3; ++$x){
			for($y = 0; $y <= 6; ++$y){
				for($z = -3; $z <= 3; ++$z){
					if($this->get($x, $y, $z) instanceof ResinClump){
						++$count;
					}
				}
			}
		}
		return $count;
	}

	private static function assertCrumbled(Creaking $creaking, string $message = "") : void{
		self::assertTrue($creaking->isClosed() || $creaking->isFlaggedForDespawn(), $message);
		self::assertSame(Creaking::STATE_CRUMBLING, $creaking->getState(), $message);
	}

	public function testHeartIsAwakeOnlyAtNightBetweenLogs() : void{
		$this->world->setTime(World::TIME_NOON);
		$heart = $this->buildHeart();
		self::assertSame(CreakingHeartState::DORMANT, $heart->getHeartState(), "rooted by day");

		foreach([12600 => CreakingHeartState::DORMANT, 12601 => CreakingHeartState::AWAKE, 18000 => CreakingHeartState::AWAKE, 23400 => CreakingHeartState::AWAKE, 23401 => CreakingHeartState::DORMANT] as $time => $expected){
			$this->world->setTime($time);
			self::assertSame($expected, $this->heart()->computeHeartState(), "time $time");
		}

		//the scheduled heart tick follows the time of day
		$this->world->setTime(World::TIME_MIDNIGHT);
		$this->runTicks(CreakingHeart::UPDATE_INTERVAL + CreakingHeart::UPDATE_VARIANCE);
		self::assertSame(CreakingHeartState::AWAKE, $this->heart()->getHeartState());

		//no creaking yet: removing a log uproots it
		$this->set(0, 3, 0, VanillaBlocks::AIR());
		$this->runTicks(1);
		self::assertSame(CreakingHeartState::UPROOTED, $this->heart()->getHeartState());
	}

	public function testNoCreakingWithoutNearbyPlayer() : void{
		$this->world->setTime(World::TIME_MIDNIGHT);
		$this->buildHeart();
		$this->runTicks(60);
		self::assertSame(CreakingHeartState::AWAKE, $this->heart()->getHeartState());
		self::assertFalse($this->tile()->hasCreaking());
	}

	public function testSummonedCreakingIsBoundToItsHeart() : void{
		$creaking = $this->heartWithCreaking();
		$tile = $this->tile();
		self::assertTrue($creaking->isHeartBound());
		self::assertTrue((new Vector3(0, self::HEART_Y, 0))->equals($creaking->getHeartPosition() ?? Vector3::zero()));
		self::assertTrue($tile->isBoundTo($creaking));
		self::assertSame($creaking, $tile->getCreaking());
		self::assertSame($creaking, $this->heart()->getCreaking());
		self::assertFalse($creaking->canDespawn());
		self::assertTrue($creaking->isFireProof());
		//on the floor, within 16 blocks horizontally
		self::assertEqualsWithDelta(1.0, $creaking->getPosition()->y, 0.001);
		self::assertLessThanOrEqual(16.5, abs($creaking->getPosition()->x));
		self::assertLessThanOrEqual(16.5, abs($creaking->getPosition()->z));

		$this->runTicks(40);
		self::assertFalse($creaking->isFlaggedForDespawn(), "it lives at night near its heart");
		self::assertSame($creaking, $tile->getCreaking());

		//a log can't be removed from under a heart that has a creaking
		$this->set(0, 3, 0, VanillaBlocks::AIR());
		$this->runTicks(CreakingHeart::UPDATE_INTERVAL + CreakingHeart::UPDATE_VARIANCE);
		self::assertSame(CreakingHeartState::AWAKE, $this->heart()->getHeartState());
	}

	public function testBindingIsSavedOnBothSides() : void{
		$creaking = $this->heartWithCreaking();
		$creakingNbt = $creaking->saveNBT();
		$tileNbt = $this->tile()->saveNBT();
		self::assertSame([0, self::HEART_Y, 0], $creakingNbt->getIntArray(Creaking::TAG_HOME_POS));
		self::assertSame($creaking->getPersistentUniqueId(), $tileNbt->getLong(TileCreakingHeart::TAG_CREAKING));

		$loaded = new Creaking($this->loc(3.5, 1, 3.5), $creakingNbt);
		self::assertTrue($loaded->isHeartBound());
		self::assertSame($creaking->getPersistentUniqueId(), $loaded->getPersistentUniqueId());
		$loadedTile = TileFactory::getInstance()->createFromData($this->world, $tileNbt);
		self::assertInstanceOf(TileCreakingHeart::class, $loadedTile);
		self::assertTrue($loadedTile->hasCreaking());
		self::assertTrue($loadedTile->isBoundTo($loaded));

		//a player-spawned creaking has no heart
		$free = new Creaking($this->loc(5.5, 1, 5.5));
		self::assertFalse($free->isHeartBound());
		self::assertFalse($free->saveNBT()->getTag(Creaking::TAG_HOME_POS) !== null);
		$loaded->flagForDespawn();
		$free->flagForDespawn();
	}

	public function testHeartBoundCreakingIsInvulnerable() : void{
		$creaking = $this->heartWithCreaking();
		$zombie = new Zombie($this->loc(4.5, 1, 4.5));
		$ev = new EntityDamageByEntityEvent($zombie, $creaking, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5.0);
		$creaking->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertTrue($creaking->isAlive());
		self::assertSame(1, $creaking->getSwayingTicks(), "a hit makes it sway");
		self::assertSame(0, $this->countResinAround(), "only player hits make the heart react");

		foreach([EntityDamageEvent::CAUSE_FALL, EntityDamageEvent::CAUSE_FIRE, EntityDamageEvent::CAUSE_LAVA, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, EntityDamageEvent::CAUSE_MAGIC] as $cause){
			$creaking->attack(new EntityDamageEvent($creaking, $cause, 10.0));
			self::assertTrue($creaking->isAlive(), "cause $cause");
		}

		//the sway lasts 5 ticks (1 to 6, then reset)
		$this->runTicks(6);
		self::assertSame(0, $creaking->getSwayingTicks());

		//the void still kills it
		$creaking->attack(new EntityDamageEvent($creaking, EntityDamageEvent::CAUSE_VOID, 4.0));
		self::assertFalse($creaking->isAlive());
		$zombie->flagForDespawn();
	}

	public function testPlayerSpawnedCreakingIsMortal() : void{
		$creaking = new Creaking($this->loc(3.5, 1, 3.5));
		$creaking->attack(new EntityDamageByEntityEvent($this->player(), $creaking, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2.0));
		self::assertFalse($creaking->isAlive());
		self::assertFalse($creaking->isFireProof());
	}

	public function testPlayerHitGrowsResinOnTheLogs() : void{
		$creaking = $this->heartWithCreaking();
		//a player's hit is cancelled (the mocked player isn't in the world, so the heart reaction is called directly)
		$ev = new EntityDamageByEntityEvent($this->player(), $creaking, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2.0);
		$creaking->attack($ev);
		self::assertTrue($ev->isCancelled());
		self::assertTrue($creaking->isAlive());

		$this->heart()->onCreakingHurt($creaking);
		$resin = $this->countResinAround();
		self::assertGreaterThanOrEqual(2, $resin);
		self::assertLessThanOrEqual(3, $resin);

		//the heart reacts once every 5 seconds
		$this->runTicks(10);
		$this->heart()->onCreakingHurt($creaking);
		self::assertSame($resin, $this->countResinAround());
		$this->runTicks(CreakingHeart::HURT_REACTION_COOLDOWN);
		$this->heart()->onCreakingHurt($creaking);
		self::assertGreaterThan($resin, $this->countResinAround());

		//no resin by day (a named creaking survives it)
		$creaking->setNameTag("Bark");
		$this->world->setTime(World::TIME_NOON);
		$this->runTicks(CreakingHeart::HURT_REACTION_COOLDOWN + CreakingHeart::UPDATE_INTERVAL);
		self::assertSame(CreakingHeartState::DORMANT, $this->heart()->getHeartState());
		$before = $this->countResinAround();
		$this->heart()->onCreakingHurt($creaking);
		self::assertSame($before, $this->countResinAround());
	}

	public function testResinOnlyGrowsOnPaleOakLogsNearTheHeart() : void{
		$this->world->setTime(World::TIME_MIDNIGHT);
		$heart = $this->buildHeart();
		for($i = 0; $i < 9; ++$i){
			$pos = $heart->spreadResin();
			self::assertNotNull($pos);
			$resin = $this->world->getBlock($pos);
			self::assertInstanceOf(ResinClump::class, $resin);
			$attachedToLog = false;
			foreach($resin->getFaces() as $face){
				$support = $this->world->getBlock($pos->getSide($face));
				$attachedToLog = $attachedToLog || $support->isSameState(VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
			}
			self::assertTrue($attachedToLog, "resin at $pos is not on a pale oak log");
		}
		//9 free faces: 4 sides of each log and the top of the upper one (the lower one stands on stone)
		self::assertNull($heart->spreadResin(), "every face of the logs is covered");
	}

	public function testCreakingCrumblesByDay() : void{
		$creaking = $this->heartWithCreaking();
		$this->world->setTime(World::TIME_NOON);
		$this->runTicks(1);
		self::assertCrumbled($creaking);
		self::assertFalse($this->tile()->hasCreaking(), "the heart forgets it");
	}

	public function testNamedCreakingSurvivesTheDay() : void{
		$creaking = $this->heartWithCreaking();
		$creaking->setNameTag("Bark");
		$this->world->setTime(World::TIME_NOON);
		$this->runTicks(2);
		self::assertFalse($creaking->isFlaggedForDespawn());
		self::assertTrue($this->tile()->isBoundTo($creaking));
	}

	public function testCreakingTooFarFromItsHeartCrumbles() : void{
		$creaking = $this->heartWithCreaking();
		$creaking->teleport(new Vector3(30.5, 1, 20.5));
		$this->runTicks(1);
		self::assertCrumbled($creaking);
		self::assertFalse($this->tile()->hasCreaking());
	}

	public function testBreakingTheHeartMakesTheCreakingTwitchThenCrumble() : void{
		$creaking = $this->heartWithCreaking();
		$this->breakBlock(0, self::HEART_Y, 0);
		self::assertNotInstanceOf(CreakingHeart::class, $this->get(0, self::HEART_Y, 0));
		self::assertSame(Creaking::STATE_TWITCHING, $creaking->getState());
		self::assertTrue($creaking->isFrozen());
		$this->runTicks(Creaking::TWITCH_TICKS - 2);
		self::assertFalse($creaking->isFlaggedForDespawn(), "still twitching");
		$this->runTicks(3);
		self::assertCrumbled($creaking);
	}

	public function testCreakingOfAReplacedHeartCrumbles() : void{
		$creaking = $this->heartWithCreaking();
		$this->set(0, self::HEART_Y, 0, VanillaBlocks::STONE());
		$this->runTicks(1);
		self::assertCrumbled($creaking, "no longer bound to a heart");
	}

	public function testComparatorReadsCreakingDistance() : void{
		$this->world->setTime(World::TIME_MIDNIGHT);
		$heart = $this->buildHeart();
		self::assertSame(0, $heart->getComparatorOutput());
		$creaking = $heart->summonCreaking();
		self::assertNotNull($creaking);
		$creaking->teleport(new Vector3(8.5, 1, 0.5));
		$distance = $creaking->getPosition()->distance(new Vector3(0.5, 2.5, 0.5));
		self::assertSame(15 - (int) floor($distance / 32 * 15), $this->heart()->getComparatorOutput());
	}

	public function testOnlyNaturalHeartsDropExperience() : void{
		$axe = VanillaItems::IRON_AXE();
		self::assertSame(0, VanillaBlocks::CREAKING_HEART()->getXpDropForTool($axe));
		$xp = VanillaBlocks::CREAKING_HEART()->setNatural(true)->getXpDropForTool($axe);
		self::assertGreaterThanOrEqual(20, $xp);
		self::assertLessThanOrEqual(24, $xp);
	}
}
