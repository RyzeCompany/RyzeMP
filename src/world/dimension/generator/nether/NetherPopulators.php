<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\nether;

use pocketmine\world\dimension\generator\nether\populator\AncientDebrisLargePopulator;
use pocketmine\world\dimension\generator\nether\populator\AncientDebrisSmallPopulator;
use pocketmine\world\dimension\generator\nether\populator\BasaltDeltaLavaPopulator;
use pocketmine\world\dimension\generator\nether\populator\BasaltDeltaMagmaPopulator;
use pocketmine\world\dimension\generator\nether\populator\BasaltDeltaPillarPopulator;
use pocketmine\world\dimension\generator\nether\populator\CrimsonFungiTreePopulator;
use pocketmine\world\dimension\generator\nether\populator\CrimsonGrassesPopulator;
use pocketmine\world\dimension\generator\nether\populator\CrimsonWeepingVinesPopulator;
use pocketmine\world\dimension\generator\nether\populator\FirePopulator;
use pocketmine\world\dimension\generator\nether\populator\GlowstonePopulator;
use pocketmine\world\dimension\generator\nether\populator\LavaOrePopulator;
use pocketmine\world\dimension\generator\nether\populator\LavaPopulator;
use pocketmine\world\dimension\generator\nether\populator\MagmaPopulator;
use pocketmine\world\dimension\generator\nether\populator\NetherBlackstonePopulator;
use pocketmine\world\dimension\generator\nether\populator\NetherGoldOrePopulator;
use pocketmine\world\dimension\generator\nether\populator\NetherGravelPopulator;
use pocketmine\world\dimension\generator\nether\populator\NetherQuartzPopulator;
use pocketmine\world\dimension\generator\nether\populator\SoulsandPopulator;
use pocketmine\world\dimension\generator\nether\populator\WarpedFungiTreePopulator;
use pocketmine\world\dimension\generator\nether\populator\WarpedGrassesPopulator;
use pocketmine\world\dimension\generator\nether\populator\WarpedTwistingVinesPopulator;
use pocketmine\world\dimension\generator\Populator;
use pocketmine\world\dimension\generator\structure\BastionRemnantPopulator;
use pocketmine\world\dimension\generator\structure\NetherFortressPopulator;
use pocketmine\world\dimension\generator\structure\NetherFossilPopulator;
use pocketmine\world\dimension\generator\structure\RuinedPortalPopulator;

/**
 * The nether population pass, in execution order.
 */
final class NetherPopulators{

	private function __construct(){
	}

	/**
	 * @return list<Populator>
	 */
	public static function create(int $seed, string $resourceFolder) : array{
		return [
			new GlowstonePopulator(),
			new SoulsandPopulator(),
			new MagmaPopulator(),
			new LavaOrePopulator(),
			new FirePopulator(),
			new LavaPopulator(),
			new NetherGoldOrePopulator(),
			new AncientDebrisSmallPopulator(),
			new AncientDebrisLargePopulator(),
			new NetherQuartzPopulator(),
			new BasaltDeltaLavaPopulator(),
			new BasaltDeltaPillarPopulator(),
			new BasaltDeltaMagmaPopulator(),
			new CrimsonFungiTreePopulator(),
			new CrimsonGrassesPopulator(),
			new CrimsonWeepingVinesPopulator(),
			new WarpedFungiTreePopulator(),
			new WarpedGrassesPopulator(),
			new WarpedTwistingVinesPopulator(),
			new NetherBlackstonePopulator(),
			new NetherGravelPopulator(),
			new BastionRemnantPopulator($seed, $resourceFolder),
			new NetherFortressPopulator($seed, $resourceFolder),
			new RuinedPortalPopulator($seed, $resourceFolder),
			new NetherFossilPopulator($seed, $resourceFolder),
		];
	}
}
