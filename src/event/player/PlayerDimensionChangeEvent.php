<?php

declare(strict_types=1);

namespace pocketmine\event\player;

use pocketmine\network\mcpe\DimensionTransition;
use pocketmine\player\Player;

/** A transition observation, not a guarantee that the destination has rendered. Not cancellable. */
final class PlayerDimensionChangeEvent extends PlayerEvent{

	public const STARTED = "started";
	public const SCREEN_STOPPED = "screen_stopped";
	public const CLIENT_ACKNOWLEDGED = "client_acknowledged";
	public const REPLACED = "replaced";
	public const DISCONNECTED = "disconnected";
	public const TIMED_OUT = "timed_out";
	public const SEND_CANCELLED = "send_cancelled";

	public function __construct(Player $player, private DimensionTransition $transition, private string $state){
		$this->player = $player;
		$this->transition = clone $transition;
	}

	public function getState() : string{ return $this->state; }
	public function getTransition() : DimensionTransition{ return clone $this->transition; }
}
