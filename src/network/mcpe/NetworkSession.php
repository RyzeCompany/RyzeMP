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

namespace pocketmine\network\mcpe;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\EntityDataHelper;
use pocketmine\event\player\PlayerDuplicateLoginEvent;
use pocketmine\event\player\PlayerResourcePackOfferEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\form\Form;
use pocketmine\item\Item;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\FilterNoisyPacketException;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\cache\ChunkRequestWorkers;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\CommandOverloadMapper;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\encryption\PrepareEncryptionTask;
use pocketmine\network\mcpe\handler\DeathPacketHandler;
use pocketmine\network\mcpe\handler\HandshakePacketHandler;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\handler\PacketHandlerAction;
use pocketmine\network\mcpe\handler\PacketHandlerInspector;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\handler\ResourcePacksPacketHandler;
use pocketmine\network\mcpe\handler\SessionStartPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\ChangeDimensionPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\GameRulesChangedPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\MovementEffectPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\OpenSignPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerStartItemCooldownPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\SetDifficultyPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\network\mcpe\protocol\TransferPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\DisconnectFailReason;
use pocketmine\network\mcpe\protocol\types\GameRule as ProtocolGameRule;
use pocketmine\network\mcpe\protocol\types\IntGameRule;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\MovementEffectType;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\network\mcpe\protocol\UpdateAdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\UpdatePlayerGameTypePacket;
use pocketmine\network\mcpe\proxy\ProxyPacketSender;
use pocketmine\network\mcpe\proxy\ProxyTicket;
use pocketmine\network\mcpe\proxy\ProxyTransferStyle;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\UsedChunkStatus;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\AsyncPool;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Binary;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\TextFormat;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function array_map;
use function array_slice;
use function array_unshift;
use function array_values;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chunk_split;
use function class_exists;
use function count;
use function get_class;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_encode;
use function max;
use function method_exists;
use function min;
use function openssl_pkey_get_details;
use function openssl_pkey_get_public;
use function ord;
use function random_bytes;
use function str_ends_with;
use function str_split;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function ucfirst;
use const JSON_THROW_ON_ERROR;
use const M_PI;

class NetworkSession{
	/**
	 * Most parameters a client accepts in a translated TextPacket (1.26.x clients disconnect with "Too many input
	 * elements, expected no more than 4" otherwise). Messages with more are translated by the server.
	 */
	private const MAX_CLIENT_TRANSLATION_PARAMETERS = 4;

	private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
	private const INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

	private const INCOMING_GAME_PACKETS_PER_TICK = 2;
	private const INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

	private const INCOMING_PACKET_BATCH_HARD_LIMIT = 300;

	/** Radius of empty chunks sent around the player in the detour dimension of a same-dimension world switch. */
	private const DETOUR_CHUNK_RADIUS = 3;

	/**
	 * Payload of an empty chunk per dimension network ID, for the detour of same-dimension world switches.
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private static array $emptyChunkPayloads = [];

	private PacketRateLimiter $packetBatchLimiter;
	private PacketRateLimiter $gamePacketLimiter;

	private \PrefixedLogger $logger;
	private ?Player $player = null;
	private ?PlayerInfo $info = null;
	private ?int $ping = null;

	private ?PacketHandler $handler = null;
	/**
	 * @var PacketHandlerAction[]|null
	 * @phpstan-var array<class-string<Packet>, PacketHandlerAction>|null
	 */
	private ?array $handlerActions = null;

	private bool $connected = true;
	/** connected sessions with a non-standard broadcaster or compressor, see hasOnlyStandardSessions() */
	private static int $nonStandardSessions = 0;
	private bool $standardSession;

	/** the send buffers of every session, in C++ (chunkutils2 >= 0.3.9), null = PHP arrays */
	private static ?NativeSendBuffers $nativeSendBuffers = null;
	private static bool $nativeSendBuffersChecked = false;
	private static int $nextSendId = 1;
	/** whether the native send buffers have openNew() (chunkutils2 >= 0.3.10); null = not checked yet */
	private static ?bool $nativeOpenNew = null;
	/**
	 * id of this session's native send buffer; never reused by its NativeSendBuffers instance ($sendBuffers), so stale
	 * viewer lists cannot reach another session
	 */
	private int $sendId;
	/**
	 * the native send buffers which gave $sendId and hold this session's packets (those of getNativeSendBuffers() when
	 * the session was made), null = the PHP array $sendBuffer. Always the same instance outside tests; when a test swaps
	 * it (setNativeSendBuffersEnabled()), the session keeps its own: ids are unique only within one instance (openNew()
	 * gives the same ids in a new one), so the new instance may have another session under this id.
	 */
	private ?NativeSendBuffers $sendBuffers = null;
	private bool $disconnectGuard = false;
	private bool $loggedIn = false;
	private bool $authenticated = false;
	private int $connectTime;
	private ?CompoundTag $cachedOfflinePlayerData = null;
	private ?SpawnTerrainPrefetch $spawnTerrainPrefetch = null;

	private ?EncryptionContext $cipher = null;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $sendBuffer = [];
	/**
	 * @var PromiseResolver[]
	 * @phpstan-var list<PromiseResolver<true>>
	 */
	private array $sendBufferAckPromises = [];

	/**
	 * Batches waiting to be sent, in order, and actions that must run once every batch queued before them was sent
	 * (enabling encryption).
	 * @phpstan-var \SplQueue<array{CompressBatchPromise|string|\Closure() : void, list<PromiseResolver<true>>, bool}>
	 */
	private \SplQueue $compressedQueue;
	private bool $forceAsyncCompression = true;
	private bool $enableCompression = false; //disabled until handshake completed

	private int $nextAckReceiptId = 0;
	/**
	 * @var PromiseResolver[][]
	 * @phpstan-var array<int, list<PromiseResolver<true>>>
	 */
	private array $ackPromisesByReceiptId = [];

	private ?InventoryManager $invManager = null;

	/** Dimension the client currently renders, dimension changes in progress and pending world switches. */
	private ClientDimensionTracker $dimensionTracker;
	private int $dimensionChangeStartTick = 0;
	/** Server tick at which the server-side dimension change ack must be sent at the latest, or null if none pending. */
	private ?int $dimensionChangeAckDeadline = null;

	/**
	 * @var \Closure[]|ObjectSet
	 * @phpstan-var ObjectSet<\Closure() : void>
	 */
	private ObjectSet $disposeHooks;

	private string $noisyPacketBuffer = "";
	private int $noisyPacketsDropped = 0;

	/** Most recent PlayerAuthInput tick processed, echoed back in client-predicted packets such as MovementEffect. */
	private int $lastPlayerInputTick = 0;

