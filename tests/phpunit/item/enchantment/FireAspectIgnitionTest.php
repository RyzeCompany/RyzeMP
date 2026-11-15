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

namespace pocketmine\item\enchantment;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

final class FireAspectIgnitionTest extends MobTestCase{

	private function pig() : Pig{
		$this->floor(-4, -4, 4, 4);
		$pig = new Pig(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$pig->spawnToAll();
		$pig->setHealth(1);
		return $pig;
	}

	/**
	 * @param Item[] $drops
	 */
	private static function hasItem(array $drops, Item $item) : bool{
		foreach($drops as $drop){
			if($drop->equals($item, false, false) && $drop->getCount() > 0){
				return true;
			}
		}
		return false;
	}

	public function testKillingHitDropsCookedFood() : void{
		$pig = $this->pig();
		$sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FIRE_ASPECT(), 1));
		self::assertTrue(FireAspectIgnition::beforeHit($pig, $sword));
		$pig->attack(new EntityDamageEvent($pig, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
		self::assertFalse($pig->isAlive());
		$drops = $pig->getDrops();
		self::assertTrue(self::hasItem($drops, VanillaItems::COOKED_PORKCHOP()));
		self::assertFalse(self::hasItem($drops, VanillaItems::RAW_PORKCHOP()));
	}

	public function testPlainWeaponDoesNotIgnite() : void{
		$pig = $this->pig();
		self::assertFalse(FireAspectIgnition::beforeHit($pig, VanillaItems::DIAMOND_SWORD()));
		self::assertFalse($pig->isOnFire());
		$pig->attack(new EntityDamageEvent($pig, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
		self::assertFalse(self::hasItem($pig->getDrops(), VanillaItems::COOKED_PORKCHOP()));
	}

	public function testCancelledHitPutsTheFireOut() : void{
		$pig = $this->pig();
		$sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FIRE_ASPECT(), 2));
		$ignited = FireAspectIgnition::beforeHit($pig, $sword);
		self::assertTrue($ignited);
		FireAspectIgnition::revert($pig, $ignited);
		self::assertSame(0, $pig->getFireTicks());
		self::assertTrue($pig->isAlive());
	}

	public function testBurningTargetIsLeftAlone() : void{
		$pig = $this->pig();
		$pig->setOnFire(8);
		$sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::FIRE_ASPECT(), 1));
		self::assertFalse(FireAspectIgnition::beforeHit($pig, $sword), "an existing fire is not reverted by a cancelled hit");
		FireAspectIgnition::revert($pig, false);
		self::assertTrue($pig->isOnFire());
	}
}
