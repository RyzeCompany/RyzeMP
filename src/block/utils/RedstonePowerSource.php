<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

/** Native electrical output. $face points from the source towards its receiver. */
interface RedstonePowerSource{

	/** Power supplied directly to a neighbouring component, in the range 0-15. */
	public function getWeakPower(int $face) : int;

	/** Power conducted by a neighbouring solid block, in the range 0-15. */
	public function getStrongPower(int $face) : int;
}
