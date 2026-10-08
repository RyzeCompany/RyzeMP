<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\nether\populator;

use pocketmine\world\dimension\generator\nether\object\OreGeneratorFeature;

final class LavaOrePopulator extends OreGeneratorFeature{

	public function name() : string{
		return "nether_lava_ore";
	}

	public function getState() : string{
		return "minecraft:lava";
	}

	public function getClusterCount() : int{
		return 32;
	}

	public function getClusterSize() : int{
		return 1;
	}

	public function getMinHeight() : int{
		return 0;
	}

	public function getMaxHeight() : int{
		return 32;
	}
}
