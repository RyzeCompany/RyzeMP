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

use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\data\bedrock\EnchantmentIds;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

final class MissingEnchantmentsTest extends MobTestCase{

	/**
	 * @phpstan-return list<array{int, string, \Closure() : Enchantment}>
	 */
	public static function enchantments() : array{
		return [
			[EnchantmentIds::SMITE, "smite", fn() => VanillaEnchantments::SMITE()],
			[EnchantmentIds::BANE_OF_ARTHROPODS, "bane_of_arthropods", fn() => VanillaEnchantments::BANE_OF_ARTHROPODS()],
			[EnchantmentIds::DEPTH_STRIDER, "depth_strider", fn() => VanillaEnchantments::DEPTH_STRIDER()],
			[EnchantmentIds::LUCK_OF_THE_SEA, "luck_of_the_sea", fn() => VanillaEnchantments::LUCK_OF_THE_SEA()],
			[EnchantmentIds::LURE, "lure", fn() => VanillaEnchantments::LURE()],
			[EnchantmentIds::IMPALING, "impaling", fn() => VanillaEnchantments::IMPALING()],
			[EnchantmentIds::RIPTIDE, "riptide", fn() => VanillaEnchantments::RIPTIDE()],
			[EnchantmentIds::LOYALTY, "loyalty", fn() => VanillaEnchantments::LOYALTY()],
			[EnchantmentIds::PIERCING, "piercing", fn() => VanillaEnchantments::PIERCING()],
			[EnchantmentIds::BINDING, "binding", fn() => VanillaEnchantments::BINDING()],
		];
	}

	public function testRegisteredAndSurviveASaveRoundTrip() : void{
		foreach(self::enchantments() as [$id, $name, $get]){
			$enchantment = $get();
			self::assertSame($enchantment, EnchantmentIdMap::getInstance()->fromId($id), $name);
			self::assertSame($enchantment, StringToEnchantmentParser::getInstance()->parse($name), $name);
			$book = VanillaItems::ENCHANTED_BOOK()->addEnchantment(new EnchantmentInstance($enchantment, 1));
			self::assertTrue(Item::nbtDeserialize($book->nbtSerialize())->hasEnchantment($enchantment), $name);
		}
	}

	public function testOfferedForTheRightItems() : void{
		$registry = AvailableEnchantmentRegistry::getInstance();
		self::assertTrue($registry->isAvailableForItem(VanillaEnchantments::SMITE(), VanillaItems::DIAMOND_SWORD()));
		self::assertTrue($registry->isAvailableForItem(VanillaEnchantments::DEPTH_STRIDER(), VanillaItems::DIAMOND_BOOTS()));
		self::assertTrue($registry->isAvailableForItem(VanillaEnchantments::LURE(), VanillaItems::FISHING_ROD()));
		self::assertTrue($registry->isAvailableForItem(VanillaEnchantments::LOYALTY(), VanillaItems::TRIDENT()));
		self::assertTrue($registry->isAvailableForItem(VanillaEnchantments::PIERCING(), VanillaItems::CROSSBOW()));
		self::assertFalse($registry->isAvailableForItem(VanillaEnchantments::PIERCING(), VanillaItems::BOW()));
	}

	public function testIncompatibilities() : void{
		$registry = IncompatibleEnchantmentRegistry::getInstance();
		self::assertFalse($registry->areCompatible(VanillaEnchantments::SMITE(), VanillaEnchantments::SHARPNESS()));
		self::assertFalse($registry->areCompatible(VanillaEnchantments::SMITE(), VanillaEnchantments::BANE_OF_ARTHROPODS()));
		self::assertFalse($registry->areCompatible(VanillaEnchantments::DEPTH_STRIDER(), VanillaEnchantments::FROST_WALKER()));
		self::assertFalse($registry->areCompatible(VanillaEnchantments::RIPTIDE(), VanillaEnchantments::LOYALTY()));
		self::assertFalse($registry->areCompatible(VanillaEnchantments::RIPTIDE(), VanillaEnchantments::CHANNELING()));
		self::assertTrue($registry->areCompatible(VanillaEnchantments::LOYALTY(), VanillaEnchantments::CHANNELING()));
		self::assertFalse($registry->areCompatible(VanillaEnchantments::PIERCING(), VanillaEnchantments::MULTISHOT()));
	}

	public function testDamageBonusTargets() : void{
		$zombie = new Zombie(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$spider = new Spider(new Location(3.5, 1, 0.5, $this->world, 0, 0));
		$cow = new Cow(new Location(6.5, 1, 0.5, $this->world, 0, 0));
		$smite = VanillaEnchantments::SMITE();
		$bane = VanillaEnchantments::BANE_OF_ARTHROPODS();
		self::assertTrue($smite->isApplicableTo($zombie));
		self::assertFalse($smite->isApplicableTo($spider));
		self::assertTrue($bane->isApplicableTo($spider));
		self::assertFalse($bane->isApplicableTo($cow));
		self::assertEqualsWithDelta(12.5, $smite->getDamageBonus(5), 0.001);
		$bane->onPostAttack($zombie, $spider, 1);
		self::assertTrue($spider->getEffects()->has(VanillaEffects::SLOWNESS()));
		foreach([$zombie, $spider, $cow] as $entity){
			$entity->close();
		}
	}
}
