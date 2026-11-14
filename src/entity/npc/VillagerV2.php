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

namespace pocketmine\entity\npc;

use pocketmine\block\Bed;
use pocketmine\block\Liquid;
use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\raid\HideFromRaidGoal;
use pocketmine\entity\ai\villager\DoorInteraction;
use pocketmine\entity\ai\villager\ExploreOutskirtsGoal;
use pocketmine\entity\ai\villager\FertilizeCropsGoal;
use pocketmine\entity\ai\villager\GatherAtBellGoal;
use pocketmine\entity\ai\villager\GoHomeGoal;
use pocketmine\entity\ai\villager\HarvestCropsGoal;
use pocketmine\entity\ai\villager\MoveIndoorsGoal;
use pocketmine\entity\ai\villager\MoveOutdoorsGoal;
use pocketmine\entity\ai\villager\MoveTowardsDwellingGoal;
use pocketmine\entity\ai\villager\PickupItemsGoal;
use pocketmine\entity\ai\villager\PlayGoal;
use pocketmine\entity\ai\villager\ShareFoodGoal;
use pocketmine\entity\ai\villager\SleepInBedGoal;
use pocketmine\entity\ai\villager\VillagerGoal;
use pocketmine\entity\ai\villager\WorkAtJobSiteGoal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\TakeFlowerGoal;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Illager;
use pocketmine\entity\monster\MobConversion;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\Witch;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\npc\trade\ExplorerMaps;
use pocketmine\entity\object\FireworkRocket;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\SimpleInventory;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\item\FireworkRocketType;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Binary;
use pocketmine\world\GameRules;
use pocketmine\world\raid\PoiType;
use pocketmine\world\raid\VillageScanner;
use pocketmine\world\weather\Precipitation;
use pocketmine\world\World;
use function array_map;
use function ceil;
use function count;
use function floor;
use function in_array;
use function intdiv;
use function max;
use function min;
use function mt_rand;

/**
 * The current villager (Bedrock "minecraft:villager_v2", entities/villager_v2.json): 20 health, 0.6x1.9, movement 0.5
 * (strolls and panics at 0.6x), flees from zombies, illagers, vexes and zoglins ({@link VillagerV2::isAvoided()}),
 * babies scale 0.5. Profession (VARIANT 0-14: unemployed, farmer, fisherman, shepherd, fletcher, librarian,
 * cartographer, cleric, armorer, weaponsmith, toolsmith, butcher, leatherworker, mason, nitwit), biome type
 * (MARK_VARIANT 0-6: plains, desert, jungle, savanna, snow, swamp, taiga; plains by default) and skin (SKIN_ID 0-5).
 * A new villager is a baby 5% of the time; adults get one of the 13 professions or nitwit (Bedrock entity_spawned:
 * spawn eggs, the summon command, plugins), except the villagers of village structures (spawn reason
 * {@link SpawnReason::STRUCTURE}), which are 90% unemployed and 10% nitwits and then take the free job sites (Bedrock
 * spawn_from_village); babies growing up become nitwits 10% of the time (Bedrock ageable_grow_up).
 *
 * Village life ("minecraft:dweller", updated every 3-5 seconds of AI time):
 * - Claims: a villager claims the nearest free bed within 48 blocks; an unemployed adult (not a nitwit) with a bed
 *   claims the nearest free job site block within 32 blocks and takes its profession ({@link JobSites}); employed
 *   villagers without a job site claim one of their profession. If the job site is broken, a villager that never
 *   traded becomes unemployed again; one that traded keeps its profession and looks for another job site. Claims are
 *   saved in the NBT, registered in the world's {@link \pocketmine\world\raid\PoiManager} and released on death.
 * - Schedule ({@link VillagerSchedule}): work at the job site (farmers also harvest and replant crops, use bone meal
 *   on them and compost their surplus seeds), gather and gossip at the bell, wander (exploring the outskirts of the
 *   village), go home and sleep in the claimed bed at night (SLEEPING flag, bed occupied). Babies play. When it rains
 *   (or snows) where they are, villagers go home instead; those without a bed look for a roof. A sleeping villager
 *   wakes up when hurt, when its schedule moves on or when a player uses its bed.
 * - Dwelling: the random walks of a villager with a bed stay within 32 blocks of it (more if its job site is
 *   farther), and a villager that strayed farther walks back ({@link VillagerV2::getHomeRadius()}).
 * - Bells: villagers within 32 blocks of a ringing bell hide in a bed for 30 seconds ({@link VillagerV2::hearBell()}).
 * - Trades restock when the villager arrives at its job site, at most twice a day, if it has a bed (Bedrock).
 * - Food (minecraft:shareables, {@link VillagerV2::getWantAmount()}): villagers walk to wanted items within 3 blocks
 *   and pick them up into 8 hidden slots, up to their want amounts (bread, carrots, potatoes and beetroots; peasants
 *   also wheat and seeds, farmers also bone meal); peasants bake 3 wheat into bread; villagers with a surplus throw
 *   half of it to hungry villagers nearby.
 * - Breeding: an adult with at least 12 food points (bread 4, carrot/potato/beetroot 1), outside its sleeping hours
 *   and with a free bed nearby, spends them and becomes willing (in love) for 30 seconds; two willing villagers make
 *   a baby, which grows up in 20 minutes.
 * - Gossip ({@link Gossips}): curing, trading, hitting and killing villagers change the reputation of the player,
 *   which lowers prices (see {@link TradePrices}) and makes iron golems hostile at -100 or less.
 * - Iron golems: every 700 ticks, at any time of day, a village with at least 10 villagers per golem (plus one), 20
 *   beds and 75% of its villagers having worked in the last day summons an iron golem in the 17x13x17 box around
 *   its centre (Bedrock conditions, {@link VillagerV2::trySummonGolem()}).
 *
 * Trading: players open the vanilla trading screen (UpdateTradePacket, see {@link TradeInventory}); trades come from
 * {@link VillagerTrades} (the vanilla trade tables, picked per group when the villager reaches a level). Trading gives
 * the villager experience (TRADE_XP) and levels it up (TRADE_TIER, up to master) with new trades, and gives the player
 * experience. Players with Hero of the Village pay less.
 *
 * During a raid villagers run to the beds of the village ({@link HideFromRaidGoal}); when it is won they go outdoors
 * and launch fireworks for 30 seconds. Struck by lightning a villager becomes a witch.
 *
 * Not implemented: trade demand, librarians inspecting bookshelves, fishermen/shepherd specific work goals, village
 * migration and the Java panic golem summoning.
 */
class VillagerV2 extends Animal implements Trader{
	use TradeItemDisplayTrait;

	public const TAG_PROFESSION = "Variant"; //TAG_Int
	public const TAG_TYPE = "MarkVariant"; //TAG_Int
	public const TAG_SKIN = "SkinID"; //TAG_Int
	public const TAG_TRADE_TIER = "TradeTier"; //TAG_Int
	public const TAG_TRADE_EXP = "TradeExperience"; //TAG_Int
	public const TAG_OFFERS = "Offers"; //TAG_List<TAG_Compound>, vanilla "Recipes" entries
	public const TAG_BED = "PmBedPosition"; //TAG_IntArray [x, y, z]
	public const TAG_JOB_SITE = "PmJobSitePosition"; //TAG_IntArray [x, y, z]
	public const TAG_INVENTORY = "Inventory"; //TAG_List<TAG_Compound> items with "Slot"
	public const TAG_GOSSIPS = "Gossips"; //TAG_List<TAG_Compound>
	public const TAG_GOSSIP_DECAY = "PmGossipDecay"; //TAG_Int, ticks until the next decay
	public const TAG_RESTOCK_DAY = "PmRestockDay"; //TAG_Int, day of the last restocks
	public const TAG_RESTOCKS = "PmRestocks"; //TAG_Int, restocks on that day
	/** TAG_Int, ticks since the villager last arrived at its job site (so that hasWorkedRecently() survives restarts) */
	private const TAG_LAST_WORKED_AGO = "PmLastWorkedAgo";

	public const PROFESSION_UNEMPLOYED = 0;
	public const PROFESSION_FARMER = 1;
	public const PROFESSION_FISHERMAN = 2;
	public const PROFESSION_SHEPHERD = 3;
	public const PROFESSION_FLETCHER = 4;
	public const PROFESSION_LIBRARIAN = 5;
	public const PROFESSION_CARTOGRAPHER = 6;
	public const PROFESSION_CLERIC = 7;
	public const PROFESSION_ARMORER = 8;
	public const PROFESSION_WEAPONSMITH = 9;
	public const PROFESSION_TOOLSMITH = 10;
	public const PROFESSION_BUTCHER = 11;
	public const PROFESSION_LEATHERWORKER = 12;
	public const PROFESSION_MASON = 13;
	public const PROFESSION_NITWIT = 14;

