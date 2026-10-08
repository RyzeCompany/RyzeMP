<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Facing;

final class HopperRedstoneTest extends TestCase{

    public function testDirectLeverAndButtonPower() : void{
        $lever = VanillaBlocks::LEVER();
        self::assertFalse(Hopper::isDirectPowerSource($lever, Facing::NORTH));
        $lever->setActivated(true);
        self::assertTrue(Hopper::isDirectPowerSource($lever, Facing::NORTH));

        $button = VanillaBlocks::STONE_BUTTON();
        self::assertFalse(Hopper::isDirectPowerSource($button, Facing::EAST));
        $button->setPressed(true);
        self::assertTrue(Hopper::isDirectPowerSource($button, Facing::EAST));
    }

    public function testDirectTorchAndWirePower() : void{
        $torch = VanillaBlocks::REDSTONE_TORCH();
        $torch->setLit(false);
        self::assertFalse(Hopper::isDirectPowerSource($torch, Facing::UP));
        $torch->setLit(true);
        self::assertTrue(Hopper::isDirectPowerSource($torch, Facing::UP));

        $wire = VanillaBlocks::REDSTONE_WIRE();
        self::assertFalse(Hopper::isDirectPowerSource($wire, Facing::SOUTH));
        $wire->setOutputSignalStrength(12);
        self::assertTrue(Hopper::isDirectPowerSource($wire, Facing::SOUTH));
    }

    public function testPoweredRepeaterIsDirectional() : void{
        $repeater = VanillaBlocks::REDSTONE_REPEATER();
        $repeater->setPowered(true);
        $repeater->setFacing(Facing::WEST);
        self::assertTrue(Hopper::isDirectPowerSource($repeater, Facing::EAST));
        self::assertFalse(Hopper::isDirectPowerSource($repeater, Facing::WEST));
        $repeater->setPowered(false);
        self::assertFalse(Hopper::isDirectPowerSource($repeater, Facing::EAST));
    }
}
