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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\CombatRules;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Trident as TridentEntity;
use pocketmine\entity\Zombie;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\redstone\NullThreadSafeLogger;
use Ramsey\Uuid\Uuid;

final class TridentTest extends MobTestCase{

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

	private function player(float $x, float $y, float $z, int $useTicks) : TridentTestPlayer{
		$player = (new \ReflectionClass(TridentTestPlayer::class))->newInstanceWithoutConstructor();
		$player->useTicks = $useTicks;
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($x, $y, $z, $this->world, 0, 0));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, GameMode::SURVIVAL);
		(new \ReflectionProperty(Player::class, "tickMovement"))->setValue($player, Vector3::zero());
		(new \ReflectionProperty(Entity::class, "size"))->setValue($player, new EntitySizeInfo(1.8, 0.6, 1.62));
		(new \ReflectionProperty(Human::class, "uuid"))->setValue($player, Uuid::uuid4());
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		$player->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		$this->world->addEntity($player);
		$this->players[] = $player;
		return $player;
	}

	private static function tickSpin(Player $player) : void{
		(new \ReflectionMethod(Player::class, "tickSpinAttack"))->invoke($player, 1);
	}

	private function riptideTrident() : Trident{
		return VanillaItems::TRIDENT()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::RIPTIDE(), 3));
	}

	public function testThrowNeedsTenTicksAndHasConstantSpeed() : void{
		$player = $this->player(0.5, 1, 0.5, 9);
		$trident = VanillaItems::TRIDENT();
		$returned = [];
		self::assertSame(ItemUseResult::FAIL, $trident->onReleaseUsing($player, $returned));
		self::assertSame(1, $trident->getCount());

		foreach([10, 30] as $ticks){
			$player->useTicks = $ticks;
			$trident = VanillaItems::TRIDENT();
			self::assertSame(ItemUseResult::SUCCESS, $trident->onReleaseUsing($player, $returned));
			self::assertTrue($trident->isNull(), "the thrown trident leaves the hand");
			$thrown = null;
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof TridentEntity && !$entity->isClosed()){
					$thrown = $entity;
				}
			}
			self::assertNotNull($thrown);
			self::assertEqualsWithDelta(4.0, $thrown->getMotion()->length(), 0.0001);
			$thrown->close();
		}
	}

	public function testRiptideOnLandDoesNothing() : void{
		$player = $this->player(0.5, 1, 0.5, 20);
		$trident = $this->riptideTrident();
		self::assertFalse($trident->canStartUsingItem($player));
		$returned = [];
		self::assertSame(ItemUseResult::FAIL, $trident->onReleaseUsing($player, $returned));
		self::assertSame(1, $trident->getCount());
		self::assertSame(0, $trident->getDamage());
		self::assertFalse($player->isSpinAttacking());
	}

	public function testRiptideInWaterKeepsTheTridentAndHitsATouchedMob() : void{
		$this->set(0, 1, 0, VanillaBlocks::WATER());
		$this->set(0, 2, 0, VanillaBlocks::WATER());
		$player = $this->player(0.5, 1, 0.5, 20);
		$trident = $this->riptideTrident();
		self::assertTrue($trident->canStartUsingItem($player));
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $trident->onReleaseUsing($player, $returned));
		self::assertSame(1, $trident->getCount(), "a Riptide trident is never thrown");
		self::assertSame(1, $trident->getDamage());
		self::assertTrue($player->isSpinAttacking());
		foreach($this->world->getEntities() as $entity){
			self::assertNotInstanceOf(TridentEntity::class, $entity);
		}

		$zombie = new Zombie(new Location(1.0, 1, 0.5, $this->world, 0, 0));
		$zombie->spawnToAll();
		$health = $zombie->getHealth();
		self::tickSpin($player);
		//8 damage, less 1.6% for the zombie's natural armor
		self::assertEqualsWithDelta($health - 8 * (1 - CombatRules::getArmorReduction(8, Zombie::NATURAL_ARMOR, 0)), $zombie->getHealth(), 0.0001);
		self::assertFalse($player->isSpinAttacking(), "the spin ends on the first hit");
		$zombie->close();
	}

	public function testSpinAttackEndsWithoutContact() : void{
		$player = $this->player(0.5, 1, 0.5, 20);
		$player->startSpinAttack($this->riptideTrident(), 20);
		for($i = 0; $i < 19; ++$i){
			self::tickSpin($player);
		}
		self::assertTrue($player->isSpinAttacking());
		self::tickSpin($player);
		self::assertFalse($player->isSpinAttacking());
	}
}

/**
 * A player built without a network session, with a fixed item use duration.
 */
final class TridentTestPlayer extends Player{
	public int $useTicks = 0;

	public function getItemUseDuration() : int{
		return $this->useTicks;
	}

	public function hasFiniteResources() : bool{
		return true;
	}

	public function __destruct(){
		//NOOP: never connected
	}
}
