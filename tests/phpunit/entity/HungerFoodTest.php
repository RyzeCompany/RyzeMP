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
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\player\MovementCosts;
use function cos;
use function deg2rad;
use function mt_srand;
use function round;
use function sin;
use function str_repeat;

/**
 * Hunger and survival numbers of player.json: exhaustion of moving, saturation start and cap, air refill, Soul Speed
 * boots wear.
 */
class HungerFoodTest extends MobTestCase{

	private function human(float $x = 0.5, float $y = 1, float $z = 0.5) : Human{
		$human = new Human(new Location($x, $y, $z, $this->world, 0, 0), new Skin("Standard_Custom", str_repeat("\x00", 64 * 64 * 4)));
		$human->spawnToAll();
		return $human;
	}

	/**
	 * Moves through MovementCosts::exhaust() in steps of $step metres along the given direction and returns the
	 * exhaustion of each cause.
	 *
	 * @return float[] cause => exhaustion
	 * @phpstan-return array<int, float>
	 */
	private function walk(float $metres, float $step, float $yawDegrees, float $pitchDegrees, bool $swimming, bool $inWater, bool $sprinting, bool $onGround = true) : array{
		$exhaustion = [];
		$hunger = $this->createMock(HungerManager::class);
		$hunger->method("exhaust")->willReturnCallback(function(float $amount, int $cause) use (&$exhaustion) : float{
			$exhaustion[$cause] = ($exhaustion[$cause] ?? 0.0) + $amount;
			return $amount;
		});
		$horizontal = cos(deg2rad($pitchDegrees));
		$dx = -sin(deg2rad($yawDegrees)) * $horizontal * $step;
		$dz = cos(deg2rad($yawDegrees)) * $horizontal * $step;
		$dy = -sin(deg2rad($pitchDegrees)) * $step;
		$steps = (int) round($metres / $step);
		for($i = 0; $i < $steps; $i++){
			MovementCosts::exhaust($hunger, $dx, $dy, $dz, $swimming, $inWater, $sprinting, $onGround);
		}
		return $exhaustion;
	}

	public function testSprintCostsPointOnePerMetre() : void{
		//sprinting at 5.6 m/s moves about 0.28 m per tick
		$exhaustion = $this->walk(1000, 0.25, 37, 0, false, false, true);
		self::assertEqualsWithDelta(100.0, $exhaustion[PlayerExhaustEvent::CAUSE_SPRINTING] ?? 0, 0.001, "1000 m sprint = 100 exhaustion (25 food points)");
		self::assertCount(1, $exhaustion);
	}

	public function testSprintIgnoresVerticalMovement() : void{
		$exhaustion = $this->walk(100, 0.25, 0, -30, false, false, true);
		self::assertEqualsWithDelta(10.0 * cos(deg2rad(30)), $exhaustion[PlayerExhaustEvent::CAUSE_SPRINTING] ?? 0, 0.001);
	}

	public function testWalkingIsFree() : void{
		$exhaustion = $this->walk(100, 0.2, 90, 0, false, false, false);
		self::assertSame([PlayerExhaustEvent::CAUSE_WALKING => 0.0], $exhaustion);
	}

	public function testSwimmingCostsPointZeroOnePerMetre() : void{
		//swimming counts the 3D distance, diving included
		$exhaustion = $this->walk(100, 0.2, 120, 40, true, true, true);
		self::assertEqualsWithDelta(1.0, $exhaustion[PlayerExhaustEvent::CAUSE_SWIMMING] ?? 0, 0.0001, "100 m swim = 1 exhaustion");
		self::assertCount(1, $exhaustion, "sprint-swimming costs swimming exhaustion only");

		$exhaustion = $this->walk(10, 0.1, 0, 90, true, true, false);
		self::assertEqualsWithDelta(0.1, $exhaustion[PlayerExhaustEvent::CAUSE_SWIMMING] ?? 0, 0.0001, "swimming straight down");
	}

	public function testWadingCostsHorizontalDistance() : void{
		$exhaustion = $this->walk(100, 0.2, 0, -30, false, true, true);
		self::assertEqualsWithDelta(cos(deg2rad(30)), $exhaustion[PlayerExhaustEvent::CAUSE_SWIMMING] ?? 0, 0.0001);
		self::assertCount(1, $exhaustion);
	}

	public function testNoMovementCostsNothing() : void{
		$hunger = $this->createMock(HungerManager::class);
		$hunger->expects(self::never())->method("exhaust");
		MovementCosts::exhaust($hunger, 0, 0, 0, true, true, true, true);
		MovementCosts::exhaust($hunger, 0, 0, 0, false, true, true, true);
		MovementCosts::exhaust($hunger, 0, 0.5, 0, false, false, true, false); //falling or jumping
		MovementCosts::exhaust($hunger, 0, -0.5, 0, false, true, false, false);
	}

	public function testSprintingInTheAirCostsNothing() : void{
		$hunger = $this->createMock(HungerManager::class);
		$hunger->expects(self::never())->method("exhaust");
		//sprint jumping, a sprinting fall, or gliding with the sprint flag still set
		MovementCosts::exhaust($hunger, 0.28, 0.4, 0, false, false, true, false);
		MovementCosts::exhaust($hunger, 0.28, -0.5, 0.1, false, false, true, false);
		MovementCosts::exhaust($hunger, 1.5, -0.2, 1.0, false, false, true, false);
	}

	public function testWaterCostsEvenWhenNotOnGround() : void{
		$exhaustion = $this->walk(10, 0.1, 0, 0, false, true, true, false);
		self::assertEqualsWithDelta(0.1, $exhaustion[PlayerExhaustEvent::CAUSE_SWIMMING] ?? 0, 0.0001);
		self::assertCount(1, $exhaustion);
	}

