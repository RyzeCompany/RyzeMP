<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\entity\object\LightningBolt;
use pocketmine\world\GameRules;
use pocketmine\world\World;

final class LightningPhysicsRulesTest extends TestCase{
	public function testFireRulesRespectDifficultyAndGamerule() : void{
		self::assertFalse(LightningBolt::mayIgnite(false, World::DIFFICULTY_HARD));
		self::assertFalse(LightningBolt::mayIgnite(true, World::DIFFICULTY_EASY));
		self::assertTrue(LightningBolt::mayIgnite(true, World::DIFFICULTY_NORMAL));
	}

	public function testCopperWaxPreventsLightningCleaning() : void{
		$rod = VanillaBlocks::LIGHTNING_ROD();
		$rod->setOxidation(CopperOxidation::OXIDIZED);
		self::assertTrue(LightningBolt::isCleanableCopper($rod));
		$rod->setWaxed(true);
		self::assertFalse(LightningBolt::isCleanableCopper($rod));
		self::assertFalse(LightningBolt::isCleanableCopper(VanillaBlocks::STONE()));
	}

	public function testFireTickRuleIsPersistent() : void{
		$path = sys_get_temp_dir() . "/ryze-fire-rule-" . bin2hex(random_bytes(8)) . ".yml";
		try{
			$rules = new GameRules($path);
			self::assertTrue($rules->getBool(GameRules::DO_FIRE_TICK));
			$rules->setBool(GameRules::DO_FIRE_TICK, false);
			self::assertFalse((new GameRules($path))->getBool(GameRules::DO_FIRE_TICK));
		}finally{
			if(is_file($path)){ unlink($path); }
		}
	}
}
