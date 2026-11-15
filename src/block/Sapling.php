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

use pocketmine\block\utils\SaplingType;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\VanillaTreeGrower;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\generator\object\TreeFactory;
use function mt_rand;

class Sapling extends Flowable{
	use StaticSupportTrait;

	/** Percent chance for bone meal to advance the sapling one stage (SaplingBlock.isBonemealSuccess()) */
	private const BONE_MEAL_CHANCE = 45;
	/** Light needed at the block above for random growth (SaplingBlock.BRIGHTNESS_FOR_SAPLING_GROWTH) */
	private const MIN_GROWTH_LIGHT = 9;

	protected bool $ready = false;

	private SaplingType $saplingType;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, SaplingType $saplingType){
		$this->saplingType = $saplingType;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->ready);
	}

	public function isReady() : bool{ return $this->ready; }

	/** @return $this */
	public function setReady(bool $ready) : self{
		$this->ready = $ready;
		return $this;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return $supportBlock->hasTypeTag(BlockTypeTags::DIRT) || $supportBlock->hasTypeTag(BlockTypeTags::MUD);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			//vanilla (SaplingBlock): a sapling that can't grow (a dark oak sapling outside a 2x2 square) doesn't use the
			//bone meal; otherwise it is used up and advances the sapling one stage with a 45% chance
			if($this->saplingType->needsTwoByTwo() && $this->findTwoByTwoOrigin() === null){
				return false;
			}
			$item->pop();
			if(($player !== null && !$player->hasFiniteResources()) || mt_rand(0, 99) < self::BONE_MEAL_CHANCE){
				$this->advanceTree($player);
			}
			return true;
		}

		return false;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		//vanilla: brightness 9 at the block above and a 1 in 7 chance (SaplingBlock.randomTick())
		$world = $this->position->getWorld();
		if(mt_rand(0, 6) === 0 && $world->getFullLightAt($this->position->getFloorX(), $this->position->getFloorY() + 1, $this->position->getFloorZ()) >= self::MIN_GROWTH_LIGHT){
			$this->advanceTree(null);
		}
	}

	/**
	 * SaplingBlock.advanceTree(): a new sapling becomes ready first, a ready one grows its tree.
	 */
	private function advanceTree(?Player $player) : void{
		if($this->ready){
			$this->grow($player);
		}else{
			$this->ready = true;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	private function grow(?Player $player) : bool{
		$grown = VanillaTreeGrower::growTree(
			$this,
			$player,
			VanillaTreeGrower::forSapling($this->saplingType),
			fn(Block $block) : bool => $block instanceof Sapling && $block->saplingType === $this->saplingType
		);
		return $grown ?? $this->growLegacy($player);
	}

	/**
	 * PocketMine trees, used when the vanilla data (which has the vanilla tree features) is not available.
	 */
	private function growLegacy(?Player $player) : bool{
		$random = new Random(mt_rand());
		$origin = $this->position;
		$tree = null;
		if($this->saplingType->canGrowTwoByTwo() && ($twoByTwoOrigin = $this->findTwoByTwoOrigin()) !== null){
			$tree = TreeFactory::getTwoByTwo($random, $this->saplingType->getTreeType());
			$origin = $twoByTwoOrigin;
		}elseif(!$this->saplingType->needsTwoByTwo()){
			$tree = TreeFactory::get($random, $this->saplingType->getTreeType());
		}
		$transaction = $tree?->getBlockTransaction($this->position->getWorld(), $origin->getFloorX(), $origin->getFloorY(), $origin->getFloorZ(), $random);
		if($transaction === null){
			return false;
		}

		$ev = new StructureGrowEvent($this, $transaction, $player);
		$ev->call();
		if(!$ev->isCancelled()){
			return $transaction->apply();
		}
		return false;
	}

	/**
	 * Returns the north-west corner of a 2x2 square of saplings of the same type that includes this one, or null if
	 * there is none.
	 */
	private function findTwoByTwoOrigin() : ?Vector3{
		$world = $this->position->getWorld();
		foreach([[0, 0], [0, -1], [-1, 0], [-1, -1]] as [$offX, $offZ]){
			$origin = $this->position->add($offX, 0, $offZ);
			for($dx = 0; $dx <= 1; ++$dx){
				for($dz = 0; $dz <= 1; ++$dz){
					$block = $world->getBlock($origin->add($dx, 0, $dz));
					if(!$block instanceof Sapling || $block->saplingType !== $this->saplingType){
						continue 3;
					}
				}
			}
			return $origin;
		}
		return null;
	}

	public function getFuelTime() : int{
		return 100;
	}

	public function getCompostabilityChance() : int{
		return 30;
	}

	public function getFlameEncouragement() : int{
		return $this->saplingType === SaplingType::CHERRY || $this->saplingType === SaplingType::PALE_OAK || $this->saplingType === SaplingType::POPLAR ? 5 : 0;
	}

	public function getFlammability() : int{
		return $this->saplingType === SaplingType::CHERRY || $this->saplingType === SaplingType::PALE_OAK || $this->saplingType === SaplingType::POPLAR ? 20 : 0;
	}
}
