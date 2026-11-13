<?php
declare(strict_types=1);

namespace pocketmine\world\mobs;

use PHPUnit\Framework\TestCase;
use pocketmine\world\mobs\spawn\NaturalSpawnPolicy as Policy;
use pocketmine\world\World;

final class NaturalSpawnPolicyTest extends TestCase{
	public function testPlayerDistanceBoundaries() : void{
		self::assertFalse(Policy::withinPlayerRange(23.99**2));
		self::assertTrue(Policy::withinPlayerRange(24**2));
		self::assertTrue(Policy::withinPlayerRange(48**2));
		self::assertFalse(Policy::withinPlayerRange(48.01**2));
	}
	public function testCapsRejectFullPopulations() : void{
		self::assertTrue(Policy::underCap(0,1));
		self::assertFalse(Policy::underCap(1,1));
		self::assertFalse(Policy::underCap(0,0));
		self::assertFalse(Policy::underCap(0,-2));
	}
	public function testPeacefulAndLightConstraints() : void{
		self::assertFalse(Policy::canSpawnZombie(World::DIFFICULTY_PEACEFUL,0));
		self::assertFalse(Policy::canSpawnZombie(World::DIFFICULTY_NORMAL,8));
		self::assertTrue(Policy::canSpawnZombie(World::DIFFICULTY_NORMAL,7));
		self::assertFalse(Policy::canSpawnAnimal(8));
		self::assertTrue(Policy::canSpawnAnimal(9));
	}
}
