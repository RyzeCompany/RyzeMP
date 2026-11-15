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

namespace pocketmine\item;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\FireworkRocket as FireworkEntity;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\Zombie;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\redstone\NullThreadSafeLogger;
use Ramsey\Uuid\Uuid;

final class CrossbowFireworkTest extends MobTestCase{

	/** @var Player[] */
	private array $players = [];

	protected function tearDown() : void{
		foreach($this->players as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	/**
	 * A player facing +Z (yaw 0) at the given position, without a network session.
	 */
	private function player(float $x, float $y, float $z) : Player{
		$player = (new \ReflectionClass(CrossbowFireworkTestPlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($x, $y, $z, $this->world, 0, 0));
		(new \ReflectionProperty(Entity::class, "size"))->setValue($player, new EntitySizeInfo(1.8, 0.6, 1.62));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, GameMode::SURVIVAL);
		(new \ReflectionProperty(Player::class, "tickMovement"))->setValue($player, Vector3::zero());
		(new \ReflectionProperty(Human::class, "uuid"))->setValue($player, Uuid::uuid4());
		(new \ReflectionProperty(Human::class, "inventory"))->setValue($player, new PlayerInventory($player));
		(new \ReflectionProperty(Human::class, "offHandInventory"))->setValue($player, new PlayerOffHandInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$player->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		$this->world->addEntity($player);
		$this->players[] = $player;
		return $player;
	}

	private function rocket(int $stars) : FireworkRocket{
		$explosions = [];
		for($i = 0; $i < $stars; ++$i){
			$explosions[] = new FireworkRocketExplosion(FireworkRocketType::SMALL_BALL, [DyeColor::RED]);
		}
		return VanillaItems::FIREWORK_ROCKET()->setFlightTimeMultiplier(2)->setExplosions($explosions);
	}

	private static function chargedItem(Crossbow $crossbow) : ?Item{
		$value = (new \ReflectionProperty(Crossbow::class, "chargedItem"))->getValue($crossbow);
		return $value instanceof Item ? $value : null;
	}

	/**
	 * @return FireworkEntity[]
	 */
	private function fireworks() : array{
		$result = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof FireworkEntity && !$entity->isClosed()){
				$result[] = $entity;
			}
		}
		return $result;
	}

	public function testLoadsARocketFromTheOffHand() : void{
		$player = $this->player(0.5, 1, 0.5);
		$crossbow = VanillaItems::CROSSBOW();
		self::assertFalse($crossbow->canStartUsingItem($player), "no ammo");

		$player->getOffHandInventory()->setItem(0, $this->rocket(1)->setCount(2));
		self::assertTrue($crossbow->canStartUsingItem($player));
		self::assertTrue($crossbow->onChargeComplete($player, $crossbow->getChargeDuration()));
		self::assertSame(1, $player->getOffHandInventory()->getItem(0)->getCount());

		//the charged rocket survives the item NBT round trip (Bedrock "chargedItem")
		$copy = Item::nbtDeserialize($crossbow->nbtSerialize());
		self::assertInstanceOf(Crossbow::class, $copy);
		$charged = self::chargedItem($copy);
		self::assertInstanceOf(FireworkRocket::class, $charged);
		self::assertSame(2, $charged->getFlightTimeMultiplier());
		self::assertCount(1, $charged->getExplosions());
	}

	public function testOffHandRocketBeatsArrows() : void{
		$player = $this->player(0.5, 1, 0.5);
		$player->getInventory()->setItem(0, VanillaItems::ARROW()->setCount(5));
		$player->getOffHandInventory()->setItem(0, $this->rocket(0));
		$crossbow = VanillaItems::CROSSBOW();
		self::assertTrue($crossbow->onChargeComplete($player, $crossbow->getChargeDuration()));
		self::assertInstanceOf(FireworkRocket::class, self::chargedItem($crossbow));
		self::assertSame(5, $player->getInventory()->getItem(0)->getCount());
		self::assertTrue($player->getOffHandInventory()->getItem(0)->isNull());
	}

	public function testFiresRocketsAndCostsThreeDurabilityEach() : void{
		$player = $this->player(0.5, 1, 0.5);
		$crossbow = VanillaItems::CROSSBOW();
		$crossbow->setCharged($this->rocket(1));
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $crossbow->onClickAir($player, new Vector3(0, 0, 1), $returned));
		self::assertFalse($crossbow->isCharged());
		self::assertSame(3, $crossbow->getDamage());
		$fireworks = $this->fireworks();
		self::assertCount(1, $fireworks);
		self::assertTrue($fireworks[0]->isShotAsProjectile());
		self::assertEqualsWithDelta(1.6, $fireworks[0]->getMotion()->length(), 0.0001);
		self::assertGreaterThan(0.99, $fireworks[0]->getMotion()->normalize()->z, "fired straight ahead");
		foreach($this->world->getEntities() as $entity){
			self::assertNotInstanceOf(ArrowEntity::class, $entity);
		}
		foreach($fireworks as $firework){
			$firework->close();
		}

		$multishot = VanillaItems::CROSSBOW()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::MULTISHOT()));
		$multishot->setCharged($this->rocket(0));
		self::assertSame(ItemUseResult::SUCCESS, $multishot->onClickAir($player, new Vector3(0, 0, 1), $returned));
		self::assertSame(9, $multishot->getDamage());
		self::assertCount(3, $this->fireworks());
	}

	public function testRocketExplodesOnAMobWithDamagePerStar() : void{
		foreach([0 => false, 2 => true] as $stars => $hurts){
			$player = $this->player(0.5, 1, 0.5);
			$zombie = new Zombie(new Location(0.5, 1, 4.5, $this->world, 0, 0));
			$zombie->spawnToAll();
			$health = $zombie->getHealth();

			$crossbow = VanillaItems::CROSSBOW();
			$crossbow->setCharged($this->rocket($stars));
			$returned = [];
			$crossbow->onClickAir($player, new Vector3(0, 0, 1), $returned);
			$fireworks = $this->fireworks();
			self::assertCount(1, $fireworks);
			$this->world->removeEntity($player);
			$this->runTicks(6);
			self::assertTrue($fireworks[0]->isClosed() || $fireworks[0]->isFlaggedForDespawn(), "exploded on the zombie ($stars stars)");
			$damage = $health - $zombie->getHealth();
			if($hurts){
				//5 + 2 per star at most, less with distance (Java FireworkRocketEntity.dealExplosionDamage)
				self::assertGreaterThan(0.0, $damage);
				self::assertLessThanOrEqual(9.0, $damage);
			}else{
				self::assertSame(0.0, $damage, "a rocket without stars does no damage");
			}
			$zombie->close();
		}
	}

	public function testRocketExplodesOnABlock() : void{
		for($y = 1; $y <= 4; ++$y){
			$this->set(0, $y, 4, VanillaBlocks::STONE());
		}
		$player = $this->player(0.5, 1, 0.5);
		$crossbow = VanillaItems::CROSSBOW();
		$crossbow->setCharged($this->rocket(3)); //flight 2: about 30-42 ticks
		$returned = [];
		$crossbow->onClickAir($player, new Vector3(0, 0, 1), $returned);
		$fireworks = $this->fireworks();
		self::assertCount(1, $fireworks);
		//the fake player has no armor inventory to take the explosion damage with
		$this->world->removeEntity($player);
		$this->runTicks(6);
		self::assertTrue($fireworks[0]->isClosed() || $fireworks[0]->isFlaggedForDespawn());
		self::assertLessThan(4.0, $fireworks[0]->getPosition()->z);
		self::assertCount(0, $this->fireworks());
	}
}

/**
 * A player built without a network session.
 */
final class CrossbowFireworkTestPlayer extends Player{

	public function hasFiniteResources() : bool{
		return true;
	}

	public function __destruct(){
		//NOOP: never connected
	}
}
