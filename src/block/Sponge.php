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
use pocketmine\block\utils\UltraWarmHelper;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\utils\Utils;
use pocketmine\world\format\Chunk;
use pocketmine\world\particle\EvaporationParticle;
use pocketmine\world\sound\FizzSound;
use pocketmine\world\sound\SpongeAbsorbSound;
use pocketmine\world\World;
use function count;

class Sponge extends Opaque{
	/** How far (in blocks, taxicab distance) a sponge reaches for water (Java SpongeBlock.MAX_DEPTH). */
	private const MAX_DEPTH = 6;
	/** Most blocks visited by the search which a sponge accepts, itself included: 64 waters at most. */
	private const MAX_COUNT = 65;

	protected bool $wet = false;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->bool($this->wet);
	}

	public function isWet() : bool{ return $this->wet; }

	/** @return $this */
	public function setWet(bool $wet) : self{
		$this->wet = $wet;
		return $this;
	}

	public function onPostPlace() : void{
		$this->update();
	}

	public function onNearbyBlockChange() : void{
		if(!$this->wet){
			$this->update();
		}
	}

	/**
	 * A wet sponge placed in an ultra-warm dimension dries at once (Java WetSpongeBlock.onPlace()); a dry sponge soaks
	 * up the water around it and turns wet (Java SpongeBlock.tryAbsorbWater()).
	 */
	private function update() : void{
		$world = $this->position->getWorld();
		if($this->wet){
			if(!UltraWarmHelper::isUltraWarm($this->position)){
				return;
			}
			$this->wet = false;
			$world->setBlock($this->position, $this);
			//Java plays wet_sponge.dries at pitch (1 + 0.2 * random) * 0.7 with a puff of steam (level event 2009);
			//Bedrock has no such sound: the fizz at that low pitch and the evaporation particle are the closest
			$center = $this->position->add(0.5, 0.5, 0.5);
			$world->addSound($center, new FizzSound((1.0 + Utils::getRandomFloat() * 0.2) * 0.7));
			$world->addParticle($center, new EvaporationParticle());
		}
		if($this->absorbWater($world)){
			$this->wet = true;
			$world->setBlock($this->position, $this);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new SpongeAbsorbSound());
		}
	}

	/**
	 * Removes the water around the sponge, breadth first, like Java SpongeBlock.removeWaterBreadthFirstSearch(): water
	 * blocks and bubble columns become air, waterlogged blocks are drained, and underwater plants (kelp, seagrass) are
	 * broken with their drops. The search goes through water only.
	 *
	 * @return bool whether any water was removed
	 */
	private function absorbWater(World $world) : bool{
		$startX = $this->position->getFloorX();
		$startY = $this->position->getFloorY();
		$startZ = $this->position->getFloorZ();
		/** @var int[][] $queue x, y, z, depth */
		$queue = [[$startX, $startY, $startZ, 0]];
		$visited = [];
		$accepted = 0;
		for($i = 0; $i < count($queue); ++$i){
			[$x, $y, $z, $depth] = $queue[$i];
			$hash = World::blockHash($x, $y, $z);
			if(isset($visited[$hash])){
				continue;
			}
			$visited[$hash] = true;
			if($depth > 0 && !self::removeWaterAt($world, $x, $y, $z)){
				continue;
			}
			if(++$accepted >= self::MAX_COUNT){
				break;
			}
			if($depth < self::MAX_DEPTH){
				foreach(Facing::OFFSET as [$dx, $dy, $dz]){
					$queue[] = [$x + $dx, $y + $dy, $z + $dz, $depth + 1];
				}
			}
		}
		return $accepted > 1;
	}

	/**
	 * Takes the water at the position, if there is any. Returns whether it did.
	 */
	private static function removeWaterAt(World $world, int $x, int $y, int $z) : bool{
		if(!$world->isInWorld($x, $y, $z) || !$world->isChunkLoaded($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)){
			return false;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if(!LiquidLayer::isWater($block)){
			return false;
		}
		if($block instanceof SeaPickle){
			$world->setBlockAt($x, $y, $z, $block->setUnderwater(false));
		}elseif(WaterloggedHelper::canDrain($block)){
			WaterloggedHelper::drain($block);
		}elseif($block instanceof Water || $block instanceof BubbleColumn){
			$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
		}elseif($block instanceof Kelp || $block instanceof Seagrass || $block instanceof TallSeagrass){
			$center = $block->getPosition()->add(0.5, 0.5, 0.5);
			foreach($block->getDrops(VanillaItems::AIR()) as $drop){
				$world->dropItem($center, $drop);
			}
			$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
		}else{
			return false;
		}
		return true;
	}
}