	private const PROFESSION_NAMES = [
		"unskilled", "farmer", "fisherman", "shepherd", "fletcher", "librarian", "cartographer", "cleric", "armor", "weapon", "tool", "butcher", "leather", "mason", "nitwit"
	];

	public const TYPE_PLAINS = 0;
	public const TYPE_DESERT = 1;
	public const TYPE_JUNGLE = 2;
	public const TYPE_SAVANNA = 3;
	public const TYPE_SNOW = 4;
	public const TYPE_SWAMP = 5;
	public const TYPE_TAIGA = 6;

	public const MAX_TIER = 4;
	/** Restocks a day at most (vanilla: twice, when working at the job site). */
	public const MAX_RESTOCKS_PER_DAY = 2;

	/** Range of the bed and job site searches. */
	public const BED_SEARCH_HORIZONTAL = 48;
	public const BED_SEARCH_VERTICAL = 16;
	/** Ticks an unreachable bed is skipped when looking for one. */
	public const UNREACHABLE_BED_MEMORY = 1200;
	public const JOB_SITE_SEARCH_HORIZONTAL = 32;
	public const JOB_SITE_SEARCH_VERTICAL = 8;
	/** Ticks between two village updates (Bedrock dweller update_interval_base 60 + variant 40). */
	public const DWELLER_INTERVAL = 60;
	public const DWELLER_INTERVAL_VARIANT = 40;

	/** Food points spent to become willing to breed (3 bread, or 12 carrots, potatoes or beetroots). */
	public const BREEDING_FOOD_POINTS = 12;
	public const INVENTORY_SIZE = 8;
	/** Ticks during which a villager doesn't pick up the food it threw to another one. */
	private const THROWN_ITEM_TICKS = 200;

	public const GOSSIP_CURE_MAJOR = 20;
	public const GOSSIP_CURE_MINOR = 25;
	public const GOSSIP_TRADE = 2;
	public const GOSSIP_HIT = 25;
	/** Iron golems within this range help a villager that is hurt (see {@link IronGolem::onVillagerAttacked()}). */
	public const GOLEM_DEFEND_RANGE = 16.0;
	public const GOSSIP_KILL = 25;
	/** Villagers this close to a villager killed by a player remember it. */
	public const KILL_WITNESS_RANGE = 16.0;

	/** Iron golem summoning (Bedrock): villagers per golem, beds and share of villagers that worked in the last day. */
	public const GOLEM_VILLAGERS_PER_GOLEM = 10;
	public const GOLEM_MIN_BEDS = 20;
	public const GOLEM_WORKED_RATIO = 0.75;
	public const GOLEM_CHECK_INTERVAL = 700;
	public const VILLAGE_RANGE_HORIZONTAL = 32;
	public const VILLAGE_RANGE_VERTICAL = 16;
	/** Half sizes of the box around the village centre where golems are summoned (Bedrock: 17x13x17). */
	public const GOLEM_SPAWN_HORIZONTAL = 8;
	public const GOLEM_SPAWN_VERTICAL = 6;

	/** Percent of nitwits among the adults spawned by villages and among the babies growing up (Bedrock). */
	public const NITWIT_CHANCE = 10;
	/** Blocks around the bed the villager's random walks stay within (move_towards_dwelling_restriction). */
	public const DWELLING_RADIUS = 32;
	/** Ticks a villager hides in a bed after hearing a bell (behavior.hide duration 30 s). */
	public const HIDE_TICKS = 600;
	/** Villagers within this distance of a ringing bell hide. */
	public const BELL_HEARING_RANGE = 32.0;
	/** Ticks a celebration lasts (celebrate_survive duration 30 s, then minecraft:stop_celebrating). */
	public const CELEBRATE_TICKS = 600;
	/** Ticks between two fireworks of a celebrating villager (celebrate_survive fireworks_interval 2-7 s). */
	public const FIREWORK_INTERVAL_MIN = 40;
	public const FIREWORK_INTERVAL_MAX = 140;
	/** Horizontal reach of the item pickup (behavior.pickup_items max_dist). */
	public const PICKUP_MAX_DISTANCE = 3.0;

	private int $profession = self::PROFESSION_UNEMPLOYED;
	private int $type = self::TYPE_PLAINS;
	private int $skin = 0;
	private int $tradeTier = 0;
	private int $tradeExp = 0;
	/**
	 * @var TradeOffer[]
	 * @phpstan-var list<TradeOffer>
	 */
	private array $offers = [];
	private ?Player $tradingPlayer = null;
	private bool $celebrating = false;

	private ?Vector3 $bed = null;
	private ?Vector3 $jobSite = null;
	private bool $claimsRegistered = false;
	private ?Vector3 $sleepingIn = null;
	private ?DoorInteraction $doors = null;
	/** @phpstan-var array<int, int> block hash => server tick until which the bed is skipped (unreachable) */
	private array $unreachableBeds = [];
	private int $dwellerTicks = 0;
	/** Tick on which the path search to a free bed was first refused by the budget, -1 if it wasn't (see AiBudget). */
	private int $bedSearchWaitingSince = -1;
	private int $pickupTicks = 0;
	private int $restockDay = -1;
	private int $restocksToday = 0;
	/** Server tick of the last arrival at the job site. */
	private int $lastWorkedTick = -100000;
	/**
	 * Created by the code that spawns it right now with the profession rolled in initEntity() (its spawn reason may
	 * still change that profession).
	 */
	private bool $freshSpawn = false;
	/** Radius of the dwelling restriction around the bed (see {@link VillagerV2::getHomeRadius()}). */
	private int $dwellingRadius = self::DWELLING_RADIUS;
	/** Server tick until which the villager hides in a bed because a bell rang (minecraft:hide). */
	private int $hideUntil = -1;
	/** AI ticks until the next celebration firework. */
	private int $fireworkTicks = 0;
	/** Server tick at which the celebration ends by itself. */
	private int $celebrateUntil = 0;
	/** Wanted item a few blocks away that the villager walks to (see {@link PickupItemsGoal}). */
	private ?ItemEntity $pickupTarget = null;

	/**
	 * @var int[] runtime ID of an item the villager threw to another one => tick until which it doesn't pick it up
	 * @phpstan-var array<int, int>
	 */
	private array $thrownItems = [];
	private SimpleInventory $inventory;
	private Gossips $gossips;
	private int $gossipDecayTicks = Gossips::DECAY_INTERVAL;

