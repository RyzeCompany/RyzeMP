<?php

declare(strict_types=1);

namespace pocketmine\anticheat\timeline;

use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use function array_shift;
use function count;
use function microtime;

/**
 * Bounded history of authoritative client input.
 *
 * The history is deliberately small: enough for movement/combat correlation
 * without letting per-player anti-cheat state grow over time.
 */
final class InputTimeline{
	private const MAX_FRAMES = 60;

	/** @var list<InputFrame> */
	private array $frames = [];

	public function record(PlayerAuthInputPacket $packet, int $protocolId) : InputFrame{
		$position = $packet->getPosition();
		$delta = $packet->getDelta();

		$frame = new InputFrame(
			$packet->getTick(),
			$protocolId,
			$packet->getInputMode(),
			$packet->getPlayMode(),
			$packet->getInteractionMode(),
			microtime(true),
			$position->x,
			$position->y,
			$position->z,
			$delta->x,
			$delta->y,
			$delta->z,
			$packet->getYaw(),
			$packet->getPitch()
		);

		$this->frames[] = $frame;
		if(count($this->frames) > self::MAX_FRAMES){
			array_shift($this->frames);
		}

		return $frame;
	}

	public function getLatest() : ?InputFrame{
		$count = count($this->frames);
		return $count === 0 ? null : $this->frames[$count - 1];
	}

	public function getPrevious() : ?InputFrame{
		$count = count($this->frames);
		return $count < 2 ? null : $this->frames[$count - 2];
	}

	/**
	 * @return list<InputFrame>
	 */
	public function getFrames() : array{
		return $this->frames;
	}

	public function clear() : void{
		$this->frames = [];
	}
}
