<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\end\populator;

use pocketmine\world\dimension\generator\ChunkHash;
use pocketmine\world\dimension\generator\end\EndIslands;
use pocketmine\world\dimension\generator\end\object\EndGateway;
use pocketmine\world\dimension\generator\nether\object\WorldQuery;
use pocketmine\world\dimension\generator\object\BlockManager;
use pocketmine\world\dimension\generator\Populator;
use pocketmine\world\dimension\generator\random\LegacyRandom;
use pocketmine\world\ChunkManager;

final class EndGatewayPopulator implements Populator{

	private LegacyRandom $random;
	private EndGateway $endGateway;

	public function __construct(){
		$this->random = new LegacyRandom(0);
		$this->endGateway = new EndGateway();
	}

	public function populate(BlockManager $root, ChunkManager $world, int $chunkX, int $chunkZ, int $seed) : void{
		if($chunkX * $chunkX + $chunkZ * $chunkZ <= 4096){
			return;
		}
		$random = $this->random;
		$random->setSeed($seed ^ ChunkHash::hash($chunkX, $chunkZ));
		if(EndIslands::height($seed, $chunkX, $chunkZ, 1, 1) > 40.0){
			if($random->nextBoundedInt(700) === 0){
				$x = ($chunkX << 4) + $random->nextBoundedInt(16);
				$z = ($chunkZ << 4) + $random->nextBoundedInt(16);
				$y = WorldQuery::heightMap($world, $x, $z) + $random->nextBoundedInt(7) + 3;
				if($y > 1 && $y < 254){
					$object = new BlockManager($world);
					$this->endGateway->generate($object, $x, $y, $z);
					$root->merge($object);
				}
			}
		}
	}
}
