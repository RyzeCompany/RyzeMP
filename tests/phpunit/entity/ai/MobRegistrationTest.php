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

final class MobRegistrationTest extends TestCase{
	public function testNewMobsHavePersistentIdsAndSpawnerFactories() : void{
		$factory=EntityFactory::getInstance();
		$spawn=SpawnerEntityRegistry::getInstance();
		foreach([
			Cow::class=>"minecraft:cow",
			Pig::class=>"minecraft:pig",
			Chicken::class=>"minecraft:chicken",
			Sheep::class=>"minecraft:sheep",
			Zombie::class=>"minecraft:zombie"
		] as $class=>$id){
			self::assertTrue($factory->isRegistered($class),$class." must save/reload in LevelDB");
			self::assertTrue($spawn->isRegistered($id),$id." must be spawnable");
		}
	}
}
