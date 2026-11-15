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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\Torch;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\generator\end\TheEnd;
use function count;
use function file_exists;
use function restore_error_handler;
use function set_error_handler;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use const E_DEPRECATED;
use const E_USER_DEPRECATED;

final class DragonFightTest extends DimensionWorldTestCase{
	private const SURFACE_Y = 62;

	private string $fightPath;

	protected function getDimension() : Dimension{
		return Dimension::END;
	}

	protected function setUp() : void{
		parent::setUp();
		$this->fightPath = sys_get_temp_dir() . "/pm-dragon-fight-test-" . uniqid() . ".json";
		DragonFight::setInstance(new DragonFight($this->fightPath));
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		//a small island of End stone around 0,0
		$this->fill(-8, self::SURFACE_Y - 2, -8, 8, self::SURFACE_Y, 8, VanillaBlocks::END_STONE());
	}

	protected function tearDown() : void{
		DragonFight::reset();
		if(file_exists($this->fightPath)){
			unlink($this->fightPath);
		}
		parent::tearDown();
	}

	private function origin() : Vector3{
		return new Vector3(0, self::SURFACE_Y + 1, 0);
	}

	public function testOriginIsOnTheSurface() : void{
		self::assertTrue(EndExitPortal::findOrigin($this->world)->equals($this->origin()));
		self::assertTrue(DragonFight::getInstance()->getExitPortalOrigin($this->world)->equals($this->origin()));
	}

