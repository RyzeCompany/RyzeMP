<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\end;

use pocketmine\world\dimension\Dimensions;
use pocketmine\world\dimension\generator\end\populator\ObsidianPillarPopulator;
use pocketmine\entity\Location;
use pocketmine\entity\object\EndCrystal;
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
		if($this->seed !== $world->getSeed()){
			$this->seed = $world->getSeed();
			$this->spawns = ObsidianPillarPopulator::crystalSpawns($this->seed);
		}
		foreach($this->spawns as $spawn){
			if($spawn["chunkX"] !== $chunkX || $spawn["chunkZ"] !== $chunkZ){
				continue;
			}
			$yaw = (new Random($this->seed ^ ($spawn["chunkX"] * 31 + $spawn["chunkZ"])))->nextFloat() * 360;
			$crystal = new EndCrystal(Location::fromObject($spawn["position"], $world, $yaw, 0.0));
			$crystal->setShowBase(true);
			$crystal->spawnToAll();
		}
	}
}
