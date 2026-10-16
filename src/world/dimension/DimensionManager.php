<?php

declare(strict_types=1);

namespace pocketmine\world\dimension;

use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\world\dimension\generator\GeneratorOptions;
use pocketmine\world\dimension\generator\end\EndCrystalSpawner;
use pocketmine\world\dimension\generator\VanillaGenerators;
use pocketmine\world\dimension\portal\PortalContent;
use pocketmine\world\dimension\portal\PortalTravel;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use function copy;
use function is_file;

final class DimensionManager{

	public const NETHER_WORLD = "world_nether";
	public const END_WORLD = "world_the_end";

	private Dimensions $dimensions;
	private PortalTravel $portalTravel;
	private EndCrystalSpawner $endCrystalSpawner;
	private DimensionSettings $settings;

	public function __construct(private Server $server){
		$path = $server->getDataPath() . "dimensions.yml";
		if(!is_file($path) && !copy(\pocketmine\RESOURCE_PATH . "dimensions.yml", $path)){
			throw new \RuntimeException("Could not create dimensions.yml");
		}
		$this->settings = DimensionSettings::fromArray((new Config($path, Config::YAML))->getAll());
		VanillaGenerators::register();
		PortalContent::register($server->getAsyncPool(), $server->getCraftingManager());
		$this->dimensions = new Dimensions($server, $this->settings->getWorldNames());
		$this->portalTravel = new PortalTravel($this->dimensions, $this->settings);
		$this->endCrystalSpawner = new EndCrystalSpawner($this->dimensions);
	}

	public function getDimensions() : Dimensions{
		return $this->dimensions;
	}

	/** @phpstan-return DimensionIds::* */
	public function getDimension(World $world) : int{
		return $this->dimensions->getDimension($world);
	}

	public function prepareWorlds() : void{
		$overworld = $this->server->getWorldManager()->getDefaultWorld();
		if($overworld !== null){
			$this->settings->validateOverworld($overworld->getFolderName());
		}
		$this->prepareWorld(DimensionIds::NETHER, VanillaGenerators::NETHER);
		$this->prepareWorld(DimensionIds::THE_END, VanillaGenerators::END);
	}

	public function tick() : void{
		$this->portalTravel->tick();
	}

	public function touchNetherPortal(Entity $entity) : void{
		$this->portalTravel->touchNetherPortal($entity);
	}

	public function touchEndPortal(Entity $entity) : void{
		$this->portalTravel->touchEndPortal($entity);
	}

	/** Invalidates delayed portal callbacks after a successful external teleport. */
	public function cancelPendingPortalTravel(Entity $entity) : void{
		$this->portalTravel->cancelPending($entity->getId());
	}

	/** Releases portal state when an entity dies or is disposed. */
	public function forgetEntity(Entity $entity) : void{
		$this->portalTravel->forget($entity->getId());
	}

	public function onChunkPopulated(World $world, int $chunkX, int $chunkZ) : void{
		$this->endCrystalSpawner->onChunkPopulated($world, $chunkX, $chunkZ);
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

