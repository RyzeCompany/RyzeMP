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

use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\world\particle\SmokeParticle;
use pocketmine\world\sound\FireExtinguishSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\vibration\GameEvent;
use function min;
use function mt_rand;

abstract class FillableCauldron extends Transparent implements ComparatorReadable{
	public const MIN_FILL_LEVEL = 1;
	public const MAX_FILL_LEVEL = 6;
	/** Levels added by rain or snow (a third of the cauldron, like a water bottle). */
	public const PRECIPITATION_FILL_AMOUNT = 2;
	/** Chance that rain or snow falling on a cauldron adds to it (vanilla). */
	public const RAIN_FILL_CHANCE = 0.05;
	public const SNOW_FILL_CHANCE = 0.1;
	/** Smoke particles shown when incompatible contents are mixed and the cauldron empties. */
	private const MIX_SMOKE_PARTICLES = 8;

	private int $fillLevel = self::MIN_FILL_LEVEL;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::MIN_FILL_LEVEL, self::MAX_FILL_LEVEL, $this->fillLevel);
	}

	public function getFillLevel() : int{ return $this->fillLevel; }

	/** @return $this */
	public function setFillLevel(int $fillLevel) : self{
		if($fillLevel < self::MIN_FILL_LEVEL || $fillLevel > self::MAX_FILL_LEVEL){
			throw new \InvalidArgumentException("Fill level must be in range " . self::MIN_FILL_LEVEL . " ... " . self::MAX_FILL_LEVEL);
		}
		$this->fillLevel = $fillLevel;
		return $this;
	}

	/**
	 * Bedrock comparators read the fill level itself (1-6), for every kind of cauldron.
	 */
	public function getComparatorOutput() : int{
		return $this->fillLevel;
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, 11 / 16) //bottom of the cauldron
		];

		foreach(Facing::HORIZONTAL as $f){ //add the frame parts around the bowl
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::UP ? SupportType::EDGE : SupportType::NONE;
	}

	protected function withFillLevel(int $fillLevel) : Block{
		return $fillLevel === 0 ? VanillaBlocks::CAULDRON() : $this->setFillLevel(min(self::MAX_FILL_LEVEL, $fillLevel));
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	protected function addFillLevels(int $amount, Item $usedItem, Item $returnedItem, array &$returnedItems) : void{
		if($this->fillLevel >= self::MAX_FILL_LEVEL){
			return;
		}
		$this->position->getWorld()->setBlock($this->position, $this->withFillLevel($this->fillLevel + $amount));
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), $this->getFillSound());
		$this->position->getWorld()->addGameEvent($this->position, GameEvent::BLOCK_CHANGE);

		$usedItem->pop();
		$returnedItems[] = $returnedItem;
	}

	/**
	 * Adds levels from rain or snow (no item, no sound).
	 */
	protected function fillByPrecipitation() : void{
		if($this->fillLevel < self::MAX_FILL_LEVEL){
			$this->position->getWorld()->setBlock($this->position, $this->withFillLevel($this->fillLevel + self::PRECIPITATION_FILL_AMOUNT));
			$this->position->getWorld()->addGameEvent($this->position, GameEvent::BLOCK_CHANGE);
		}
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	protected function removeFillLevels(int $amount, Item $usedItem, Item $returnedItem, array &$returnedItems) : void{
		if($this->fillLevel < $amount){
			return;
		}

		$this->position->getWorld()->setBlock($this->position, $this->withFillLevel($this->fillLevel - $amount));
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), $this->getEmptySound());
		$this->position->getWorld()->addGameEvent($this->position, GameEvent::BLOCK_CHANGE);

		$usedItem->pop();
		$returnedItems[] = $returnedItem;
	}

	/**
	 * Returns the sound played when adding levels to the cauldron liquid.
	 */
	abstract public function getFillSound() : Sound;

	/**
	 * Returns the sound played when removing levels from the cauldron liquid.
	 */
	abstract public function getEmptySound() : Sound;

	/**
	 * @param Item[] &$returnedItems
	 */
	protected function mix(Item $usedItem, Item $returnedItem, array &$returnedItems) : void{
		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::CAULDRON());
		$world->addGameEvent($this->position, GameEvent::BLOCK_CHANGE);
		//incompatible contents fizz away (random.fizz) with a puff of smoke, like vanilla
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new FireExtinguishSound());
		for($i = 0; $i < self::MIX_SMOKE_PARTICLES; ++$i){
			$world->addParticle($this->position->add(mt_rand(2, 14) / 16, 0.8, mt_rand(2, 14) / 16), new SmokeParticle());
		}

		$usedItem->pop();
		$returnedItems[] = $returnedItem;
	}

	public function asItem() : Item{
		return VanillaBlocks::CAULDRON()->asItem();
	}
}
