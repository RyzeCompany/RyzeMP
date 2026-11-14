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
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\EndSpike;
use function array_filter;
use function array_values;
use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class DragonRespawnTest extends DimensionWorldTestCase{
	private const SURFACE_Y = 62;
	/** Ticks of the stages before SUMMONING_DRAGON: START (1) + PREPARING_TO_SUMMON_PILLARS (100) */
	private const PILLARS_START = 101;

	private string $fightPath;

	protected function getDimension() : Dimension{
		return Dimension::END;
	}

	protected function setUp() : void{
		parent::setUp();
		$this->fightPath = sys_get_temp_dir() . "/pm-dragon-respawn-test-" . uniqid() . ".json";
		DragonFight::setInstance(new DragonFight($this->fightPath));
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		//the pillars are up to 42+10 blocks from 0,0
		$loader = new class implements ChunkLoader{};
		for($cx = -4; $cx <= 3; ++$cx){
			for($cz = -4; $cz <= 3; ++$cz){
				if(!$this->world->isChunkLoaded($cx, $cz)){
					$this->world->registerChunkLoader($loader, $cx, $cz, false);
					$this->world->setChunk($cx, $cz, new Chunk([], true));
				}
			}
		}
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

	/**
	 * Kills the first dragon: the exit portal is active.
	 */
	private function killDragon() : void{
		DragonFight::getInstance()->onDragonKilled($this->world);
		$this->runTicks(1);
	}

	/**
	 * Places End crystals on the bedrock rim of the exit portal, on the given sides.
	 *
	 * @param int[] $sides
	 * @return EndCrystal[]
	 */
	private function placeRespawnCrystals(array $sides = Facing::HORIZONTAL) : array{
		$crystals = [];
		foreach($sides as $side){
			$rim = $this->origin()->getSide($side, DragonFight::RESPAWN_CRYSTAL_DISTANCE);
			self::assertSame(BlockTypeIds::BEDROCK, $this->get($rim->getFloorX(), $rim->getFloorY(), $rim->getFloorZ())->getTypeId());
			$crystal = new EndCrystal(Location::fromObject($rim->add(0.5, 1, 0.5), $this->world));
			$crystal->spawnToAll();
			$crystals[] = $crystal;
		}
		return $crystals;
	}

	private function countPortalBlocks() : int{
		$count = 0;
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				if($this->get($x, self::SURFACE_Y + 1, $z)->getTypeId() === BlockTypeIds::END_PORTAL){
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * @return EnderDragon[]
	 */
	private function dragons() : array{
		return array_values(array_filter($this->world->getEntities(), fn($e) => $e instanceof EnderDragon && $e->isAlive() && !$e->isFlaggedForDespawn()));
	}

	private function crystalOnSpike(EndSpike $spike) : ?EndCrystal{
		foreach($this->world->getEntities() as $entity){
			$pos = $entity->getPosition();
			if($entity instanceof EndCrystal && !$entity->isFlaggedForDespawn() && $entity->isAlive() &&
				$pos->getFloorX() === $spike->centerX && $pos->getFloorZ() === $spike->centerZ && $pos->getFloorY() === $spike->height + 1){
				return $entity;
			}
		}
		return null;
	}

	public function testNoRespawnBeforeTheFirstKill() : void{
		EndExitPortal::build($this->world, $this->origin(), false);
		$this->placeRespawnCrystals();
		self::assertFalse(DragonFight::getInstance()->tryRespawn($this->world), "the dragon was never killed");
		self::assertNull(DragonFight::getInstance()->getRespawnStage($this->world));
	}

	public function testNoRespawnWhileADragonIsAlive() : void{
		$this->killDragon();
		$dragon = new EnderDragon(new Location(0.5, 100, 0.5, $this->world, 0, 0));
		$dragon->spawnToAll();
		$this->placeRespawnCrystals();
		self::assertFalse(DragonFight::getInstance()->tryRespawn($this->world));
	}

	public function testNeedsACrystalOnEachSide() : void{
		$this->killDragon();
		self::assertTrue(DragonFight::getInstance()->isDragonKilled($this->world));
		$this->placeRespawnCrystals([Facing::NORTH, Facing::SOUTH, Facing::EAST]);
		self::assertFalse(DragonFight::getInstance()->tryRespawn($this->world));
		//a crystal in the wrong place (in a corner of the portal) doesn't count
		$wrong = new EndCrystal(Location::fromObject($this->origin()->add(-1.5, 1, -1.5), $this->world));
		$wrong->spawnToAll();
		self::assertFalse(DragonFight::getInstance()->tryRespawn($this->world));

		$this->placeRespawnCrystals([Facing::WEST]);
		self::assertSame(20, $this->countPortalBlocks());
		self::assertTrue(DragonFight::getInstance()->tryRespawn($this->world));
		self::assertSame(DragonRespawnStage::START, DragonFight::getInstance()->getRespawnStage($this->world));
		self::assertSame(0, $this->countPortalBlocks(), "the exit portal closes");
		self::assertSame(BlockTypeIds::BEDROCK, $this->get(3, self::SURFACE_Y + 1, 0)->getTypeId(), "the bedrock frame stays");
		self::assertFalse(DragonFight::getInstance()->tryRespawn($this->world), "already respawning");
	}

	public function testFullRespawnSequence() : void{
		$fight = DragonFight::getInstance();
		$this->killDragon();
		$egg = EndExitPortal::getPillarTop($this->origin());
		self::assertSame(BlockTypeIds::DRAGON_EGG, $this->get(0, $egg->getFloorY(), 0)->getTypeId());
		$spikes = DragonFight::getSpikes($this->world);
		self::assertCount(10, $spikes);
		//the first pillar was blown up by players
		$first = $spikes[0];
		$this->fill($first->centerX - 1, $first->height - 5, $first->centerZ - 1, $first->centerX + 1, $first->height + 2, $first->centerZ + 1, VanillaBlocks::AIR());

		$crystals = $this->placeRespawnCrystals();
		self::assertTrue($fight->tryRespawn($this->world));

		$this->runTicks(1);
		self::assertSame(DragonRespawnStage::PREPARING_TO_SUMMON_PILLARS, $fight->getRespawnStage($this->world));
		foreach($crystals as $crystal){
			self::assertTrue((new Vector3(0, DragonFight::DRAGON_SPAWN_Y, 0))->equals($crystal->getBeamTarget() ?? Vector3::zero()), "beams aim above the portal");
		}

		$this->runTicks(100);
		self::assertSame(DragonRespawnStage::SUMMONING_PILLARS, $fight->getRespawnStage($this->world));
		$this->runTicks(1);
		foreach($crystals as $crystal){
			self::assertTrue((new Vector3($first->centerX, $first->height + 1, $first->centerZ))->equals($crystal->getBeamTarget() ?? Vector3::zero()), "beams aim at the first pillar");
		}
		self::assertSame(BlockTypeIds::AIR, $this->get($first->centerX, $first->height, $first->centerZ)->getTypeId());
		$this->runTicks(DragonFight::PILLAR_TICKS - 1);
		//the first pillar is rebuilt with an invulnerable crystal aiming above the portal
		self::assertSame(BlockTypeIds::BEDROCK, $this->get($first->centerX, $first->height, $first->centerZ)->getTypeId());
		self::assertSame(BlockTypeIds::OBSIDIAN, $this->get($first->centerX, $first->height - 1, $first->centerZ)->getTypeId());
		self::assertSame(BlockTypeIds::OBSIDIAN, $this->get($first->centerX + 1, $first->height - 3, $first->centerZ)->getTypeId());
		self::assertSame(BlockTypeIds::FIRE, $this->get($first->centerX, $first->height + 1, $first->centerZ)->getTypeId());
		$newCrystal = $this->crystalOnSpike($first);
		self::assertNotNull($newCrystal);
		self::assertTrue($newCrystal->isInvulnerable());
		self::assertTrue($newCrystal->showBase());
		self::assertTrue((new Vector3(0, DragonFight::DRAGON_SPAWN_Y, 0))->equals($newCrystal->getBeamTarget() ?? Vector3::zero()));
		$newCrystal->attack(new EntityDamageEvent($newCrystal, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 50));
		self::assertTrue($newCrystal->isAlive(), "invulnerable during the sequence");
		$this->runTicks(1);
		$second = $spikes[1];
		foreach($crystals as $crystal){
			self::assertTrue((new Vector3($second->centerX, $second->height + 1, $second->centerZ))->equals($crystal->getBeamTarget() ?? Vector3::zero()), "then at the second");
		}

		//the other nine pillars, then the dragon
		$this->runTicks(DragonFight::PILLAR_TICKS * 9);
		self::assertSame(DragonRespawnStage::SUMMONING_DRAGON, $fight->getRespawnStage($this->world));
		foreach($spikes as $spike){
			self::assertNotNull($this->crystalOnSpike($spike), "crystal on the pillar at $spike->centerX $spike->centerZ");
		}
		self::assertCount(0, $this->dragons());
		self::assertTrue($fight->isDragonKilled($this->world));

		$this->runTicks(100);
		self::assertNull($fight->getRespawnStage($this->world));
		self::assertFalse($fight->isDragonKilled($this->world));
		self::assertCount(1, $this->dragons(), "the new dragon is there");
		foreach($crystals as $crystal){
			self::assertTrue($crystal->isFlaggedForDespawn() || $crystal->isClosed(), "the four crystals are gone");
		}
		foreach($spikes as $spike){
			$crystal = $this->crystalOnSpike($spike);
			self::assertNotNull($crystal);
			self::assertFalse($crystal->isInvulnerable());
			self::assertNull($crystal->getBeamTarget());
		}
		self::assertSame(0, $this->countPortalBlocks(), "the portal stays closed until the dragon dies");
		self::assertSame(BlockTypeIds::DRAGON_EGG, $this->get(0, $egg->getFloorY(), 0)->getTypeId(), "the egg stays");
		self::assertSame(BlockTypeIds::TORCH, $this->get(1, self::SURFACE_Y + 3, 0)->getTypeId(), "the final explosions break no blocks");

		//the respawned dragon gives 500 XP and opens the portal again when it dies
		$dragon = $this->dragons()[0];
		self::assertSame(EnderDragon::XP, $dragon->getXpDropAmount());
		$dragon->attack(new EntityDamageEvent($dragon, EntityDamageEvent::CAUSE_SUICIDE, 1000));
		$this->runTicks(EnderDragon::DEATH_TICKS + 2);
		self::assertSame(20, $this->countPortalBlocks());
		self::assertSame(2, $fight->getKills($this->world));
		self::assertTrue($fight->isDragonKilled($this->world));
	}

	public function testDestroyingARespawnCrystalAborts() : void{
		$fight = DragonFight::getInstance();
		$this->killDragon();
		$crystals = $this->placeRespawnCrystals();
		self::assertTrue($fight->tryRespawn($this->world));
		$this->runTicks(self::PILLARS_START + DragonFight::PILLAR_TICKS + 1);
		self::assertSame(DragonRespawnStage::SUMMONING_PILLARS, $fight->getRespawnStage($this->world));
		$rebuilt = $this->crystalOnSpike(DragonFight::getSpikes($this->world)[0]);
		self::assertNotNull($rebuilt);
		self::assertTrue($rebuilt->isInvulnerable());

		$crystals[0]->attack(new EntityDamageEvent($crystals[0], EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(2);
		self::assertNull($fight->getRespawnStage($this->world));
		self::assertTrue($fight->isDragonKilled($this->world));
		self::assertSame(20, $this->countPortalBlocks(), "the portal opens again");
		self::assertFalse($rebuilt->isInvulnerable());
		self::assertNull($rebuilt->getBeamTarget());
		self::assertFalse($crystals[0]->isAlive());
		foreach($crystals as $crystal){
			//its explosion destroys the others too (chain reaction)
			self::assertTrue(!$crystal->isAlive() || $crystal->getBeamTarget() === null);
		}
		self::assertCount(0, $this->dragons());
	}

	public function testRespawnIsSavedAndResumed() : void{
		$this->killDragon();
		$crystals = $this->placeRespawnCrystals();
		self::assertTrue(DragonFight::getInstance()->tryRespawn($this->world));
		$this->runTicks(self::PILLARS_START + 60);

		//"restart": a new fight table read from the file finds the crystals again by their position
		$reloaded = new DragonFight($this->fightPath);
		self::assertSame(DragonRespawnStage::SUMMONING_PILLARS, $reloaded->getRespawnStage($this->world));
		self::assertTrue($reloaded->isDragonKilled($this->world));
		DragonFight::setInstance($reloaded);
		$this->runTicks(DragonFight::PILLAR_TICKS * 10 + 100);
		self::assertNull($reloaded->getRespawnStage($this->world));
		self::assertCount(1, $this->dragons());
		foreach($crystals as $crystal){
			self::assertTrue($crystal->isFlaggedForDespawn() || $crystal->isClosed());
		}
	}

	public function testRespawnAbortsWhenTheCrystalsAreGone() : void{
		$this->killDragon();
		$crystals = $this->placeRespawnCrystals();
		self::assertTrue(DragonFight::getInstance()->tryRespawn($this->world));
		$this->runTicks(5);
		foreach($crystals as $crystal){
			$crystal->flagForDespawn();
		}
		$this->runTicks(2);
		self::assertNull(DragonFight::getInstance()->getRespawnStage($this->world));
		self::assertSame(20, $this->countPortalBlocks());
	}

	public function testLegacyFileWithKillsMeansDeadDragon() : void{
		file_put_contents($this->fightPath, '{"dimension_test":{"started":true,"kills":1,"portal":[0,63,0],"gateways":[1,2]}}');
		self::assertTrue((new DragonFight($this->fightPath))->isDragonKilled($this->world));
		file_put_contents($this->fightPath, '{"dimension_test":{"started":true,"kills":0,"portal":[0,63,0],"gateways":null}}');
		self::assertFalse((new DragonFight($this->fightPath))->isDragonKilled($this->world));
		//a corrupted respawn entry is dropped
		file_put_contents($this->fightPath, '{"dimension_test":{"started":true,"kills":1,"killed":true,"respawn":{"stage":"bogus","time":3,"crystals":[]}}}');
		self::assertNull((new DragonFight($this->fightPath))->getRespawnStage($this->world));
	}
}
