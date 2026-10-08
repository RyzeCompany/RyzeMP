<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\block\tile\MonsterSpawner as MonsterSpawnerTile;
use pocketmine\entity\SpawnerEntityRegistry;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;

final class MonsterSpawnerTest extends TestCase{
	public function testLegacyEntityIdentifierAndSettings() : void{
		$tile = (new \ReflectionClass(MonsterSpawnerTile::class))->newInstanceWithoutConstructor();
		$nbt = CompoundTag::create()
			->setString("EntityIdentifier", "minecraft:enderman")
			->setTag("Delay", new IntTag(100))
			->setTag("SpawnCount", new IntTag(2))
			->setTag("MinSpawnDelay", new IntTag(50))
			->setTag("MaxSpawnDelay", new IntTag(140));
		$tile->readSaveData($nbt);
		self::assertSame("minecraft:enderman", $tile->getEntityTypeId());
		self::assertSame(100, $tile->getSpawnDelay());
		self::assertSame(4, $tile->getSpawnRange());
	}

	public function testSpawnerBoundsBadValues() : void{
		$tile = (new \ReflectionClass(MonsterSpawnerTile::class))->newInstanceWithoutConstructor();
		$nbt = CompoundTag::create()
			->setString("EntityIdentifier", "minecraft:enderman")
			->setShort("Delay", -300)
			->setShort("SpawnRange", 100)
			->setShort("MinSpawnDelay", 0)
			->setShort("MaxSpawnDelay", -1);
		$tile->readSaveData($nbt);
		self::assertSame(0, $tile->getSpawnDelay());
		self::assertSame(8, $tile->getSpawnRange());
	}

	public function testSpawnerCreatorRegistration() : void{
		$registry = SpawnerEntityRegistry::getInstance();
		$id = "mykoss:test_spawner_creator";
		$factory = static function(\pocketmine\entity\Location $location, ?CompoundTag $nbt = null) : \pocketmine\entity\Entity{
			throw new \LogicException("Only registry lookup should run in this test");
		};
		$registry->register($id, $factory);
		self::assertTrue($registry->isRegistered($id));
		$registry->unregister($id);
		self::assertFalse($registry->isRegistered($id));
	}
}
