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

use pocketmine\block\utils\LiquidLayer;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\event\block\FarmlandHydrationChangeEvent;
use pocketmine\event\entity\EntityTrampleFarmlandEvent;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\GameRules;
use function intdiv;

class Farmland extends Transparent{
	public const MAX_WETNESS = 7;

	private const WATER_SEARCH_HORIZONTAL_LENGTH = 9;

	private const WATER_SEARCH_VERTICAL_LENGTH = 2;

	private const WATER_POSITION_INDEX_UNKNOWN = -1;

	/** Entities with width² × height at or below this never trample farmland (vanilla FarmlandBlock.fallOn) */
	private const TRAMPLE_MIN_VOLUME = 0.512;
	/** Total possible options for water X/Z indexes */
	private const WATER_POSITION_INDICES_TOTAL = (self::WATER_SEARCH_HORIZONTAL_LENGTH ** 2) * 2;

	protected int $wetness = 0; //"moisture" blockstate property in PC

	/**
	 * Cached value indicating the relative coordinates of the most recently found water block.
	 *
	 * If this is set to a non-unknown value, the farmland block will check the relative coordinates indicated by
	 * this value for water, before searching the entire 9x2x9 grid around the farmland. This significantly benefits
	 * hydrating or fully hydrated farmland, avoiding the need for costly searches on every random tick.
	 *
	 * If the coordinates indicated don't contain water, the full 9x2x9 volume will be searched as before. A new index
	 * will be recorded if water is found, otherwise it will be set to unknown and future searches will search the full
	 * 9x2x9 volume again.
	 *
	 * This property is not exposed to the API or saved on disk. It is only used by PocketMine-MP at runtime as a cache.
	 */
	private int $waterPositionIndex = self::WATER_POSITION_INDEX_UNKNOWN;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_WETNESS, $this->wetness);
		$w->boundedIntAuto(-1, self::WATER_POSITION_INDICES_TOTAL - 1, $this->waterPositionIndex);
	}

	public function getWetness() : int{ return $this->wetness; }

	/** @return $this */
	public function setWetness(int $wetness) : self{
		if($wetness < 0 || $wetness > self::MAX_WETNESS){
			throw new \InvalidArgumentException("Wetness must be in range 0 ... " . self::MAX_WETNESS);
		}
		$this->wetness = $wetness;
		return $this;
	}

	/**
	 * @internal
	 */
	public function getWaterPositionIndex() : int{ return $this->waterPositionIndex; }

	/**
	 * @internal
	 */
	public function setWaterPositionIndex(int $waterPositionIndex) : self{
		if($waterPositionIndex < -1 || $waterPositionIndex >= self::WATER_POSITION_INDICES_TOTAL){
			throw new \InvalidArgumentException("Water XZ index must be in range -1 ... " . (self::WATER_POSITION_INDICES_TOTAL - 1));
		}
		$this->waterPositionIndex = $waterPositionIndex;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 1 / 16)];
	}

	public function onNearbyBlockChange() : void{
		if($this->getSide(Facing::UP)->isSolid()){
			$this->position->getWorld()->setBlock($this->position, VanillaBlocks::DIRT());
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();

		//this property may be updated by canHydrate() - track this so we know if we need to set the block again
		$oldWaterPositionIndex = $this->waterPositionIndex;
		$changed = false;

		//rain falling on the farmland keeps it wet too (vanilla)
		$hydrated = $world->getWeather()->isRainingAt($this->position->getFloorX(), $this->position->getFloorY() + 1, $this->position->getFloorZ()) || $this->canHydrate();
		if(!$hydrated){
			if($this->wetness > 0){
				$event = new FarmlandHydrationChangeEvent($this, $this->wetness, $this->wetness - 1);
				$event->call();
				if(!$event->isCancelled()){
					$this->wetness = $event->getNewHydration();
					$world->setBlock($this->position, $this, false);
					$changed = true;
				}
			}elseif(!$this->isUnderCrops()){
				//dry farmland only reverts to dirt when nothing is planted on it (vanilla maintains_farmland)
				$world->setBlock($this->position, VanillaBlocks::DIRT());
				$changed = true;
			}
		}elseif($this->wetness < self::MAX_WETNESS){
			$event = new FarmlandHydrationChangeEvent($this, $this->wetness, self::MAX_WETNESS);
			$event->call();
			if(!$event->isCancelled()){
				$this->wetness = $event->getNewHydration();
				$world->setBlock($this->position, $this, false);
				$changed = true;
			}
		}

		if(!$changed && $oldWaterPositionIndex !== $this->waterPositionIndex){
			//ensure the water square index is saved regardless of whether anything else happened
			$world->setBlock($this->position, $this, false);
		}
	}

	/**
	 * Crops, stems, torchflower crops and pitcher crops keep dry farmland from turning back into dirt.
	 */
	private function isUnderCrops() : bool{
		$above = $this->getSide(Facing::UP);
		return $above instanceof Crops || $above instanceof TorchflowerCrop || $above instanceof PitcherCrop || $above instanceof DoublePitcherCrop;
	}

	/**
	 * Players always trample; other mobs only while mobGriefing is on. Small entities (width² × height <= 0.512, such
	 * as chickens, cats, rabbits and baby mobs) never trample.
	 */
	private static function canTrample(Living $entity) : bool{
		$size = $entity->getSize();
		if($size->getWidth() * $size->getWidth() * $size->getHeight() <= self::TRAMPLE_MIN_VOLUME){
			return false;
		}
		return $entity instanceof Player || GameRules::allowsMobGriefing($entity->getWorld());
	}

	public function onEntityLand(Entity $entity) : ?float{
		if($entity instanceof Living && Utils::getRandomFloat() < $entity->getFallDistance() - 0.5 && self::canTrample($entity)){
			$ev = new EntityTrampleFarmlandEvent($entity, $this);
			$ev->call();
			if(!$ev->isCancelled()){
				$this->position->getWorld()->setBlock($this->position, VanillaBlocks::DIRT());
			}
		}
		return null;
	}

	protected function canHydrate() : bool{
		$world = $this->position->getWorld();

		$startX = $this->position->getFloorX() - (int) (self::WATER_SEARCH_HORIZONTAL_LENGTH / 2);
		$startY = $this->position->getFloorY();
		$startZ = $this->position->getFloorZ() - (int) (self::WATER_SEARCH_HORIZONTAL_LENGTH / 2);

		if($this->waterPositionIndex !== self::WATER_POSITION_INDEX_UNKNOWN){
			$raw = $this->waterPositionIndex;
			$x = $raw % self::WATER_SEARCH_HORIZONTAL_LENGTH;
			$raw = intdiv($raw, self::WATER_SEARCH_HORIZONTAL_LENGTH);
			$z = $raw % self::WATER_SEARCH_HORIZONTAL_LENGTH;
			$raw = intdiv($raw, self::WATER_SEARCH_HORIZONTAL_LENGTH);
			$y = $raw % self::WATER_SEARCH_VERTICAL_LENGTH;

			if(LiquidLayer::hasWaterAt($world, $startX + $x, $startY + $y, $startZ + $z)){
				return true;
			}
		}

		//no water found at cached position - search the whole area
		//y will increment after x/z have been exhausted, as usually water will be at the same Y as the farmland
		//dry farmland searches on every random tick: the subchunks are read directly, looked up once per chunk column
		for($y = 0; $y < self::WATER_SEARCH_VERTICAL_LENGTH; $y++){
			$worldY = $startY + $y;
			if($worldY < $world->getMinY() || $worldY >= $world->getMaxY()){
				continue;
			}
			$subChunkY = $worldY >> SubChunk::COORD_BIT_SIZE;
			$localY = $worldY & SubChunk::COORD_MASK;
			$lastChunkX = $lastChunkZ = null;
			$subChunk = null;
			for($x = 0; $x < self::WATER_SEARCH_HORIZONTAL_LENGTH; $x++){
				$worldX = $startX + $x;
				for($z = 0; $z < self::WATER_SEARCH_HORIZONTAL_LENGTH; $z++){
					$worldZ = $startZ + $z;
					if(($worldX >> Chunk::COORD_BIT_SIZE) !== $lastChunkX || ($worldZ >> Chunk::COORD_BIT_SIZE) !== $lastChunkZ){
						$lastChunkX = $worldX >> Chunk::COORD_BIT_SIZE;
						$lastChunkZ = $worldZ >> Chunk::COORD_BIT_SIZE;
						$subChunk = $world->getChunk($lastChunkX, $lastChunkZ)?->getSubChunk($subChunkY);
					}
					if($subChunk !== null && LiquidLayer::hasWaterInSubChunk($subChunk, $worldX & SubChunk::COORD_MASK, $localY, $worldZ & SubChunk::COORD_MASK)){
						$this->waterPositionIndex = $x + ($z * self::WATER_SEARCH_HORIZONTAL_LENGTH) + ($y * self::WATER_SEARCH_HORIZONTAL_LENGTH ** 2);
						return true;
					}
				}
			}
		}

		$this->waterPositionIndex = self::WATER_POSITION_INDEX_UNKNOWN;
		return false;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::DIRT()->asItem()
		];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return VanillaBlocks::DIRT()->asItem();
	}
}
