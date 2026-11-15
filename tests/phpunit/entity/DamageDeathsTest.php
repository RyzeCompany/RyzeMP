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

namespace pocketmine\entity;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\monster\Blaze;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\WitherSkull;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\world\GameRules;
use function max;
use function min;
use function mt_srand;

/**
 * Damage causes (armour, protection enchantments, fire rules per cause), death messages and wither roses.
 */
final class DamageDeathsTest extends MobTestCase{

	protected function tearDown() : void{
		mt_srand();
		parent::tearDown();
	}

	private function zombie(float $x = 0.5, float $z = 0.5) : Zombie{
		return new Zombie(new Location($x, 1, $z, $this->world, 0, 0));
	}

	private static function wearDiamond(Living $entity, ?EnchantmentInstance $enchantment = null) : void{
		$armor = $entity->getArmorInventory();
		foreach([VanillaItems::DIAMOND_HELMET(), VanillaItems::DIAMOND_CHESTPLATE(), VanillaItems::DIAMOND_LEGGINGS(), VanillaItems::DIAMOND_BOOTS()] as $slot => $item){
			if($enchantment !== null){
				$item->addEnchantment($enchantment);
			}
			$armor->setItem($slot, $item);
		}
	}

	public function testBedrockProtectionHasNoRandomPart() : void{
		$zombie = $this->zombie();
		self::wearDiamond($zombie, new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 4));
		for($i = 0; $i < 20; ++$i){
			//BDS 1.26.60: an iron sword (7) on full diamond armour with Protection IV on every piece: 0.68
			$ev = new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 7);
			$zombie->applyDamageModifiers($ev);
			self::assertEqualsWithDelta(0.6804, $ev->getFinalDamage(), 0.001);
		}
		$zombie->close();

		//Feather Falling IV: 3 points per level against falls, 4 pieces would pass the 20 point cap
		$zombie = $this->zombie();
		$zombie->getArmorInventory()->setBoots(VanillaItems::LEATHER_BOOTS()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FEATHER_FALLING(), 4)));
		$ev = new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_FALL, 10);
		$zombie->applyDamageModifiers($ev);
		self::assertEqualsWithDelta(-10 * 0.48, $ev->getModifier(EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS), 1.0e-9);
		$zombie->close();
	}

	public function testCriticalArrowDamage() : void{
		$getHitDamage = new \ReflectionMethod(Arrow::class, "getHitDamage");
		$arrow = new Arrow(new Location(0.5, 5, 0.5, $this->world, 0, 0), null, true);
		$arrow->setMotion(new Vector3(0, 0, 3));
		$min = $max = null;
		for($i = 0; $i < 300; ++$i){
			$damage = $getHitDamage->invoke($arrow);
			self::assertIsFloat($damage);
			//speed 3 x 2 = 6, plus up to 6 / 2 + 2 (BDS 1.26.60: 6.3 to 10.9 at 6 blocks)
			self::assertGreaterThanOrEqual(6.0, $damage);
			self::assertLessThan(11.0, $damage);
			$min = $min === null ? $damage : min($min, $damage);
			$max = $max === null ? $damage : max($max, $damage);
		}
		self::assertLessThan(7.0, $min);
		self::assertGreaterThan(10.0, $max);

		$arrow->setCritical(false);
		self::assertSame(6.0, $getHitDamage->invoke($arrow));
		$arrow->close();
	}

	public function testArrowStaysCriticalWhenItHitsAnEntity() : void{
		$zombie = $this->zombie(0.5, 3.5);
		$zombie->spawnToAll();
		$arrow = new Arrow(new Location(0.5, 2, 0.5, $this->world, 0, 0), null, true);
		$arrow->spawnToAll();
		$arrow->setMotion(new Vector3(0, 0, 3));
		mt_srand(1);
		$health = $zombie->getHealth();
		$this->runTicks(3);
		//onHit() used to clear the critical flag before onHitEntity() worked out the damage: always exactly 6
		self::assertGreaterThan(6.0, $health - $zombie->getHealth(), "a critical hit");
		if(!$zombie->isClosed()){
			$zombie->close();
		}
	}

	public function testFireCauses() : void{
		foreach([
			EntityDamageEvent::CAUSE_FIRE,
			EntityDamageEvent::CAUSE_FIRE_TICK,
			EntityDamageEvent::CAUSE_LAVA,
			EntityDamageEvent::CAUSE_MAGMA,
			EntityDamageEvent::CAUSE_CAMPFIRE,
			EntityDamageEvent::CAUSE_FIREBALL
		] as $cause){
			self::assertTrue(EntityDamageEvent::isFireCause($cause), "cause $cause");
		}
		foreach([
			EntityDamageEvent::CAUSE_PROJECTILE,
			EntityDamageEvent::CAUSE_WITHER_SKULL,
			EntityDamageEvent::CAUSE_MAGIC,
			EntityDamageEvent::CAUSE_LIGHTNING,
			EntityDamageEvent::CAUSE_FREEZE
		] as $cause){
			self::assertFalse(EntityDamageEvent::isFireCause($cause), "cause $cause");
		}
		self::assertTrue(EntityDamageEvent::isProjectileCause(EntityDamageEvent::CAUSE_FIREBALL));
		self::assertTrue(EntityDamageEvent::isProjectileCause(EntityDamageEvent::CAUSE_WITHER_SKULL));
		self::assertFalse(EntityDamageEvent::isProjectileCause(EntityDamageEvent::CAUSE_THORNS));
	}

	public function testArmourAndProtectionPerCause() : void{
		mt_srand(1);
		$zombie = $this->zombie();
		self::wearDiamond($zombie, new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 4));
		$modifiers = function(int $cause) use ($zombie) : EntityDamageEvent{
			$ev = new EntityDamageEvent($zombie, $cause, 10);
			$zombie->applyDamageModifiers($ev);
			return $ev;
		};

		$thorns = $modifiers(EntityDamageEvent::CAUSE_THORNS);
		self::assertLessThan(0, $thorns->getModifier(EntityDamageEvent::MODIFIER_ARMOR), "thorns is reduced by armour");

		foreach([EntityDamageEvent::CAUSE_WITHER, EntityDamageEvent::CAUSE_DRAGON_BREATH, EntityDamageEvent::CAUSE_STALAGMITE] as $cause){
			$ev = $modifiers($cause);
			self::assertFalse($ev->isApplicable(EntityDamageEvent::MODIFIER_ARMOR), "cause $cause bypasses armour");
			self::assertLessThan(0, $ev->getModifier(EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS), "cause $cause: Protection still applies");
		}

		$sonicBoom = $modifiers(EntityDamageEvent::CAUSE_SONIC_BOOM);
		self::assertFalse($sonicBoom->isApplicable(EntityDamageEvent::MODIFIER_ARMOR));
		self::assertFalse($sonicBoom->isApplicable(EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS), "the sonic boom bypasses protection enchantments");
		self::assertSame(10.0, $sonicBoom->getFinalDamage());

		$stalactite = $modifiers(EntityDamageEvent::CAUSE_STALACTITE);
		self::assertLessThan(0, $stalactite->getModifier(EntityDamageEvent::MODIFIER_ARMOR_HELMET), "a helmet protects from stalactites");
		$zombie->close();
	}

	public function testFireProtectionReducesFireballs() : void{
		mt_srand(1);
		$zombie = $this->zombie();
		self::wearDiamond($zombie, new EnchantmentInstance(VanillaEnchantments::FIRE_PROTECTION(), 4));
		foreach([EntityDamageEvent::CAUSE_FIREBALL, EntityDamageEvent::CAUSE_MAGMA, EntityDamageEvent::CAUSE_CAMPFIRE] as $cause){
			$ev = new EntityDamageEvent($zombie, $cause, 5);
			$zombie->applyDamageModifiers($ev);
			self::assertLessThan(0, $ev->getModifier(EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS), "cause $cause");
		}
		$ev = new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_PROJECTILE, 5);
		$zombie->applyDamageModifiers($ev);
		self::assertSame(0.0, $ev->getModifier(EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS), "not an arrow");
		$zombie->close();
	}

	public function testFireResistanceBlocksFireballs() : void{
		$zombie = $this->zombie();
		$zombie->getEffects()->add(new EffectInstance(VanillaEffects::FIRE_RESISTANCE(), 600));
		foreach([EntityDamageEvent::CAUSE_FIREBALL, EntityDamageEvent::CAUSE_MAGMA, EntityDamageEvent::CAUSE_CAMPFIRE] as $cause){
			$ev = new EntityDamageEvent($zombie, $cause, 5);
			$zombie->attack($ev);
			self::assertTrue($ev->isCancelled(), "cause $cause");
		}
		$ev = new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_WITHER_SKULL, 5);
		$zombie->attack($ev);
		self::assertFalse($ev->isCancelled(), "a wither skull is not fire");
		$zombie->close();
	}

	public function testFireImmuneMobsIgnoreFireballs() : void{
		$blaze = new Blaze(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$ev = new EntityDamageEvent($blaze, EntityDamageEvent::CAUSE_FIREBALL, 5);
		$blaze->attack($ev);
		self::assertTrue($ev->isCancelled());
		$blaze->close();
	}

	public function testFireDamageGameRuleCoversNewFireCauses() : void{
		$zombie = $this->zombie();
		$this->world->setGameRule(GameRules::FIRE_DAMAGE, false);
		$this->world->setGameRule(GameRules::FALL_DAMAGE, false);
		try{
			self::assertFalse(GameRules::allowsPlayerDamage($this->world, new EntityDamageByBlockEvent(VanillaBlocks::MAGMA(), $zombie, EntityDamageEvent::CAUSE_MAGMA, 1)));
			self::assertFalse(GameRules::allowsPlayerDamage($this->world, new EntityDamageByBlockEvent(VanillaBlocks::CAMPFIRE(), $zombie, EntityDamageEvent::CAUSE_CAMPFIRE, 1)));
			$blaze = new Blaze(new Location(4.5, 1, 4.5, $this->world, 0, 0));
			$fireball = new SmallFireball(new Location(1.5, 1, 1.5, $this->world, 0, 0), $blaze);
			self::assertFalse(GameRules::allowsPlayerDamage($this->world, new EntityDamageByChildEntityEvent($blaze, $fireball, $zombie, EntityDamageEvent::CAUSE_FIREBALL, 5)));
			self::assertFalse(GameRules::allowsPlayerDamage($this->world, new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_STALAGMITE, 5)), "falldamage");
			self::assertTrue(GameRules::allowsPlayerDamage($this->world, new EntityDamageEvent($zombie, EntityDamageEvent::CAUSE_WITHER, 1)));
			$fireball->close();
			$blaze->close();
		}finally{
			$this->world->setGameRule(GameRules::FIRE_DAMAGE, true);
			$this->world->setGameRule(GameRules::FALL_DAMAGE, true);
		}
		$zombie->close();
	}

	private static function hitCause(Projectile $projectile) : int{
		$cause = (new \ReflectionMethod(Projectile::class, "getHitDamageCause"))->invoke($projectile);
		self::assertIsInt($cause);
		return $cause;
	}

	public function testProjectileHitCauses() : void{
		$loc = new Location(0.5, 1, 0.5, $this->world, 0, 0);
		$fireball = new SmallFireball($loc, null);
		$skull = new WitherSkull($loc, null);
		$arrow = new Arrow($loc, null, false);
		self::assertSame(EntityDamageEvent::CAUSE_FIREBALL, self::hitCause($fireball));
		self::assertSame(EntityDamageEvent::CAUSE_WITHER_SKULL, self::hitCause($skull));
		self::assertSame(EntityDamageEvent::CAUSE_PROJECTILE, self::hitCause($arrow));
		$fireball->close();
		$skull->close();
		$arrow->close();
	}

	public function testEnvironmentDeathMessages() : void{
		$zombie = $this->zombie();
		$keys = [
			EntityDamageEvent::CAUSE_FIRE => "death.attack.inFire",
			EntityDamageEvent::CAUSE_CAMPFIRE => "death.attack.inFire",
			EntityDamageEvent::CAUSE_FIRE_TICK => "death.attack.onFire",
			EntityDamageEvent::CAUSE_LAVA => "death.attack.lava",
			EntityDamageEvent::CAUSE_MAGMA => "death.attack.magma",
			EntityDamageEvent::CAUSE_STARVATION => "death.attack.starve",
			EntityDamageEvent::CAUSE_WITHER => "death.attack.wither",
			EntityDamageEvent::CAUSE_SWEET_BERRY_BUSH => "death.attack.sweetBerry",
			EntityDamageEvent::CAUSE_STALACTITE => "death.attack.stalactite",
			EntityDamageEvent::CAUSE_STALAGMITE => "death.attack.stalagmite",
			EntityDamageEvent::CAUSE_SONIC_BOOM => "death.attack.sonicBoom",
			EntityDamageEvent::CAUSE_FREEZE => "death.attack.freeze",
			EntityDamageEvent::CAUSE_DRAGON_BREATH => "death.attack.magic",
			EntityDamageEvent::CAUSE_MAGIC => "death.attack.magic",
			EntityDamageEvent::CAUSE_DROWNING => "death.attack.drown"
		];
		foreach($keys as $cause => $key){
			$message = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageEvent($zombie, $cause, 1));
			self::assertSame($key, $message->getText(), "cause $cause");
			self::assertSame(["Steve"], $message->getParameters());
		}
		$bed = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByBlockEvent(VanillaBlocks::BED(), $zombie, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 10));
		self::assertSame("death.attack.explosion.by.bed", $bed->getText());
		$anchor = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByBlockEvent(VanillaBlocks::RESPAWN_ANCHOR(), $zombie, EntityDamageEvent::CAUSE_BLOCK_EXPLOSION, 10));
		self::assertSame("death.attack.explosion.by.bed", $anchor->getText());
		$zombie->close();
	}

	public function testMobNamesAreTranslationKeys() : void{
		$victim = $this->zombie();
		$skeleton = new Skeleton(new Location(2.5, 1, 0.5, $this->world, 0, 0));
		$message = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($skeleton, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame("death.attack.mob", $message->getText());
		$killer = $message->getParameters()[1];
		self::assertInstanceOf(Translatable::class, $killer);
		self::assertSame("entity.skeleton.name", $killer->getText());

		$arrow = new Arrow(new Location(1.5, 1, 0.5, $this->world, 0, 0), $skeleton, false);
		$shot = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByChildEntityEvent($skeleton, $arrow, $victim, EntityDamageEvent::CAUSE_PROJECTILE, 1));
		self::assertSame("death.attack.arrow", $shot->getText());
		$killer = $shot->getParameters()[1];
		self::assertInstanceOf(Translatable::class, $killer);
		self::assertSame("entity.skeleton.name", $killer->getText());

		//an arrow nobody shot (dispenser) names the arrow itself
		$dispensed = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($arrow, $victim, EntityDamageEvent::CAUSE_PROJECTILE, 1));
		self::assertSame("death.attack.arrow", $dispensed->getText());
		$killer = $dispensed->getParameters()[1];
		self::assertInstanceOf(Translatable::class, $killer);
		self::assertSame("entity.arrow.name", $killer->getText());

		$skeleton->setNameTag("Bones");
		$named = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($skeleton, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame(["Steve", "Bones"], $named->getParameters(), "named mobs keep their name");

		$skeleton->setMainHandItem(VanillaItems::IRON_SWORD()->setCustomName("Sting"));
		$withWeapon = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($skeleton, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame("death.attack.mob.item", $withWeapon->getText());
		self::assertSame(["Steve", "Bones", "Sting"], $withWeapon->getParameters());

		$arrow->close();
		$skeleton->close();
		$victim->close();
	}

	public function testMobAttackDeathMessages() : void{
		$victim = $this->zombie();
		$blaze = new Blaze(new Location(2.5, 1, 0.5, $this->world, 0, 0));
		$fireball = new SmallFireball(new Location(1.5, 1, 0.5, $this->world, 0, 0), $blaze);
		$fireballed = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByChildEntityEvent($blaze, $fireball, $victim, EntityDamageEvent::CAUSE_FIREBALL, 5));
		self::assertSame("death.attack.fireball", $fireballed->getText());
		$dispensed = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($fireball, $victim, EntityDamageEvent::CAUSE_FIREBALL, 5));
		self::assertSame("death.attack.onFire", $dispensed->getText(), "fire charge from a dispenser");

		$evoker = new Evoker(new Location(3.5, 1, 0.5, $this->world, 0, 0));
		$fangs = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($evoker, $victim, EntityDamageEvent::CAUSE_MAGIC, 6));
		self::assertSame("death.attack.indirectMagic", $fangs->getText());

		$thorns = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByEntityEvent($evoker, $victim, EntityDamageEvent::CAUSE_THORNS, 2));
		self::assertSame("death.attack.thorns", $thorns->getText());

		$smash = new EntityDamageByEntityEvent($evoker, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 6);
		$smash->setModifier(12, EntityDamageEvent::MODIFIER_SMASH);
		self::assertSame("death.attack.maceSmash.player", PlayerDeathEvent::deriveMessage("Steve", $smash)->getText());

		$fireball->close();
		$blaze->close();
		$evoker->close();
		$victim->close();
	}

	public function testEscapeDeathMessages() : void{
		$victim = $this->zombie();
		$skeleton = new Skeleton(new Location(2.5, 1, 0.5, $this->world, 0, 0));
		$victim->attack(new EntityDamageByEntityEvent($skeleton, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
		self::assertSame($skeleton, $victim->getRecentAttacker());

		$lava = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageByBlockEvent(VanillaBlocks::LAVA(), $victim, EntityDamageEvent::CAUSE_LAVA, 4));
		self::assertSame("death.attack.lava.player", $lava->getText());
		self::assertCount(2, $lava->getParameters());
		$burnt = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageEvent($victim, EntityDamageEvent::CAUSE_FIRE_TICK, 1));
		self::assertSame("death.attack.onFire.player", $burnt->getText());
		$starved = PlayerDeathEvent::deriveMessage("Steve", new EntityDamageEvent($victim, EntityDamageEvent::CAUSE_STARVATION, 1));
		self::assertSame("death.attack.starve", $starved->getText(), "no escape variant");

		$skeleton->close();
		self::assertNull($victim->getRecentAttacker(), "a removed attacker loses the credit");
		$victim->close();
	}

	public function testHotSulfurCubeDeathMessage() : void{
		$hot = new SulfurCube(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$hot->absorbBlock(VanillaBlocks::MAGMA()->asItem());
		$cow = new Cow(new Location(1.6, 1, 0.5, $this->world, 0, 0));
		//how soon the first area attack comes depends on the entity IDs (staggered ticks): wait for it
		for($i = 0; $i < 40 && $cow->getLastDamageCause() === null; ++$i){
			$this->runTicks(1);
		}
		$cause = $cow->getLastDamageCause();
		self::assertNotNull($cause, "the hot cube burns the cow");
		self::assertSame(EntityDamageEvent::CAUSE_MAGMA, $cause->getCause());
		self::assertNull($cow->getRecentAttacker(), "the cube's area attack isn't credited to it");
		self::assertSame("death.attack.sulfurCube.hot", PlayerDeathEvent::deriveMessage("Steve", $cause)->getText());

		$magmaBlock = new EntityDamageByBlockEvent(VanillaBlocks::MAGMA(), $cow, EntityDamageEvent::CAUSE_MAGMA, 1);
		self::assertSame("death.attack.magma", PlayerDeathEvent::deriveMessage("Steve", $magmaBlock)->getText());
		$cow->close();
		$hot->close();
	}

	public function testClientTranslatesEntityNames() : void{
		$language = new Language(Language::FALLBACK_LANGUAGE);
		$prepare = new \ReflectionMethod(NetworkSession::class, "prepareClientTranslatableParameter");
		self::assertSame("%entity.skeleton.name", $prepare->invoke(null, $language, new Translatable("entity.skeleton.name")));
		self::assertSame("Skeleton", $language->translate(new Translatable("entity.skeleton.name")), "the server (console) has the English name");
		self::assertSame("Protection", $prepare->invoke(null, $language, VanillaEnchantments::PROTECTION()->getName()), "other nested translations are done by the server");
	}

	private function countWitherRoses() : int{
		$roses = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && $entity->getItem()->equals(VanillaBlocks::WITHER_ROSE()->asItem())){
				++$roses;
				$entity->close();
			}
		}
		return $roses;
	}

	public function testWitherKillDropsWitherRose() : void{
		$wither = new Wither(new Location(0.5, 1, 6.5, $this->world, 0, 0));
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$this->runTicks(1);
		$cow->attack(new EntityDamageByEntityEvent($wither, $cow, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1000));
		self::assertFalse($cow->isAlive());
		self::assertSame(1, $this->countWitherRoses());

		$skull = new WitherSkull(new Location(2.5, 1, 2.5, $this->world, 0, 0), $wither);
		$zombie = $this->zombie(3.5, 0.5);
		$this->runTicks(1);
		$zombie->attack(new EntityDamageByChildEntityEvent($wither, $skull, $zombie, EntityDamageEvent::CAUSE_WITHER_SKULL, 1000));
		self::assertSame(1, $this->countWitherRoses(), "killed by a skull");

		$blownUp = $this->zombie(3.5, 3.5);
		$this->runTicks(1);
		$blownUp->attack(new EntityDamageByEntityEvent($skull, $blownUp, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 1000));
		self::assertSame(1, $this->countWitherRoses(), "killed by the explosion of a skull, credited to its owner");

		$skeleton = new Skeleton(new Location(5.5, 1, 0.5, $this->world, 0, 0));
		$other = $this->zombie(-3.5, 0.5);
		$this->runTicks(1);
		$other->attack(new EntityDamageByEntityEvent($skeleton, $other, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1000));
		self::assertSame(0, $this->countWitherRoses(), "only the Wither's kills");

		$skull->close();
		$skeleton->close();
		$wither->close();
	}
}
