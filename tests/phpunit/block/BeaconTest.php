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

namespace pocketmine\block;

use pocketmine\block\tile\Beacon as TileBeacon;
use pocketmine\block\tile\TileFactory;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\data\bedrock\EffectIds;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_map;

/**
 * Beacon pyramid, sky access, power selection rules and effects. Uses the in-memory world of the redstone tests.
 */
final class BeaconTest extends RedstoneTestCase{
	private const X = 0;
	private const Y = 20;
	private const Z = 0;

	/**
	 * Builds the given number of complete pyramid layers under the beacon position, alternating base block types.
	 */
	private function buildPyramid(int $levels) : void{
		$blocks = [VanillaBlocks::IRON(), VanillaBlocks::GOLD(), VanillaBlocks::EMERALD(), VanillaBlocks::DIAMOND(), VanillaBlocks::NETHERITE()];
		$i = 0;
		for($level = 1; $level <= $levels; ++$level){
			for($dx = -$level; $dx <= $level; ++$dx){
				for($dz = -$level; $dz <= $level; ++$dz){
					$this->set(self::X + $dx, self::Y - $level, self::Z + $dz, $blocks[$i++ % 5]);
				}
			}
		}
	}

	private function placeBeacon() : Beacon{
		$this->set(self::X, self::Y, self::Z, VanillaBlocks::BEACON());
		$beacon = $this->get(self::X, self::Y, self::Z);
		self::assertInstanceOf(Beacon::class, $beacon);
		return $beacon;
	}

	private function tile() : TileBeacon{
		$tile = $this->world->getTileAt(self::X, self::Y, self::Z);
		self::assertInstanceOf(TileBeacon::class, $tile);
		return $tile;
	}

	private function level() : int{
		return Beacon::calculatePyramidLevel($this->world, self::X, self::Y, self::Z);
	}

	public function testPyramidLevels() : void{
		self::assertSame(0, $this->level());
		for($levels = 1; $levels <= 4; ++$levels){
			$this->buildPyramid($levels);
			self::assertSame($levels, $this->level(), "pyramid with $levels layers");
		}
	}

	public function testPyramidIsCappedAtFour() : void{
		$this->buildPyramid(4);
		//a fifth layer does not count
		for($dx = -5; $dx <= 5; ++$dx){
			for($dz = -5; $dz <= 5; ++$dz){
				$this->set(self::X + $dx, self::Y - 5, self::Z + $dz, VanillaBlocks::IRON());
			}
		}
		self::assertSame(4, $this->level());
	}

	public function testIncompleteLayerStopsThePyramid() : void{
		$this->buildPyramid(3);
		//a missing corner of layer 2 leaves only layer 1
		$this->set(self::X + 2, self::Y - 2, self::Z - 2, VanillaBlocks::STONE());
		self::assertSame(1, $this->level());

		//a missing block of layer 1 means no pyramid, whatever is below
		$this->set(self::X + 2, self::Y - 2, self::Z - 2, VanillaBlocks::IRON());
		self::assertSame(3, $this->level());
		$this->set(self::X, self::Y - 1, self::Z, VanillaBlocks::AIR());
		self::assertSame(0, $this->level());
	}

	public function testSkyAccess() : void{
		$beacon = $this->placeBeacon();
		self::assertTrue($beacon->hasSkyAccess());

		$this->set(self::X, self::Y + 5, self::Z, VanillaBlocks::GLASS());
		$this->set(self::X, self::Y + 6, self::Z, VanillaBlocks::OAK_LEAVES());
		$this->set(self::X, self::Y + 7, self::Z, VanillaBlocks::BEDROCK());
		self::assertTrue($this->placeBeacon()->hasSkyAccess(), "glass, leaves and bedrock let the beam through");

		$this->set(self::X, self::Y + 30, self::Z, VanillaBlocks::STONE());
		self::assertFalse($this->placeBeacon()->hasSkyAccess(), "opaque blocks stop the beam");
	}

