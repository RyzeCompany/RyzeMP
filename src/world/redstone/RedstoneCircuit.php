<?php

declare(strict_types=1);

namespace pocketmine\world\redstone;

use pocketmine\block\Block;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\block\utils\RedstoneTickingComponent;
use pocketmine\world\World;

/** World-owned, ordered electrical ticks. Pending pulses are never overwritten by neighbour updates. */
final class RedstoneCircuit{
	/** @var \SplPriorityQueue<array{int, int}, array{int, int, int, int, int, int, int, int}> */
	private \SplPriorityQueue $queue;
	/** @var array<int, int> block hash => sequence */
	private array $pending = [];
	private int $sequence = 0;

	public function __construct(private World $world, private int $currentTick){
		$this->queue = new \SplPriorityQueue();
	}

	public function schedule(Block $block, int $delay, int $targetPower) : void{
		if($delay < 1 || $targetPower < 0 || $targetPower > 15 || !$block instanceof RedstoneTickingComponent){
			throw new \InvalidArgumentException("Invalid redstone tick");
		}
		$pos = $block->getPosition();
		$hash = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		if(isset($this->pending[$hash])){
			return;
		}
		$sequence = ++$this->sequence;
		$this->pending[$hash] = $sequence;
		$due = $this->currentTick + $delay;
		$this->queue->insert([$hash, $sequence, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $block->getStateId(), $targetPower, $due], [-$due, -$sequence]);
	}

	public function tick(int $currentTick) : void{
		$this->currentTick = $currentTick;
		while(!$this->queue->isEmpty()){
			[$hash, $sequence, $x, $y, $z, $stateId, $targetPower, $due] = $this->queue->top();
			if($due > $currentTick){
				break;
			}
			$this->queue->extract();
			if(($this->pending[$hash] ?? null) !== $sequence){
				continue;
			}
			unset($this->pending[$hash]);
			$block = RedstoneSignal::getBlockAt($this->world, $x, $y, $z);
			if($block instanceof RedstoneTickingComponent && $block->getStateId() === $stateId){
				$block->onRedstoneUpdate($targetPower);
			}
		}
	}

	/** Invalidate even silent writes, including removal and replacement by the same block type. */
	public function onBlockChanged(Block $before) : void{
		$pos = $before->getPosition();
		$hash = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		unset($this->pending[$hash]);
	}
}
