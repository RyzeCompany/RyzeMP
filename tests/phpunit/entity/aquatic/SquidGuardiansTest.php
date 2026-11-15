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

namespace pocketmine\entity\aquatic;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\hostile\goal\FreeAvoidEntityGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\ElderGuardian;
use pocketmine\entity\monster\Guardian;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Squid;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\mobs\MobDespawner;
use pocketmine\world\mobs\NaturalMobSpawner;
use pocketmine\world\mobs\spawn\BundledSpawnRules;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\Position;
use pocketmine\world\WorldManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;
use function array_filter;
use function count;
use function max;
use function min;
use function mt_srand;

final class SquidGuardiansTest extends MobTestCase{
	private Vector3 $playerPos;

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		MobDespawner::clearCache();
		//damage events look their damager up through the world manager
		$server = $this->world->getServer();
		if($server instanceof MockObject){
			$worldManager = $this->createMock(WorldManager::class);
			$worldManager->method("findEntity")->willReturnCallback(fn(int $id) => $this->world->getEntity($id));
			$server->method("getWorldManager")->willReturn($worldManager);
		}
		$this->playerPos = new Vector3(0.5, 1, 3.5);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function pool(int $minX = -8, int $maxX = 8, int $height = 5) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = -4; $z <= 4; ++$z){
				for($y = 1; $y <= $height; ++$y){
					$this->set($x, $y, $z, VanillaBlocks::WATER());
				}
			}
		}
	}

	private function player(Item $held) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$uuid = Uuid::uuid4();
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn($uuid);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getId")->willReturn(999999);
		$player->method("getPosition")->willReturnCallback(fn() => Position::fromObject($this->playerPos, $this->world));
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function rules() : SpawnRules{
		$rules = BundledSpawnRules::loadFile(Path::join(\pocketmine\RESOURCE_PATH, BundledSpawnRules::FILE));
		self::assertNotNull($rules);
		return $rules;
	}

	private function hitByPlayer(Squid $squid, Player $player) : void{
		(new \ReflectionProperty(Squid::class, "lastPlayerHit"))->setValue($squid, $player);
		(new \ReflectionProperty(Squid::class, "lastPlayerHitTick"))->setValue($squid, $this->world->getServer()->getTick());
	}

	public function testSquidPersistenceSurvivesSaving() : void{
		$this->pool();
		$egg = new Squid($this->loc(0.5, 2, 0.5));
		self::assertTrue($egg->isPersistent(), "squids made by eggs, commands and plugins are persistent");
		self::assertFalse($egg->canDespawn());

		$natural = new Squid($this->loc(2.5, 2, 0.5));
		$natural->setPersistent(false);
		$nbt = $natural->saveNBT();
		$natural->close();
		$reloaded = new Squid($this->loc(2.5, 2, 0.5), $nbt);
		self::assertFalse($reloaded->isPersistent(), "a natural squid saved with its chunk may still despawn");
		self::assertTrue($reloaded->canDespawn());

		$legacy = $nbt->setByte(Mob::TAG_PERSISTENT, 0);
		$legacy->removeTag(Mob::TAG_PERSISTENT);
		$old = new Squid($this->loc(4.5, 2, 0.5), $legacy);
		self::assertFalse($old->isPersistent(), "squids saved before the tag existed were the world's");

		//no player in the world: the despawnable squids go on their next check, the persistent one stays
		$this->runTicks(MobDespawner::CHECK_INTERVAL + 1);
		self::assertTrue($reloaded->isFlaggedForDespawn() || $reloaded->isClosed());
		self::assertTrue($old->isFlaggedForDespawn() || $old->isClosed());
		self::assertFalse($egg->isFlaggedForDespawn() || $egg->isClosed());
		$egg->close();
	}

	public function testReloadedSquidIsCounted() : void{
		$this->pool();
		$spawner = new NaturalMobSpawner($this->world);
		$spawner->setRules($this->rules());
		$squid = new Squid($this->loc(0.5, 2, 0.5));
		$squid->setPersistent(false);
		$reloaded = new Squid($this->loc(0.5, 2, 0.5), $squid->saveNBT());
		$squid->close();
		$count = $spawner->countType(0, 0, "minecraft:squid", true) + $spawner->countType(0, 0, "minecraft:squid", false);
		self::assertSame(1, $count, "a squid loaded from disk counts towards the caps");
		$reloaded->setPersistent(true);
		$spawner = new NaturalMobSpawner($this->world);
		$spawner->setRules($this->rules());
		self::assertSame(0, $spawner->countType(0, 0, "minecraft:squid", true) + $spawner->countType(0, 0, "minecraft:squid", false), "persistent squids don't count");
		$reloaded->close();
	}

	public function testSquidCanBeLed() : void{
		$this->pool();
		$player = $this->player(VanillaItems::LEAD());
		$squid = new Squid($this->loc(0.5, 2, 0.5));
		$squid->setPersistent(false);
		self::assertTrue($squid->onInteract($player, new Vector3(0, 0, 0)));
		self::assertSame($player, $squid->getLeashHolder());
		self::assertTrue($player->getInventory()->getItemInHand()->isNull(), "lead used");
		self::assertFalse($squid->canDespawn(), "led squids don't despawn");
		self::assertNotSame("", $squid->saveNBT()->getString(Mob::TAG_LEASH_PLAYER, ""), "the holder is saved");

		$this->playerPos = new Vector3(0.5, 2, 30.5);
		$this->runTicks(2);
		self::assertFalse($squid->isLeashed(), "too far: the lead breaks");
		$leads = array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn() && $e->getItem()->getTypeId() === ItemTypeIds::LEAD);
		self::assertCount(1, $leads);
		$squid->close();
	}

	public function testSquidExperience() : void{
		$squid = new Squid($this->loc(0.5, 1, 0.5));
		self::assertSame(0, $squid->getXpDropAmount(), "no experience without a player kill");
		$this->hitByPlayer($squid, $this->player(VanillaItems::AIR()));
		for($i = 0; $i < 30; ++$i){
			$xp = $squid->getXpDropAmount();
			self::assertGreaterThanOrEqual(1, $xp);
			self::assertLessThanOrEqual(3, $xp);
		}
		$baby = new Squid($this->loc(2.5, 1, 0.5));
		$baby->makeSpawnBaby();
		$this->hitByPlayer($baby, $this->player(VanillaItems::AIR()));
		self::assertSame(0, $baby->getXpDropAmount(), "babies give no experience");
		$glow = new GlowSquid($this->loc(4.5, 1, 0.5));
		$this->hitByPlayer($glow, $this->player(VanillaItems::AIR()));
		self::assertGreaterThanOrEqual(1, $glow->getXpDropAmount());
	}

	public function testSquidBabies() : void{
		$babies = 0;
		for($i = 0; $i < 600; ++$i){
			$squid = new Squid($this->loc(0.5, 1, 0.5));
			if(BabySpawning::rollSpawnBaby($squid)){
				++$babies;
				self::assertTrue($squid->isBaby());
				self::assertSame(Squid::BABY_SCALE, $squid->getScale());
				self::assertEqualsWithDelta(0.5, $squid->getSize()->getWidth(), 0.0001);
			}
			$squid->close();
		}
		self::assertEqualsWithDelta(30, $babies, 15, "5% of spawned squids are babies");

		$baby = new GlowSquid($this->loc(0.5, 1, 0.5));
		$baby->makeSpawnBaby();
		$nbt = $baby->saveNBT();
		$baby->close();
		$nbt->setInt("Age", -2);
		$loaded = new GlowSquid($this->loc(0.5, 1, 0.5), $nbt);
		self::assertTrue($loaded->isBaby(), "the age is saved");
		self::assertSame(2, $loaded->getGrowTicks());
		$this->runTicks(3);
		self::assertFalse($loaded->isBaby(), "grows up");
		self::assertSame(1.0, $loaded->getScale());
		self::assertEqualsWithDelta(0.8, $loaded->getSize()->getWidth(), 0.0001);
		$loaded->close();
	}

	public function testInkLooting() : void{
		$squid = new Squid($this->loc(0.5, 1, 0.5));
		$max = 0;
		$min = 99;
		for($i = 0; $i < 200; ++$i){
			$count = $squid->getDrops()[0]->getCount();
			$max = max($max, $count);
			$min = min($min, $count);
		}
		self::assertSame([1, 3], [$min, $max], "1-3 ink sacs");

		$sword = VanillaItems::DIAMOND_SWORD();
		$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::LOOTING(), 3));
		$this->hitByPlayer($squid, $this->player($sword));
		$max = 0;
		for($i = 0; $i < 300; ++$i){
			$drop = $squid->getDrops()[0];
			self::assertSame(ItemTypeIds::INK_SAC, $drop->getTypeId());
			$max = max($max, $drop->getCount());
		}
		self::assertSame(6, $max, "Looting III adds up to 3");
		self::assertSame(VanillaItems::GLOW_INK_SAC()->getTypeId(), (new GlowSquid($this->loc(2.5, 1, 0.5)))->getDrops()[0]->getTypeId());
	}

	public function testSquidSwimsAndFlees() : void{
		$this->pool(-12, 12, 6);
		$squid = new Squid($this->loc(0.5, 3, 0.5));
		$start = $squid->getPosition();
		$this->runTicks(100);
		self::assertFalse($squid->isClosed());
		self::assertGreaterThan(0.5, $squid->getPosition()->distance($start), "it swims around");
		self::assertTrue($squid->isUnderwater(), "it stays in the water");

		$cow = new Cow($this->loc($squid->getPosition()->x + 2, 1, $squid->getPosition()->z));
		$squid->attack(new EntityDamageByEntityEvent($cow, $squid, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		$this->runTicks(1);
		$direction = $squid->swimDirection;
		self::assertNotNull($direction);
		self::assertLessThan(0, $direction->x, "it swims away from the attacker");
		$cow->close();
	}

	public function testOutOfWaterSquidSticksToTheGround() : void{
		$squid = new Squid($this->loc(0.5, 1, 0.5));
		$squid->setMotion(new Vector3(0.3, 0, 0.3));
		$this->runTicks(5);
		self::assertEqualsWithDelta(0.5, $squid->getPosition()->x, 0.35);
		self::assertNull($squid->swimDirection);
		$squid->close();
	}

	public function testGuardianTooCloseStopsCharging() : void{
		$this->pool();
		$cow = new Cow($this->loc(2.5, 1, 0.5));
		$cow->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$cow->setMaxHealth(1000);
		$cow->setHealth(1000);
		$guardian = new Guardian($this->loc(0.5, 1, 0.5));
		$guardian->setAttackTarget($cow);
		$this->runTicks(30);
		self::assertTrue($guardian->isTooClose(), "within 3 blocks: guardian_passive");
		self::assertNull($guardian->getBeamTarget(), "no laser while too close");
		self::assertSame(1000.0, $cow->getHealth());

		//far again: back to guardian_aggressive within 1-3 seconds, then the laser
		$cow->teleport(new Vector3(-7.5, 1, 0.5));
		$guardian->teleport(new Vector3(0.5, 1, 0.5));
		$beam = null;
		for($i = 1; $i <= 200 && $beam === null; ++$i){
			$this->runTicks(1);
			if($guardian->getBeamTarget() !== null){
				$beam = $i;
			}
		}
		self::assertFalse($guardian->isTooClose());
		self::assertNotNull($beam, "charges again once the target is far enough");

		$elder = new ElderGuardian($this->loc(-4.5, 1, 0.5));
		$elder->setAttackTarget($cow);
		$this->runTicks(30);
		self::assertFalse($elder->isTooClose(), "elder guardians have no target_nearby_sensor");
		self::assertSame($cow, $elder->getBeamTarget());
	}

	public function testAvoidGoalSwimsAway() : void{
		$this->pool();
		$guardian = new Guardian($this->loc(0.5, 2.5, 0.5));
		$cow = new Cow($this->loc(2.5, 2.5, 0.5));
		$goal = new FreeAvoidEntityGoal($guardian, $guardian->getSwimControl(), fn() => $cow, Guardian::AVOID_PLAYER_DISTANCE, Guardian::SWIM_SPEED);
		self::assertTrue($goal->canStart());
		$goal->start();
		self::assertTrue($guardian->getSwimControl()->hasWanted());
		self::assertLessThan(0.5, $guardian->getSwimControl()->getWantedX(), "away from the danger");
		$cow->teleport(new Vector3(20.5, 1, 0.5));
		$goal->stop();
		self::assertFalse($goal->canStart(), "farther than 8 blocks");
	}

	public function testGuardiansTargetAxolotls() : void{
		$this->pool();
		$axolotl = new Axolotl($this->loc(-5.5, 1, 0.5));
		$axolotl->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$guardian = new ElderGuardian($this->loc(3.5, 1, 0.5));
		$this->runTicks(40);
		self::assertSame($axolotl, $guardian->getAttackTarget());
	}

	public function testSwimSpeeds() : void{
		self::assertSame(0.12, (new \ReflectionMethod(Guardian::class, "getSwimSpeed"))->invoke(new Guardian($this->loc(0.5, 1, 0.5))));
		self::assertSame(0.15, (new \ReflectionMethod(ElderGuardian::class, "getSwimSpeed"))->invoke(new ElderGuardian($this->loc(4.5, 1, 0.5))));
		$drowned = new Drowned($this->loc(-4.5, 1, 0.5));
		self::assertSame(0.06, $drowned->getSwimSpeed());
		$drowned->setBaby(true);
		self::assertSame(0.08, $drowned->getSwimSpeed());
	}

	public function testGuardianFishLootComesFromTheFishingTable() : void{
		$guardian = new Guardian($this->loc(0.5, 1, 0.5));
		(new \ReflectionProperty(Mob::class, "lastHurtByPlayer"))->setValue($guardian, $this->player(VanillaItems::AIR()));
		(new \ReflectionProperty(Mob::class, "lastHurtByPlayerTick"))->setValue($guardian, $this->world->getServer()->getTick());
		$addFish = new \ReflectionMethod(Guardian::class, "addPlayerKillFish");
		$kinds = [];
		for($i = 0; $i < 6000; ++$i){
			/** @var Item[] $drops */
			$drops = [VanillaItems::PRISMARINE_SHARD()];
			$addFish->invokeArgs($guardian, [&$drops]);
			foreach($drops as $drop){
				$kinds[$drop->getTypeId()] = true;
			}
		}
		unset($kinds[ItemTypeIds::PRISMARINE_SHARD]);
		self::assertArrayHasKey(ItemTypeIds::RAW_SALMON, $kinds, "not always cod");
		self::assertGreaterThan(1, count($kinds));
	}

	public function testNbtWithoutBabyStaysAdult() : void{
		$squid = new Squid($this->loc(0.5, 1, 0.5), new CompoundTag());
		self::assertFalse($squid->isBaby());
		self::assertSame(0, $squid->getGrowTicks());
	}
}
