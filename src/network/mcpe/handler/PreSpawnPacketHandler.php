<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\data\bedrock\ArmorTrimMaterialTypeIdMap;
use pocketmine\data\bedrock\ArmorTrimPatternTypeIdMap;
use pocketmine\entity\property\EntityPropertyRegistry;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\item\ArmorTrimMaterial;
use pocketmine\item\ArmorTrimPattern;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\cache\CraftingDataCache;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\ServerboundLoadingScreenPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\TrimDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\GameMode as ProtocolGameMode;
use pocketmine\network\mcpe\protocol\types\hud\LoadingScreenType;
use pocketmine\network\mcpe\protocol\types\LevelSettings;
use pocketmine\network\mcpe\protocol\types\NetworkPermissions;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\ServerTelemetryData;
use pocketmine\network\mcpe\protocol\types\SpawnSettings;
use pocketmine\network\mcpe\protocol\types\TrimMaterial;
use pocketmine\network\mcpe\protocol\types\TrimPattern;
use pocketmine\network\mcpe\proxy\ProxyTicket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\VersionInfo;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\map\MapManager;
use Ramsey\Uuid\Uuid;
use function array_map;
use function sprintf;

/**
 * Handler used for the pre-spawn phase of the session.
 */
#[SilentDiscard(PlayerAuthInputPacket::class, comment: "Spammed after StartGame even though player has no controls")]
class PreSpawnPacketHandler extends PacketHandler{
	public function __construct(
		private Server $server,
		private Player $player,
		private NetworkSession $session,
		private InventoryManager $inventoryManager
	){}

	public function setUp() : void{
		Timings::$playerNetworkSendPreSpawnGameData->startTiming();
		try{
			$location = $this->player->getLocation();
			$world = $location->getWorld();

			$typeConverter = $this->session->getTypeConverter();

			$ticket = $this->session->getProxyTicket();
			if($ticket !== null && $ticket->transfer){
				$this->setUpProxyTransfer($ticket);
				return;
			}

			$this->session->getLogger()->debug("Preparing StartGamePacket");
			$levelSettings = new LevelSettings();
			$levelSettings->seed = -1;
			$levelSettings->spawnSettings = new SpawnSettings(SpawnSettings::BIOME_TYPE_DEFAULT, "", $world->getDimension()->getNetworkId());
			$this->session->setClientDimension($world->getDimension());
			$serverGameMode = $this->server->getGamemode();
			//the world game mode only matters for players sent DEFAULT (we always send the real one); BDS was never seen
			//with a spectator world game mode, so keep the value older servers sent for it
			$levelSettings->worldGamemode = $serverGameMode === GameMode::SPECTATOR ? ProtocolGameMode::CREATIVE : $typeConverter->coreGameModeToProtocol($serverGameMode);
			$levelSettings->difficulty = $world->getDifficulty();
			$levelSettings->spawnPosition = BlockPosition::fromVector3($world->getSpawnLocation());
			$levelSettings->hasAchievementsDisabled = true;
			$levelSettings->time = $world->getTime();
			$levelSettings->eduEditionOffer = 0;
			$levelSettings->rainLevel = $world->getWeather()->getRainLevel();
			$levelSettings->lightningLevel = $world->getWeather()->getLightningLevel();
			$levelSettings->commandsEnabled = true;
			$levelSettings->hardcore = $this->server->isHardcore(); //hardcore hearts and the hardcore death screen
			$levelSettings->gameRules = NetworkSession::getClientGameRules($world) + [
				"naturalregeneration" => new BoolGameRule(false, false) //Hack for client side regeneration
			];
			$levelSettings->experiments = new Experiments([], false);

			//1.26.50+: the client disconnects with "MissingStructureData" unless it gets this before StartGamePacket
			$this->session->getLogger()->debug("Sending jigsaw structure data");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getJigsawStructureData());

			$this->session->sendDataPacket(StartGamePacket::create(
				$this->player->getId(),
				$this->player->getId(),
				$typeConverter->coreGameModeToProtocol($this->player->getGamemode()),
				$this->player->getOffsetPosition($location),
				$location->pitch,
				$location->yaw,
				new CacheableNbt(CompoundTag::create()), //TODO: we don't care about this right now
				$levelSettings,
				"",
				$this->server->getMotd(),
				"",
				false,
				new PlayerMovementSettings(0, true),
				0,
				0,
				"",
				true,
				sprintf("%s %s", VersionInfo::NAME, VersionInfo::VERSION()->getFullVersion(true)),
				Uuid::fromString(Uuid::NIL),
				false,
				true, //blockNetworkIdsAreHashes - see BlockStateDictionary::computeNetworkId()
				new NetworkPermissions(disableClientSounds: true),
				true,
				null,
				new ServerTelemetryData("", "", "", ""),
				[],
				0,
			));

			$this->session->getLogger()->debug("Sending items");
			$this->session->sendDataPacket(ItemRegistryPacket::create($typeConverter->getItemTypeDictionary()->getEntries()));

			$this->session->getLogger()->debug("Sending actor identifiers");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getAvailableActorIdentifiers());

			$this->session->getLogger()->debug("Sending actor properties");
			foreach(EntityPropertyRegistry::getInstance()->getSyncPackets() as $packet){
				$this->session->sendDataPacket($packet);
			}

