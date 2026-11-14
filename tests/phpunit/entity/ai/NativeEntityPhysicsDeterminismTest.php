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

use pocketmine\block\utils\SlabType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\CaveSpider;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Random;
use pocketmine\world\World;
use function bin2hex;
use function count;
use function floor;
use function getenv;
use function implode;
use function ksort;
use function mt_srand;
use function pack;

/**
 * The C++ entity physics of chunkutils2 (World::getNativeEntityPhysics()) must not change anything: the same mobs in the
 * same world, ticked with them and without them (the PHP code), end up in exactly the same state (positions, motions,
 * rotations, health, targets... compared bit for bit) at every checkpoint. The world has the blocks the physics deal
 * with: slabs, stairs, fences, walls, panes, water, lava, cobwebs, cactus, soul sand, magma, pits and a low ceiling,
 * across chunk borders on both sides of zero.
 */
final class NativeEntityPhysicsDeterminismTest extends MobTestCase{

	private const CHECKPOINTS = 4;
	private const TICKS_PER_CHECKPOINT = 75;

	protected function tearDown() : void{
		World::setNativeEntityPhysicsEnabled(true);
		parent::tearDown();
	}

	private function terrain(Random $random) : void{
		$w = $this->world;
		$pick = fn(int $min, int $max) : int => $min + $random->nextBoundedInt($max - $min + 1);
		$walls = [
			VanillaBlocks::STONE(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::COBBLESTONE_WALL(), VanillaBlocks::GLASS_PANE(),
			VanillaBlocks::OAK_STAIRS(), VanillaBlocks::OAK_SLAB()->setSlabType(SlabType::TOP), VanillaBlocks::IRON_BARS()
		];
		for($i = 0; $i < 90; ++$i){
			$x = $pick(-22, 22);
			$z = $pick(-22, 22);
			$block = $walls[$random->nextBoundedInt(count($walls))];
			for($y = 1, $h = $pick(1, 3); $y <= $h; ++$y){
				$w->setBlockAt($x, $y, $z, $block, false);
			}
		}
		for($i = 0; $i < 50; ++$i){
			$w->setBlockAt($pick(-22, 22), 1, $pick(-22, 22), VanillaBlocks::SMOOTH_STONE_SLAB(), false);
		}
		for($i = 0; $i < 12; ++$i){
			$w->setBlockAt($pick(-22, 22), 1, $pick(-22, 22), VanillaBlocks::CARPET(), false);
		}
		for($x = 5; $x <= 12; ++$x){
			for($z = 5; $z <= 12; ++$z){
				$w->setBlockAt($x, 0, $z, VanillaBlocks::WATER(), false);
				$w->setBlockAt($x, -1, $z, VanillaBlocks::STONE(), false);
			}
		}
		for($x = -6; $x <= -3; ++$x){
			$w->setBlockAt($x, 0, -10, VanillaBlocks::LAVA(), false);
			$w->setBlockAt($x, -1, -10, VanillaBlocks::STONE(), false);
		}
		for($i = 0; $i < 6; ++$i){
			$x = $pick(-20, 20);
			$z = $pick(-20, 20);
			$w->setBlockAt($x, 0, $z, VanillaBlocks::SAND(), false);
			$w->setBlockAt($x, 1, $z, VanillaBlocks::CACTUS(), false);
		}
		for($i = 0; $i < 6; ++$i){
			$w->setBlockAt($pick(-20, 20), 0, $pick(-20, 20), VanillaBlocks::MAGMA(), false);
			$w->setBlockAt($pick(-20, 20), 0, $pick(-20, 20), VanillaBlocks::SOUL_SAND(), false);
			$w->setBlockAt($pick(-20, 20), 1, $pick(-20, 20), VanillaBlocks::COBWEB(), false);
		}
		for($i = 0; $i < 10; ++$i){
			$w->setBlockAt($pick(-20, 20), 0, $pick(-20, 20), VanillaBlocks::AIR(), false);
		}
		for($x = 14; $x <= 20; ++$x){
			for($z = -20; $z <= -14; ++$z){
				$w->setBlockAt($x, 3, $z, VanillaBlocks::STONE(), false);
			}
		}
	}

	private static function f(float $v) : string{
		return bin2hex(pack("e", $v));
	}

	private function dump() : string{
		$out = [];
		$entities = $this->world->getEntities();
		ksort($entities);
		$index = 0;
		foreach($entities as $e){
			$l = $e->getLocation();
			$m = $e->getMotion();
			$bb = $e->getBoundingBox();
			$line = ($index++) . " " . (new \ReflectionClass($e))->getShortName() . " " . self::f($l->x) . " " . self::f($l->y) . " " . self::f($l->z) .
				" " . self::f($l->yaw) . " " . self::f($l->pitch) . " " . self::f($m->x) . " " . self::f($m->y) . " " . self::f($m->z) .
				" bb " . self::f($bb->minX) . " " . self::f($bb->minY) . " " . self::f($bb->minZ) . " " . self::f($bb->maxX) . " " . self::f($bb->maxY) . " " . self::f($bb->maxZ) .
				" g=" . ($e->isOnGround() ? 1 : 0) . " c=" . ($e->isCollided ? 1 : 0) . ($e->isCollidedHorizontally ? 1 : 0) . " fd=" . self::f($e->getFallDistance());
			if($e instanceof Living){
				$line .= " h=" . self::f($e->getHealth()) . " air=" . $e->getAirSupplyTicks();
			}
			if($e instanceof Mob){
				$line .= " hy=" . self::f($e->getHeadYaw()) . " t=" . ($e->getAttackTarget() !== null ? "y" : "-") . " nav=" . ($e->getNavigation()->isDone() ? "d" : "p");
			}
			$out[] = $line;
		}
		return implode("\n", $out);
	}

	/**
	 * @return string[] the state of every entity at each checkpoint
	 */
	private function simulate(bool $native) : array{
		World::setNativeEntityPhysicsEnabled($native);
		//the mobs draw from mt_rand(), and some spread their work over ticks by entity ID: both start the same in each run
		mt_srand(4242);
		(new \ReflectionProperty(Entity::class, "entityCount"))->setValue(null, 1_000_000);
		$random = new Random(7771);
		$this->terrain($random);
		$loc = fn() : Location => new Location($random->nextBoundedInt(41) - 20 + 0.5, 4, $random->nextBoundedInt(41) - 20 + 0.5, $this->world, $random->nextBoundedInt(360), 0);
		$targets = [];
		for($i = 0; $i < 6; ++$i){
			$cow = new Cow($loc());
			$cow->setMaxHealth(1000000);
			$cow->setHealth(1000000);
			$cow->spawnToAll();
			$targets[] = $cow;
		}
		$factories = [
			fn(Location $l) => new Cow($l), fn(Location $l) => new Sheep($l), fn(Location $l) => new Pig($l),
			fn(Location $l) => new Chicken($l), fn(Location $l) => new Zombie($l), fn(Location $l) => new Skeleton($l),
			fn(Location $l) => new Spider($l), fn(Location $l) => new CaveSpider($l), fn(Location $l) => new IronGolem($l),
			fn(Location $l) => new Wolf($l), fn(Location $l) => new VillagerV2($l), fn(Location $l) => new Horse($l),
			fn(Location $l) => new Ravager($l), fn(Location $l) => new Creeper($l), fn(Location $l) => new Witch($l),
			fn(Location $l) => new Husk($l), fn(Location $l) => new Drowned($l), fn(Location $l) => new Turtle($l),
			fn(Location $l) => new Slime($l, null, 2),
		];
		$mobs = [];
		for($i = 0; $i < 57; ++$i){
			$mob = $factories[$i % count($factories)]($loc());
			$mob->spawnToAll();
			if($i % 3 === 0 && !$mob instanceof Turtle){
				$mob->setAttackTarget($targets[$random->nextBoundedInt(count($targets))]);
			}
			$mobs[] = $mob;
		}

		$dumps = [];
		for($c = 1; $c <= self::CHECKPOINTS; ++$c){
			$this->runTicks(self::TICKS_PER_CHECKPOINT);
			if($c === 2){
				//world changes next to the mobs (the collision box cache is invalidated), teleports and knockbacks
				foreach($mobs as $k => $m){
					if($m->isClosed()){
						continue;
					}
					$p = $m->getPosition();
					if($k % 5 === 0){
						$this->world->setBlockAt((int) floor($p->x) + 1, (int) floor($p->y), (int) floor($p->z), VanillaBlocks::OAK_FENCE());
					}elseif($k % 7 === 0){
						$m->teleport(new Vector3($random->nextBoundedInt(37) - 18 + 0.5, 5, $random->nextBoundedInt(37) - 18 + 0.5));
					}elseif($k % 11 === 0){
						$m->knockBack(0.4, 0.2, 0.6);
					}
				}
			}
			$dumps[] = $this->dump();
		}
		return $dumps;
	}

	public function testSameSimulationWithAndWithoutNativePhysics() : void{
		if(World::getNativeEntityPhysics() === null){
			self::markTestSkipped("chunkutils2 without the native entity physics (0.3.12+)");
		}
		TimingsHandler::setEnabled(false);
		$this->difficulty = World::DIFFICULTY_HARD;
		$php = $this->simulate(false);

		//the same scenario in a new world
		parent::tearDown();
		$this->setUp();
		$this->difficulty = World::DIFFICULTY_HARD;
		//NATIVE_DETERMINISM_SELF_CHECK=1 runs the PHP code both times: the harness itself must then agree
		$native = $this->simulate(getenv("NATIVE_DETERMINISM_SELF_CHECK") !== "1");

		for($c = 0; $c < self::CHECKPOINTS; ++$c){
			self::assertSame($php[$c], $native[$c], "checkpoint " . (($c + 1) * self::TICKS_PER_CHECKPOINT));
		}
	}
}
