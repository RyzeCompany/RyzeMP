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
use pocketmine\world\dimension\portal\PortalLinkManager;
use pocketmine\world\dimension\portal\PortalTravel;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\format\io\WorldProvider;
use pocketmine\world\format\io\data\BedrockWorldData;
use pocketmine\world\format\io\leveldb\LevelDB;
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
	private PortalLinkManager $portalLinks;

	public function __construct(private Server $server){
		$path = $server->getDataPath() . "dimensions.yml";
		if(!is_file($path) && !copy(\pocketmine\RESOURCE_PATH . "dimensions.yml", $path)){
			throw new \RuntimeException("Could not create dimensions.yml");
		}
		$this->settings = DimensionSettings::fromArray((new Config($path, Config::YAML))->getAll());
		VanillaGenerators::register();
		PortalContent::register($server->getAsyncPool(), $server->getCraftingManager());
		$this->dimensions = new Dimensions($server, $this->settings->getWorldNames());
		$this->portalLinks = new PortalLinkManager();
		$this->portalTravel = new PortalTravel($this->dimensions, $this->settings, $this->portalLinks);
		$this->endCrystalSpawner = new EndCrystalSpawner($this->dimensions);
	}

	public function getDimensions() : Dimensions{
		return $this->dimensions;
	}

	/** Returns the registry used by both Nether and End portal travel. Register links on the server thread. */
	public function getPortalLinkManager() : PortalLinkManager{
		return $this->portalLinks;
	}

	/** @phpstan-return DimensionIds::* */
	public function getDimension(World $world) : int{
		return $this->dimensions->getDimension($world);
	}

	/** @internal Select storage before World can load or generate any chunks. */
	public function configureWorldProvider(string $name, WorldProvider $provider) : void{
		foreach($this->settings->getWorldNames() as $dimension => $folder){
			if($name !== $folder){ continue; }
			if($provider instanceof LevelDB){
				$provider->configureDimension($dimension, $this->settings->getSourceDimension($dimension));
				$this->server->getLogger()->info("Native dimensions: $name uses LevelDB source dimension " . $provider->getStorageDimension());
				$data = $provider->getWorldData();
				// Imported level.dat describes its Overworld generator, even for a native Nether/End DB.
				// Preserve plugin generators; replace only the generic vanilla import defaults.
				if($data instanceof BedrockWorldData && in_array(strtolower($data->getGenerator()), ["default", "normal", "flat"], true)){
					$data->getCompoundTag()->setString("generatorName", $dimension === DimensionIds::NETHER ? VanillaGenerators::NETHER : VanillaGenerators::END);
					$data->getCompoundTag()->setString("generatorOptions", GeneratorOptions::encode(\pocketmine\RESOURCE_PATH));
				}
			}
			return;
		}
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

