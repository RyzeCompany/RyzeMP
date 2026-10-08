<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\object;

use pocketmine\block\Block;

/**
 * A block queued in a BlockManager at world coordinates.
 */
final class PlacedBlock{

	public function __construct(
		public readonly int $x,
		public readonly int $y,
		public readonly int $z,
		public readonly Block $block
	){}
}
