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

use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\LeavesType;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\event\block\LeavesDecayEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\World;
use function count;
use function min;
use function mt_rand;

class Leaves extends Transparent{
	/**
	 * Bedrock's own trees keep their leaves within 4 blocks of a log, but the trees placed here (world generator and
	 * saplings) are the Java-shaped vanilla features, whose leaves reach 6 blocks (cherry and jungle crowns, fancy oaks,
	 * acacias): with 4, a fifth of a freshly grown cherry or jungle crown would decay. Java's distance (6) keeps them.
	 */
	private const MAX_LOG_DISTANCE = 6;

	/**
	 * Drop chances as 1 in N, by Fortune level 0-3 (vanilla leaves loot: saplings 5%, 6.25%, 8.33%, 10%; jungle
	 * saplings 2.5%, 2.78%, 3.13%, 4.17%; apples 0.5%, 0.56%, 0.625%, 0.83%; sticks 2%, 2.2%, 2.5%, 3.33%).
	 */
	private const SAPLING_CHANCE = [20, 16, 12, 10];
	private const JUNGLE_SAPLING_CHANCE = [40, 36, 32, 24];
	private const APPLE_CHANCE = [200, 180, 160, 120];
	private const STICK_CHANCE = [50, 45, 40, 30];

	protected LeavesType $leavesType; //immutable for now
	protected bool $noDecay = false;
	protected bool $checkDecay = false;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, LeavesType $leavesType){
		$this->leavesType = $leavesType;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->noDecay);
		$w->bool($this->checkDecay);
	}

	public function getLeavesType() : LeavesType{ return $this->leavesType; }

	public function isNoDecay() : bool{ return $this->noDecay; }

	/** @return $this */
	public function setNoDecay(bool $noDecay) : self{
		$this->noDecay = $noDecay;
		return $this;
	}

	public function isCheckDecay() : bool{ return $this->checkDecay; }

	/** @return $this */
	public function setCheckDecay(bool $checkDecay) : self{
		$this->checkDecay = $checkDecay;
		return $this;
	}

	public function blocksDirectSkyLight() : bool{
		return true;
	}

	/**
	 * @param true[] $visited reference parameter
	 * @phpstan-param array<int, true> $visited
	 * @phpstan-param-out array<int, true> $visited
	 */
	protected function findLog(Vector3 $pos, array &$visited = [], int $distance = 0) : bool{
		$index = World::blockHash($pos->x, $pos->y, $pos->z);
		if(isset($visited[$index])){
			return false;
		}
		$visited[$index] = true;

		$block = $this->position->getWorld()->getBlock($pos);
		if($block instanceof Wood){ //type doesn't matter
			return true;
		}

		if($block instanceof Leaves && $distance < self::MAX_LOG_DISTANCE){
			foreach(Facing::ALL as $side){
				if($this->findLog($pos->getSide($side), $visited, $distance + 1)){
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a log is at most {@link Leaves::MAX_LOG_DISTANCE} steps away through leaves (Java LeavesBlock: leaves
	 * decay at distance 7). Breadth-first, so every block is reached by its shortest path.
	 */
	private function hasLogInRange() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$visited = [World::blockHash($x, $y, $z) => true];
		$queue = [[$x, $y, $z]];
		for($distance = 1; $distance <= self::MAX_LOG_DISTANCE && count($queue) > 0; ++$distance){
			$next = [];
			foreach($queue as [$qx, $qy, $qz]){
				foreach(Facing::OFFSET as [$ox, $oy, $oz]){
					$nx = $qx + $ox;
					$ny = $qy + $oy;
					$nz = $qz + $oz;
					$hash = World::blockHash($nx, $ny, $nz);
					if(isset($visited[$hash]) || !$world->isInWorld($nx, $ny, $nz)){
						continue;
					}
					$visited[$hash] = true;
					$block = $world->getBlockAt($nx, $ny, $nz);
					if($block instanceof Wood){ //type doesn't matter
						return true;
					}
					if($block instanceof Leaves && $distance < self::MAX_LOG_DISTANCE){
						$next[] = [$nx, $ny, $nz];
					}
				}
			}
			$queue = $next;
		}
		return false;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		//vanilla (MangroveLeavesBlock): bone meal on mangrove leaves with air below hangs a new propagule there
		if($item instanceof Fertilizer && $this->leavesType === LeavesType::MANGROVE){
			$below = $this->getSide(Facing::DOWN);
			if($below->getTypeId() === BlockTypeIds::AIR && $this->position->getWorld()->isInWorld($below->position->getFloorX(), $below->position->getFloorY(), $below->position->getFloorZ())){
				$item->pop();
				BlockEventHelper::grow($below, VanillaBlocks::MANGROVE_PROPAGULE()->setHanging(true)->setStage(0), $player);
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		if(!$this->noDecay && !$this->checkDecay){
			$this->checkDecay = true;
			$this->position->getWorld()->setBlock($this->position, $this, false);
		}
	}

	public function ticksRandomly() : bool{
		return !$this->noDecay && $this->checkDecay;
	}

	public function onRandomTick() : void{
		if(!$this->noDecay && $this->checkDecay){
			$cancelled = false;
			if(LeavesDecayEvent::hasHandlers()){
				$ev = new LeavesDecayEvent($this);
				$ev->call();
				$cancelled = $ev->isCancelled();
			}

			$world = $this->position->getWorld();
			if($cancelled || $this->hasLogInRange()){
				$this->checkDecay = false;
				$world->setBlock($this->position, $this, false);
			}else{
				$world->useBreakOn($this->position);
			}
		}
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->noDecay = true; //artificial leaves don't decay
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if(($item->getBlockToolType() & BlockToolType::SHEARS) !== 0){
			return parent::getDropsForCompatibleTool($item);
		}

		$drops = [];
		$fortune = min(3, $item->getEnchantmentLevel(VanillaEnchantments::FORTUNE()));
		if(mt_rand(1, ($this->leavesType === LeavesType::JUNGLE ? self::JUNGLE_SAPLING_CHANCE : self::SAPLING_CHANCE)[$fortune]) === 1){ //Saplings
			$sapling = (match($this->leavesType){
				LeavesType::ACACIA => VanillaBlocks::ACACIA_SAPLING(),
				LeavesType::BIRCH => VanillaBlocks::BIRCH_SAPLING(),
				LeavesType::DARK_OAK => VanillaBlocks::DARK_OAK_SAPLING(),
				LeavesType::JUNGLE => VanillaBlocks::JUNGLE_SAPLING(),
				LeavesType::OAK => VanillaBlocks::OAK_SAPLING(),
				LeavesType::SPRUCE => VanillaBlocks::SPRUCE_SAPLING(),
				LeavesType::MANGROVE => null, //vanilla: propagules grow under the leaves instead of dropping
				LeavesType::AZALEA => VanillaBlocks::AZALEA(),
				LeavesType::FLOWERING_AZALEA => VanillaBlocks::FLOWERING_AZALEA(),
				LeavesType::CHERRY => VanillaBlocks::CHERRY_SAPLING(),
				LeavesType::PALE_OAK => VanillaBlocks::PALE_OAK_SAPLING(),
				LeavesType::ORANGE_POPLAR, LeavesType::RED_POPLAR, LeavesType::YELLOW_POPLAR => VanillaBlocks::POPLAR_SAPLING(),
			})?->asItem();
			if($sapling !== null){
				$drops[] = $sapling;
			}
		}
		if(
			($this->leavesType === LeavesType::OAK || $this->leavesType === LeavesType::DARK_OAK) &&
			mt_rand(1, self::APPLE_CHANCE[$fortune]) === 1
		){ //Apples
			$drops[] = VanillaItems::APPLE();
		}
		if(mt_rand(1, self::STICK_CHANCE[$fortune]) === 1){
			$drops[] = VanillaItems::STICK()->setCount(mt_rand(1, 2));
		}

		return $drops;
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getFlameEncouragement() : int{
		return $this->isAzalea() ? 15 : 30;
	}

	public function getFlammability() : int{
		return $this->isAzalea() ? 100 : 60;
	}

	private function isAzalea() : bool{
		return $this->leavesType === LeavesType::AZALEA || $this->leavesType === LeavesType::FLOWERING_AZALEA;
	}

	public function getCompostabilityChance() : int{
		return match($this->leavesType){
			LeavesType::FLOWERING_AZALEA => 50,
			default => 30
		};
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