	public static function getNetworkTypeId() : string{ return EntityIds::VILLAGER_V2; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	/**
	 * Villagers cannot be led (vanilla).
	 */
	public function canBeLeashed() : bool{
		return false;
	}

	public function getName() : string{
		return "Villager";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(1.96, 0.98, 1.671); //baby collision box 0.98 x 1.96 at scale 0.5
	}

	protected function canBeAgeLocked() : bool{
		return false; //no golden dandelion in its ageable component
	}

	public function makeSpawnBaby() : bool{
		parent::makeSpawnBaby();
		if($this->profession !== self::PROFESSION_UNEMPLOYED){
			$this->profession = self::PROFESSION_UNEMPLOYED; //babies have no job
			$this->offers = [];
			$this->networkPropertiesDirty = true;
		}
		return true;
	}

	/**
	 * Profession of an adult of a village structure (Bedrock spawn_from_village): 90% unemployed, 10% nitwit. They then
	 * take the free job sites of the village.
	 *
	 * @internal
	 */
	public static function rollVillageProfession() : int{
		return mt_rand(0, 99) < self::NITWIT_CHANCE ? self::PROFESSION_NITWIT : self::PROFESSION_UNEMPLOYED;
	}

	/**
	 * Profession of any other new adult (Bedrock entity_spawned: spawn eggs, the summon command, plugins): the 13
	 * professions and nitwit, equally likely.
	 *
	 * @internal
	 */
	public static function rollSpawnEggProfession() : int{
		return mt_rand(self::PROFESSION_FARMER, self::PROFESSION_NITWIT);
	}

	public function setSpawnReason(string $reason) : void{
		parent::setSpawnReason($reason);
		if($reason === SpawnReason::STRUCTURE && $this->freshSpawn && !$this->isBaby()){
			//spawn_from_village instead of entity_spawned
			$this->setProfession(self::rollVillageProfession());
		}
	}

	/**
	 * A baby growing up becomes a nitwit 10% of the time, otherwise it stays unemployed (Bedrock ageable_grow_up).
	 */
	protected function onGrowUp() : void{
		parent::onGrowUp();
		if($this->profession === self::PROFESSION_UNEMPLOYED && mt_rand(0, 99) < self::NITWIT_CHANCE){
			$this->setProfession(self::PROFESSION_NITWIT);
		}
	}

	/**
	 * Villager type of a biome (Bedrock biome tags).
	 */
	public static function typeForBiome(World $world, Vector3 $pos) : int{
		$tags = MobBiomeInfo::getTags($world, $pos);
		$has = fn(string $tag) => in_array($tag, $tags, true);
		return match(true){
			$has("desert") || $has("mesa") => self::TYPE_DESERT,
			$has("jungle") => self::TYPE_JUNGLE,
			$has("savanna") => self::TYPE_SAVANNA,
			$has("frozen") || $has("ice") || ($has("cold") && !$has("ocean")) || $has("grove") || $has("snowy_slopes") => self::TYPE_SNOW,
			$has("swamp") || $has("mangrove_swamp") => self::TYPE_SWAMP,
			$has("taiga") => self::TYPE_TAIGA,
			default => self::TYPE_PLAINS
		};
	}

	/**
	 * pocketmine/nbt reads the values of an IntArray as unsigned (-418 comes back as 4294966878, which the next save
	 * of the home as an IntTag rejects): they are sign-extended here. Values already in the signed range (any save,
	 * old or new: the bytes are the same) are unchanged.
	 *
	 * @param int[] $array
	 */
	private static function readPos(array $array) : ?Vector3{
		return count($array) === 3 ? new Vector3(Binary::signInt($array[0]), Binary::signInt($array[1]), Binary::signInt($array[2])) : null;
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function writePos(Vector3 $pos) : array{
		return [(int) $pos->x, (int) $pos->y, (int) $pos->z];
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.5);
		$this->inventory = new SimpleInventory(self::INVENTORY_SIZE);
		$new = $nbt->getTag(self::TAG_PROFESSION) === null;
		if($new && $nbt->getTag(self::TAG_AGE) === null && mt_rand(0, 19) === 0){
			$nbt->setInt(self::TAG_AGE, self::BABY_AGE);
		}
		$this->gossips = Gossips::fromNbt($nbt->getListTag(self::TAG_GOSSIPS));
		parent::initEntity($nbt);
		if($new){
			//entity_spawned; village structures roll again in setSpawnReason() (spawn_from_village)
			$this->profession = $this->isBaby() ? self::PROFESSION_UNEMPLOYED : self::rollSpawnEggProfession();
			$this->freshSpawn = true;
			$this->type = self::typeForBiome($this->getWorld(), $this->location);
			$this->skin = mt_rand(0, 5);
		}else{
			$this->profession = $nbt->getInt(self::TAG_PROFESSION) % 15;
			$this->type = $nbt->getInt(self::TAG_TYPE, 0) % 7;
			$this->skin = $nbt->getInt(self::TAG_SKIN, 0);
		}
		$this->tradeTier = min(self::MAX_TIER, $nbt->getInt(self::TAG_TRADE_TIER, 0));
		$this->tradeExp = $nbt->getInt(self::TAG_TRADE_EXP, 0);
		$offers = $nbt->getListTag(self::TAG_OFFERS);
		if($offers !== null){
			foreach($offers as $tag){
				if($tag instanceof CompoundTag){
					try{
						$this->offers[] = TradeOffer::fromNbt($tag);
					}catch(SavedDataLoadingException){
						//dropped: unknown item
					}
				}
			}
		}
		if(count($this->offers) === 0 && !$this->isBaby()){
			$this->unlockTradesUpTo($this->tradeTier);
		}

		$this->bed = self::readPos($nbt->getIntArray(self::TAG_BED, []));
		$this->jobSite = self::readPos($nbt->getIntArray(self::TAG_JOB_SITE, []));
		$items = $nbt->getListTag(self::TAG_INVENTORY);
		if($items !== null){
			foreach($items as $tag){
				if($tag instanceof CompoundTag){
					try{
						$slot = $tag->getByte(SavedItemStackData::TAG_SLOT, 0);
						if($slot >= 0 && $slot < self::INVENTORY_SIZE){
							$this->inventory->setItem($slot, Item::nbtDeserialize($tag));
						}
					}catch(SavedDataLoadingException){
						//dropped: unknown item
					}
				}
			}
		}
		$this->gossipDecayTicks = $nbt->getInt(self::TAG_GOSSIP_DECAY, Gossips::DECAY_INTERVAL);
		$this->restockDay = $nbt->getInt(self::TAG_RESTOCK_DAY, -1);
		$this->restocksToday = $nbt->getInt(self::TAG_RESTOCKS, 0);
		$workedAgo = $nbt->getInt(self::TAG_LAST_WORKED_AGO, -1);
		if($workedAgo >= 0 && $workedAgo <= World::TIME_FULL){
			$this->lastWorkedTick = $this->server->getTick() - $workedAgo;
		}
		$this->dwellerTicks = mt_rand(1, self::DWELLER_INTERVAL);
		$this->syncDwelling();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_PROFESSION, $this->profession);
		$nbt->setInt(self::TAG_TYPE, $this->type);
		$nbt->setInt(self::TAG_SKIN, $this->skin);
		$nbt->setInt(self::TAG_TRADE_TIER, $this->tradeTier);
		$nbt->setInt(self::TAG_TRADE_EXP, $this->tradeExp);
		$nbt->setTag(self::TAG_OFFERS, new ListTag(array_map(fn(TradeOffer $o) => $o->toNbt(), $this->offers)));
		if($this->bed !== null){
			$nbt->setIntArray(self::TAG_BED, self::writePos($this->bed));
		}
		if($this->jobSite !== null){
			$nbt->setIntArray(self::TAG_JOB_SITE, self::writePos($this->jobSite));
		}
		$items = new ListTag([], NBT::TAG_Compound);
		foreach($this->inventory->getContents() as $slot => $item){
			$items->push($item->nbtSerialize($slot));
		}
		$nbt->setTag(self::TAG_INVENTORY, $items);
		$nbt->setTag(self::TAG_GOSSIPS, $this->gossips->toNbt());
		$nbt->setInt(self::TAG_GOSSIP_DECAY, $this->gossipDecayTicks);
		$nbt->setInt(self::TAG_RESTOCK_DAY, $this->restockDay);
		$nbt->setInt(self::TAG_RESTOCKS, $this->restocksToday);
		$workedAgo = $this->server->getTick() - $this->lastWorkedTick;
		if($workedAgo <= World::TIME_FULL){
			$nbt->setInt(self::TAG_LAST_WORKED_AGO, max(0, $workedAgo));
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new HideFromRaidGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 0.6));
		$this->goalSelector->addGoal(2, new class($this) extends Goal{
			public function __construct(private VillagerV2 $villager){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

			public function canStart() : bool{ return $this->villager->getTradingPlayer() !== null; }

			public function start() : void{ $this->villager->getNavigation()->stop(); }

			public function tick() : void{
				$player = $this->villager->getTradingPlayer();
				if($player !== null){
					$this->villager->getLookControl()->setLookAtEntity($player);
				}
			}
		});
		$this->goalSelector->addGoal(2, new MoveOutdoorsGoal($this));
		$this->goalSelector->addGoal(3, new SleepInBedGoal($this));
		$this->goalSelector->addGoal(4, new AvoidEntityGoal($this, self::isAvoided(...), 8.0, 0.6, 0.6, fn() => self::worldHasAvoided($this->getWorld())));
		$this->goalSelector->addGoal(4, new PickupItemsGoal($this));
		$this->goalSelector->addGoal(5, new BreedGoal($this, 0.5));
		$this->goalSelector->addGoal(5, new TradeInterestGoal($this,
			fn(Player $player) : bool => $this->sleepingIn === null && $this->canTradeWith($player) && $this->getTradingPlayer() === null,
			fn(?Item $item) => $this->setShownTradeItem($item)
		));
		$this->goalSelector->addGoal(6, new GoHomeGoal($this));
		$this->goalSelector->addGoal(6, new MoveIndoorsGoal($this));
		$this->goalSelector->addGoal(7, new HarvestCropsGoal($this));
		$this->goalSelector->addGoal(7, new GatherAtBellGoal($this));
		$this->goalSelector->addGoal(8, new FertilizeCropsGoal($this));
		$this->goalSelector->addGoal(8, new WorkAtJobSiteGoal($this));
		$this->goalSelector->addGoal(8, new PlayGoal($this));
		$this->goalSelector->addGoal(9, new ExploreOutskirtsGoal($this));
		$this->goalSelector->addGoal(9, new TakeFlowerGoal($this, $this->isBaby(...)));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(10, new ShareFoodGoal($this));
		$this->goalSelector->addGoal(11, new MoveTowardsDwellingGoal($this));
		$this->goalSelector->addGoal(11, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(12, new RandomLookAroundGoal($this));
	}

	/**
	 * Mobs villagers run from (avoid_mob_type families zombie, zombie_villager, illager, vex and zoglin): zombies,
	 * husks, drowned and zombie villagers but not zombified piglins (family zombie_pigman), pillagers, vindicators and
	 * evokers, vexes and zoglins.
	 */
	public static function isAvoided(Entity $entity) : bool{
		return ($entity instanceof Zombie && !$entity instanceof ZombifiedPiglin) || $entity instanceof Illager || $entity instanceof Vex || $entity instanceof Zoglin;
	}

	/**
	 * Whether the world holds any entity of a class {@link VillagerV2::isAvoided()} may accept: villagers don't look
	 * around for them in worlds without any.
	 */
	private static function worldHasAvoided(World $world) : bool{
		return $world->hasEntitiesOfClass(Zombie::class) || $world->hasEntitiesOfClass(Illager::class) || $world->hasEntitiesOfClass(Vex::class) ||
			$world->hasEntitiesOfClass(Zoglin::class);
	}

	public function getProfession() : int{
		return $this->profession;
	}

	/**
	 * Changes the profession; the trades are replaced by the new profession's for the current level. A profession set
	 * this way is kept by a later {@link VillagerV2::setSpawnReason()}.
	 */
	public function setProfession(int $profession) : void{
		$this->freshSpawn = false; //chosen by the code creating the villager: the village roll doesn't replace it
		$this->profession = $profession;
		$this->offers = [];
		$this->unlockTradesUpTo($this->tradeTier);
		$this->networkPropertiesDirty = true;
	}

	public function getVillagerType() : int{
		return $this->type;
	}

	public function setVillagerType(int $type) : void{
		$this->type = $type;
		$this->networkPropertiesDirty = true;
	}

	public function getTradeExperience() : int{
		return $this->tradeExp;
	}

	/**
	 * Whether a player traded with this villager: it then keeps its profession for good.
	 */
	public function hasTraded() : bool{
		return $this->tradeExp > 0;
	}

	private function unlockTradesUpTo(int $tier) : void{
		$known = [];
		foreach($this->offers as $offer){
			$known[$offer->getTier()] = true;
		}
		$world = $this->getWorld();
		$inOverworld = $world->getDimension()->getNetworkId() === DimensionIds::OVERWORLD;
		for($t = 0; $t <= $tier; ++$t){
			if(!isset($known[$t])){
				$offers = VillagerTrades::createOffers($this->profession, $t, null, $this->type, $inOverworld,
					fn(Item $map, string $destination) : ?Item => ExplorerMaps::create($map, $destination, $world, $this->location)
				);
				foreach($offers as $offer){
					$this->offers[] = $offer;
				}
			}
		}
	}

	public function getTradeOffers() : array{
		return $this->offers;
	}

	public function getTradeTier() : int{
		return $this->tradeTier;
	}

	public function getTierExpRequirements() : array{
		return VillagerTrades::TIER_EXP_REQUIREMENTS;
	}

	public function getTradeDisplayName() : string{
		return "entity.villager." . self::PROFESSION_NAMES[$this->profession];
	}

	/**
	 * Bedrock behavior.trade_with_player filters: not in water, and on the ground or sleeping (sleeping villagers
	 * trade, swimming ones don't).
	 */
	public function canTradeWith(Player $player) : bool{
		return !$this->isBaby() && $this->isAlive() && count($this->offers) > 0 &&
			!$this->isInWater() && ($this->onGround || $this->sleepingIn !== null) &&
			$this->profession !== self::PROFESSION_UNEMPLOYED && $this->profession !== self::PROFESSION_NITWIT &&
			($this->tradingPlayer === null || $this->tradingPlayer === $player || !$this->tradingPlayer->isConnected());
	}

	public function getTradingPlayer() : ?Player{
		if($this->tradingPlayer !== null && (!$this->tradingPlayer->isConnected() || !$this->tradingPlayer->getCurrentWindow() instanceof TradeInventory)){
			$this->setTradingPlayer(null);
		}
		return $this->tradingPlayer;
	}

	public function setTradingPlayer(?Player $player) : void{
		$this->tradingPlayer = $player;
		$this->networkPropertiesDirty = true;
	}

	public function onTrade(Player $player, TradeOffer $offer, int $times) : void{
		$this->broadcastActorEvent(ActorEvent::COMPLETE_TRADE);
		$this->playMobSound(LevelSoundEvent::HAGGLE_YES);
		$this->gossips->add($player->getUniqueId()->toString(), GossipType::TRADING, self::GOSSIP_TRADE * $times);
		$this->addTradeExperience($offer->getTraderExp() * $times);
	}

	/**
	 * Adds trading experience, levelling the villager up (with new trades) when it reaches the next level.
	 */
	public function addTradeExperience(int $exp) : void{
		$this->tradeExp += $exp;
		while($this->tradeTier < self::MAX_TIER && $this->tradeExp >= VillagerTrades::TIER_EXP_REQUIREMENTS[$this->tradeTier + 1]){
			++$this->tradeTier;
			$this->unlockTradesUpTo($this->tradeTier);
			$this->broadcastActorEvent(ActorEvent::VILLAGER_HAPPY);
			$this->effectManager->add(new EffectInstance(VanillaEffects::REGENERATION(), 200));
		}
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Resets the uses of every trade, updating their demand first (see {@link TradeOffer::restock()}).
	 */
	public function restock() : void{
		foreach($this->offers as $offer){
			$offer->restock();
		}
	}

	private function needsRestock() : bool{
		foreach($this->offers as $offer){
			if($offer->getUses() > 0){
				return true;
			}
		}
		return false;
	}

	public function getRestocksToday() : int{
		return $this->restockDay === intdiv($this->getWorld()->getTime(), World::TIME_FULL) ? $this->restocksToday : 0;
	}

	/**
	 * Called by {@link WorkAtJobSiteGoal} when the villager reaches its job site: restocks the trades if some were
	 * used, at most {@link VillagerV2::MAX_RESTOCKS_PER_DAY} times a day and only with a bed (Bedrock).
	 */
	public function onArriveAtWork() : void{
		$this->lastWorkedTick = $this->server->getTick();
		$day = intdiv($this->getWorld()->getTime(), World::TIME_FULL);
		if($day !== $this->restockDay){
			$this->restockDay = $day;
			$this->restocksToday = 0;
		}
		if($this->bed !== null && $this->restocksToday < self::MAX_RESTOCKS_PER_DAY && $this->needsRestock()){
			++$this->restocksToday;
			$this->restock();
		}
	}

	/**
	 * Whether the villager worked at its job site during the last day.
	 */
	public function hasWorkedRecently() : bool{
		return $this->server->getTick() - $this->lastWorkedTick <= World::TIME_FULL;
	}

	// ---- schedule, claims and sleep -------------------------------------------------------------------------------

	/**
	 * What the villager does now according to its schedule.
	 */
	public function getActivity() : VillagerActivity{
		$world = $this->getWorld();
		$weather = $world->getWeather();
		$raining = $weather->isRaining() && $weather->getPrecipitationAt(
			(int) floor($this->location->x), (int) floor($this->location->y), (int) floor($this->location->z)
		) !== Precipitation::NONE;
		return VillagerSchedule::getActivity($world->getTimeOfDay(), $this->profession, $this->isBaby(), $raining);
	}

	public function getBedPosition() : ?Vector3{
		return $this->bed;
	}

	public function getJobSitePosition() : ?Vector3{
		return $this->jobSite;
	}

	/**
	 * Claims the bed (its head block). Returns false if another villager has it.
	 */
	public function claimBed(Vector3 $bed) : bool{
		$bed = $bed->floor();
		if(!$this->getWorld()->getPoiManager()->claim($bed, $this->id)){
			return false;
		}
		if($this->bed !== null && !$this->bed->equals($bed)){
			$this->releaseBed();
		}
		$this->bed = $bed;
		$this->syncDwelling();
		return true;
	}

	/**
	 * Whether the path finder finds a way to the block (within 1.5 blocks of it).
	 */
	public function canWalkTo(Vector3 $block) : bool{
		$loc = $this->location;
		$finder = $this->getNavigation()->getPathFinder();
		//villagers avoid water (vanilla avoid_water), which makes searches across it expand many more nodes: this rare
		//search gets twice the budget (vanilla Java villagers search up to 768 nodes)
		$finder->setMaxNodes(AiBudget::getMaxPathNodes() * 2);
		$path = $finder->findPath(
			(int) floor($loc->x), (int) floor($loc->y), (int) floor($loc->z),
			$block->getFloorX(), $block->getFloorY(), $block->getFloorZ(), 1.5
		);
		$finder->setMaxNodes(AiBudget::getMaxPathNodes());
		return $path !== null && $path->reachesTarget();
	}

	/**
	 * Forgets the bed if it is the claimed one and skips it when looking for a bed for a while (the villager couldn't
	 * get there).
	 */
	public function markBedUnreachable(Vector3 $bed) : void{
		$bed = $bed->floor();
		$this->unreachableBeds[World::blockHash($bed->getFloorX(), $bed->getFloorY(), $bed->getFloorZ())] = $this->server->getTick() + self::UNREACHABLE_BED_MEMORY;
		if($this->bed !== null && $this->bed->equals($bed)){
			$this->releaseBed();
		}
	}

	public function releaseBed() : void{
		$bed = $this->bed;
		if($bed !== null){
			if($this->sleepingIn !== null){
				$this->wakeUp();
			}
			$this->getWorld()->getPoiManager()->release($bed, $this->id);
			$this->bed = null;
			$this->syncDwelling();
		}
	}

	/**
	 * Claims the job site block; an unemployed villager takes its profession. Returns false if another villager has
	 * it or it isn't a job site of the villager's profession.
	 */
	public function claimJobSite(Vector3 $pos) : bool{
		$pos = $pos->floor();
		$world = $this->getWorld();
		$profession = JobSites::getProfession($world->getBlock($pos)->getTypeId());
		if($profession === null || ($this->profession !== self::PROFESSION_UNEMPLOYED && $profession !== $this->profession) || !$world->getPoiManager()->claim($pos, $this->id)){
			return false;
		}
		if($this->jobSite !== null && !$this->jobSite->equals($pos)){
			$this->releaseJobSite();
		}
		$this->jobSite = $pos;
		$this->syncDwelling();
		if($this->profession !== $profession){
			$this->setProfession($profession);
		}
		$this->broadcastActorEvent(ActorEvent::VILLAGER_HAPPY);
		return true;
	}

	public function releaseJobSite() : void{
		if($this->jobSite !== null){
			$this->getWorld()->getPoiManager()->release($this->jobSite, $this->id);
			$this->jobSite = null;
			$this->syncDwelling();
		}
	}

	private function isLoaded(Vector3 $pos) : bool{
		return $this->getWorld()->isChunkLoaded(((int) $pos->x) >> 4, ((int) $pos->z) >> 4);
	}

	/**
	 * Registers the saved claims, checks they still stand, claims a bed and a job site if needed, and updates food
	 * and breeding (vanilla dweller update, every 3-5 seconds).
	 */
	public function updateDweller() : void{
		$world = $this->getWorld();
		$poi = $world->getPoiManager();
		if(!$this->claimsRegistered){
			$this->claimsRegistered = true;
			if($this->bed !== null && !$poi->claim($this->bed, $this->id)){
				$this->bed = null;
			}
			if($this->jobSite !== null && !$poi->claim($this->jobSite, $this->id)){
				$this->jobSite = null;
			}
		}

		$bed = $this->bed;
		if($bed !== null && $this->isLoaded($bed)){
			$block = $world->getBlock($bed);
			if(!$block instanceof Bed || !$block->isHeadPart()){
				$this->releaseBed();
			}
		}
		$jobSite = $this->jobSite;
		if($jobSite !== null && $this->isLoaded($jobSite) && JobSites::getProfession($world->getBlock($jobSite)->getTypeId()) !== $this->profession){
			$this->releaseJobSite();
			if(!$this->hasTraded() && $this->profession !== self::PROFESSION_NITWIT){
				$this->setProfession(self::PROFESSION_UNEMPLOYED);
				$this->broadcastActorEvent(ActorEvent::VILLAGER_ANGRY);
			}
		}

		if($this->bed === null){
			$now = $this->server->getTick();
			foreach($this->unreachableBeds as $hash => $until){
				if($until <= $now){
					unset($this->unreachableBeds[$hash]);
				}
			}
			$free = $poi->findNearestFree(PoiType::BED, $this->location, self::BED_SEARCH_HORIZONTAL, self::BED_SEARCH_VERTICAL, null,
				fn(Vector3 $pos) => !isset($this->unreachableBeds[World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())])
			);
			if($free !== null && !AiBudget::tryPathSearch($now, $this->bedSearchWaitingSince)){
				//no path search left this tick (shared with all mobs): try again in a second, ahead of newer searches
				if($this->bedSearchWaitingSince < 0){
					$this->bedSearchWaitingSince = $now;
				}
				$this->dwellerTicks = min($this->dwellerTicks, 20 + mt_rand(0, 20));
			}elseif($free !== null){
				$this->bedSearchWaitingSince = -1;
				//vanilla AcquirePoi: only beds the villager can walk to (one candidate per update, like its 3-5 s retries)
				if($this->canWalkTo($free)){
					$this->claimBed($free);
				}else{
					$this->markBedUnreachable($free);
				}
			}
		}
		if($this->jobSite === null && $this->bed !== null && !$this->isBaby() && $this->profession !== self::PROFESSION_NITWIT){
			$profession = $this->profession;
			$free = $poi->findNearestFree(PoiType::JOB_SITE, $this->location, self::JOB_SITE_SEARCH_HORIZONTAL, self::JOB_SITE_SEARCH_VERTICAL,
				$profession === self::PROFESSION_UNEMPLOYED ? null : fn(int $typeId) => JobSites::getProfession($typeId) === $profession
			);
			if($free !== null){
				$this->claimJobSite($free);
			}
		}

		$this->syncDwelling();
		$this->bakeBread();
		if($this->isWillingToBreed()){
			$this->spendFood(self::BREEDING_FOOD_POINTS);
			$this->setInLove(self::LOVE_TICKS);
			$this->broadcastActorEvent(ActorEvent::LOVE_PARTICLES);
		}
		if($this->bed !== null && !$this->isBaby()){
			$this->trySummonGolem();
		}
	}

	/**
	 * The dwelling restriction (Bedrock move_towards_dwelling_restriction, explore_outskirts): the random walks of a
	 * villager with a bed stay within {@link VillagerV2::DWELLING_RADIUS} blocks of it, or farther if its job site is
	 * farther, using the mob home (see {@link \pocketmine\entity\HomeTrait}). Updated when the claims change.
	 */
	private function syncDwelling() : void{
		$bed = $this->bed;
		$home = $this->getHomePos();
		if($bed === null){
			if($home !== null){
				$this->setHomePos(null);
			}
			return;
		}
		if($home === null || !$home->equals($bed)){
			$this->setHomePos($bed);
		}
		$radius = self::DWELLING_RADIUS;
		if($this->jobSite !== null){
			$radius = max($radius, (int) ceil($this->jobSite->distance($bed)) + 8);
		}
		$this->dwellingRadius = $radius;
	}

	public function getHomeRadius() : int{
		return $this->bed !== null ? $this->dwellingRadius : 0;
	}

	public function isSleeping() : bool{
		return $this->sleepingIn !== null;
	}

	public function canBePushedBy(Entity $entity) : bool{
		//a villager sleeping in its bed stays in it, so that its sleep (pose, bed claim, waking up on the bed) holds. This is
		//a choice: villager_v2.json has minecraft:pushable_by_entity with no sleeping condition, and Java only halves the
		//push (LivingEntity.push(Entity) does nothing for a sleeping entity, its own pushEntities() still pushes it)
		return $this->sleepingIn === null && parent::canBePushedBy($entity);
	}

	/**
	 * Lies down in the claimed bed (the villager must be next to it). Returns false if there is no bed or someone else
	 * sleeps in it.
	 */
	public function startSleeping() : bool{
		$bedPos = $this->bed;
		if($bedPos === null){
			return false;
		}
		$world = $this->getWorld();
		$claimant = $world->getPoiManager()->getClaimant($bedPos);
		if($claimant !== null && $claimant !== $this->id){
			//another villager owns this bed (claims can change while a villager walks to it)
			$this->bed = null;
			return false;
		}
		$bed = $world->getBlock($bedPos);
		if(!$bed instanceof Bed || !$bed->isHeadPart() || self::findSleeperAt($world, $bedPos) !== null){
			return false;
		}
		if($bed->isOccupied()){
			foreach($world->getPlayers() as $player){
				$sleeping = $player->getSleepingPosition();
				if($sleeping !== null && $sleeping->equals($bedPos)){
					return false;
				}
			}
			if(self::findSleeperAt($world, $bedPos) !== null){
				return false;
			}
			//left occupied by a villager that was unloaded while asleep
		}
		$world->setBlock($bedPos, $bed->setOccupied(true));
		$this->sleepingIn = $bedPos;
		$this->getNavigation()->stop();
		$this->setMotion(Vector3::zero());
		$this->teleport(new Vector3($bedPos->x + 0.5, $bedPos->y + 0.5625, $bedPos->z + 0.5));
		$this->networkPropertiesDirty = true;
		return true;
	}

	/**
	 * Gets out of bed. A villager that slept until morning heals (Bedrock).
	 */
	public function wakeUp() : void{
		$bedPos = $this->sleepingIn;
		if($bedPos === null){
			return;
		}
		$this->sleepingIn = null;
		$this->networkPropertiesDirty = true;
		$world = $this->getWorld();
		if($this->isLoaded($bedPos)){
			$bed = $world->getBlock($bedPos);
			if($bed instanceof Bed && $bed->isOccupied()){
				$world->setBlock($bedPos, $bed->setOccupied(false));
			}
		}
		if($this->isAlive() && $this->getActivity() !== VillagerActivity::SLEEP){
			$this->setHealth($this->getMaxHealth());
		}
	}

	/**
	 * Returns the villager sleeping in the bed whose head is at the position, if any.
	 */
	public static function findSleeperAt(World $world, Vector3 $bedHead) : ?VillagerV2{
		$pos = $bedHead->floor();
		foreach($world->getNearbyEntities(new AxisAlignedBB($pos->x - 1, $pos->y - 1, $pos->z - 1, $pos->x + 2, $pos->y + 2, $pos->z + 2)) as $entity){
			if($entity instanceof VillagerV2 && $entity->sleepingIn !== null && $entity->sleepingIn->equals($pos)){
				return $entity;
			}
		}
		return null;
	}

	// ---- food and breeding ----------------------------------------------------------------------------------------

	public function getInventory() : SimpleInventory{
		return $this->inventory;
	}

	/**
	 * Food points of an item: bread 4, carrot, potato and beetroot 1.
	 */
	public static function getFoodPoints(Item $item) : int{
		return match($item->getTypeId()){
			ItemTypeIds::BREAD => 4,
			ItemTypeIds::CARROT, ItemTypeIds::POTATO, ItemTypeIds::BEETROOT => 1,
			default => 0
		};
	}

	public function getFoodPointsInInventory() : int{
		$points = 0;
		foreach($this->inventory->getContents() as $item){
			$points += self::getFoodPoints($item) * $item->getCount();
		}
		return $points;
	}

	/**
	 * Whether the villager is a "peasant" (Bedrock behavior_peasant shareables: unemployed villagers, nitwits and
	 * farmers; the other professions taken at a job site get behavior_non_peasant).
	 */
	public function isPeasant() : bool{
		return $this->profession === self::PROFESSION_UNEMPLOYED || $this->profession === self::PROFESSION_NITWIT || $this->profession === self::PROFESSION_FARMER;
	}

	/**
	 * How many of the item the villager wants to hold (Bedrock minecraft:shareables want_amount), 0 for items it
	 * doesn't pick up: everybody wants 3 bread; non-peasants 12 carrots, potatoes and beetroots; peasants 60 of these,
	 * 64 wheat and beetroot seeds and 45 wheat (baked into bread); farmers also 64 torchflower seeds, pitcher pods and
	 * bone meal.
	 */
	public function getWantAmount(Item $item) : int{
		$peasant = $this->isPeasant();
		return match($item->getTypeId()){
			ItemTypeIds::BREAD => 3,
			ItemTypeIds::CARROT, ItemTypeIds::POTATO, ItemTypeIds::BEETROOT => $peasant ? 60 : 12,
			ItemTypeIds::WHEAT_SEEDS, ItemTypeIds::BEETROOT_SEEDS => $peasant ? 64 : 0,
			ItemTypeIds::WHEAT => $peasant ? 45 : 0,
			ItemTypeIds::TORCHFLOWER_SEEDS, ItemTypeIds::PITCHER_POD, ItemTypeIds::BONE_MEAL => $this->profession === self::PROFESSION_FARMER ? 64 : 0,
			default => 0
		};
	}

	/**
	 * Number of the item the villager would pick up now (its want amount minus what it holds).
	 */
	public function getWantedCount(Item $item) : int{
		$want = $this->getWantAmount($item);
		if($want <= 0){
			return 0;
		}
		$have = 0;
		foreach($this->inventory->getContents() as $held){
			if($held->getTypeId() === $item->getTypeId()){
				$have += $held->getCount();
			}
		}
		return max(0, $want - $have);
	}

	/**
	 * Whether the villager picks up the item now (see {@link VillagerV2::getWantAmount()}).
	 */
	public function wantsItem(Item $item) : bool{
		return $this->getWantedCount($item) > 0;
	}

	/**
	 * Puts the item in the villager's inventory; what doesn't fit is dropped.
	 */
	public function addToInventory(Item $item) : void{
		foreach($this->inventory->addItem($item) as $leftover){
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $leftover);
		}
	}

