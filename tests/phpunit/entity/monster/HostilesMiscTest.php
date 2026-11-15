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

namespace pocketmine\entity\monster;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\InfestedStone;
use pocketmine\block\MobHead;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\hostile\goal\PhantomAttackGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\vehicle\Boat;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\ItemBlock;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use Ramsey\Uuid\Uuid;
use function abs;
use function array_filter;
use function array_values;
use function count;
use function mt_srand;

/**
 * Overworld hostiles (Bedrock rules): charged creepers and mob heads, creeper explosion centre, silverfish wake-up and
 * anger broadcast, endermen in vehicles and their teleport cube, mobGriefing, phantom swoops and endermites.
 */
final class HostilesMiscTest extends MobTestCase{
	private int $nextPlayerId = 7100000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
	}

	protected function tearDown() : void{
		foreach($this->players as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A mocked player standing at the location, made the only player of the world.
	 */
	private function player(Location $location, ?GameMode $gameMode = null) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getGamemode")->willReturn($gameMode ?? GameMode::SURVIVAL);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturnCallback(fn() => $location->add(0, 1.62, 0));
		$player->method("getBoundingBox")->willReturnCallback(fn() => new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		$player->method("getId")->willReturn(++$this->nextPlayerId);
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("getInventory")->willReturn(new PlayerInventory($player));
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, [$player]);
		return $player;
	}

	private static function freeze(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isFlaggedForDespawn() && !$e->isClosed()));
	}

	/**
	 * Returns the mob heads lying on the ground, by type.
	 *
	 * @return MobHeadType[]
	 * @phpstan-return list<MobHeadType>
	 */
	private function droppedHeads() : array{
		$heads = [];
		foreach($this->entitiesOf(ItemEntity::class) as $itemEntity){
			$item = $itemEntity->getItem();
			if($item instanceof ItemBlock && ($block = $item->getBlock()) instanceof MobHead){
				$heads[] = $block->getMobHeadType();
			}
		}
		return $heads;
	}

	public function testLightningBoltChargesCreeperWithoutHarm() : void{
		$this->difficulty = World::DIFFICULTY_EASY; //no fire blocks
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$creeper->spawnToAll();
		LightningBolt::strike($this->loc(0.5, 1, 0.5));
		$this->runTicks(2);
		self::assertTrue($creeper->isPowered());
		self::assertSame($creeper->getMaxHealth(), (int) $creeper->getHealth(), "the strike doesn't hurt it");
		self::assertTrue($creeper->isAlive());
	}

	public function testHeadTypes() : void{
		self::assertSame(MobHeadType::ZOMBIE, ChargedCreeperHeads::getHeadType(Zombie::getNetworkTypeId()));
		self::assertSame(MobHeadType::SKELETON, ChargedCreeperHeads::getHeadType(Skeleton::getNetworkTypeId()));
		self::assertSame(MobHeadType::WITHER_SKELETON, ChargedCreeperHeads::getHeadType(WitherSkeleton::getNetworkTypeId()));
		self::assertSame(MobHeadType::CREEPER, ChargedCreeperHeads::getHeadType(Creeper::getNetworkTypeId()));
		self::assertSame(MobHeadType::PIGLIN, ChargedCreeperHeads::getHeadType(Piglin::getNetworkTypeId()));
		self::assertNull(ChargedCreeperHeads::getHeadType(Husk::getNetworkTypeId()), "variants drop no head");
		self::assertNull(ChargedCreeperHeads::getHeadType(Stray::getNetworkTypeId()));
		self::assertNull(ChargedCreeperHeads::getHeadType(PiglinBrute::getNetworkTypeId()));
	}

	public function testChargedCreeperExplosionDropsHeads() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$zombie = new Zombie($this->loc(2.5, 1, 0.5));
		$skeleton = new Skeleton($this->loc(-1.5, 1, 0.5));
		$husk = new Husk($this->loc(0.5, 1, 2.5));
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		foreach([$zombie, $skeleton, $husk, $creeper] as $mob){
			$mob->spawnToAll();
		}
		$this->runTicks(1); //mobs die (and drop their loot) once they have been ticked
		$creeper->setPowered(true);
		$creeper->explode();
		self::assertFalse($zombie->isAlive());
		self::assertFalse($skeleton->isAlive());
		self::assertFalse($husk->isAlive());
		$heads = $this->droppedHeads();
		self::assertCount(2, $heads, "every killed head mob drops its head (Bedrock), not the husk");
		self::assertContains(MobHeadType::ZOMBIE, $heads);
		self::assertContains(MobHeadType::SKELETON, $heads);
	}

	public function testUnchargedCreeperExplosionDropsNoHead() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$zombie = new Zombie($this->loc(1.5, 1, 0.5));
		$zombie->spawnToAll();
		$creeper = new Creeper($this->loc(0.5, 1, 0.5));
		$creeper->spawnToAll();
		$this->runTicks(1);
		//reseed so the rotten flesh roll doesn't depend on how many random numbers spawning used (e.g. door breaking)
		mt_srand(20261021);
		$creeper->explode();
		self::assertFalse($zombie->isAlive());
		self::assertGreaterThan(0, count($this->entitiesOf(ItemEntity::class)), "the zombie dropped its loot");
		self::assertSame([], $this->droppedHeads());
	}

	public function testCreeperExplodesAtItsFeet() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$positions = [];
		$listener = new RegisteredListener(function(EntityExplodeEvent $ev) use (&$positions) : void{
			$positions[] = $ev->getPosition()->asVector3();
		}, EventPriority::MONITOR, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(EntityExplodeEvent::class)->register($listener);
		try{
			$creeper = new Creeper($this->loc(0.5, 1, 0.5));
			$creeper->spawnToAll();
			$creeper->explode();
		}finally{
			HandlerListManager::global()->getListFor(EntityExplodeEvent::class)->unregister($listener);
		}
		self::assertCount(1, $positions);
		self::assertEquals(new Vector3(0.5, 1, 0.5), $positions[0]);
	}

	public function testSilverfishWakeUpScanOrder() : void{
		self::assertSame([0, 1, -1, 2, -2], Silverfish::getWakeUpScanOffsets(2));
		self::assertCount(21, Silverfish::getWakeUpScanOffsets(10));
	}

	public function testSilverfishWakeUpWithoutMobGriefing() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$this->set(1, 1, 0, VanillaBlocks::INFESTED_STONE());
		$silverfish = new Silverfish($this->loc(0.5, 1, 0.5));
		$silverfish->spawnToAll();
		$silverfish->wakeUpFriends();
		self::assertSame(VanillaBlocks::STONE()->getTypeId(), $this->get(1, 1, 0)->getTypeId(), "back to plain stone");
		self::assertCount(1, $this->entitiesOf(Silverfish::class), "no silverfish comes out");

		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		$this->set(1, 1, 0, VanillaBlocks::INFESTED_STONE());
		$silverfish->wakeUpFriends();
		self::assertNotInstanceOf(InfestedStone::class, $this->get(1, 1, 0));
		self::assertCount(2, $this->entitiesOf(Silverfish::class), "the hidden silverfish comes out");
	}

	public function testSilverfishDoesNotHideWithoutMobGriefing() : void{
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				for($y = 1; $y <= 2; ++$y){
					if($x !== 0 || $z !== 0){
						$this->set($x, $y, $z, VanillaBlocks::STONE());
					}
				}
			}
		}
		$this->set(0, 2, 0, VanillaBlocks::STONE());
		$silverfish = new Silverfish($this->loc(0.5, 1, 0.5));
		$silverfish->spawnToAll();
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		for($i = 0; $i < 50; ++$i){
			self::assertFalse($silverfish->tryHideInBlock());
		}
		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		self::assertTrue($silverfish->tryHideInBlock(), "every side is stone");
	}

	public function testSilverfishAngerBroadcast() : void{
		$hurt = new Silverfish($this->loc(0.5, 1, 0.5));
		$friend = new Silverfish($this->loc(12.5, 1, 0.5));
		$far = new Silverfish($this->loc(-23.5, 1, 0.5));
		foreach([$hurt, $friend, $far] as $silverfish){
			self::freeze($silverfish);
			$silverfish->spawnToAll();
		}
		$cow = new Cow($this->loc(2.5, 1, 0.5));
		self::freeze($cow);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$cow->spawnToAll();
		$hurt->attack(new EntityDamageByEntityEvent($cow, $hurt, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(10);
		self::assertSame($cow, $hurt->getAttackTarget());
		self::assertSame($cow, $friend->getAttackTarget(), "a silverfish 12 blocks away joins");
		self::assertNull($far->getAttackTarget(), "24 blocks is beyond broadcast_range 20");
	}

	public function testRidingEndermanNeverTeleports() : void{
		$boat = new Boat($this->loc(0.5, 1, 0.5));
		$boat->spawnToAll();
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		$enderman->spawnToAll();
		self::assertTrue($enderman->mount($boat));
		self::assertFalse($enderman->teleportRandomly(64));
		self::assertFalse($enderman->teleportTo(5.5, 1, 5.5));
		$enderman->attack(new EntityDamageEvent($enderman, EntityDamageEvent::CAUSE_MAGIC, 1));
		//daylight teleports and random teleports
		$this->runTicks(1200);
		self::assertSame($boat, $enderman->getVehicle(), "still in the boat");
		self::assertTrue($enderman->isRiding());

		$enderman->dismount(true);
		self::assertTrue($enderman->teleportRandomly(64), "teleports again once out of the boat");
	}

	public function testEndermanRandomTeleportCube() : void{
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		$enderman->spawnToAll();
		$origin = new Vector3(0.5, 1, 0.5);
		$moved = 0;
		for($i = 0; $i < 60; ++$i){
			$enderman->teleport($origin);
			if($enderman->teleportRandomly(1)){
				++$moved;
				$pos = $enderman->getPosition();
				self::assertLessThanOrEqual(16.0, abs($pos->x - $origin->x));
				self::assertLessThanOrEqual(16.0, abs($pos->z - $origin->z));
			}
		}
		self::assertGreaterThan(0, $moved);
	}

	public function testEndermanTakesNoBlockWithoutMobGriefing() : void{
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				if($x !== 0 || $z !== 0){
					$this->set($x, 1, $z, VanillaBlocks::DIRT());
				}
			}
		}
		$enderman = new Enderman($this->loc(0.5, 1, 0.5));
		$enderman->spawnToAll();
		$tick = new \ReflectionMethod(Enderman::class, "tickBlockCarrying");
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		for($i = 0; $i < 2000; ++$i){
			$tick->invoke($enderman);
		}
		self::assertNull($enderman->getCarriedBlock());
		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		for($i = 0; $i < 2000 && $enderman->getCarriedBlock() === null; ++$i){
			$tick->invoke($enderman);
		}
		self::assertNotNull($enderman->getCarriedBlock(), "takes a block with mobGriefing on");

		$enderman->setCarriedBlock(VanillaBlocks::DIRT());
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		for($i = 0; $i < 20000; ++$i){
			$tick->invoke($enderman);
		}
		self::assertNotNull($enderman->getCarriedBlock(), "places nothing with mobGriefing off");
	}

	public function testPhantomSwoopDelay() : void{
		$phantom = new Phantom($this->loc(0.5, 12, 0.5));
		$phantom->spawnToAll();
		$cow = new Cow($this->loc(3.5, 1, 0.5));
		self::freeze($cow);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$cow->spawnToAll();
		$phantom->setAttackTarget($cow);
		$goal = (new \ReflectionProperty(Phantom::class, "attackGoal"))->getValue($phantom);
		self::assertInstanceOf(PhantomAttackGoal::class, $goal);
		$next = new \ReflectionProperty(PhantomAttackGoal::class, "nextSweepTick");
		for($i = 0; $i < 200 && !$phantom->isSwooping(); ++$i){
			$this->runTicks(1);
		}
		self::assertTrue($phantom->isSwooping());
		$delay = $next->getValue($goal);
		self::assertGreaterThanOrEqual(200, $delay, "at least 10 seconds until the next swoop");
		self::assertLessThanOrEqual(400, $delay, "at most 20 seconds");
	}

	public function testPhantomTargetsPlayerUnderARoof() : void{
		for($x = -3; $x <= 3; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$this->set($x, 4, $z, VanillaBlocks::STONE());
			}
		}
		$player = $this->player($this->loc(0.5, 1, 0.5));
		$phantom = new Phantom($this->loc(0.5, 20, 0.5));
		$phantom->spawnToAll();
		for($i = 0; $i < 60 && $phantom->getAttackTarget() === null; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($player, $phantom->getAttackTarget(), "no must_see: the roof doesn't hide the player");
	}

	public function testEndermiteHasNoLifetimeAndHuntsEndermen() : void{
		//a creative player nearby: no despawning, and not a target
		$this->player($this->loc(0.5, 1, 6.5), GameMode::CREATIVE);
		$endermite = new Endermite($this->loc(0.5, 1, 0.5));
		$endermite->setPersistent(false);
		$endermite->spawnToAll();
		$enderman = new Enderman($this->loc(4.5, 1, 0.5));
		self::freeze($enderman);
		$enderman->setMaxHealth(10000);
		$enderman->setHealth(10000);
		$enderman->spawnToAll();
		$endermite->setMaxHealth(10000);
		$endermite->setHealth(10000);
		for($i = 0; $i < 100 && $endermite->getAttackTarget() !== $enderman; ++$i){
			$this->runTicks(1);
		}
		self::assertSame($enderman, $endermite->getAttackTarget(), "the endermite targets the enderman");
		for($i = 0; $i < 2500 && !$endermite->isFlaggedForDespawn() && !$endermite->isClosed(); ++$i){
			$this->runTicks(1);
		}
		self::assertFalse($endermite->isFlaggedForDespawn(), "no 2-minute lifetime in Bedrock");
		self::assertFalse($endermite->isClosed());
	}
}
