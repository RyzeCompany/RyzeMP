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

namespace pocketmine\entity\effect;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\EffectIdMap;

final class OmenEffectsTest extends TestCase{

	/**
	 * Bedrock effect IDs (minecraft.wiki effect pages, Nukkit's Effect table).
	 */
	public function testBedrockIds() : void{
		$map = EffectIdMap::getInstance();
		self::assertSame(28, $map->toId(VanillaEffects::BAD_OMEN()));
		self::assertSame(29, $map->toId(VanillaEffects::VILLAGE_HERO()));
		self::assertSame(32, $map->toId(VanillaEffects::WIND_CHARGED()));
		self::assertSame(33, $map->toId(VanillaEffects::WEAVING()));
		self::assertSame(34, $map->toId(VanillaEffects::OOZING()));
		self::assertSame(35, $map->toId(VanillaEffects::INFESTED()));
		self::assertSame(36, $map->toId(VanillaEffects::RAID_OMEN()));
		self::assertSame(VanillaEffects::RAID_OMEN(), $map->fromId(36));
		self::assertSame(31, $map->toId(VanillaEffects::TRIAL_OMEN()));
		self::assertSame(VanillaEffects::TRIAL_OMEN(), $map->fromId(31));
	}

	public function testCommandNames() : void{
		$parser = StringToEffectParser::getInstance();
		self::assertSame(VanillaEffects::RAID_OMEN(), $parser->parse("raid_omen"));
		self::assertSame(VanillaEffects::TRIAL_OMEN(), $parser->parse("trial_omen"));
		self::assertSame(VanillaEffects::VILLAGE_HERO(), $parser->parse("village_hero"));
		self::assertSame(VanillaEffects::VILLAGE_HERO(), $parser->parse("hero_of_the_village"));
	}

	public function testHeroOfTheVillageHasNoParticles() : void{
		self::assertFalse(VanillaEffects::VILLAGE_HERO()->hasBubbles());
		self::assertFalse(VanillaEffects::VILLAGE_HERO()->isBad());
	}
}
