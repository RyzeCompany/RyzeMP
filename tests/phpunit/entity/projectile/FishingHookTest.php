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

namespace pocketmine\entity\projectile;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\FishingHookEntityEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\player\PlayerFishEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;
use pocketmine\ServerConfigGroup;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldManager;

final class FishingHookTest extends TestCase{
	private World $world;
	private Server $server;
	private Player $owner;
	private Player $target;
	private PlayerInventory $inventory;
	private PluginManager $plugins;
	private Plugin $plugin;
	private int $tick = 0;
	private bool $pvp = true;
	private bool $connected = true;
	private bool $wall = false;
	/** @var Entity[] */
	private array $entities = [];
	/** @var Block[] */
	private array $blocks = [];

	protected function setUp() : void{
		HandlerListManager::global()->unregisterAll();
		$this->server = $this->createMock(Server::class);
		$this->server->method("getTick")->willReturnCallback(fn() => $this->tick);
		$yml = $this->createMock(Config::class);
		$properties = $this->createMock(Config::class);
		$properties->method("exists")->willReturn(true);
		$properties->method("get")->willReturnCallback(fn() => $this->pvp);
		$this->server->method("getConfigGroup")->willReturn(new ServerConfigGroup($yml, $properties));
		$manager = $this->createMock(WorldManager::class);
		$manager->method("findEntity")->willReturnCallback(fn(int $id) => $this->entities[$id] ?? null);
		$this->server->method("getWorldManager")->willReturn($manager);
		$this->world = $this->createMock(World::class);
		$this->world->method("isLoaded")->willReturn(true);
		$this->world->method("isInLoadedTerrain")->willReturn(true);
		$this->world->method("getServer")->willReturn($this->server);
		$this->world->method("addEntity")->willReturnCallback(function(Entity $entity) : void{
			$this->entities[$entity->getId()] = $entity;
		});
		$getBlock = function(int $x, int $y, int $z) : Block{
			$block = clone ($this->blocks["$x:$y:$z"] ?? ($this->wall && $x === 2 ? VanillaBlocks::STONE() : VanillaBlocks::AIR()));
			$block->position($this->world, $x, $y, $z);
			return $block;
		};
		$this->world->method("getBlockAt")->willReturnCallback($getBlock);
		$this->world->method("getBlock")->willReturnCallback(fn(Vector3 $pos) => $getBlock($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()));
		$this->owner = $this->makePlayer(100001, 0);
		$this->target = $this->makePlayer(100002, 5);
		$this->inventory = new PlayerInventory($this->owner);
		$this->inventory->setItemInHand(VanillaItems::FISHING_ROD());
		$this->owner->method("getInventory")->willReturn($this->inventory);
		$this->plugin = $this->createStub(Plugin::class);
		$this->plugin->method("isEnabled")->willReturn(true);
		$this->plugins = new PluginManager($this->server, null);
	}

