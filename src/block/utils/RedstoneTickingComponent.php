<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

/** A delayed electrical update, distinct from a block's ordinary scheduled tick. */
interface RedstoneTickingComponent{

	public function onRedstoneUpdate(int $targetPower) : void;
}
