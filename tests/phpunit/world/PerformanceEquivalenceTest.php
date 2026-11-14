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

use pocketmine\block\Block;
use pocketmine\block\BubbleColumn;
use pocketmine\block\Fire;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\vehicle\Boat;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\Random;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_map;
use function count;
use function floor;
use function intdiv;
use function max;
use function min;
use function mt_rand;
use function mt_srand;

/**
 * The fast paths added for performance must give the same answers as the code they replaced.
 */
final class PerformanceEquivalenceTest extends RedstoneTestCase{

	protected function getChunkRadius() : int{
		return 3; //x/z -48..63 loaded
	}

	/**
	 * Fills x -40..40, z -6..6, y -2..6 with a random mix of blocks.
	 *
	 * @param Block[] $palette
	 */
	private function scatter(array $palette, int $seed) : void{
		$random = new Random($seed);
		for($x = -40; $x <= 40; $x += 1){
			for($z = -6; $z <= 6; ++$z){
				for($y = -2; $y <= 6; ++$y){
					$this->world->setBlockAt($x, $y, $z, $palette[$random->nextBoundedInt(count($palette))], false);
				}
			}
		}
	}

	public function testFireSpreadGridMatchesBlocks() : void{
		$this->scatter([
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(),
			VanillaBlocks::OAK_PLANKS(), VanillaBlocks::WOOL(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::BOOKSHELF(),
			VanillaBlocks::COAL(), VanillaBlocks::TNT(), VanillaBlocks::HAY_BALE(), VanillaBlocks::NETHERRACK(),
			VanillaBlocks::CRIMSON_PLANKS(), VanillaBlocks::OAK_FENCE(),
		], 1);
		$readGrid = new \ReflectionMethod(Fire::class, "readSpreadGrid");
		$airStateId = VanillaBlocks::AIR()->getStateId();

		$checked = 0;
		//fires up to x=65 (the chunk at x=64 is not loaded) and around the world height limits
		foreach([[-41, 65, -1, 7], [-3, 3, -66, -62], [-3, 3, 316, 321]] as [$fromX, $toX, $fromY, $toY]){
			for($fireX = $fromX; $fireX <= $toX; $fireX += 3){
				for($fireZ = -6; $fireZ <= 6; $fireZ += 4){
					for($fireY = $fromY; $fireY <= $toY; $fireY += 2){
						$grid = $readGrid->invoke(null, $this->world, $fireX, $fireY, $fireZ);
						self::assertIsArray($grid);
						for($dx = -2; $dx <= 2; ++$dx){
							for($dz = -2; $dz <= 2; ++$dz){
								for($dy = -2; $dy <= 5; ++$dy){
									[$x, $y, $z] = [$fireX + $dx, $fireY + $dy, $fireZ + $dz];
									//what the block-by-block code saw: air in a loaded chunk is a target, otherwise the flame
									//encouragement of World::getBlockAt() (air outside the world and in unloaded chunks)
									if(!$this->world->isInWorld($x, $y, $z) || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
										$expected = 0;
									}else{
										$block = $this->world->getBlockAt($x, $y, $z);
										$expected = $block->getStateId() === $airStateId ? -1 : $block->getFlameEncouragement();
									}
									self::assertSame($expected, $grid[($dx + 2) * 40 + ($dz + 2) * 8 + ($dy + 2)], "$x $y $z");
									++$checked;
								}
							}
						}
					}
				}
			}
		}
		self::assertGreaterThan(10000, $checked);
	}

	/**
	 * @param Block[] $blocks
	 * @return string[]
	 */
	private static function describe(array $blocks) : array{
		return array_map(fn(Block $b) => $b->getPosition()->asVector3() . " " . $b->getStateId(), $blocks);
	}

	public function testBlocksAroundMatchesIntersectedBlocks() : void{
		$this->scatter([
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(),
			VanillaBlocks::COBWEB(), VanillaBlocks::WATER(), VanillaBlocks::LAVA(), VanillaBlocks::SWEET_BERRY_BUSH(),
			VanillaBlocks::POWDER_SNOW(), VanillaBlocks::MAGMA(), VanillaBlocks::CACTUS(), VanillaBlocks::LADDER(),
		], 2);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$random = new Random(3);
		$blocksAround = new \ReflectionMethod(Entity::class, "getBlocksAroundWithEntityInsideActions");
		$blocksIntersected = new \ReflectionMethod(Entity::class, "getBlocksIntersected");
		$cache = new \ReflectionProperty(Entity::class, "blocksAround");
		/** @return Block[] */
		$fast = function() use ($blocksAround, $zombie) : array{
			$result = $blocksAround->invoke($zombie);
			self::assertIsArray($result);
			$blocks = [];
			foreach($result as $block){
				self::assertInstanceOf(Block::class, $block);
				$blocks[] = $block;
			}
			return $blocks;
		};
		/** @return Block[] */
		$reference = function() use ($blocksIntersected, $zombie) : array{
			$generator = $blocksIntersected->invoke($zombie, 0.001);
			self::assertInstanceOf(\Generator::class, $generator);
			$result = [];
			foreach($generator as $block){
				self::assertInstanceOf(Block::class, $block);
				if($block->hasEntityCollision()){
					$result[] = $block;
				}
			}
			return $result;
		};
		$reset = fn() => $cache->setValue($zombie, null);

		$found = 0;
		for($i = 0; $i < 3000; ++$i){
			//includes positions straddling chunk borders and outside the loaded chunks (x up to 66)
			$zombie->teleport(new Vector3($random->nextFloat() * 108 - 42, $random->nextFloat() * 8 - 2.5, $random->nextFloat() * 14 - 7));
			$reset();
			$expected = self::describe($reference());
			$reset();
			$actual = self::describe($fast());
			self::assertSame($expected, $actual, "at " . $zombie->getPosition()->asVector3());
			$found += count($actual);
		}
		self::assertGreaterThan(100, $found, "the test should meet blocks with entity collision");
		$zombie->close();
	}

	public function testCountsEntitiesThatMayBeStoodOn() : void{
		self::assertFalse($this->world->hasEntitiesThatMayBeStoodOn());
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		self::assertFalse($this->world->hasEntitiesThatMayBeStoodOn(), "zombies can't be stood on");

		$boat = new Boat(Location::fromObject(new Vector3(3.5, 1, 0.5), $this->world));
		self::assertTrue($this->world->hasEntitiesThatMayBeStoodOn());
		$boat2 = new Boat(Location::fromObject(new Vector3(6.5, 1, 0.5), $this->world));
		$boat->close();
		self::assertTrue($this->world->hasEntitiesThatMayBeStoodOn(), "one boat left");
		$boat2->close();
		self::assertFalse($this->world->hasEntitiesThatMayBeStoodOn());
		$zombie->close();
	}

	/**
	 * Fire::spreadFire() before the grid (block by block), kept as the reference.
	 */
	private function referenceSpreadFire(Fire $fire) : void{
		$world = $this->world;
		$isNearRain = new \ReflectionMethod(Fire::class, "isNearRain");
		//since Bedrock 1.26.60 a spread goes through spreadBlock(), which also gives the new fire its connections
		$spreadBlock = new \ReflectionMethod(Fire::class, "spreadBlock");
		$difficultyChanceIncrease = $world->getDifficulty() * 7;
		$ageDivisor = $fire->getAge() + 30;
		$position = $fire->getPosition();
		for($y = -1; $y <= 4; ++$y){
			$targetY = $y + (int) $position->y;
			if($targetY < World::Y_MIN || $targetY >= World::Y_MAX){
				continue;
			}
			$randomBound = 100 + ($y > 1 ? ($y - 1) * 100 : 0);
			for($z = -1; $z <= 1; ++$z){
				$targetZ = $z + (int) $position->z;
				for($x = -1; $x <= 1; ++$x){
					if($x === 0 && $y === 0 && $z === 0){
						continue;
					}
					$targetX = $x + (int) $position->x;
					if(!$world->isInWorld($targetX, $targetY, $targetZ) || !$world->isChunkLoaded($targetX >> 4, $targetZ >> 4)){
						continue;
					}
					$block = $world->getBlockAt($targetX, $targetY, $targetZ);
					if($block->getTypeId() !== VanillaBlocks::AIR()->getTypeId()){
						continue;
					}
					$encouragement = 0;
					foreach($block->getPosition()->sides() as $side){
						if($world->isInWorld((int) $side->x, (int) $side->y, (int) $side->z)){
							$encouragement = max($encouragement, $world->getBlockAt((int) $side->x, (int) $side->y, (int) $side->z)->getFlameEncouragement());
						}
					}
					if($encouragement <= 0){
						continue;
					}
					$maxChance = intdiv($encouragement + 40 + $difficultyChanceIncrease, $ageDivisor);
					if($maxChance > 0 && mt_rand(0, $randomBound - 1) <= $maxChance && $isNearRain->invoke(null, $world, $targetX, $targetY, $targetZ) === false){
						$new = clone $fire;
						$new->setAge(min(Fire::MAX_AGE, $fire->getAge() + (mt_rand(0, 4) >> 2)));
						$spreadBlock->invoke($fire, $block, $new);
					}
				}
			}
		}
	}

	/**
	 * @return int[]
	 */
	private function snapshot() : array{
		$states = [];
		for($x = -12; $x <= 12; ++$x){
			for($z = -6; $z <= 6; ++$z){
				for($y = -2; $y <= 8; ++$y){
					$states[] = $this->world->getBlockAt($x, $y, $z)->getStateId();
				}
			}
		}
		return $states;
	}

	public function testFireSpreadMatchesReference() : void{
		$spreadFire = new \ReflectionMethod(Fire::class, "spreadFire");
		$palette = [
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(),
			VanillaBlocks::OAK_PLANKS(), VanillaBlocks::WOOL(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::HAY_BALE(),
			VanillaBlocks::NETHERRACK(), VanillaBlocks::TNT(),
		];
		$spreads = 0;
		$expected = [];
		$actual = [];
		for($round = 0; $round < 60; ++$round){
			foreach([true, false] as $useReference){
				$this->scatter($palette, 100 + $round);
				$fire = VanillaBlocks::FIRE()->setAge($round % (Fire::MAX_AGE + 1));
				$this->world->setBlockAt(0, 2, 0, $fire, false);
				$fire = $this->world->getBlockAt(0, 2, 0);
				self::assertInstanceOf(Fire::class, $fire);
				mt_srand(5000 + $round);
				if($useReference){
					$this->referenceSpreadFire($fire);
					$expected = $this->snapshot();
				}else{
					$before = $this->snapshot();
					$spreadFire->invoke($fire, false); //the test world is not humid
					$actual = $this->snapshot();
					if($actual !== $before){
						++$spreads;
					}
				}
			}
			self::assertSame($expected, $actual, "round $round");
		}
		self::assertGreaterThan(5, $spreads, "the fire should spread in some rounds");
	}

	public function testEyeChecksMatchBlocks() : void{
		$this->scatter([
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(), VanillaBlocks::GLASS(),
			VanillaBlocks::WATER(), VanillaBlocks::WATER()->setDecay(3), VanillaBlocks::WATER()->setDecay(6),
			VanillaBlocks::WATER()->setFalling(true), VanillaBlocks::LAVA(), VanillaBlocks::SEAGRASS(), VanillaBlocks::KELP(),
			VanillaBlocks::SEA_PICKLE()->setUnderwater(true), VanillaBlocks::SEA_PICKLE()->setUnderwater(false),
			VanillaBlocks::OAK_SLAB(), VanillaBlocks::BUBBLE_COLUMN(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::SAND(),
		], 4);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$random = new Random(5);
		$eyeInBubbleColumn = new \ReflectionMethod($zombie, "isEyeInBubbleColumn");
		$checked = 0;
		for($i = 0; $i < 4000; ++$i){
			$zombie->teleport(new Vector3($random->nextFloat() * 108 - 42, $random->nextFloat() * 9 - 3.5, $random->nextFloat() * 14 - 7));
			$loc = $zombie->getLocation();
			$eyeY = $loc->y + $zombie->getEyeHeight();
			$block = $this->world->getBlockAt((int) floor($loc->x), (int) floor($eyeY), (int) floor($loc->z));
			//the checks as they were, on the Block
			if($block instanceof Water){
				$underwater = $eyeY < ((int) floor($eyeY) + 1) - ($block->getFluidHeightPercent() - 0.1111111);
			}else{
				$underwater = $block instanceof Waterlogged && $block->isWaterlogged();
			}
			$insideSolid = $block->isSolid() && !$block->isTransparent() && $block->collidesWithBB($zombie->getBoundingBox());
			self::assertSame($underwater, $zombie->isUnderwater(), "underwater at " . $loc->asVector3());
			self::assertSame($insideSolid, $zombie->isInsideOfSolid(), "inside solid at " . $loc->asVector3());
			self::assertSame($block instanceof BubbleColumn, $eyeInBubbleColumn->invoke($zombie), "bubble column at " . $loc->asVector3());
			$checked += $underwater || $insideSolid ? 1 : 0;
		}
		self::assertGreaterThan(200, $checked, "the test should meet water and solid blocks");
		$zombie->close();
	}

	private static bool $overriddenCowRegistered = false;

	/**
	 * Living::entityBaseTick() reads the block at the eyes once for the suffocation check and the air supply when the
	 * class keeps those checks: the same air, breathing, health and update result as a class that overrides one of them
	 * (which runs isInsideOfSolid() and doAirSupplyTick() as they are).
	 */
	public function testBaseTickEyeChecksMatchOverriddenChecks() : void{
		$this->scatter([
			VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::STONE(), VanillaBlocks::GLASS(),
			VanillaBlocks::WATER(), VanillaBlocks::WATER()->setDecay(3), VanillaBlocks::WATER()->setFalling(true),
			VanillaBlocks::SEAGRASS(), VanillaBlocks::KELP(), VanillaBlocks::OAK_STAIRS(), VanillaBlocks::OAK_SLAB(),
			VanillaBlocks::BUBBLE_COLUMN(), VanillaBlocks::SAND(), VanillaBlocks::OAK_LEAVES(),
		], 6);
		//water in the liquid layer of half of the stairs
		$stairs = VanillaBlocks::OAK_STAIRS()->getStateId();
		$water = VanillaBlocks::WATER()->getStillForm()->getStateId();
		for($x = -40; $x <= 40; $x += 2){
			for($z = -6; $z <= 6; ++$z){
				for($y = -2; $y <= 6; ++$y){
					if($this->world->getBlockAt($x, $y, $z)->getStateId() === $stairs){
						$this->world->getOrLoadChunkAtPosition(new Vector3($x, $y, $z))?->getSubChunk($y >> 4)->setLiquidLayerStateId($x & 0xf, $y & 0xf, $z & 0xf, $water);
					}
				}
			}
		}
		if(!self::$overriddenCowRegistered){
			self::$overriddenCowRegistered = true;
			EntityFactory::getInstance()->register(OverriddenBreathingCow::class, fn(World $world, CompoundTag $nbt) : OverriddenBreathingCow => new OverriddenBreathingCow(EntityDataHelper::parseLocation($nbt, $world), $nbt), ["pocketmine:test_overridden_breathing_cow"]);
		}
		$plain = new Cow(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$overriding = new OverriddenBreathingCow(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$baseTick = new \ReflectionMethod(Living::class, "entityBaseTick");
		$random = new Random(7);
		$results = [];
		for($i = 0; $i < 3000; ++$i){
			$position = new Vector3($random->nextFloat() * 82 - 41, $random->nextFloat() * 9 - 3.5, $random->nextFloat() * 14 - 7);
			$air = $random->nextBoundedInt(330) - 20;
			$breathing = $random->nextBoolean();
			$waterBreathing = $random->nextBoundedInt(8) === 0;
			$outcome = [];
			foreach([$plain, $overriding] as $cow){
				$cow->teleport($position);
				$cow->setHealth($cow->getMaxHealth());
				$cow->setAirSupplyTicks($air);
				$cow->setBreathing($breathing);
				$cow->getEffects()->clear();
				if($waterBreathing){
					$cow->getEffects()->add(new EffectInstance(VanillaEffects::WATER_BREATHING(), 100));
				}
				$hasUpdate = $baseTick->invoke($cow, 1);
				$outcome[] = [$hasUpdate, $cow->getAirSupplyTicks(), $cow->isBreathing(), $cow->getHealth()];
			}
			self::assertSame($outcome[1], $outcome[0], "at $position");
			$results[$outcome[0][1] < $air ? "losing air" : ($outcome[0][3] < $plain->getMaxHealth() ? "hurt" : "other")] = true;
		}
		self::assertCount(3, $results, "the test should meet entities losing air, suffocating and breathing");
		$plain->close();
		$overriding->close();
	}
}
