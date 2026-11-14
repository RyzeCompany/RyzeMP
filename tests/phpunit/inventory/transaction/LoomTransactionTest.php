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

namespace pocketmine\inventory\transaction;

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\BannerPatternLayer;
use pocketmine\block\utils\BannerPatternType;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\Banner;
use pocketmine\item\VanillaItems;

final class LoomTransactionTest extends TestCase{

	public function testAddsALayer() : void{
		$banner = VanillaItems::BANNER()->setColor(DyeColor::WHITE)->setCount(3);
		$result = LoomTransaction::calculateResult($banner, VanillaItems::DYE()->setColor(DyeColor::RED), null, BannerPatternType::STRIPE_TOP);
		self::assertInstanceOf(Banner::class, $result);
		self::assertSame(1, $result->getCount());
		$layers = $result->getPatterns();
		self::assertCount(1, $layers);
		self::assertSame(BannerPatternType::STRIPE_TOP, $layers[0]->getType());
		self::assertSame(DyeColor::RED, $layers[0]->getColor());
	}

	public function testPatternItems() : void{
		$banner = VanillaItems::BANNER();
		$dye = VanillaItems::DYE()->setColor(DyeColor::BLACK);
		self::assertNull(LoomTransaction::calculateResult($banner, $dye, null, BannerPatternType::CREEPER), "needs the creeper pattern item");
		self::assertNull(LoomTransaction::calculateResult($banner, $dye, VanillaItems::SKULL_BANNER_PATTERN(), BannerPatternType::CREEPER));
		self::assertNotNull(LoomTransaction::calculateResult($banner, $dye, VanillaItems::CREEPER_BANNER_PATTERN(), BannerPatternType::CREEPER));
		self::assertNotNull(LoomTransaction::calculateResult($banner, $dye, VanillaItems::FIELD_MASONED_BANNER_PATTERN(), BannerPatternType::BRICKS));
	}

	public function testLayerLimitAndInputs() : void{
		$layers = [];
		for($i = 0; $i < LoomTransaction::MAX_LAYERS; ++$i){
			$layers[] = new BannerPatternLayer(BannerPatternType::BORDER, DyeColor::BLUE);
		}
		$full = VanillaItems::BANNER()->setPatterns($layers);
		$dye = VanillaItems::DYE()->setColor(DyeColor::BLACK);
		self::assertNull(LoomTransaction::calculateResult($full, $dye, null, BannerPatternType::CROSS));
		self::assertNull(LoomTransaction::calculateResult(VanillaItems::DIAMOND(), $dye, null, BannerPatternType::CROSS));
		self::assertNull(LoomTransaction::calculateResult(VanillaItems::BANNER(), VanillaItems::DIAMOND(), null, BannerPatternType::CROSS));
	}
}