	public function testPrimaryEffectsByLevel() : void{
		$speed = VanillaEffects::SPEED();
		$haste = VanillaEffects::HASTE();
		$resistance = VanillaEffects::RESISTANCE();
		$jump = VanillaEffects::JUMP_BOOST();
		$strength = VanillaEffects::STRENGTH();
		$regeneration = VanillaEffects::REGENERATION();

		self::assertFalse(Beacon::isValidSelection($speed, null, 0));
		self::assertTrue(Beacon::isValidSelection($speed, null, 1));
		self::assertTrue(Beacon::isValidSelection($haste, null, 1));
		self::assertFalse(Beacon::isValidSelection($resistance, null, 1));
		self::assertFalse(Beacon::isValidSelection($jump, null, 1));
		self::assertTrue(Beacon::isValidSelection($resistance, null, 2));
		self::assertTrue(Beacon::isValidSelection($jump, null, 2));
		self::assertFalse(Beacon::isValidSelection($strength, null, 2));
		self::assertTrue(Beacon::isValidSelection($strength, null, 3));
		self::assertTrue(Beacon::isValidSelection($strength, null, 4));

		//regeneration is only a secondary power; other effects are never allowed
		self::assertFalse(Beacon::isValidSelection($regeneration, null, 4));
		self::assertFalse(Beacon::isValidSelection(VanillaEffects::NIGHT_VISION(), null, 4));
		self::assertFalse(Beacon::isValidSelection(null, $regeneration, 4));
	}

	public function testSecondaryEffectNeedsFullPyramid() : void{
		$speed = VanillaEffects::SPEED();
		$regeneration = VanillaEffects::REGENERATION();

		self::assertFalse(Beacon::isValidSelection($speed, $regeneration, 3));
		self::assertFalse(Beacon::isValidSelection($speed, $speed, 3));
		self::assertTrue(Beacon::isValidSelection($speed, $regeneration, 4));
		self::assertTrue(Beacon::isValidSelection($speed, $speed, 4), "level II of the primary power");
		self::assertFalse(Beacon::isValidSelection($speed, VanillaEffects::HASTE(), 4), "two different primary powers");
		self::assertFalse(Beacon::isValidSelection($speed, VanillaEffects::NIGHT_VISION(), 4));
	}

	public function testPaymentItems() : void{
		foreach([VanillaItems::IRON_INGOT(), VanillaItems::GOLD_INGOT(), VanillaItems::EMERALD(), VanillaItems::DIAMOND(), VanillaItems::NETHERITE_INGOT()] as $item){
			self::assertTrue(Beacon::isPaymentItem($item), $item->getName());
		}
		self::assertFalse(Beacon::isPaymentItem(VanillaItems::COAL()));
		self::assertFalse(Beacon::isPaymentItem(VanillaItems::AIR()));
		self::assertFalse(Beacon::isPaymentItem(VanillaBlocks::DIAMOND()->asItem()));
	}

	public function testRangeAndDuration() : void{
		self::assertSame([20, 30, 40, 50], array_map(Beacon::getEffectRange(...), [1, 2, 3, 4]));
		self::assertSame([200, 240, 280, 320], array_map(Beacon::getEffectDuration(...), [1, 2, 3, 4]));

		$beacon = new Vector3(0, 64, 0);
		self::assertTrue(Beacon::isInEffectArea($beacon, 1, new Vector3(20, 64, -20), 320));
		self::assertFalse(Beacon::isInEffectArea($beacon, 1, new Vector3(22, 64, 0), 320));
		self::assertTrue(Beacon::isInEffectArea($beacon, 4, new Vector3(50, 64, 0), 320));
		//the area reaches the top of the world, but only range blocks below the beacon
		self::assertTrue(Beacon::isInEffectArea($beacon, 1, new Vector3(0, 319, 0), 320));
		self::assertTrue(Beacon::isInEffectArea($beacon, 1, new Vector3(0, 44, 0), 320));
		self::assertFalse(Beacon::isInEffectArea($beacon, 1, new Vector3(0, 43, 0), 320));
	}

	/**
	 * @param EffectInstance[] $effects
	 * @return int[] effect ID => amplifier
	 */
	private static function summarize(array $effects) : array{
		$result = [];
		foreach($effects as $effect){
			self::assertTrue($effect->isAmbient());
			self::assertTrue($effect->isVisible());
			$result[EffectIdMap::getInstance()->toId($effect->getType())] = $effect->getAmplifier();
		}
		return $result;
	}

