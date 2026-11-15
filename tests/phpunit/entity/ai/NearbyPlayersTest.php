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

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\sensing\NearbyPlayers;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Breeze;
use pocketmine\entity\monster\Endermite;
use pocketmine\entity\monster\Slime;
use pocketmine\inventory\ArmorInventory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\redstone\NullThreadSafeLogger;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_values;
use function count;
use function mt_rand;
use function mt_srand;

/**
 * The player-only target search (NearbyPlayers) looks at exactly the players of the box search, in its order.
 */
final class NearbyPlayersTest extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $fakePlayers = [];

	protected function tearDown() : void{
		foreach($this->fakePlayers as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->fakePlayers = [];
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, standing in the test world (see WardenTest).
	 */
	private function fakePlayer(float $x, float $y, float $z) : Player{
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($x, $y, $z, $this->world, 0, 0));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		(new \ReflectionProperty(Human::class, "uuid"))->setValue($player, Uuid::uuid4());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, GameMode::SURVIVAL);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$this->world->addEntity($player);
		$this->fakePlayers[] = $player;
		return $player;
	}

	/**
	 * @phpstan-template TMob of Mob
	 * @phpstan-param TMob $mob
	 * @phpstan-return TMob
	 */
	private function frozen(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		return $mob;
	}

	public function testSamePlayersInSameOrderAsBoxSearch() : void{
		mt_srand(1234);
		//players spread over several chunks, some sharing a chunk, between cows
		for($i = 0; $i < 14; ++$i){
			$this->fakePlayer(mt_rand(-400, 400) / 10, mt_rand(10, 30) / 10, mt_rand(-400, 400) / 10);
		}
		for($i = 0; $i < 4; ++$i){
			$this->fakePlayer(3.3 + $i * 0.7, 1, 5.1 + $i * 1.3); //one chunk
		}
		for($i = 0; $i < 20; ++$i){
			$this->frozen(new Cow(new Location(mt_rand(-200, 200) / 10, 1, mt_rand(-200, 200) / 10, $this->world, 0, 0)))->spawnToAll();
		}
		$exclude = $this->fakePlayers[3];
		$several = 0;
		for($i = 0; $i < 2000; ++$i){
			$x = mt_rand(-450, 450) / 10;
			$z = mt_rand(-450, 450) / 10;
			$y = mt_rand(-20, 40) / 10;
			$h = mt_rand(5, 300) / 10;
			$v = mt_rand(5, 300) / 10;
			$bb = new AxisAlignedBB($x - $h, $y - $v, $z - $h, $x + $h, $y + $v, $z + $h);
			$ex = $i % 3 === 0 ? $exclude : null;
			$expected = array_values(array_filter($this->world->getNearbyEntities($bb, $ex), fn(Entity $e) => $e instanceof Player));
			$actual = NearbyPlayers::inBox($this->world, $bb, $ex);
			self::assertSame($expected, $actual, "box $i");
			if(count($expected) > 1){
				++$several;
			}
		}
		self::assertGreaterThan(100, $several, "boxes holding several players are covered");
	}

	public function testUnknownWhenTheIndexHasThePlayerElsewhere() : void{
		$player = $this->fakePlayer(0.5, 1, 0.5);
		//moved without telling the world (the entity index still has it in chunk 0, 0)
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location(40.5, 1, 0.5, $this->world, 0, 0));
		$player->boundingBox = new AxisAlignedBB(40.2, 1, 0.2, 40.8, 2.8, 0.8);
		self::assertNull(NearbyPlayers::inBox($this->world, new AxisAlignedBB(30, 0, -10, 50, 10, 10)), "the box search decides");
		self::assertSame([], NearbyPlayers::inBox($this->world, new AxisAlignedBB(-10, 0, -10, 10, 10, 10)), "its box isn't in this one");
	}

	public function testPlayerOnlySearchesTargetTheNearestPlayer() : void{
		//slimes and breezes look for players and golems, endermites for players and endermen: with none of the others in
		//the world only the players in the search box are looked at
		$slime = $this->frozen(new Slime(new Location(0.5, 1, 0.5, $this->world, 0, 0), null, 4));
		$breeze = $this->frozen(new Breeze(new Location(0.5, 1, -8.5, $this->world, 0, 0)));
		$endermite = $this->frozen(new Endermite(new Location(-8.5, 1, 0.5, $this->world, 0, 0)));
		foreach([$slime, $breeze, $endermite] as $mob){
			$mob->spawnToAll();
		}
		$far = $this->fakePlayer(10.5, 1, 10.5);
		$near = $this->fakePlayer(-3.5, 1, -3.5);
		for($i = 0; $i < 200 && ($slime->getAttackTarget() === null || $breeze->getAttackTarget() === null || $endermite->getAttackTarget() === null); ++$i){
			$this->runTicks(1);
		}
		self::assertSame($near, $slime->getAttackTarget());
		self::assertSame($near, $breeze->getAttackTarget());
		self::assertSame($near, $endermite->getAttackTarget());
		self::assertNotSame($far, $slime->getAttackTarget());
	}
}
