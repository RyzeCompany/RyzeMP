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

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\item\FireworkRocketType;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData;

/**
 * Item types saved in a tag (ominous banners: banner with Type 1) must reach clients with that tag, and come back as
 * the same item.
 */
final class ItemTypeTagTest extends TestCase{

	public function testOminousBannerKeepsItsType() : void{
		$converter = TypeConverter::getInstance();
		$net = $converter->coreItemStackToNet(VanillaItems::OMINOUS_BANNER());
		$nbt = ItemStackExtraData::read(new ByteBufferReader($net->getRawExtraData()))->getNbt();
		self::assertNotNull($nbt);
		self::assertSame(1, $nbt->getInt("Type"));

		$back = $converter->netItemStackToCore($net);
		self::assertTrue($back->equals(VanillaItems::OMINOUS_BANNER()), "the Type tag is not left in the named tag");
	}

	public function testOminousBannerWithCustomName() : void{
		$converter = TypeConverter::getInstance();
		$item = VanillaItems::OMINOUS_BANNER()->setCustomName("Captain");
		$back = $converter->netItemStackToCore($converter->coreItemStackToNet($item));
		self::assertTrue($back->equals($item));
	}

	/**
	 * Firework rockets and stars can't lose their NBT (clearNamedTag() throws): they used to throw out of
	 * netItemStackToCore() whenever a client sent one.
	 */
	public function testFireworksFromClients() : void{
		$converter = TypeConverter::getInstance();
		$explosion = new FireworkRocketExplosion(FireworkRocketType::STAR, [DyeColor::RED], [DyeColor::BLUE], true, false);
		$rocket = VanillaItems::FIREWORK_ROCKET()->setFlightTimeMultiplier(2)->setExplosions([$explosion]);
		$star = VanillaItems::FIREWORK_STAR()->setExplosion($explosion);
		foreach([VanillaItems::FIREWORK_ROCKET(), $rocket, $star, (clone $rocket)->setCustomName("named")] as $item){
			$back = $converter->netItemStackToCore($converter->coreItemStackToNet($item));
			self::assertTrue($back->equals($item), (string) $item);
		}
	}

	public function testPlainBannerUnchanged() : void{
		$converter = TypeConverter::getInstance();
		$banner = VanillaItems::BANNER()->setColor(DyeColor::RED);
		$net = $converter->coreItemStackToNet($banner);
		self::assertNull(ItemStackExtraData::read(new ByteBufferReader($net->getRawExtraData()))->getNbt());
		self::assertTrue($converter->netItemStackToCore($net)->equals($banner));
	}
}