	public function __construct(
		private Server $server,
		private NetworkSessionManager $manager,
		private PacketPool $packetPool,
		private PacketSender $sender,
		private PacketBroadcaster $broadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private Compressor $compressor,
		private TypeConverter $typeConverter,
		private string $ip,
		private int $port
	){
		$this->logger = new \PrefixedLogger($this->server->getLogger(), $this->getLogPrefix());

		$this->standardSession = $this->isStandardSession();
		if(!$this->standardSession){
			self::$nonStandardSessions++;
		}
		$native = $this->sendBuffers = self::getNativeSendBuffers();
		//chunkutils2 >= 0.3.10 picks the id itself: a slot of a dense table plus a generation, never valid twice, found
		//by an array index instead of a hash lookup on every append (every broadcast appends to many sessions)
		/** @phpstan-ignore function.alreadyNarrowedType (older chunkutils2 versions lack it; the PHPStan stub always has it) */
		if($native !== null && (self::$nativeOpenNew ??= method_exists($native, "openNew"))){
			$this->sendId = $native->openNew();
		}else{
			$this->sendId = self::$nextSendId++;
			$native?->open($this->sendId);
		}

		$this->compressedQueue = new \SplQueue();

		$this->disposeHooks = new ObjectSet();

		$this->dimensionTracker = new ClientDimensionTracker($this->server->getConfigGroup()->getPropertyBool(YmlServerProperties::PLAYER_CLEAR_TERRAIN_ON_WORLD_CHANGE, true));

		$this->connectTime = time();
		$this->packetBatchLimiter = new PacketRateLimiter("Packet Batches", self::INCOMING_PACKET_BATCH_PER_TICK, self::INCOMING_PACKET_BATCH_BUFFER_TICKS);
		$this->gamePacketLimiter = new PacketRateLimiter("Game Packets", self::INCOMING_GAME_PACKETS_PER_TICK, self::INCOMING_GAME_PACKETS_BUFFER_TICKS);

		$this->setHandler(new SessionStartPacketHandler(
			$this,
			$this->onSessionStartSuccess(...)
		));

		$this->manager->add($this);
		$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_open()));
	}

	private function getLogPrefix() : string{
		return "NetworkSession: " . $this->getDisplayName();
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	private function onSessionStartSuccess() : void{
		$this->logger->debug("Session start handshake completed, awaiting login packet");
		$this->flushGamePacketQueue();
		$this->enableCompression = true;
		$this->setHandler(new LoginPacketHandler(
			$this->server,
			$this,
			function(PlayerInfo $info) : void{
				$this->info = $info;
				$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_playerName(TextFormat::AQUA . $info->getUsername() . TextFormat::RESET)));
				$this->logger->setPrefix($this->getLogPrefix());
				$this->manager->markLoginReceived($this);
			},
			$this->setAuthenticationStatus(...)
		));
	}

	protected function createPlayer() : void{
		$this->server->createPlayer($this, $this->info, $this->authenticated, $this->cachedOfflinePlayerData)->onCompletion(
			$this->onPlayerCreated(...),
			function() : void{
				//TODO: this should never actually occur... right?
				$this->disconnectWithError(
					reason: "Failed to create player",
					disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_internal()
				);
			}
		);
	}

	private function onPlayerCreated(Player $player) : void{
		if(!$this->isConnected()){
			//the remote player might have disconnected before spawn terrain generation was finished
			return;
		}
		$this->player = $player;
		if(!$this->server->addOnlinePlayer($player)){
			return;
		}

		$this->invManager = new InventoryManager($this->player, $this);

		$effectManager = $this->player->getEffects();
		$effectManager->getEffectAddHooks()->add($effectAddHook = function(EffectInstance $effect, bool $replacesOldEffect) : void{
			$this->entityEventBroadcaster->onEntityEffectAdded([$this], $this->player, $effect, $replacesOldEffect);
		});
		$effectManager->getEffectRemoveHooks()->add($effectRemoveHook = function(EffectInstance $effect) : void{
			$this->entityEventBroadcaster->onEntityEffectRemoved([$this], $this->player, $effect);
		});
		$this->disposeHooks->add(static function() use ($effectManager, $effectAddHook, $effectRemoveHook) : void{
			$effectManager->getEffectAddHooks()->remove($effectAddHook);
			$effectManager->getEffectRemoveHooks()->remove($effectRemoveHook);
		});

		$permissionHooks = $this->player->getPermissionRecalculationCallbacks();
		$permissionHooks->add($permHook = function() : void{
			$this->logger->debug("Syncing available commands and abilities/permissions due to permission recalculation");
			$this->syncAbilities($this->player);
			$this->syncAvailableCommands();
		});
		$this->disposeHooks->add(static function() use ($permissionHooks, $permHook) : void{
			$permissionHooks->remove($permHook);
		});
		//a new player's spawn spot is known now (and a plugin may have chosen another place)
		$this->prefetchSpawnTerrain($player->getPosition());
		$this->beginSpawnSequence();
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getPlayerInfo() : ?PlayerInfo{
		return $this->info;
	}

	public function isConnected() : bool{
		return $this->connected && !$this->disconnectGuard;
	}

	/**
	 * Returns whether every connected session uses the standard packet broadcaster and the server's compressor. Then a
	 * broadcast does not need to look at each recipient's broadcaster and compressor (two calls per recipient of every
	 * broadcast: thousands per tick with many players close together).
	 *
	 * @internal
	 */
	public static function hasOnlyStandardSessions() : bool{
		return self::$nonStandardSessions === 0;
	}

	private function isStandardSession() : bool{
		return $this->broadcaster instanceof StandardPacketBroadcaster && $this->compressor === ZlibCompressor::getInstance();
	}

	/**
	 * The native send buffers of the sessions (chunkutils2 >= 0.3.9), or null when the extension doesn't provide them.
	 * A packet for many sessions is appended to all of them in one call, see {@link NetworkBroadcastUtils}.
	 *
	 * @internal
	 */
	public static function getNativeSendBuffers() : ?NativeSendBuffers{
		if(!self::$nativeSendBuffersChecked){
			self::$nativeSendBuffersChecked = true;
			if(class_exists(NativeSendBuffers::class)){
				self::$nativeSendBuffers = new NativeSendBuffers();
			}
		}
		return self::$nativeSendBuffers;
	}

	/**
	 * @internal for tests whose fake sessions bypass the constructor: disables (or enables again, with a new instance)
	 * the native send buffers. Sessions made before keep the native send buffers which gave their send id for their own
	 * packets (direct sends, flushes, closing), but their send ids mean nothing to the new instance (it may give the same
	 * ids to other sessions): broadcasts with their ids (Entity::$viewerSendIds of their players, getSendId()) must not
	 * reach the new instance, so such sessions must not receive broadcasts after the call.
	 */
	public static function setNativeSendBuffersEnabled(bool $enabled) : void{
		self::$nativeSendBuffersChecked = true;
		self::$nativeSendBuffers = $enabled && class_exists(NativeSendBuffers::class) ? new NativeSendBuffers() : null;
	}

	/**
	 * Id of this session's native send buffer (see getNativeSendBuffers()); valid for the session's lifetime and never
	 * given to another session by the same NativeSendBuffers instance (there is only one outside tests, see
	 * setNativeSendBuffersEnabled()).
	 *
	 * @internal
	 */
	public function getSendId() : int{
		return $this->sendId;
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	/**
	 * @internal
	 * The identity ticket of a session that came through pm-proxy (network.proxy), or null.
	 */
	public function getProxyTicket() : ?ProxyTicket{
		return $this->sender instanceof ProxyPacketSender ? $this->sender->getTicket() : null;
	}

	public function getDisplayName() : string{
		return $this->info !== null ? $this->info->getUsername() : $this->ip . " " . $this->port;
	}

	/**
	 * Returns the last recorded ping measurement for this session, in milliseconds, or null if a ping measurement has not yet been recorded.
	 */
	/**
	 * Returns the name of the transport carrying this session ("raknet", "nethernet"...), or "unknown" if the
	 * packet sender does not say.
	 */
	public function getTransportName() : string{
		return $this->sender instanceof TransportAwarePacketSender ? $this->sender->getTransportName() : "unknown";
	}

	/**
	 * Whether the transport already encrypts the traffic, in which case the Minecraft-level encryption handshake is
	 * skipped (NetherNet uses DTLS and vanilla clients do not perform the handshake on it).
	 */
	public function isTransportEncrypted() : bool{
		return $this->sender instanceof TransportAwarePacketSender && $this->sender->isTransportEncrypted();
	}

	public function getPing() : ?int{
		return $this->ping;
	}

	/**
	 * @internal Called by the network interface to update last recorded ping measurements.
	 */
	public function updatePing(int $ping) : void{
		$this->ping = $ping;
	}

	public function getHandler() : ?PacketHandler{
		return $this->handler;
	}

	public function setHandler(?PacketHandler $handler) : void{
		if($this->connected){ //TODO: this is fine since we can't handle anything from a disconnected session, but it might produce surprises in some cases
			$this->handler = $handler;
			if($this->handler !== null){
				$this->handlerActions = PacketHandlerInspector::getHandlerActions($this->handler);
				$this->handler->setUp();
			}else{
				$this->handlerActions = null;
			}
		}
	}

	private function checkRepeatedPacketFilter(string $buffer) : bool{
		if($buffer === $this->noisyPacketBuffer){
			$this->noisyPacketsDropped++;
			return true;
		}
		//stop filtering once we see a packet with a different buffer
		//this won't be any good for interleaved spammy packets, but we haven't seen any of those so far, and this
		//is the simplest and most conservative filter we can do
		$this->noisyPacketBuffer = "";
		$this->noisyPacketsDropped = 0;

		return false;
	}

	/**
	 * @throws PacketHandlingException
	 */
	public function handleEncoded(string $payload) : void{
		if(!$this->connected){
			return;
		}

		Timings::$playerNetworkReceive->startTiming();
		try{
			$this->packetBatchLimiter->decrement();

			if($this->cipher !== null){
				Timings::$playerNetworkReceiveDecrypt->startTiming();
				try{
					$payload = $this->cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->logger->debug("Encrypted packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					Timings::$playerNetworkReceiveDecrypt->stopTiming();
				}
			}

			if(strlen($payload) < 1){
				throw new PacketHandlingException("No bytes in payload");
			}

			if($this->enableCompression){
				$compressionType = ord($payload[0]);
				$compressed = substr($payload, 1);
				if($compressionType === CompressionAlgorithm::NONE){
					$decompressed = $compressed;
				}elseif($compressionType === $this->compressor->getNetworkId()){
					Timings::$playerNetworkReceiveDecompress->startTiming();
					try{
						$decompressed = $this->compressor->decompress($compressed);
					}catch(DecompressionException $e){
						$this->logger->debug("Failed to decompress packet: " . base64_encode($compressed));
						throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
					}finally{
						Timings::$playerNetworkReceiveDecompress->stopTiming();
					}
				}else{
					throw new PacketHandlingException("Packet compressed with unexpected compression type $compressionType");
				}
			}else{
				$decompressed = $payload;
			}

			$count = 0;
			try{
				$stream = new ByteBufferReader($decompressed);
				foreach(PacketBatch::decodeRaw($stream) as $buffer){
					if(++$count >= self::INCOMING_PACKET_BATCH_HARD_LIMIT){
						//this should be well more than enough; under normal conditions the game packet rate limiter
						//will kick in well before this. This is only here to make sure we can't get huge batches of
						//noisy packets to bog down the server, since those aren't counted by the regular limiter.
						throw new PacketHandlingException("Reached hard limit of " . self::INCOMING_PACKET_BATCH_HARD_LIMIT . " per batch packet");
					}

					if($this->checkRepeatedPacketFilter($buffer)){
						continue;
					}

					$this->gamePacketLimiter->decrement();
					$packet = $this->packetPool->getPacket($buffer);
					if($packet === null){
						$this->logger->debug("Unknown packet: " . base64_encode($buffer));
						throw new PacketHandlingException("Unknown packet received");
					}
					try{
						$this->handleDataPacket($packet, $buffer);
					}catch(PacketHandlingException $e){
						$this->unhandledPacketDebug($packet, $buffer, "Packet processing error");
						throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
					}catch(FilterNoisyPacketException){
						$this->noisyPacketBuffer = $buffer;
					}
					if(!$this->isConnected()){
						//handling this packet may have caused a disconnection
						$this->logger->debug("Aborting batch processing due to server-side disconnection");
						break;
					}
				}
			}catch(PacketDecodeException|DataDecodeException $e){
				$this->logger->logException($e);
				throw PacketHandlingException::wrap($e, "Packet batch decode error");
			}

			//send the replies to this batch now instead of at the end of the server tick: otherwise every response
			//(item stack responses, block corrections, chat...) waits up to 50ms (25ms on average) before leaving,
			//on top of the network latency. Only in-game, so the login sequence keeps its exact flush points.
			if($this->player !== null && $this->isConnected()){
				$this->flushGamePacketQueue();
			}
		}finally{
			Timings::$playerNetworkReceive->stopTiming();
		}
	}

	private function unhandledPacketDebug(Packet $packet, string $buffer, string $label) : void{
		$debugSegment = substr($buffer, 0, 1024);
		$debugSegmentLength = strlen($debugSegment);
		$fullLength = strlen($buffer);
		$truncatedLabel = $debugSegmentLength === $fullLength ? "" : " ... (" . ($fullLength - $debugSegmentLength) . " bytes not shown)";
		$this->logger->debug($label . ": " . $packet->getName() . " ($fullLength bytes): " . base64_encode($debugSegment) . $truncatedLabel);
	}

	/**
	 * @throws PacketHandlingException
	 * @throws FilterNoisyPacketException
	 */
	public function handleDataPacket(Packet $packet, string $buffer) : void{
		if(!($packet instanceof ServerboundPacket)){
			throw new PacketHandlingException("Unexpected non-serverbound packet");
		}

		$timings = Timings::getReceiveDataPacketTimings($packet);
		$timings->startTiming();

		try{
			$handlerAction = PacketHandlerAction::DISCARD_WITH_DEBUG;
			//TODO: it would be better to use packet ID and avoid the object allocation, but it's unavoidable for now
			//because I don't want to copy paste packet header decoding
			if($this->handlerActions !== null && isset($this->handlerActions[$packet::class])){
				$handlerAction = $this->handlerActions[$packet::class];
			}
			if(DataPacketDecodeEvent::hasHandlers()){
				$ev = new DataPacketDecodeEvent($this, $packet->pid(), $buffer);
				$cancel = $handlerAction !== PacketHandlerAction::HANDLED;
				if($cancel){
					$ev->cancel();
				}
				$ev->call();
				if($cancel && !$ev->isCancelled()){
					//uncancelled by a plugin, let it through to DataPacketReceiveEvent
					$handlerAction = PacketHandlerAction::HANDLED;
				}elseif(!$cancel && $ev->isCancelled()){
					//explicitly cancelled by plugin, drop it quietly
					$handlerAction = PacketHandlerAction::DISCARD_SILENT;
				}
			}

			if($handlerAction !== PacketHandlerAction::HANDLED){
				if($handlerAction === PacketHandlerAction::DISCARD_WITH_DEBUG){
					$this->unhandledPacketDebug($packet, $buffer, "Discarded without decoding");
				}
				return;
			}

			$decodeTimings = Timings::getDecodeDataPacketTimings($packet);
			$decodeTimings->startTiming();
			try{
				$stream = new ByteBufferReader($buffer);
				try{
				$packet->decode($stream, ProtocolInfo::CURRENT_PROTOCOL);
				}catch(PacketDecodeException $e){
					throw PacketHandlingException::wrap($e);
				}
				if($stream->getUnreadLength() > 0){
					$remains = substr($stream->getData(), $stream->getOffset());
					$this->logger->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": " . bin2hex($remains));
				}
			}finally{
				$decodeTimings->stopTiming();
			}

			if(DataPacketReceiveEvent::hasHandlers()){
				$ev = new DataPacketReceiveEvent($this, $packet);
				$ev->call();
				if($ev->isCancelled()){
					return;
				}
			}
			$handlerTimings = Timings::getHandleDataPacketTimings($packet);
			$handlerTimings->startTiming();
			try{
				if($this->handler === null || !$packet->handle($this->handler)){
					$this->unhandledPacketDebug($packet, $buffer, "Handler rejected");
				}
			}finally{
				$handlerTimings->stopTiming();
			}
		}finally{
			$timings->stopTiming();
		}
	}

	public function handleAckReceipt(int $receiptId) : void{
		if(!$this->connected){
			return;
		}
		if(isset($this->ackPromisesByReceiptId[$receiptId])){
			$promises = $this->ackPromisesByReceiptId[$receiptId];
			unset($this->ackPromisesByReceiptId[$receiptId]);
			foreach($promises as $promise){
				$promise->resolve(true);
			}
		}
	}

	/**
	 * @param string|null $encoded the packet already encoded (see {@link NetworkSession::sendEncodedDataPacket()})
	 * @phpstan-param PromiseResolver<true>|null $ackReceiptResolver
	 */
	private function sendDataPacketInternal(ClientboundPacket $packet, bool $immediate, ?PromiseResolver $ackReceiptResolver, ?string $encoded = null) : bool{
		if(!$this->connected){
			return false;
		}
		//Basic safety restriction. TODO: improve this
		if(!$this->loggedIn && !$packet->canBeSentBeforeLogin()){
			throw new \InvalidArgumentException("Attempted to send " . get_class($packet) . " to " . $this->getDisplayName() . " too early");
		}

		$timings = Timings::getSendDataPacketTimings($packet);
		$timings->startTiming();
		try{
			if(DataPacketSendEvent::hasHandlers()){
				$ev = new DataPacketSendEvent([$this], [$packet]);
				$ev->call();
				if($ev->isCancelled()){
					return false;
				}
				$packets = $ev->getPackets();
			}else{
				$packets = [$packet];
				if($encoded !== null){
					//nothing could see or change the packet: its known encoding is what encoding it would give
					if($ackReceiptResolver !== null){
						$this->sendBufferAckPromises[] = $ackReceiptResolver;
					}
					$this->addToSendBuffer($encoded);
					if($immediate){
						$this->flushGamePacketQueue();
					}
					return true;
				}
			}

			if($ackReceiptResolver !== null){
				$this->sendBufferAckPromises[] = $ackReceiptResolver;
			}
			$writer = new ByteBufferWriter();
			foreach($packets as $evPacket){
				$writer->clear(); //memory reuse let's gooooo
				$this->addToSendBuffer(self::encodePacketTimed($writer, $evPacket));
			}
			if($immediate){
				$this->flushGamePacketQueue();
			}

			return true;
		}finally{
			$timings->stopTiming();
		}
	}

	public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
		return $this->sendDataPacketInternal($packet, $immediate, null);
	}

	/**
	 * Same as {@link NetworkSession::sendDataPacket()} for a packet whose encoding is already known (a cached packet
	 * sent to every player, e.g. CraftingDataPacket): $encoded must be what {@link NetworkSession::encodePacketTimed()}
	 * gives for $packet. It is only used when no DataPacketSendEvent handler is registered; otherwise the handlers get
	 * $packet as usual and whatever they leave is encoded.
	 *
	 * @internal
	 */
	public function sendEncodedDataPacket(ClientboundPacket $packet, string $encoded, bool $immediate = false) : bool{
		return $this->sendDataPacketInternal($packet, $immediate, null, $encoded);
	}

	/**
	 * @phpstan-return Promise<true>
	 */
	public function sendDataPacketWithReceipt(ClientboundPacket $packet, bool $immediate = false) : Promise{
		/** @phpstan-var PromiseResolver<true> $resolver */
		$resolver = new PromiseResolver();

		if(!$this->sendDataPacketInternal($packet, $immediate, $resolver)){
			$resolver->reject();
		}

		return $resolver->getPromise();
	}

	/**
	 * @internal
	 */
	public static function encodePacketTimed(ByteBufferWriter $serializer, ClientboundPacket $packet) : string{
		$timings = Timings::getEncodeDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$packet->encode($serializer, ProtocolInfo::CURRENT_PROTOCOL);
			return $serializer->getData();
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * Returns the packet buffer with the varint length prefix it carries inside a packet batch (what
	 * {@link PacketBatch::encodeRaw()} writes for it). Packets sent to many sessions are prefixed once.
	 *
	 * @internal
	 */
	public static function prefixPacketBuffer(string $buffer) : string{
		return Binary::writeUnsignedVarInt(strlen($buffer)) . $buffer;
	}

	/**
	 * @internal
	 */
	public function addToSendBuffer(string $buffer) : void{
		$this->addPrefixedToSendBuffer(self::prefixPacketBuffer($buffer));
	}

	/**
	 * Same as {@link NetworkSession::addToSendBuffer()} for a buffer already prefixed with its length (see
	 * {@link NetworkSession::prefixPacketBuffer()}).
	 *
	 * @internal
	 */
	public function addPrefixedToSendBuffer(string $prefixedBuffer) : void{
		$native = $this->sendBuffers;
		if($native !== null){
			$native->append($this->sendId, $prefixedBuffer);
		}else{
			$this->sendBuffer[] = $prefixedBuffer;
		}
	}

	/**
	 * @param bool $forceSync compress on the main thread, so that the batch is on its way before the session closes
	 */
	private function flushGamePacketQueue(bool $forceSync = false) : void{
		//the buffers carry their length prefix already: the batch is their concatenation
		Timings::$playerNetworkSendBatch->startTiming();
		$native = $this->sendBuffers;
		if($native !== null){
			$batchBuffer = $native->take($this->sendId);
		}elseif(count($this->sendBuffer) > 0){
			$batchBuffer = implode("", $this->sendBuffer);
			$this->sendBuffer = [];
		}else{
			$batchBuffer = "";
		}
		Timings::$playerNetworkSendBatch->stopTiming();
		if($batchBuffer !== ""){
			Timings::$playerNetworkSend->startTiming();
			try{
				$syncMode = null; //automatic
				if($forceSync){
					$syncMode = true;
				}elseif($this->forceAsyncCompression && self::hasIdleAsyncWorker($this->server->getAsyncPool())){
					//when every running worker is busy (e.g. preparing this player's chunks), the batch (StartGamePacket
					//and the other spawn data) would wait behind their tasks or for a new worker to start (and its
					//memory), which is much longer than compressing it here (~5 ms): then the usual setting decides
					$syncMode = false;
				}

				if($this->enableCompression){
					$batch = $this->server->prepareBatch($batchBuffer, $this->compressor, $syncMode, Timings::$playerNetworkSendCompressSessionBuffer);
				}else{
					$batch = $batchBuffer;
				}
				$ackPromises = $this->sendBufferAckPromises;
				$this->sendBufferAckPromises = [];
				//these packets were already potentially buffered for up to 50ms - make sure the transport layer doesn't
				//delay them any longer
				$this->queueCompressedNoGamePacketFlush($batch, networkFlush: true, ackPromises: $ackPromises);
			}finally{
				Timings::$playerNetworkSend->stopTiming();
			}
		}
	}

	private static function hasIdleAsyncWorker(AsyncPool $pool) : bool{
		foreach($pool->getTaskQueueSizes() as $queueSize){
			if($queueSize === 0){
				return true;
			}
		}
		return false;
	}

	public function getBroadcaster() : PacketBroadcaster{ return $this->broadcaster; }

	public function getEntityEventBroadcaster() : EntityEventBroadcaster{ return $this->entityEventBroadcaster; }

	public function getCompressor() : Compressor{
		return $this->compressor;
	}

	public function getTypeConverter() : TypeConverter{ return $this->typeConverter; }

	public function queueCompressed(CompressBatchPromise|string $payload, bool $immediate = false) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			//if the next packet causes a flush, avoid unnecessarily flushing twice
			//however, if the next packet does *not* cause a flush, game packets should be flushed to avoid delays
			$this->flushGamePacketQueue();
			$this->queueCompressedNoGamePacketFlush($payload, $immediate);
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	/**
	 * @param PromiseResolver[] $ackPromises
	 *
	 * @phpstan-param list<PromiseResolver<true>> $ackPromises
	 */
	private function queueCompressedNoGamePacketFlush(CompressBatchPromise|string $batch, bool $networkFlush = false, array $ackPromises = []) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			$this->compressedQueue->enqueue([$batch, $ackPromises, $networkFlush]);
			if(is_string($batch)){
				$this->flushCompressedQueue();
			}else{
				$batch->onResolve(function() : void{
					if($this->connected){
						$this->flushCompressedQueue();
					}
				});
			}
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	private function flushCompressedQueue() : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			while(!$this->compressedQueue->isEmpty()){
				[$current, $ackPromises, $networkFlush] = $this->compressedQueue->bottom();
				if($current instanceof \Closure){
					$this->compressedQueue->dequeue();
					$current();

				}elseif(is_string($current)){
					$this->compressedQueue->dequeue();
					$this->sendEncoded($current, $networkFlush, $ackPromises);

				}elseif($current->hasResult()){
					$this->compressedQueue->dequeue();
					$this->sendEncoded($current->getResult(), $networkFlush, $ackPromises);

				}else{
					//can't send any more queued until this one is ready
					break;
				}
			}
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	/**
	 * @param PromiseResolver[] $ackPromises
	 * @phpstan-param list<PromiseResolver<true>> $ackPromises
	 */
	private function sendEncoded(string $payload, bool $immediate, array $ackPromises) : void{
		if($this->cipher !== null){
			Timings::$playerNetworkSendEncrypt->startTiming();
			$payload = $this->cipher->encrypt($payload);
			Timings::$playerNetworkSendEncrypt->stopTiming();
		}

		if(count($ackPromises) > 0){
			$ackReceiptId = $this->nextAckReceiptId++;
			$this->ackPromisesByReceiptId[$ackReceiptId] = $ackPromises;
		}else{
			$ackReceiptId = null;
		}
		Timings::$playerNetworkSendTransport->startTiming();
		$this->sender->send($payload, $immediate, $ackReceiptId);
		Timings::$playerNetworkSendTransport->stopTiming();
	}

	/**
	 * @phpstan-param \Closure() : void $func
	 */
	private function tryDisconnect(\Closure $func, Translatable|string $reason) : void{
		if($this->connected && !$this->disconnectGuard){
			$this->disconnectGuard = true;
			$func();
			$this->disconnectGuard = false;
			$this->flushGamePacketQueue(forceSync: true);
			$this->sender->close("");
			foreach($this->disposeHooks as $callback){
				$callback();
			}
			$this->disposeHooks->clear();
			$this->releaseSpawnTerrainPrefetch();
			$this->setHandler(null);
			$this->connected = false;
			if(!$this->standardSession){
				self::$nonStandardSessions--;
			}
			$this->sendBuffers?->close($this->sendId);

			$ackPromisesByReceiptId = $this->ackPromisesByReceiptId;
			$this->ackPromisesByReceiptId = [];
			foreach($ackPromisesByReceiptId as $resolvers){
				foreach($resolvers as $resolver){
					$resolver->reject();
				}
			}
			$sendBufferAckPromises = $this->sendBufferAckPromises;
			$this->sendBufferAckPromises = [];
			foreach($sendBufferAckPromises as $resolver){
				$resolver->reject();
			}

			$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_network_session_close($reason)));
		}
	}

	/**
	 * Performs actions after the session has been disconnected. By this point, nothing should be interacting with the
	 * session, so it's safe to destroy any cycles and perform destructive cleanup.
	 */
	private function dispose() : void{
		$this->invManager = null;
	}

	/**
	 * @param int $failReason DisconnectFailReason::* (the client picks the title of its disconnection screen from it)
	 */
	private function sendDisconnectPacket(Translatable|string $message, int $failReason = DisconnectFailReason::UNKNOWN) : void{
		if($message instanceof Translatable){
			$language = $this->player?->getLanguage() ?? ($this->info !== null ? $this->server->getLanguageForLocale($this->info->getLocale()) : $this->server->getLanguage());
			$translated = $language->translate($message);
		}else{
			$translated = $message;
		}
		$this->sendDataPacket(DisconnectPacket::create($failReason, $translated, ""));
	}

	/**
	 * Disconnects the session, destroying the associated player (if it exists).
	 *
	 * @param Translatable|string      $reason                  Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $disconnectScreenMessage Shown on the player's disconnection screen (null will use the reason)
	 */
	public function disconnect(Translatable|string $reason, Translatable|string|null $disconnectScreenMessage = null, bool $notify = true) : void{
		$this->tryDisconnect(function() use ($reason, $disconnectScreenMessage, $notify) : void{
			if($notify){
				$this->sendDisconnectPacket($disconnectScreenMessage ?? $reason);
			}
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	public function disconnectWithError(Translatable|string $reason, Translatable|string|null $disconnectScreenMessage = null) : void{
		$errorId = implode("-", str_split(bin2hex(random_bytes(6)), 4));

		$this->disconnect(
			reason: KnownTranslationFactory::pocketmine_disconnect_error($reason, $errorId)->prefix(TextFormat::RED),
			disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error($disconnectScreenMessage ?? $reason, $errorId),
		);
	}

	public function disconnectIncompatibleProtocol(int $protocolVersion) : void{
		$this->tryDisconnect(
			function() use ($protocolVersion) : void{
				$this->sendDataPacket(PlayStatusPacket::create($protocolVersion < ProtocolInfo::CURRENT_PROTOCOL ? PlayStatusPacket::LOGIN_FAILED_CLIENT : PlayStatusPacket::LOGIN_FAILED_SERVER), true);
			},
			KnownTranslationFactory::pocketmine_disconnect_incompatibleProtocol((string) $protocolVersion)
		);
	}

	/**
	 * Instructs the remote client to connect to a different server: sends a TransferPacket (reload world off, no
	 * gatherings data, like BDS) and closes the session without a DisconnectPacket, which would make the client show
	 * an error instead of switching. Clients since 1.26.60 only join over NetherNet, so the target must accept NetherNet
	 * (signaling on TCP $port, see Player::transfer()).
	 */
	public function transfer(string $ip, int $port, Translatable|string|null $reason = null) : void{
		$reason ??= KnownTranslationFactory::pocketmine_disconnect_transfer();
		$this->tryDisconnect(function() use ($ip, $port, $reason) : void{
			$this->sendDataPacket(TransferPacket::create($ip, $port, false), true);
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	/**
	 * Called by the Player when it is closed (for example due to getting kicked).
	 *
	 * @param int $failReason DisconnectFailReason::* sent to the client (Player::kick() uses KICKED, like BDS)
	 */
	public function onPlayerDestroyed(Translatable|string $reason, Translatable|string $disconnectScreenMessage, int $failReason = DisconnectFailReason::UNKNOWN) : void{
		$this->tryDisconnect(function() use ($disconnectScreenMessage, $failReason) : void{
			$this->sendDisconnectPacket($disconnectScreenMessage, $failReason);
		}, $reason);
	}

	/**
	 * Called by the network interface to close the session when the client disconnects without server input, for
	 * example in a timeout condition or voluntary client disconnect.
	 */
	public function onClientDisconnect(Translatable|string $reason) : void{
		$this->tryDisconnect(function() use ($reason) : void{
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	/**
	 * On encrypted transports (NetherNet) the Minecraft encryption handshake is skipped, and what proves that the login
	 * was sent by the owner of its key (and not replayed by someone who captured it) is the identity assertion of the
	 * transport: the client signed its DTLS fingerprints with the key in the "cpk" claim of its authentication token,
	 * which must be the key of the login. Only matters for Xbox-authenticated sessions: anyone can claim any name
	 * without authentication anyway.
	 *
	 * @return bool false if the session was disconnected
	 */
	private function checkTransportIdentity(string $clientPubKey) : bool{
		if(!$this->sender instanceof TransportAwarePacketSender){
			return true;
		}
		$peerKey = $this->sender->getPeerPublicKey();
		$peerDer = $peerKey !== null ? base64_decode($peerKey, true) : false;
		if($peerDer !== false && self::publicKeysEqual($peerDer, $clientPubKey)){
			$this->logger->debug("Transport identity key matches the login key");
			return true;
		}
		$problem = $peerDer === false ? "No transport identity assertion" : "Transport identity key does not match the login key";
		if(!$this->authenticated){
			$this->logger->debug("$problem (not authenticated, ignored)");
			return true;
		}
		if(!$this->server->getConfigGroup()->getPropertyBool(YmlServerProperties::NETWORK_NETHERNET_VERIFY_IDENTITY, true)){
			$this->logger->warning("$problem (network.nethernet.verify-identity is off, allowed)");
			return true;
		}
		if($peerDer !== false){
			$this->logger->debug("Transport key SHA-256 " . hash("sha256", $peerDer) . ", login key SHA-256 " . hash("sha256", $clientPubKey));
		}
		$this->disconnectWithError(
			reason: KnownTranslationFactory::pocketmine_disconnect_invalidSession($problem),
			disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_authentication()
		);
		return false;
	}

	/**
	 * Compares two DER-encoded public keys, also when they encode the same key differently (e.g. compressed points).
	 */
	private static function publicKeysEqual(string $derA, string $derB) : bool{
		if($derA === $derB){
			return true;
		}
		$toPem = fn(string $der) : string => "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
		$a = @openssl_pkey_get_public($toPem($derA));
		$b = @openssl_pkey_get_public($toPem($derB));
		if($a === false || $b === false){
			return false;
		}
		$da = openssl_pkey_get_details($a);
		$db = openssl_pkey_get_details($b);
		if($da === false || $db === false || !isset($da["ec"], $db["ec"]) || !is_array($da["ec"]) || !is_array($db["ec"])){
			return false;
		}
		return ($da["ec"]["curve_name"] ?? null) === ($db["ec"]["curve_name"] ?? null) &&
			($da["ec"]["x"] ?? null) === ($db["ec"]["x"] ?? null) &&
			($da["ec"]["y"] ?? null) === ($db["ec"]["y"] ?? null);
	}

	private function setAuthenticationStatus(bool $authenticated, bool $authRequired, Translatable|string|null $error, ?string $clientPubKey) : void{
		if(!$this->connected){
			return;
		}
		if($error === null){
			if($authenticated && !($this->info instanceof XboxLivePlayerInfo)){
				$error = "Expected XUID but none found";
			}elseif($clientPubKey === null){
				$error = "Missing client public key"; //failsafe
			}
		}

		if($error !== null){
			$this->disconnectWithError(
				reason: KnownTranslationFactory::pocketmine_disconnect_invalidSession($error),
				disconnectScreenMessage: KnownTranslationFactory::pocketmine_disconnect_error_authentication()
			);

			return;
		}

		$this->authenticated = $authenticated;

		if(!$this->authenticated){
			if($authRequired){
				$this->disconnect("Not authenticated", KnownTranslationFactory::disconnectionScreen_notAuthenticated());
				return;
			}
			if($this->info instanceof XboxLivePlayerInfo){
				$this->logger->warning("Discarding unexpected XUID for non-authenticated player");
				$this->info = $this->info->withoutXboxData();
			}
		}
		$this->logger->debug("Xbox Live authenticated: " . ($this->authenticated ? "YES" : "NO"));

		$checkXUID = $this->server->getConfigGroup()->getPropertyBool(YmlServerProperties::PLAYER_VERIFY_XUID, true);
		$myXUID = $this->info instanceof XboxLivePlayerInfo ? $this->info->getXuid() : "";
		$kickForXUIDMismatch = function(string $xuid) use ($checkXUID, $myXUID) : bool{
			if($checkXUID && $myXUID !== $xuid){
				$this->logger->debug("XUID mismatch: expected '$xuid', but got '$myXUID'");
				//TODO: Longer term, we should be identifying playerdata using something more reliable, like XUID or UUID.
				//However, that would be a very disruptive change, so this will serve as a stopgap for now.
				//Side note: this will also prevent offline players hijacking XBL playerdata on online servers, since their
				//XUID will always be empty.
				$this->disconnect("XUID does not match (possible impersonation attempt)");
				return true;
			}
			return false;
		};

		foreach($this->manager->getSessions() as $existingSession){
			if($existingSession === $this){
				continue;
			}
			$info = $existingSession->getPlayerInfo();
			if($info !== null && (strcasecmp($info->getUsername(), $this->info->getUsername()) === 0 || $info->getUuid()->equals($this->info->getUuid()))){
				if($kickForXUIDMismatch($info instanceof XboxLivePlayerInfo ? $info->getXuid() : "")){
					return;
				}
				$ev = new PlayerDuplicateLoginEvent($this, $existingSession, KnownTranslationFactory::disconnectionScreen_loggedinOtherLocation(), null);
				$ev->call();
				if($ev->isCancelled()){
					$this->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
					return;
				}

				$existingSession->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
			}
		}

		//TODO: make player data loading async
		//TODO: we shouldn't be loading player data here at all, but right now we don't have any choice :(
		$this->cachedOfflinePlayerData = $this->server->getOfflinePlayerData($this->info->getUsername());
		if($checkXUID){
			$recordedXUID = $this->cachedOfflinePlayerData !== null ? $this->cachedOfflinePlayerData->getTag(Player::TAG_LAST_KNOWN_XUID) : null;
			if(!($recordedXUID instanceof StringTag)){
				$this->logger->debug("No previous XUID recorded, no choice but to trust this player");
			}elseif(!$kickForXUIDMismatch($recordedXUID->getValue())){
				$this->logger->debug("XUID match");
			}
		}

		if($this->isTransportEncrypted() && $this->sender instanceof TransportAwarePacketSender && !$this->checkTransportIdentity($clientPubKey ?? "")){
			return;
		}

		//the player will spawn: what it needs for that is prepared from now on, while the client finishes logging in
		$this->startSpawnTerrainPrefetch();

		if($this->isTransportEncrypted()){
			$this->logger->debug("Transport is encrypted (" . $this->getTransportName() . "), skipping encryption handshake");
			$this->onServerLoginSuccess();
		}elseif(EncryptionContext::$ENABLED){
			$this->server->getAsyncPool()->submitTask(new PrepareEncryptionTask($clientPubKey, function(string $encryptionKey, string $handshakeJwt) : void{
				if(!$this->connected){
					return;
				}
				$this->sendDataPacket(ServerToClientHandshakePacket::create($handshakeJwt), true);

				//the handshake may still be compressing (compression thread): encryption starts right after it was sent
				$cipher = EncryptionContext::fakeGCM($encryptionKey);
				$this->compressedQueue->enqueue([function() use ($cipher) : void{ $this->cipher = $cipher; }, [], false]);
				$this->flushCompressedQueue();

				$this->setHandler(new HandshakePacketHandler($this->onServerLoginSuccess(...)));
				$this->logger->debug("Enabled encryption");
			}));
		}else{
			$this->onServerLoginSuccess();
		}
		//after the encryption task, which gets an idle worker first
		ChunkRequestWorkers::get($this->server->getAsyncPool())->warmUp();
	}

	/**
	 * Starts preparing the chunks a returning player needs before it spawns (see {@link SpawnTerrainPrefetch}), around
	 * its saved position, which {@link Server::createPlayer()} will use. A new player gets that once its spawn spot is
	 * found (see {@link NetworkSession::onPlayerCreated()}): its random spot near the world spawn may need generation,
	 * which chunks prepared around the world spawn would only delay.
	 */
	private function startSpawnTerrainPrefetch() : void{
		$data = $this->cachedOfflinePlayerData;
		if($data === null){
			return;
		}
		try{
			$world = $this->server->getWorldManager()->getWorldByName($data->getString(Player::TAG_LEVEL, ""));
			if($world !== null){
				$this->prefetchSpawnTerrain(EntityDataHelper::parseLocation($data, $world));
			}
		}catch(SavedDataLoadingException | NbtException){
			//the player is created at the world spawn (or kicked) then
		}
	}

	private function prefetchSpawnTerrain(Position $position) : void{
		$config = $this->server->getConfigGroup();
		if(!$config->getPropertyBool(YmlServerProperties::CHUNK_SENDING_FAST_SPAWN, true)){
			return;
		}
		$world = $position->getWorld();
		$chunkX = $position->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $position->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		if($this->spawnTerrainPrefetch !== null){
			if($this->spawnTerrainPrefetch->isAround($world, $chunkX, $chunkZ)){
				return;
			}
			$this->releaseSpawnTerrainPrefetch();
		}
		$radius = min($this->server->getAllowedViewDistance($this->server->getViewDistance()), $config->getPropertyInt(YmlServerProperties::CHUNK_SENDING_SPAWN_RADIUS, 4));
		//Player::$spawnThreshold + 1: the number of chunks the player waits for before it spawns
		$count = (int) ($radius ** 2 * M_PI) + 1;
		$this->spawnTerrainPrefetch = new SpawnTerrainPrefetch($world, $this->compressor, $chunkX, $chunkZ, $radius, $count);
	}

	private function releaseSpawnTerrainPrefetch() : void{
		$this->spawnTerrainPrefetch?->release();
		$this->spawnTerrainPrefetch = null;
	}

	private function onServerLoginSuccess() : void{
		$this->loggedIn = true;

		if($this->isProxyTransfer()){
			//the client is in game on another server of the network: it already has its resource packs
			$this->logger->debug("Player transferred by the proxy, skipping the resource packs phase");
			$this->createPlayer();
			return;
		}
		if($this->getProxyTicket()?->packs === true){
			//the proxy already sent LOGIN_SUCCESS and the network's resource packs
			$this->logger->debug("Resource packs sent by the proxy, skipping the resource packs phase");
			$this->createPlayer();
			return;
		}

		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::LOGIN_SUCCESS));

		$this->logger->debug("Initiating resource packs phase");

		$packManager = $this->server->getResourcePackManager();
		$resourcePacks = $packManager->getResourceStack();
		$keys = [];
		foreach($resourcePacks as $resourcePack){
			$key = $packManager->getPackEncryptionKey($resourcePack->getPackId());
			if($key !== null){
				$keys[$resourcePack->getPackId()] = $key;
			}
		}
		$event = new PlayerResourcePackOfferEvent($this->info, $resourcePacks, $keys, $packManager->resourcePacksRequired());
		$event->call();
		$this->setHandler(new ResourcePacksPacketHandler($this, $event->getResourcePacks(), $event->getEncryptionKeys(), $event->mustAccept(), function() : void{
			$this->createPlayer();
		}));
	}

	private function beginSpawnSequence() : void{
		$this->setHandler(new PreSpawnPacketHandler($this->server, $this->player, $this, $this->invManager));
		$this->player->setNoClientPredictions(); //TODO: HACK: fix client-side falling pre-spawn

		$this->logger->debug("Waiting for chunk radius request");
	}

	public function notifyTerrainReady() : void{
		//the player holds the chunks it uses now
		$this->releaseSpawnTerrainPrefetch();
		if($this->isProxyTransfer()){
			//the client spawned long ago, on its first server: it would not answer a spawn notification
			$this->logger->debug("Terrain ready for a transferred player, entering in-game phase");
			$this->onClientSpawnResponse();
			if($this->sender instanceof ProxyPacketSender){
				$this->sender->getInterface()->onTransferSpawned($this, $this->sender->getTicket());
			}
			return;
		}
		$this->logger->debug("Sending spawn notification, waiting for spawn response");
		//right away, behind the chunks already on their way, instead of at the end of the tick
		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN), true);
		$this->setHandler(new SpawnResponsePacketHandler($this->onClientSpawnResponse(...), $this->player));
	}

	private function onClientSpawnResponse() : void{
		$this->logger->debug("Received spawn response, entering in-game phase");
		$this->player->setNoClientPredictions(false); //TODO: HACK: we set this during the spawn sequence to prevent the client sending junk movements
		$this->player->doFirstSpawn();
		$this->forceAsyncCompression = false;
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function onServerDeath(Translatable|string $deathMessage) : void{
		if($this->handler instanceof InGamePacketHandler){ //TODO: this is a bad fix for pre-spawn death, this shouldn't be reachable at all at this stage :(
			$this->setHandler(new DeathPacketHandler($this->player, $this, $this->invManager ?? throw new AssumptionFailedError(), $deathMessage));
		}
	}

	public function onServerRespawn() : void{
		$this->entityEventBroadcaster->syncAttributes([$this], $this->player, $this->player->getAttributeMap()->getAll());
		$this->player->sendData(null);

		$this->syncAbilities($this->player);
		$this->invManager->syncAll();
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function syncMovement(Vector3 $pos, ?float $yaw = null, ?float $pitch = null, int $mode = MovePlayerPacket::MODE_NORMAL) : void{
		if($this->player !== null){
			$location = $this->player->getLocation();
			$yaw = $yaw ?? $location->getYaw();
			$pitch = $pitch ?? $location->getPitch();

			$this->sendDataPacket(MovePlayerPacket::simple(
				$this->player->getId(),
				$this->player->getOffsetPosition($pos),
				$pitch,
				$yaw,
				$yaw, //TODO: head yaw
				$mode,
				$this->player->onGround,
				0, //TODO: riding entity ID
				0 //TODO: tick
			));

			if($this->handler instanceof InGamePacketHandler){
				$this->handler->forceMoveSync = true;
			}
		}
	}

	public function syncViewAreaRadius(int $distance) : void{
		$this->sendDataPacket(ChunkRadiusUpdatedPacket::create($distance));
	}

	public function syncViewAreaCenterPoint(Vector3 $newPos, int $viewDistance) : void{
		$this->sendDataPacket(NetworkChunkPublisherUpdatePacket::create(BlockPosition::fromVector3($newPos), $viewDistance * 16, [])); //blocks, not chunks >.>
	}

	public function syncPlayerSpawnPoint(Position $newSpawn) : void{
		$newSpawnBlockPosition = BlockPosition::fromVector3($newSpawn);
		//TODO: respawn causing block position (bed, respawn anchor)
		$this->sendDataPacket(SetSpawnPositionPacket::playerSpawn($newSpawnBlockPosition, self::getDimensionIdOf($newSpawn), $newSpawnBlockPosition));
	}

	public function syncWorldSpawnPoint(Position $newSpawn) : void{
		$this->sendDataPacket(SetSpawnPositionPacket::worldSpawn(BlockPosition::fromVector3($newSpawn), self::getDimensionIdOf($newSpawn)));
	}

	/**
	 * @phpstan-return DimensionIds::*
	 */
	private static function getDimensionIdOf(Position $position) : int{
		return $position->isValid() ? $position->getWorld()->getDimension()->getNetworkId() : DimensionIds::OVERWORLD;
	}

	/**
	 * @internal
	 * Records the dimension the client was put in by StartGamePacket.
	 */
	public function setClientDimension(Dimension $dimension) : void{
		$this->dimensionTracker->onStartGame($dimension);
	}

	/**
	 * @internal
	 * Whether this session is a player the proxy moved here from another server of the network (see ProxyTicket).
	 */
	public function isProxyTransfer() : bool{
		return $this->getProxyTicket()?->transfer === true;
	}

	/**
	 * @internal
	 * Moves a player who arrived from another server into this server's world instead of a StartGamePacket: the client
	 * is still in the other server's dimension and terrain, so it goes through a dimension change, with the detour of
	 * a world switch when the dimension is the same, which makes it drop the old terrain.
	 */
	public function beginProxyTransferIn(Dimension $clientDimension) : void{
		if($this->player === null){
			return;
		}
		$world = $this->player->getWorld();
		$style = $this->getProxyTicket()->style ?? ProxyTransferStyle::DIMENSION;
		if($style !== ProxyTransferStyle::DIMENSION && $world->getDimension() === $clientDimension){
			//no loading screen: the proxy already replaced the previous server's terrain with empty chunks, so the client
			//only needs to be moved to its place here; this server's chunks arrive like after a teleport
			$this->logger->debug("Transfer without dimension change (style $style)");
			$this->dimensionTracker->onStartGame($clientDimension);
			$this->syncMovement($this->player->getPosition(), null, null, MovePlayerPacket::MODE_TELEPORT);
			$this->syncWorldState($world);
			return;
		}
		$this->dimensionTracker->onStartGame($clientDimension);
		$this->dimensionTracker->onTerrainSent();
		$clear = $this->dimensionTracker->getClearSameDimensionTerrain();
		$this->dimensionTracker->setClearSameDimensionTerrain(true);
		$change = $this->dimensionTracker->onEnterWorld($world->getDimension(), false, true);
		$this->dimensionTracker->setClearSameDimensionTerrain($clear);
		if($change !== null){
			$this->sendClientDimensionChange($change);
		}
		$this->syncWorldState($world);
	}

	/**
	 * Returns the dimension the client currently renders, or null before StartGamePacket was sent.
	 */
	public function getClientDimension() : ?Dimension{
		return $this->dimensionTracker->getClientDimension();
	}

	/**
	 * Whether the client is in the loading screen of a dimension change.
	 */
	public function isChangingDimension() : bool{
		return $this->dimensionTracker->isChanging();
	}

	/**
	 * Whether a switch between two worlds of the same dimension makes the client drop the previous world's terrain, by
	 * sending it through another dimension and back (with the dimension loading screen). Defaults to
	 * player.clear-terrain-on-world-change in pocketmine.yml.
	 * Without it, chunks of the previous world the new world doesn't overwrite stay visible on the client.
	 */
	public function getClearTerrainOnWorldChange() : bool{
		return $this->dimensionTracker->getClearSameDimensionTerrain();
	}

	/**
	 * @see NetworkSession::getClearTerrainOnWorldChange()
	 */
	public function setClearTerrainOnWorldChange(bool $clear) : void{
		$this->dimensionTracker->setClearSameDimensionTerrain($clear);
	}

	/**
	 * Moves the client to another dimension: it drops all its chunks and shows the dimension loading screen until the
	 * chunks around its new position arrive.
	 *
	 * Flow (same as dragonfly and PowerNukkitX, which take it from BDS): ChangeDimension with the arrival position and,
	 * when the player is alive, a loading screen ID; then, once the first chunk of the new dimension has been sent, a
	 * PlayerAction DIMENSION_CHANGE_ACK from the server (since 1.19.50 the server sends it; the client answers with its
	 * own ack and with ServerboundLoadingScreen start/end packets carrying the ID).
	 *
	 * The detour of a same-dimension world switch (see {@link ClientDimensionTracker}) gets a few empty chunks of the
	 * detour dimension and the server ack right away, like WaterdogPE does between servers: the client then leaves that
	 * dimension as soon as it acknowledges it, and no world chunk is sent until the real change back.
	 */
	private function sendClientDimensionChange(ClientDimensionChange $change) : void{
		if($this->player === null){
			return;
		}
		$dimension = $change->dimension;
		$position = $this->player->getOffsetPosition($this->player->getPosition());
		if($change->detour){
			//keep the client inside the detour dimension's height range (e.g. Overworld Y -40 or 200 in the Nether)
			$position = new Vector3($position->x, max($dimension->getMinY() + 1, min($dimension->getMaxY() - 2, $position->y)), $position->z);
		}
		$this->dimensionChangeStartTick = $this->server->getTick();
		$this->logger->debug("Changing client dimension to " . $dimension->name . ($change->detour ? " (detour of a world switch)" : "") . ($change->respawn ? " (respawn)" : ""));
		$this->sendDataPacket(ChangeDimensionPacket::create($dimension->getNetworkId(), $position, $change->respawn, $change->loadingScreenId));

		if($change->detour){
			$this->dimensionChangeAckDeadline = null;
			$dimensionId = $dimension->getNetworkId();
			$this->sendDataPacket(NetworkChunkPublisherUpdatePacket::create(BlockPosition::fromVector3($position), self::DETOUR_CHUNK_RADIUS * 16, []));
			$payload = self::$emptyChunkPayloads[$dimensionId] ??= ChunkSerializer::serializeFullChunk(new Chunk([], false), $dimensionId, $this->typeConverter->getBlockTranslator());
			$centerX = $position->getFloorX() >> Chunk::COORD_BIT_SIZE;
			$centerZ = $position->getFloorZ() >> Chunk::COORD_BIT_SIZE;
			for($x = -self::DETOUR_CHUNK_RADIUS; $x <= self::DETOUR_CHUNK_RADIUS; ++$x){
				for($z = -self::DETOUR_CHUNK_RADIUS; $z <= self::DETOUR_CHUNK_RADIUS; ++$z){
					$this->sendDataPacket(LevelChunkPacket::create(new ChunkPosition($centerX + $x, $centerZ + $z), $dimensionId, 0, false, null, $payload));
				}
			}
			$this->sendDimensionChangeAck();
		}else{
			$this->dimensionChangeAckDeadline = $this->dimensionChangeStartTick + 40;
		}
	}

	private function tickDimensionChange() : void{
		$tick = $this->server->getTick();
		if($this->dimensionChangeAckDeadline !== null && $tick >= $this->dimensionChangeAckDeadline){
			//no chunk could be sent yet (e.g. terrain still generating): don't keep the client waiting forever
			$this->sendDimensionChangeAck();
		}
		if($this->dimensionTracker->isChanging() && $tick - $this->dimensionChangeStartTick > 600 && $this->player !== null){
			$this->logger->debug("Client never reported the end of the dimension change, assuming it finished");
			$hadPendingWorld = $this->dimensionTracker->holdsChunks();
			$this->onDimensionChangeFinished($this->dimensionTracker->forceChangeDone(!$this->player->isAlive(), $this->player->spawned), $hadPendingWorld);
		}
	}

	private function sendDimensionChangeAck() : void{
		$this->dimensionChangeAckDeadline = null;
		if($this->player !== null){
			$position = BlockPosition::fromVector3($this->player->getPosition());
			$this->sendDataPacket(PlayerActionPacket::create($this->player->getId(), PlayerAction::DIMENSION_CHANGE_ACK, $position, $position, 0));
		}
	}

	/**
	 * @internal
	 * Called when the client reports that it finished the dimension change (its own DIMENSION_CHANGE_ACK or the end of
	 * the loading screen).
	 */
	public function onClientDimensionChangeDone(?int $loadingScreenId) : void{
		if(!$this->dimensionTracker->isChanging() || $this->player === null){
			return;
		}
		$expected = $this->dimensionTracker->getLoadingScreenId();
		$hadPendingWorld = $this->dimensionTracker->holdsChunks();
		$next = $this->dimensionTracker->onClientChangeDone($loadingScreenId, !$this->player->isAlive(), $this->player->spawned);
		if($next === null && $this->dimensionTracker->isChanging()){
			$this->logger->debug("Ignoring " . ($loadingScreenId !== null ? "end of loading screen $loadingScreenId" : "dimension change ack") . " (expected loading screen " . ($expected ?? "none") . ")");
			return;
		}
		$this->logger->debug("Client finished changing dimension");
		$this->onDimensionChangeFinished($next, $hadPendingWorld);
	}

	/**
	 * @param bool $hadPendingWorld whether a world switch was waiting for the end of the change
	 */
	private function onDimensionChangeFinished(?ClientDimensionChange $next, bool $hadPendingWorld) : void{
		$this->dimensionChangeAckDeadline = null;
		if($next !== null){
			$this->sendClientDimensionChange($next);
		}
		if($hadPendingWorld && $this->player !== null && !$this->dimensionTracker->isDetour()){
			//the client is now on its way to (or already in) the dimension of the world the player is in
			$this->syncWorldState($this->player->getWorld());
		}
	}

	public function syncGameMode(GameMode $mode, bool $isRollback = false) : void{
		$this->sendDataPacket(SetPlayerGameTypePacket::create($this->typeConverter->coreGameModeToProtocol($mode)));
		if($this->player !== null){
			//BDS 1.26.52 announces a game mode change only with UpdatePlayerGameType, to every player including this one
			//(it never sends SetPlayerGameType for it): the client uses it to switch to native spectator
			$tick = $this->server->getTick();
			$actorUniqueId = $this->player->getId();
			$recipients = [$this->player];
			if(!$isRollback){
				foreach($this->server->getOnlinePlayers() as $player){
					if($player !== $this->player){
						$recipients[] = $player;
					}
				}
			}
			foreach($recipients as $recipient){
				if($recipient->spawned && $recipient->isConnected()){
					$session = $recipient->getNetworkSession();
					$session->sendDataPacket(UpdatePlayerGameTypePacket::create($session->getTypeConverter()->coreGameModeToProtocol($mode), $actorUniqueId, $tick));
				}
			}
			$this->syncAbilities($this->player);
			$this->syncAdventureSettings(); //TODO: we might be able to do this with the abilities packet alone
		}
		if(!$isRollback && $this->invManager !== null){
			$this->invManager->syncCreative();
		}
	}

	public function syncAbilities(Player $for) : void{
		$isOp = $for->hasPermission(DefaultPermissions::ROOT_OPERATOR);

		//ALL of these need to be set for the base layer, otherwise the client will cry
		$boolAbilities = [
			AbilitiesLayer::ABILITY_ALLOW_FLIGHT => $for->getAllowFlight(),
			AbilitiesLayer::ABILITY_FLYING => $for->isFlying(),
			AbilitiesLayer::ABILITY_NO_CLIP => !$for->hasBlockCollision(),
			AbilitiesLayer::ABILITY_OPERATOR => $isOp,
			AbilitiesLayer::ABILITY_TELEPORT => $for->hasPermission(DefaultPermissionNames::COMMAND_TELEPORT_SELF),
			AbilitiesLayer::ABILITY_INVULNERABLE => $for->isCreative(),
			AbilitiesLayer::ABILITY_MUTED => false,
			AbilitiesLayer::ABILITY_WORLD_BUILDER => false,
			AbilitiesLayer::ABILITY_INFINITE_RESOURCES => !$for->hasFiniteResources(),
			AbilitiesLayer::ABILITY_LIGHTNING => false,
			AbilitiesLayer::ABILITY_BUILD => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_MINE => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_DOORS_AND_SWITCHES => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_OPEN_CONTAINERS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_ATTACK_PLAYERS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_ATTACK_MOBS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_PRIVILEGED_BUILDER => false,
		];

		$layers = [
			new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, $boolAbilities, $for->getFlightSpeedMultiplier(), 1, 0.1),
		];
		if($for->isSpectator()){
			//native spectator: the same layers BDS 1.26.52 sends, spectator layer first. The client builds this layer
			//itself (BDS sends no UpdateAbilities when the game mode changes), but every UpdateAbilities replaces them
			array_unshift($layers, SpectatorAbilities::layer($for->isFlying(), !$for->hasBlockCollision()));
		}elseif(!$for->hasBlockCollision()){
			//TODO: HACK! In 1.19.80, the client starts falling when block collision is disabled outside spectator mode
			//(this was our faux spectator mode before native spectator) and it clips into a block. We can't seem to
			//prevent this short of forcing the player to always fly when block collision is disabled. Also, for some
			//reason the client always reads flight state from this layer if present, even though the player isn't in
			//spectator mode.

			$layers[] = new AbilitiesLayer(AbilitiesLayer::LAYER_SPECTATOR, [
				AbilitiesLayer::ABILITY_FLYING => true,
			], null, null, null);
		}

		$this->sendDataPacket(UpdateAbilitiesPacket::create(new AbilitiesData(
			$isOp ? CommandPermissions::OPERATOR : CommandPermissions::NORMAL,
			$isOp ? PlayerPermissions::OPERATOR : PlayerPermissions::MEMBER,
			$for->getId(),
			$layers
		)));
	}

	public function syncAdventureSettings() : void{
		if($this->player === null){
			throw new \LogicException("Cannot sync adventure settings for a player that is not yet created");
		}
		//everything except auto jump is handled via UpdateAbilitiesPacket
		$this->sendDataPacket(UpdateAdventureSettingsPacket::create(
			noAttackingMobs: false,
			noAttackingPlayers: false,
			worldImmutable: false,
			showNameTags: true,
			autoJump: $this->player->hasAutoJump()
		));
	}

	public function syncAvailableCommands() : void{
		$commandData = [];
		$visibleCommands = [];
		foreach($this->server->getCommandMap()->getCommands() as $command){
			if(strtolower($command->getLabel()) === "help"){
				//the client has its own /help, and BDS doesn't send one: a server /help in the list made 1.26 clients drop
				//every command listed before it (no suggestions for /give, /gamemode...). Typed, it still reaches the server.
				continue;
			}
			if(!isset($visibleCommands[$command->getLabel()]) && $command->testPermissionSilent($this->player)){
				$visibleCommands[$command->getLabel()] = $command;
			}
		}
		$overloadMapper = new CommandOverloadMapper(CommandOverloadMapper::commandNames($visibleCommands));
		foreach($visibleCommands as $command){
			$lname = strtolower($command->getLabel());
			$aliases = $command->getAliases();
			$aliasObj = null;
			if(count($aliases) > 0){
				if(!in_array($lname, $aliases, true)){
					//work around a client bug which makes the original name not show when aliases are used
					$aliases[] = $lname;
				}
				$aliasObj = new CommandHardEnum(ucfirst($command->getLabel()) . "Aliases", $aliases);
			}

			$description = $command->getDescription();
			$data = new CommandData(
				$lname, //TODO: commands containing uppercase letters in the name crash 1.9.0 client
				$description instanceof Translatable ? $this->player->getLanguage()->translate($description) : $description,
				0,
				CommandPermissions::NORMAL,
				$aliasObj,
				$overloadMapper->map($command),
				chainedSubCommandData: []
			);

			$commandData[$command->getLabel()] = $data;
		}

		$this->sendDataPacket(AvailableCommandsPacketAssembler::assemble(array_values($commandData), [], []));
	}

	/**
	 * @return string[][]
	 * @phpstan-return array{string, string[]}
	 */
	public function prepareClientTranslatableMessage(Translatable $message) : array{
		//we can't send nested translations to the client, so make sure they are always pre-translated by the server
		$language = $this->player->getLanguage();
		$parameters = array_map(fn(string|Translatable $p) => $p instanceof Translatable ? self::prepareClientTranslatableParameter($language, $p) : $p, $message->getParameters());
		$untranslatedParameterCount = 0;
		$translated = $language->translateString($message->getText(), $parameters, "pocketmine.", $untranslatedParameterCount);
		return [$translated, array_slice($parameters, 0, $untranslatedParameterCount)];
	}

	/**
	 * Vanilla entity names (entity.<id>.name, e.g. the killer in a death message) are sent as "%key" like BDS does, so
	 * that each client shows them in its own language; other nested translations are translated by the server.
	 */
	private static function prepareClientTranslatableParameter(Language $language, Translatable $parameter) : string{
		$key = $parameter->getText();
		if(count($parameter->getParameters()) === 0 && str_starts_with($key, "entity.") && str_ends_with($key, ".name")){
			return "%" . $key;
		}
		return $language->translate($parameter);
	}

	public function onChatMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			[$text, $parameters] = $this->prepareClientTranslatableMessage($message);
			if(!$this->server->isLanguageForced() && count($parameters) <= self::MAX_CLIENT_TRANSLATION_PARAMETERS){
				$this->sendDataPacket(TextPacket::translation($text, $parameters));
			}else{
				$this->sendDataPacket(TextPacket::raw($this->player->getLanguage()->translate($message)));
			}
		}else{
			$this->sendDataPacket(TextPacket::raw($message));
		}
	}

	public function onJukeboxPopup(Translatable|string $message) : void{
		$parameters = [];
		if($message instanceof Translatable){
			[$text, $clientParameters] = $this->prepareClientTranslatableMessage($message);
			if(!$this->server->isLanguageForced() && count($clientParameters) <= self::MAX_CLIENT_TRANSLATION_PARAMETERS){
				[$message, $parameters] = [$text, $clientParameters];
			}else{
				$message = $this->player->getLanguage()->translate($message);
			}
		}
		$this->sendDataPacket(TextPacket::jukeboxPopup($message, $parameters));
	}

	public function onPopup(string $message) : void{
		$this->sendDataPacket(TextPacket::popup($message));
	}

	public function onTip(string $message) : void{
		$this->sendDataPacket(TextPacket::tip($message));
	}

	public function onFormSent(int $id, Form $form) : bool{
		return $this->sendDataPacket(ModalFormRequestPacket::create($id, json_encode($form, JSON_THROW_ON_ERROR)));
	}

	public function onCloseAllForms() : void{
		$this->sendDataPacket(ClientboundCloseFormPacket::create());
	}

	/**
	 * @phpstan-param \Closure() : void $onCompletion
	 */
	private function sendChunkPacket(string $chunkPacket, \Closure $onCompletion, World $world) : void{
		$world->timings->syncChunkSend->startTiming();
		try{
			$this->queueCompressed($chunkPacket);
			$this->dimensionTracker->onTerrainSent();
			if($this->dimensionChangeAckDeadline !== null){
				//the client has terrain of the new dimension: let it leave the dimension change
				$this->sendDimensionChangeAck();
			}
			$onCompletion();
		}finally{
			$world->timings->syncChunkSend->stopTiming();
		}
	}

	/**
	 * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
	 * @param \Closure $onCompletion To be called when chunk sending has completed.
	 * @phpstan-param \Closure() : void $onCompletion
	 */
	public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
		$world = $this->player->getLocation()->getWorld();
		$promiseOrPacket = ChunkCache::getInstance($world, $this->compressor)->request($chunkX, $chunkZ);
		if(is_string($promiseOrPacket)){
			$this->sendChunkPacket($promiseOrPacket, $onCompletion, $world);
			return;
		}
		$promiseOrPacket->onResolve(
			//this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
			function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
				if(!$this->isConnected()){
					return;
				}
				$currentWorld = $this->player->getLocation()->getWorld();
				if($world !== $currentWorld || ($status = $this->player->getUsedChunkStatus($chunkX, $chunkZ)) === null){
					$this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
					return;
				}
				if($status !== UsedChunkStatus::REQUESTED_SENDING){
					//TODO: make this an error
					//this could be triggered due to the shitty way that chunk resends are handled
					//right now - not because of the spammy re-requesting, but because the chunk status reverts
					//to NEEDED if they want to be resent.
					return;
				}
				$this->sendChunkPacket($promise->getResult(), $onCompletion, $world);
			}
		);
	}

	public function stopUsingChunk(int $chunkX, int $chunkZ) : void{

	}

	public function onEnterWorld() : void{
		if($this->player !== null){
			$world = $this->player->getWorld();
			//another dimension: ChangeDimension; same dimension: a detour through another dimension so the client drops
			//the previous world's terrain (ChangeDimension to the dimension the client is already in would leave it in
			//an endless loading screen); a same-dimension switch during a change may wait for it to end. See
			//ClientDimensionTracker.
			$change = $this->dimensionTracker->onEnterWorld($world->getDimension(), !$this->player->isAlive(), $this->player->spawned);
			if($change !== null){
				$this->sendClientDimensionChange($change);
			}elseif($this->dimensionTracker->holdsChunks()){
				$this->logger->debug("Entered world " . $world->getFolderName() . " during a dimension change, completing the switch once it ends");
			}
			$this->syncWorldState($world);
		}
	}

	private function syncWorldState(World $world) : void{
		$this->syncWorldTime($world->getTime());
		$this->syncWorldDifficulty($world->getDifficulty());
		$this->syncWorldSpawnPoint($world->getSpawnLocation());
		$weather = $world->getWeather();
		$this->syncWorldWeather($weather->getRainLevel(), $weather->getLightningLevel());
		$this->syncWorldGameRules($world);
	}

	/**
	 * Game rules of the world that the client applies itself (coordinates, days played, sun and moon, respawn
	 * screen, keep inventory), see {@link GameRules::isSentToClients()}.
	 *
	 * @return ProtocolGameRule[]
	 * @phpstan-return array<string, ProtocolGameRule>
	 */
	public static function getClientGameRules(World $world) : array{
		$rules = [];
		foreach(GameRules::getNames() as $name){
			if(GameRules::isSentToClients($name)){
				$value = $world->getGameRule($name);
				$rules[$name] = is_bool($value) ? new BoolGameRule($value, false) : new IntGameRule($value, false);
			}
		}
		return $rules;
	}

	public function syncWorldGameRules(World $world) : void{
		$this->sendDataPacket(GameRulesChangedPacket::create(self::getClientGameRules($world)));
	}

	public function syncWorldTime(int $worldTime) : void{
		$this->sendDataPacket(SetTimePacket::create($worldTime));
	}

	/**
	 * Sends the rain and thunder state: START_RAIN/START_THUNDER carry the intensity (0-65535), 0 stops them.
	 *
	 * @param float $rainLevel      0.0 - 1.0
	 * @param float $lightningLevel 0.0 - 1.0
	 */
	public function syncWorldWeather(float $rainLevel, float $lightningLevel) : void{
		$this->sendDataPacket($rainLevel > 0 ?
			LevelEventPacket::create(LevelEvent::START_RAIN, (int) ($rainLevel * 65535), null) :
			LevelEventPacket::create(LevelEvent::STOP_RAIN, 0, null)
		);
		$this->sendDataPacket($lightningLevel > 0 ?
			LevelEventPacket::create(LevelEvent::START_THUNDER, (int) ($lightningLevel * 65535), null) :
			LevelEventPacket::create(LevelEvent::STOP_THUNDER, 0, null)
		);
	}

	public function syncWorldDifficulty(int $worldDifficulty) : void{
		$this->sendDataPacket(SetDifficultyPacket::create($worldDifficulty));
	}

	public function getInvManager() : ?InventoryManager{
		return $this->invManager;
	}

	/**
	 * 1.26.40+: the vanilla client expects the player list to carry the skin as the client originally sent it (persona
	 * pieces, profile hash, ...) and the real device platform, so echo the login data back where we have it.
	 */
	private function createPlayerListEntry(Player $player) : PlayerListEntry{
		$info = $player->getPlayerInfo();
		$extraData = $info->getExtraData();
		$deviceOs = $extraData["DeviceOS"] ?? -1;
		//1.26.52 only knows Google, iOS, OSX, Amazon, Win32, Dedicated, Sony, Nintendo, Xbox, Linux and Unknown: a client
		//that gets any other value (the old Windows 10 UWP 7, Gear VR, HoloLens, tvOS, Windows Phone) in another player's
		//entry disconnects on the spot. Plugins and fake players still send 7, so map the rest to Unknown.
		if(!is_int($deviceOs) || !in_array($deviceOs, self::VALID_BUILD_PLATFORMS, true)){
			$deviceOs = -1;
		}
		$platformOnlineId = $extraData["PlatformOnlineId"] ?? "";
		$skin = $player->getSkin();
		//the login skin as the client sent it, unless the player has changed skin since
		$loginSkinData = $skin === $info->getSkin() ? $info->getNetworkSkinData() : null;
		return PlayerListEntry::createAdditionEntry(
			$player->getUniqueId(),
			$player->getId(),
			$player->getDisplayName(),
			$loginSkinData ?? $this->typeConverter->getSkinAdapter()->toSkinData($skin),
			$player->getXuid(),
			is_string($platformOnlineId) ? $platformOnlineId : "",
			$deviceOs
		);
	}

	/** Build platforms the 1.26.52 client accepts in a PlayerListPacket entry (BuildPlatform enum of the protocol docs) */
	private const VALID_BUILD_PLATFORMS = [-1, 1, 2, 3, 4, 8, 9, 11, 12, 13, 15];

	/**
	 * @param Player[] $players
	 */
	public function syncPlayerList(array $players) : void{
		$this->sendDataPacket(PlayerListPacket::add(array_map($this->createPlayerListEntry(...), array_values($players))));
	}

	public function onPlayerAdded(Player $p) : void{
		$this->sendDataPacket(PlayerListPacket::add([$this->createPlayerListEntry($p)]));
	}

	public function onPlayerRemoved(Player $p) : void{
		if($p !== $this->player){
			$this->sendDataPacket(PlayerListPacket::remove([PlayerListEntry::createRemovalEntry($p->getUniqueId())]));
		}
	}

	public function onTitle(string $title) : void{
		$this->sendDataPacket(SetTitlePacket::title($title));
	}

	public function onSubTitle(string $subtitle) : void{
		$this->sendDataPacket(SetTitlePacket::subtitle($subtitle));
	}

	public function onActionBar(string $actionBar) : void{
		$this->sendDataPacket(SetTitlePacket::actionBarMessage($actionBar));
	}

	public function onClearTitle() : void{
		$this->sendDataPacket(SetTitlePacket::clearTitle());
	}

	public function onResetTitleOptions() : void{
		$this->sendDataPacket(SetTitlePacket::resetTitleOptions());
	}

	public function onTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
		$this->sendDataPacket(SetTitlePacket::setAnimationTimes($fadeIn, $stay, $fadeOut));
	}

	public function onToastNotification(string $title, string $body) : void{
		$this->sendDataPacket(ToastRequestPacket::create($title, $body));
	}

	public function onOpenSignEditor(Vector3 $signPosition, bool $frontSide) : void{
		$this->sendDataPacket(OpenSignPacket::create(BlockPosition::fromVector3($signPosition), $frontSide));
	}

	public function onItemCooldownChanged(Item $item, int $ticks) : void{
		$this->sendDataPacket(PlayerStartItemCooldownPacket::create(
			GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName(),
			$ticks
		));
	}

	public function setLastPlayerInputTick(int $tick) : void{
		$this->lastPlayerInputTick = $tick;
	}

	/**
	 * Tells the client about a firework boost while gliding, so it can predict its own movement for the given duration.
	 */
	public function onGlideBoost(int $durationTicks) : void{
		if($this->player !== null){
			$this->sendDataPacket(MovementEffectPacket::create(
				$this->player->getId(),
				MovementEffectType::GLIDE_BOOST,
				$durationTicks,
				$this->lastPlayerInputTick
			));
		}
	}

	public function onChargeItemComplete() : void{
		if($this->player !== null){
			$this->sendDataPacket(ActorEventPacket::create(
				$this->player->getId(),
				ActorEvent::CHARGED_ITEM,
				0,
				null
			));
		}
	}

	public function tick() : void{
		if(!$this->isConnected()){
			$this->dispose();
			return;
		}

		if($this->info === null){
			if(time() >= $this->connectTime + 10){
				$this->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_loginTimeout());
			}

			return;
		}

		$this->spawnTerrainPrefetch?->tick();

		if($this->player !== null){
			if(!$this->dimensionTracker->holdsChunks()){
				//chunks of the new world must wait for the client to come back from the detour of a world switch
				$this->player->doChunkRequests();
			}

			$dirtyAttributes = $this->player->getAttributeMap()->needSend();
			$this->entityEventBroadcaster->syncAttributes([$this], $this->player, $dirtyAttributes);
			foreach($dirtyAttributes as $attribute){
				//TODO: we might need to send these to other players in the future
				//if that happens, this will need to become more complex than a flag on the attribute itself
				$attribute->markSynchronized();
			}

			$this->tickDimensionChange();
		}
		Timings::$playerNetworkSendInventorySync->startTiming();
		try{
			$this->invManager?->flushPendingUpdates();
		}finally{
			Timings::$playerNetworkSendInventorySync->stopTiming();
		}

		$this->flushGamePacketQueue();
	}
}
