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

namespace pocketmine\player;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\AttributeMap;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\HungerManager;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Durable;
use pocketmine\item\Shield;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\world\redstone\NullThreadSafeLogger;

/**
 * A shield raised by using it (how Java players and Bedrock players with it in the main hand raise it) stays raised
 * after blocking: the wear written back to the hand must not stop the use of the item.
 */
final class ShieldUseTest extends MobTestCase{

	/** @var Entity[] */
	private array $spawned = [];

	protected function tearDown() : void{
		foreach($this->spawned as $entity){
			if($entity instanceof Player){
				if($this->world->getEntity($entity->getId()) === $entity){
					$this->world->removeEntity($entity);
				}
				(new \ReflectionProperty(Entity::class, "closed"))->setValue($entity, true);
			}elseif(!$entity->isClosed()){
				$entity->flagForDespawn();
				$entity->close();
			}
		}
		$this->spawned = [];
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, facing +Z, with the held item listener it gets on spawn.
	 */
	private function player(Vector3 $pos) : Player{
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($pos->x, $pos->y, $pos->z, $this->world, 0, 0));
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Entity::class, "attributeMap"))->setValue($player, new AttributeMap());
		(new \ReflectionMethod(Living::class, "addAttributes"))->invoke($player);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($pos->x - 0.3, $pos->y, $pos->z - 0.3, $pos->x + 0.3, $pos->y + 1.8, $pos->z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, GameMode::SURVIVAL);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$inventory = new PlayerInventory($player);
		(new \ReflectionProperty(Human::class, "inventory"))->setValue($player, $inventory);
		(new \ReflectionProperty(Human::class, "offHandInventory"))->setValue($player, new PlayerOffHandInventory($player));
		(new \ReflectionProperty(Human::class, "hungerManager"))->setValue($player, new HungerManager($player));
		$listener = (new \ReflectionMethod(Player::class, "createHeldItemListener"))->invoke($player);
		self::assertInstanceOf(CallbackInventoryListener::class, $listener);
		$inventory->getListeners()->add($listener);
		$this->world->addEntity($player);
		$this->spawned[] = $player;
		return $player;
	}

	private function zombie(Vector3 $pos) : Zombie{
		$zombie = new Zombie(Location::fromObject($pos, $this->world));
		$zombie->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$zombie->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$zombie->spawnToAll();
		$this->spawned[] = $zombie;
		return $zombie;
	}

	/**
	 * Raises the shield by using it and waits out the block delay.
	 */
	private static function raiseByUse(Player $player) : void{
		$player->setUsingItem(true);
		(new \ReflectionProperty(Player::class, "shieldRaisedTick"))->setValue($player, $player->getServer()->getTick() - Shield::BLOCK_DELAY_TICKS);
		self::tickShield($player);
	}

	private static function tickShield(Player $player) : void{
		(new \ReflectionMethod(Player::class, "tickShield"))->invoke($player);
	}

	private static function hit(Zombie $zombie, Player $player) : float{
		$before = $player->getHealth();
		$player->attack(new EntityDamageByEntityEvent($zombie, $player, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
		return $before - $player->getHealth();
	}

	public function testShieldRaisedByUseKeepsBlocking() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$player->getInventory()->setItemInHand(VanillaItems::SHIELD());
		$zombie = $this->zombie(new Vector3(0.5, 1, 2.5));
		self::raiseByUse($player);
		self::assertTrue($player->isBlocking());
		$startTick = $player->getItemUseDuration();

		self::assertSame(0.0, self::hit($zombie, $player));
		$shield = $player->getInventory()->getItemInHand();
		self::assertInstanceOf(Durable::class, $shield);
		self::assertGreaterThan(0, $shield->getDamage(), "the block wore the shield");
		self::assertTrue($player->isUsingItem(), "the wear doesn't stop the use");
		self::assertSame($startTick, $player->getItemUseDuration(), "same use, same start tick");

		self::tickShield($player);
		self::assertTrue($player->isBlocking());
		self::assertSame(0.0, self::hit($zombie, $player), "the second hit is blocked too");
	}

	public function testAnotherItemInHandStopsTheUse() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$player->getInventory()->setItemInHand(VanillaItems::SHIELD());
		self::raiseByUse($player);

		$player->getInventory()->setItemInHand(VanillaItems::IRON_SWORD());
		self::assertFalse($player->isUsingItem());
		self::tickShield($player);
		self::assertFalse($player->isBlocking());

		$player->getInventory()->setItemInHand(VanillaItems::SHIELD());
		$player->setUsingItem(true);
		$player->getInventory()->setItemInHand(VanillaItems::AIR());
		self::assertFalse($player->isUsingItem(), "emptying the hand stops it too");
	}
}
