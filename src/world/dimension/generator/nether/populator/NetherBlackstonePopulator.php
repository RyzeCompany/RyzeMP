<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\nether\populator;

final class NetherBlackstonePopulator extends NetherGravelPopulator{

	public function name() : string{
		return "nether_blackstone";
	}

	public function getState() : string{
		return "minecraft:blackstone";
	}
}