	public function testInactivePortalShape() : void{
		EndExitPortal::build($this->world, $this->origin(), false);
		$y = self::SURFACE_Y + 1;
		//pillar with torches
		for($dy = 0; $dy < EndExitPortal::PILLAR_HEIGHT; ++$dy){
			self::assertSame(BlockTypeIds::BEDROCK, $this->get(0, $y + $dy, 0)->getTypeId());
		}
		self::assertSame(BlockTypeIds::AIR, $this->get(0, $y + 4, 0)->getTypeId());
		foreach(Facing::HORIZONTAL as $facing){
			$pos = (new Vector3(0, $y + 2, 0))->getSide($facing);
			$torch = $this->get($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
			self::assertInstanceOf(Torch::class, $torch);
			self::assertSame($facing, $torch->getFacing());
		}
		//empty pool, bedrock rim, bedrock bowl under the pool, End stone under the rim
		self::assertSame(BlockTypeIds::AIR, $this->get(2, $y, 1)->getTypeId());
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(3, $y, 0)->getTypeId());
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(2, $y, 2)->getTypeId());
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(2, $y - 1, 1)->getTypeId());
		self::assertSame(BlockTypeIds::END_STONE, $this->get(2, $y - 1, 2)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->get(4, $y, 0)->getTypeId(), "outside the rim nothing changes");
	}

	public function testActivePortalHasTwentyPortalBlocks() : void{
		EndExitPortal::build($this->world, $this->origin(), true);
		$y = self::SURFACE_Y + 1;
		$portals = 0;
		for($x = -4; $x <= 4; ++$x){
			for($z = -4; $z <= 4; ++$z){
				if($this->get($x, $y, $z)->getTypeId() === BlockTypeIds::END_PORTAL){
					++$portals;
				}
			}
		}
		self::assertSame(20, $portals);
		self::assertSame(BlockTypeIds::END_PORTAL, $this->get(-2, $y, 1)->getTypeId());
	}

	public function testKillsActivatePortalAndLeaveEggTwice() : void{
		$fight = DragonFight::getInstance();
		$top = EndExitPortal::getPillarTop($this->origin());
		self::assertSame(0, $fight->getKills($this->world));

		$fight->onDragonKilled($this->world);
		self::assertSame(BlockTypeIds::END_PORTAL, $this->get(1, self::SURFACE_Y + 1, 0)->getTypeId());
		self::assertSame(BlockTypeIds::DRAGON_EGG, $this->get(0, $top->getFloorY(), 0)->getTypeId());
		self::assertSame(1, $fight->getKills($this->world));

		$this->set(0, $top->getFloorY(), 0, VanillaBlocks::AIR()); //the egg was taken
		$fight->onDragonKilled($this->world);
		self::assertSame(BlockTypeIds::DRAGON_EGG, $this->get(0, $top->getFloorY(), 0)->getTypeId(), "Bedrock: the second dragon leaves an egg too");

		$this->set(0, $top->getFloorY(), 0, VanillaBlocks::AIR());
		$fight->onDragonKilled($this->world);
		self::assertSame(BlockTypeIds::AIR, $this->get(0, $top->getFloorY(), 0)->getTypeId(), "no egg from the third dragon on");
		self::assertSame(3, $fight->getKills($this->world));

		$this->set(0, $top->getFloorY(), 0, VanillaBlocks::AIR());
		$fight->onDragonKilled($this->world);
		self::assertSame(BlockTypeIds::AIR, $this->get(0, $top->getFloorY(), 0)->getTypeId());
		self::assertSame(4, $fight->getKills($this->world));

		//saved
		$reloaded = new DragonFight($this->fightPath);
		self::assertSame(4, $reloaded->getKills($this->world));
		self::assertTrue($reloaded->getExitPortalOrigin($this->world)->equals($this->origin()));
	}

	public function testStartTriggersNoDeprecation() : void{
		//the server logs deprecations: spawning the dragon and the crystals once used lcg_value() (deprecated in PHP 8.4)
		set_error_handler(function(int $severity, string $message, string $file, int $line) : bool{
			throw new \ErrorException($message, 0, $severity, $file, $line);
		}, E_DEPRECATED | E_USER_DEPRECATED);
		try{
			DragonFight::getInstance()->start($this->world);
			//a pillar rebuilt by the respawn ritual gets a new crystal
			DragonFight::rebuildSpike($this->world, DragonFight::getSpikes($this->world)[0], null);
		}finally{
			restore_error_handler();
		}
		self::assertTrue(DragonFight::getInstance()->isStarted($this->world));
	}

	public function testStartPlacesCrystalsAndDragon() : void{
		$dragon = DragonFight::getInstance()->start($this->world);
		self::assertTrue(DragonFight::getInstance()->isStarted($this->world));
		self::assertSame((float) DragonFight::DRAGON_SPAWN_Y, $dragon->getPosition()->y);
		$crystals = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof EndCrystal){
				$crystals[] = $entity;
			}
		}
		self::assertCount(TheEnd::PILLAR_COUNT, $crystals);
		foreach($crystals as $crystal){
			self::assertTrue($crystal->showBase());
		}
		//the empty exit portal is there
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(3, self::SURFACE_Y + 1, 0)->getTypeId());
		self::assertSame(BlockTypeIds::AIR, $this->get(1, self::SURFACE_Y + 1, 0)->getTypeId());
		self::assertSame(EnderDragon::FIRST_KILL_XP, $dragon->getXpDropAmount());
	}

	public function testSummonedDragonDeathKeepsThePortalClosed() : void{
		//BDS: only the dragon of the fight opens the portal; a summoned one gives no egg, gateway or portal
		$dragon = new EnderDragon(new Location(0.5, 100, 0.5, $this->world, 0, 0));
		self::assertFalse($dragon->isFightDragon());
		$this->runTicks(2);
		$dragon->attack(new EntityDamageEvent($dragon, EntityDamageEvent::CAUSE_SUICIDE, 1000));
		$this->runTicks(EnderDragon::DEATH_TICKS + 2);
		self::assertNotSame(BlockTypeIds::END_PORTAL, $this->get(1, self::SURFACE_Y + 1, 0)->getTypeId());
		self::assertNotSame(BlockTypeIds::DRAGON_EGG, $this->get(0, self::SURFACE_Y + 5, 0)->getTypeId());
		self::assertSame(0, DragonFight::getInstance()->getKills($this->world));
	}

	public function testDragonDeathOpensThePortal() : void{
		$dragon = new EnderDragon(new Location(0.5, 100, 0.5, $this->world, 0, 0));
		$dragon->setFightDragon(true);
		$this->runTicks(2);
		$dragon->attack(new EntityDamageEvent($dragon, EntityDamageEvent::CAUSE_SUICIDE, 1000));
		self::assertFalse($dragon->isAlive());
		$this->runTicks(EnderDragon::DEATH_TICKS + 2);
		self::assertTrue($dragon->isClosed() || $dragon->isFlaggedForDespawn());
		self::assertSame(BlockTypeIds::END_PORTAL, $this->get(1, self::SURFACE_Y + 1, 0)->getTypeId());
		self::assertSame(BlockTypeIds::DRAGON_EGG, $this->get(0, self::SURFACE_Y + 5, 0)->getTypeId());
		self::assertSame(1, DragonFight::getInstance()->getKills($this->world));
		$second = new EnderDragon(new Location(0.5, 100, 0.5, $this->world, 0, 0));
		self::assertSame(EnderDragon::XP, $second->getXpDropAmount(), "500 XP after the first kill");
	}

	public function testDragonPerchesOnThePortal() : void{
		EndExitPortal::build($this->world, $this->origin(), false);
		$dragon = new EnderDragon(new Location(30.5, 90, 0.5, $this->world, 0, 0));
		$dragon->setPhase(EnderDragon::PHASE_LANDING);
		for($i = 0; $i < 600 && $dragon->getPhase() !== EnderDragon::PHASE_PERCHED; ++$i){
			$this->runTicks(1);
		}
		self::assertSame(EnderDragon::PHASE_PERCHED, $dragon->getPhase());
		$perch = $dragon->getPerchPosition();
		self::assertNotNull($perch);
		self::assertLessThan(1.0, $dragon->getPosition()->distance($perch));
		//nobody around: takes off (or charges) after 5 seconds
		$this->runTicks(110);
		self::assertNotSame(EnderDragon::PHASE_PERCHED, $dragon->getPhase());
	}

	public function testCrystalsHealTheDragon() : void{
		$dragon = new EnderDragon(new Location(0.5, 100, 0.5, $this->world, 0, 0));
		$crystal = new EndCrystal(new Location(10.5, 100, 0.5, $this->world, 0, 0));
		$crystal->spawnToAll();
		$dragon->setHealth(100);
		$this->runTicks(40);
		self::assertGreaterThan(100, $dragon->getHealth());
		self::assertSame($crystal, $dragon->getHealingCrystal());
		self::assertSame(1, $dragon->getCrystalsAlive());

		//destroying the crystal it heals from hurts it by 10
		$health = $dragon->getHealth();
		$crystal->flagForDespawn();
		$this->runTicks(12);
		self::assertEqualsWithDelta($health - EnderDragon::CRYSTAL_DESTROYED_DAMAGE, $dragon->getHealth(), 0.01);
		self::assertNull($dragon->getHealingCrystal());
	}

	public function testStartPlacesNoCrystalsTwiceOnArrival() : void{
		$fight = DragonFight::getInstance();
		$fight->start($this->world);
		self::assertTrue($fight->isStarted($this->world));
		$count = count($this->world->getEntities());
		$fight->onPlayerArrived($this->world);
		self::assertCount($count, $this->world->getEntities(), "an already started fight doesn't start again");
	}
}
