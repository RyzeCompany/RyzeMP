<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\nether\populator;

use pocketmine\world\dimension\generator\nether\object\OreGeneratorFeature;

final class NetherQuartzPopulator extends OreGeneratorFeature{

	public function name() : string{
		return "nether_quartz";
	}

	public function getState() : string{
		return "minecraft:quartz_ore";
	}

	public function getClusterCount() : int{
		return 20;
	}

	public function getClusterSize() : int{
		return 14;
	}

	public function getMinHeight() : int{
		return 10;
	}

	public function getMaxHeight() : int{
		return 117;
	}
}
