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

namespace pocketmine\block;

use pocketmine\block\utils\SculkSpreader;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;

/**
 * Sculk catalysts absorbing the experience of mobs dying nearby and spreading sculk. Uses the in-memory world of the
 * redstone tests.
 */
final class SculkCatalystTest extends RedstoneTestCase{

	private function catalyst() : SculkCatalyst{
		$catalyst = $this->get(0, 1, 0);
		self::assertInstanceOf(SculkCatalyst::class, $catalyst);
		return $catalyst;
	}

	private static bool $registered = false;

	private function zombieAt(float $x, float $z, int $xp) : Zombie{
		if(!self::$registered){
			self::$registered = true;
			EntityFactory::getInstance()->register(XpZombie::class, fn(World $world, CompoundTag $nbt) : XpZombie => new XpZombie(EntityDataHelper::parseLocation($nbt, $world), $nbt), ["pocketmine:test_xp_zombie"]);
		}
		$zombie = new XpZombie(Location::fromObject(new Vector3($x, 1, $z), $this->world));
		$zombie->xp = $xp;
		return $zombie;
	}

	private function countSculk(int $radius) : int{
		$count = 0;
		for($x = -$radius; $x <= $radius; ++$x){
			for($z = -$radius; $z <= $radius; ++$z){
				for($y = 0; $y <= 2; ++$y){
					$block = $this->get($x, $y, $z);
					if($block instanceof Sculk || $block instanceof SculkVein){
						++$count;
					}
				}
			}
		}
		return $count;
	}

	private function countOrbs() : int{
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ExperienceOrb){
				++$count;
			}
		}
		return $count;
	}

	public function testDeathNearCatalystIsAbsorbed() : void{
		$this->floor(-12, -12, 12, 12);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_CATALYST());

		$zombie = $this->zombieAt(3.5, 0.5, 20);
		$zombie->kill();
		self::assertSame(0, $this->countOrbs(), "the experience is absorbed");
		self::assertTrue($this->catalyst()->isBlooming());
		self::assertSame(20, $this->world->getVibrations()->getCatalystSpreader(0, 1, 0)->getCursors()[0]->charge);

		$this->runTicks(SculkCatalyst::BLOOM_TICKS + 1);
		self::assertFalse($this->catalyst()->isBlooming(), "the bloom only lasts 8 ticks");

		$this->runTicks(300);
		self::assertGreaterThan(0, $this->countSculk(12), "sculk spread from the death position");
		self::assertSame(0, $this->world->getVibrations()->getStatistics()["spreading"], "the charge ran out");
	}

	public function testDeathFarFromCatalystDropsExperience() : void{
		$this->floor(-12, -12, 12, 12);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_CATALYST());
		$this->zombieAt(9.5, 0.5, 5)->kill();
		self::assertGreaterThan(0, $this->countOrbs());
		self::assertFalse($this->catalyst()->isBlooming());
	}

	public function testNoExperienceNoBloom() : void{
		$this->floor(-12, -12, 12, 12);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_CATALYST());
		$this->zombieAt(2.5, 0.5, 0)->kill();
		self::assertFalse($this->catalyst()->isBlooming());
	}

	public function testClosestCatalystWins() : void{
		$this->floor(-12, -12, 12, 12);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_CATALYST());
		$this->set(6, 1, 0, VanillaBlocks::SCULK_CATALYST());
		$this->zombieAt(5.5, 0.5, 5)->kill();
		$far = $this->catalyst();
		$near = $this->get(6, 1, 0);
		self::assertInstanceOf(SculkCatalyst::class, $near);
		self::assertTrue($near->isBlooming());
		self::assertFalse($far->isBlooming());
	}

	public function testLargeExperienceSpawnsGrowths() : void{
		$this->floor(-12, -12, 12, 12);
		$this->set(0, 1, 0, VanillaBlocks::SCULK_CATALYST());
		$catalyst = $this->catalyst();
		for($i = 0; $i < 4; ++$i){
			$catalyst->absorbExperience(2, 1, 0, 400);
		}
		$this->runTicks(600);
		$growths = 0;
		for($x = -12; $x <= 12; ++$x){
			for($z = -12; $z <= 12; ++$z){
				$block = $this->get($x, 1, $z);
				if($block instanceof SculkSensor){
					++$growths;
				}elseif($block instanceof SculkShrieker){
					self::assertFalse($block->canSummon(), "shriekers grown by a catalyst can't summon wardens");
					++$growths;
				}
			}
		}
		self::assertGreaterThan(0, $growths, "sensors or shriekers grow on the sculk");
		self::assertGreaterThan(20, $this->countSculk(12));
	}

	public function testReplaceableBlocks() : void{
		self::assertTrue(SculkSpreader::isSculkReplaceable(VanillaBlocks::STONE()));
		self::assertTrue(SculkSpreader::isSculkReplaceable(VanillaBlocks::DEEPSLATE()));
		self::assertTrue(SculkSpreader::isSculkReplaceable(VanillaBlocks::DIRT()));
		self::assertFalse(SculkSpreader::isSculkReplaceable(VanillaBlocks::OAK_PLANKS()));
		self::assertFalse(SculkSpreader::isSculkReplaceable(VanillaBlocks::OBSIDIAN()));
	}

	public function testCursorsAreCapped() : void{
		$spreader = new SculkSpreader();
		$spreader->addCursors(0, 0, 0, 2500);
		self::assertCount(3, $spreader->getCursors());
		self::assertSame(1000, $spreader->getCursors()[0]->charge);
		self::assertSame(500, $spreader->getCursors()[2]->charge);
		for($i = 0; $i < 40; ++$i){
			$spreader->addCursors(0, 0, 0, 5);
		}
		self::assertCount(SculkSpreader::MAX_CURSORS, $spreader->getCursors());
	}
}
