<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\dimension\Dimensions;
use pocketmine\world\World;
use function abs;
use function max;
use function min;

/** Finds a safe End landing without clearing blocks from custom maps. */
final class EndArrival{

	public const SEARCH_RADIUS = 6;
	public const VERTICAL_SEARCH = 12;

	/** Call only after the search area and platform footprint have been populated. */
	public static function findOrCreate(World $world, Vector3 $preferred, bool $createPlatform) : ?Vector3{
		$x = $preferred->getFloorX();
		$z = $preferred->getFloorZ();
		$minY = max($world->getMinY(), Dimensions::getMinY(DimensionIds::THE_END)) + 1;
		$maxY = min($world->getMaxY(), Dimensions::getMaxY(DimensionIds::THE_END)) - 3;
		$y = $preferred->getFloorY();
		if($y < $minY || $y > $maxY){
			return null;
		}
		for($radius = 0; $radius <= self::SEARCH_RADIUS; ++$radius){
			for($dx = -$radius; $dx <= $radius; ++$dx){
				for($dz = -$radius; $dz <= $radius; ++$dz){
					if(max(abs($dx), abs($dz)) !== $radius){
						continue;
					}
					for($height = 0; $height <= self::VERTICAL_SEARCH; ++$height){
						foreach($height === 0 ? [$y] : [$y + $height, $y - $height] as $candidateY){
							if($candidateY >= $minY && $candidateY <= $maxY && self::isSafe($world, $x + $dx, $candidateY, $z + $dz)){
								return new Vector3($x + $dx + 0.5, $candidateY, $z + $dz + 0.5);
							}
						}
					}
				}
			}
		}
		if(!$createPlatform){
			return null;
		}
		// Validate the entire volume before the first write. Partial clearing is unsafe.
		for($bx = $x - 2; $bx <= $x + 2; ++$bx){
			for($bz = $z - 2; $bz <= $z + 2; ++$bz){
				for($by = $y - 1; $by <= $y + 2; ++$by){
					if(!self::isAir($world, $bx, $by, $bz)){
						return null;
					}
				}
			}
		}
		for($bx = $x - 2; $bx <= $x + 2; ++$bx){
			for($bz = $z - 2; $bz <= $z + 2; ++$bz){
				$world->setBlockAt($bx, $y - 1, $bz, VanillaBlocks::OBSIDIAN());
			}
		}
		return new Vector3($x + 0.5, $y, $z + 0.5);
	}

	private static function isSafe(World $world, int $x, int $y, int $z) : bool{
		$floor = $world->getBlockAt($x, $y - 1, $z);
		return $floor->isFullCube() &&
			$floor->getTypeId() !== BlockTypeIds::MAGMA &&
			self::isAir($world, $x, $y, $z) && self::isAir($world, $x, $y + 1, $z);
	}

	private static function isAir(World $world, int $x, int $y, int $z) : bool{
		return $world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::AIR;
	}
}
