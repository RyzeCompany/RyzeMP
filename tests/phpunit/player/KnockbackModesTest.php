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
use pocketmine\entity\Attribute;
use pocketmine\entity\AttributeMap;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Human;
use pocketmine\entity\HungerManager;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\redstone\NullThreadSafeLogger;
use function min;
use function sqrt;

/**
 * Knockback of player hits against PocketMine-MP 5.44.3: with player.knockback: pmmp (the default) every hit pushes
 * exactly like 5.44.3's code, and in both modes a knockback a plugin sets is applied as it is.
 */
final class KnockbackModesTest extends MobTestCase{

	/** @var Entity[] */
	private array $spawned = [];
	/** @phpstan-var RegisteredListener<EntityDamageByEntityEvent>|null */
	private ?RegisteredListener $listener = null;

	protected function tearDown() : void{
		if($this->listener !== null){
			HandlerListManager::global()->getListFor(EntityDamageByEntityEvent::class)->unregister($this->listener);
			$this->listener = null;
		}
		Living::setVanillaKnockback(false);
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
	 * Living::knockBack() of PocketMine-MP 5.44.3, for a victim without knockback resistance.
	 */
	private static function pmmpKnockBack(Vector3 $motion, float $x, float $z, float $force, ?float $verticalLimit = Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : Vector3{
		$f = sqrt($x * $x + $z * $z);
		if($f <= 0){
			return $motion;
		}
		$f = 1 / $f;
		$motionY = $motion->y / 2 + $force;
		$verticalLimit ??= $force;
		return new Vector3($motion->x / 2 + $x * $f * $force, min($motionY, $verticalLimit), $motion->z / 2 + $z * $f * $force);
	}

	private static function assertMotion(Vector3 $expected, Entity $entity, string $message = "") : void{
		$motion = $entity->getMotion();
		self::assertEqualsWithDelta($expected->x, $motion->x, 1.0e-9, "x $message");
		self::assertEqualsWithDelta($expected->y, $motion->y, 1.0e-9, "y $message");
		self::assertEqualsWithDelta($expected->z, $motion->z, 1.0e-9, "z $message");
	}

	/**
	 * A Player built without its constructor, standing in the test world and facing +Z.
	 */
	private function player(Vector3 $pos, ?Item $held = null) : Player{
		$player = (new \ReflectionClass(KnockbackModesTestPlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($pos->x, $pos->y, $pos->z, $this->world, 0, 0));
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Entity::class, "attributeMap"))->setValue($player, new AttributeMap());
		(new \ReflectionMethod(Living::class, "addAttributes"))->invoke($player);
		(new \ReflectionProperty(Entity::class, "motion"))->setValue($player, Vector3::zero());
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
		$inventory->setItemInHand($held ?? VanillaItems::IRON_SWORD());
		$this->world->addEntity($player);
		$this->spawned[] = $player;
		return $player;
	}

	private function pin(Mob $mob) : void{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->getTargetSelector()->setFlagDisabled(Goal::FLAG_TARGET, true);
		$mob->setMaxHealth(1000);
		$mob->setHealth(1000);
		$mob->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(0.0);
		$mob->spawnToAll();
		$this->spawned[] = $mob;
	}

	/**
	 * A zombie 2 blocks in front (+Z) of the player at x, standing still.
	 */
	private function victim(float $x, ?Vector3 $motion = null) : Zombie{
		$zombie = new Zombie(new Location($x, 1, 2.5, $this->world, 0, 0));
		$this->pin($zombie);
		$zombie->setMotion($motion ?? Vector3::zero());
		return $zombie;
	}

	private static function sword(int $knockback = 0) : Item{
		$sword = VanillaItems::IRON_SWORD();
		if($knockback > 0){
			$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::KNOCKBACK(), $knockback));
		}
		return $sword;
	}

	/**
	 * Lets a "plugin" change every EntityDamageByEntityEvent.
	 *
	 * @phpstan-param \Closure(EntityDamageByEntityEvent) : void $handler
	 */
	private function plugin(\Closure $handler) : void{
		$this->listener = new RegisteredListener($handler, EventPriority::NORMAL, $this->createConfiguredMock(Plugin::class, ["isEnabled" => true]), false, new TimingsHandler("test"));
		HandlerListManager::global()->getListFor(EntityDamageByEntityEvent::class)->register($this->listener);
	}

	public function testPmmpModeHitsLike5443() : void{
		$zero = Vector3::zero();
		$base = self::pmmpKnockBack($zero, 0, 2, 0.4);

		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$victim = $this->victim(0.5);
		$player->attackEntity($victim);
		self::assertMotion($base, $victim, "normal hit");

		$sprinter = $this->player(new Vector3(10.5, 1, 0.5));
		$sprinter->setSprinting();
		$victim = $this->victim(10.5);
		$sprinter->attackEntity($victim);
		self::assertMotion($base, $victim, "sprint hit: no extra push");

		foreach([1, 2] as $level){
			$x = 20.5 + $level * 10;
			$attacker = $this->player(new Vector3($x, 1, 0.5), self::sword($level));
			$attacker->setSprinting();
			$victim = $this->victim($x);
			$attacker->attackEntity($victim);
			//5.44.3: the event's push, then KnockbackEnchantment::onPostAttack() pushes again with level * 0.5
			self::assertMotion(self::pmmpKnockBack($base, 0, 2, $level * 0.5), $victim, "Knockback $level");
		}

		$airborne = new Vector3(0.1, 0.3, -0.2);
		$player = $this->player(new Vector3(60.5, 1, 0.5));
		$victim = $this->victim(60.5, $airborne);
		$player->attackEntity($victim);
		self::assertMotion(self::pmmpKnockBack($airborne, 0, 2, 0.4), $victim, "victim in the air");
	}

	public function testPmmpModeNetheriteDoesNotResist() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$victim = $this->victim(0.5);
		$victim->getArmorInventory()->setChestplate(VanillaItems::NETHERITE_CHESTPLATE());
		$victim->getArmorInventory()->setBoots(VanillaItems::NETHERITE_BOOTS());
		self::assertSame(0.0, $victim->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->getValue());
		$player->attackEntity($victim);
		self::assertMotion(self::pmmpKnockBack(Vector3::zero(), 0, 2, 0.4), $victim);
	}

	public function testPlayerVictim() : void{
		//the test server has pvp off: the hit of another player as Player::attackEntity() sends it
		$attacker = $this->player(new Vector3(0.5, 1, 0.5));
		$attacker->setSprinting();
		$victim = $this->player(new Vector3(0.5, 1, 2.5));
		$victim->attack(new EntityDamageByEntityEvent($attacker, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 7));
		self::assertMotion(self::pmmpKnockBack(Vector3::zero(), 0, 2, 0.4), $victim);
	}

	public function testVanillaModeExtras() : void{
		Living::setVanillaKnockback(true);
		$base = self::pmmpKnockBack(Vector3::zero(), 0, 2, 0.4);

		$sprinter = $this->player(new Vector3(0.5, 1, 0.5));
		$sprinter->setSprinting();
		$victim = $this->victim(0.5);
		$sprinter->attackEntity($victim);
		self::assertMotion(self::pmmpKnockBack($base, 0, 2, 0.5), $victim, "sprint hit: one level further");

		$sprinter = $this->player(new Vector3(10.5, 1, 0.5), self::sword(1));
		$sprinter->setSprinting();
		$victim = $this->victim(10.5);
		$sprinter->attackEntity($victim);
		self::assertMotion(self::pmmpKnockBack($base, 0, 2, 1.0), $victim, "sprint + Knockback I: a single push");

		$victim = $this->victim(20.5);
		$victim->getArmorInventory()->setChestplate(VanillaItems::NETHERITE_CHESTPLATE());
		self::assertEqualsWithDelta(0.1, $victim->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->getValue() ?? 0.0, 1.0e-9);
	}

	/**
	 * @return \Generator<string, array{bool, float, float}>
	 */
	public static function pluginKnockbackProvider() : \Generator{
		foreach(["pmmp" => false, "vanilla" => true] as $mode => $vanilla){
			yield "$mode, knockback x0" => [$vanilla, 0.0, Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT];
			yield "$mode, knockback x0.5" => [$vanilla, 0.2, Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT];
			yield "$mode, knockback x2" => [$vanilla, 0.8, Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT];
			yield "$mode, vertical limit 0" => [$vanilla, Living::DEFAULT_KNOCKBACK_FORCE, 0.0];
			yield "$mode, vertical limit 1" => [$vanilla, 0.8, 1.0];
		}
	}

	/**
	 * @dataProvider pluginKnockbackProvider
	 */
	public function testPluginKnockbackIsFinal(bool $vanilla, float $force, float $verticalLimit) : void{
		Living::setVanillaKnockback($vanilla);
		$this->plugin(function(EntityDamageByEntityEvent $ev) use ($force, $verticalLimit) : void{
			$ev->setKnockBack($force);
			$ev->setVerticalKnockBackLimit($verticalLimit);
		});
		//a sprinting attacker: the vanilla sprint push must not come on top of the plugin's value
		$sprinter = $this->player(new Vector3(0.5, 1, 0.5));
		$sprinter->setSprinting();
		$victim = $this->victim(0.5, new Vector3(0, 0.2, 0));
		$sprinter->attackEntity($victim);
		self::assertMotion(self::pmmpKnockBack(new Vector3(0, 0.2, 0), 0, 2, $force, $verticalLimit), $victim);
	}

	/**
	 * @return \Generator<string, array{bool}>
	 */
	public static function modeProvider() : \Generator{
		yield "pmmp" => [false];
		yield "vanilla" => [true];
	}

	/**
	 * @dataProvider modeProvider
	 */
	public function testPluginAttackCooldown(bool $vanilla) : void{
		Living::setVanillaKnockback($vanilla);
		$cooldown = 0;
		$this->plugin(function(EntityDamageByEntityEvent $ev) use (&$cooldown) : void{
			$ev->setAttackCooldown($cooldown);
		});
		$base = self::pmmpKnockBack(Vector3::zero(), 0, 2, 0.4);

		//no cooldown: a second hit in the same tick is a full hit with its own push
		$player = $this->player(new Vector3(0.5, 1, 0.5));
		$victim = $this->victim(0.5);
		$player->attackEntity($victim);
		$player->attackEntity($victim);
		self::assertMotion(self::pmmpKnockBack($base, 0, 2, 0.4), $victim, "cooldown 0");

		//20 ticks: the second hit is cancelled (not stronger) and doesn't push
		$cooldown = 20;
		$player = $this->player(new Vector3(10.5, 1, 0.5));
		$victim = $this->victim(10.5);
		$player->attackEntity($victim);
		self::assertFalse($player->attackEntity($victim));
		self::assertMotion($base, $victim, "cooldown 20");
	}

	/**
	 * @dataProvider modeProvider
	 */
	public function testCancelledHitDoesNotPush(bool $vanilla) : void{
		Living::setVanillaKnockback($vanilla);
		$this->plugin(function(EntityDamageByEntityEvent $ev) : void{
			$ev->cancel();
		});
		$motion = new Vector3(0.05, 0.1, 0);
		$sprinter = $this->player(new Vector3(0.5, 1, 0.5), self::sword(2));
		$sprinter->setSprinting();
		$victim = $this->victim(0.5, $motion);
		self::assertFalse($sprinter->attackEntity($victim));
		self::assertMotion($motion, $victim);
	}

	/**
	 * @dataProvider modeProvider
	 */
	public function testDirectKnockBackAndSetMotion(bool $vanilla) : void{
		Living::setVanillaKnockback($vanilla);
		$motion = new Vector3(0.2, -0.1, 0.3);
		$victim = $this->victim(0.5, $motion);
		$victim->knockBack(1, -1, 0.7, null);
		self::assertMotion(self::pmmpKnockBack($motion, 1, -1, 0.7, null), $victim, "knockBack()");
		$victim->knockBack(-2, 0, 0.3);
		self::assertMotion(self::pmmpKnockBack(self::pmmpKnockBack($motion, 1, -1, 0.7, null), -2, 0, 0.3), $victim, "knockBack() with the default limit");

		$victim->setMotion(new Vector3(1, 2, 3));
		self::assertMotion(new Vector3(1, 2, 3), $victim, "setMotion()");
		$victim->addMotion(0.5, -0.5, 0);
		self::assertMotion(new Vector3(1.5, 1.5, 3), $victim, "addMotion()");
	}

	/**
	 * @dataProvider modeProvider
	 */
	public function testMobTossFollowsPluginKnockback(bool $vanilla) : void{
		Living::setVanillaKnockback($vanilla);
		$golem = new IronGolem(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$this->pin($golem);
		$target = $this->victim(0.5);
		self::assertTrue($golem->attackMelee($target, 7));
		//vanilla iron golem: the event's push plus 0.4 up
		self::assertMotion(self::pmmpKnockBack(Vector3::zero(), 0, 2, 0.4)->add(0, 0.4, 0), $target, "untouched");

		$this->plugin(function(EntityDamageByEntityEvent $ev) : void{
			$ev->setKnockBack(0.0);
		});
		$target = $this->victim(10.5, new Vector3(0, 0.2, 0));
		$golem->teleport(new Vector3(10.5, 1, 0.5));
		self::assertTrue($golem->attackMelee($target, 7));
		self::assertMotion(self::pmmpKnockBack(new Vector3(0, 0.2, 0), 0, 2, 0.0), $target, "knockback 0 set by a plugin: no toss");
	}
}

final class KnockbackModesTestPlayer extends Player{
	public function setSprinting(bool $value = true) : void{
		(new \ReflectionProperty(Living::class, "sprinting"))->setValue($this, $value);
	}
}