	private function spendFood(int $points) : void{
		foreach($this->inventory->getContents() as $slot => $item){
			$value = self::getFoodPoints($item);
			while($points > 0 && $value > 0 && !$item->isNull()){
				$item->pop();
				$points -= $value;
			}
			$this->inventory->setItem($slot, $item);
			if($points <= 0){
				return;
			}
		}
	}

	/**
	 * Peasants (unemployed villagers, nitwits and farmers) bake every 3 wheat into a bread (shareables craft_into).
	 */
	private function bakeBread() : void{
		if(!$this->isPeasant()){
			return;
		}
		$wheat = 0;
		foreach($this->inventory->getContents() as $item){
			if($item->getTypeId() === ItemTypeIds::WHEAT){
				$wheat += $item->getCount();
			}
		}
		$breads = intdiv($wheat, 3);
		if($breads > 0){
			$this->inventory->removeItem(VanillaItems::WHEAT()->setCount($breads * 3));
			$this->addToInventory(VanillaItems::BREAD()->setCount($breads));
		}
	}

	/**
	 * Whether the villager has less food than it wants (less than 12 food points).
	 */
	public function wantsFood() : bool{
		return !$this->isBaby() && $this->getFoodPointsInInventory() < self::BREEDING_FOOD_POINTS;
	}

