<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\block\RedstoneWire;
use pocketmine\event\block\BlockRedstoneUpdateEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function max;
use function min;

/** Stateless power queries. Reads never load chunks or recursively power conductors. */
final class RedstoneSignal{

	private function __construct(){}

	public static function isConductor(Block $block) : bool{
		return $block->isSolid() && !$block->isTransparent();
	}

	public static function getBlockAt(World $world, int $x, int $y, int $z) : ?Block{
		if(!$world->isInWorld($x, $y, $z) || !$world->isChunkLoaded($x >> 4, $z >> 4)){
			return null;
		}
		return $world->getBlockAt($x, $y, $z);
	}

	/** $side points from the receiver to the source. */
	public static function getPowerFromSide(Block $receiver, int $side, bool $ignoreWire = false) : int{
		$pos = $receiver->getPosition();
		$world = $pos->getWorld();
		$neighbour = $pos->getSide($side);
		$source = self::getBlockAt($world, $neighbour->getFloorX(), $neighbour->getFloorY(), $neighbour->getFloorZ());
		if($source === null){
			return 0;
		}
		$power = 0;
		if($source instanceof RedstonePowerSource && !($ignoreWire && $source instanceof RedstoneWire)){
			$power = $source->getWeakPower(Facing::opposite($side));
		}
		if(self::isConductor($source)){
			$power = max($power, self::getStrongPower($source, $ignoreWire));
		}
		return max(0, min(15, $power));
	}

	/** Strong power into this block. A powered solid block never powers another solid block. */
	public static function getStrongPower(Block $block, bool $ignoreWire = false) : int{
		$pos = $block->getPosition();
		$world = $pos->getWorld();
		$power = 0;
		foreach(Facing::ALL as $side){
			$neighbour = $pos->getSide($side);
			$source = self::getBlockAt($world, $neighbour->getFloorX(), $neighbour->getFloorY(), $neighbour->getFloorZ());
			if($source instanceof RedstonePowerSource && !($ignoreWire && $source instanceof RedstoneWire)){
				$power = max($power, $source->getStrongPower(Facing::opposite($side)));
			}
		}
		return max(0, min(15, $power));
	}

	public static function getReceivedPower(Block $receiver, bool $ignoreWire = false) : int{
		$power = 0;
		foreach(Facing::ALL as $side){
			$power = max($power, self::getPowerFromSide($receiver, $side, $ignoreWire));
			if($power >= 15){
				return 15;
			}
		}
		return $power;
	}

	/** Notify components beyond support blocks as well as ascending/descending wire. */
	public static function notifyNeighbours(World $world, Vector3 $pos) : void{
		$world->notifyNeighbourBlockUpdate($pos);
		foreach(Facing::ALL as $side){
			$neighbour = $pos->getSide($side);
			if(self::getBlockAt($world, $neighbour->getFloorX(), $neighbour->getFloorY(), $neighbour->getFloorZ()) !== null){
				$world->notifyNeighbourBlockUpdate($neighbour);
			}
		}
	}

	/** Let plugins cancel or adjust an electrical state transition before it is committed. */
	public static function filterChange(Block $block, int $oldPower, int $newPower) : int{
		if($oldPower === $newPower || !BlockRedstoneUpdateEvent::hasHandlers()){
			return $newPower;
		}
		$event = new BlockRedstoneUpdateEvent($block, $oldPower, $newPower);
		$event->call();
		return $event->isCancelled() ? $oldPower : $event->getNewPower();
	}
}