			$this->session->getLogger()->debug("Sending biome definitions");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getBiomeDefs());

			$this->sendPlayerState();
		}finally{
			Timings::$playerNetworkSendPreSpawnGameData->stopTiming();
		}
	}

	/**
	 * A player the proxy moved here from another server of the network: the client keeps everything StartGamePacket
	 * and the static data packets gave it (same server software, same registries), so it only gets this server's
	 * state for the player and a dimension change into this server's world.
	 */
	private function setUpProxyTransfer(ProxyTicket $ticket) : void{
		$this->session->getLogger()->debug("Player transferred by the proxy: no StartGamePacket, changing dimension instead");
		$typeConverter = $this->session->getTypeConverter();
		$this->session->sendDataPacket(SetPlayerGameTypePacket::create($typeConverter->coreGameModeToProtocol($this->player->getGamemode())));
		$this->sendPlayerState();
		$this->session->beginProxyTransferIn(Dimension::fromNetworkId($ticket->dimension) ?? Dimension::OVERWORLD);
		//the client asked for its view distance on its first server and will not ask again
		$this->player->setViewDistance($ticket->viewDistance > 0 ? $ticket->viewDistance : $this->server->getViewDistance());
	}

	/**
	 * State of the player sent after StartGamePacket: attributes, commands, abilities, effects, inventory, recipes...
	 */
	private function sendPlayerState() : void{
		$this->session->getLogger()->debug("Sending attributes");
		$this->session->getEntityEventBroadcaster()->syncAttributes([$this->session], $this->player, $this->player->getAttributeMap()->getAll());

		$this->session->getLogger()->debug("Sending available commands");
		$this->session->syncAvailableCommands();

		$this->session->getLogger()->debug("Sending abilities");
		$this->session->syncAbilities($this->player);
		$this->session->syncAdventureSettings();

		$this->session->getLogger()->debug("Sending effects");
		foreach($this->player->getEffects()->all() as $effect){
			$this->session->getEntityEventBroadcaster()->onEntityEffectAdded([$this->session], $this->player, $effect, false);
		}

		$this->session->getLogger()->debug("Sending actor metadata");
		$this->player->sendData([$this->player]);
		//the player has everything now and nobody else sees it yet (AddPlayer carries all of it): without this the
		//first tick sent the same full metadata again
		$this->player->getNetworkProperties()->clearDirtyProperties();

		$this->session->getLogger()->debug("Sending inventory");
		$this->inventoryManager->syncAll();
		$this->inventoryManager->syncSelectedHotbarSlot();

		$this->session->getLogger()->debug("Sending creative inventory data");
		$this->inventoryManager->syncCreative();

		$this->session->getLogger()->debug("Sending armor trim data");

		$serializer = GlobalItemDataHandlers::getSerializer();
		$patternMap = ArmorTrimPatternTypeIdMap::getInstance();
		$materialMap = ArmorTrimMaterialTypeIdMap::getInstance();

		$this->session->sendDataPacket(TrimDataPacket::create(
			array_map(fn(ArmorTrimPattern $pattern) => new TrimPattern($serializer->serializeType($pattern->getItem())->getName(), $patternMap->toId($pattern)), $patternMap->getAllPatterns()),
			array_map(fn(ArmorTrimMaterial $material) => new TrimMaterial($materialMap->toId($material), $material->getColor(), $serializer->serializeType($material->getItem())->getName()), $materialMap->getAllMaterials()))
		);

		$this->session->getLogger()->debug("Sending crafting data");
		$craftingDataCache = CraftingDataCache::getInstance();
		$craftingManager = $this->server->getCraftingManager();
		if(DataPacketSendEvent::hasHandlers()){
			//the handlers get the cached packet itself and may change it, as before: it is encoded as they leave it
			$craftingDataCache->clearEncodedCache($craftingManager);
			$this->session->sendDataPacket($craftingDataCache->getCache($craftingManager));
		}else{
			$this->session->sendEncodedDataPacket(
				$craftingDataCache->getCache($craftingManager),
				$craftingDataCache->getEncodedCache($craftingManager, $this->session->getTypeConverter())
			);
		}

		$this->session->getLogger()->debug("Sending player list");
		$this->session->syncPlayerList($this->server->getOnlinePlayers());
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->radius);

		return true;
	}

	public function handlePlayerAction(PlayerActionPacket $packet) : bool{
		//a player transferred by the proxy goes through a dimension change before it spawns here
		if($packet->action === PlayerAction::DIMENSION_CHANGE_ACK){
			$this->session->onClientDimensionChangeDone(null);
		}
		return true;
	}

	public function handleMapInfoRequest(MapInfoRequestPacket $packet) : bool{
		//the client asks for the maps in its inventory as soon as it gets them, and only once: the request is kept and
		//answered once the player has spawned
		MapManager::getInstance()->handleMapInfoRequest($this->player, $packet->mapId);
		return true;
	}

	public function handleServerboundLoadingScreen(ServerboundLoadingScreenPacket $packet) : bool{
		if($packet->getLoadingScreenType() === LoadingScreenType::STOP_LOADING_SCREEN && $packet->getLoadingScreenId() !== null){
			$this->session->onClientDimensionChangeDone($packet->getLoadingScreenId());
		}
		return true;
	}
}
