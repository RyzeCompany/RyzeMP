<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\SpawnerEntityRegistry;
use pocketmine\entity\Zombie;
use pocketmine\entity\Skeleton;
use pocketmine\entity\Creeper;
use pocketmine\entity\Spider;
use pocketmine\entity\CaveSpider;
use pocketmine\entity\Husk;
use pocketmine\entity\Endermite;
use pocketmine\world\World;

final class MobRegistrationTest extends TestCase{
	public function testNewMobsHavePersistentIdsAndSpawnerFactories() : void{
		$factory=EntityFactory::getInstance();
		$spawn=SpawnerEntityRegistry::getInstance();
		foreach([
			Cow::class=>"minecraft:cow",
			Pig::class=>"minecraft:pig",
			Chicken::class=>"minecraft:chicken",
			Sheep::class=>"minecraft:sheep",
			Zombie::class=>"minecraft:zombie",
			Skeleton::class=>"minecraft:skeleton",
			Creeper::class=>"minecraft:creeper",
			Spider::class=>"minecraft:spider",
			CaveSpider::class=>"minecraft:cave_spider",
			Husk::class=>"minecraft:husk",
			Endermite::class=>"minecraft:endermite"
		] as $class=>$id){
			self::assertTrue($factory->isRegistered($class),$class." must save/reload in LevelDB");
			self::assertTrue($spawn->isRegistered($id),$id." must be spawnable");
		}
	}

	public function testCaveSpiderPoisonScalesWithDifficulty() : void{
		self::assertSame(0, CaveSpider::getPoisonTicks(World::DIFFICULTY_PEACEFUL));
		self::assertSame(0, CaveSpider::getPoisonTicks(World::DIFFICULTY_EASY));
		self::assertSame(140, CaveSpider::getPoisonTicks(World::DIFFICULTY_NORMAL));
		self::assertSame(300, CaveSpider::getPoisonTicks(World::DIFFICULTY_HARD));
	}
}
