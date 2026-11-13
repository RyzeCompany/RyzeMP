<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\end;

use pocketmine\world\dimension\Dimensions;
use pocketmine\world\dimension\generator\end\populator\ObsidianPillarPopulator;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\generator\vanilla\EndSpikes;
use pocketmine\world\generator\vanilla\VanillaDataIdentity;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\utils\Random;
use pocketmine\world\World;

/**
 * Places the end crystal on top of each obsidian spike. Spikes are built on
 * the generation threads, which cannot create entities, so the crystal of a
 * spike is spawned on the main thread once the chunk that builds it has been
 * populated.
 */
final class EndCrystalSpawner{

	private ?int $seed = null;

	/** @var list<array{chunkX: int, chunkZ: int, position: \pocketmine\math\Vector3}> */
	private array $spawns = [];

	public function __construct(
		private Dimensions $dimensions
	){}

	public function onChunkPopulated(World $world, int $chunkX, int $chunkZ) : void{
		if($this->dimensions->getDimension($world) !== DimensionIds::THE_END){
			return;
		}
		$data = $world->getProvider()->getWorldData();
		if($data->getGenerator() === VanillaGenerators::END_CORE && VanillaDataIdentity::hasStructureContent($data->getGeneratorOptions())){
			foreach(EndSpikes::forSeed($world->getSeed()) as $spike){
				if($spike->isCenterWithinChunk($chunkX, $chunkZ) &&
					$world->getBlockAt($spike->centerX, $spike->height, $spike->centerZ)->getTypeId() === VanillaBlocks::BEDROCK()->getTypeId()){
					$this->spawnCrystal($world, new Vector3($spike->centerX + 0.5, $spike->height + 1, $spike->centerZ + 0.5), $chunkX, $chunkZ);
				}
			}
			return;
		}
		if($this->seed !== $world->getSeed()){
			$this->seed = $world->getSeed();
			$this->spawns = ObsidianPillarPopulator::crystalSpawns($this->seed);
		}
		foreach($this->spawns as $spawn){
			if($spawn["chunkX"] !== $chunkX || $spawn["chunkZ"] !== $chunkZ){
				continue;
			}
			$this->spawnCrystal($world, $spawn["position"], $chunkX, $chunkZ);
		}
	}

	private function spawnCrystal(World $world, Vector3 $position, int $chunkX, int $chunkZ) : void{
		$yaw = (new Random($world->getSeed() ^ ($chunkX * 31 + $chunkZ)))->nextFloat() * 360;
		$crystal = new EndCrystal(Location::fromObject($position, $world, $yaw, 0.0));
		$crystal->setShowBase(true);
		$crystal->spawnToAll();
	}
}
