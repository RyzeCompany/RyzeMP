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

namespace pocketmine\world\dimension;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Egg;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\entity\projectile\Trident;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ItemUseResult;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\WorldManager;
use function array_filter;
use function count;
use function file_exists;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class EndCrystalTest extends DimensionWorldTestCase{
	private const FLOOR_Y = 10;

	private string $fightPath;

	protected function getDimension() : Dimension{
		return Dimension::END;
	}

	protected function setUp() : void{
		parent::setUp();
		$this->fightPath = sys_get_temp_dir() . "/pm-end-crystal-test-" . uniqid() . ".json";
		DragonFight::setInstance(new DragonFight($this->fightPath));
		$this->fill(-8, self::FLOOR_Y, -8, 24, self::FLOOR_Y, 8, VanillaBlocks::OBSIDIAN());
	}

	protected function tearDown() : void{
		DragonFight::reset();
		if(file_exists($this->fightPath)){
			unlink($this->fightPath);
		}
		parent::tearDown();
	}

	private function crystal(float $x, float $z) : EndCrystal{
		$crystal = new EndCrystal(new Location($x, self::FLOOR_Y + 1, $z, $this->world, 0, 0));
		$crystal->spawnToAll();
		return $crystal;
	}

	private function isDestroyed(EndCrystal $crystal) : bool{
		return !$crystal->isAlive() || $crystal->isClosed() || $crystal->isFlaggedForDespawn();
	}

	private function shoot(Entity $projectile, EndCrystal $crystal) : void{
		$projectile->setMotion(new Vector3(1.5, 0, 0));
		$projectile->spawnToAll();
		for($i = 0; $i < 20 && !$this->isDestroyed($crystal); ++$i){
			$this->runTicks(1);
		}
	}

	/**
	 * @phpstan-return \Closure(float, float) : Location
	 */
	private function shooterLocation() : \Closure{
		return fn(float $x, float $z) => new Location($x, self::FLOOR_Y + 2, $z, $this->world, 0, 0);
	}

	public function testAnyProjectileDestroysIt() : void{
		$at = $this->shooterLocation();
		$projectiles = [
			"arrow" => fn() => new Arrow($at(-5.5, 0.5), null, false),
			"snowball" => fn() => new Snowball($at(-5.5, 0.5), null),
			"egg" => fn() => new Egg($at(-5.5, 0.5), null),
			"trident" => fn() => new Trident($at(-5.5, 0.5), VanillaItems::TRIDENT(), null)
		];
		foreach($projectiles as $name => $make){
			$crystal = $this->crystal(0.5, 0.5);
			$this->shoot($make(), $crystal);
			self::assertTrue($this->isDestroyed($crystal), "a $name destroys the crystal");
			$this->runTicks(2);
			foreach($this->world->getEntities() as $entity){
				if(!$entity instanceof EndCrystal){
					$entity->flagForDespawn();
				}
			}
			$this->runTicks(1);
		}
	}

	public function testSplashPotionDoesNotDestroyIt() : void{
		$crystal = $this->crystal(0.5, 0.5);
		$potion = new SplashPotion($this->shooterLocation()(-5.5, 0.5), null, PotionType::WATER);
		$this->shoot($potion, $crystal);
		self::assertFalse($this->isDestroyed($crystal));
	}

	public function testExplodesAndChainsToNearbyCrystals() : void{
		$first = $this->crystal(0.5, 0.5);
		$second = $this->crystal(4.5, 0.5);
		$far = $this->crystal(20.5, 0.5);
		$this->set(2, self::FLOOR_Y + 1, 3, VanillaBlocks::STONE());
		$first->attack(new EntityDamageEvent($first, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 0));
		self::assertFalse($first->isAlive(), "a hit that deals no damage still destroys it");
		$this->runTicks(3);
		self::assertTrue($this->isDestroyed($second), "chain reaction");
		self::assertFalse($this->isDestroyed($far));
		self::assertSame(BlockTypeIds::AIR, $this->get(2, self::FLOOR_Y + 1, 3)->getTypeId(), "the explosion breaks blocks");
		self::assertSame(BlockTypeIds::OBSIDIAN, $this->get(2, self::FLOOR_Y, 0)->getTypeId());
		self::assertCount(0, array_filter([[1, 1], [2, 2], [3, 1]], fn($p) => $this->get($p[0], self::FLOOR_Y + 1, $p[1])->getTypeId() === BlockTypeIds::FIRE), "no fire");
	}

	public function testDragonAndInvulnerability() : void{
		//damage events find their damager through the world manager
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
		$server->method("getWorldManager")->willReturn($worldManager);

		$crystal = $this->crystal(0.5, 0.5);
		$dragon = new EnderDragon(new Location(10.5, 40, 0.5, $this->world, 0, 0));
		$ev = new EntityDamageByEntityEvent($dragon, $crystal, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10);
		$crystal->attack($ev);
		self::assertTrue($ev->isCancelled(), "the dragon doesn't destroy crystals");
		self::assertTrue($crystal->isAlive());

		$crystal->setInvulnerable(true);
		$crystal->attack(new EntityDamageEvent($crystal, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 100));
		self::assertTrue($crystal->isAlive());
		self::assertTrue($crystal->saveNBT()->getByte("Invulnerable", 0) === 1);
		$crystal->attack(new EntityDamageEvent($crystal, EntityDamageEvent::CAUSE_VOID, 100));
		self::assertFalse($crystal->isAlive(), "the void still kills it");
	}

	private function player() : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getId")->willReturn(424242);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	public function testPlacement() : void{
		$player = $this->player();
		$place = function(int $x, int $z) use ($player) : ItemUseResult{
			$item = VanillaItems::END_CRYSTAL()->setCount(2);
			$block = $this->get($x, self::FLOOR_Y, $z);
			$returned = [];
			return $item->onInteractBlock($player, $block->getSide(Facing::UP), $block, Facing::UP, new Vector3(0.5, 1, 0.5), $returned);
		};
		$crystals = fn() => count(array_filter($this->world->getEntities(), fn($e) => $e instanceof EndCrystal));

		self::assertSame(ItemUseResult::SUCCESS, $place(0, 0), "on obsidian");
		self::assertSame(1, $crystals());
		self::assertSame(ItemUseResult::NONE, $place(0, 0), "a crystal is already there");
		self::assertSame(ItemUseResult::NONE, $place(1, 0), "the crystal next to it is in the way");

		$this->set(5, self::FLOOR_Y, 0, VanillaBlocks::BEDROCK());
		self::assertSame(ItemUseResult::SUCCESS, $place(5, 0), "on bedrock");

		$this->set(9, self::FLOOR_Y, 0, VanillaBlocks::STONE());
		self::assertSame(ItemUseResult::NONE, $place(9, 0), "not on stone");

		$this->set(12, self::FLOOR_Y + 2, 0, VanillaBlocks::STONE());
		self::assertSame(ItemUseResult::NONE, $place(12, 0), "needs two air blocks above");
		self::assertSame(2, $crystals());
	}

	public function testPlacingTheFourthCrystalStartsTheRespawn() : void{
		$origin = new Vector3(0, self::FLOOR_Y + 1, 0);
		$fight = DragonFight::getInstance();
		//the exit portal (and the dragon killed once) at the default position on top of the obsidian floor
		self::assertTrue($fight->getExitPortalOrigin($this->world)->equals($origin));
		$fight->onDragonKilled($this->world);
		$player = $this->player();
		foreach(Facing::HORIZONTAL as $i => $side){
			self::assertNull($fight->getRespawnStage($this->world));
			$rim = $origin->getSide($side, DragonFight::RESPAWN_CRYSTAL_DISTANCE);
			$block = $this->get($rim->getFloorX(), $rim->getFloorY(), $rim->getFloorZ());
			self::assertSame(BlockTypeIds::BEDROCK, $block->getTypeId());
			$returned = [];
			self::assertSame(ItemUseResult::SUCCESS, VanillaItems::END_CRYSTAL()->onInteractBlock($player, $block->getSide(Facing::UP), $block, Facing::UP, new Vector3(0.5, 1, 0.5), $returned));
		}
		self::assertSame(DragonRespawnStage::START, $fight->getRespawnStage($this->world));
	}
}
