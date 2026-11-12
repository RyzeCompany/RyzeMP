<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\VanillaBlocks;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use function abs;
use function min;
use function max;

/**
 * End obsidian spike terrain, based on VanillaMine's EndSpikes and
 * vanilla EndFeatures::spike() geometry.
 *
 * Only blocks in the requested chunk are written. The cylinder and cage
 * therefore cross chunk boundaries without changing adjacent chunk state
 * or depending on the order of population tasks.
 *
 * End crystal entities are deliberately handled in a later Mob AI/boss phase.
 */
final class VanillaEndSpikePopulator{

	private function __construct(){}

	public static function populate(ChunkManager $world, int $chunkX, int $chunkZ, int $seed) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Missing End chunk $chunkX,$chunkZ");
		$x0 = $chunkX << 4;
		$z0 = $chunkZ << 4;
		$x1 = $x0 + Chunk::EDGE_LENGTH - 1;
		$z1 = $z0 + Chunk::EDGE_LENGTH - 1;
		$obsidian = VanillaBlocks::OBSIDIAN()->getStateId();
		$ironBars = VanillaBlocks::IRON_BARS()->getStateId();
		$bedrock = VanillaBlocks::BEDROCK()->getStateId();
		$fire = VanillaBlocks::FIRE()->getStateId();
		$air = VanillaBlocks::AIR()->getStateId();
		$minY = max(0, $world->getMinY());
		$maxY = min(128, $world->getMaxY());

		foreach(EndSpikes::forSeed($seed) as $spike){
			$cx = $spike->centerX;
			$cz = $spike->centerZ;
			$radius = $spike->radius;
			// Reject non-intersecting chunks before walking any blocks.
			if($cx + $radius < $x0 || $cx - $radius > $x1 || $cz + $radius < $z0 || $cz - $radius > $z1){
				continue;
			}
			$radiusSquared = $radius * $radius + 1;
			for($x = max($x0, $cx - $radius); $x <= min($x1, $cx + $radius); ++$x){
				for($z = max($z0, $cz - $radius); $z <= min($z1, $cz + $radius); ++$z){
					$dx = $x - $cx;
					$dz = $z - $cz;
					$inside = $dx * $dx + $dz * $dz <= $radiusSquared;
					for($y = $minY; $y < $maxY && $y <= $spike->height + 10; ++$y){
						if($inside && $y < $spike->height){
							$chunk->setBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $obsidian);
						}elseif($y > 65){
							$chunk->setBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $air);
						}
					}
				}
			}

			if($spike->guarded){
				for($dx = -2; $dx <= 2; ++$dx){
					$x = $cx + $dx;
					if($x < $x0 || $x > $x1){
						continue;
					}
					for($dz = -2; $dz <= 2; ++$dz){
						$z = $cz + $dz;
						if($z < $z0 || $z > $z1){
							continue;
						}
						for($dy = 0; $dy <= 3; ++$dy){
							$y = $spike->height + $dy;
							if($y >= $minY && $y < $maxY && (abs($dx) === 2 || abs($dz) === 2 || $dy === 3)){
								// TODO: exact north/east/south/west iron-bar state orientation.
								$chunk->setBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $ironBars);
							}
						}
					}
				}
			}

			if($cx >= $x0 && $cx <= $x1 && $cz >= $z0 && $cz <= $z1 && $spike->height + 1 < $maxY){
				$lx = $cx & Chunk::COORD_MASK;
				$lz = $cz & Chunk::COORD_MASK;
				$chunk->setBlockStateId($lx, $spike->height, $lz, $bedrock);
				$chunk->setBlockStateId($lx, $spike->height + 1, $lz, $fire);
			}
		}
	}
}