	/**
	 * Food the villager can give away: half of a stack of at least 6 bread or 24 carrots, potatoes or beetroots.
	 */
	public function getShareableFood() : ?Item{
		foreach($this->inventory->getContents() as $item){
			$points = self::getFoodPoints($item);
			if($points > 0 && $item->getCount() * $points >= 24){
				return (clone $item)->setCount(intdiv($item->getCount(), 2));
			}
		}
		return null;
	}

	/**
	 * Throws half of a food surplus towards the other villager.
	 */
	public function throwFoodTo(VillagerV2 $receiver) : void{
		$food = $this->getShareableFood();
		if($food === null){
			return;
		}
		$this->inventory->removeItem($food);
		$diff = $receiver->getPosition()->subtractVector($this->location);
		//items slide about 15 times their initial speed: land about a block before the receiver
		$motion = $diff->normalize()->multiply(min(0.3, max(0.05, ($diff->length() - 1.0) / 15)))->add(0, 0.2, 0);
		$entity = $this->getWorld()->dropItem($this->location->add(0, $this->getEyeHeight() - 0.3, 0), $food, $motion, 20);
		if($entity !== null){
			$this->thrownItems[$entity->getId()] = $this->server->getTick() + self::THROWN_ITEM_TICKS;
		}
	}

