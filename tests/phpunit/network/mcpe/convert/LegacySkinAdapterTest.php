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
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use function str_repeat;

class LegacySkinAdapterTest extends TestCase{

	private const PATCH = '{"geometry":{"default":"geometry.humanoid.custom","animated_face":"geometry.animated_face"}}';

	private static function faceAnimation() : SkinAnimation{
		return new SkinAnimation(new SkinImage(64, 32, str_repeat("\x10", 64 * 32 * 4)), SkinAnimation::TYPE_HEAD, 2.0, 1);
	}

	public function testPersonaSkinIsSentAsTheClientSentIt() : void{
		$adapter = new LegacySkinAdapter();
		$data = new SkinData("persona-test", "", self::PATCH, new SkinImage(256, 256, str_repeat("\x00", 256 * 256 * 4)),
			[self::faceAnimation()],
			personaPieces: [new PersonaSkinPiece("a0f263b3-e093-4c85-aadb-3759417898ff", "persona_eyes", "2099de18-429a-465a-a49b-fc4710a17bb3", true, "")],
			pieceTintColors: [new PersonaPieceTintColor("persona_eyes", ["#ff523d89", "#ff2e180e", "#ffe9ecec", "#0"])],
			persona: true
		);
		$skin = $adapter->fromSkinData($data);
		//PocketMine's Skin can't hold a persona: the server side still gets the placeholder
		self::assertSame("Standard_Custom", $skin->getSkinId());
		//but the other players get the persona, not a random colour
		self::assertSame($data, $adapter->toSkinData($skin));
	}

	public function testClassicSkinKeepsItsFaceAnimation() : void{
		$adapter = new LegacySkinAdapter();
		$data = new SkinData("classic-test", "", self::PATCH, new SkinImage(64, 64, str_repeat("\xff", 64 * 64 * 4)), [self::faceAnimation()], armSize: SkinData::ARM_SIZE_SLIM);
		$skin = $adapter->fromSkinData($data);
		self::assertSame("classic-test", $skin->getSkinId());
		$sent = $adapter->toSkinData($skin);
		self::assertSame($data, $sent);
		self::assertCount(1, $sent->getAnimations());
		self::assertSame(SkinData::ARM_SIZE_SLIM, $sent->getArmSize());
	}

	public function testSkinsMadeByPluginsAreConverted() : void{
		$adapter = new LegacySkinAdapter();
		$data = new SkinData("classic-test", "", self::PATCH, new SkinImage(64, 64, str_repeat("\xff", 64 * 64 * 4)), [self::faceAnimation()]);
		$adapter->fromSkinData($data);

		//same contents, but a Skin of its own: built from the Skin's own data
		$pluginSkin = new Skin("classic-test", str_repeat("\xff", 64 * 64 * 4), "", "geometry.humanoid.custom");
		$sent = $adapter->toSkinData($pluginSkin);
		self::assertNotSame($data, $sent);
		self::assertSame("classic-test", $sent->getSkinId());
		self::assertSame([], $sent->getAnimations());
	}
}
