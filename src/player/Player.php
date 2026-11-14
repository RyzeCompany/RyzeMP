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

namespace pocketmine\player;

use pocketmine\block\BaseSign;
use pocketmine\block\Bed;
use pocketmine\block\BlockTypeTags;
use pocketmine\block\UnknownBlock;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\command\CommandSender;
use pocketmine\crafting\CraftingGrid;
use pocketmine\data\java\GameModeIdMap;
use pocketmine\entity\animal\Parrot;
use pocketmine\entity\animation\Animation;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\animation\ConsumingItemAnimation;
use pocketmine\entity\animation\CriticalHitAnimation;
use pocketmine\entity\animation\MagicHitAnimation;
use pocketmine\entity\Attribute;
use pocketmine\entity\CombatRules;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\WardenSpawnTracker;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExtinguishEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerBedLeaveEvent;
use pocketmine\event\player\PlayerBlockPickEvent;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerDisplayNameChangeEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerEmoteEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerEntityPickEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerJumpEvent;
use pocketmine\event\player\PlayerKickEvent;
use pocketmine\event\player\PlayerMissSwingEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerToggleCrawlEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\player\PlayerToggleGlideEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\event\player\PlayerToggleSwimEvent;
use pocketmine\event\player\PlayerTransferEvent;
use pocketmine\event\player\PlayerViewDistanceChangeEvent;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\CreativeInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\PlayerCraftingInventory;
use pocketmine\inventory\PlayerCursorInventory;
use pocketmine\inventory\TemporaryInventory;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionBuilder;
use pocketmine\inventory\transaction\TransactionCancelledException;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Axe;
use pocketmine\item\Chargeable;
use pocketmine\item\ConsumableItem;
use pocketmine\item\Durable;
use pocketmine\item\Elytra;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\FireAspectIgnition;
use pocketmine\item\enchantment\KnockbackEnchantment;
use pocketmine\item\enchantment\MeleeWeaponEnchantment;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemUseResult;
use pocketmine\item\Mace;
use pocketmine\item\Releasable;
use pocketmine\item\Shield;
use pocketmine\item\Spear;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\DisconnectFailReason;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PlayerMetadataFlags;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissibleBase;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\player\chat\StandardChatFormatter;
use pocketmine\plugin\PluginFaultHandler;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\Server;
use pocketmine\ServerProperties;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use pocketmine\world\ChunkListener;
use pocketmine\world\ChunkListenerNoOpTrait;
use pocketmine\world\ChunkLoader;
use pocketmine\world\ChunkTicker;
use pocketmine\world\format\Chunk;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\SleepRules;
use pocketmine\world\sound\EntityAttackNoDamageSound;
use pocketmine\world\sound\EntityAttackSound;
use pocketmine\world\sound\FireExtinguishSound;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\LungeSound;
use pocketmine\world\sound\MaceSmashSound;
use pocketmine\world\sound\ShieldBlockSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\sound\SpearHitSound;
use pocketmine\world\SpawnLocator;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use Ramsey\Uuid\UuidInterface;
use function abs;
use function array_filter;
use function array_shift;
use function assert;
use function count;
use function explode;
use function floor;
use function get_class;
use function hrtime;
use function in_array;
use function max;
use function mb_strlen;
use function microtime;
use function min;
use function preg_match;
use function spl_object_id;
use function sqrt;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use const INF;
use const M_PI;
use const M_SQRT3;
use const PHP_INT_MAX;

/**
 * Main class that handles networking, recovery, and packet sending to the server part
 */
class Player extends Human implements CommandSender, ChunkListener, IPlayer, NeverSavedWithChunkEntity{
	use PermissibleDelegateTrait;

	/**
	 * @var RideableSeat[]|null
	 * @phpstan-var list<RideableSeat>|null
	 */
	private static ?array $shoulderSeats = null;

	/** Seconds between two logged invalid form responses of the same player */
	private const FORM_VALIDATION_LOG_INTERVAL = 5.0;

	private const MOVES_PER_TICK = 2;
	private const MOVE_BACKLOG_SIZE = 100 * self::MOVES_PER_TICK; //100 ticks backlog (5 seconds)
	/** How far above a surface a falling player may be to count as landed (client positions are exact on landing). */
	private const LANDING_TOLERANCE = 0.01;
	/** Fall distance ignored by the damage calculation: the client stands 0.001 above the position it is sent. */
	private const CLIENT_POSITION_FALL_TOLERANCE = 0.0015;
	/** Height of a sleeping player's eyes above the bed block (BDS 1.26.60 corrects the client to it) */
	private const SLEEPING_EYE_HEIGHT = 0.90625;
	/**
	 * Entity flags the client sets on itself from its own input (PlayerAuthInput start and stop flags), per flags property.
	 */
	private const CLIENT_PREDICTED_FLAGS = [
		EntityMetadataProperties::FLAGS => (1 << EntityMetadataFlags::SNEAKING) | (1 << EntityMetadataFlags::SPRINTING) | (1 << EntityMetadataFlags::GLIDING) | (1 << EntityMetadataFlags::SWIMMING),
		EntityMetadataProperties::FLAGS2 => 1 << (EntityMetadataFlags::CRAWLING - 64),
	];
	/** Riptide spin attack damage before enchantments (Java startAutoSpinAttack(20, 8.0)) */
	private const SPIN_ATTACK_DAMAGE = 8.0;

	/** Max length of a chat message (UTF-8 codepoints, not bytes) */
	private const MAX_CHAT_CHAR_LENGTH = 512;
	/**
	 * Max length of a chat message in bytes. This is a theoretical maximum (if every character was 4 bytes).
	 * Since mb_strlen() is O(n), it gets very slow with large messages. Checking byte length with strlen() is O(1) and
	 * is a useful heuristic to filter out oversized messages.
	 */
	private const MAX_CHAT_BYTE_LENGTH = self::MAX_CHAT_CHAR_LENGTH * 4;
	private const MAX_REACH_DISTANCE_CREATIVE = 13;
	private const MAX_REACH_DISTANCE_SURVIVAL = 7;
	private const MAX_REACH_DISTANCE_ENTITY_INTERACTION = 8;

	public const DEFAULT_FLIGHT_SPEED_MULTIPLIER = 0.05;

	public const TAG_FIRST_PLAYED = "firstPlayed"; //TAG_Long
	public const TAG_LAST_PLAYED = "lastPlayed"; //TAG_Long
	private const TAG_GAME_MODE = "playerGameType"; //TAG_Int
	private const TAG_SPAWN_WORLD = "SpawnLevel"; //TAG_String
	private const TAG_SPAWN_X = "SpawnX"; //TAG_Int
	private const TAG_SPAWN_Y = "SpawnY"; //TAG_Int
	private const TAG_SPAWN_Z = "SpawnZ"; //TAG_Int
	private const TAG_SPAWN_BLOCK = "SpawnBlock"; //TAG_Byte: SpawnLocator::SPAWN_BLOCK_* (bed, respawn anchor)
	private const TAG_DEATH_WORLD = "DeathLevel"; //TAG_String
	private const TAG_DEATH_X = "DeathPositionX"; //TAG_Int
	private const TAG_DEATH_Y = "DeathPositionY"; //TAG_Int
	private const TAG_DEATH_Z = "DeathPositionZ"; //TAG_Int
	public const TAG_LEVEL = "Level"; //TAG_String
	public const TAG_LAST_KNOWN_XUID = "LastKnownXUID"; //TAG_String
	public const TAG_TIME_SINCE_REST = "TimeSinceRest"; //TAG_Int
	public const TAG_WARDEN_SPAWN_TRACKER = "WardenSpawnTracker"; //TAG_Compound
	private const TAG_SEEN_CREDITS = "hasSeenCredits"; //TAG_Byte

	/**
	 * Validates the given username.
	 */
	public static function isValidUserName(?string $name) : bool{
		if($name === null){
			return false;
		}

		$lname = strtolower($name);
		$len = strlen($name);
		return $lname !== "rcon" && $lname !== "console" && $len >= 1 && $len <= 16 && preg_match("/[^A-Za-z0-9_ ]/", $name) === 0;
	}

	protected ?NetworkSession $networkSession;

	public bool $spawned = false;

	protected string $username;
	protected string $displayName;
	protected string $xuid = "";
	protected bool $authenticated;
	protected PlayerInfo $playerInfo;

	protected ?Inventory $currentWindow = null;
	/** @var Inventory[] */
	protected array $permanentWindows = [];
	protected PlayerCursorInventory $cursorInventory;
	protected PlayerCraftingInventory $craftingGrid;
	protected CreativeInventory $creativeInventory;

	protected int $messageCounter = 2;

	/**
	 * Squared distances (blocks) up to which viewers get every movement update / every 2nd tick; beyond, every 4th.
	 * -1 disables the throttling. See player.movement-broadcast in pocketmine.yml.
	 */
	private int $movementFullRateDistanceSquared;
	private int $movementHalfRateDistanceSquared;
	/** Some viewers missed the last movement update; send the final position to everyone once the player stops. */
	private bool $movementBroadcastPending = false;
	/** Soul Speed level of the worn boots, null when the boots changed since it was last read */
	private ?int $soulSpeedLevel = null;
	/** Whether the player was touching water at its last movement (splash vibration). */
	private bool $wasInWater = false;

	protected int $firstPlayed;
	protected int $lastPlayed;
	/** Ticks since the player last entered a bed or died (Bedrock "Time Since Last Rest", used by phantom spawning). */
	protected int $timeSinceRest = 0;
	/** Warning level towards wardens raised by sculk shriekers; created when first needed */
	private ?WardenSpawnTracker $wardenSpawnTracker = null;
	/** Whether the player has seen the End Poem and credits (vanilla hasSeenCredits), see PortalTravel. */
	private bool $seenCredits = false;
	private ?RecipeBook $recipeBook = null;
	protected GameMode $gamemode;

	/**
	 * @var UsedChunkStatus[] chunkHash => status
	 * @phpstan-var array<int, UsedChunkStatus>
	 */
	protected array $usedChunks = [];
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $activeChunkGenerationRequests = [];
	/**
	 * @var true[] chunkHash => dummy
	 * @phpstan-var array<int, true>
	 */
	protected array $loadQueue = [];
	protected int $nextChunkOrderRun = 5;
	/**
	 * Value of $nextChunkOrderRun while its countdown is the one armed by the player's own movement and nothing else
	 * changed it, null otherwise. When such a countdown ends while the last chunk order is still up to date, the order
	 * is skipped: it would do nothing (see {@link Player::isChunkOrderUpToDate()}).
	 */
	private ?int $chunkOrderMovementTimer = null;
	/** Whether something which orderChunks() reacts to happened since the last order (unloads, teleports...) */
	private bool $chunkOrderDirty = true;
	/** World ID, centre chunk, radii and number of chunks selected by the last order */
	private int $lastChunkOrderWorldId = 0;
	private int $lastChunkOrderCenterX = 0;
	private int $lastChunkOrderCenterZ = 0;
	private int $lastChunkOrderRadius = 0;
	private int $lastChunkOrderTickRadius = 0;
	private int $lastChunkOrderSimulationDistance = 0;
	private int $lastChunkOrderChunkCount = 0;
	/**
	 * @var bool[] class => whether orderChunks(), isConnected() and getWorld() are the core ones
	 * @phpstan-var array<class-string<Player>, bool>
	 */
	private static array $coreChunkOrderByClass = [];
	/**
	 * @var bool[] class => whether the entity class keeps every method checkNearEntities() calls on touched entities
	 * as Entity declares them (so being touched does nothing to it)
	 * @phpstan-var array<class-string<Entity>, bool>
	 */
	private static array $touchInertByClass = [];

	/** @var true[] */
	private array $tickingChunks = [];
	/**
	 * Chunks within the simulation distance, whose entities are ticked (see World::getSimulationDistance())
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $simulatingChunks = [];

	protected int $viewDistance = -1;
	/** Distance last passed to setViewDistance() (what the client asked for), -1 before the first one */
	private int $requestedViewDistance = -1;
	protected int $spawnThreshold;
	protected int $spawnChunkLoadCount = 0;
	protected int $chunksPerTick;
	/** chunk-sending.fast-spawn */
	private bool $fastSpawn;
	protected ChunkSelector $chunkSelector;
	protected ChunkLoader $chunkLoader;
	protected ChunkTicker $chunkTicker;

	/** @var bool[] map: raw UUID (string) => bool */
	protected array $hiddenPlayers = [];

	protected float $moveRateLimit = 10 * self::MOVES_PER_TICK;
	protected ?float $lastMovementProcess = null;

	protected int $inAirTicks = 0;

	/** remaining ticks of a Riptide spin attack (0 = not spinning) */
	private int $spinAttackTicks = 0;
	/** the Riptide trident used for the current spin attack (its melee enchantments apply to the hit) */
	private ?Item $spinAttackItem = null;

	protected float $stepHeight = 0.6;

	protected ?Vector3 $sleeping = null;
	/** Where sleepOn() laid the player down; null when waking up shouldn't move them (teleports) */
	private ?Vector3 $lyingPosition = null;
	private ?Position $spawnPosition = null;
	/** What set the spawn point (SpawnLocator::SPAWN_BLOCK_*): bed and anchor spawns only work while the block does */
	private int $spawnBlockType = SpawnLocator::SPAWN_BLOCK_NONE;

	private bool $respawnLocked = false;
	/** The next respawn is a hardcore one: at the death position instead of the spawn point. */
	private bool $hardcoreRespawn = false;

	private ?Position $deathPosition = null;

	//TODO: Abilities
	protected bool $autoJump = true;
	protected bool $allowFlight = false;
	protected bool $blockCollision = true;
	protected bool $flying = false;
	protected bool $sneakPressed = false;
	/** Text of the interaction button shown to touch controls (see setInteractiveTag()). */
	private string $interactiveTag = "";

	protected float $flightSpeedMultiplier = self::DEFAULT_FLIGHT_SPEED_MULTIPLIER;

	/** @phpstan-var positive-int|null  */
	protected ?int $lineHeight = null;
	protected string $locale = "en_US";
	private Language $language;

	protected int $startAction = -1;

	/** Ticks spent gliding since the player started, used for elytra durability loss. */
	protected int $glideTicks = 0;
	/** Horizontal distance moved during the previous tick while gliding, used for kinetic (fly into wall) damage. */
	protected float $lastGlideHorizontalSpeed = 0.0;
	protected ?Vector3 $lastGlideTickPosition = null;

	/** Server tick on which the player raised a shield, or -1 if no shield is raised. */
	protected int $shieldRaisedTick = -1;
	/** Whether the raised shield is currently blocking (raised for at least Shield::BLOCK_DELAY_TICKS). */
	protected bool $blocking = false;

	/** Distance moved during the last tick (blocks/tick), measured from the positions the client reported. */
	protected Vector3 $tickMovement;
	protected ?Vector3 $previousTickPosition = null;
	/** Server tick of the last spear charge hit. */
	protected int $lastSpearChargeHitTick = -1;

	/**
	 * @phpstan-var array<int|string, int>
	 * @var int[] stateId|cooldownTag => ticks map
	 */
	protected array $usedItemsCooldown = [];

	private int $lastEmoteTick = 0;

	protected int $formIdCounter = 0;
	/** @var Form[] */
	protected array $forms = [];
	/** microtime() of the last logged invalid form response (they are rate-limited: a client can send them at will) */
	private float $lastFormValidationLog = 0.0;
	private int $suppressedFormValidationLogs = 0;
	private bool $loggedMessageAfterQuit = false;
	/** Set once the player's data has been saved on quit: nothing that happens to it afterwards is persisted */
	private bool $savedOnQuit = false;

	protected \Logger $logger;

	protected ?SurvivalBlockBreakHandler $blockBreakHandler = null;

	/** DisconnectPacket reason sent by disconnect(): kick() sets KICKED while it disconnects */
	private int $disconnectFailReason = DisconnectFailReason::UNKNOWN;