	/**
	 * Picks up the wanted items within reach (up to the want amounts) and remembers the nearest wanted item within
	 * {@link VillagerV2::PICKUP_MAX_DISTANCE} blocks, which {@link PickupItemsGoal} walks to (behavior.pickup_items).
	 * One entity query every 10 ticks.
	 */
	private function pickUpItems() : void{
		$this->pickupTarget = null;
		if($this->isBaby() || $this->sleepingIn !== null || !GameRules::allowsMobGriefing($this->getWorld())){
			return;
		}
		$now = $this->server->getTick();
		foreach($this->thrownItems as $id => $until){
			if($until <= $now){
				unset($this->thrownItems[$id]);
			}
		}
		$reach = $this->boundingBox->expandedCopy(1.0, 0.5, 1.0);
		$d = self::PICKUP_MAX_DISTANCE;
		$bestDist = 0.0;
		foreach($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy($d, 1.0, $d), $this) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn() || $entity->getPickupDelay() > 0 || isset($this->thrownItems[$entity->getId()])){
				continue;
			}
			$item = $entity->getItem();
			$wanted = $this->getWantedCount($item);
			if($wanted <= 0){
				continue;
			}
			if(!$entity->getBoundingBox()->intersectsWith($reach)){
				$dist = $entity->getPosition()->distanceSquared($this->location);
				if($this->pickupTarget === null || $dist < $bestDist){
					$this->pickupTarget = $entity;
					$bestDist = $dist;
				}
				continue;
			}
			$taken = min($wanted, $item->getCount());
			$leftovers = $this->inventory->addItem((clone $item)->setCount($taken));
			foreach($leftovers as $leftover){
				$taken -= $leftover->getCount();
			}
			if($taken >= $item->getCount()){
				$entity->flagForDespawn();
			}elseif($taken > 0){
				$entity->setStackSize($item->getCount() - $taken);
			}
		}
	}

	/**
	 * Wanted item a few blocks away found by the last pickup scan, if it is still there.
	 *
	 * @internal
	 */
	public function getPickupTarget() : ?ItemEntity{
		$target = $this->pickupTarget;
		if($target !== null && ($target->isClosed() || $target->isFlaggedForDespawn())){
			$this->pickupTarget = $target = null;
		}
		return $target;
	}

	/**
	 * Whether the villager becomes willing to breed now: an adult (not on cooldown) with enough food, awake and outside
	 * its sleeping hours, with a free bed in the village. Bedrock adds make_and_receive_love with the work, gather,
	 * wander and home schedules and only removes it with the bed schedule.
	 */
	public function isWillingToBreed() : bool{
		if($this->isBaby() || $this->age !== 0 || $this->isInLove() || $this->sleepingIn !== null || $this->getFoodPointsInInventory() < self::BREEDING_FOOD_POINTS){
			return false;
		}
		if($this->getActivity() === VillagerActivity::SLEEP){
			return false;
		}
		return $this->countFreeBeds() > 0;
	}

	/**
	 * Unclaimed beds around the villager (in the village).
	 */
	public function countFreeBeds() : int{
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		$h = self::BED_SEARCH_HORIZONTAL;
		$v = self::BED_SEARCH_VERTICAL;
		return $this->getWorld()->getPoiManager()->countBeds($x - $h, $y - $v, $z - $h, $x + $h, $y + $v, $z + $h)[1];
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new VillagerV2($location, CompoundTag::create()->setInt(self::TAG_AGE, self::BABY_AGE));
		$baby->setVillagerType(self::typeForBiome($location->getWorld(), $location));
		return $baby;
	}

	public function breed(Animal $partner) : ?Animal{
		$baby = $this->spawnBaby($partner);
		foreach([$this, $partner] as $parent){
			$parent->setAge(self::BREED_COOLDOWN);
			$parent->setInLove(0);
		}
		return $baby;
	}

	public function getXpDropAmount() : int{
		return 0;
	}

	// ---- gossip, reputation, golems ------------------------------------------------------------------------------

	public function getGossips() : Gossips{
		return $this->gossips;
	}

	public function getPlayerReputation(Player $player) : int{
		return $this->gossips->getReputation($player->getUniqueId()->toString());
	}

	/**
	 * Called when a player cured this villager from a zombie villager: major and minor positive gossip (the cure
	 * discount).
	 */
	public function onCuredBy(string $playerUuid) : void{
		$this->gossips->add($playerUuid, GossipType::MAJOR_POSITIVE, self::GOSSIP_CURE_MAJOR);
		$this->gossips->add($playerUuid, GossipType::MINOR_POSITIVE, self::GOSSIP_CURE_MINOR);
	}

	/**
	 * Two villagers talk: they share their gossip.
	 */
	public function gossipWith(VillagerV2 $other) : void{
		$this->gossips->shareWith($other->gossips);
		$other->gossips->shareWith($this->gossips);
	}

	/**
	 * Whether a village with these numbers summons an iron golem (Bedrock): at least 10 adult villagers per golem it
	 * would then have, 20 beds, and 75% of its villagers having worked in the last day.
	 *
	 * @internal
	 */
	public static function canVillageSummonGolem(int $villagers, int $worked, int $golems, int $beds) : bool{
		return $villagers > 0 && $villagers >= self::GOLEM_VILLAGERS_PER_GOLEM * ($golems + 1) && $worked >= $villagers * self::GOLEM_WORKED_RATIO && $beds >= self::GOLEM_MIN_BEDS;
	}

	/**
	 * Summons an iron golem in the village of the villager if it meets the Bedrock conditions (see
	 * {@link VillagerV2::canVillageSummonGolem()}), at any time of day: each village is checked once every
	 * {@link VillagerV2::GOLEM_CHECK_INTERVAL} ticks, and the golem appears in the 17x13x17 box around the village
	 * centre. Called by the dweller update of the villagers; working out the village is gated per 32x32 area, so that
	 * crowds of villagers don't look for it over and over.
	 */
	public function trySummonGolem() : ?IronGolem{
		$world = $this->getWorld();
		$poi = $world->getPoiManager();
		$now = $this->server->getTick();
		if(!$poi->tryGolemCheck((int) floor($this->location->x), (int) floor($this->location->z), $now, self::GOLEM_CHECK_INTERVAL)){
			return null;
		}
		$village = VillageScanner::findVillageAt($world, $this->location);
		if($village === null){
			return null;
		}
		$center = $village->getCenter();
		if(!$poi->tryVillageGolemCheck($center, $now, self::GOLEM_CHECK_INTERVAL)){
			return null;
		}
		$villagers = 0;
		$worked = 0;
		$golems = 0;
		foreach($world->getNearbyEntities($village->getBounds()) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive() && !$entity->isBaby()){
				++$villagers;
				if($entity->hasWorkedRecently()){
					++$worked;
				}
			}elseif($entity instanceof IronGolem && $entity->isAlive()){
				++$golems;
			}
		}
		if(!self::canVillageSummonGolem($villagers, $worked, $golems, count($village->getBeds()))){
			return null;
		}
		return self::spawnGolemAround($world, $center);
	}

	/**
	 * Spawns an iron golem on a free spot of the 17x13x17 box around the village centre (10 tries), returns it or null.
	 *
	 * @internal
	 */
	public static function spawnGolemAround(World $world, Vector3 $center) : ?IronGolem{
		$x = (int) floor($center->x);
		$y = (int) floor($center->y);
		$z = (int) floor($center->z);
		$h = self::GOLEM_SPAWN_HORIZONTAL;
		$v = self::GOLEM_SPAWN_VERTICAL;
		for($attempt = 0; $attempt < 10; ++$attempt){
			$gx = $x + mt_rand(-$h, $h);
			$gz = $z + mt_rand(-$h, $h);
			if(!$world->isChunkLoaded($gx >> 4, $gz >> 4)){
				continue;
			}
			for($gy = $y + $v; $gy >= $y - $v; --$gy){
				if(!$world->isInWorld($gx, $gy + 2, $gz) || !$world->isInWorld($gx, $gy - 1, $gz)){
					continue;
				}
				if($world->getBlockAt($gx, $gy - 1, $gz)->isSolid() && self::isFree($world, $gx, $gy, $gz)){
					$golem = new IronGolem(new Location($gx + 0.5, $gy, $gz + 0.5, $world, mt_rand(0, 359), 0));
					$golem->setVillageCreated(true);
					$golem->spawnToAll();
					return $golem;
				}
			}
		}
		return null;
	}

	private static function isFree(World $world, int $x, int $y, int $z) : bool{
		for($i = 0; $i < 3; ++$i){
			$block = $world->getBlockAt($x, $y + $i, $z);
			if($block->isSolid() || $block instanceof Liquid){
				return false;
			}
		}
		return true;
	}

	// ---- events -------------------------------------------------------------------------------------------------------

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled()){
			return;
		}
		$this->wakeUp();
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Living){
				$r = self::GOLEM_DEFEND_RANGE;
				$loc = $this->location;
				foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
					if($entity instanceof IronGolem){
						$entity->onVillagerAttacked($damager);
					}
				}
			}
			if($damager instanceof Player){
				$this->gossips->add($damager->getUniqueId()->toString(), GossipType::MINOR_NEGATIVE, self::GOSSIP_HIT);
				if($this->isAlive()){
					$this->broadcastActorEvent(ActorEvent::VILLAGER_ANGRY);
				}
			}
		}
	}

	protected function onDeath() : void{
		$killer = $this->lastHurtByPlayer;
		//a pet's kill is the pet's murder, not its owner's (Java Villager.die tells witnesses about the killing entity)
		if($killer !== null && $this->wasRecentlyHitByPlayerDirectly()){
			$uuid = $killer->getUniqueId()->toString();
			$r = self::KILL_WITNESS_RANGE;
			$loc = $this->location;
			foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
				if($entity instanceof VillagerV2 && $entity->isAlive()){
					$entity->gossips->add($uuid, GossipType::MAJOR_NEGATIVE, self::GOSSIP_KILL);
				}
			}
		}
		$this->wakeUp();
		$zombified = $this->shouldBeZombified();
		if($zombified){
			//remembered with its bed and job site, which it claims again if they are still free when cured
			ZombieVillager::fromVillager($this)->spawnToAll();
		}
		$this->releaseBed();
		$this->releaseJobSite();
		parent::onDeath();
		if($zombified){
			$this->flagForDespawn(); //turned into the zombie villager: no corpse
		}
	}

	/**
	 * Villagers killed by zombies (not zombified piglins) turn into zombie villagers: half of the time on Normal,
	 * always on Hard (vanilla Zombie.killedEntity).
	 */
	private function shouldBeZombified() : bool{
		$cause = $this->getLastDamageCause();
		if(!$cause instanceof EntityDamageByEntityEvent){
			return false;
		}
		$killer = $cause->getDamager();
		if(!$killer instanceof Zombie || $killer instanceof ZombifiedPiglin){
			return false;
		}
		return match($this->getWorld()->getDifficulty()){
			World::DIFFICULTY_HARD => true,
			World::DIFFICULTY_NORMAL => mt_rand(0, 1) === 0,
			default => false
		};
	}

	/**
	 * Vanilla: lightning turns a villager into a witch (not on Peaceful), which frees its bed and job site.
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			parent::onStruckByLightning($bolt);
			return;
		}
		$this->wakeUp();
		$this->releaseBed();
		$this->releaseJobSite();
		$this->tradingPlayer?->removeCurrentWindow();
		MobConversion::convertByLightning($this, new Witch($this->location->asLocation()));
	}

	protected function onDispose() : void{
		//unloaded: free the claims for this session, the NBT keeps them
		$poi = $this->getWorld()->getPoiManager();
		if($this->bed !== null){
			$poi->release($this->bed, $this->id);
		}
		if($this->jobSite !== null){
			$poi->release($this->jobSite, $this->id);
		}
		parent::onDispose();
	}

	public function isCelebrating() : bool{
		return $this->celebrating;
	}

	/**
	 * Villagers celebrate when their village wins a raid (Bedrock minecraft:celebrate, celebrate_survive): they go
	 * outdoors (see {@link MoveOutdoorsGoal}) and launch a firework every 2-7 seconds while nothing is above them. The
	 * celebration stops by itself after {@link VillagerV2::CELEBRATE_TICKS} ticks.
	 */
	public function setCelebrating(bool $celebrating) : void{
		if($celebrating){
			$this->celebrateUntil = $this->server->getTick() + self::CELEBRATE_TICKS;
		}
		if($celebrating !== $this->celebrating){
			$this->celebrating = $celebrating;
			$this->networkPropertiesDirty = true;
			if($celebrating){
				$this->playMobSound(LevelSoundEvent::CELEBRATE);
				$this->fireworkTicks = mt_rand(self::FIREWORK_INTERVAL_MIN, self::FIREWORK_INTERVAL_MAX);
			}
		}
	}

	/**
	 * Whether no block is above the villager (it sees the sky). One height map lookup.
	 */
	public function canSeeSky() : bool{
		return VillagerGoal::isUnderSky($this->getWorld(), $this->location);
	}

	/**
	 * Launches a celebration firework: a burst of one random colour that flies for 1-3 "flight durations" and hurts
	 * nobody (Java CelebrateVillagersSurvivedRaid; Bedrock fireworks only hurt when shot from a crossbow).
	 */
	public function launchFirework() : FireworkRocket{
		$colors = DyeColor::cases();
		$explosion = new FireworkRocketExplosion(FireworkRocketType::BURST, [$colors[mt_rand(0, count($colors) - 1)]]);
		$flight = mt_rand(0, 2);
		$location = Location::fromObject($this->location->add(0, $this->getEyeHeight(), 0), $this->getWorld(), mt_rand(0, 359), 90);
		$firework = new CelebrationFirework($location, ($flight + 1) * 10 + mt_rand(0, 5) + mt_rand(0, 6), [$explosion]);
		$firework->setOwningEntity($this);
		$firework->setMotion(new Vector3(0, 0.05, 0));
		$firework->spawnToAll();
		return $firework;
	}

	/**
	 * A bell rang nearby: the villager hides in a bed for {@link VillagerV2::HIDE_TICKS} ticks (Bedrock minecraft:hide,
	 * behavior.hide; see {@link HideFromRaidGoal}).
	 */
	public function hearBell() : void{
		if($this->isAlive() && $this->sleepingIn === null){
			$this->hideUntil = $this->server->getTick() + self::HIDE_TICKS;
		}
	}

	/**
	 * Whether the villager is hiding because a bell rang.
	 */
	public function isHidingFromBell() : bool{
		return $this->hideUntil > $this->server->getTick();
	}

	/**
	 * Makes the villagers within {@link VillagerV2::BELL_HEARING_RANGE} blocks of a ringing bell hide.
	 *
	 * @internal Called by the bell block.
	 */
	public static function onBellRung(World $world, Vector3 $bell) : void{
		$r = self::BELL_HEARING_RANGE;
		$c = $bell->add(0.5, 0.5, 0.5);
		foreach($world->getNearbyEntities(new AxisAlignedBB($c->x - $r, $c->y - $r, $c->z - $r, $c->x + $r, $c->y + $r, $c->z + $r)) as $entity){
			if($entity instanceof VillagerV2 && $entity->location->distanceSquared($c) <= $r * $r){
				$entity->hearBell();
			}
		}
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->canTradeWith($player)){
			$this->playMobSound(LevelSoundEvent::HAGGLE_NO);
			return false;
		}
		$this->getNavigation()->stop();
		return $player->setCurrentWindow(new TradeInventory($this, $player));
	}

	protected function tickAi(int $currentTick) : void{
		$this->freshSpawn = false;
		parent::tickAi($currentTick);
		if($this->celebrating){
			if($currentTick >= $this->celebrateUntil){
				$this->setCelebrating(false); //minecraft:stop_celebrating
			}elseif(--$this->fireworkTicks <= 0){
				$this->fireworkTicks = mt_rand(self::FIREWORK_INTERVAL_MIN, self::FIREWORK_INTERVAL_MAX);
				if($this->sleepingIn === null && $this->canSeeSky()){
					$this->launchFirework();
				}
			}
		}
		if(--$this->dwellerTicks <= 0){
			$this->dwellerTicks = self::DWELLER_INTERVAL + mt_rand(0, self::DWELLER_INTERVAL_VARIANT);
			$this->updateDweller();
		}
		if(--$this->pickupTicks <= 0){
			$this->pickupTicks = 10;
			$this->pickUpItems();
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(($this->gossipDecayTicks -= $tickDiff) <= 0){
			$this->gossipDecayTicks = Gossips::DECAY_INTERVAL;
			$this->gossips->decay();
		}
		$bed = $this->sleepingIn;
		if($bed !== null && $this->isAlive()){
			//stay in bed
			$this->motion = Vector3::zero();
		}
		if($this->isAlive() && !$this->closed){
			$this->doors ??= new DoorInteraction($this);
			$this->doors->tick();
		}
		return $hasUpdate;
	}

	/**
	 * Villagers path through doors and open them (vanilla navigation can_open_doors, see {@link DoorInteraction}), and
	 * down drops of a block at most (vanilla minecraft:preferred_path max_fall_blocks 1; 3 for most mobs).
	 */
	protected function createNavigation() : PathNavigator{
		$navigation = new PathNavigator($this, 1);
		$navigation->getPathFinder()->setCanOpenDoors(true);
		return $navigation;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->profession);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->type);
		$properties->setInt(EntityMetadataProperties::SKIN_ID, $this->skin);
		$properties->setInt(EntityMetadataProperties::TRADE_TIER, $this->tradeTier);
		$properties->setInt(EntityMetadataProperties::MAX_TRADE_TIER, self::MAX_TIER);
		$properties->setInt(EntityMetadataProperties::TRADE_XP, $this->tradeExp);
		$properties->setLong(EntityMetadataProperties::TRADING_PLAYER_EID, $this->tradingPlayer?->getId() ?? 0);
		$properties->setGenericFlag(EntityMetadataFlags::CELEBRATING, $this->celebrating);
		$properties->setGenericFlag(EntityMetadataFlags::SLEEPING, $this->sleepingIn !== null);
		if($this->sleepingIn !== null){
			$properties->setBlockPos(EntityMetadataProperties::PLAYER_BED_POSITION, BlockPosition::fromVector3($this->sleepingIn));
		}
	}
}
