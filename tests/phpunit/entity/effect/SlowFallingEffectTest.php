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

use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\data\bedrock\EffectIds;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\item\PotionType;

final class SlowFallingEffectTest extends MobTestCase{

	public function testRegisteredAndInPotions() : void{
		self::assertSame(VanillaEffects::SLOW_FALLING(), EffectIdMap::getInstance()->fromId(EffectIds::SLOW_FALLING));
		self::assertSame(VanillaEffects::SLOW_FALLING(), StringToEffectParser::getInstance()->parse("slow_falling"));
		$effects = PotionType::SLOW_FALLING->getEffects();
		self::assertCount(1, $effects);
		self::assertSame(90 * 20, $effects[0]->getDuration());
	}

	public function testMobsFallSlowlyWithoutDamage() : void{
		$normal = new Cow(new Location(0.5, 20, 0.5, $this->world, 0, 0));
		$slow = new Cow(new Location(4.5, 20, 0.5, $this->world, 0, 0));
		$slow->getEffects()->add(new EffectInstance(VanillaEffects::SLOW_FALLING(), 2000));
		$this->runTicks(10);
		self::assertLessThan($slow->getPosition()->y, $normal->getPosition()->y);
		self::assertGreaterThan(18.5, $slow->getPosition()->y);
		$this->runTicks(400);
		self::assertEqualsWithDelta($slow->getMaxHealth(), $slow->getHealth(), 0.001, "no fall damage");
		$normal->close();
		$slow->close();
	}
}
