<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\end\object;

use pocketmine\world\dimension\generator\object\BlockManager;
use function abs;

/**
 * Bedrock fountain and active exit portal, centred on the given position.
 *
 * Until the native Ender Dragon fight is implemented, the exit portal is
 * generated active so players always have a survival path back to the
 * Overworld. The active surface contains the vanilla 20 End Portal blocks:
 * a 5x5 area without the four corners and central bedrock pillar.
 */
final class ExitPortal{

	private const BEDROCK = "minecraft:bedrock";
	private const END_PORTAL = "minecraft:end_portal";

	public function generate(BlockManager $level, int $x, int $y, int $z) : void{
		for($dx = -2; $dx <= 2; $dx++){
			for($dz = -2; $dz <= 2; $dz++){
				if(!(abs($dx) === 2 && abs($dz) === 2)){
					$level->setBlockStateAt($x + $dx, $y - 1, $z + $dz, self::BEDROCK);
					if($dx === 0 && $dz === 0){
						$level->setBlockStateAt($x, $y, $z, self::BEDROCK);
					}else{
						$level->setBlockStateAt($x + $dx, $y, $z + $dz, self::END_PORTAL);
					}
					if($dx === -2){
						$level->setBlockStateAt($x - 3, $y, $z + $dz, self::BEDROCK);
					}
					if($dx === 2){
						$level->setBlockStateAt($x + 3, $y, $z + $dz, self::BEDROCK);
					}
					if($dz === -2){
						$level->setBlockStateAt($x + $dx, $y, $z - 3, self::BEDROCK);
					}
					if($dz === 2){
						$level->setBlockStateAt($x + $dx, $y, $z + 3, self::BEDROCK);
					}
				}else{
					$level->setBlockStateAt($x + $dx, $y, $z + $dz, self::BEDROCK);
				}
			}
		}
		for($i = 0; $i < 4; $i++){
			$level->setBlockStateAt($x, $y + $i, $z, self::BEDROCK);
		}
	}
}
