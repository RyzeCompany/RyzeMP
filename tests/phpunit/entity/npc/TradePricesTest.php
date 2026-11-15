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

namespace pocketmine\entity\npc;

use PHPUnit\Framework\TestCase;
use pocketmine\item\VanillaItems;

final class TradePricesTest extends TestCase{

	public function testNoEffectNoDiscount() : void{
		self::assertSame(14, TradePrices::applyHeroDiscount(14, 0));
	}

	public function testHeroOfTheVillageI() : void{
		//30% rounded down, at least 1
		self::assertSame(7, TradePrices::applyHeroDiscount(10, 1));
		self::assertSame(10, TradePrices::applyHeroDiscount(14, 1));
		self::assertSame(2, TradePrices::applyHeroDiscount(3, 1));
		self::assertSame(1, TradePrices::applyHeroDiscount(2, 1));
		self::assertSame(1, TradePrices::applyHeroDiscount(1, 1));
		self::assertSame(45, TradePrices::applyHeroDiscount(64, 1));
	}

	public function testHigherLevels() : void{
		//wiki example: level III, 14 emeralds -> 9
		self::assertSame(9, TradePrices::applyHeroDiscount(14, 3));
		//level V: 55%
		self::assertSame(5, TradePrices::applyHeroDiscount(10, 5));
	}

	public function testClientPriceKeepsDemand() : void{
		//the client gets the price raised by demand (sent with demand 0), the Hero discount is left to the client
		$offer = new TradeOffer(VanillaItems::CLAY()->setCount(10), null, VanillaItems::EMERALD(), 16, 0, 2, true, 0, 0.05, 0.0, 8);
		self::assertSame(14, TradePrices::getClientBuyACount($offer, null));
		self::assertSame(TradePrices::getBuyACount($offer, null), TradePrices::getClientBuyACount($offer, null));
	}
}