	public function testCreatedEffects() : void{
		$speed = VanillaEffects::SPEED();
		$strength = VanillaEffects::STRENGTH();
		$regeneration = VanillaEffects::REGENERATION();

		self::assertSame([], Beacon::createEffects(0, $speed, null));
		self::assertSame([], Beacon::createEffects(2, $strength, null), "strength needs 3 layers");
		self::assertSame([EffectIds::SPEED => 0], self::summarize(Beacon::createEffects(1, $speed, null)));
		self::assertSame([EffectIds::SPEED => 0], self::summarize(Beacon::createEffects(3, $speed, $speed)), "secondary ignored below level 4");
		self::assertSame([EffectIds::SPEED => 1], self::summarize(Beacon::createEffects(4, $speed, $speed)));
		self::assertSame([EffectIds::REGENERATION => 0, EffectIds::STRENGTH => 0], self::summarize(Beacon::createEffects(4, $strength, $regeneration)));

		$effects = Beacon::createEffects(4, $speed, null);
		self::assertSame(320, $effects[0]->getDuration());
	}

	public function testEffectsAreApplied() : void{
		$cow = new Cow(Location::fromObject(new Vector3(self::X + 5.5, self::Y, self::Z + 5.5), $this->world)); //not undead: undead are immune to regeneration
		Beacon::applyEffects($cow, Beacon::createEffects(4, VanillaEffects::HASTE(), VanillaEffects::REGENERATION()));
		$effects = $cow->getEffects();
		self::assertTrue($effects->has(VanillaEffects::HASTE()));
		self::assertTrue($effects->has(VanillaEffects::REGENERATION()));
		$haste = $effects->get(VanillaEffects::HASTE());
		self::assertNotNull($haste);
		self::assertSame(0, $haste->getAmplifier());
		self::assertTrue($haste->isAmbient());

		//applying again refreshes the effect and upgrades it to level II
		Beacon::applyEffects($cow, Beacon::createEffects(4, VanillaEffects::HASTE(), VanillaEffects::HASTE()));
		self::assertSame(1, $effects->get(VanillaEffects::HASTE())?->getAmplifier());
		$cow->close();
	}

	public function testScheduledUpdateComputesTheBeam() : void{
		$this->buildPyramid(2);
		$this->placeBeacon();
		$this->runTicks(2);
		$tile = $this->tile();
		self::assertSame(2, $tile->getPyramidLevel());
		self::assertTrue($tile->isActive());

		//the next check happens 80 ticks later, not before
		$this->set(self::X, self::Y + 3, self::Z, VanillaBlocks::STONE());
		$this->runTicks(Beacon::UPDATE_INTERVAL_TICKS - 5);
		self::assertTrue($tile->isActive());
		$this->runTicks(10);
		self::assertFalse($tile->isActive(), "beam blocked by stone");
		self::assertSame(2, $tile->getPyramidLevel());
	}

	public function testEffectsSavedInTile() : void{
		$beacon = $this->placeBeacon();
		$beacon->setPrimaryEffect(VanillaEffects::JUMP_BOOST());
		$beacon->setSecondaryEffect(VanillaEffects::REGENERATION());
		$this->world->setBlock($beacon->getPosition(), $beacon);

		$tile = $this->tile();
		self::assertSame(EffectIds::JUMP_BOOST, $tile->getPrimaryEffect());
		self::assertSame(EffectIds::REGENERATION, $tile->getSecondaryEffect());

		$nbt = $tile->saveNBT();
		self::assertSame(EffectIds::JUMP_BOOST, $nbt->getInt("primary"));
		self::assertSame(EffectIds::REGENERATION, $nbt->getInt("secondary"));
		self::assertSame(EffectIds::JUMP_BOOST, $tile->getSpawnCompound()->getInt("primary"));

		$loaded = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileBeacon::class, $loaded);
		self::assertSame(EffectIds::JUMP_BOOST, $loaded->getPrimaryEffect());
		self::assertSame(EffectIds::REGENERATION, $loaded->getSecondaryEffect());
		self::assertTrue($loaded->isLoadedFromSave());

		$reread = $this->get(self::X, self::Y, self::Z);
		self::assertInstanceOf(Beacon::class, $reread);
		self::assertSame(VanillaEffects::JUMP_BOOST(), $reread->getPrimaryEffect());
		self::assertSame(VanillaEffects::REGENERATION(), $reread->getSecondaryEffect());
	}
}
