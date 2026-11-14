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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Attribute;
use pocketmine\entity\CombatRules;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Shield;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;

/**
 * Armour toughness, netherite knockback resistance, shields against explosions and their wear, totems of undying held
 * by mobs and Frost Walker.
 */
final class ArmorShieldsTest extends MobTestCase{

	private function loc(float $x, float $y, float $z, float $yaw = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, 0);
	}

	private static function wearDiamond(Living $entity) : void{
		$armor = $entity->getArmorInventory();
		$armor->setHelmet(VanillaItems::DIAMOND_HELMET());
		$armor->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
		$armor->setLeggings(VanillaItems::DIAMOND_LEGGINGS());
		$armor->setBoots(VanillaItems::DIAMOND_BOOTS());
	}

	private static function wearNetherite(Living $entity) : void{
		$armor = $entity->getArmorInventory();
		$armor->setHelmet(VanillaItems::NETHERITE_HELMET());
		$armor->setChestplate(VanillaItems::NETHERITE_CHESTPLATE());
		$armor->setLeggings(VanillaItems::NETHERITE_LEGGINGS());
		$armor->setBoots(VanillaItems::NETHERITE_BOOTS());
	}

	/**
	 * @return \Generator<string, array{float, float, float, float}>
	 */
	public static function toughnessProvider() : \Generator{
		//full diamond: 20 armour, 8 toughness; full netherite: 20 armour, 12 toughness
		yield "diamond, 5 damage" => [5, 20, 8, 0.75];
		yield "diamond, 20 damage" => [20, 20, 8, 0.6];
		yield "diamond, 40 damage" => [40, 20, 8, 0.4];
		yield "netherite, 5 damage" => [5, 20, 12, 0.76];
		yield "netherite, 20 damage" => [20, 20, 12, 0.64];
		yield "netherite, 40 damage" => [40, 20, 12, 0.48];
		yield "small hits: close to 4% per point" => [1, 20, 0, 0.78];
		yield "big hits pierce iron" => [20, 15, 0, 0.2];
		yield "never below a fifth of the armour" => [100, 15, 0, 0.12];
		yield "no armour" => [20, 0, 0, 0.0];
	}

	/**
	 * @dataProvider toughnessProvider
	 */
	public function testToughnessReduction(float $damage, float $armor, float $toughness, float $expected) : void{
		self::assertEqualsWithDelta($expected, CombatRules::getArmorReduction($damage, $armor, $toughness), 0.0001);
	}

	public function testToughnessOnMobs() : void{
		//BDS 1.26.52: /damage 36 entity_attack kills a zombie in full diamond, one in full netherite survives
		$diamond = new Zombie($this->loc(0.5, 1, 0.5));
		self::wearDiamond($diamond);
		$diamond->attack(new EntityDamageEvent($diamond, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 36));
		self::assertFalse($diamond->isAlive());

		$netherite = new Zombie($this->loc(4.5, 1, 0.5));
		self::wearNetherite($netherite);
		$netherite->attack(new EntityDamageEvent($netherite, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 36));
		self::assertTrue($netherite->isAlive());
		self::assertEqualsWithDelta(20 - 36 * (1 - 0.512), $netherite->getHealth(), 0.001);
		$netherite->close();
	}

	private static function knockbackResistance(Living $entity) : float{
		$attribute = $entity->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE);
		self::assertNotNull($attribute);
		return $attribute->getValue();
	}

	public function testNetheriteKnockbackResistance() : void{
		Living::setVanillaKnockback(true);
		try{
			$this->netheriteKnockbackResistance();
		}finally{
			Living::setVanillaKnockback(false);
		}
	}

	public function testNoNetheriteKnockbackResistanceInPmmpMode() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		self::wearNetherite($zombie);
		self::assertSame(0.0, self::knockbackResistance($zombie));
		$zombie->close();
	}

	private function netheriteKnockbackResistance() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		self::assertSame(0.0, self::knockbackResistance($zombie));
		self::wearNetherite($zombie);
		self::assertEqualsWithDelta(0.4, self::knockbackResistance($zombie), 0.000001);
		$zombie->getArmorInventory()->setHelmet(VanillaItems::DIAMOND_HELMET());
		self::assertEqualsWithDelta(0.3, self::knockbackResistance($zombie), 0.000001);
		$zombie->getArmorInventory()->clearAll();
		self::assertSame(0.0, self::knockbackResistance($zombie));
		$zombie->close();

		//added to the mob's own resistance
		$hoglin = new Hoglin($this->loc(4.5, 1, 0.5));
		self::assertEqualsWithDelta(0.6, self::knockbackResistance($hoglin), 0.000001);
		$hoglin->getArmorInventory()->setChestplate(VanillaItems::NETHERITE_CHESTPLATE());
		self::assertEqualsWithDelta(0.7, self::knockbackResistance($hoglin), 0.000001);
		$hoglin->getArmorInventory()->setChestplate(VanillaItems::AIR());
		self::assertEqualsWithDelta(0.6, self::knockbackResistance($hoglin), 0.000001);

		//a base value set while armour is worn (the attribute reset on respawn) gets the armour share on the next change
		$hoglin->getArmorInventory()->setChestplate(VanillaItems::NETHERITE_CHESTPLATE());
		$hoglin->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->resetToDefault();
		$hoglin->getArmorInventory()->setHelmet(VanillaItems::NETHERITE_HELMET());
		self::assertEqualsWithDelta(0.2, self::knockbackResistance($hoglin), 0.000001);
		$hoglin->close();
	}

	public function testNetheriteLoadedFromNbt() : void{
		Living::setVanillaKnockback(true);
		try{
			$this->netheriteLoadedFromNbt();
		}finally{
			Living::setVanillaKnockback(false);
		}
	}

	private function netheriteLoadedFromNbt() : void{
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		self::wearNetherite($zombie);
		$nbt = $zombie->saveNBT();
		$zombie->close();

		$loaded = new Zombie($this->loc(0.5, 1, 0.5), $nbt);
		self::assertEqualsWithDelta(0.4, self::knockbackResistance($loaded), 0.000001);
		$loaded->close();
	}

	public function testShieldWear() : void{
		self::assertSame(4, Shield::getDurabilityCost(3));
		self::assertSame(0, Shield::getDurabilityCost(2.5));
		self::assertSame(7, Shield::getDurabilityCost(6));
		self::assertSame(7, Shield::getDurabilityCost(6.9));
	}

	private function blockingPlayer(float $yaw) : Player{
		$player = (new \ReflectionClass(ArmorShieldsTestPlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $this->loc(0.5, 1, 0.5, $yaw));
		(new \ReflectionProperty(Player::class, "blocking"))->setValue($player, true);
		return $player;
	}

	private static function canBlock(Player $player, EntityDamageEvent $source) : bool{
		return (new \ReflectionMethod(Player::class, "canBlockWithShield"))->invoke($player, $source) === true;
	}

	public function testShieldBlocksExplosionsFromAnySide() : void{
		//yaw 0 faces +Z
		$player = $this->blockingPlayer(0.0);
		$inFront = new Creeper($this->loc(0.5, 1, 3.5));
		$behind = new Creeper($this->loc(0.5, 1, -2.5));

		self::assertTrue(self::canBlock($player, new EntityDamageByEntityEvent($inFront, $player, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 30)));
		//Bedrock blocks a blast from behind too
		self::assertTrue(self::canBlock($player, new EntityDamageByEntityEvent($behind, $player, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 30)));
		//melee attacks still need to come from the front
		self::assertFalse(self::canBlock($player, new EntityDamageByEntityEvent($behind, $player, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3)));

		//beds and respawn anchors: the block is the source
		$bed = $this->world->getBlockAt(0, 1, 4);
		self::assertTrue(self::canBlock($player, new EntityDamageByBlockEvent($bed, $player, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 30)));
		$bedBehind = $this->world->getBlockAt(0, 1, -4);
		self::assertTrue(self::canBlock($player, new EntityDamageByBlockEvent($bedBehind, $player, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 30)));

		//no known source, other causes and a lowered shield don't block
		self::assertFalse(self::canBlock($player, new EntityDamageEvent($player, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 30)));
		self::assertFalse(self::canBlock($player, new EntityDamageByEntityEvent($inFront, $player, EntityDamageEvent::CAUSE_MAGIC, 30)));
		self::assertTrue(self::canBlock($player, new EntityDamageByEntityEvent($inFront, $player, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3)));
		(new \ReflectionProperty(Player::class, "blocking"))->setValue($player, false);
		self::assertFalse(self::canBlock($player, new EntityDamageByEntityEvent($inFront, $player, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 30)));

		$inFront->close();
		$behind->close();
	}

	public function testMobTotemOfUndying() : void{
		$piglin = new Piglin($this->loc(0.5, 1, 0.5));
		$piglin->setMainHandItem(VanillaItems::TOTEM());
		$piglin->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), 200));
		$piglin->attack(new EntityDamageEvent($piglin, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 100));
		self::assertTrue($piglin->isAlive(), "saved by the totem");
		self::assertEqualsWithDelta(1.0, $piglin->getHealth(), 0.0001);
		self::assertTrue($piglin->getMainHandItem()->isNull(), "totem used up");
		$effects = $piglin->getEffects();
		self::assertFalse($effects->has(VanillaEffects::POISON()), "effects cleared");
		self::assertSame(1, $effects->get(VanillaEffects::REGENERATION())?->getAmplifier());
		self::assertSame(1, $effects->get(VanillaEffects::ABSORPTION())?->getAmplifier());
		self::assertTrue($effects->has(VanillaEffects::FIRE_RESISTANCE()));

		//no totem left: the next lethal hit kills it
		$piglin->setAbsorption(0);
		$piglin->attack(new EntityDamageEvent($piglin, EntityDamageEvent::CAUSE_SUICIDE, 100));
		self::assertFalse($piglin->isAlive());

		//off hand, undead (no regeneration for them)
		$husk = new Husk($this->loc(4.5, 1, 0.5));
		$husk->setOffHandItem(VanillaItems::TOTEM());
		$husk->attack(new EntityDamageEvent($husk, EntityDamageEvent::CAUSE_FALL, 100));
		self::assertTrue($husk->isAlive());
		self::assertTrue($husk->getOffHandItem()->isNull());
		self::assertTrue($husk->getEffects()->has(VanillaEffects::FIRE_RESISTANCE()));
		$husk->close();

		//the void isn't stopped by a totem
		$voided = new Husk($this->loc(8.5, 1, 0.5));
		$voided->setMainHandItem(VanillaItems::TOTEM());
		$voided->attack(new EntityDamageEvent($voided, EntityDamageEvent::CAUSE_VOID, 100));
		self::assertFalse($voided->isAlive());
		self::assertSame(ItemTypeIds::TOTEM, $voided->getMainHandItem()->getTypeId());
	}

	private function frostWalker(float $x, float $y, float $z) : Zombie{
		$zombie = new Zombie($this->loc($x, $y, $z));
		$zombie->getArmorInventory()->setBoots(VanillaItems::DIAMOND_BOOTS()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FROST_WALKER(), 1)));
		return $zombie;
	}

	private function waterPool() : void{
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::WATER(), false);
			}
		}
		$this->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
	}

	private static function move(Living $entity, float $dx, float $dy, float $dz) : void{
		(new \ReflectionMethod(Living::class, "move"))->invoke($entity, $dx, $dy, $dz);
	}

	public function testFrostWalkerDisc() : void{
		$this->waterPool();
		$zombie = $this->frostWalker(0.5, 1, 0.5);
		self::move($zombie, 0.1, -0.01, 0);
		self::assertTrue($zombie->isOnGround());

		//Frost Walker I: radius 3 around the zombie at x 0.6, z 0.5
		$frozen = [];
		for($x = -5; $x <= 5; ++$x){
			for($z = -5; $z <= 5; ++$z){
				if($x === 0 && $z === 0){
					continue;
				}
				$dx = $x + 0.5 - 0.6;
				$dz = $z + 0.5 - 0.5;
				$isIce = $this->world->getBlockAt($x, 0, $z)->getTypeId() === BlockTypeIds::FROSTED_ICE;
				self::assertSame($dx * $dx + $dz * $dz < 9, $isIce, "block $x, $z");
				if($isIce){
					$frozen[] = "$x:$z";
				}
			}
		}
		self::assertContains("3:0", $frozen);
		self::assertContains("2:2", $frozen);
		self::assertNotContains("3:3", $frozen, "a disc, not a square");
		self::assertNotContains("-3:-3", $frozen);
		$zombie->close();
	}

	public function testFrostWalkerNothingWhileAirborne() : void{
		$this->waterPool();
		$zombie = $this->frostWalker(2.5, 3, 2.5);
		self::move($zombie, 0.2, 0, 0.2);
		self::assertFalse($zombie->isOnGround());
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				self::assertNotSame(BlockTypeIds::FROSTED_ICE, $this->world->getBlockAt($x, 0, $z)->getTypeId(), "block $x, $z");
			}
		}
		$zombie->close();
	}
}

/**
 * A player built without a network session: only its shield logic is used.
 */
final class ArmorShieldsTestPlayer extends Player{

	public function __destruct(){
		//NOOP: never connected
	}
}
