<?php

declare(strict_types=1);

namespace pocketmine\world\dimension;

use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\Server;
use pocketmine\world\dimension\generator\GeneratorOptions;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\dimension\portal\PortalContent;
use pocketmine\world\dimension\portal\PortalTravel;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;

final class DimensionManager{

	public const NETHER_WORLD = "world_nether";
	public const END_WORLD = "world_the_end";

	private Dimensions $dimensions;
	private PortalTravel $portalTravel;

	public function __construct(private Server $server){
		VanillaGenerators::register();
		PortalContent::register($server->getAsyncPool(), $server->getCraftingManager());
		$this->dimensions = new Dimensions($server, [
			DimensionIds::NETHER => self::NETHER_WORLD,
			DimensionIds::THE_END => self::END_WORLD
		]);
		$this->portalTravel = new PortalTravel($this->dimensions);
	}

	public function getDimensions() : Dimensions{
		return $this->dimensions;
	}

	/** @phpstan-return DimensionIds::* */
	public function getDimension(World $world) : int{
		return $this->dimensions->getDimension($world);
	}

	public function prepareWorlds() : void{
		$this->prepareWorld(DimensionIds::NETHER, VanillaGenerators::NETHER);
		$this->prepareWorld(DimensionIds::THE_END, VanillaGenerators::END);
	}

	public function tick() : void{
		$this->portalTravel->tick($this->server);
	}

	private function prepareWorld(int $dimension, string $generatorName) : void{
		$name = $this->dimensions->getWorldName($dimension);
		if($name === null){
			return;
		}
		$worldManager = $this->server->getWorldManager();
		if($worldManager->getWorldByName($name) !== null){
			return;
		}
		if($worldManager->isWorldGenerated($name)){
			$worldManager->loadWorld($name, true);
			return;
		}
		$generator = GeneratorManager::getInstance()->getGenerator($generatorName);
		if($generator === null){
			$this->server->getLogger()->error("Native dimensions: generator {$generatorName} is not registered");
			return;
		}
		$options = WorldCreationOptions::create()
			->setGeneratorClass($generator->getGeneratorClass())
			->setGeneratorOptions(GeneratorOptions::encode(\pocketmine\RESOURCE_PATH));
		$seed = $worldManager->getDefaultWorld()?->getSeed();
		if($seed !== null){
			$options->setSeed($seed);
		}
		$worldManager->generateWorld($name, $options);
		$this->server->getLogger()->info("Native dimensions: created {$name}");
	}
}
