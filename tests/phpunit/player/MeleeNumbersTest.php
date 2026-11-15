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

use pocketmine\block\BlockToolType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Attribute;
use pocketmine\entity\AttributeMap;
use pocketmine\entity\CombatRules;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Human;
use pocketmine\entity\HungerManager;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Vindicator;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\Rarity;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\world\redstone\NullThreadSafeLogger;

/**
 * Bedrock melee and enchantment numbers: Sharpness, melee-only Strength, sprint knockback, critical hits, the
 * invulnerability window, hoe damage, Quick Charge weight, Conduit Power mining and tool types.
 */
final class MeleeNumbersTest extends MobTestCase{

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
		Living::setVanillaKnockback(false);
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, standing in the test world and facing +Z.
	 */
	private function player(Vector3 $pos, Item $held) : Player{
		$player = (new \ReflectionClass(MeleeNumbersTestPlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($pos->x, $pos->y, $pos->z, $this->world, 0, 0));
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->world->getServer());
		(new \ReflectionProperty(Entity::class, "attributeMap"))->setValue($player, new AttributeMap());
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
		(new \ReflectionProperty(Human::class, "hungerManager"))->setValue($player, new HungerManager($player));
		$inventory->setItemInHand($held);
		$this->world->addEntity($player);
		$this->spawned[] = $player;
		return $player;
	}

	private function zombie(Vector3 $pos) : Zombie{
		$zombie = new Zombie(Location::fromObject($pos, $this->world));
		$this->pin($zombie);
		return $zombie;
	}

