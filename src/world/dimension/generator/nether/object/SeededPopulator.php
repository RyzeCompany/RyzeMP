<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\nether\object;

use pocketmine\world\dimension\generator\Populator;
use pocketmine\world\dimension\generator\random\Xoroshiro128;

/**
 * A populator owning the Xoroshiro128 random it reseeds for every chunk.
 */
abstract class SeededPopulator implements Populator{

	protected Xoroshiro128 $random;

	public function __construct(){
		$this->random = new Xoroshiro128(0);
	}
}
