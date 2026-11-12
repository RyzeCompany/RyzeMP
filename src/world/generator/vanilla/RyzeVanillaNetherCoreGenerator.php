<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

/** Opt-in Nether density and biome pipeline. Does not change DimensionManager defaults. */
final class RyzeVanillaNetherCoreGenerator extends VanillaDimensionCoreGenerator{
	protected const DIMENSION = "nether";
}
