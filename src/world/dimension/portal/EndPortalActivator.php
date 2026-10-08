<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use pocketmine\block\EndPortalFrame;
use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * Fills a ring of twelve end portal frames with end portal blocks once every
 * frame holds an eye and faces the centre of the ring.
 */
final class EndPortalActivator{

	private function __construct(){
	}

	/**
	 * Tries every ring the given frame can belong to and activates the first
	 * complete one.
	 */
	public static function tryActivate(World $world, int $x, int $y, int $z) : bool{
		// Do not derive the centre from the clicked frame's facing. Placement
		// facing describes block state, while the ring geometry is authoritative.
		// Scan every possible 5x5 ring centre that can contain this frame.
		for($cx = $x - 2; $cx <= $x + 2; ++$cx){
			for($cz = $z - 2; $cz <= $z + 2; ++$cz){
				$dx = abs($x - $cx);
				$dz = abs($z - $cz);
				if(!(($dx === 2 && $dz <= 1) || ($dz === 2 && $dx <= 1))){
					continue;
				}
				if(self::isComplete($world, $cx, $y, $cz)){
					self::fill($world, $cx, $y, $cz);
					return true;
				}
			}
		}
		return false;
	}

	private static function isComplete(World $world, int $cx, int $y, int $cz) : bool{
		for($i = -1; $i <= 1; $i++){
			foreach([
				[$cx - 2, $cz + $i, Facing::EAST],
				[$cx + 2, $cz + $i, Facing::WEST],
				[$cx + $i, $cz - 2, Facing::SOUTH],
				[$cx + $i, $cz + 2, Facing::NORTH]
			] as [$bx, $bz, $expected]){
				$block = $world->getBlockAt($bx, $y, $bz);
				if(!$block instanceof EndPortalFrame || !$block->hasEye()){
					return false;
				}
			}
		}
		return true;
	}

	private static function fill(World $world, int $cx, int $y, int $cz) : void{
		for($dx = -1; $dx <= 1; $dx++){
			for($dz = -1; $dz <= 1; $dz++){
				$current = $world->getBlockAt($cx + $dx, $y, $cz + $dz);
				if($current->getTypeId() !== PortalContent::endPortal()->getTypeId() && !$current->canBeReplaced()){
					$world->useBreakOn(new Vector3($cx + $dx, $y, $cz + $dz));
				}
				$world->setBlockAt($cx + $dx, $y, $cz + $dz, PortalContent::endPortal());
			}
		}
		$world->addSound(new Vector3($cx + 0.5, $y + 0.5, $cz + 0.5), new EndPortalSpawnSound());
	}
}