	private function makePlayer(int $id, float $x) : Player{
		$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods([
			"getId", "getWorld", "getServer", "getPosition", "getEyePos", "getLocation", "getSize",
			"isConnected", "isAlive", "isCreative", "isSpectator", "getInventory", "setMotion",
			"attack", "onDispose", "destroyCycles"
		])->getMock();
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->server);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createStub(\Logger::class));
		$player->method("getId")->willReturn($id);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getServer")->willReturn($this->server);
		$player->method("getPosition")->willReturn(new Position($x, 64, 0, $this->world));
		$player->method("getEyePos")->willReturn(new Vector3($x, 65.62, 0));
		$player->method("getLocation")->willReturn(new Location($x, 64, 0, $this->world, 0, 0));
		$player->method("getSize")->willReturn(new EntitySizeInfo(1.8, 0.6));
		$player->method("isConnected")->willReturnCallback(fn() => $this->connected);
		$player->method("isAlive")->willReturn(true);
		$player->method("isCreative")->willReturn(false);
		$player->method("isSpectator")->willReturn(false);
		$player->expects(self::never())->method("attack");
		$this->entities[$id] = $player;
		return $player;
	}

	protected function tearDown() : void{
		foreach($this->entities as $entity){
			$entity->close();
		}
		$this->entities = [];
		HandlerListManager::global()->unregisterAll();
	}

	private function hook(?FishingSettings $settings = null) : FishingHook{
		$hook = new FishingHook(new Location(4, 65, 0, $this->world, 0, 0), $this->owner, $settings ?? new FishingSettings());
		$this->owner->setFishingHook($hook);
		return $hook;
	}

	private function hit(FishingHook $hook) : void{
		(new \ReflectionMethod(FishingHook::class, "onHitEntity"))->invoke($hook, $this->target, new RayTraceResult(new AxisAlignedBB(0, 0, 0, 1, 1, 1), Facing::WEST, new Vector3(4.7, 65, 0)));
	}

	private function listen(string $eventClass, \Closure $handler) : void{
		$this->plugins->registerEvent($eventClass, $handler, EventPriority::NORMAL, $this->plugin);
	}

	public function testLaunchReelAndCooldownArePerPlayer() : void{
		$rod = VanillaItems::FISHING_ROD();
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $rod->onClickAir($this->owner, new Vector3(1, 0, 0), $returned));
		$hook = $this->owner->getFishingHook();
		self::assertNotNull($hook);
		self::assertFalse($hook->canSaveWithChunk());
		self::assertFalse($hook->canCollideWith($this->owner));
		self::assertEquals(new Vector3(1.1, 0, 0), $hook->getMotion());
		self::assertSame(ItemUseResult::FAIL, $rod->onClickAir($this->owner, new Vector3(1, 0, 0), $returned));
		self::assertSame($hook, $this->owner->getFishingHook());
		$this->tick = 10;
		self::assertSame(ItemUseResult::SUCCESS, $rod->onClickAir($this->owner, new Vector3(1, 0, 0), $returned));
		self::assertTrue($hook->isFlaggedForDespawn());
		self::assertNull($this->owner->getFishingHook());
		self::assertSame(0, $rod->getDamage());
		self::assertFalse($this->owner->tryStartFishingAction(10));
	}

	public function testCancelledLaunchLeavesNoActiveHook() : void{
		$this->listen(ProjectileLaunchEvent::class, static function(ProjectileLaunchEvent $event) : void{ $event->cancel(); });
		$returned = [];
		self::assertSame(ItemUseResult::FAIL, VanillaItems::FISHING_ROD()->onClickAir($this->owner, new Vector3(1, 0, 0), $returned));
		self::assertNull($this->owner->getFishingHook());
	}

	public function testHookAndPullOnceWithoutDamage() : void{
		$hook = $this->hook();
		$this->hit($hook);
		self::assertSame($this->target, $hook->getTargetEntity());
		$this->target->expects(self::once())->method("setMotion")->with(self::callback(
			static fn(Vector3 $motion) : bool => $motion->x === -0.6 && $motion->y === 0.2 && $motion->z === 0.0
		))->willReturn(true);
		self::assertSame(5, $hook->reelIn($this->owner));
		self::assertSame(0, $hook->reelIn($this->owner));
		self::assertTrue($hook->isFlaggedForDespawn());
	}

	public function testLegacyDamageProtectionCancelsHook() : void{
		$this->listen(EntityDamageByEntityEvent::class, function(EntityDamageByEntityEvent $event) : void{
			self::assertSame($this->owner, $event->getDamager());
			self::assertSame(0.0, $event->getFinalDamage());
			$event->cancel();
		});
		$hook = $this->hook();
		$this->hit($hook);
		self::assertNull($hook->getTargetEntity());
		self::assertTrue($hook->isFlaggedForDespawn());
	}

	public function testProtectionIsRecheckedAtReel() : void{
		$hook = $this->hook();
		$this->hit($hook);
		$this->listen(FishingHookEntityEvent::class, static function(FishingHookEntityEvent $event) : void{
			self::assertSame(FishingHookEntityEvent::ACTION_PULL, $event->getAction());
			$event->cancel();
		});
		$this->target->expects(self::never())->method("setMotion");
		self::assertSame(0, $hook->reelIn($this->owner));
	}

	public function testPvpDisabledPreventsHook() : void{
		$this->pvp = false;
		$hook = $this->hook();
		$this->hit($hook);
		self::assertNull($hook->getTargetEntity());
	}

	public function testInvulnerableTargetCannotBeHooked() : void{
		$this->target->noDamageTicks = 20;
		$hook = $this->hook();
		$this->hit($hook);
		self::assertNull($hook->getTargetEntity());
		self::assertTrue($hook->isFlaggedForDespawn());
	}

	public function testSuccessfulPullDamagesHeldRod() : void{
		$hook = $this->hook();
		$this->hit($hook);
		$this->target->method("setMotion")->willReturn(true);
		$rod = $this->inventory->getItemInHand();
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $rod->onClickAir($this->owner, new Vector3(1, 0, 0), $returned));
		self::assertSame(5, $rod->getDamage());
		self::assertNull($this->owner->getFishingHook());
	}

	public function testRejectedMotionDoesNotChargeDurability() : void{
		$hook = $this->hook();
		$this->hit($hook);
		$this->target->expects(self::once())->method("setMotion")->willReturn(false);
		self::assertSame(0, $hook->reelIn($this->owner));
		self::assertTrue($hook->isFlaggedForDespawn());
	}

	public function testWallAddedAfterHookPreventsPull() : void{
		$hook = $this->hook();
		$this->hit($hook);
		$this->wall = true;
		$this->target->expects(self::never())->method("setMotion");
		self::assertSame(0, $hook->reelIn($this->owner));
	}

	public function testRodSwitchAndDisconnectInvalidateHook() : void{
		$hook = $this->hook();
		$this->inventory->setHeldItemIndex(1);
		self::assertFalse($hook->isValidFor($this->owner));
		$hook->onUpdate(1);
		self::assertTrue($hook->isFlaggedForDespawn());
		$this->inventory->setHeldItemIndex(0);
		$hook = $this->hook();
		$this->connected = false;
		self::assertFalse($hook->isValidFor($this->owner));
		$hook->onUpdate(2);
		self::assertTrue($hook->isFlaggedForDespawn());
	}

	private function floatingHook() : FishingHook{
		$this->blocks["4:64:0"] = VanillaBlocks::WATER();
		$water = $this->world->getBlockAt(4, 64, 0);
		$hook = $this->hook(new FishingSettings(["bite-wait-min-ticks" => 1, "bite-wait-max-ticks" => 1, "bite-window-ticks" => 3]));
		(new \ReflectionMethod(FishingHook::class, "onHitBlock"))->invoke($hook, $water, new RayTraceResult(new AxisAlignedBB(4, 64, 0, 5, 65, 1), Facing::UP, new Vector3(4, 65, 0)));
		(new \ReflectionMethod(FishingHook::class, "entityBaseTick"))->invoke($hook, 1);
		return $hook;
	}

	public function testBiteYieldsOneCatchAndExperience() : void{
		$hook = $this->floatingHook();
		$this->world->expects(self::once())->method("dropItem");
		$this->world->expects(self::once())->method("dropExperience")->with(self::anything(), self::callback(static fn(int $xp) : bool => $xp >= 1 && $xp <= 6));
		self::assertSame(1, $hook->reelIn($this->owner));
		self::assertSame(0, $hook->reelIn($this->owner));
	}

	public function testFlyingHookHitsWaterSurfaceAndCatches() : void{
		$this->blocks["4:64:0"] = VanillaBlocks::WATER();
		$hook = new FishingHook(new Location(4.5, 65.5, 0.5, $this->world, 0, 0), $this->owner, new FishingSettings(["bite-wait-min-ticks" => 1, "bite-wait-max-ticks" => 1]));
		$this->owner->setFishingHook($hook);
		$hook->setMotion(new Vector3(0, -1, 0));
		self::assertTrue($hook->onUpdate(1));
		self::assertFalse($hook->hasMovementUpdate());
		self::assertGreaterThan(64.5, $hook->getPosition()->y);
		$this->world->expects(self::once())->method("dropItem");
		self::assertSame(1, $hook->reelIn($this->owner));
	}

	public function testCancelledCatchAndRecursiveReelProduceNoLoot() : void{
		$hook = $this->floatingHook();
		$this->listen(PlayerFishEvent::class, function(PlayerFishEvent $event) : void{
			self::assertSame(0, $event->getHook()->reelIn($this->owner));
			$event->cancel();
		});
		$this->world->expects(self::never())->method("dropItem");
		$this->world->expects(self::never())->method("dropExperience");
		self::assertSame(0, $hook->reelIn($this->owner));
	}

	public function testMissedBiteCannotYieldLoot() : void{
		$hook = $this->floatingHook();
		(new \ReflectionMethod(FishingHook::class, "entityBaseTick"))->invoke($hook, 3);
		$this->world->expects(self::never())->method("dropItem");
		self::assertSame(0, $hook->reelIn($this->owner));
	}

	public function testInvalidConfigurationAndVerticalPullAreBounded() : void{
		$settings = new FishingSettings(["max-distance" => INF, "pull-strength" => 100, "pull-vertical" => 100, "bite-wait-min-ticks" => 300, "bite-wait-max-ticks" => -1]);
		self::assertSame(24.0, $settings->range);
		self::assertSame(300, $settings->biteWaitMax);
		self::assertEquals(new Vector3(0, 0.5, 0), $settings->getPullMotion(new Vector3(0, 1000, 0)));
		self::assertEquals(new Vector3(1.5, -0.5, 0), $settings->getPullMotion(new Vector3(1000, -1000, 0)));
	}
}