	/**
	 * An iron golem: no natural armor, so hits land for the raw melee damage (the BDS measurements were made on one).
	 */
	private function golem(Vector3 $pos) : IronGolem{
		$golem = new IronGolem(Location::fromObject($pos, $this->world));
		$this->pin($golem);
		return $golem;
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

	private function cow(Vector3 $pos) : Cow{
		$cow = new Cow(Location::fromObject($pos, $this->world));
		$cow->spawnToAll();
		$this->spawned[] = $cow;
		return $cow;
	}

	private static function sword(int $sharpness = 0) : Item{
		$sword = VanillaItems::IRON_SWORD();
		if($sharpness > 0){
			$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), $sharpness));
		}
		return $sword;
	}

	/**
	 * Health the zombie loses from one hit by the player.
	 */
	private static function hit(Player $player, Living $victim) : float{
		$before = $victim->getHealth();
		$player->attackEntity($victim);
		return $before - $victim->getHealth();
	}

	public function testSharpnessUsesTheBedrockBonus() : void{
		self::assertEqualsWithDelta(1.25, VanillaEnchantments::SHARPNESS()->getDamageBonus(1), 1.0e-9);
		self::assertEqualsWithDelta(6.25, VanillaEnchantments::SHARPNESS()->getDamageBonus(5), 1.0e-9);

		//BDS 1.26.60: 7 + 6.25 rounded down to 13; the zombie's 2 natural armor points take 1.6% off
		$player = $this->player(new Vector3(0.5, 1, 0.5), self::sword(5));
		$zombie = $this->zombie(new Vector3(0.5, 1, 2.5));
		self::assertEqualsWithDelta(13 * (1 - 0.4 / 25), self::hit($player, $zombie), 1.0e-6);
		self::assertEqualsWithDelta(12.792, 13 * (1 - 0.4 / 25), 1.0e-9);
	}

	/**
	 * @return \Generator<string, array{Item, float}>
	 */
	public static function roundedMeleeProvider() : \Generator{
		//BDS 1.26.60, hits on an iron golem
		yield "fist" => [VanillaItems::AIR(), 1];
		yield "wooden sword" => [VanillaItems::WOODEN_SWORD(), 5];
		yield "iron sword" => [self::sword(), 7];
		yield "iron sword, Sharpness I" => [self::sword(1), 8];
		yield "iron sword, Sharpness II" => [self::sword(2), 9];
		yield "iron sword, Sharpness III" => [self::sword(3), 10];
		yield "iron sword, Sharpness IV" => [self::sword(4), 12];
		yield "iron sword, Sharpness V" => [self::sword(5), 13];
		yield "wooden sword, Sharpness I" => [VanillaItems::WOODEN_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 1)), 6];
		yield "wooden sword, Sharpness II" => [VanillaItems::WOODEN_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 2)), 7];
		yield "diamond sword, Sharpness III" => [VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3)), 11];
		yield "diamond spear jab" => [VanillaItems::DIAMOND_SPEAR(), 5];
	}

	/**
	 * @dataProvider roundedMeleeProvider
	 */
	public function testMeleeDamageIsRoundedDown(Item $held, float $expected) : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5), $held);
		$golem = $this->golem(new Vector3(0.5, 1, 2.5));
		self::assertEqualsWithDelta($expected, self::hit($player, $golem), 1.0e-6);
	}

	/**
	 * @return \Generator<string, array{Item, ?EffectInstance, float}>
	 */
	public static function meleeEffectProvider() : \Generator{
		//BDS 1.26.60, hits on an iron golem: Strength x1.3 + 1 per level, Weakness x0.8 - 0.5 per level, on the
		//rounded weapon damage
		yield "fist, Strength I" => [VanillaItems::AIR(), new EffectInstance(VanillaEffects::STRENGTH(), 600, 0), 2.3];
		yield "fist, Strength II" => [VanillaItems::AIR(), new EffectInstance(VanillaEffects::STRENGTH(), 600, 1), 3.99];
		yield "iron sword, Strength I" => [self::sword(), new EffectInstance(VanillaEffects::STRENGTH(), 600, 0), 10.1];
		yield "iron sword, Strength II" => [self::sword(), new EffectInstance(VanillaEffects::STRENGTH(), 600, 1), 14.13];
		yield "iron sword, Sharpness I, Strength I" => [self::sword(1), new EffectInstance(VanillaEffects::STRENGTH(), 600, 0), 11.4];
		yield "iron sword, Sharpness I, Strength II" => [self::sword(1), new EffectInstance(VanillaEffects::STRENGTH(), 600, 1), 15.82];
		yield "fist, Weakness I" => [VanillaItems::AIR(), new EffectInstance(VanillaEffects::WEAKNESS(), 600, 0), 0.3];
		yield "iron sword, Weakness I" => [self::sword(), new EffectInstance(VanillaEffects::WEAKNESS(), 600, 0), 5.1];
		yield "iron sword, Sharpness I, Weakness I" => [self::sword(1), new EffectInstance(VanillaEffects::WEAKNESS(), 600, 0), 5.9];
		yield "iron sword, Weakness II" => [self::sword(), new EffectInstance(VanillaEffects::WEAKNESS(), 600, 1), 3.58];
		yield "netherite sword, Weakness II" => [VanillaItems::NETHERITE_SWORD(), new EffectInstance(VanillaEffects::WEAKNESS(), 600, 1), 4.86];
	}

	/**
	 * @dataProvider meleeEffectProvider
	 */
	public function testStrengthAndWeaknessCompoundOnTheWeaponDamage(Item $held, EffectInstance $effect, float $expected) : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5), $held);
		$player->getEffects()->add($effect);
		$golem = $this->golem(new Vector3(0.5, 1, 2.5));
		self::assertEqualsWithDelta($expected, self::hit($player, $golem), 1.0e-6);
	}

	public function testCriticalHitMultipliesTheRoundedDamageAndStrength() : void{
		//BDS 1.26.60: falling hits on an iron golem
		$player = $this->player(new Vector3(0.5, 1, 0.5), self::sword(1));
		$player->fallDistance = 1.0;
		self::assertEqualsWithDelta(12, self::hit($player, $this->golem(new Vector3(0.5, 1, 2.5))), 1.0e-6);

		$strong = $this->player(new Vector3(8.5, 1, 0.5), self::sword());
		$strong->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 600, 0));
		$strong->fallDistance = 1.0;
		self::assertEqualsWithDelta(15.15, self::hit($strong, $this->golem(new Vector3(8.5, 1, 2.5))), 1.0e-6);
	}

	public function testMaceSmashBonusIsMultipliedByTheCriticalHit() : void{
		//BDS 1.26.60, iron golem: (6 + smash bonus) x 1.5. The damage matches the bot's fall one tick after it sent the hit,
		//so those are the server's fall distances: 1.593 -> 18.5556, 2.110 -> 21.6573, 2.695 -> 25.1674, 3.346 -> 28.0389
		//and 4.844 -> 32.5335
		foreach([[1.593, 18.5556], [2.1096, 21.6573], [2.6946, 25.1674], [3.346, 28.0389], [4.8445, 32.5335]] as $i => [$fall, $expected]){
			$player = $this->player(new Vector3(0.5 + 8 * $i, 1, 0.5), VanillaItems::MACE());
			$player->fallDistance = $fall;
			self::assertEqualsWithDelta($expected, self::hit($player, $this->golem(new Vector3(0.5 + 8 * $i, 1, 2.5))), 0.01, "fall $fall");
		}

		//with Strength I: ((6 + smash bonus) x 1.3 + 1) x 1.5
		$strong = $this->player(new Vector3(-8.5, 1, 0.5), VanillaItems::MACE());
		$strong->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 600, 0));
		$strong->fallDistance = 2.6946;
		self::assertEqualsWithDelta(34.2176, self::hit($strong, $this->golem(new Vector3(-8.5, 1, 2.5))), 0.01);
	}

	public function testNaturalArmor() : void{
		//BDS 1.26.60: a fist hit does 0.94 to zombies, husks, drowned, zombified piglins and evokers (2 armor points), 1 to
		//skeletons, vindicators and iron golems
		$loc = Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world);
		foreach([new Zombie($loc), new Husk($loc), new Drowned($loc), new ZombifiedPiglin($loc), new Evoker($loc)] as $mob){
			self::assertSame(2, $mob->getArmorPoints(), $mob->getName());
			self::assertEqualsWithDelta(0.06, CombatRules::getArmorReduction(1, $mob->getArmorPoints(), 0), 1.0e-9);
			$mob->close();
		}
		foreach([new Skeleton($loc), new Vindicator($loc), new IronGolem($loc)] as $mob){
			self::assertSame(0, $mob->getArmorPoints(), $mob->getName());
			$mob->close();
		}

		$player = $this->player(new Vector3(0.5, 1, 0.5), VanillaItems::AIR());
		self::assertEqualsWithDelta(0.94, self::hit($player, $this->zombie(new Vector3(0.5, 1, 2.5))), 1.0e-6);
	}

	public function testArmorIsCappedAtTwenty() : void{
		//BDS 1.26.60: a zombie in full diamond (2 + 20 armor) takes what a skeleton in full diamond does: 0.21 from a fist,
		//5.25 from a Sharpness V netherite sword (15)
		self::assertEqualsWithDelta(1 - 0.21, CombatRules::getArmorReduction(1, 22, 8), 1.0e-9);
		self::assertEqualsWithDelta(1 - 5.25 / 15, CombatRules::getArmorReduction(15, 22, 8), 1.0e-9);
		self::assertSame(CombatRules::getArmorReduction(15, 20, 8), CombatRules::getArmorReduction(15, 22, 8));

		//an iron chestplate and diamond leggings on a zombie: 2 + 6 + 6 armor, 2 toughness (fist 0.456, iron sword 3.864)
		self::assertEqualsWithDelta(1 - 0.456, CombatRules::getArmorReduction(1, 14, 2), 1.0e-9);
		self::assertEqualsWithDelta(1 - 3.864 / 7, CombatRules::getArmorReduction(7, 14, 2), 1.0e-9);
	}

	public function testStrengthOnlyBoostsMelee() : void{
		$attacker = $this->zombie(new Vector3(0.5, 1, 0.5));
		$victim = $this->zombie(new Vector3(0.5, 1, 2.5));
		$attacker->getEffects()->add(new EffectInstance(VanillaEffects::STRENGTH(), 600, 1));
		$attacker->getEffects()->add(new EffectInstance(VanillaEffects::WEAKNESS(), 600, 0));

		$melee = new EntityDamageByEntityEvent($attacker, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 4);
		self::assertEqualsWithDelta(4 * 0.3 * 2, $melee->getModifier(EntityDamageEvent::MODIFIER_STRENGTH), 1.0e-9);
		self::assertEqualsWithDelta(-4 * 0.2, $melee->getModifier(EntityDamageEvent::MODIFIER_WEAKNESS), 1.0e-9);

		foreach([EntityDamageEvent::CAUSE_PROJECTILE, EntityDamageEvent::CAUSE_MAGIC, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION] as $cause){
			$ev = new EntityDamageByEntityEvent($attacker, $victim, $cause, 4);
			self::assertSame(0.0, $ev->getModifier(EntityDamageEvent::MODIFIER_STRENGTH), "cause $cause");
			self::assertSame(4.0, $ev->getFinalDamage(), "cause $cause");
		}
	}

	public function testSprintHitKnocksBackFurther() : void{
		Living::setVanillaKnockback(true);
		$walker = $this->player(new Vector3(-5.5, 1, 0.5), self::sword());
		$walked = $this->zombie(new Vector3(-5.5, 1, 2.5));
		$walker->attackEntity($walked);

		$sprinter = $this->player(new Vector3(5.5, 1, 0.5), self::sword());
		$sprinter->setSprinting();
		$sprinted = $this->zombie(new Vector3(5.5, 1, 2.5));
		$sprinter->attackEntity($sprinted);

		self::assertGreaterThan($walked->getMotion()->z + 0.2, $sprinted->getMotion()->z);
		self::assertLessThanOrEqual(Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT + 1.0e-9, $sprinted->getMotion()->y, "the sprint push doesn't lift higher than the base push");
	}

	public function testNoSprintPushOnPluginKnockback() : void{
		Living::setVanillaKnockback(true);
		$sprinter = $this->player(new Vector3(5.5, 1, 0.5), self::sword());
		$sprinter->setSprinting();
		$method = new \ReflectionMethod(Player::class, "applyPostAttackKnockBack");

		//a plugin disabled the knockback of this hit: no sprint push either
		$victim = $this->zombie(new Vector3(5.5, 1, 2.5));
		$victim->setMotion(new Vector3(0, 0, 0));
		$ev = new EntityDamageByEntityEvent($sprinter, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1);
		$ev->setKnockBack(0.0);
		$method->invoke($sprinter, $victim, $ev, []);
		self::assertSame(0.0, $victim->getMotion()->lengthSquared());

		//a plugin halved the knockback or changed its vertical limit: its value is final, no sprint push on top
		$ev->setKnockBack(Living::DEFAULT_KNOCKBACK_FORCE / 2);
		$method->invoke($sprinter, $victim, $ev, []);
		self::assertSame(0.0, $victim->getMotion()->lengthSquared());
		$ev->setKnockBack(Living::DEFAULT_KNOCKBACK_FORCE);
		$ev->setVerticalKnockBackLimit(0.2);
		$method->invoke($sprinter, $victim, $ev, []);
		self::assertSame(0.0, $victim->getMotion()->lengthSquared());

		//untouched: the sprint push
		$ev->setVerticalKnockBackLimit(Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT);
		$method->invoke($sprinter, $victim, $ev, []);
		self::assertEqualsWithDelta(0.5, $victim->getMotion()->z, 1.0e-6);
	}

	public function testSprintAddsToKnockbackEnchantment() : void{
		Living::setVanillaKnockback(true);
		$knockbackOne = self::sword()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::KNOCKBACK(), 1));
		$walker = $this->player(new Vector3(-5.5, 1, 0.5), $knockbackOne);
		$walked = $this->zombie(new Vector3(-5.5, 1, 2.5));
		$walker->attackEntity($walked);

		$sprinter = $this->player(new Vector3(5.5, 1, 0.5), clone $knockbackOne);
		$sprinter->setSprinting();
		$sprinted = $this->zombie(new Vector3(5.5, 1, 2.5));
		$sprinter->attackEntity($sprinted);

		//one knockBack() call of level 2, not two calls of level 1 (each call halves the motion already there)
		$walkedSpeed = $walked->getMotion()->z;
		self::assertEqualsWithDelta($walkedSpeed + 0.5, $sprinted->getMotion()->z, 0.05);
		self::assertLessThanOrEqual(Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT + 1.0e-9, $sprinted->getMotion()->y, "sprint + Knockback I keeps the vanilla 0.4 lift cap");
	}

	public function testCriticalHitWhileSprintingButNotWhileRiding() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5), self::sword());
		$player->setSprinting();
		$player->fallDistance = 0.5;
		$zombie = $this->golem(new Vector3(0.5, 1, 2.5));
		self::assertEqualsWithDelta(VanillaItems::IRON_SWORD()->getAttackPoints() * 1.5, self::hit($player, $zombie), 1.0e-6);

		$rider = $this->player(new Vector3(8.5, 1, 0.5), self::sword());
		$rider->fallDistance = 0.5;
		(new \ReflectionProperty(Entity::class, "vehicle"))->setValue($rider, $this->cow(new Vector3(8.5, 1, 0.5)));
		$target = $this->golem(new Vector3(8.5, 1, 2.5));
		self::assertEqualsWithDelta(VanillaItems::IRON_SWORD()->getAttackPoints(), self::hit($rider, $target), 1.0e-6);
	}

	public function testCriticalHitInsideTheInvulnerabilityWindowDealsTheDifference() : void{
		$player = $this->player(new Vector3(0.5, 1, 0.5), self::sword());
		$zombie = $this->golem(new Vector3(0.5, 1, 2.5));
		$damage = VanillaItems::IRON_SWORD()->getAttackPoints();

		self::assertEqualsWithDelta($damage, self::hit($player, $zombie), 1.0e-6);
		self::assertSame(0.0, self::hit($player, $zombie), "the same hit again is cancelled");

		$player->fallDistance = 0.5;
		self::assertEqualsWithDelta($damage * 0.5, self::hit($player, $zombie), 1.0e-6, "a critical hit lands for the extra 50%");
		self::assertSame(0.0, self::hit($player, $zombie), "the next critical hit is no stronger than the last one");
	}

	public function testHoeDamageByTier() : void{
		self::assertSame(2, VanillaItems::WOODEN_HOE()->getAttackPoints());
		self::assertSame(2, VanillaItems::GOLDEN_HOE()->getAttackPoints());
		self::assertSame(3, VanillaItems::STONE_HOE()->getAttackPoints());
		self::assertSame(4, VanillaItems::IRON_HOE()->getAttackPoints());
		self::assertSame(5, VanillaItems::DIAMOND_HOE()->getAttackPoints());
		self::assertSame(6, VanillaItems::NETHERITE_HOE()->getAttackPoints());
	}

	public function testQuickChargeWeight() : void{
		self::assertSame(Rarity::UNCOMMON, VanillaEnchantments::QUICK_CHARGE()->getRarity());
		self::assertSame(5, VanillaEnchantments::QUICK_CHARGE()->getRarity());
	}

	public function testToolTypes() : void{
		foreach([VanillaBlocks::RAIL(), VanillaBlocks::POWERED_RAIL(), VanillaBlocks::DETECTOR_RAIL(), VanillaBlocks::ACTIVATOR_RAIL()] as $rail){
			self::assertSame(BlockToolType::PICKAXE, $rail->getBreakInfo()->getToolType(), $rail->getName());
		}
		self::assertSame(BlockToolType::HOE, VanillaBlocks::HAY_BALE()->getBreakInfo()->getToolType());
		self::assertSame(BlockToolType::HOE, VanillaBlocks::DRIED_KELP()->getBreakInfo()->getToolType());
		self::assertSame(BlockToolType::AXE, VanillaBlocks::BIG_DRIPLEAF_HEAD()->getBreakInfo()->getToolType());
		self::assertSame(BlockToolType::AXE, VanillaBlocks::BIG_DRIPLEAF_STEM()->getBreakInfo()->getToolType());
		self::assertSame(BlockToolType::NONE, VanillaBlocks::GLOWSTONE()->getBreakInfo()->getToolType());

		//glowstone still drops its dust by hand, rails still drop by hand
		self::assertNotEmpty(VanillaBlocks::GLOWSTONE()->getDrops(VanillaItems::AIR()));
		self::assertNotEmpty(VanillaBlocks::RAIL()->getDrops(VanillaItems::AIR()));
	}

	public function testConduitPowerSpeedsUpMiningLikeHaste() : void{
		$this->set(0, 1, 3, VanillaBlocks::STONE());
		$player = $this->player(new Vector3(0.5, 1, 0.5), VanillaItems::IRON_PICKAXE());
		$player->onGround = true;
		$progress = function() use ($player) : float{
			$handler = new SurvivalBlockBreakHandler($player, new Vector3(0, 1, 3), $this->get(0, 1, 3), 0, 16);
			$speed = (new \ReflectionProperty(SurvivalBlockBreakHandler::class, "breakSpeed"))->getValue($handler);
			self::assertIsFloat($speed);
			return $speed;
		};
		$plain = $progress();

		$player->getEffects()->add(new EffectInstance(VanillaEffects::CONDUIT_POWER(), 600, 0));
		$conduit = $progress();
		$player->getEffects()->clear();
		$player->getEffects()->add(new EffectInstance(VanillaEffects::HASTE(), 600, 0));
		$haste = $progress();

		self::assertGreaterThan($plain, $conduit);
		self::assertEqualsWithDelta($haste, $conduit, 1.0e-9);

		$player->getEffects()->add(new EffectInstance(VanillaEffects::CONDUIT_POWER(), 600, 1));
		self::assertGreaterThan($haste, $progress(), "the higher of Haste and Conduit Power applies");
	}
}

/**
 * A player without the network and hunger side effects that the test doesn't set up.
 */
final class MeleeNumbersTestPlayer extends Player{
	protected function applyPostDamageEffects(EntityDamageEvent $source) : void{
		//NOOP
	}

	public function setSprinting(bool $value = true) : void{
		(new \ReflectionProperty(Living::class, "sprinting"))->setValue($this, $value);
	}
}