	public function __construct(Server $server, NetworkSession $session, PlayerInfo $playerInfo, bool $authenticated, Location $spawnLocation, ?CompoundTag $namedtag){
		$username = TextFormat::clean($playerInfo->getUsername());
		$this->logger = new \PrefixedLogger($server->getLogger(), "Player: $username");

		$this->server = $server;
		$this->networkSession = $session;
		$this->playerInfo = $playerInfo;
		$this->authenticated = $authenticated;

		$this->username = $username;
		$this->displayName = $this->username;
		$this->locale = $this->playerInfo->getLocale();
		$this->language = $server->getLanguageForLocale($this->locale);

		$this->uuid = $this->playerInfo->getUuid();
		$this->xuid = $this->playerInfo instanceof XboxLivePlayerInfo ? $this->playerInfo->getXuid() : "";

		$this->creativeInventory = CreativeInventory::getInstance();

		$rootPermissions = [DefaultPermissions::ROOT_USER => true];
		if($this->server->isOp($this->username)){
			$rootPermissions[DefaultPermissions::ROOT_OPERATOR] = true;
		}
		$this->perm = new PermissibleBase($rootPermissions);
		$this->chunksPerTick = $this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::CHUNK_SENDING_PER_TICK, 4);
		$this->fastSpawn = $this->server->getConfigGroup()->getPropertyBool(YmlServerProperties::CHUNK_SENDING_FAST_SPAWN, true);
		$fullRateDistance = $this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::PLAYER_MOVEMENT_BROADCAST_FULL_RATE_DISTANCE, 24);
		$halfRateDistance = max($fullRateDistance, $this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::PLAYER_MOVEMENT_BROADCAST_HALF_RATE_DISTANCE, 48));
		$this->movementFullRateDistanceSquared = $fullRateDistance < 0 ? -1 : $fullRateDistance ** 2;
		$this->movementHalfRateDistanceSquared = $halfRateDistance ** 2;
		$this->spawnThreshold = (int) (($this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::CHUNK_SENDING_SPAWN_RADIUS, 4) ** 2) * M_PI);
		$this->chunkSelector = new ChunkSelector();

		$this->chunkLoader = new class implements ChunkLoader{};
		$this->chunkTicker = new ChunkTicker();
		$world = $spawnLocation->getWorld();
		//load the spawn chunk so we can see the terrain
		$xSpawnChunk = $spawnLocation->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$zSpawnChunk = $spawnLocation->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		$world->registerChunkLoader($this->chunkLoader, $xSpawnChunk, $zSpawnChunk, true);
		$world->registerChunkListener($this, $xSpawnChunk, $zSpawnChunk);
		$this->usedChunks[World::chunkHash($xSpawnChunk, $zSpawnChunk)] = UsedChunkStatus::NEEDED;

		$this->tickMovement = Vector3::zero();

		parent::__construct($spawnLocation, $this->playerInfo->getSkin(), $namedtag);
	}

	protected function initHumanData(CompoundTag $nbt) : void{
		$this->setNameTag($this->username);
	}

	private function callDummyItemHeldEvent() : void{
		$slot = $this->inventory->getHeldItemIndex();

		$event = new PlayerItemHeldEvent($this, $this->inventory->getItem($slot), $slot);
		$event->call();
		//TODO: this event is actually cancellable, but cancelling it here has no meaningful result, so we
		//just ignore it. We fire this only because the content of the held slot changed, not because the
		//held slot index changed. We can't prevent that from here, and nor would it be sensible to.
	}

	/**
	 * Stops the use of the held item (eating, a bow, a raised shield...) when the held slot changes to another item.
	 */
	private function createHeldItemListener() : CallbackInventoryListener{
		return new CallbackInventoryListener(
			function(Inventory $unused, int $slot, Item $oldItem) : void{
				if($slot === $this->inventory->getHeldItemIndex()){
					//Java LivingEntity.updateUsingItem(): the use goes on while the hand holds the same kind of item, so
					//wear or new NBT (a shield that just blocked a hit) doesn't lower it
					if($oldItem->getStateId() !== $this->inventory->getItemInHand()->getStateId()){
						$this->setUsingItem(false);
					}

					$this->callDummyItemHeldEvent();
				}
			},
			function() : void{
				$this->setUsingItem(false);
				$this->callDummyItemHeldEvent();
			}
		);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->addDefaultWindows();

		$this->inventory->getListeners()->add($this->createHeldItemListener());
		$this->armorInventory->getListeners()->add(new CallbackInventoryListener(
			onSlotChange: function(Inventory $unused, int $slot) : void{
				if($slot === ArmorInventory::SLOT_FEET){
					$this->soulSpeedLevel = null;
				}
			},
			onContentChange: function() : void{ $this->soulSpeedLevel = null; }
		));
		$recipeBook = $this->getRecipeBook();
		$recipeBook->load($nbt);
		$this->inventory->getListeners()->add($recipeBook);
		$this->armorInventory->getListeners()->add($recipeBook);
		$this->offHandInventory->getListeners()->add($recipeBook);

		$this->firstPlayed = $nbt->getLong(self::TAG_FIRST_PLAYED, $now = (int) (microtime(true) * 1000));
		$this->lastPlayed = $nbt->getLong(self::TAG_LAST_PLAYED, $now);
		$this->timeSinceRest = max(0, $nbt->getInt(self::TAG_TIME_SINCE_REST, 0));
		$this->seenCredits = $nbt->getByte(self::TAG_SEEN_CREDITS, 0) !== 0;
		if(($wardenTag = $nbt->getTag(self::TAG_WARDEN_SPAWN_TRACKER)) instanceof CompoundTag){
			$this->getWardenSpawnTracker()->load($wardenTag, $this->server->getTick());
		}

		if(!$this->server->getForceGamemode() && ($gameModeTag = $nbt->getTag(self::TAG_GAME_MODE)) instanceof IntTag){
			$this->internalSetGameMode(GameModeIdMap::getInstance()->fromId($gameModeTag->getValue()) ?? GameMode::SURVIVAL); //TODO: bad hack here to avoid crashes on corrupted data
		}else{
			$this->internalSetGameMode($this->server->getGamemode());
		}

		$this->keepMovement = true;

		$this->setNameTagVisible();
		$this->setNameTagAlwaysVisible();
		$this->setCanClimb();

		if(($world = $this->server->getWorldManager()->getWorldByName($nbt->getString(self::TAG_SPAWN_WORLD, ""))) instanceof World){
			$this->spawnPosition = new Position($nbt->getInt(self::TAG_SPAWN_X), $nbt->getInt(self::TAG_SPAWN_Y), $nbt->getInt(self::TAG_SPAWN_Z), $world);
			$this->spawnBlockType = $nbt->getByte(self::TAG_SPAWN_BLOCK, SpawnLocator::SPAWN_BLOCK_NONE);
		}
		if(($world = $this->server->getWorldManager()->getWorldByName($nbt->getString(self::TAG_DEATH_WORLD, ""))) instanceof World){
			$this->deathPosition = new Position($nbt->getInt(self::TAG_DEATH_X), $nbt->getInt(self::TAG_DEATH_Y), $nbt->getInt(self::TAG_DEATH_Z), $world);
		}
	}

	public function getLeaveMessage() : Translatable|string{
		if($this->spawned){
			return KnownTranslationFactory::multiplayer_player_left($this->getDisplayName())->prefix(TextFormat::YELLOW);
		}

		return "";
	}

	public function isAuthenticated() : bool{
		return $this->authenticated;
	}

	/**
	 * Returns an object containing information about the player, such as their username, skin, and misc extra
	 * client-specific data.
	 */
	public function getPlayerInfo() : PlayerInfo{ return $this->playerInfo; }

	/**
	 * If the player is logged into Xbox Live, returns their Xbox user ID (XUID) as a string. Returns an empty string if
	 * the player is not logged into Xbox Live.
	 */
	public function getXuid() : string{
		return $this->xuid;
	}

	/**
	 * Returns the player's UUID. This should be the preferred method to identify a player.
	 * It does not change if the player changes their username.
	 *
	 * All players will have a UUID, regardless of whether they are logged into Xbox Live or not. However, note that
	 * non-XBL players can fake their UUIDs.
	 */
	public function getUniqueId() : UuidInterface{
		return parent::getUniqueId();
	}

	/**
	 * TODO: not sure this should be nullable
	 */
	public function getFirstPlayed() : ?int{
		return $this->firstPlayed;
	}

	/**
	 * TODO: not sure this should be nullable
	 */
	public function getLastPlayed() : ?int{
		return $this->lastPlayed;
	}

	public function hasPlayedBefore() : bool{
		return $this->lastPlayed - $this->firstPlayed > 1; // microtime(true) - microtime(true) may have less than one millisecond difference
	}

	/**
	 * Sets whether the player is allowed to toggle flight mode.
	 *
	 * If set to false, the player will be locked in its current flight mode (flying/not flying), and attempts by the
	 * player to enter or exit flight mode will be prevented.
	 *
	 * Note: Setting this to false DOES NOT change whether the player is currently flying. Use
	 * {@link Player::setFlying()} for that purpose.
	 */
	public function setAllowFlight(bool $value) : void{
		if($this->allowFlight !== $value){
			$this->allowFlight = $value;
			$this->getNetworkSession()->syncAbilities($this);
		}
	}

	/**
	 * Returns whether the player is allowed to toggle its flight state.
	 *
	 * If false, the player is locked in its current flight mode (flying/not flying), and attempts by the player to
	 * enter or exit flight mode will be prevented.
	 */
	public function getAllowFlight() : bool{
		return $this->allowFlight;
	}

	/**
	 * Sets whether the player's movement may be obstructed by blocks with collision boxes.
	 * If set to false, the player can move through any block unobstructed.
	 *
	 * Note: Enabling flight mode in conjunction with this is recommended. A non-flying player will simply fall through
	 * the ground into the void.
	 * @see Player::setFlying()
	 */
	public function setHasBlockCollision(bool $value) : void{
		if($this->blockCollision !== $value){
			$this->blockCollision = $value;
			$this->getNetworkSession()->syncAbilities($this);
		}
	}

	/**
	 * Returns whether blocks may obstruct the player's movement.
	 * If false, the player can move through any block unobstructed.
	 */
	public function hasBlockCollision() : bool{
		return $this->blockCollision;
	}

	public function setFlying(bool $value) : void{
		if($this->flying !== $value){
			$this->flying = $value;
			$this->resetFallDistance();
			$this->getNetworkSession()->syncAbilities($this);
		}
	}

	public function isFlying() : bool{
		return $this->flying;
	}

	/**
	 * Sets the player's flight speed multiplier.
	 *
	 * Normal flying speed in blocks-per-tick is (multiplier * 10) blocks per tick.
	 * When sprint-flying, this is doubled to 20.
	 *
	 * If set to zero, the player will not be able to move in the xz plane when flying.
	 * Negative values will invert the controls.
	 *
	 * Note: Movement speed attribute does not influence flight speed.
	 *
	 * @see Player::DEFAULT_FLIGHT_SPEED_MULTIPLIER
	 */
	public function setFlightSpeedMultiplier(float $flightSpeedMultiplier) : void{
		if($this->flightSpeedMultiplier !== $flightSpeedMultiplier){
			$this->flightSpeedMultiplier = $flightSpeedMultiplier;
			$this->getNetworkSession()->syncAbilities($this);
		}
	}

	/**
	 * Returns the player's flight speed multiplier.
	 *
	 * Normal flying speed in blocks-per-tick is (multiplier * 10) blocks per tick.
	 * When sprint-flying, this is doubled to 20.
	 *
	 * If set to zero, the player will not be able to move in the xz plane when flying.
	 * Negative values will invert the controls.
	 *
	 * @see Player::DEFAULT_FLIGHT_SPEED_MULTIPLIER
	 */
	public function getFlightSpeedMultiplier() : float{
		return $this->flightSpeedMultiplier;
	}

	public function setAutoJump(bool $value) : void{
		if($this->autoJump !== $value){
			$this->autoJump = $value;
			$this->getNetworkSession()->syncAdventureSettings();
		}
	}

	public function hasAutoJump() : bool{
		return $this->autoJump;
	}

	public function spawnTo(Player $player) : void{
		if($this->isAlive() && $player->isAlive() && $player->canSee($this) && !$this->isSpectator()){
			parent::spawnTo($player);
		}
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function getScreenLineHeight() : int{
		return $this->lineHeight ?? 7;
	}

	public function setScreenLineHeight(?int $height) : void{
		if($height !== null && $height < 1){
			throw new \InvalidArgumentException("Line height must be at least 1");
		}
		$this->lineHeight = $height;
	}

	public function canSee(Player $player) : bool{
		return !isset($this->hiddenPlayers[$player->getUniqueId()->getBytes()]);
	}

	public function hidePlayer(Player $player) : void{
		if($player === $this){
			return;
		}
		$this->hiddenPlayers[$player->getUniqueId()->getBytes()] = true;
		$player->despawnFrom($this);
	}

	public function showPlayer(Player $player) : void{
		if($player === $this){
			return;
		}
		unset($this->hiddenPlayers[$player->getUniqueId()->getBytes()]);
		if($player->isOnline()){
			$player->spawnTo($this);
		}
	}

	public function canCollideWith(Entity $entity) : bool{
		return false;
	}

	public function canBeCollidedWith() : bool{
		return !$this->isSpectator() && parent::canBeCollidedWith();
	}

	public function resetFallDistance() : void{
		parent::resetFallDistance();
		$this->inAirTicks = 0;
	}

	public function getViewDistance() : int{
		return $this->viewDistance;
	}

	/**
	 * Applies again the distance last passed to {@link Player::setViewDistance()}, after the distance the server allows
	 * changed (the memory manager limits it while the server is short of memory, and lifts the limit afterwards).
	 *
	 * @internal
	 */
	public function refreshViewDistance() : void{
		if($this->requestedViewDistance >= 0 && $this->server->getAllowedViewDistance($this->requestedViewDistance) !== $this->viewDistance){
			$this->setViewDistance($this->requestedViewDistance);
		}
	}

	public function setViewDistance(int $distance) : void{
		$this->requestedViewDistance = $distance;
		$newViewDistance = $this->server->getAllowedViewDistance($distance);

		if($newViewDistance !== $this->viewDistance){
			$ev = new PlayerViewDistanceChangeEvent($this, $this->viewDistance, $newViewDistance);
			$ev->call();
		}

		$this->viewDistance = $newViewDistance;

		$this->spawnThreshold = (int) (min($this->viewDistance, $this->server->getConfigGroup()->getPropertyInt(YmlServerProperties::CHUNK_SENDING_SPAWN_RADIUS, 4)) ** 2 * M_PI);

		$this->nextChunkOrderRun = 0;
		$this->chunkOrderDirty = true;

		$this->getNetworkSession()->syncViewAreaRadius($this->viewDistance);

		$this->logger->debug("Setting view distance to " . $this->viewDistance . " (requested " . $distance . ")");
	}

	public function isOnline() : bool{
		return $this->isConnected();
	}

	public function isConnected() : bool{
		return $this->networkSession !== null && $this->networkSession->isConnected();
	}

	public function getNetworkSession() : NetworkSession{
		if($this->networkSession === null){
			throw new \LogicException("Player is not connected");
		}
		return $this->networkSession;
	}

	/**
	 * Returns the network session while the player is connected, null otherwise (one call instead of isConnected() +
	 * getNetworkSession(): this runs for every viewer of every broadcast).
	 *
	 * @internal
	 */
	public function getConnectedNetworkSession() : ?NetworkSession{
		return $this->networkSession !== null && $this->networkSession->isConnected() ? $this->networkSession : null;
	}

	/**
	 * Network session for the message/title/form/toast senders: null once the player has been destroyed after it quit,
	 * so that a plugin which keeps a reference to it (a repeating scoreboard/HUD task, a delayed database callback...)
	 * makes these a no-op instead of throwing from getNetworkSession() on every call. While the player is
	 * disconnecting but not yet destroyed, the session itself already drops the packets.
	 */
	private function getSessionForMessage() : ?NetworkSession{
		if($this->networkSession === null){
			if(!$this->loggedMessageAfterQuit){
				$this->loggedMessageAfterQuit = true;
				$this->logger->debug("Ignoring messages, titles and forms sent after the player quit (a plugin still uses this Player object)");
			}
			return null;
		}
		return $this->networkSession;
	}

	/**
	 * Gets the username
	 */
	public function getName() : string{
		return $this->username;
	}

	/**
	 * Returns the "friendly" display name of this player to use in the chat.
	 */
	public function getDisplayName() : string{
		return $this->displayName;
	}

	public function setDisplayName(string $name) : void{
		$ev = new PlayerDisplayNameChangeEvent($this, $this->displayName, $name);
		$ev->call();

		$this->displayName = $ev->getNewName();
	}

	public function canBeRenamed() : bool{
		return false;
	}

	/**
	 * Returns the player's locale, e.g. en_US.
	 */
	public function getLocale() : string{
		return $this->locale;
	}

	/**
	 * Returns the language used to translate messages sent to this player. This is the player's game language if
	 * per-player languages are enabled and a translation exists for it, otherwise the server language.
	 */
	public function getLanguage() : Language{
		return $this->language;
	}

	/**
	 * Called when a player changes their skin.
	 * Plugin developers should not use this, use setSkin() and sendSkin() instead.
	 */
	public function changeSkin(Skin $skin, string $newSkinName, string $oldSkinName) : bool{
		$ev = new PlayerChangeSkinEvent($this, $this->getSkin(), $skin);
		$ev->call();

		if($ev->isCancelled()){
			$this->sendSkin([$this]);
			return true;
		}

		$this->setSkin($ev->getNewSkin());
		$this->sendSkin($this->server->getOnlinePlayers());
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * If null is given, will additionally send the skin to the player itself as well as its viewers.
	 */
	public function sendSkin(?array $targets = null) : void{
		parent::sendSkin($targets ?? $this->server->getOnlinePlayers());
	}

	/**
	 * Returns whether the player is currently using an item (right-click and hold).
	 */
	public function isUsingItem() : bool{
		return $this->startAction > -1;
	}

	public function setUsingItem(bool $value) : void{
		if(!$value && $this->startAction !== -1 && $this->spawned){
			//Java LivingEntity.stopUsingItem(): finishing or cancelling the use of an item (eating, a bow, a shield...)
			$this->getWorld()->addGameEvent($this->location, GameEvent::ITEM_INTERACT_FINISH, $this);
		}
		$this->startAction = $value ? $this->server->getTick() : -1;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns how long the player has been using their currently-held item for. Used for determining arrow shoot force
	 * for bows.
	 */
	public function getItemUseDuration() : int{
		return $this->startAction === -1 ? -1 : ($this->server->getTick() - $this->startAction);
	}

	/**
	 * Returns the server tick on which the player's cooldown period expires for the given item.
	 */
	public function getItemCooldownExpiry(Item $item) : int{
		$this->checkItemCooldowns();
		return $this->usedItemsCooldown[$item->getCooldownTag() ?? $item->getStateId()] ?? 0;
	}

	/**
	 * Returns whether the player has a cooldown period left before it can use the given item again.
	 */
	public function hasItemCooldown(Item $item) : bool{
		$this->checkItemCooldowns();
		return isset($this->usedItemsCooldown[$item->getCooldownTag() ?? $item->getStateId()]);
	}

	/**
	 * Resets the player's cooldown time for the given item back to the maximum.
	 */
	public function resetItemCooldown(Item $item, ?int $ticks = null) : void{
		$ticks = $ticks ?? $item->getCooldownTicks();
		if($ticks > 0){
			$this->usedItemsCooldown[$item->getCooldownTag() ?? $item->getStateId()] = $this->server->getTick() + $ticks;
			$this->getNetworkSession()->onItemCooldownChanged($item, $ticks);
		}
	}

	protected function checkItemCooldowns() : void{
		$serverTick = $this->server->getTick();
		foreach($this->usedItemsCooldown as $itemId => $cooldownUntil){
			if($cooldownUntil <= $serverTick){
				unset($this->usedItemsCooldown[$itemId]);
			}
		}
	}

	protected function setPosition(Vector3 $pos) : bool{
		$oldWorld = $this->location->isValid() ? $this->location->getWorld() : null;
		if(parent::setPosition($pos)){
			$newWorld = $this->getWorld();
			if($oldWorld !== $newWorld){
				if($oldWorld !== null){
					foreach($this->usedChunks as $index => $status){
						World::getXZ($index, $X, $Z);
						$this->unloadChunk($X, $Z, $oldWorld);
					}
				}

				$this->usedChunks = [];
				$this->loadQueue = [];
				$this->chunkOrderDirty = true;
				$this->getNetworkSession()->onEnterWorld();
			}

			return true;
		}

		return false;
	}

	protected function unloadChunk(int $x, int $z, ?World $world = null) : void{
		//the next chunk order must bring back a chunk unloaded while still in view (e.g. a forced unload)
		$this->chunkOrderDirty = true;
		$world = $world ?? $this->getWorld();
		$index = World::chunkHash($x, $z);
		if(isset($this->usedChunks[$index])){
			foreach($world->getChunkEntities($x, $z) as $entity){
				if($entity !== $this){
					$entity->despawnFrom($this);
				}
			}
			$this->getNetworkSession()->stopUsingChunk($x, $z);
			unset($this->usedChunks[$index]);
			unset($this->activeChunkGenerationRequests[$index]);
		}
		$world->unregisterChunkLoader($this->chunkLoader, $x, $z);
		$world->unregisterChunkListener($this, $x, $z);
		unset($this->loadQueue[$index]);
		$world->unregisterTickingChunk($this->chunkTicker, $x, $z);
		unset($this->tickingChunks[$index]);
		$world->unregisterSimulatingChunk($this->chunkTicker, $x, $z);
		unset($this->simulatingChunks[$index]);
	}

	protected function spawnEntitiesOnAllChunks() : void{
		foreach($this->usedChunks as $chunkHash => $status){
			if($status === UsedChunkStatus::SENT){
				World::getXZ($chunkHash, $chunkX, $chunkZ);
				$this->spawnEntitiesOnChunk($chunkX, $chunkZ);
			}
		}
	}

	protected function spawnEntitiesOnChunk(int $chunkX, int $chunkZ) : void{
		foreach($this->getWorld()->getChunkEntities($chunkX, $chunkZ) as $entity){
			if($entity !== $this && !$entity->isFlaggedForDespawn()){
				$entity->spawnTo($this);
			}
		}
	}

	/**
	 * Requests chunks from the world to be sent, up to a set limit every tick. This operates on the results of the most recent chunk
	 * order.
	 */
	protected function requestChunks() : void{
		if(!$this->isConnected()){
			return;
		}

		Timings::$playerChunkSend->startTiming();

		$count = 0;
		$world = $this->getWorld();

		$limit = $this->chunksPerTick - count($this->activeChunkGenerationRequests);
		//not spawned yet: the chunks the player waits for go out faster than per-tick while that is cheap, i.e. while
		//they are loaded and their packets encoded already (prepared during the login, see SpawnTerrainPrefetch),
		//within the time budget of the tick shared by the joining players
		$spawnStart = $spawnDeadline = null;
		if($this->fastSpawn && $this->spawnChunkLoadCount !== -1){
			$spawnStart = hrtime(true);
			$spawnDeadline = $spawnStart + SpawnChunkBudget::remaining($this->server->getTick());
		}
		foreach($this->loadQueue as $index => $distance){
			if($count >= $limit && ($spawnDeadline === null || count($this->usedChunks) > $this->spawnThreshold || hrtime(true) >= $spawnDeadline)){
				break;
			}

			$X = null;
			$Z = null;
			World::getXZ($index, $X, $Z);

			++$count;

			$this->usedChunks[$index] = UsedChunkStatus::REQUESTED_GENERATION;
			$this->activeChunkGenerationRequests[$index] = true;
			unset($this->loadQueue[$index]);
			$world->registerChunkLoader($this->chunkLoader, $X, $Z, true);
			$world->registerChunkListener($this, $X, $Z);
			if(isset($this->tickingChunks[$index])){
				$world->registerTickingChunk($this->chunkTicker, $X, $Z);
			}
			if(isset($this->simulatingChunks[$index])){
				$world->registerSimulatingChunk($this->chunkTicker, $X, $Z);
			}

			$world->requestChunkPopulation($X, $Z, $this->chunkLoader)->onCompletion(
				function() use ($X, $Z, $index, $world) : void{
					if(!$this->isConnected() || !isset($this->usedChunks[$index]) || $world !== $this->getWorld()){
						return;
					}
					if($this->usedChunks[$index] !== UsedChunkStatus::REQUESTED_GENERATION){
						//We may have previously requested this, decided we didn't want it, and then decided we did want
						//it again, all before the generation request got executed. In that case, the promise would have
						//multiple callbacks for this player. In that case, only the first one matters.
						return;
					}
					unset($this->activeChunkGenerationRequests[$index]);
					$this->usedChunks[$index] = UsedChunkStatus::REQUESTED_SENDING;

					$this->getNetworkSession()->startUsingChunk($X, $Z, function() use ($X, $Z, $index) : void{
						$this->usedChunks[$index] = UsedChunkStatus::SENT;
						if($this->spawnChunkLoadCount === -1){
							$this->spawnEntitiesOnChunk($X, $Z);
						}elseif($this->spawnChunkLoadCount++ === $this->spawnThreshold){
							$this->spawnChunkLoadCount = -1;

							$this->spawnEntitiesOnAllChunks();

							$this->getNetworkSession()->notifyTerrainReady();
						}
						(new PlayerPostChunkSendEvent($this, $X, $Z))->call();
					});
				},
				static function() : void{
					//NOOP: we'll re-request this if it fails anyway
				}
			);
		}
		if($spawnStart !== null){
			SpawnChunkBudget::use($this->server->getTick(), hrtime(true) - $spawnStart);
		}

		Timings::$playerChunkSend->stopTiming();
	}

	private function recheckBroadcastPermissions() : void{
		foreach([
			DefaultPermissionNames::BROADCAST_ADMIN => Server::BROADCAST_CHANNEL_ADMINISTRATIVE,
			DefaultPermissionNames::BROADCAST_USER => Server::BROADCAST_CHANNEL_USERS
		] as $permission => $channel){
			if($this->hasPermission($permission)){
				$this->server->subscribeToBroadcastChannel($channel, $this);
			}else{
				$this->server->unsubscribeFromBroadcastChannel($channel, $this);
			}
		}
	}

	/**
	 * Called by the network system when the pre-spawn sequence is completed (e.g. after sending spawn chunks).
	 * This fires join events and broadcasts join messages to other online players.
	 */
	public function doFirstSpawn() : void{
		if($this->spawned){
			return;
		}
		$this->spawned = true;
		$this->recheckBroadcastPermissions();
		$this->getPermissionRecalculationCallbacks()->add(function(array $changedPermissionsOldValues) : void{
			if(isset($changedPermissionsOldValues[Server::BROADCAST_CHANNEL_ADMINISTRATIVE]) || isset($changedPermissionsOldValues[Server::BROADCAST_CHANNEL_USERS])){
				$this->recheckBroadcastPermissions();
			}
		});

		$ev = new PlayerJoinEvent($this,
			KnownTranslationFactory::multiplayer_player_joined($this->getDisplayName())->prefix(TextFormat::YELLOW)
		);
		$ev->call();
		if($ev->getJoinMessage() !== ""){
			$this->server->broadcastMessage($ev->getJoinMessage());
		}

		$this->noDamageTicks = 60;

		$this->spawnToAll();
		if($this->isSpectator()){
			//the onGround sync of internalSetGameMode() was dropped when the game mode came from the saved data (no
			//session yet): without it a client joining in spectator thinks it stands on the ground, stops flying
			//(STOP_FLYING) and keeps toggling while it moves; the head of its spectator model flickers in third person
			$this->onGround = false;
			$this->sendPosition($this->location, null, null, MovePlayerPacket::MODE_TELEPORT);
		}
		$this->getWorld()->getWeather()->broadcast($this);
		$this->getRecipeBook()->sendInitial();

		if($this->getHealth() <= 0){
			$this->logger->debug("Quit while dead, forcing respawn");
			$this->actuallyRespawn();
		}
	}

	/**
	 * @param true[] $oldTickingChunks
	 * @param true[] $newTickingChunks
	 *
	 * @phpstan-param array<int, true> $oldTickingChunks
	 * @phpstan-param array<int, true> $newTickingChunks
	 */
	private function updateTickingChunkRegistrations(array $oldTickingChunks, array $newTickingChunks) : void{
		$world = $this->getWorld();
		foreach($oldTickingChunks as $hash => $_){
			if(!isset($newTickingChunks[$hash]) && !isset($this->loadQueue[$hash])){
				//we are (probably) still using this chunk, but it's no longer within ticking range
				World::getXZ($hash, $tickingChunkX, $tickingChunkZ);
				$world->unregisterTickingChunk($this->chunkTicker, $tickingChunkX, $tickingChunkZ);
			}
		}
		foreach($newTickingChunks as $hash => $_){
			if(!isset($oldTickingChunks[$hash]) && !isset($this->loadQueue[$hash])){
				//we were already using this chunk, but it is now within ticking range
				World::getXZ($hash, $tickingChunkX, $tickingChunkZ);
				$world->registerTickingChunk($this->chunkTicker, $tickingChunkX, $tickingChunkZ);
			}
		}
	}

	/**
	 * @param true[] $oldChunks
	 * @param true[] $newChunks
	 *
	 * @phpstan-param array<int, true> $oldChunks
	 * @phpstan-param array<int, true> $newChunks
	 */
	private function updateSimulatingChunkRegistrations(array $oldChunks, array $newChunks) : void{
		$world = $this->getWorld();
		foreach($oldChunks as $hash => $_){
			if(!isset($newChunks[$hash]) && !isset($this->loadQueue[$hash])){
				World::getXZ($hash, $chunkX, $chunkZ);
				$world->unregisterSimulatingChunk($this->chunkTicker, $chunkX, $chunkZ);
			}
		}
		foreach($newChunks as $hash => $_){
			if(!isset($oldChunks[$hash]) && !isset($this->loadQueue[$hash])){
				World::getXZ($hash, $chunkX, $chunkZ);
				$world->registerSimulatingChunk($this->chunkTicker, $chunkX, $chunkZ);
			}
		}
	}

	/**
	 * Calculates which new chunks this player needs to use, and which currently-used chunks it needs to stop using.
	 * This is based on factors including the player's current render radius and current position.
	 */
	protected function orderChunks() : void{
		if(!$this->isConnected() || $this->viewDistance === -1){
			return;
		}

		Timings::$playerChunkOrder->startTiming();

		$newOrder = [];
		$tickingChunks = [];
		$unloadChunks = $this->usedChunks;

		$world = $this->getWorld();
		$tickingChunkRadius = $world->getChunkTickRadius();
		$simulatingChunks = [];
		$simulationDistance = $world->getSimulationDistance();
		$viewRadius = $this->server->getAllowedViewDistance($this->viewDistance);
		$centerX = $this->location->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$centerZ = $this->location->getFloorZ() >> Chunk::COORD_BIT_SIZE;
		$selectedCount = 0;

		foreach($this->chunkSelector->selectChunks($viewRadius, $centerX, $centerZ) as $radius => $hash){
			if(!isset($this->usedChunks[$hash]) || $this->usedChunks[$hash] === UsedChunkStatus::NEEDED){
				$newOrder[$hash] = true;
			}
			if($radius < $tickingChunkRadius){
				$tickingChunks[$hash] = true;
			}
			if($radius < $simulationDistance){
				$simulatingChunks[$hash] = true;
			}
			unset($unloadChunks[$hash]);
			++$selectedCount;
		}

		foreach($unloadChunks as $index => $status){
			World::getXZ($index, $X, $Z);
			$this->unloadChunk($X, $Z);
		}

		$this->loadQueue = $newOrder;

		$this->updateTickingChunkRegistrations($this->tickingChunks, $tickingChunks);
		$this->tickingChunks = $tickingChunks;
		$this->updateSimulatingChunkRegistrations($this->simulatingChunks, $simulatingChunks);
		$this->simulatingChunks = $simulatingChunks;

		if(count($this->loadQueue) > 0 || count($unloadChunks) > 0){
			$this->getNetworkSession()->syncViewAreaCenterPoint($this->location, $this->viewDistance);
		}

		$this->lastChunkOrderWorldId = $world->getId();
		$this->lastChunkOrderCenterX = $centerX;
		$this->lastChunkOrderCenterZ = $centerZ;
		$this->lastChunkOrderRadius = $viewRadius;
		$this->lastChunkOrderTickRadius = $tickingChunkRadius;
		$this->lastChunkOrderSimulationDistance = $simulationDistance;
		$this->lastChunkOrderChunkCount = $selectedCount;
		$this->chunkOrderDirty = false;

		Timings::$playerChunkOrder->stopTiming();
	}

	/**
	 * Returns whether orderChunks() would do nothing if it ran now: no chunk to load, unload, start or stop ticking or
	 * simulating, and no view update to send. That is the case when the player is still in the world and chunk of the
	 * last order, with the same radii, nothing invalidated that order since (see $chunkOrderDirty), and every chunk it
	 * selected is requested or sent and not waiting to be sent again: no chunk is queued, the used chunks are exactly
	 * as many as the selected ones (they can only be selected ones), and none of them is NEEDED.
	 *
	 * Only for the core orderChunks(): a subclass overriding it (or isConnected() or getWorld(), which it calls) always
	 * gets its orders.
	 */
	private function isChunkOrderUpToDate() : bool{
		if($this->chunkOrderDirty || count($this->loadQueue) !== 0){
			return false;
		}
		if(!(self::$coreChunkOrderByClass[static::class] ??= self::usesCoreChunkOrder(static::class))){
			return false;
		}
		if(!$this->isConnected() || $this->viewDistance === -1){
			return false; //orderChunks() does nothing either
		}
		$world = $this->getWorld();
		return
			$world->getId() === $this->lastChunkOrderWorldId &&
			($this->location->getFloorX() >> Chunk::COORD_BIT_SIZE) === $this->lastChunkOrderCenterX &&
			($this->location->getFloorZ() >> Chunk::COORD_BIT_SIZE) === $this->lastChunkOrderCenterZ &&
			$world->getChunkTickRadius() === $this->lastChunkOrderTickRadius &&
			$world->getSimulationDistance() === $this->lastChunkOrderSimulationDistance &&
			$this->server->getAllowedViewDistance($this->viewDistance) === $this->lastChunkOrderRadius &&
			count($this->usedChunks) === $this->lastChunkOrderChunkCount &&
			!in_array(UsedChunkStatus::NEEDED, $this->usedChunks, true);
	}

	/**
	 * @phpstan-param class-string<Player> $class
	 */
	private static function usesCoreChunkOrder(string $class) : bool{
		return
			(new \ReflectionMethod($class, "orderChunks"))->getDeclaringClass()->getName() === self::class &&
			(new \ReflectionMethod($class, "isConnected"))->getDeclaringClass()->getName() === self::class &&
			(new \ReflectionMethod($class, "getWorld"))->getDeclaringClass()->getName() === Entity::class;
	}

	/**
	 * Called when the player moved horizontally: chunks are ordered again when a 20-tick countdown ends (unless an
	 * order is due sooner). That order is skipped if it would do nothing then (see doChunkRequests()).
	 */
	private function scheduleChunkOrderAfterMove() : void{
		if($this->nextChunkOrderRun > 20){
			$this->nextChunkOrderRun = 20;
			$this->chunkOrderMovementTimer = 20;
		}
	}

	/**
	 * Returns whether the player is using the chunk with the given coordinates, irrespective of whether the chunk has
	 * been sent yet.
	 */
	public function isUsingChunk(int $chunkX, int $chunkZ) : bool{
		return isset($this->usedChunks[World::chunkHash($chunkX, $chunkZ)]);
	}

	/**
	 * @return UsedChunkStatus[] chunkHash => status
	 * @phpstan-return array<int, UsedChunkStatus>
	 */
	public function getUsedChunks() : array{
		return $this->usedChunks;
	}

	/**
	 * Returns a usage status of the given chunk, or null if the player is not using the given chunk.
	 */
	public function getUsedChunkStatus(int $chunkX, int $chunkZ) : ?UsedChunkStatus{
		return $this->usedChunks[World::chunkHash($chunkX, $chunkZ)] ?? null;
	}

	/**
	 * Returns whether the target chunk has been sent to this player.
	 */
	public function hasReceivedChunk(int $chunkX, int $chunkZ) : bool{
		$status = $this->usedChunks[World::chunkHash($chunkX, $chunkZ)] ?? null;
		return $status === UsedChunkStatus::SENT;
	}

	/**
	 * Ticks the chunk-requesting mechanism.
	 */
	public function doChunkRequests() : void{
		if($this->nextChunkOrderRun !== PHP_INT_MAX){
			//whether the countdown is still the one armed by movement, which nothing else changed
			$movementTimer = $this->nextChunkOrderRun === $this->chunkOrderMovementTimer;
			if($this->nextChunkOrderRun-- <= 0){
				$this->nextChunkOrderRun = PHP_INT_MAX;
				$this->chunkOrderMovementTimer = null;
				//moving inside the same chunk does not change the order: skip it when it would do nothing
				if(!$movementTimer || !$this->isChunkOrderUpToDate()){
					$this->orderChunks();
				}
			}else{
				$this->chunkOrderMovementTimer = $movementTimer ? $this->nextChunkOrderRun : null;
			}
		}

		if(count($this->loadQueue) > 0){
			$this->requestChunks();
		}
	}

	public function getDeathPosition() : ?Position{
		if($this->deathPosition !== null && !$this->deathPosition->isValid()){
			$this->deathPosition = null;
		}
		return $this->deathPosition;
	}

	/**
	 * @param Vector3|Position|null $pos
	 */
	public function setDeathPosition(?Vector3 $pos) : void{
		if($pos !== null){
			if($pos instanceof Position && $pos->world !== null){
				$world = $pos->world;
			}else{
				$world = $this->getWorld();
			}
			$this->deathPosition = new Position($pos->x, $pos->y, $pos->z, $world);
		}else{
			$this->deathPosition = null;
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * @return Position
	 */
	public function getSpawn(){
		if($this->hasValidCustomSpawn()){
			return $this->spawnPosition;
		}else{
			$world = $this->server->getWorldManager()->getDefaultWorld();

			return $world->getSpawnLocation();
		}
	}

	public function hasValidCustomSpawn() : bool{
		return $this->spawnPosition !== null && $this->spawnPosition->isValid();
	}

	/**
	 * Sets the spawnpoint of the player (and the compass direction) to a Vector3, or set it on another world with a
	 * Position object
	 *
	 * @param Vector3|Position|null $pos
	 */
	public function setSpawn(?Vector3 $pos) : void{
		if($pos !== null){
			if(!($pos instanceof Position)){
				$world = $this->getWorld();
			}else{
				$world = $pos->getWorld();
			}
			$this->spawnPosition = new Position($pos->x, $pos->y, $pos->z, $world);
			//a bed or respawn anchor only stays the spawn point while it is there (checked on respawn)
			$this->spawnBlockType = $world->isInLoadedTerrain($pos) ?
				SpawnLocator::getSpawnBlockType($world->getBlockAt($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())) :
				SpawnLocator::SPAWN_BLOCK_NONE;
		}else{
			$this->spawnPosition = null;
			$this->spawnBlockType = SpawnLocator::SPAWN_BLOCK_NONE;
		}
		$this->getNetworkSession()->syncPlayerSpawnPoint($this->getSpawn());
	}

	/**
	 * Returns the number of ticks since the player last entered a bed or died (Bedrock "Time Since Last Rest").
	 * Phantoms spawn around players who have not rested for 3 days (72000 ticks).
	 */
	public function getTimeSinceRest() : int{
		return $this->timeSinceRest;
	}

	/**
	 * Returns the player's warning level towards wardens, raised by sculk shriekers (see
	 * {@link \pocketmine\block\SculkShrieker}).
	 */
	public function getWardenSpawnTracker() : WardenSpawnTracker{
		return $this->wardenSpawnTracker ??= new WardenSpawnTracker($this->server->getTick());
	}

	/**
	 * Whether the feet or the eyes are in water (unlocks the boat recipes).
	 */
	private function isTouchingWater() : bool{
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		return (self::blockFlagsAt($world, $x, (int) floor($this->location->y), $z) & self::BLOCK_FLAG_WATER) !== 0 || $this->isUnderwater();
	}

	/**
	 * Returns the recipes the player has unlocked in the recipe book.
	 */
	public function getRecipeBook() : RecipeBook{
		return $this->recipeBook ??= new RecipeBook($this);
	}

	public function setTimeSinceRest(int $ticks) : void{
		$this->timeSinceRest = max(0, $ticks);
	}

	/**
	 * Returns whether the player has seen the End Poem and credits, which play the first time they leave the End through
	 * its exit portal (see {@link \pocketmine\world\dimension\PortalTravel}).
	 */
	public function hasSeenCredits() : bool{
		return $this->seenCredits;
	}

	/**
	 * Sets whether the player has seen the End credits: false makes them play again on the next trip out of the End.
	 */
	public function setSeenCredits(bool $seen) : void{
		$this->seenCredits = $seen;
	}

	public function isSleeping() : bool{
		return $this->sleeping !== null;
	}

	/**
	 * Position of the bed the player is sleeping in, or null when awake.
	 */
	public function getSleepingPosition() : ?Vector3{
		return $this->sleeping !== null ? clone $this->sleeping : null;
	}

	public function sleepOn(Vector3 $pos) : bool{
		$pos = $pos->floor();
		$b = $this->getWorld()->getBlock($pos);

		$ev = new PlayerBedEnterEvent($this, $b);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		if($b instanceof Bed){
			$b->setOccupied();
			$this->getWorld()->setBlock($pos, $b);
		}

		$this->sleeping = $pos;
		$this->networkPropertiesDirty = true;
		$this->timeSinceRest = 0;

		//the sleeper lies in the middle of the bed (vanilla): BDS places the eyes 0.90625 above the bed block
		$this->lyingPosition = new Vector3($pos->x + 0.5, $pos->y + self::SLEEPING_EYE_HEIGHT - $this->getOffsetPosition(Vector3::zero())->y, $pos->z + 0.5);
		$this->moveInBed($this->lyingPosition);

		$this->setSpawn($pos);

		$this->getWorld()->setSleepTicks(60);

		return true;
	}

	/**
	 * Moves the player into or out of their bed, for themselves and their viewers.
	 */
	private function moveInBed(Vector3 $pos) : void{
		$this->setPosition($pos);
		$this->resetFallDistance();
		if($this->isConnected()){
			$this->sendPosition($this->location, $this->location->yaw, $this->location->pitch, MovePlayerPacket::MODE_RESET);
		}
		$this->broadcastMovement(true);
	}

	public function stopSleep() : void{
		if($this->sleeping instanceof Vector3){
			$b = $this->getWorld()->getBlock($this->sleeping);
			if($b instanceof Bed){
				$b->setOccupied(false);
				$this->getWorld()->setBlock($this->sleeping, $b);
			}
			(new PlayerBedLeaveEvent($this, $b))->call();

			$lying = $this->lyingPosition;
			$bedPos = $this->sleeping;
			$this->sleeping = null;
			$this->lyingPosition = null;
			$this->networkPropertiesDirty = true;

			//getting up next to the bed (vanilla), unless a teleport is taking the player elsewhere
			if($lying !== null){
				$standUp = $b instanceof Bed ? SpawnLocator::findBedStandUpPosition($this->getWorld(), $b, $this->location->yaw) : null;
				$this->moveInBed($standUp !== null ? $standUp->add(0.5, 0, 0.5) : new Vector3($lying->x, $bedPos->y + 0.5625, $lying->z));
			}

			//with playerssleepingpercentage below 100, the others may still be enough to skip the night
			$othersSleeping = false;
			foreach($this->getWorld()->getPlayers() as $p){
				if($p !== $this && $p->isSleeping()){
					$othersSleeping = true;
					break;
				}
			}
			$this->getWorld()->setSleepTicks($othersSleeping ? 60 : 0);

			$this->getNetworkSession()->sendDataPacket(AnimatePacket::create($this->getId(), AnimatePacket::ACTION_STOP_SLEEP));
		}
	}

	public function getGamemode() : GameMode{
		return $this->gamemode;
	}

	protected function internalSetGameMode(GameMode $gameMode) : void{
		$this->gamemode = $gameMode;

		$this->allowFlight = $this->gamemode === GameMode::CREATIVE;
		$this->hungerManager->setEnabled($this->isSurvival());

		if($this->isSpectator()){
			$this->setFlying(true);
			$this->setHasBlockCollision(false);
			$this->setSilent();
			$this->onGround = false;

			//TODO: HACK! this syncs the onground flag with the client so that flying works properly
			//this is a yucky hack but we don't have any other options :(
			$this->sendPosition($this->location, null, null, MovePlayerPacket::MODE_TELEPORT);
		}else{
			if($this->isSurvival()){
				$this->setFlying(false);
			}
			$this->setHasBlockCollision(true);
			$this->setSilent(false);
			$this->checkGroundState(0, 0, 0, 0, 0, 0);
		}
	}

	/**
	 * Sets the provided gamemode.
	 */
	public function setGamemode(GameMode $gm) : bool{
		if($this->gamemode === $gm){
			return false;
		}

		$ev = new PlayerGameModeChangeEvent($this, $gm);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		$this->internalSetGameMode($gm);

		if($this->isSpectator()){
			$this->despawnFromAll();
		}else{
			$this->spawnToAll();
		}

		$this->getNetworkSession()->syncGameMode($this->gamemode);
		return true;
	}

	/**
	 * NOTE: Because Survival and Adventure Mode share some similar behaviour, this method will also return true if the player is
	 * in Adventure Mode. Supply the $literal parameter as true to force a literal Survival Mode check.
	 *
	 * @param bool $literal whether a literal check should be performed
	 */
	public function isSurvival(bool $literal = false) : bool{
		return $this->gamemode === GameMode::SURVIVAL || (!$literal && $this->gamemode === GameMode::ADVENTURE);
	}

	/**
	 * NOTE: Because Creative and Spectator Mode share some similar behaviour, this method will also return true if the player is
	 * in Spectator Mode. Supply the $literal parameter as true to force a literal Creative Mode check.
	 *
	 * @param bool $literal whether a literal check should be performed
	 */
	public function isCreative(bool $literal = false) : bool{
		return $this->gamemode === GameMode::CREATIVE || (!$literal && $this->gamemode === GameMode::SPECTATOR);
	}

	/**
	 * NOTE: Because Adventure and Spectator Mode share some similar behaviour, this method will also return true if the player is
	 * in Spectator Mode. Supply the $literal parameter as true to force a literal Adventure Mode check.
	 *
	 * @param bool $literal whether a literal check should be performed
	 */
	public function isAdventure(bool $literal = false) : bool{
		return $this->gamemode === GameMode::ADVENTURE || (!$literal && $this->gamemode === GameMode::SPECTATOR);
	}

	public function isSpectator() : bool{
		return $this->gamemode === GameMode::SPECTATOR;
	}

	public function setSneakPressed(bool $sneakPressed) : void{
		$this->sneakPressed = $sneakPressed;
	}

	/**
	 * Returns whether the player is pressing the sneak key.
	 * The player may still be sneaking even if this is false due to gameplay mechanics (e.g. releasing sneak while in a 1.5 block high space).
	 */
	public function isSneakPressed() : bool{
		return $this->sneakPressed;
	}

	/**
	 * Applies state the client has already changed on its side (sneaking, sprinting, swimming, gliding, flying,
	 * crawling, from PlayerAuthInput) without echoing the resulting entity metadata back to this player: like BDS,
	 * only the viewers get it. Metadata that was already pending is sent to everyone first, as usual.
	 * Echoing it made the client re-apply its own appearance on every movement key (in spectator the translucent
	 * head flickered in third person).
	 *
	 * @param \Closure() : bool $apply returns false if the server rejected part of the client's state, which then
	 *                                 gets the full metadata to revert it
	 *
	 * @internal
	 */
	public function applyClientPredictedState(\Closure $apply) : bool{
		$pending = $this->getDirtyNetworkData();
		if(count($pending) > 0){
			$this->sendData(null, $pending);
			$this->getNetworkProperties()->clearDirtyProperties();
		}

		$before = $this->getNetworkProperties()->getAll();

		$accepted = $apply();

		$changed = $this->getDirtyNetworkData();
		if(count($changed) > 0){
			$viewers = $this->getViewers();
			if(count($viewers) > 0){
				$this->sendData($viewers, $changed);
			}
			$this->getNetworkProperties()->clearDirtyProperties();

			//anything else (e.g. changed by an event handler) still reaches the player itself
			$own = $changed;
			foreach(self::CLIENT_PREDICTED_FLAGS as $propertyId => $mask){
				$old = $before[$propertyId] ?? null;
				$oldValue = $old instanceof LongMetadataProperty ? $old->getValue() : 0; //not set yet: no flags
				$new = $own[$propertyId] ?? null;
				if($new instanceof LongMetadataProperty && (($oldValue ^ $new->getValue()) & ~$mask) === 0){
					unset($own[$propertyId]);
				}
			}
			if(!isset($own[EntityMetadataProperties::SCALE])){
				//the posture (sneaking, swimming...) changed the size: the client sized itself
				unset($own[EntityMetadataProperties::BOUNDING_BOX_WIDTH], $own[EntityMetadataProperties::BOUNDING_BOX_HEIGHT]);
			}
			if(count($own) > 0){
				$this->sendData([$this], $own);
			}
		}
		if(!$accepted){
			$this->sendData([$this]);
		}
		return $accepted;
	}

	/**
	 * TODO: make this a dynamic ability instead of being hardcoded
	 */
	public function hasFiniteResources() : bool{
		return $this->gamemode !== GameMode::CREATIVE;
	}

	public function getDrops() : array{
		if($this->hasFiniteResources()){
			return parent::getDrops();
		}

		return [];
	}

	public function getXpDropAmount() : int{
		if($this->hasFiniteResources()){
			return parent::getXpDropAmount();
		}

		return 0;
	}

	protected function checkGroundState(float $wantedX, float $wantedY, float $wantedZ, float $dx, float $dy, float $dz) : void{
		if(!$this->blockCollision){
			$this->onGround = false;
		}else{
			$bb = clone $this->boundingBox;
			//while falling, only a surface right under the feet counts: the client sends exact positions when it lands,
			//and with the usual 0.2 margin a fall ended one tick early (no smash attack or critical hit in the last tick
			//before landing, as the client still sees itself in the air)
			$bb->minY = $this->location->y - ($dy < 0 ? self::LANDING_TOLERANCE : 0.2);
			$bb->maxY = $this->location->y + 0.2;

			//we're already at the new position at this point; check if there are blocks we might have landed on between
			//the old and new positions (running down stairs necessitates this)
			$bb = $bb->addCoord(-$dx, -$dy, -$dz);

			$this->onGround = $this->isCollided = $this->getWorld()->hasCollisionBlocks($bb) || $this->isStandingOnEntity($bb);
		}
	}

	/**
	 * Returns whether the given box (around the player's feet) touches the top of an entity players can stand on, like
	 * a happy ghast holding still. Player movement is client-side: the client stands on such entities, but the server
	 * only checks blocks when moving the player.
	 */
	private function isStandingOnEntity(AxisAlignedBB $feet) : bool{
		if(!$this->getWorld()->hasEntitiesThatMayBeStoodOn()){
			return false; //runs on every movement: most worlds have no boats or happy ghasts
		}
		foreach($this->getWorld()->getNearbyEntities($feet, $this) as $entity){
			if($entity->canBeStoodOn() && $entity !== $this->vehicle && $entity->getBoundingBox()->maxY <= $this->location->y + self::LANDING_TOLERANCE){
				return true;
			}
		}
		return false;
	}

	public function canBeMovedByCurrents() : bool{
		return false; //currently has no server-side movement
	}

	/**
	 * Sets the text of the interaction button shown to the player (touch controls), e.g. "action.interact.ride.horse",
	 * or "" for none. Updated when the client reports the entity it points at (InteractPacket MOUSEOVER).
	 */
	public function setInteractiveTag(string $tag) : void{
		if($tag !== $this->interactiveTag){
			$this->interactiveTag = $tag;
			$this->networkPropertiesDirty = true;
		}
	}

	public function getInteractiveTag() : string{
		return $this->interactiveTag;
	}

	protected function checkNearEntities() : void{
		$world = $this->getWorld();
		if(!$this->mayTouchReactiveEntity($world)){
			//the loop below would only find entities on which it does nothing (usually other players)
			return;
		}
		foreach($world->getNearbyEntities($this->boundingBox->expandedCopy(1, 0.5, 1), $this) as $entity){
			$entity->scheduleUpdate();

			if(!$entity->isAlive() || $entity->isFlaggedForDespawn()){
				continue;
			}

			$entity->onCollideWithPlayer($this);
		}
	}

	/**
	 * Returns whether checkNearEntities() may do something: whether, among the entities its getNearbyEntities() call
	 * would return, there is one on which scheduleUpdate(), isAlive(), isFlaggedForDespawn() and onCollideWithPlayer()
	 * do not all do nothing. They all do nothing for an entity whose class keeps the methods as Entity declares them
	 * (most mobs and players: only items, arrows and tridents react to being touched) and which is scheduled for updates
	 * already, in this world, and not closed.
	 *
	 * The box and chunk range are the ones of getNearbyEntities($this->boundingBox->expandedCopy(1, 0.5, 1), $this),
	 * with AxisAlignedBB::intersectsWith()'s comparisons, so no entity it returns is missed. Unlike it, this allocates
	 * nothing and calls no method per entity, and a crowd of players (no entity reacting) skips the scan.
	 */
	private function mayTouchReactiveEntity(World $world) : bool{
		$bb = $this->boundingBox;
		$minX = $bb->minX - 1.0;
		$minY = $bb->minY - 0.5;
		$minZ = $bb->minZ - 1.0;
		$maxX = $bb->maxX + 1.0;
		$maxY = $bb->maxY + 0.5;
		$maxZ = $bb->maxZ + 1.0;
		$minChunkX = ((int) floor($minX - 2)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkX = ((int) floor($maxX + 2)) >> Chunk::COORD_BIT_SIZE;
		$minChunkZ = ((int) floor($minZ - 2)) >> Chunk::COORD_BIT_SIZE;
		$maxChunkZ = ((int) floor($maxZ + 2)) >> Chunk::COORD_BIT_SIZE;

		$inertByClass = self::$touchInertByClass;
		$updateEntities = $world->updateEntities;
		for($x = $minChunkX; $x <= $maxChunkX; ++$x){
			for($z = $minChunkZ; $z <= $maxChunkZ; ++$z){
				foreach($world->getChunkEntities($x, $z) as $entity){
					$entityBB = $entity->boundingBox;
					if(
						$maxX - $entityBB->minX > 0.00001 && $entityBB->maxX - $minX > 0.00001 &&
						$maxZ - $entityBB->minZ > 0.00001 && $entityBB->maxZ - $minZ > 0.00001 &&
						$maxY - $entityBB->minY > 0.00001 && $entityBB->maxY - $minY > 0.00001 &&
						$entity !== $this &&
						!(
							($inertByClass[$entity::class] ?? self::isTouchInert($entity)) &&
							!$entity->closed &&
							$entity->location->world === $world &&
							($updateEntities[$entity->id] ?? null) === $entity
						)
					){
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Whether the entity's class keeps isAlive(), isFlaggedForDespawn(), onCollideWithPlayer() and getWorld() (which
	 * scheduleUpdate() calls) as Entity declares them.
	 */
	private static function isTouchInert(Entity $entity) : bool{
		$inert = true;
		foreach(["isAlive", "isFlaggedForDespawn", "onCollideWithPlayer", "getWorld"] as $method){
			if((new \ReflectionMethod($entity, $method))->getDeclaringClass()->getName() !== Entity::class){
				$inert = false;
				break;
			}
		}
		return self::$touchInertByClass[$entity::class] = $inert;
	}

	public function getInAirTicks() : int{
		return $this->inAirTicks;
	}

	/**
	 * Attempts to move the player to the given coordinates. Unless you have some particularly specialized logic, you
	 * probably want to use teleport() instead of this.
	 *
	 * This is used for processing movements sent by the player over network.
	 *
	 * @param Vector3 $newPos Coordinates of the player's feet, centered horizontally at the base of their bounding box.
	 */
	public function handleMovement(Vector3 $newPos) : void{
		Timings::$playerMove->startTiming();
		try{
			$this->actuallyHandleMovement($newPos);
		}finally{
			Timings::$playerMove->stopTiming();
		}
	}

	private function actuallyHandleMovement(Vector3 $newPos) : void{
		$this->moveRateLimit--;
		if($this->moveRateLimit < 0){
			return;
		}

		$oldPos = $this->location;
		$distanceSquared = $newPos->distanceSquared($oldPos);

		if($this->lyingPosition !== null){
			//a sleeping player stays in the bed: BDS corrects the client back to it
			if($distanceSquared > 0.0001){
				$this->revertMovement($oldPos);
			}
			return;
		}

		$revert = false;

		if($distanceSquared > 225){ //15 blocks
			//TODO: this is probably too big if we process every movement
			/* !!! BEWARE YE WHO ENTER HERE !!!
			 *
			 * This is NOT an anti-cheat check. It is a safety check.
			 * Without it hackers can teleport with freedom on their own and cause lots of undesirable behaviour, like
			 * freezes, lag spikes and memory exhaustion due to sync chunk loading and collision checks across large distances.
			 * Not only that, but high-latency players can trigger such behaviour innocently.
			 *
			 * If you must tamper with this code, be aware that this can cause very nasty results. Do not waste our time
			 * asking for help if you suffer the consequences of messing with this.
			 */
			$this->logger->debug("Moved too fast (" . sqrt($distanceSquared) . " blocks in 1 movement), reverting movement");
			$this->logger->debug("Old position: " . $oldPos->asVector3() . ", new position: " . $newPos);
			$revert = true;
		}elseif(!$this->getWorld()->isInLoadedTerrain($newPos)){
			$revert = true;
			$this->nextChunkOrderRun = 0;
			$this->chunkOrderDirty = true;
		}

		if(!$revert && $distanceSquared !== 0.0){
			$dx = $newPos->x - $oldPos->x;
			$dy = $newPos->y - $oldPos->y;
			$dz = $newPos->z - $oldPos->z;

			$this->move($dx, $dy, $dz);
		}

		if($revert){
			$this->revertMovement($oldPos);
		}
	}

	/**
	 * Fires movement events and synchronizes player movement, every tick.
	 */
	protected function processMostRecentMovements() : void{
		$now = $this->server->getTickStartTime();
		$multiplier = $this->lastMovementProcess !== null ? ($now - $this->lastMovementProcess) * 20 : 1;
		$exceededRateLimit = $this->moveRateLimit < 0;
		$this->moveRateLimit = min(self::MOVE_BACKLOG_SIZE, max(0, $this->moveRateLimit) + self::MOVES_PER_TICK * $multiplier);
		$this->lastMovementProcess = $now;

		$from = clone $this->lastLocation;
		$to = clone $this->location;

		$delta = $to->distanceSquared($from);
		$deltaAngle = abs($this->lastLocation->yaw - $to->yaw) + abs($this->lastLocation->pitch - $to->pitch);

		if($delta > 0.0001 || $deltaAngle > 1.0){
			if(PlayerMoveEvent::hasHandlers()){
				$ev = new PlayerMoveEvent($this, $from, $to);

				$ev->call();

				if($ev->isCancelled()){
					if($this->vehicle !== null){
						//a riding player can't be held back without its vehicle: it gets off instead
						$this->dismount(true);
					}else{
						$this->revertMovement($from);
					}
					return;
				}

				if($to->distanceSquared($ev->getTo()) > 0.01){ //If plugins modify the destination
					$this->teleport($ev->getTo());
					return;
				}
			}

			$this->lastLocation = $to;
			$this->broadcastMovement();

			if($this->vehicle === null){ //riding doesn't make hungry or wear boots
				//the blocks touched are needed by checkBlockIntersections() later this tick anyway
				$inWater = false;
				foreach($this->getBlocksAroundWithEntityInsideActions() as $block){
					if($block instanceof Water){
						$inWater = true;
						break;
					}
				}
				if($inWater && !$this->wasInWater){
					//Java Entity.doWaterSplashEffect()
					$this->getWorld()->addGameEvent($this->location, GameEvent::SPLASH, $this);
				}
				$this->wasInWater = $inWater;
				if($this->hungerManager->isEnabled()){
					MovementCosts::exhaust(
						$this->hungerManager,
						$to->x - $from->x,
						$to->y - $from->y,
						$to->z - $from->z,
						$this->swimming || ($inWater && $this->isUnderwater()),
						$inWater,
						$this->isSprinting(),
						$this->onGround
					);
				}
				if(
					$this->onGround &&
					($this->soulSpeedLevel ??= $this->armorInventory->getBoots()->getEnchantmentLevel(VanillaEnchantments::SOUL_SPEED())) > 0 &&
					((int) floor($to->x) !== (int) floor($from->x) || (int) floor($to->y) !== (int) floor($from->y) || (int) floor($to->z) !== (int) floor($from->z)) &&
					$this->hasFiniteResources()
				){
					MovementCosts::wearSoulSpeedBoots($this);
				}
			}

			if((($from->x - $to->x) ** 2) + (($from->z - $to->z) ** 2) > 0){
				$this->scheduleChunkOrderAfterMove();
			}
		}

		if($this->movementBroadcastPending && $delta <= 0.0001 && $deltaAngle <= 1.0){
			$this->movementBroadcastPending = false;
			parent::broadcastMovement();
		}

		if($exceededRateLimit){ //client and server positions will be out of sync if this happens
			$this->logger->debug("Exceeded movement rate limit, forcing to last accepted position");
			$this->sendPosition($this->location, $this->location->getYaw(), $this->location->getPitch(), MovePlayerPacket::MODE_RESET);
		}
	}

	/**
	 * Sends this player's movement to viewers, less often to the ones far away (see player.movement-broadcast in
	 * pocketmine.yml): with N players in view every movement goes to every viewer every tick, which is N^2 packets.
	 * Teleports always go to everyone.
	 */
	protected function broadcastMovement(bool $teleport = false) : void{
		if($this->vehicle !== null){
			//viewers place a riding player on its seat themselves: only its head rotation is sent (vanilla)
			$this->movementBroadcastPending = false;
			NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [MovePlayerPacket::create(
				$this->id,
				$this->getOffsetPosition($this->location),
				$this->location->pitch,
				$this->location->yaw,
				$this->location->yaw,
				MovePlayerPacket::MODE_PITCH,
				false,
				$this->vehicle->getId(),
				0,
				0,
				0
			)]);
			return;
		}
		if($teleport || $this->movementFullRateDistanceSquared < 0){
			$this->movementBroadcastPending = false;
			parent::broadcastMovement($teleport);
			return;
		}
		$tick = $this->server->getTick();
		$skipped = false;
		$x = $this->location->x;
		$y = $this->location->y;
		$z = $this->location->z;
		$fullRateSquared = $this->movementFullRateDistanceSquared;
		$halfRateSquared = $this->movementHalfRateDistanceSquared;
		$viewers = $this->hasSpawned;
		$viewerSendIds = $this->viewerSendIds;
		$sendIds = [];
		$selector = NetworkBroadcastUtils::getNativeMovementSelector();
		if($selector !== null){
			//chunkutils2 >= 0.3.10 runs the loop below in C++ (PHP's arithmetic, ** 2 included): the same send ids, keyed
			//and ordered like the viewers, and the same skipped flag; -1 when it can't read the viewers as this loop
			//would (e.g. a class overriding getId()), then the loop runs
			$selected = $selector->selectMovement($viewers, $viewerSendIds, $x, $y, $z, $tick, $fullRateSquared, $halfRateSquared, $sendIds);
			if($selected >= 0){
				$this->movementBroadcastPending = $this->movementBroadcastPending || ($selected & 1) !== 0;
				if(count($sendIds) > 0){
					NetworkBroadcastUtils::broadcastPacketsToSendIds($viewers, $sendIds, [$this->createMovementPacket()]);
				}
				return;
			}
		}
		//the native send buffers only need the recipients' send ids (keyed like the viewers), the other paths the players
		$native = $selector !== null || NetworkBroadcastUtils::canAppendToNativeSendBuffers();
		$recipients = [];
		foreach($viewers as $key => $viewer){
			//inlined distanceSquared(): this loop runs for every viewer of every moving player. Keep ** 2 (the C
			//library's pow()): $d * $d is not always the same float (Windows: 23.69 ** 2 + 41.746663339720939 ** 2 is
			//above 2304, the products are exactly 2304), so a viewer one rounding step from a limit would get another rate
			$viewerLocation = $viewer->location;
			$distanceSquared = ($viewerLocation->x - $x) ** 2 + ($viewerLocation->y - $y) ** 2 + ($viewerLocation->z - $z) ** 2;
			//beyond the full rate distance every 2nd or 4th tick, offset by the viewer's ID so that the skipped ticks are
			//spread out instead of all landing on the same tick
			if($distanceSquared <= $fullRateSquared || (($tick + $viewer->getId()) % ($distanceSquared <= $halfRateSquared ? 2 : 4)) === 0){
				if($native){
					$sendIds[$key] = $viewerSendIds[$key] ?? 0;
				}else{
					$recipients[] = $viewer;
				}
			}else{
				$skipped = true;
			}
		}
		$this->movementBroadcastPending = $this->movementBroadcastPending || $skipped;
		if($native){
			if(count($sendIds) > 0){
				NetworkBroadcastUtils::broadcastPacketsToSendIds($viewers, $sendIds, [$this->createMovementPacket()]);
			}
		}elseif(count($recipients) > 0){
			NetworkBroadcastUtils::broadcastPackets($recipients, [$this->createMovementPacket()]);
		}
	}

	protected function revertMovement(Location $from) : void{
		$this->setPosition($from);
		$this->sendPosition($from, $from->yaw, $from->pitch, MovePlayerPacket::MODE_RESET);
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		//the client is placed 0.001 above the given position (see Human::getOffsetPosition()), so a fall that starts
		//after a teleport or a spawn measures 0.001 too much, which would round a whole-block fall up by 1 damage
		return $this->flying ? 0 : parent::calculateFallDamage($fallDistance - self::CLIENT_POSITION_FALL_TOLERANCE);
	}

	public function jump() : void{
		(new PlayerJumpEvent($this))->call();
		parent::jump();
		$this->dropShoulderPassengers();
	}

	/**
	 * The two shoulder seats of players (vanilla player.json minecraft:rideable: seat_count 2, family parrot_tame).
	 *
	 * @return RideableSeat[]
	 * @phpstan-return list<RideableSeat>
	 */
	public function getRideableSeats() : array{
		//looked up every tick for each parrot on a shoulder (its position)
		return self::$shoulderSeats ??= [
			RideableSeat::at(0.4, -0.2, -0.1),
			RideableSeat::at(-0.4, -0.2, -0.1)
		];
	}

	/**
	 * Only tamed parrots ride players, on their shoulders.
	 */
	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Parrot && $entity->isTamed();
	}

	/**
	 * The shoulder seats are relative to the network position of players (their eyes).
	 */
	public function getPassengerPosition(Entity $passenger) : Vector3{
		return parent::getPassengerPosition($passenger)->add(0, $this->getOffsetPosition(Vector3::zero())->y, 0);
	}

	/**
	 * Gets the parrots off the player's shoulders (jumping, getting hurt).
	 */
	private function dropShoulderPassengers() : void{
		foreach($this->passengers as $passenger){
			$passenger->dismount(true);
		}
	}

	public function setMotion(Vector3 $motion) : bool{
		if(parent::setMotion($motion)){
			$this->broadcastMotion();
			$this->getNetworkSession()->sendDataPacket(SetActorMotionPacket::create($this->id, $motion, tick: 0));

			return true;
		}
		return false;
	}

	protected function updateMovement(bool $teleport = false) : void{

	}

	protected function tryChangeMovement() : void{

	}

	public function onUpdate(int $currentTick) : bool{
		$tickDiff = $currentTick - $this->lastUpdate;

		if($tickDiff <= 0){
			return true;
		}

		$this->messageCounter = 2;

		$this->lastUpdate = $currentTick;

		if($this->justCreated){
			$this->onFirstUpdate($currentTick);
		}

		if(!$this->isAlive() && $this->spawned){
			$this->onDeathUpdate($tickDiff);
			return true;
		}

		$this->timings->startTiming();

		if($this->spawned){
			Timings::$playerMove->startTiming();
			$this->processMostRecentMovements();
			$this->motion = Vector3::zero(); //TODO: HACK! (Fixes player knockback being messed up)
			if($this->onGround){
				$this->inAirTicks = 0;
			}else{
				$this->inAirTicks += $tickDiff;
			}
			$position = $this->location->asVector3();
			$this->tickMovement = $this->previousTickPosition !== null ? $position->subtractVector($this->previousTickPosition)->divide($tickDiff) : Vector3::zero();
			$this->previousTickPosition = $position;
			if(count($this->passengers) > 0){
				$this->positionPassengers(); //parrots on the shoulders
			}
			Timings::$playerMove->stopTiming();

			Timings::$entityBaseTick->startTiming();
			$this->entityBaseTick($tickDiff);
			Timings::$entityBaseTick->stopTiming();

			if($this->sleeping === null && $this->isAlive()){
				$this->timeSinceRest += $tickDiff;
			}elseif($this->sleeping !== null && $this->ticksLived % 20 === 0 && !SleepRules::canSleepNow($this->getWorld())){
				$this->stopSleep(); //morning came (or the storm ended) without the night being skipped
			}
			if($this->ticksLived % 20 === 0 && $this->recipeBook !== null && $this->recipeBook->isWaitingForWater() && $this->isTouchingWater()){
				$this->recipeBook->onEnterWater(); //boat recipes
			}

			if($this->isAlive()){
				$this->tickGliding();
				$this->tickShield();
				if($this->spinAttackTicks > 0){
					$this->tickSpinAttack($tickDiff);
				}
			}

			if($this->isCreative() && $this->fireTicks > 1){
				$this->fireTicks = 1;
			}

			$item = $this->getInventory()->getItemInHand();
			if($item instanceof Chargeable && $this->isUsingItem()){
				$item->continueUsing($this, $this->getItemUseDuration());
			}elseif($item instanceof Spear && $this->isUsingItem() && $this->isAlive()){
				$this->tickSpearCharge($item);
			}

			if(!$this->isSpectator() && $this->isAlive()){
				Timings::$playerCheckNearEntities->startTiming();
				$this->checkNearEntities();
				Timings::$playerCheckNearEntities->stopTiming();
			}

			if($this->blockBreakHandler !== null && !$this->blockBreakHandler->update()){
				$this->blockBreakHandler = null;
			}

			if($this->isUsingItem() && $this->getItemUseDuration() % 4 === 0 && ($item = $this->inventory->getItemInHand()) instanceof ConsumableItem){
				$this->broadcastAnimation(new ConsumingItemAnimation($this, $item));
			}
		}

		$this->timings->stopTiming();

		return true;
	}

	public function canEat() : bool{
		return $this->isCreative() || parent::canEat();
	}

	public function canBreathe() : bool{
		return $this->isCreative() || parent::canBreathe();
	}

	/**
	 * The point of an entity's bounding box nearest to the player's eyes: reach is measured to it, so big entities
	 * (the ender dragon) can be hit away from their centre.
	 */
	private function closestPointOf(Entity $entity) : Vector3{
		$eye = $this->getEyePos();
		$bb = $entity->getBoundingBox();
		return new Vector3(
			max($bb->minX, min($eye->x, $bb->maxX)),
			max($bb->minY, min($eye->y, $bb->maxY)),
			max($bb->minZ, min($eye->z, $bb->maxZ))
		);
	}

	/**
	 * Returns whether the player can interact with the specified position. This checks distance and direction.
	 *
	 * @param float $maxDiff defaults to half of the 3D diagonal width of a block
	 */
	public function canInteract(Vector3 $pos, float $maxDistance, float $maxDiff = M_SQRT3 / 2) : bool{
		$eyePos = $this->getEyePos();
		if($eyePos->distanceSquared($pos) > $maxDistance ** 2){
			return false;
		}

		$dV = $this->getDirectionVector();
		$eyeDot = $dV->dot($eyePos);
		$targetDot = $dV->dot($pos);
		return ($targetDot - $eyeDot) >= -$maxDiff;
	}

	/**
	 * Sends a chat message as this player. If the message begins with a / (forward-slash) it will be treated
	 * as a command.
	 */
	public function chat(string $message) : bool{
		$this->removeCurrentWindow();

		if($this->messageCounter <= 0){
			//the check below would take care of this (0 * (maxlen + 1) = 0), but it's better be explicit
			return false;
		}

		//Fast length check, to make sure we don't get hung trying to explode MBs of string ...
		$maxTotalLength = $this->messageCounter * (self::MAX_CHAT_BYTE_LENGTH + 1);
		if(strlen($message) > $maxTotalLength){
			return false;
		}

		$message = TextFormat::clean($message, false);
		foreach(explode("\n", $message, $this->messageCounter + 1) as $messagePart){
			if(trim($messagePart) !== "" && strlen($messagePart) <= self::MAX_CHAT_BYTE_LENGTH && mb_strlen($messagePart, 'UTF-8') <= self::MAX_CHAT_CHAR_LENGTH && $this->messageCounter-- > 0){
				if(str_starts_with($messagePart, './')){
					$messagePart = substr($messagePart, 1);
				}

				if(str_starts_with($messagePart, "/")){
					Timings::$playerCommand->startTiming();
					$this->server->dispatchCommand($this, substr($messagePart, 1));
					Timings::$playerCommand->stopTiming();
				}else{
					$ev = new PlayerChatEvent($this, $messagePart, $this->server->getBroadcastChannelSubscribers(Server::BROADCAST_CHANNEL_USERS), new StandardChatFormatter());
					$ev->call();
					if(!$ev->isCancelled()){
						$this->server->broadcastMessage($ev->getFormatter()->format($ev->getPlayer()->getDisplayName(), $ev->getMessage()), $ev->getRecipients());
					}
				}
			}
		}

		return true;
	}

	public function selectHotbarSlot(int $hotbarSlot) : bool{
		if(!$this->inventory->isHotbarSlot($hotbarSlot)){ //TODO: exception here?
			return false;
		}
		if($hotbarSlot === $this->inventory->getHeldItemIndex()){
			return true;
		}

		$ev = new PlayerItemHeldEvent($this, $this->inventory->getItem($hotbarSlot), $hotbarSlot);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		$this->inventory->setHeldItemIndex($hotbarSlot);
		$this->setUsingItem(false);

		return true;
	}

	/**
	 * @param Item[] $extraReturnedItems
	 */
	private function returnItemsFromAction(Item $oldHeldItem, Item $newHeldItem, array $extraReturnedItems) : void{
		$heldItemChanged = false;

		if(!$newHeldItem->equalsExact($oldHeldItem) && $oldHeldItem->equalsExact($this->inventory->getItemInHand())){
			//determine if the item was changed in some meaningful way, or just damaged/changed count
			//if it was really changed we always need to set it, whether we have finite resources or not
			$newReplica = clone $oldHeldItem;
			$newReplica->setCount($newHeldItem->getCount());
			if($newReplica instanceof Durable && $newHeldItem instanceof Durable){
				$newDamage = $newHeldItem->getDamage();
				if($newDamage >= 0 && $newDamage <= $newReplica->getMaxDurability()){
					$newReplica->setDamage($newDamage);
				}
			}
			$damagedOrDeducted = $newReplica->equalsExact($newHeldItem);

			if(!$damagedOrDeducted || $this->hasFiniteResources()){
				if($newHeldItem instanceof Durable && $newHeldItem->isBroken()){
					$this->broadcastSound(new ItemBreakSound());
				}
				$this->inventory->setItemInHand($newHeldItem);
				$heldItemChanged = true;
			}
		}

		if(!$heldItemChanged){
			$newHeldItem = $oldHeldItem;
		}

		if($heldItemChanged && count($extraReturnedItems) > 0 && $newHeldItem->isNull()){
			$this->inventory->setItemInHand(array_shift($extraReturnedItems));
		}
		foreach($this->inventory->addItem(...$extraReturnedItems) as $drop){
			//TODO: we can't generate a transaction for this since the items aren't coming from an inventory :(
			$ev = new PlayerDropItemEvent($this, $drop);
			if($this->isSpectator()){
				$ev->cancel();
			}
			$ev->call();
			if(!$ev->isCancelled()){
				$this->dropItem($drop);
			}
		}
	}

	/**
	 * Activates the item in hand, for example throwing a projectile.
	 *
	 * @return bool if it did something
	 */
	public function useHeldItem() : bool{
		$directionVector = $this->getDirectionVector();
		$item = $this->inventory->getItemInHand();
		$oldItem = clone $item;

		$ev = new PlayerItemUseEvent($this, $item, $directionVector);
		if($this->hasItemCooldown($item) || $this->isSpectator()){
			$ev->cancel();
		}

		$ev->call();

		if($ev->isCancelled()){
			return false;
		}

		if($this->vehicle instanceof Mob && $this->vehicle->handleRiderItemUse($this, $item)){
			return true; //boosting the vehicle (carrot or warped fungus on a stick)
		}

		$returnedItems = [];
		$result = $item->onClickAir($this, $directionVector, $returnedItems);
		if($result === ItemUseResult::FAIL){
			return false;
		}

		$this->resetItemCooldown($oldItem);
		$this->returnItemsFromAction($oldItem, $item, $returnedItems);

		$this->setUsingItem(
			$item instanceof Releasable &&
			//a Chargeable (crossbow) that just fired must not start charging again in the same click
			(!$item instanceof Chargeable || $result === ItemUseResult::NONE) &&
			$item->canStartUsingItem($this)
		);

		return true;
	}

	/**
	 * Consumes the currently-held item.
	 *
	 * @return bool if the consumption succeeded.
	 */
	public function consumeHeldItem() : bool{
		$slot = $this->inventory->getItemInHand();
		if($slot instanceof ConsumableItem){
			$oldItem = clone $slot;

			$residue = $slot->getResidue();
			$ev = new PlayerItemConsumeEvent($this, $slot, $residue->isNull() ? [] : [$residue]);
			if($this->hasItemCooldown($slot)){
				$ev->cancel();
			}
			$ev->call();

			if($ev->isCancelled() || !$this->consumeObject($slot)){
				return false;
			}

			$this->setUsingItem(false);
			$this->resetItemCooldown($oldItem);

			$slot->pop();
			$this->returnItemsFromAction($oldItem, $slot, $ev->getResidue());

			return true;
		}

		return false;
	}

	/**
	 * Releases the held item, for example to fire a bow. This should be preceded by a call to useHeldItem().
	 *
	 * @return bool if it did something.
	 */
	/**
	 * Called when the client reports that the Chargeable item it is using finished charging (e.g. a crossbow).
	 * Stops using the item and loads it if it was charged for long enough.
	 *
	 * @return bool whether the item is now charged
	 */
	public function completeChargingHeldItem() : bool{
		try{
			$item = $this->inventory->getItemInHand();
			if(!$this->isUsingItem() || !$item instanceof Chargeable){
				return false;
			}
			$oldItem = clone $item;
			if(!$item->onChargeComplete($this, $this->getItemUseDuration())){
				return false;
			}
			$this->returnItemsFromAction($oldItem, $item, []);
			$this->getNetworkSession()->onChargeItemComplete();
			return true;
		}finally{
			$this->setUsingItem(false);
		}
	}

	public function releaseHeldItem() : bool{
		try{
			$item = $this->inventory->getItemInHand();
			if(!$this->isUsingItem() || $this->hasItemCooldown($item)){
				return false;
			}
			if($item instanceof Chargeable){
				//BDS 1.26.60 also loads a crossbow released after a full charge, not only on the client's charge-complete use
				return $this->completeChargingHeldItem();
			}

			$oldItem = clone $item;

			$returnedItems = [];
			$result = $item->onReleaseUsing($this, $returnedItems);
			if($result === ItemUseResult::SUCCESS){
				$this->resetItemCooldown($oldItem);
				$this->returnItemsFromAction($oldItem, $item, $returnedItems);
				return true;
			}

			return false;
		}finally{
			$this->setUsingItem(false);
		}
	}

	public function pickBlock(Vector3 $pos, bool $addTileNBT) : bool{
		$block = $this->getWorld()->getBlock($pos);
		if($block instanceof UnknownBlock){
			return true;
		}

		$item = $block->getPickedItem($addTileNBT);

		$ev = new PlayerBlockPickEvent($this, $block, $item);
		$existingSlot = $this->inventory->first($item);
		if($existingSlot === -1 && $this->hasFiniteResources()){
			$ev->cancel();
		}
		$ev->call();

		if(!$ev->isCancelled()){
			$this->equipOrAddPickedItem($existingSlot, $item);
		}

		return true;
	}

	public function pickEntity(int $entityId) : bool{
		$entity = $this->getWorld()->getEntity($entityId);
		//TODO: HACK! We really shouldn't be keeping disconnected players (and generally flagged-for-despawn entities)
		//in the world's entity table, but changing that is too risky for a hotfix. This workaround will do for now.
		if($entity === null || $entity->isFlaggedForDespawn()){
			return true;
		}

		$item = $entity->getPickedItem();
		if($item === null){
			return true;
		}

		$ev = new PlayerEntityPickEvent($this, $entity, $item);
		$existingSlot = $this->inventory->first($item);
		if($existingSlot === -1 && ($this->hasFiniteResources() || $this->isSpectator())){
			$ev->cancel();
		}
		$ev->call();

		if(!$ev->isCancelled()){
			$this->equipOrAddPickedItem($existingSlot, $item);
		}

		return true;
	}

	private function equipOrAddPickedItem(int $existingSlot, Item $item) : void{
		if($existingSlot !== -1){
			if($existingSlot < $this->inventory->getHotbarSize()){
				$this->inventory->setHeldItemIndex($existingSlot);
			}else{
				$this->inventory->swap($this->inventory->getHeldItemIndex(), $existingSlot);
			}
		}else{
			$firstEmpty = $this->inventory->firstEmpty();
			if($firstEmpty === -1){ //full inventory
				$this->inventory->setItemInHand($item);
			}elseif($firstEmpty < $this->inventory->getHotbarSize()){
				$this->inventory->setItem($firstEmpty, $item);
				$this->inventory->setHeldItemIndex($firstEmpty);
			}else{
				$this->inventory->swap($this->inventory->getHeldItemIndex(), $firstEmpty);
				$this->inventory->setItemInHand($item);
			}
		}
	}

	/**
	 * Performs a left-click (attack) action on the block.
	 *
	 * @return bool if an action took place successfully
	 */
	public function attackBlock(Vector3 $pos, int $face) : bool{
		if($pos->distanceSquared($this->location) > 10000){
			return false; //TODO: maybe this should throw an exception instead?
		}

		$target = $this->getWorld()->getBlock($pos);

		$ev = new PlayerInteractEvent($this, $this->inventory->getItemInHand(), $target, null, $face, PlayerInteractEvent::LEFT_CLICK_BLOCK);
		if($this->isSpectator()){
			$ev->cancel();
		}
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());
		if($target->onAttack($this->inventory->getItemInHand(), $face, $this)){
			return true;
		}

		$block = $target->getSide($face);
		if($block->hasTypeTag(BlockTypeTags::FIRE)){
			$this->getWorld()->setBlock($block->getPosition(), VanillaBlocks::AIR());
			$this->getWorld()->addSound($block->getPosition()->add(0.5, 0.5, 0.5), new FireExtinguishSound());
			return true;
		}

		if(!$this->isCreative() && !$target->getBreakInfo()->breaksInstantly()){
			$this->blockBreakHandler = new SurvivalBlockBreakHandler($this, $pos, $target, $face, 16);
		}

		return true;
	}

	public function continueBreakBlock(Vector3 $pos, int $face) : void{
		if($this->blockBreakHandler !== null && $this->blockBreakHandler->getBlockPos()->distanceSquared($pos) < 0.0001){
			$this->blockBreakHandler->setTargetedFace($face);
		}
	}

	public function stopBreakBlock(Vector3 $pos) : void{
		if($this->blockBreakHandler !== null && $this->blockBreakHandler->getBlockPos()->distanceSquared($pos) < 0.0001){
			$this->blockBreakHandler = null;
		}
	}

	/**
	 * Breaks the block at the given position using the currently-held item.
	 *
	 * @return bool if the block was successfully broken, false if a rollback needs to take place.
	 */
	public function breakBlock(Vector3 $pos) : bool{
		$this->removeCurrentWindow();

		if($this->canInteract($pos->add(0.5, 0.5, 0.5), $this->isCreative() ? self::MAX_REACH_DISTANCE_CREATIVE : self::MAX_REACH_DISTANCE_SURVIVAL)){
			$this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());
			$this->stopBreakBlock($pos);
			$item = $this->inventory->getItemInHand();
			$oldItem = clone $item;
			$returnedItems = [];
			if($this->getWorld()->useBreakOn($pos, $item, $this, true, $returnedItems)){
				$this->returnItemsFromAction($oldItem, $item, $returnedItems);
				$this->hungerManager->exhaust(0.005, PlayerExhaustEvent::CAUSE_MINING);
				return true;
			}
		}else{
			$this->logger->debug("Cancelled block break at $pos due to not currently being interactable");
		}

		return false;
	}

	/**
	 * Touches the block at the given position with the currently-held item.
	 *
	 * @return bool if it did something
	 */
	public function interactBlock(Vector3 $pos, int $face, Vector3 $clickOffset) : bool{
		$this->setUsingItem(false);

		if($this->canInteract($pos->add(0.5, 0.5, 0.5), $this->isCreative() ? self::MAX_REACH_DISTANCE_CREATIVE : self::MAX_REACH_DISTANCE_SURVIVAL)){
			$this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());
			$item = $this->inventory->getItemInHand(); //this is a copy of the real item
			$oldItem = clone $item;
			$returnedItems = [];
			if($this->getWorld()->useItemOn($pos, $item, $face, $clickOffset, $this, true, $returnedItems)){
				$this->returnItemsFromAction($oldItem, $item, $returnedItems);
				return true;
			}
		}else{
			$this->logger->debug("Cancelled interaction of block at $pos due to not currently being interactable");
		}

		return false;
	}

	/**
	 * Recomputes the Strength and Weakness modifiers of a melee hit the Bedrock way: on the weapon damage with its
	 * enchantments and smash bonus, compounding per level (see {@link CombatRules::applyStrength()}). The event set
	 * them from the base damage alone.
	 */
	private function applyMeleeEffectModifiers(EntityDamageByEntityEvent $ev) : void{
		$strength = $this->effectManager->get(VanillaEffects::STRENGTH());
		$weakness = $this->effectManager->get(VanillaEffects::WEAKNESS());
		if($strength === null && $weakness === null){
			return;
		}
		$damage = $ev->getFinalDamage() - $ev->getModifier(EntityDamageEvent::MODIFIER_STRENGTH) - $ev->getModifier(EntityDamageEvent::MODIFIER_WEAKNESS);
		//order when both are active not checked on BDS
		if($strength !== null){
			$strengthened = CombatRules::applyStrength($damage, $strength->getEffectLevel());
			$ev->setModifier($strengthened - $damage, EntityDamageEvent::MODIFIER_STRENGTH);
			$damage = $strengthened;
		}
		if($weakness !== null){
			$ev->setModifier(CombatRules::applyWeakness($damage, $weakness->getEffectLevel()) - $damage, EntityDamageEvent::MODIFIER_WEAKNESS);
		}
	}

	/**
	 * Attacks the given entity with the currently-held item.
	 * TODO: move this up the class hierarchy
	 *
	 * @return bool if the entity was dealt damage
	 */
	public function attackEntity(Entity $entity) : bool{
		if(!$entity->isAlive()){
			return false;
		}
		if($entity instanceof ItemEntity || $entity instanceof Arrow){
			$this->logger->debug("Attempted to attack non-attackable entity " . get_class($entity));
			return false;
		}

		//attacking lowers a raised shield (Bedrock); it has to be raised again for the block delay
		$this->lowerShield();

		$jabbedSpear = $this->inventory->getItemInHand();
		$spearOnCooldown = false;
		if($jabbedSpear instanceof Spear){
			//2 ticks of tolerance for network jitter between the client's cooldown and ours
			$spearOnCooldown = $this->getItemCooldownExpiry($jabbedSpear) - $this->server->getTick() > 2;
			if(!$spearOnCooldown){
				$this->onSpearJab($jabbedSpear);
			}
		}

		$heldItem = $this->inventory->getItemInHand();
		$oldItem = clone $heldItem;

		$ev = new EntityDamageByEntityEvent($this, $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $heldItem->getAttackPoints());
		if(!$this->canInteract($this->closestPointOf($entity), self::MAX_REACH_DISTANCE_ENTITY_INTERACTION)){
			$this->logger->debug("Cancelled attack of entity " . $entity->getId() . " due to not currently being interactable");
			$ev->cancel();
		}elseif($this->isSpectator() || ($entity instanceof Player && !$this->server->getConfigGroup()->getConfigBool(ServerProperties::PVP))){
			$ev->cancel();
		}elseif($spearOnCooldown){
			$ev->cancel();
		}

		$meleeEnchantmentDamage = 0;
		/** @var EnchantmentInstance[] $meleeEnchantments */
		$meleeEnchantments = [];
		foreach($heldItem->getEnchantments() as $enchantment){
			$type = $enchantment->getType();
			if($type instanceof MeleeWeaponEnchantment && $type->isApplicableTo($entity)){
				$meleeEnchantmentDamage += $type->getDamageBonus($enchantment->getLevel());
				$meleeEnchantments[] = $enchantment;
			}
		}
		//Bedrock rounds the weapon damage plus the enchantment bonus down to a whole number (Sharpness V iron sword: 13)
		$baseDamage = $ev->getBaseDamage();
		$ev->setModifier(CombatRules::getMeleeWeaponDamage($baseDamage, $meleeEnchantmentDamage) - $baseDamage, EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS);

		$smashFallDistance = null;
		if($heldItem instanceof Mace){
			$ev->setArmorEffectivenessReduction($heldItem->getArmorEffectivenessReduction());
			if($heldItem->canSmashAttack($this)){
				//Bedrock adds the smash bonus before Strength and the critical hit multiply the damage (Java adds it after)
				$smashFallDistance = $this->fallDistance;
				$ev->setModifier(Mace::calculateSmashDamageBonus($smashFallDistance, $heldItem->getEnchantmentLevel(VanillaEnchantments::DENSITY())), EntityDamageEvent::MODIFIER_SMASH);
			}
		}

		$this->applyMeleeEffectModifiers($ev);

		//Bedrock allows critical hits while sprinting (Java doesn't), never while riding
		if(!$this->isFlying() && $this->fallDistance > 0 && !$this->effectManager->has(VanillaEffects::BLINDNESS()) && !$this->isUnderwater() && $this->getVehicle() === null){
			$ev->setModifier($ev->getFinalDamage() / 2, EntityDamageEvent::MODIFIER_CRITICAL);
		}

		//Bedrock: a mob killed by a Fire Aspect hit is already burning, so it drops cooked food
		$preIgnited = !$ev->isCancelled() && FireAspectIgnition::beforeHit($entity, $heldItem);

		$entity->attack($ev);
		$this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());

		$soundPos = $entity->getPosition()->add(0, $entity->size->getHeight() / 2, 0);
		if($ev->isCancelled()){
			FireAspectIgnition::revert($entity, $preIgnited);
			$this->getWorld()->addSound($soundPos, new EntityAttackNoDamageSound());
			return false;
		}
		$this->getWorld()->addSound($soundPos, new EntityAttackSound());

		if($ev->getModifier(EntityDamageEvent::MODIFIER_CRITICAL) > 0 && $entity instanceof Living){
			$entity->broadcastAnimation(new CriticalHitAnimation($entity));
		}
		if($ev->getModifier(EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS) > 0 && $entity instanceof Living){
			$entity->broadcastAnimation(new MagicHitAnimation($entity));
		}

		if($smashFallDistance !== null && $heldItem instanceof Mace){
			//a smash attack cancels the fall: no fall damage on landing
			$this->resetFallDistance();
			$this->getWorld()->addSound($soundPos, new MaceSmashSound($entity->isOnGround(), $smashFallDistance > Mace::HEAVY_SMASH_FALL_DISTANCE));
			$heldItem->onSmashAttack($this, $entity, $smashFallDistance);
		}

		$this->applyPostAttackKnockBack($entity, $ev, $meleeEnchantments);

		if($this->isAlive()){
			//reactive damage like thorns might cause us to be killed by attacking another mob, which
			//would mean we'd already have dropped the inventory by the time we reached here
			$returnedItems = [];
			$heldItem->onAttackEntity($entity, $returnedItems);
			$this->returnItemsFromAction($oldItem, $heldItem, $returnedItems);

			$this->hungerManager->exhaust(0.1, PlayerExhaustEvent::CAUSE_ATTACK);
		}

		return true;
	}

	/**
	 * Runs the held item's melee enchantment post-attack effects and the sprint-hit knockback.
	 * With player.knockback: vanilla, a sprint hit knocks back one level further, added to the weapon's Knockback in a
	 * single push (Java Player.attack), its lift capped at 0.4 like the base push. Only for hits whose knockback no
	 * plugin changed (EntityDamageByEntityEvent::setKnockBack(), setVerticalKnockBackLimit()): the plugin's values are
	 * applied as they are, like with player.knockback: pmmp (PocketMine-MP 5.44.3, no sprint push).
	 *
	 * @param EnchantmentInstance[] $meleeEnchantments
	 */
	private function applyPostAttackKnockBack(Entity $entity, EntityDamageByEntityEvent $ev, array $meleeEnchantments) : void{
		$sprintForce = 0.0;
		//player.knockback: pmmp (PocketMine-MP 5.44.3) has no sprint push; a knockback a plugin set is applied as it is
		if(Living::hasVanillaKnockback() && $this->isSprinting() && $entity instanceof Living && Living::isDefaultKnockback($ev)){
			$sprintForce = 0.5;
		}
		foreach($meleeEnchantments as $enchantment){
			$type = $enchantment->getType();
			assert($type instanceof MeleeWeaponEnchantment);
			if($sprintForce > 0 && $type instanceof KnockbackEnchantment && $entity instanceof Living){
				//one push for both levels: two knockBack() calls would halve the first push
				$this->pushAway($entity, $enchantment->getLevel() * 0.5 + $sprintForce, $ev->getVerticalKnockBackLimit());
				$sprintForce = 0.0;
				continue;
			}
			$type->onPostAttack($this, $entity, $enchantment->getLevel());
		}
		if($sprintForce > 0 && $entity instanceof Living){
			$this->pushAway($entity, $sprintForce, $ev->getVerticalKnockBackLimit());
		}
	}

	private function pushAway(Living $entity, float $force, float $verticalLimit) : void{
		$diff = $entity->getPosition()->subtractVector($this->location);
		$entity->knockBack($diff->x, $diff->z, $force, $verticalLimit);
	}

	/**
	 * Performs actions associated with the attack action (left-click) without a target entity.
	 * Under normal circumstances, this will just play the no-damage attack sound and the arm-swing animation.
	 */
	public function missSwing() : void{
		$ev = new PlayerMissSwingEvent($this);
		$ev->call();
		if(!$ev->isCancelled()){
			$item = $this->inventory->getItemInHand();
			if($item instanceof Spear && !$this->hasItemCooldown($item)){
				$this->onSpearJab($item);
			}
			$this->broadcastSound(new EntityAttackNoDamageSound());
			$this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());
		}
	}

	/**
	 * Interacts with the given entity using the currently-held item.
	 */
	public function interactEntity(Entity $entity, Vector3 $clickPos) : bool{
		$ev = new PlayerEntityInteractEvent($this, $entity, $clickPos);

		if(!$this->canInteract($this->closestPointOf($entity), self::MAX_REACH_DISTANCE_ENTITY_INTERACTION)){
			$this->logger->debug("Cancelled interaction with entity " . $entity->getId() . " due to not currently being interactable");
			$ev->cancel();
		}

		$ev->call();

		$item = $this->inventory->getItemInHand();
		$oldItem = clone $item;
		if(!$ev->isCancelled()){
			if($item->onInteractEntity($this, $entity, $clickPos)){
				if($this->hasFiniteResources() && !$item->equalsExact($oldItem) && $oldItem->equalsExact($this->inventory->getItemInHand())){
					if($item instanceof Durable && $item->isBroken()){
						$this->broadcastSound(new ItemBreakSound());
					}
					$this->inventory->setItemInHand($item);
				}
				//the item used up the interaction (e.g. a name tag naming a villager): like vanilla, the entity's own
				//interaction (opening the trade screen...) doesn't happen as well
				return true;
			}
			return $entity->onInteract($this, $clickPos);
		}
		return false;
	}

	public function toggleSprint(bool $sprint) : bool{
		if($sprint === $this->sprinting){
			return true;
		}
		$ev = new PlayerToggleSprintEvent($this, $sprint);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->setSprinting($sprint);
		return true;
	}

	public function toggleSneak(bool $sneak, bool $sneakPressed = true) : bool{
		if($sneak === $this->sneaking && $sneakPressed === $this->sneakPressed){
			return true;
		}
		$this->setSneakPressed($sneakPressed);

		$ev = new PlayerToggleSneakEvent($this, $sneak, $sneakPressed);
		if($sneak === $this->sneaking){
			$ev->cancel();
		}
		$ev->call();

		if($sneak === $this->sneaking){
			//only the sneak key changed (e.g. flying down): the sneaking state is unchanged, there is nothing for the
			//client to revert
			return true;
		}
		if($ev->isCancelled()){
			return false;
		}
		$this->setSneaking($sneak);
		return true;
	}

	public function toggleFlight(bool $fly) : bool{
		if($fly === $this->flying){
			return true;
		}
		$ev = new PlayerToggleFlightEvent($this, $fly);
		if(!$this->allowFlight){
			$ev->cancel();
		}
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->setFlying($fly);
		return true;
	}

	public function toggleGlide(bool $glide) : bool{
		if($glide === $this->gliding){
			return true;
		}
		$ev = new PlayerToggleGlideEvent($this, $glide);
		if($glide && !$this->hasUsableElytra()){
			$ev->cancel();
		}
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->setGliding($glide);
		return true;
	}

	/**
	 * Returns whether the player wears an elytra in the chest slot that can be used to glide (more than 1 durability
	 * left).
	 */
	public function hasUsableElytra() : bool{
		$chestplate = $this->armorInventory->getChestplate();
		return $chestplate instanceof Elytra && $chestplate->canGlide();
	}

	/**
	 * Starts a Riptide spin attack: for the given number of ticks, the first living entity the player touches is hurt
	 * (8 damage plus the trident's melee enchantments) and the spin ends. The launch itself is client-side motion.
	 */
	public function startSpinAttack(Item $trident, int $ticks) : void{
		$this->spinAttackTicks = $ticks;
		$this->spinAttackItem = clone $trident;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Ends the spin attack. With $checkContact (the client ended its spin, usually because it hit something), a mob
	 * touching the player (with some tolerance for latency) is hit first.
	 */
	public function stopSpinAttack(bool $checkContact = false) : void{
		if($checkContact && $this->spinAttackTicks > 0 && $this->isAlive()){
			$this->checkSpinAttackContact($this->boundingBox->expandedCopy(1, 1, 1));
		}
		if($this->spinAttackTicks > 0 || $this->spinAttackItem !== null){
			$this->spinAttackTicks = 0;
			$this->spinAttackItem = null;
			$this->networkPropertiesDirty = true;
		}
	}

	public function isSpinAttacking() : bool{
		return $this->spinAttackTicks > 0;
	}

	/**
	 * Java LivingEntity.checkAutoSpinAttack(): one box query over this tick's movement while spinning.
	 */
	private function tickSpinAttack(int $tickDiff) : void{
		$this->spinAttackTicks -= $tickDiff;
		$movement = $this->tickMovement;
		$this->checkSpinAttackContact($this->boundingBox->addCoord(-$movement->x, -$movement->y, -$movement->z));
		if($this->spinAttackTicks <= 0){
			$this->stopSpinAttack();
		}
	}

	private function checkSpinAttackContact(AxisAlignedBB $box) : void{
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if($entity instanceof Living && $entity->isAlive() && !($entity instanceof Player && $entity->isSpectator())){
				$this->spinAttackHit($entity);
				$this->spinAttackTicks = 0;
				break;
			}
		}
	}

	private function spinAttackHit(Living $entity) : void{
		$ev = new EntityDamageByEntityEvent($this, $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, self::SPIN_ATTACK_DAMAGE);
		if($this->isSpectator() || ($entity instanceof Player && !$this->server->getConfigGroup()->getConfigBool(ServerProperties::PVP))){
			$ev->cancel();
		}
		$enchantmentDamage = 0;
		foreach(($this->spinAttackItem?->getEnchantments() ?? []) as $enchantment){
			$type = $enchantment->getType();
			if($type instanceof MeleeWeaponEnchantment && $type->isApplicableTo($entity)){
				$enchantmentDamage += $type->getDamageBonus($enchantment->getLevel());
			}
		}
		$ev->setModifier($enchantmentDamage, EntityDamageEvent::MODIFIER_WEAPON_ENCHANTMENTS);

		$entity->attack($ev);
		$soundPos = $entity->getPosition()->add(0, $entity->size->getHeight() / 2, 0);
		if($ev->isCancelled()){
			$this->getWorld()->addSound($soundPos, new EntityAttackNoDamageSound());
			return;
		}
		$this->getWorld()->addSound($soundPos, new EntityAttackSound());
		if($enchantmentDamage > 0){
			$entity->broadcastAnimation(new MagicHitAnimation($entity));
		}
	}

	public function setGliding(bool $value = true) : void{
		parent::setGliding($value);
		$this->glideTicks = 0;
		$this->lastGlideHorizontalSpeed = 0.0;
		$this->lastGlideTickPosition = null;
	}

	/**
	 * Elytra upkeep, run every tick: 1 durability per second of gliding (vanilla), gliding stops when the elytra is
	 * removed or worn down to 1 durability, and flying into a wall hurts (kinetic energy).
	 */
	protected function tickGliding() : void{
		if(!$this->gliding){
			return;
		}
		$chestplate = $this->armorInventory->getChestplate();
		if(!$chestplate instanceof Elytra || !$chestplate->canGlide()){
			$this->toggleGlide(false);
			return;
		}

		if(++$this->glideTicks % 20 === 0 && $this->hasFiniteResources()){
			$oldChestplate = clone $chestplate;
			$chestplate->applyDamage(1);
			if(!$chestplate->equalsExact($oldChestplate)){
				$this->armorInventory->setChestplate($chestplate);
			}
			if(!$chestplate->canGlide()){
				$this->toggleGlide(false);
				return;
			}
		}
		if($this->glideTicks % 10 === 0){
			//Java LivingEntity.updateFallFlying(): the elytra_glide vibration every half second
			$this->getWorld()->addGameEvent($this->location, GameEvent::ELYTRA_GLIDE, $this);
		}

		$position = $this->location->asVector3();
		if($this->lastGlideTickPosition !== null){
			$dx = $position->x - $this->lastGlideTickPosition->x;
			$dz = $position->z - $this->lastGlideTickPosition->z;
			$horizontalSpeed = sqrt($dx ** 2 + $dz ** 2);
			$damage = self::calculateKineticDamage($this->lastGlideHorizontalSpeed, $horizontalSpeed);
			if($damage > 0 && $this->isTouchingWallAhead($dx, $dz)){
				$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FLY_INTO_WALL, $damage));
			}
			$this->lastGlideHorizontalSpeed = $horizontalSpeed;
		}
		$this->lastGlideTickPosition = $position;
	}

	/**
	 * Damage taken when a gliding entity loses horizontal speed by hitting a wall: (speed lost in blocks/tick) * 10 - 3
	 * (https://minecraft.wiki/w/Elytra, "Kinetic damage").
	 */
	public static function calculateKineticDamage(float $previousHorizontalSpeed, float $horizontalSpeed) : float{
		return max(0.0, ($previousHorizontalSpeed - $horizontalSpeed) * 10 - 3);
	}

	/**
	 * Returns whether there is a block right in front of the player in the given horizontal direction.
	 * Player movement is client-side, so we only see that the player stopped, not the collision itself.
	 */
	private function isTouchingWallAhead(float $dx, float $dz) : bool{
		$length = sqrt($dx ** 2 + $dz ** 2);
		if($length < 0.0001){
			//stopped dead: look along the facing direction instead
			$direction = $this->getDirectionVector();
			$dx = $direction->x;
			$dz = $direction->z;
			$length = sqrt($dx ** 2 + $dz ** 2);
			if($length < 0.0001){
				return false;
			}
		}
		$bb = $this->boundingBox->offsetCopy($dx / $length * 0.2, 0, $dz / $length * 0.2);
		return $this->getWorld()->hasCollisionBlocks($bb);
	}

	protected function updateFallState(float $distanceThisTick, bool $onGround) : ?float{
		if($this->flying){
			//flying down isn't falling: a fall is measured from where the player stops flying
			$this->fallDistance = 0.0;
			return null;
		}
		if($this->gliding && $distanceThisTick > -0.5){
			//gliding doesn't accumulate fall distance unless diving fast (vanilla LivingEntity.travel)
			$this->fallDistance = 1.0;
		}elseif($distanceThisTick < 0 && $this->effectManager->has(VanillaEffects::SLOW_FALLING())){
			//the effect also resets it every tick, but several movements can arrive between two ticks
			$this->fallDistance = 0.0;
		}
		return parent::updateFallState($distanceThisTick, $onGround);
	}

	/**
	 * Returns the shield the player would raise: the one in the main hand, or else the one in the off hand.
	 */
	public function getHeldShield() : ?Shield{
		$mainHand = $this->inventory->getItemInHand();
		if($mainHand instanceof Shield){
			return $mainHand;
		}
		$offHand = $this->offHandInventory->getItem(0);
		return $offHand instanceof Shield ? $offHand : null;
	}

	/**
	 * Returns whether the player is blocking with a raised shield.
	 */
	public function isBlocking() : bool{
		return $this->blocking;
	}

	/**
	 * Lowers the shield, if raised; it needs to be raised for Shield::BLOCK_DELAY_TICKS again to block.
	 */
	public function lowerShield() : void{
		$this->shieldRaisedTick = -1;
		if($this->blocking){
			$this->blocking = false;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * On Bedrock the shield is raised by sneaking with it in either hand (or by using it from the main hand) and
	 * blocks after a short delay.
	 */
	protected function tickShield() : void{
		$shield = $this->getHeldShield();
		$raised = $shield !== null &&
			!$this->isSpectator() &&
			!$this->hasItemCooldown($shield) &&
			($this->sneaking || ($this->isUsingItem() && $this->inventory->getItemInHand() instanceof Shield));
		if(!$raised){
			$this->lowerShield();
			return;
		}
		$currentTick = $this->server->getTick();
		if($this->shieldRaisedTick === -1){
			$this->shieldRaisedTick = $currentTick;
		}
		if(!$this->blocking && $currentTick - $this->shieldRaisedTick >= Shield::BLOCK_DELAY_TICKS){
			$this->blocking = true;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Returns whether the given damage would be stopped by the player's raised shield: melee attacks and projectiles
	 * coming from in front of the player, and explosions from any side.
	 */
	protected function canBlockWithShield(EntityDamageEvent $source) : bool{
		if(!$this->blocking){
			return false;
		}
		$sourcePosition = Shield::getBlockableAttackSource($source);
		if($sourcePosition === null){
			return false;
		}
		$cause = $source->getCause();
		if($cause === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION || $cause === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION){
			//Bedrock: a raised shield stops a blast even from behind (BDS 1.26.60, TNT 6 blocks behind the player: no
			//damage, the shield wears like facing it); Java only blocks blasts in front
			return true;
		}
		return Shield::isAttackFromFront($this->location->yaw, $this->location, $sourcePosition);
	}

	/**
	 * Applies the effects of a blocked attack: shield durability, sound, knocking back melee attackers and axes
	 * disabling the shield.
	 */
	protected function onShieldBlock(EntityDamageEvent $source) : void{
		$this->broadcastSound(new ShieldBlockSound());

		$inMainHand = $this->inventory->getItemInHand() instanceof Shield;
		$shield = $this->getHeldShield();
		if($shield === null){
			return;
		}
		$durabilityCost = Shield::getDurabilityCost($source->getBaseDamage());
		if($durabilityCost > 0 && $this->hasFiniteResources()){
			$shield->applyDamage($durabilityCost);
			if($shield->isBroken()){
				$this->broadcastSound(new ItemBreakSound());
			}
			if($inMainHand){
				$this->inventory->setItemInHand($shield);
			}else{
				$this->offHandInventory->setItem(0, $shield);
			}
		}

		$attacker = $source instanceof EntityDamageByEntityEvent ? $source->getDamager() : null;
		if($source->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK && $attacker instanceof Living){
			//on Bedrock, blocking a melee attack pushes the attacker back
			$attacker->knockBack($attacker->location->x - $this->location->x, $attacker->location->z - $this->location->z, 0.5);

			if($attacker instanceof Human && $attacker->getInventory()->getItemInHand() instanceof Axe && !$shield->isNull()){
				$this->resetItemCooldown($shield, Shield::AXE_DISABLE_TICKS);
				$this->lowerShield();
			}
		}
	}

	/**
	 * Returns how far the player moved during the last tick (blocks/tick), as reported by the client.
	 */
	public function getTickMovement() : Vector3{
		return $this->tickMovement;
	}

	/**
	 * Called when the player jabs with a spear (attack button, hitting an entity or not): starts the use cooldown
	 * shared by all spears and applies Lunge.
	 */
	protected function onSpearJab(Spear $spear) : void{
		if($this->isSpectator()){
			return;
		}
		$this->resetItemCooldown($spear, $spear->getJabCooldownTicks());

		$impulse = $spear->getLungeImpulse();
		if(
			$impulse <= 0 ||
			$this->gliding ||
			$this->isUnderwater() ||
			$this->getWorld()->getBlock($this->location) instanceof Water ||
			($this->hasFiniteResources() && $this->hungerManager->getFood() < Spear::LUNGE_MIN_FOOD)
		){
			return;
		}
		$level = $spear->getEnchantmentLevel(VanillaEnchantments::LUNGE());

		$direction = $this->getDirectionVector()->withComponents(null, 0, null);
		$length = $direction->length();
		if($length < 0.0001){
			return; //looking straight up or down: no horizontal direction
		}
		$push = $direction->divide($length)->multiply($impulse);
		$this->setMotion($this->tickMovement->add($push->x, 0, $push->z));
		$this->broadcastSound(new LungeSound($level));

		if($this->hasFiniteResources()){
			$this->hungerManager->exhaust(Spear::LUNGE_EXHAUSTION_PER_LEVEL * $level, PlayerExhaustEvent::CAUSE_CUSTOM);
			$oldSpear = clone $spear;
			$spear->applyDamage(1);
			$this->returnItemsFromAction($oldSpear, $spear, []);
		}
	}

	/**
	 * Spear charge attack, run every tick while the player holds "use" with a spear: once the activation delay has
	 * passed, hits the first entity the spear points at, dealing damage proportional to the relative speed.
	 */
	protected function tickSpearCharge(Spear $spear) : void{
		$stage = $spear->getChargeStage($this->getItemUseDuration());
		if($stage === Spear::STAGE_NONE){
			return;
		}
		if($stage === Spear::STAGE_ENDED){
			$this->setUsingItem(false);
			return;
		}
		$currentTick = $this->server->getTick();
		if($this->lastSpearChargeHitTick !== -1 && $currentTick - $this->lastSpearChargeHitTick < Spear::CHARGE_HIT_INTERVAL_TICKS){
			return;
		}
		if($this->isSpectator()){
			return;
		}

		$direction = $this->getDirectionVector();
		$target = $this->getSpearChargeTarget($direction);
		if($target === null || ($target instanceof Player && !$this->server->getConfigGroup()->getConfigBool(ServerProperties::PVP))){
			return;
		}
		$targetMovement = $target instanceof Player ? $target->getTickMovement() : $target->getMotion();
		$relativeSpeed = $this->tickMovement->subtractVector($targetMovement)->dot($direction) * 20;
		$damage = $spear->calculateChargeDamage($relativeSpeed);
		if($damage <= 0){
			return;
		}

		$ev = new EntityDamageByEntityEvent(
			$this,
			$target,
			EntityDamageEvent::CAUSE_ENTITY_ATTACK,
			$damage,
			[],
			Spear::canChargeKnockBack($stage, $relativeSpeed) ? Living::DEFAULT_KNOCKBACK_FORCE : 0.0
		);
		//Strength and Weakness don't affect charge damage
		foreach([EntityDamageEvent::MODIFIER_STRENGTH, EntityDamageEvent::MODIFIER_WEAKNESS] as $modifier){
			if($ev->isApplicable($modifier)){
				$ev->setModifier(0.0, $modifier);
			}
		}
		$this->lastSpearChargeHitTick = $currentTick;
		$target->attack($ev);
		if($ev->isCancelled()){
			return;
		}
		$this->broadcastSound(new SpearHitSound($spear->getTier()));
		if($stage === Spear::STAGE_ENGAGED && Spear::canChargeKnockBack($stage, $relativeSpeed)){
			//an engaged charge knocks riders off their mount
			$target->dismount(true);
		}

		if($this->hasFiniteResources()){
			$oldSpear = clone $spear;
			$spear->applyDamage(1);
			$this->returnItemsFromAction($oldSpear, $spear, []);
		}
	}

	/**
	 * Returns the closest living entity whose bounding box the spear (a ray from the eyes along $direction) touches.
	 */
	private function getSpearChargeTarget(Vector3 $direction) : ?Living{
		$start = $this->getEyePos();
		$end = $start->addVector($direction->multiply(Spear::CHARGE_REACH));
		$closest = null;
		$closestDistance = INF;
		foreach($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy(Spear::CHARGE_REACH, Spear::CHARGE_REACH, Spear::CHARGE_REACH), $this) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || ($entity instanceof Player && $entity->isSpectator()) || $entity === $this->vehicle || $entity->getVehicle() === $this){
				continue;
			}
			$hit = $entity->getBoundingBox()->expandedCopy(0.125, 0.125, 0.125)->calculateIntercept($start, $end);
			if($hit !== null && ($distance = $hit->getHitVector()->distanceSquared($start)) < $closestDistance){
				$closest = $entity;
				$closestDistance = $distance;
			}
		}
		return $closest;
	}

	public function toggleSwim(bool $swim) : bool{
		if($swim === $this->swimming){
			return true;
		}
		$ev = new PlayerToggleSwimEvent($this, $swim);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->setSwimming($swim);
		return true;
	}

	/**
	 * Starts or stops crawling (sent by the client when the player is forced into a gap 1 block high, or leaves it).
	 * Returns false if a plugin cancelled it.
	 */
	public function toggleCrawl(bool $crawl) : bool{
		if($crawl === $this->isCrawling()){
			return true;
		}
		$ev = new PlayerToggleCrawlEvent($this, $crawl);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->setCrawling($crawl);
		return true;
	}

	public function emote(string $emoteId) : void{
		$currentTick = $this->server->getTick();
		if($currentTick - $this->lastEmoteTick > 5){
			$this->lastEmoteTick = $currentTick;
			$event = new PlayerEmoteEvent($this, $emoteId);
			$event->call();
			if(!$event->isCancelled()){
				$emoteId = $event->getEmoteId();
				parent::emote($emoteId);
			}
		}
	}

	/**
	 * Drops an item on the ground in front of the player.
	 */
	public function dropItem(Item $item) : void{
		$this->broadcastAnimation(new ArmSwingAnimation($this), $this->getViewers());
		$this->getWorld()->dropItem($this->location->add(0, 1.3, 0), $item, $this->getDirectionVector()->multiply(0.4), 40);
	}

	/**
	 * Adds a title text to the user's screen, with an optional subtitle.
	 *
	 * @param int $fadeIn  Duration in ticks for fade-in. If -1 is given, client-sided defaults will be used.
	 * @param int $stay    Duration in ticks to stay on screen for
	 * @param int $fadeOut Duration in ticks for fade-out.
	 */
	public function sendTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) : void{
		$this->setTitleDuration($fadeIn, $stay, $fadeOut);
		if($subtitle !== ""){
			$this->sendSubTitle($subtitle);
		}
		$this->getSessionForMessage()?->onTitle($title);
	}

	/**
	 * Sets the subtitle message, without sending a title.
	 */
	public function sendSubTitle(string $subtitle) : void{
		$this->getSessionForMessage()?->onSubTitle($subtitle);
	}

	/**
	 * Adds small text to the user's screen.
	 */
	public function sendActionBarMessage(string $message) : void{
		$this->getSessionForMessage()?->onActionBar($message);
	}

	/**
	 * Removes the title from the client's screen.
	 */
	public function removeTitles() : void{
		$this->getSessionForMessage()?->onClearTitle();
	}

	/**
	 * Resets the title duration settings to defaults and removes any existing titles.
	 */
	public function resetTitles() : void{
		$this->getSessionForMessage()?->onResetTitleOptions();
	}

	/**
	 * Sets the title duration.
	 *
	 * @param int $fadeIn  Title fade-in time in ticks.
	 * @param int $stay    Title stay time in ticks.
	 * @param int $fadeOut Title fade-out time in ticks.
	 */
	public function setTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
		if($fadeIn >= 0 && $stay >= 0 && $fadeOut >= 0){
			$this->getSessionForMessage()?->onTitleDuration($fadeIn, $stay, $fadeOut);
		}
	}

	/**
	 * Sends a direct chat message to a player
	 */
	public function sendMessage(Translatable|string $message) : void{
		$this->getSessionForMessage()?->onChatMessage($message);
	}

	public function sendJukeboxPopup(Translatable|string $message) : void{
		$this->getSessionForMessage()?->onJukeboxPopup($message);
	}

	/**
	 * Sends a popup message to the player
	 *
	 * TODO: add translation type popups
	 */
	public function sendPopup(string $message) : void{
		$this->getSessionForMessage()?->onPopup($message);
	}

	public function sendTip(string $message) : void{
		$this->getSessionForMessage()?->onTip($message);
	}

	/**
	 * Sends a toast message to the player, or queue to send it if a toast message is already shown.
	 */
	public function sendToastNotification(string $title, string $body) : void{
		$this->getSessionForMessage()?->onToastNotification($title, $body);
	}

	/**
	 * Sends a Form to the player, or queue to send it if a form is already open.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function sendForm(Form $form) : void{
		$session = $this->getSessionForMessage();
		if($session === null){
			return;
		}
		$id = $this->formIdCounter++;
		if($session->onFormSent($id, $form)){
			$this->forms[$id] = $form;
		}
	}

	public function onFormSubmit(int $formId, mixed $responseData) : bool{
		if(!isset($this->forms[$formId])){
			$this->logger->debug("Got unexpected response for form $formId");
			return false;
		}

		try{
			$this->forms[$formId]->handleResponse($this, $responseData);
		}catch(FormValidationException $e){
			//the response comes from the client (and some clients send unexpected values, e.g. null for a closed
			//modal form, or no label entries in custom forms): one line without trace, at most every few seconds
			$now = microtime(true);
			if($now - $this->lastFormValidationLog >= self::FORM_VALIDATION_LOG_INTERVAL){
				$suppressed = $this->suppressedFormValidationLogs;
				$this->lastFormValidationLog = $now;
				$this->suppressedFormValidationLogs = 0;
				$this->logger->notice("Invalid response to form " . get_class($this->forms[$formId]) . ": " . $e->getMessage() . ($suppressed > 0 ? " ($suppressed more invalid form responses not logged)" : ""));
			}else{
				$this->suppressedFormValidationLogs++;
				$this->logger->debug("Invalid response to form " . get_class($this->forms[$formId]) . ": " . $e->getMessage());
			}
		}catch(\Throwable $e){
			//forms are implemented by plugins; rethrown if no plugin is to blame
			PluginFaultHandler::isolate($e, null, "response handler of form " . get_class($this->forms[$formId]), $this->forms[$formId]);
		}finally{
			unset($this->forms[$formId]);
		}

		return true;
	}

	/**
	 * @internal
	 * Returns whether the server is waiting for a response for a form with the given ID.
	 */
	public function hasPendingForm(int $formId) : bool{
		return isset($this->forms[$formId]);
	}

	/**
	 * Closes the current viewing form and forms in queue.
	 */
	public function closeAllForms() : void{
		$this->getSessionForMessage()?->onCloseAllForms();
	}

	/**
	 * Transfers a player to another server.
	 *
	 * The client closes this connection and joins the destination like a server added to its list. Bedrock clients
	 * since 1.26.60 only connect over NetherNet and refuse RakNet, so the destination must accept NetherNet: VanillaMine
	 * 5.48+, BDS with transport=nethernet, or a proxy that speaks NetherNet. NetherNet signaling uses TCP $port (and
	 * the game data UDP), so both must be open there; a RakNet-only destination (older PocketMine-MP, RakNet proxies)
	 * makes the client fail with a connection error instead of joining. iPhone/iPad clients only use HTTPS signaling
	 * with a certificate they trust (network.nethernet.https on a VanillaMine destination). Behind pm-proxy, pass
	 * the name of a network server or group instead to move the player without reconnecting.
	 *
	 * @param string                   $address The IP address or hostname of the destination server
	 * @param int                      $port    The destination port, defaults to 19132
	 * @param Translatable|string|null $message Message to show in the console when closing the player, null will use the default message
	 *
	 * @return bool if transfer was successful.
	 */
	public function transfer(string $address, int $port = 19132, Translatable|string|null $message = null) : bool{
		if($this->getSessionForMessage() === null){
			return false;
		}
		$ev = new PlayerTransferEvent($this, $address, $port, $message ?? KnownTranslationFactory::pocketmine_disconnect_transfer());
		$ev->call();
		if(!$ev->isCancelled()){
			//behind pm-proxy, the name of a server or group of the network moves the player there without reconnecting
			$proxy = $this->server->getProxyNetwork();
			if($proxy !== null && $proxy->isNetworkTarget($ev->getAddress())){
				return $proxy->transfer($this, $ev->getAddress());
			}
			$this->getNetworkSession()->transfer($ev->getAddress(), $ev->getPort(), $ev->getMessage());
			return true;
		}

		return false;
	}

	/**
	 * Kicks a player from the server
	 *
	 * @param Translatable|string      $reason                  Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $quitMessage             Message to broadcast to online players (null will use default)
	 * @param Translatable|string|null $disconnectScreenMessage Shown on the player's disconnection screen (null will use the reason)
	 */
	public function kick(Translatable|string $reason = "", Translatable|string|null $quitMessage = null, Translatable|string|null $disconnectScreenMessage = null) : bool{
		$ev = new PlayerKickEvent($this, $reason, $quitMessage ?? $this->getLeaveMessage(), $disconnectScreenMessage);
		$ev->call();
		if(!$ev->isCancelled()){
			$reason = $ev->getDisconnectReason();
			if($reason === ""){
				$reason = KnownTranslationFactory::disconnectionScreen_noReason();
			}
			$disconnectScreenMessage = $ev->getDisconnectScreenMessage() ?? $reason;
			if($disconnectScreenMessage === ""){
				$disconnectScreenMessage = KnownTranslationFactory::disconnectionScreen_noReason();
			}
			//like BDS: the client shows its "kicked" disconnection screen instead of "an error occurred"
			$this->disconnectFailReason = DisconnectFailReason::KICKED;
			try{
				$this->disconnect($reason, $ev->getQuitMessage(), $disconnectScreenMessage);
			}finally{
				$this->disconnectFailReason = DisconnectFailReason::UNKNOWN;
			}

			return true;
		}

		return false;
	}

	/**
	 * Removes the player from the server. This cannot be cancelled.
	 * This is used for remote disconnects and for uninterruptible disconnects (for example, when the server shuts down).
	 *
	 * Note for plugin developers: Prefer kick() instead of this method.
	 * That way other plugins can have a say in whether the player is removed or not.
	 *
	 * Note for internals developers: Do not call this from network sessions. It will cause a feedback loop.
	 *
	 * @param Translatable|string      $reason                  Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $quitMessage             Message to broadcast to online players (null will use default)
	 * @param Translatable|string|null $disconnectScreenMessage Shown on the player's disconnection screen (null will use the reason)
	 */
	public function disconnect(Translatable|string $reason, Translatable|string|null $quitMessage = null, Translatable|string|null $disconnectScreenMessage = null) : void{
		if(!$this->isConnected()){
			return;
		}

		$this->getNetworkSession()->onPlayerDestroyed($reason, $disconnectScreenMessage ?? $reason, $this->disconnectFailReason);
		$this->onPostDisconnect($reason, $quitMessage);
	}

	/**
	 * @internal
	 * This method executes post-disconnect actions and cleanups.
	 *
	 * @param Translatable|string      $reason      Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $quitMessage Message to broadcast to online players (null will use default)
	 */
	public function onPostDisconnect(Translatable|string $reason, Translatable|string|null $quitMessage) : void{
		if($this->isConnected()){
			throw new \LogicException("Player is still connected");
		}

		//prevent the player receiving their own disconnect message
		$this->server->unsubscribeFromAllBroadcastChannels($this);

		$this->removeCurrentWindow();

		$ev = new PlayerQuitEvent($this, $quitMessage ?? $this->getLeaveMessage(), $reason);
		$ev->call();
		if(($quitMessage = $ev->getQuitMessage()) !== ""){
			$this->server->broadcastMessage($quitMessage);
		}
		$this->stopSleep(); //before saving: the player is saved standing next to the bed, not lying in it
		$this->save();
		$this->savedOnQuit = true;

		$this->spawned = false;

		$this->blockBreakHandler = null;
		$this->despawnFromAll();

		$this->server->removeOnlinePlayer($this);

		foreach($this->server->getOnlinePlayers() as $player){
			if(!$player->canSee($this)){
				$player->showPlayer($this);
			}
		}
		$this->hiddenPlayers = [];

		if($this->location->isValid()){
			foreach($this->usedChunks as $index => $status){
				World::getXZ($index, $chunkX, $chunkZ);
				$this->unloadChunk($chunkX, $chunkZ);
			}
		}
		if(count($this->usedChunks) !== 0){
			throw new AssumptionFailedError("Previous loop should have cleared this array");
		}
		$this->loadQueue = [];

		$this->removeCurrentWindow();
		$this->removePermanentInventories();

		$this->perm->getPermissionRecalculationCallbacks()->clear();

		$this->flagForDespawn();
	}

	protected function onDispose() : void{
		$this->disconnect("Player destroyed");
		$this->cursorInventory->removeAllViewers();
		$this->craftingGrid->removeAllViewers();
		parent::onDispose();
	}

	protected function destroyCycles() : void{
		$this->networkSession = null;
		unset($this->cursorInventory);
		unset($this->craftingGrid);
		$this->spawnPosition = null;
		$this->deathPosition = null;
		$this->blockBreakHandler = null;
		parent::destroyCycles();
	}

	/**
	 * @return mixed[]
	 */
	public function __debugInfo() : array{
		return [];
	}

	public function __destruct(){
		parent::__destruct();
		$this->logger->debug("Destroyed by garbage collector");
	}

	public function canSaveWithChunk() : bool{
		return false;
	}

	public function setCanSaveWithChunk(bool $value) : void{
		throw new \BadMethodCallException("Players can't be saved with chunks");
	}

	public function getSaveData() : CompoundTag{
		$nbt = $this->saveNBT();

		$nbt->setString(self::TAG_LAST_KNOWN_XUID, $this->xuid);

		if($this->location->isValid()){
			$nbt->setString(self::TAG_LEVEL, $this->getWorld()->getFolderName());
		}

		if($this->hasValidCustomSpawn()){
			$spawn = $this->getSpawn();
			$nbt->setString(self::TAG_SPAWN_WORLD, $spawn->getWorld()->getFolderName());
			$nbt->setInt(self::TAG_SPAWN_X, $spawn->getFloorX());
			$nbt->setInt(self::TAG_SPAWN_Y, $spawn->getFloorY());
			$nbt->setInt(self::TAG_SPAWN_Z, $spawn->getFloorZ());
			if($this->spawnBlockType !== SpawnLocator::SPAWN_BLOCK_NONE){
				$nbt->setByte(self::TAG_SPAWN_BLOCK, $this->spawnBlockType);
			}
		}

		if($this->deathPosition !== null && $this->deathPosition->isValid()){
			$nbt->setString(self::TAG_DEATH_WORLD, $this->deathPosition->getWorld()->getFolderName());
			$nbt->setInt(self::TAG_DEATH_X, $this->deathPosition->getFloorX());
			$nbt->setInt(self::TAG_DEATH_Y, $this->deathPosition->getFloorY());
			$nbt->setInt(self::TAG_DEATH_Z, $this->deathPosition->getFloorZ());
		}

		$nbt->setInt(self::TAG_GAME_MODE, GameModeIdMap::getInstance()->toId($this->gamemode));
		$nbt->setLong(self::TAG_FIRST_PLAYED, $this->firstPlayed);
		$nbt->setInt(self::TAG_TIME_SINCE_REST, $this->timeSinceRest);
		if($this->seenCredits){
			$nbt->setByte(self::TAG_SEEN_CREDITS, 1);
		}
		if($this->wardenSpawnTracker !== null){
			$nbt->setTag(self::TAG_WARDEN_SPAWN_TRACKER, $this->wardenSpawnTracker->save($this->server->getTick()));
		}
		$this->recipeBook?->save($nbt);
		$nbt->setLong(self::TAG_LAST_PLAYED, (int) floor(microtime(true) * 1000));

		return $nbt;
	}

	/**
	 * Handles player data saving
	 */
	public function save() : void{
		$this->server->saveOfflinePlayerData($this->username, $this->getSaveData());
	}

	protected function onDeath() : void{
		if($this->savedOnQuit){
			//killed after its data was saved on quit (e.g. hit in the same tick it disconnected, or while waiting to be
			//despawned): the saved inventory and XP are what it gets back when it joins again, so dropping them here
			//would duplicate them
			$this->logger->debug("Died after quitting, not dropping inventory or experience");
			return;
		}
		//Crafting grid must always be evacuated even if keep-inventory is true. This dumps the contents into the
		//main inventory and drops the rest on the ground.
		$this->removeCurrentWindow();
		$this->stopSpinAttack();

		$this->setDeathPosition($this->getPosition());
		$this->timeSinceRest = 0;

		$ev = new PlayerDeathEvent($this, $this->getDrops(), $this->getXpDropAmount(), null);
		$world = $this->getWorld();
		if($world->getGameRule(GameRules::KEEP_INVENTORY) === true){
			$ev->setKeepInventory(true);
			$ev->setKeepXp(true);
		}
		if($world->getGameRule(GameRules::SHOW_DEATH_MESSAGES) === false){
			$ev->setDeathMessage("");
		}
		$ev->call();

		if(!$ev->getKeepInventory()){
			foreach($ev->getDrops() as $item){
				$this->getWorld()->dropItem($this->location, $item);
			}

			$clearInventory = fn(Inventory $inventory) => $inventory->setContents(array_filter($inventory->getContents(), fn(Item $item) => $item->keepOnDeath()));
			$this->inventory->setHeldItemIndex(0);
			$clearInventory($this->inventory);
			$clearInventory($this->armorInventory);
			$clearInventory($this->offHandInventory);
		}

		if(!$ev->getKeepXp()){
			if(!$this->getWorld()->getVibrations()->absorbDeathExperience($this, $ev->getXpDropAmount())){ //a sculk catalyst nearby takes it
				$this->getWorld()->dropExperience($this->location, $ev->getXpDropAmount());
			}
			$this->xpManager->setXpAndProgress(0, 0.0);
		}

		if($ev->getDeathMessage() !== ""){
			$this->server->broadcastMessage($ev->getDeathMessage());
		}

		$this->dropWitherRoseIfKilledByWither();
		$this->applyEffectsOnDeath();
		$this->startDeathAnimation();

		$this->getNetworkSession()->onServerDeath($ev->getDeathScreenMessage());
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		parent::onDeathUpdate($tickDiff);
		return false; //never flag players for despawn
	}

	public function respawn() : void{
		if($this->server->isHardcore()){
			if(!$this->server->getConfigGroup()->getPropertyBool(YmlServerProperties::PLAYER_HARDCORE_BAN_ON_DEATH, false)){
				//vanilla: the dead player goes on watching the world as a spectator from where they died
				if($this->gamemode === GameMode::SPECTATOR || $this->setGamemode(GameMode::SPECTATOR)){
					$this->hardcoreRespawn = true;
				}
				$this->actuallyRespawn();
				return;
			}
			if($this->kick(KnownTranslationFactory::pocketmine_disconnect_ban(KnownTranslationFactory::pocketmine_disconnect_ban_hardcore()))){ //this allows plugins to prevent the ban by cancelling PlayerKickEvent
				$this->server->getNameBans()->addBan($this->getName(), "Died in hardcore mode");
			}
			return;
		}

		$this->actuallyRespawn();
	}

	protected function actuallyRespawn() : void{
		if($this->respawnLocked){
			return;
		}
		$this->respawnLocked = true;

		$this->logger->debug("Waiting for safe respawn position to be located");
		$this->requestRespawnPosition()->onCompletion(
			function(Position $safeSpawn) : void{
				if(!$this->isConnected()){
					return;
				}
				$this->logger->debug("Respawn position located, completing respawn");
				$ev = new PlayerRespawnEvent($this, $safeSpawn);
				$ev->call();

				$realSpawn = Position::fromObject($ev->getRespawnPosition()->add(0.5, 0, 0.5), $ev->getRespawnPosition()->getWorld());
				$this->teleport($realSpawn);

				$this->setSprinting(false);
				$this->setSneaking(false);
				$this->setFlying(false);

				$this->extinguish(EntityExtinguishEvent::CAUSE_RESPAWN);
				$this->setAirSupplyTicks($this->getMaxAirSupplyTicks());
				$this->setTicksFrozen(0); //a respawned player is a fresh entity: no frost left from freezing to death
				$this->deadTicks = 0;
				$this->noDamageTicks = 60;

				$this->effectManager->clear();
				$this->setHealth($this->getMaxHealth());

				foreach($this->attributeMap->getAll() as $attr){
					if($attr->getId() === Attribute::EXPERIENCE || $attr->getId() === Attribute::EXPERIENCE_LEVEL){ //we have already reset both of those if needed when the player died
						continue;
					}
					$attr->resetToDefault();
				}
				$this->updateArmorKnockbackResistance(); //armour kept on respawn (keepInventory, plugins)

				$this->spawnToAll();
				$this->scheduleUpdate();

				$this->getNetworkSession()->onServerRespawn();
				$this->respawnLocked = false;
			},
			function() : void{
				if($this->isConnected()){
					$this->getNetworkSession()->disconnectWithError(KnownTranslationFactory::pocketmine_disconnect_error_respawn());
				}
			}
		);
	}

	/**
	 * Finds where the player respawns: next to the bed or charged respawn anchor of the spawn point (using one of the
	 * anchor's charges), at a spawn point set by a plugin, or around the world spawn (spawnradius) when the bed or
	 * anchor is gone, empty or obstructed (the spawn point is then cleared).
	 *
	 * @phpstan-return Promise<Position>
	 */
	private function requestRespawnPosition() : Promise{
		/** @phpstan-var PromiseResolver<Position> $resolver */
		$resolver = new PromiseResolver();
		if($this->hardcoreRespawn){
			$this->hardcoreRespawn = false;
			$death = $this->getDeathPosition();
			if($death !== null){
				//block corner: the respawn adds 0.5 to X and Z
				$resolver->resolve(new Position(floor($death->x), max($death->y, $death->getWorld()->getMinY()), floor($death->z), $death->getWorld()));
				return $resolver->getPromise();
			}
		}
		$toWorldSpawn = function() use ($resolver) : void{
			SpawnLocator::requestWorldSpawn($this->server->getWorldManager()->getDefaultWorld() ?? throw new AssumptionFailedError("Default world must be loaded"))->onCompletion(
				fn(Position $pos) => $resolver->resolve($pos),
				fn() => $resolver->reject()
			);
		};

		$spawn = $this->hasValidCustomSpawn() ? $this->spawnPosition : null;
		if($spawn === null){
			$toWorldSpawn();
			return $resolver->getPromise();
		}
		$world = $spawn->getWorld();
		$world->requestChunkPopulation($spawn->getFloorX() >> Chunk::COORD_BIT_SIZE, $spawn->getFloorZ() >> Chunk::COORD_BIT_SIZE, null)->onCompletion(
			function() use ($resolver, $toWorldSpawn, $spawn, $world) : void{
				if(!$this->isConnected() || !$world->isLoaded()){
					$resolver->reject();
					return;
				}
				$type = $this->spawnBlockType;
				if($type === SpawnLocator::SPAWN_BLOCK_NONE){
					//spawn points saved before the block was recorded: still check beds and anchors
					$type = SpawnLocator::getSpawnBlockType($world->getBlockAt($spawn->getFloorX(), $spawn->getFloorY(), $spawn->getFloorZ()));
				}
				if($type === SpawnLocator::SPAWN_BLOCK_NONE){
					//set by a plugin or a command: used as it is
					$world->requestSafeSpawn($spawn)->onCompletion(
						fn(Position $pos) => $resolver->resolve($pos),
						fn() => $resolver->reject()
					);
					return;
				}
				$standUp = SpawnLocator::useSpawnBlock($world, $spawn, $this->location->yaw);
				if($standUp !== null){
					$resolver->resolve(Position::fromObject($standUp, $world));
					return;
				}
				$this->setSpawn(null);
				if($type === SpawnLocator::SPAWN_BLOCK_ANCHOR){
					$this->sendMessage(KnownTranslationFactory::tile_respawn_anchor_notValid()->prefix(TextFormat::GRAY));
				}
				//a missing or obstructed bed: BDS respawns at the world spawn without any message
				$toWorldSpawn();
			},
			$toWorldSpawn
		);
		return $resolver->getPromise();
	}

	protected function applyPostDamageEffects(EntityDamageEvent $source) : void{
		parent::applyPostDamageEffects($source);

		$this->hungerManager->exhaust(0.1, PlayerExhaustEvent::CAUSE_DAMAGE);
	}

	public function attack(EntityDamageEvent $source) : void{
		if(!$this->isAlive()){
			return;
		}

		if($this->isCreative()
			&& $source->getCause() !== EntityDamageEvent::CAUSE_SUICIDE
		){
			$source->cancel();
		}elseif($this->allowFlight && ($source->getCause() === EntityDamageEvent::CAUSE_FALL || $source->getCause() === EntityDamageEvent::CAUSE_STALAGMITE)){
			$source->cancel();
		}elseif(!GameRules::allowsPlayerDamage($this->getWorld(), $source)){
			//falldamage, firedamage, drowningdamage, freezedamage, pvp (plugins can still let it through)
			$source->cancel();
		}

		$blockedByShield = !$source->isCancelled() && $this->canBlockWithShield($source);
		if($blockedByShield){
			$source->cancel();
		}

		parent::attack($source);

		if($this->sleeping !== null && !$source->isCancelled()){
			$this->stopSleep(); //getting hurt wakes the player up
		}
		if(count($this->passengers) > 0 && !$source->isCancelled()){
			$this->dropShoulderPassengers(); //parrots fly off
		}

		if($blockedByShield && $source->isCancelled()){
			$this->onShieldBlock($source);
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setString(EntityMetadataProperties::INTERACTIVE_TAG, $this->interactiveTag);
		$properties->setGenericFlag(EntityMetadataFlags::ACTION, $this->startAction > -1);
		//a native spectator goes through blocks by its abilities (no_clip), like BDS 1.26.52 its metadata keeps
		//HAS_COLLISION and is not SILENT (the server still keeps it silent)
		$nativeSpectator = isset($this->gamemode) && $this->gamemode === GameMode::SPECTATOR;
		$properties->setGenericFlag(EntityMetadataFlags::HAS_COLLISION, $nativeSpectator || $this->hasBlockCollision());
		if($nativeSpectator){
			$properties->setGenericFlag(EntityMetadataFlags::SILENT, false);
		}
		$properties->setGenericFlag(EntityMetadataFlags::BLOCKING, $this->blocking);
		$properties->setGenericFlag(EntityMetadataFlags::SPIN_ATTACK, $this->spinAttackTicks > 0);

		$properties->setPlayerFlag(PlayerMetadataFlags::SLEEP, $this->sleeping !== null);
		//BDS sets the generic flag too
		$properties->setGenericFlag(EntityMetadataFlags::SLEEPING, $this->sleeping !== null);
		if($this->sleeping !== null){
			//this should only be sent when the player enters the bed, as of 1.26.??
			//previously we were setting this to 0,0,0 if the player wasn't sleeping, but that now causes the player to
			//teleport to that position temporarily when leaving the bed. Bugrock moment...
			$properties->setBlockPos(EntityMetadataProperties::PLAYER_BED_POSITION, BlockPosition::fromVector3($this->sleeping));
		}

		if($this->deathPosition !== null && $this->deathPosition->world === $this->location->world){
			$properties->setBlockPos(EntityMetadataProperties::PLAYER_DEATH_POSITION, BlockPosition::fromVector3($this->deathPosition));
			$properties->setInt(EntityMetadataProperties::PLAYER_DEATH_DIMENSION, $this->deathPosition->getWorld()->getDimension()->getNetworkId());
			$properties->setByte(EntityMetadataProperties::PLAYER_HAS_DIED, 1);
		}else{
			$properties->setBlockPos(EntityMetadataProperties::PLAYER_DEATH_POSITION, new BlockPosition(0, 0, 0));
			$properties->setInt(EntityMetadataProperties::PLAYER_DEATH_DIMENSION, DimensionIds::OVERWORLD);
			$properties->setByte(EntityMetadataProperties::PLAYER_HAS_DIED, 0);
		}
	}

	public function sendData(?array $targets, ?array $data = null) : void{
		if($targets === null){
			$targets = $this->getViewers();
			$targets[] = $this;
		}
		parent::sendData($targets, $data);
	}

	public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
		if($this->spawned && $targets === null){
			$targets = $this->getViewers();
			$targets[] = $this;
		}
		parent::broadcastAnimation($animation, $targets);
	}

	public function broadcastSound(Sound $sound, ?array $targets = null) : void{
		if($this->spawned && $targets === null){
			$targets = $this->getViewers();
			$targets[] = $this;
		}
		parent::broadcastSound($sound, $targets);
	}

	/**
	 * TODO: remove this
	 */
	protected function sendPosition(Vector3 $pos, ?float $yaw = null, ?float $pitch = null, int $mode = MovePlayerPacket::MODE_NORMAL) : void{
		$this->getNetworkSession()->syncMovement($pos, $yaw, $pitch, $mode);

		$this->ySize = 0;
	}

	public function teleport(Vector3 $pos, ?float $yaw = null, ?float $pitch = null) : bool{
		if(parent::teleport($pos, $yaw, $pitch)){

			$this->removeCurrentWindow();
			$this->lyingPosition = null; //the teleport decides where the player gets up
			$this->stopSleep();

			$this->sendPosition($this->location, $this->location->yaw, $this->location->pitch, MovePlayerPacket::MODE_TELEPORT);
			$this->broadcastMovement(true);

			$this->spawnToAll();

			$this->resetFallDistance();
			$this->nextChunkOrderRun = 0;
			$this->chunkOrderDirty = true;
			if($this->spawnChunkLoadCount !== -1){
				$this->spawnChunkLoadCount = 0;
			}
			$this->blockBreakHandler = null;

			//TODO: workaround for player last pos not getting updated
			//Entity::updateMovement() normally handles this, but it's overridden with an empty function in Player
			$this->resetLastMovements();

			return true;
		}

		return false;
	}

	protected function addDefaultWindows() : void{
		$this->cursorInventory = new PlayerCursorInventory($this);
		$this->craftingGrid = new PlayerCraftingInventory($this);

		$this->addPermanentInventories($this->inventory, $this->armorInventory, $this->cursorInventory, $this->offHandInventory, $this->craftingGrid);

		//TODO: more windows
	}

	public function getCursorInventory() : PlayerCursorInventory{
		return $this->cursorInventory;
	}

	public function getCraftingGrid() : CraftingGrid{
		return $this->craftingGrid;
	}

	/**
	 * Returns the crafting grid the player is crafting in: the 3x3 grid of an open crafting table, otherwise the
	 * player's own 2x2 grid ({@link Player::getCraftingGrid()}).
	 */
	public function getActiveCraftingGrid() : CraftingGrid{
		$window = $this->currentWindow;
		return $window instanceof CraftingGrid ? $window : $this->craftingGrid;
	}

	/**
	 * Returns the creative inventory shown to the player.
	 * Unless changed by a plugin, this is usually the same for all players.
	 */
	public function getCreativeInventory() : CreativeInventory{
		return $this->creativeInventory;
	}

	/**
	 * To set a custom creative inventory, you need to make a clone of a CreativeInventory instance.
	 */
	public function setCreativeInventory(CreativeInventory $inventory) : void{
		$this->creativeInventory = $inventory;
		if($this->spawned && $this->isConnected()){
			$this->getNetworkSession()->getInvManager()?->syncCreative();
		}
	}

	/**
	 * @internal Called to clean up crafting grid and cursor inventory when it is detected that the player closed their
	 * inventory.
	 */
	private function doCloseInventory() : void{
		$inventories = [$this->craftingGrid, $this->cursorInventory];
		if($this->currentWindow instanceof TemporaryInventory){
			$inventories[] = $this->currentWindow;
		}

		$builder = new TransactionBuilder();
		foreach($inventories as $inventory){
			$contents = $inventory->getContents();

			if(count($contents) > 0){
				$drops = $builder->getInventory($this->inventory)->addItem(...$contents);
				foreach($drops as $drop){
					$builder->addAction(new DropItemAction($drop));
				}

				$builder->getInventory($inventory)->clearAll();
			}
		}

		$actions = $builder->generateActions();
		if(count($actions) !== 0){
			$transaction = new InventoryTransaction($this, $actions);
			try{
				$transaction->execute();
				$this->logger->debug("Successfully evacuated items from temporary inventories");
			}catch(TransactionCancelledException){
				$this->logger->debug("Plugin cancelled transaction evacuating items from temporary inventories; items will be destroyed");
				foreach($inventories as $inventory){
					$inventory->clearAll();
				}
			}catch(TransactionValidationException $e){
				throw new AssumptionFailedError("This server-generated transaction should never be invalid", 0, $e);
			}
		}
	}

	/**
	 * Returns the inventory the player is currently viewing. This might be a chest, furnace, or any other container.
	 */
	public function getCurrentWindow() : ?Inventory{
		return $this->currentWindow;
	}

	/**
	 * Opens an inventory window to the player. Returns if it was successful.
	 */
	public function setCurrentWindow(Inventory $inventory) : bool{
		if($inventory === $this->currentWindow){
			return true;
		}
		$ev = new InventoryOpenEvent($inventory, $this);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		$this->removeCurrentWindow();

		if(($inventoryManager = $this->getNetworkSession()->getInvManager()) === null){
			throw new \InvalidArgumentException("Player cannot open inventories in this state");
		}
		$this->logger->debug("Opening inventory " . get_class($inventory) . "#" . spl_object_id($inventory));
		$inventoryManager->onCurrentWindowChange($inventory);
		$inventory->onOpen($this);
		$this->currentWindow = $inventory;
		return true;
	}

	public function removeCurrentWindow() : void{
		$this->doCloseInventory();
		if($this->currentWindow !== null){
			$currentWindow = $this->currentWindow;
			$this->logger->debug("Closing inventory " . get_class($this->currentWindow) . "#" . spl_object_id($this->currentWindow));
			$this->currentWindow->onClose($this);
			if(($inventoryManager = $this->getNetworkSession()->getInvManager()) !== null){
				$inventoryManager->onCurrentWindowRemove();
			}
			$this->currentWindow = null;
			(new InventoryCloseEvent($currentWindow, $this))->call();
		}
	}

	protected function addPermanentInventories(Inventory ...$inventories) : void{
		foreach($inventories as $inventory){
			$inventory->onOpen($this);
			$this->permanentWindows[spl_object_id($inventory)] = $inventory;
		}
	}

	protected function removePermanentInventories() : void{
		foreach($this->permanentWindows as $inventory){
			$inventory->onClose($this);
		}
		$this->permanentWindows = [];
	}

	/**
	 * Opens the player's sign editor GUI for the sign at the given position.
	 */
	public function openSignEditor(Vector3 $position, bool $frontFace = true) : void{
		$block = $this->getWorld()->getBlock($position);
		if($block instanceof BaseSign){
			$this->getWorld()->setBlock($position, $block->setEditorEntityRuntimeId($this->getId()));
			$this->getNetworkSession()->onOpenSignEditor($position, $frontFace);
		}else{
			throw new \InvalidArgumentException("Block at this position is not a sign");
		}
	}

	use ChunkListenerNoOpTrait {
		onChunkChanged as private;
		onChunkUnloaded as private;
		onBlockChanged as private;
	}

	public function onChunkChanged(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$status = $this->usedChunks[$hash = World::chunkHash($chunkX, $chunkZ)] ?? null;
		if($status === UsedChunkStatus::SENT){
			$this->usedChunks[$hash] = UsedChunkStatus::NEEDED;
			$this->nextChunkOrderRun = 0;
			$this->chunkOrderDirty = true;
		}
	}

	public function onChunkUnloaded(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		if($this->isUsingChunk($chunkX, $chunkZ)){
			$this->logger->debug("Detected forced unload of chunk " . $chunkX . " " . $chunkZ);
			$this->unloadChunk($chunkX, $chunkZ);
		}
	}

	public function onBlockChanged(Vector3 $block) : void{
		if($this->sleeping !== null && $block->equals($this->sleeping) && !($this->getWorld()->getBlock($block) instanceof Bed)){
			$this->logger->debug("Bed was changed or deleted, aborting sleep");
			$this->stopSleep();
		}
	}
}
