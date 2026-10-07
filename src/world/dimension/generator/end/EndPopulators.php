<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\end;

use pocketmine\world\dimension\generator\end\populator\ChorusFlowerPopulator;
use pocketmine\world\dimension\generator\end\populator\EndGatewayPopulator;
use pocketmine\world\dimension\generator\end\populator\EndIslandPopulator;
use pocketmine\world\dimension\generator\end\populator\ExitPortalPopulator;
use pocketmine\world\dimension\generator\end\populator\ObsidianPillarPopulator;
use pocketmine\world\dimension\generator\Populator;
use pocketmine\world\dimension\generator\structure\EndCityPopulator;

/**
 * The end population pass, in execution order.
 */
final class EndPopulators{

	private function __construct(){
	}

	/**
	 * @return list<Populator>
	 */
	public static function create(int $seed, string $resourceFolder) : array{
		return [
			new ObsidianPillarPopulator(),
			new ExitPortalPopulator(),
			new ChorusFlowerPopulator(),
			new EndCityPopulator($seed, $resourceFolder),
			new EndGatewayPopulator(),
			new EndIslandPopulator(),
		];
	}
}