	public function testSprintingDrainsSaturationThenFood() : void{
		$human = $this->human();
		$hunger = $human->getHungerManager();
		self::assertSame(20.0, $hunger->getFood());
		self::assertSame(5.0, $hunger->getSaturation());
		for($i = 0; $i < 4000; $i++){ //1120 m at 0.28 m per tick: 112 exhaustion, 28 points
			MovementCosts::exhaust($hunger, 0.28, 0, 0, false, false, true, true);
		}
		self::assertSame(0.0, $hunger->getSaturation());
		self::assertSame(0.0, $hunger->getFood(), "5 saturation + 20 food = 25 points = 100 exhaustion");
	}

	public function testSaturationStartsAtFive() : void{
		self::assertSame(5.0, AttributeFactory::getInstance()->mustGet(Attribute::SATURATION)->getDefaultValue());
		self::assertSame(20.0, AttributeFactory::getInstance()->mustGet(Attribute::SATURATION)->getMaxValue());

		$human = $this->human();
		self::assertSame(5.0, $human->getHungerManager()->getSaturation(), "new player");

		//respawning resets every attribute to its default
		$human->getHungerManager()->setSaturation(17);
		$attribute = $human->getAttributeMap()->get(Attribute::SATURATION);
		self::assertNotNull($attribute);
		$attribute->resetToDefault();
		self::assertSame(5.0, $human->getHungerManager()->getSaturation(), "respawned player");
	}

	public function testFoodSaturationIsCappedAtFoodLevel() : void{
		$human = $this->human();
		$hunger = $human->getHungerManager();
		$hunger->setFood(2);
		$hunger->setSaturation(0);
		self::assertTrue($human->consumeObject(VanillaItems::GOLDEN_CARROT()));
		self::assertSame(8.0, $hunger->getFood());
		self::assertSame(8.0, $hunger->getSaturation(), "14.4 saturation capped at 8 food");

		$hunger->setFood(10);
		$hunger->setSaturation(1);
		self::assertTrue($human->consumeObject(VanillaItems::BREAD()));
		self::assertSame(15.0, $hunger->getFood());
		self::assertEqualsWithDelta(7.0, $hunger->getSaturation(), 0.0001, "below the cap: 1 + 6");
	}

	public function testSaturationEffectIsCappedAtFoodLevel() : void{
		$human = $this->human();
		$hunger = $human->getHungerManager();
		$hunger->setFood(1);
		$hunger->setSaturation(0);
		VanillaEffects::SATURATION()->applyEffect($human, new EffectInstance(VanillaEffects::SATURATION(), 1, 4));
		self::assertSame(6.0, $hunger->getFood());
		self::assertSame(6.0, $hunger->getSaturation(), "10 saturation capped at 6 food");
	}

	public function testAirRefillsInSeventyFiveTicks() : void{
		$human = $this->human();
		$human->setAirSupplyTicks(0);
		$human->setBreathing(false);
		$tick = new \ReflectionMethod(Living::class, "doAirSupplyTick");
		for($i = 0; $i < 74; $i++){
			$tick->invoke($human, 1);
		}
		self::assertSame(296, $human->getAirSupplyTicks());
		self::assertFalse($human->isBreathing());
		$tick->invoke($human, 1);
		self::assertSame(300, $human->getAirSupplyTicks(), "300 air in 75 ticks (inhale_time 3.75 s)");
		self::assertTrue($human->isBreathing());
	}

	private function wear(int $tries, int $unbreaking = 0) : int{
		$human = $this->human(0.5, 1, 0.5);
		$boots = VanillaItems::DIAMOND_BOOTS()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SOUL_SPEED(), 3));
		if($unbreaking > 0){
			$boots->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), $unbreaking));
		}
		$human->getArmorInventory()->setBoots($boots);
		mt_srand(0x50);
		for($i = 0; $i < $tries; $i++){
			MovementCosts::wearSoulSpeedBoots($human);
		}
		mt_srand();
		$worn = $human->getArmorInventory()->getBoots();
		self::assertInstanceOf(Durable::class, $worn);
		return $worn->getDamage();
	}

	public function testSoulSpeedBootsWearOnSoulBlocks() : void{
		$this->set(0, 0, 0, VanillaBlocks::SOUL_SOIL());
		$damage = $this->wear(5000);
		self::assertGreaterThan(160, $damage, "4% of 5000 = 200");
		self::assertLessThan(240, $damage);

		$withUnbreaking = $this->wear(5000, 3);
		//armour with Unbreaking III loses durability 60% + 40% / 4 = 70% of the time
		self::assertLessThan($damage * 0.85, $withUnbreaking, "Unbreaking reduces the wear");
		self::assertGreaterThan($damage * 0.55, $withUnbreaking);
	}

	public function testSoulSpeedBootsWearOnSoulSand() : void{
		//soul sand is 14 pixels tall: the wearer stands at y + 0.875
		$this->set(0, 1, 0, VanillaBlocks::SOUL_SAND());
		$human = $this->human(0.5, 1.875, 0.5);
		$human->getArmorInventory()->setBoots(VanillaItems::IRON_BOOTS()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SOUL_SPEED(), 1)));
		mt_srand(0x50);
		for($i = 0; $i < 1000; $i++){
			MovementCosts::wearSoulSpeedBoots($human);
		}
		mt_srand();
		$boots = $human->getArmorInventory()->getBoots();
		self::assertInstanceOf(Durable::class, $boots);
		self::assertGreaterThan(20, $boots->getDamage());
	}

	public function testSoulSpeedBootsDontWearElsewhere() : void{
		self::assertSame(0, $this->wear(2000), "on stone");
	}
}
