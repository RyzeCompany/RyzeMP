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

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\animal\SulfurCubeArchetype;
use pocketmine\entity\animal\SulfurCubeKnockback;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\WorldManager;
use Ramsey\Uuid\Uuid;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function max;
use function mt_srand;
use function sin;
use function sqrt;

final class SulfurCubeTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261019);
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
	}

	private function cube(float $x = 0.5, float $z = 0.5, bool $baby = false) : SulfurCube{
		$nbt = CompoundTag::create()->setInt(Animal::TAG_AGE, $baby ? Animal::BABY_AGE : 0);
		$cube = new SulfurCube(new Location($x, 1, $z, $this->world, 0, 0), $nbt);
		$cube->spawnToAll();
		return $cube;
	}

	/**
	 * @return ItemEntity[]
	 */
	private function itemEntities() : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	public function testBlocksGiveTheirArchetype() : void{
		foreach([
			[VanillaBlocks::OAK_PLANKS()->asItem(), SulfurCubeArchetype::BOUNCY],
			[VanillaBlocks::DIRT()->asItem(), SulfurCubeArchetype::REGULAR],
			[VanillaBlocks::STONE()->asItem(), SulfurCubeArchetype::SLOW_BOUNCY],
			[VanillaBlocks::IRON()->asItem(), SulfurCubeArchetype::SLOW_FLAT],
			[VanillaBlocks::SPONGE()->asItem(), SulfurCubeArchetype::FAST_FLAT],
			[VanillaBlocks::WOOL()->setColor(DyeColor::RED)->asItem(), SulfurCubeArchetype::LIGHT],
			[VanillaBlocks::PACKED_ICE()->asItem(), SulfurCubeArchetype::FAST_SLIDING],
			[VanillaBlocks::MYCELIUM()->asItem(), SulfurCubeArchetype::SLOW_SLIDING],
			[VanillaBlocks::HONEYCOMB()->asItem(), SulfurCubeArchetype::STICKY],
			[VanillaBlocks::SOUL_SAND()->asItem(), SulfurCubeArchetype::HIGH_RESISTANCE],
			[VanillaBlocks::TNT()->asItem(), SulfurCubeArchetype::EXPLOSIVE],
			[VanillaBlocks::MAGMA()->asItem(), SulfurCubeArchetype::HOT],
		] as [$item, $archetype]){
			self::assertSame($archetype, SulfurCubeArchetype::fromItem($item), $item->getName());
		}
		self::assertNull(SulfurCubeArchetype::fromItem(VanillaItems::DIAMOND_SWORD()));
		self::assertNull(SulfurCubeArchetype::fromItem(VanillaItems::SLIMEBALL()));
		self::assertNull(SulfurCubeArchetype::fromItem(VanillaItems::AIR()));
	}

	public function testFrictionFormula() : void{
		self::assertEqualsWithDelta(0.88, SulfurCubeArchetype::modifyFriction(0.6, 0.3), 1.0e-9);
		self::assertEqualsWithDelta(0.2, SulfurCubeArchetype::modifyFriction(0.6, 2.0), 1.0e-9);
		self::assertSame(0.0, SulfurCubeArchetype::modifyFriction(0.6, 3.0));
		self::assertEqualsWithDelta(0.838, SulfurCubeArchetype::modifyFriction(0.91, 1.8), 1.0e-9);
	}

	public function testSmallCubesUseTheirOwnModel() : void{
		$small = $this->cube(0.5, 0.5, true);
		self::assertTrue($small->isBaby());
		self::assertSame(1.0, $small->getScale(), "the client picks the small geometry from is_baby: no extra scale");
		self::assertEqualsWithDelta(0.49, $small->getSize()->getWidth(), 0.001);
		self::assertSame(4, $small->getMaxHealth());
		$small->setAge(0);
		self::assertEqualsWithDelta(0.98, $small->getSize()->getWidth(), 0.001);
		self::assertSame(8, $small->getMaxHealth());
		self::assertSame(8.0, $small->getHealth(), "growing up gives the medium cube its full health");
	}

	public function testAbsorbAndShear() : void{
		$cube = $this->cube();
		self::assertSame(SulfurCubeArchetype::NONE, $cube->getArchetype());
		self::assertTrue($cube->absorbBlock(VanillaBlocks::OAK_PLANKS()->asItem()->setCount(5)));
		self::assertSame(SulfurCubeArchetype::BOUNCY, $cube->getArchetype());
		self::assertSame(1, $cube->getActorProperty("minecraft:sulfur_cube_archetype"));
		self::assertSame(1, $cube->getMainHandItem()->getCount(), "only one block is absorbed");
		self::assertTrue($cube->canBeLeashed());
		self::assertFalse($cube->canDespawn());

		//the same block again: nothing; another block replaces it and drops the first one
		self::assertFalse($cube->absorbBlock(VanillaBlocks::OAK_PLANKS()->asItem()));
		self::assertTrue($cube->absorbBlock(VanillaBlocks::TNT()->asItem()));
		self::assertSame(SulfurCubeArchetype::EXPLOSIVE, $cube->getArchetype());
		self::assertSame(11, $cube->getActorProperty("minecraft:sulfur_cube_archetype"));
		self::assertCount(1, $this->itemEntities());

		$dropped = $cube->ejectBlock();
		self::assertNotNull($dropped);
		self::assertSame(SulfurCubeArchetype::NONE, $cube->getArchetype());
		self::assertSame(0, $cube->getActorProperty("minecraft:sulfur_cube_archetype"));
		self::assertCount(2, $this->itemEntities());
		self::assertNull($cube->ejectBlock());

		//small cubes don't absorb blocks
		self::assertFalse($this->cube(5.5, 0.5, true)->absorbBlock(VanillaBlocks::STONE()->asItem()));
	}

	public function testMediumCubePicksUpBlocks() : void{
		$cube = $this->cube();
		$this->world->dropItem(new Vector3(2.5, 1, 0.5), VanillaBlocks::STONE()->asItem()->setCount(3), Vector3::zero(), 0);
		for($i = 0; $i < 200 && $cube->getArchetype() === SulfurCubeArchetype::NONE; ++$i){
			$this->runTicks(1);
		}
		self::assertSame(SulfurCubeArchetype::SLOW_BOUNCY, $cube->getArchetype(), "the cube hops to the block and absorbs it");
		$items = $this->itemEntities();
		self::assertCount(1, $items);
		self::assertSame(2, $items[0]->getItem()->getCount(), "one block of the stack");
	}

	public function testItemsNobodyMayPickUpAreLeftAlone() : void{
		$cube = $this->cube();
		$display = $this->world->dropItem(new Vector3(1.5, 1, 0.5), VanillaBlocks::STONE()->asItem(), Vector3::zero(), 0);
		self::assertNotNull($display);
		$display->setPickupDelay(ItemEntity::NEVER_DESPAWN);
		$this->runTicks(100);
		self::assertSame(SulfurCubeArchetype::NONE, $cube->getArchetype(), "infinite pickup delay: a plugin's display item");
		self::assertFalse($display->isFlaggedForDespawn());
	}

	public function testBlockProtectsFromMostDamage() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::STONE()->asItem());
		$this->runTicks(1);
		$cube->attack(new EntityDamageEvent($cube, EntityDamageEvent::CAUSE_FALL, 5));
		self::assertSame(8.0, $cube->getHealth());
		$cube->attack(new EntityDamageEvent($cube, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 5));
		self::assertSame(8.0, $cube->getHealth());
		$this->runTicks(20);
		$cube->attack(new EntityDamageEvent($cube, EntityDamageEvent::CAUSE_MAGIC, 3));
		self::assertSame(5.0, $cube->getHealth(), "other damage still hurts");

		$plain = $this->cube(4.5);
		$this->runTicks(1);
		$plain->attack(new EntityDamageEvent($plain, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 3));
		self::assertSame(5.0, $plain->getHealth(), "without a block it gets hurt");
	}

	public function testExplosiveCube() : void{
		$cube = $this->cube();
		self::assertFalse($cube->prime(), "only explosive cubes");
		$cube->absorbBlock(VanillaBlocks::TNT()->asItem());

		$this->world->setGameRule(GameRules::TNT_EXPLODES, false);
		self::assertFalse($cube->prime(), "tntexplodes off");
		$this->world->setGameRule(GameRules::TNT_EXPLODES, true);

		//magma primes it without hurting it
		$this->runTicks(1);
		$cube->attack(new EntityDamageByBlockEvent(VanillaBlocks::MAGMA(), $cube, EntityDamageEvent::CAUSE_MAGMA, 1));
		self::assertTrue($cube->isPrimed());
		self::assertSame(SulfurCube::FUSE_TICKS, $cube->getFuse());
		self::assertSame(8.0, $cube->getHealth());
		self::assertNull($cube->ejectBlock(), "a primed cube keeps its TNT");

		$this->runTicks(30);
		$cube->attack(new EntityDamageEvent($cube, EntityDamageEvent::CAUSE_MAGIC, 100));
		self::assertTrue($cube->isAlive(), "primed cubes are invulnerable");

		$this->runTicks(SulfurCube::FUSE_TICKS);
		self::assertTrue($cube->isClosed() || $cube->isFlaggedForDespawn(), "it exploded");
		foreach($this->itemEntities() as $item){
			self::assertNotSame(VanillaBlocks::TNT()->asItem()->getTypeId(), $item->getItem()->getTypeId(), "the TNT goes with the explosion");
		}
		foreach($this->world->getEntities() as $entity){
			self::assertFalse($entity instanceof SulfurCube && !$entity->isFlaggedForDespawn() && !$entity->isClosed(), "no small cubes after an explosion");
		}
	}

	public function testExplosionPrimesWithShortFuse() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::TNT()->asItem());
		$this->runTicks(1);
		$cube->attack(new EntityDamageEvent($cube, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 4));
		self::assertTrue($cube->isPrimed());
		self::assertGreaterThanOrEqual(SulfurCube::EXPLOSION_FUSE_MIN, $cube->getFuse());
		self::assertLessThanOrEqual(SulfurCube::EXPLOSION_FUSE_MAX, $cube->getFuse());
	}

	public function testMediumCubeSplitsIntoTwoSmallOnes() : void{
		$cube = $this->cube();
		$cube->setNameTag("Cubey");
		$cube->absorbBlock(VanillaBlocks::DIRT()->asItem());
		$this->runTicks(1);
		$cube->attack(new EntityDamageEvent($cube, EntityDamageEvent::CAUSE_SUICIDE, 1000));
		self::assertFalse($cube->isAlive());
		$children = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof SulfurCube && $entity !== $cube){
				$children[] = $entity;
			}
		}
		self::assertCount(2, $children);
		foreach($children as $child){
			self::assertTrue($child->isBaby());
			self::assertSame("Cubey", $child->getNameTag());
			self::assertSame([], $child->split(), "small cubes don't split");
		}
		self::assertCount(1, $this->itemEntities(), "the absorbed block drops");
	}

	public function testBucketKeepsTheCube() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::MAGMA()->asItem());
		$cube->setHealth(5);
		$bucket = $cube->createBucket();
		self::assertSame(VanillaItems::SULFUR_CUBE_BUCKET()->getTypeId(), $bucket->getTypeId());

		$released = VanillaItems::SULFUR_CUBE_BUCKET();
		$released->setNamedTag($bucket->getNamedTag());
		$copy = $released->releaseMob($this->world, new Vector3(6.5, 1, 0.5));
		self::assertInstanceOf(SulfurCube::class, $copy);
		self::assertSame(SulfurCubeArchetype::HOT, $copy->getArchetype());
		self::assertSame(5.0, $copy->getHealth());
		self::assertFalse($copy->isBaby());
		self::assertTrue($copy->isFromBucket());
		self::assertFalse($copy->canDespawn());

		$small = $this->cube(8.5, 0.5, true);
		$smallBucket = VanillaItems::SULFUR_CUBE_BUCKET();
		$smallBucket->setNamedTag($small->createBucket()->getNamedTag());
		$smallCopy = $smallBucket->releaseMob($this->world, new Vector3(10.5, 1, 0.5));
		self::assertInstanceOf(SulfurCube::class, $smallCopy);
		self::assertTrue($smallCopy->isBaby());
	}

	/**
	 * A mocked player next to the given position, holding the item.
	 *
	 * @phpstan-return Player&MockObject
	 */
	private function player(Item $held, Vector3 $at) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(Position::fromObject($at, $this->world));
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function countLeads() : int{
		$count = 0;
		foreach($this->itemEntities() as $item){
			if($item->getItem()->getTypeId() === ItemTypeIds::LEAD){
				$count += $item->getItem()->getCount();
			}
		}
		return $count;
	}

	public function testBucketPicksUpMediumCubesOnly() : void{
		$small = $this->cube(0.5, 0.5, true);
		$player = $this->player(VanillaItems::BUCKET(), new Vector3(1.5, 1, 0.5));
		self::assertFalse($small->onInteract($player, $small->getPosition()), "small cubes can't be bucketed (Java mobInteract())");
		self::assertFalse($small->isFlaggedForDespawn());
		self::assertSame(ItemTypeIds::BUCKET, $player->getInventory()->getItemInHand()->getTypeId());

		//a leashed medium cube: the lead drops when it goes into the bucket
		$medium = $this->cube(4.5);
		$medium->absorbBlock(VanillaBlocks::DIRT()->asItem());
		$medium->setLeashHolder($this->cube(6.5));
		self::assertTrue($medium->onInteract($player, $medium->getPosition()));
		self::assertTrue($medium->isFlaggedForDespawn());
		self::assertSame(VanillaItems::SULFUR_CUBE_BUCKET()->getTypeId(), $player->getInventory()->getItemInHand()->getTypeId());
		self::assertFalse($medium->isLeashed());
		self::assertSame(1, $this->countLeads());
	}

	public function testExplodingCubeDropsItsLead() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::TNT()->asItem());
		$cube->setLeashHolder($this->cube(2.5));
		self::assertTrue($cube->prime());
		$this->runTicks(SulfurCube::FUSE_TICKS + 1);
		self::assertTrue($cube->isClosed() || $cube->isFlaggedForDespawn(), "it exploded");
		//the lead drops right before the explosion (Java tickFuse()), which may then destroy it like any item
		self::assertFalse($cube->isLeashed());
	}

	public function testSavedCubeKeepsItsBlock() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::HONEYCOMB()->asItem());
		$copy = new SulfurCube(new Location(3.5, 1, 0.5, $this->world, 0, 0), $cube->saveNBT());
		self::assertSame(SulfurCubeArchetype::STICKY, $copy->getArchetype());
		self::assertSame(9, $copy->getActorProperty("minecraft:sulfur_cube_archetype"));
	}

	public function testBouncyCubeBounces() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::OAK_PLANKS()->asItem());
		$cube->teleport(new Vector3(0.5, 6, 0.5));
		$bounced = false;
		$wasFalling = false;
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			$motionY = $cube->getMotion()->y;
			if($motionY < -0.2){
				$wasFalling = true;
			}elseif($wasFalling && $motionY > 0.2){
				$bounced = true;
				break;
			}
		}
		self::assertTrue($bounced, "a bouncy cube bounces back up after a fall");

		$sticky = $this->cube(6.5);
		$sticky->absorbBlock(VanillaBlocks::HONEYCOMB()->asItem());
		$sticky->teleport(new Vector3(6.5, 6, 0.5));
		$maxUp = 0.0;
		$landed = false;
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			if($sticky->isOnGround()){
				$landed = true;
			}
			if($landed){
				$maxUp = max($maxUp, $sticky->getMotion()->y);
			}
		}
		self::assertTrue($landed);
		self::assertLessThanOrEqual(0.0, $maxUp, "a sticky cube doesn't bounce");
	}

	public function testCubeWithBlockHasNoAi() : void{
		$cube = $this->cube();
		$cube->absorbBlock(VanillaBlocks::STONE()->asItem());
		$start = $cube->getPosition();
		$this->runTicks(100);
		self::assertLessThan(0.01, $cube->getPosition()->distance($start), "it only moves when pushed");
		self::assertSame(0.0, $cube->getLocation()->yaw - 90 * (int) ($cube->getLocation()->yaw / 90), "axis aligned");
	}

	public function testKickGoesWhereThePlayerAims() : void{
		$archetype = SulfurCubeArchetype::REGULAR;
		$cube = new Vector3(0, 0, 3);
		$feet = new Vector3(0, 0, 0);
		$eye = new Vector3(0, 1.62, 0);
		//aiming at the centre of the cube, straight along +Z
		$look = $cube->add(0, 0.49, 0)->subtractVector($eye)->normalize();
		$kick = SulfurCubeKnockback::compute($archetype, $eye, $look, $feet, $cube, 0.98, 1.0);
		self::assertGreaterThan(0.0, $kick->z);
		self::assertEqualsWithDelta(0.0, $kick->x, 1.0e-6);
		self::assertGreaterThan(0.0, $kick->y);
		//knockback resistance -1: twice the base power
		self::assertEqualsWithDelta(0.165 * 2, $kick->z, 0.08);

		//off-centre: the ball leaves the player-to-cube line at 1.6 times the angle between the aim and the centre, on
		//the other side (2.6 times that angle from the aim)
		$aim = $cube->add(0.4, 0.49, 0)->subtractVector($eye)->normalize();
		$side = SulfurCubeKnockback::compute($archetype, $eye, $aim, $feet, $cube, 0.98, 1.0);
		self::assertLessThan(0.0, $side->x * $aim->x, "deflected away from the side that was hit");
		$aimAngle = atan2($aim->x, $aim->z);
		self::assertEqualsWithDelta(-1.6 * $aimAngle, atan2($side->x, $side->z), 1.0e-6);
		self::assertEqualsWithDelta(0.165 * 2, sqrt($side->x ** 2 + $side->z ** 2), 0.08);

		//a player one block above the cube drives it down (Java runs the angle rules on its power before the final x0.4)
		$above = new Vector3(0, 1, 1);
		$aboveEye = $above->add(0, 1.62, 0);
		$down = SulfurCubeKnockback::compute($archetype, $aboveEye, $cube->add(0, 0.49, 0)->subtractVector($aboveEye)->normalize(), $above, $cube, 0.98, 1.0);
		self::assertLessThan(0.0, $down->y);
		self::assertGreaterThan(0.0, $down->z);

		//aiming low lifts it more than aiming high
		$low = SulfurCubeKnockback::compute($archetype, $eye, $cube->add(0, 0.05, 0)->subtractVector($eye)->normalize(), $feet, $cube, 0.98, 1.0);
		$high = SulfurCubeKnockback::compute($archetype, $eye, $cube->add(0, 0.95, 0)->subtractVector($eye)->normalize(), $feet, $cube, 0.98, 1.0);
		self::assertGreaterThan($high->y, $low->y);

		//harder hits go further (square root of the damage)
		$strong = SulfurCubeKnockback::compute($archetype, $eye, $look, $feet, $cube, 0.98, 9.0);
		self::assertEqualsWithDelta($kick->z * 3, $strong->z, 1.0e-6);

		//extra knockback (Knockback I: 0.5): the same rules from where the player faces, a quarter of 0.5 as strong
		$extra = SulfurCubeKnockback::computeExtra($archetype, $eye, $look, 0.0, $feet, $cube, 0.98, 1.0, 0.5);
		self::assertEqualsWithDelta($kick->z * 0.125, $extra->z, 1.0e-6);
		self::assertEqualsWithDelta($kick->y * 0.125, $extra->y, 1.0e-6);
		self::assertEqualsWithDelta(0.0, $extra->x, 1.0e-6);
	}

	/**
	 * A mocked player at the given feet position, looking along the given yaw and pitch.
	 *
	 * @phpstan-return Player&MockObject
	 */
	private function kicker(Vector3 $feet, float $yaw, float $pitch) : Player{
		$player = $this->createMock(Player::class);
		$location = new Location($feet->x, $feet->y, $feet->z, $this->world, $yaw, $pitch);
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, 1_000_000);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $location);
		$player->method("getId")->willReturn(1_000_000);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn(Position::fromObject($feet, $this->world));
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getEyePos")->willReturn($feet->add(0, 1.62, 0));
		$y = deg2rad($yaw);
		$p = deg2rad($pitch);
		$player->method("getDirectionVector")->willReturn(new Vector3(-cos($p) * sin($y), -sin($p), cos($p) * cos($y)));
		$player->method("isAlive")->willReturn(true);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * A melee hit by a mocked player, which isn't in the world the damager is normally looked up in.
	 */
	private static function hitBy(Player $player, Entity $victim, float $damage) : EntityDamageByEntityEvent{
		return new class($player, $victim, $damage) extends EntityDamageByEntityEvent{
			public function __construct(private Player $player, Entity $victim, float $damage){
				parent::__construct($player, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage);
			}

			public function getDamager() : Entity{
				return $this->player;
			}
		};
	}

	public function testKnockbackWeaponKicksLikeABall() : void{
		$cube = $this->cube(0.5, 3.5);
		$cube->absorbBlock(VanillaBlocks::DIRT()->asItem());
		$this->runTicks(1);
		//straight along +Z, at the cube's centre
		$player = $this->kicker(new Vector3(0.5, 1, 0.5), 0.0, 22.0);
		$cube->setMotion(new Vector3(0.2, 0, 0));
		$hit = self::hitBy($player, $cube, 1);
		$cube->attack($hit);
		self::assertFalse($hit->isCancelled());
		$afterHit = $cube->getMotion();
		self::assertSame(8.0, $cube->getHealth());
		self::assertEqualsWithDelta(0.2, $afterHit->x, 1.0e-6, "the hit adds to the ball's motion");
		self::assertGreaterThan(0.2, $afterHit->z);

		//Knockback I right after the hit, like KnockbackEnchantment::onPostAttack()
		$cube->knockBack(0, 3, 0.5);
		$afterExtra = $cube->getMotion();
		self::assertEqualsWithDelta(0.2, $afterExtra->x, 1.0e-6, "not the generic knockback, which halves the motion");
		self::assertEqualsWithDelta($afterHit->z * 1.125, $afterExtra->z, 1.0e-6, "a quarter of 0.5 of the hit's power on top");
		self::assertLessThan(0.5, $afterExtra->z);

		//not without a hit in the same tick
		$this->runTicks(1);
		$cube->setMotion(new Vector3(0.2, 0, 0));
		$cube->knockBack(0, 3, 0.5);
		self::assertEqualsWithDelta(0.1, $cube->getMotion()->x, 1.0e-6, "generic knockback");
	}

	public function testHotCubeBurnsOthers() : void{
		$hot = $this->cube();
		$hot->absorbBlock(VanillaBlocks::MAGMA()->asItem());
		$victim = $this->cube(1.6);
		$explosive = $this->cube(-0.6);
		$explosive->absorbBlock(VanillaBlocks::TNT()->asItem());
		$this->runTicks(5);
		self::assertLessThan(8.0, $victim->getHealth(), "a cube without a block next to a hot one gets burnt");
		self::assertTrue($explosive->isPrimed(), "magma damage primes an explosive cube");
		self::assertSame(8.0, $explosive->getHealth(), "without hurting it (cause magma: no_but_entity_effects_apply)");
		self::assertSame(8.0, $hot->getHealth());
		self::assertGreaterThan(0, count($this->world->getEntities()));
	}

	public function testHotCubeDoesNotWearDownUnprimableTnt() : void{
		$this->world->setGameRule(GameRules::TNT_EXPLODES, false);
		$hot = $this->cube();
		$hot->absorbBlock(VanillaBlocks::MAGMA()->asItem());
		$explosive = $this->cube(-0.6);
		$explosive->absorbBlock(VanillaBlocks::TNT()->asItem());
		$this->runTicks(100);
		self::assertFalse($explosive->isPrimed());
		self::assertTrue($explosive->isAlive());
		self::assertSame(8.0, $explosive->getHealth());
		self::assertSame(SulfurCubeArchetype::EXPLOSIVE, $explosive->getArchetype());
	}
}
