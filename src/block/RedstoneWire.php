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

use pocketmine\block\utils\AnalogRedstoneSignalEmitter;
use pocketmine\block\utils\AnalogRedstoneSignalEmitterTrait;
use pocketmine\block\utils\RedstoneWireConnection;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function intdiv;

/**
 * Redstone dust. Its power level is solved per network by {@link \pocketmine\world\redstone\RedstoneEngine}.
 *
 * Since Bedrock 1.26.60 the connection shape is part of the block state (redstone_{north,east,south,west}), so the
 * server computes it from the neighbouring blocks, like the fence connections since 1.26.50.
 */
class RedstoneWire extends Flowable implements AnalogRedstoneSignalEmitter{
	use StaticSupportTrait;
	use AnalogRedstoneSignalEmitterTrait {
		describeBlockOnlyState as describeSignalStrength;
	}

	/**
	 * @var RedstoneWireConnection[]
	 * @phpstan-var array<int, RedstoneWireConnection>
	 */
	protected array $connections = [];

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeSignalStrength($w);
		//four 3-valued sides take 7 bits packed in base 3 (8 bits as separate enums would exceed the state bit limit)
		$packed = 0;
		foreach(self::CONNECTION_ORDER as $i => $face){
			$packed += self::connectionToInt($this->getConnection($face)) * (3 ** $i);
		}
		$w->boundedIntAuto(0, 80, $packed);
		foreach(self::CONNECTION_ORDER as $face){
			$this->connections[$face] = self::intToConnection($packed % 3);
			$packed = intdiv($packed, 3);
		}
	}

	private const CONNECTION_ORDER = [Facing::NORTH, Facing::EAST, Facing::SOUTH, Facing::WEST];

	private static function connectionToInt(RedstoneWireConnection $connection) : int{
		return match($connection){
			RedstoneWireConnection::NONE => 0,
			RedstoneWireConnection::SIDE => 1,
			RedstoneWireConnection::UP => 2,
		};
	}

	private static function intToConnection(int $value) : RedstoneWireConnection{
		return match($value){
			0 => RedstoneWireConnection::NONE,
			1 => RedstoneWireConnection::SIDE,
			default => RedstoneWireConnection::UP,
		};
	}

	public function getConnection(int $face) : RedstoneWireConnection{
		return $this->connections[$face] ?? RedstoneWireConnection::NONE;
	}

	/** @return $this */
	public function setConnection(int $face, RedstoneWireConnection $connection) : self{
		if(Facing::axis($face) === Axis::Y){
			throw new \InvalidArgumentException("Redstone wire only connects horizontally");
		}
		$this->connections[$face] = $connection;
		return $this;
	}

	/**
	 * Computes the connection towards one side, following the vanilla rules: dust climbs a neighbouring block to
	 * reach dust on top of it (unless a conductor sits on top of this dust), connects to redstone components and to
	 * dust one block lower when the neighbour doesn't conduct.
	 */
	private function computeConnection(int $face, bool $canClimb) : RedstoneWireConnection{
		$side = $this->getSide($face);
		if($canClimb && $side->getSupportType(Facing::UP)->hasCenterSupport() && $side->getSide(Facing::UP) instanceof RedstoneWire){
			return $side->getSupportType(Facing::opposite($face)) === SupportType::FULL ? RedstoneWireConnection::UP : RedstoneWireConnection::SIDE;
		}
		if($side instanceof RedstoneWire || $side->connectsToRedstoneWire(Facing::opposite($face))){
			return RedstoneWireConnection::SIDE;
		}
		if(!$side->isRedstoneConductor() && $side->getSide(Facing::DOWN) instanceof RedstoneWire){
			return RedstoneWireConnection::SIDE;
		}
		return RedstoneWireConnection::NONE;
	}

	/**
	 * Recomputes the connection shape from the neighbouring blocks. Returns true if anything changed.
	 */
	protected function recalculateConnections() : bool{
		$canClimb = !$this->getSide(Facing::UP)->isRedstoneConductor();
		$new = [];
		foreach(Facing::HORIZONTAL as $face){
			$new[$face] = $this->computeConnection($face, $canClimb);
		}
		//like vanilla, dust connected on a single axis (or nowhere) extends into a line (or a cross)
		$noNorthSouth = $new[Facing::NORTH] === RedstoneWireConnection::NONE && $new[Facing::SOUTH] === RedstoneWireConnection::NONE;
		$noEastWest = $new[Facing::EAST] === RedstoneWireConnection::NONE && $new[Facing::WEST] === RedstoneWireConnection::NONE;
		foreach(Facing::HORIZONTAL as $face){
			if($new[$face] === RedstoneWireConnection::NONE && (Facing::axis($face) === Axis::X ? $noNorthSouth : $noEastWest)){
				$new[$face] = RedstoneWireConnection::SIDE;
			}
		}
		$changed = false;
		foreach($new as $face => $connection){
			if($this->getConnection($face) !== $connection){
				$this->connections[$face] = $connection;
				$changed = true;
			}
		}
		return $changed;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->recalculateConnections();
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN)->hasCenterSupport();
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		if($this->signalStrength === 0 || $face === Facing::UP){
			return 0;
		}
		if($face === Facing::DOWN){
			return $this->signalStrength;
		}
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone === null){
			return 0;
		}
		$pos = $this->position;
		return ($redstone->getWirePoweredFaces($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) & (1 << $face)) !== 0 ? $this->signalStrength : 0;
	}

	public function getStrongPower(int $face) : int{
		//blocks powered by wire don't power other wire: the engine ignores wire power when solving networks
		return $this->getWeakPower($face);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$pos = $this->position;
		if($this->recalculateConnections()){
			$pos->getWorld()->setBlock($pos, $this);
		}
		$pos->getWorld()->getRedstone()?->markWireDirty($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
	}

	public function onPostPlace() : void{
		parent::onPostPlace();
		$this->updateDiagonalWires();
	}

	/**
	 * Dust one block higher or lower on each side connects to this dust but is not a direct neighbour, so it is not
	 * notified by the world when this dust appears or disappears.
	 */
	private function updateDiagonalWires() : void{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		foreach(Facing::HORIZONTAL as $face){
			[$dx, , $dz] = Facing::OFFSET[$face];
			foreach([-1, 1] as $dy){
				$wire = $world->getBlockAt($x + $dx, $y + $dy, $z + $dz);
				if($wire instanceof RedstoneWire && $wire->recalculateConnections()){
					$world->setBlock($wire->position, $wire);
				}
			}
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		$this->updateDiagonalWires();
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone !== null){
			$x = $this->position->getFloorX();
			$y = $this->position->getFloorY();
			$z = $this->position->getFloorZ();
			//wires one block higher or lower are connected, but are not direct neighbours
			foreach(Facing::HORIZONTAL as $face){
				[$dx, , $dz] = Facing::OFFSET[$face];
				foreach([-1, 1] as $dy){
					if($world->getBlockAt($x + $dx, $y + $dy, $z + $dz) instanceof RedstoneWire){
						$redstone->markWireDirty($x + $dx, $y + $dy, $z + $dz);
					}
				}
				if($this->signalStrength > 0){
					$redstone->updateAround($x + $dx, $y, $z + $dz);
				}
			}
			if($this->signalStrength > 0){
				$redstone->updateAround($x, $y - 1, $z);
			}
		}
		return $result;
	}
}
