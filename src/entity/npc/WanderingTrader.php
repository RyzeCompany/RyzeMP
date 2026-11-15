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

use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\TraderLlama;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Illager;
use pocketmine\entity\monster\Vex;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\TradeInventory;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_map;
use function count;
use function max;
use function mt_rand;

/**
 * Bedrock values (entities/wandering_trader.json): 20 health, 0.6x1.9, movement 0.5 (0.6x when strolling or
 * panicking), runs (x0.6) from zombies, zombie villagers, zombified piglins, illagers, vexes and zoglins within 6
 * blocks, and strolls within 16 blocks of where it spawned (home restriction). Has two "buy" trades, two special and
 * five common ones picked from the vanilla table (trading/economy_trades/wandering_trader_trades.json, see
 * {@link VillagerTrades}); it has no levels.
 *
 * Every new trader (world spawner, spawn egg, command) comes with two adult trader llamas on leads it holds (vanilla
 * spawn_entity should_leash, see {@link TraderLlama}); llamas on its leads within 10 blocks attack whoever hurts it.
 * It despawns, with the llamas still on its leads (remove_child_entities), once no player is within 24 blocks and
 * either its timer (2 or 3 days: 48000 or 72000 ticks while loaded) ran out or all its trades are used up, even if
 * named (Bedrock).
 *
 * behavior.drink_potion: when running from a mob or just hurt (by a mob, or by anyone between dusk and midnight) and
 * visible, it drinks Invisibility (Long Invisibility from dusk to midnight), walking slower (-0.2) for 32 ticks;
 * behavior.drink_milk: invisible in the daytime and not running away, it drinks milk, which clears its effects. These
 * are checked once a second. No drops; no XP.
 */
class WanderingTrader extends Mob implements Trader{
	use TradeItemDisplayTrait;

	public const TAG_OFFERS = "Offers"; //TAG_List<TAG_Compound>
	public const TAG_DESPAWN_DELAY = "DespawnDelay"; //TAG_Int
	/** It only despawns when no player is this close. */
	public const DESPAWN_PLAYER_DISTANCE = 24;
	/** Vanilla timer random_time_choices: 2400 or 3600 seconds. */
	public const DESPAWN_DELAYS = [48000, 72000];
	/** Home restriction_radius. */
	public const HOME_RADIUS = 16;
	/** Ticks spent drinking a potion or milk (Java UseItemGoal, the item's use duration). */
	public const DRINK_TICKS = 32;
	/** How long it stays scared (minecraft:angry duration 5 s) after being hurt. */
	private const SCARED_TICKS = 100;
	private const MOVEMENT_SPEED = 0.5;
	/** drink_potion speed_modifier. */
	private const DRINKING_SPEED_MODIFIER = -0.2;

	/**
	 * @var TradeOffer[]
	 * @phpstan-var list<TradeOffer>
	 */
	private array $offers = [];
	private ?Player $tradingPlayer = null;
	/** Ticks left before it despawns, 0 = never. */
	private int $despawnDelay = 0;
	/** Whether its llamas are still to be spawned (a new trader, on its first tick). */
	private bool $llamasPending = false;
	/** Whether the avoid goal runs (Bedrock is_avoiding_mobs). */
	private bool $avoidingMobs = false;
	/** Server tick until which it is scared after being hurt, and whether a player hurt it. */
	private int $scaredUntil = 0;
	private bool $scaredByPlayer = false;
	/** Potion being drunk (null with milk), ticks left. */
	private ?PotionType $drinkingPotion = null;
	private int $drinkTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::WANDERING_TRADER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	public function getName() : string{
		return "Wandering Trader";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(self::MOVEMENT_SPEED);
		parent::initEntity($nbt);
		$this->setHomeRestriction(self::HOME_RADIUS);
		if($nbt->getTag(self::TAG_DESPAWN_DELAY) === null){
			//a new trader: the timer is a base component (every trader despawns) and it brings its llamas
			$this->despawnDelay = self::DESPAWN_DELAYS[mt_rand(0, count(self::DESPAWN_DELAYS) - 1)];
			$this->llamasPending = true;
		}else{
			$this->despawnDelay = $nbt->getInt(self::TAG_DESPAWN_DELAY);
		}
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
		}else{
			$this->offers = VillagerTrades::createWanderingTraderOffers();
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_DESPAWN_DELAY, $this->despawnDelay);
		$nbt->setTag(self::TAG_OFFERS, new ListTag(array_map(fn(TradeOffer $o) => $o->toNbt(), $this->offers)));
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 0.6));
		$this->goalSelector->addGoal(1, new class($this) extends Goal{
			public function __construct(private WanderingTrader $trader){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

			public function canStart() : bool{ return $this->trader->getTradingPlayer() !== null; }

			public function start() : void{ $this->trader->getNavigation()->stop(); }

			public function tick() : void{
				$player = $this->trader->getTradingPlayer();
				if($player !== null){
					$this->trader->getLookControl()->setLookAtEntity($player);
				}
			}
		});
		$this->goalSelector->addGoal(3, new TradeInterestGoal($this,
			fn(Player $player) : bool => $this->canTradeWith($player) && $this->getTradingPlayer() === null && !$this->isDrinking(),
			function(?Item $item) : void{
				if(!$this->isDrinking()){ //the hand holds the drink
					$this->setShownTradeItem($item);
				}
			}
		));
		//no search in worlds without any of the mobs it avoids
		$this->goalSelector->addGoal(2, new class($this, self::isAvoided(...), 6.0, 0.6, 0.6, fn() => self::worldHasAvoided($this->getWorld()), fn(bool $avoiding) => $this->avoidingMobs = $avoiding) extends AvoidEntityGoal{
			/**
			 * @phpstan-param \Closure(Entity) : bool $filter
			 * @phpstan-param \Closure() : bool $condition
			 * @phpstan-param \Closure(bool) : mixed $onAvoiding
			 */
			public function __construct(Mob $mob, \Closure $filter, float $maxDistance, float $walkSpeed, float $sprintSpeed, \Closure $condition, private \Closure $onAvoiding){
				parent::__construct($mob, $filter, $maxDistance, $walkSpeed, $sprintSpeed, $condition);
			}

			public function start() : void{
				parent::start();
				($this->onAvoiding)(true);
			}

			public function stop() : void{
				parent::stop();
				($this->onAvoiding)(false);
			}
		});
		$this->goalSelector->addGoal(6, new MoveTowardsHomeRestrictionGoal($this, 0.6, retryCooldown: 100));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
	}

	/**
	 * Ticks left before the trader despawns (it waits for players to leave), 0 if it never despawns.
	 */
	public function getDespawnDelay() : int{
		return $this->despawnDelay;
	}

	public function setDespawnDelay(int $ticks) : void{
		$this->despawnDelay = max(0, $ticks);
	}

	/**
	 * Mobs it runs from (avoid_mob_type families zombie, zombie_villager, zombie_pigman, illager, vex, zoglin).
	 */
	public static function isAvoided(Entity $entity) : bool{
		return $entity instanceof Zombie || $entity instanceof Illager || $entity instanceof Vex || $entity instanceof Zoglin;
	}

	/**
	 * Whether the world holds any entity {@link WanderingTrader::isAvoided()} accepts.
	 */
	private static function worldHasAvoided(World $world) : bool{
		return $world->hasEntitiesOfClass(Zombie::class) || $world->hasEntitiesOfClass(Illager::class) || $world->hasEntitiesOfClass(Vex::class) ||
			$world->hasEntitiesOfClass(Zoglin::class);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if($this->llamasPending){
			$this->spawnTraderLlamas();
		}
		if($this->despawnDelay > 0){
			$this->despawnDelay = max(1, $this->despawnDelay - $tickDiff);
		}
		if($this->drinkTicks > 0){
			$this->drinkTicks -= $tickDiff;
			if($this->drinkTicks <= 0){
				$this->finishDrinking();
			}
			$hasUpdate = true;
		}
		if(($this->server->getTick() + $this->id) % 20 === 0){
			if(($this->despawnDelay === 1 || $this->isSoldOut()) && $this->getTradingPlayer() === null &&
				$this->getWorld()->getNearestEntity($this->location, self::DESPAWN_PLAYER_DISTANCE, Player::class) === null
			){
				$this->despawn();
				return false;
			}
			if($this->drinkTicks <= 0 && $this->getTradingPlayer() === null){
				//trade_with_player (priority 1) keeps drink_potion (1) and drink_milk (5) from starting
				$this->pickDrink();
			}
		}
		return $hasUpdate;
	}

	/**
	 * Whether every trade is used up (vanilla has_trade_supply false). Wandering traders never restock.
	 */
	public function isSoldOut() : bool{
		if(count($this->offers) === 0){
			return false;
		}
		foreach($this->offers as $offer){
			if(!$offer->isDisabled()){
				return false;
			}
		}
		return true;
	}

	/**
	 * Despawns the trader and the llamas on its leads (despawn remove_child_entities).
	 */
	private function despawn() : void{
		foreach($this->getLeashedLlamas() as $llama){
			$llama->flagForDespawn();
		}
		$this->flagForDespawn();
	}

	/**
	 * Returns the llamas (trader llamas or others) on leads it holds; they are always within 10 blocks.
	 *
	 * @return Llama[]
	 * @phpstan-return list<Llama>
	 */
	public function getLeashedLlamas() : array{
		$llamas = [];
		$loc = $this->location;
		$r = Mob::LEASH_BREAK_DISTANCE;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			if($entity instanceof Llama && !$entity->isClosed() && $entity->isLeashedTo($this)){
				$llamas[] = $entity;
			}
		}
		return $llamas;
	}

	/**
	 * Spawns its two trader llamas on leads it holds, where it stands (vanilla spawn_entity, spawn_event
	 * from_wandering_trader). New traders do it on their first tick; the world spawner does it right away with
	 * $filter, which may veto a llama (returns false: the llama isn't added). Returns the llamas spawned.
	 *
	 * @phpstan-param (\Closure(TraderLlama) : bool)|null $filter
	 * @return TraderLlama[]
	 * @phpstan-return list<TraderLlama>
	 */
	public function spawnTraderLlamas(?\Closure $filter = null) : array{
		$this->llamasPending = false;
		$llamas = [];
		$loc = $this->location;
		for($i = 0; $i < 2; ++$i){
			$llama = new TraderLlama(new Location($loc->x, $loc->y, $loc->z, $this->getWorld(), mt_rand(0, 359), 0));
			$llama->setFromWanderingTrader(true);
			$llama->setSpawnReason($this->getSpawnReason());
			if($filter !== null && (!$filter($llama) || $llama->isClosed())){
				$llama->close();
				continue;
			}
			$llama->setLeashHolder($this);
			$llama->spawnToAll();
			$llamas[] = $llama;
		}
		return $llamas;
	}

	public function isDrinking() : bool{
		return $this->drinkTicks > 0;
	}

	/**
	 * Starts drinking a potion, or milk (null), if the drink_potion or drink_milk filters pass.
	 */
	private function pickDrink() : void{
		$visible = !$this->effectManager->has(VanillaEffects::INVISIBILITY());
		$time = $this->getWorld()->getTimeOfDay();
		if($visible){
			$scared = $this->server->getTick() < $this->scaredUntil;
			if($time >= World::TIME_SUNSET && $time < World::TIME_MIDNIGHT){
				if($this->avoidingMobs || $scared){
					$this->startDrinking(PotionType::LONG_INVISIBILITY);
				}
			}elseif($this->avoidingMobs || ($scared && !$this->scaredByPlayer)){
				$this->startDrinking(PotionType::INVISIBILITY);
			}
		}elseif(!$this->avoidingMobs && ($time < World::TIME_NIGHT || $time >= World::TIME_SUNRISE)){
			$this->startDrinking(null);
		}
	}

	/**
	 * Drinks the potion, or milk (null): the drink is shown in its hand for {@link WanderingTrader::DRINK_TICKS}.
	 */
	public function startDrinking(?PotionType $potion) : void{
		$this->drinkingPotion = $potion;
		$this->drinkTicks = self::DRINK_TICKS;
		$this->setShownTradeItem($potion !== null ? VanillaItems::POTION()->setType($potion) : VanillaItems::MILK_BUCKET());
		if($potion !== null){
			$this->setMovementSpeed(self::MOVEMENT_SPEED + self::DRINKING_SPEED_MODIFIER);
		}
		$this->playMobSound($potion !== null ? LevelSoundEvent::DRINK : LevelSoundEvent::DRINK_MILK);
		$this->networkPropertiesDirty = true;
	}

	private function finishDrinking() : void{
		$potion = $this->drinkingPotion;
		$this->drinkingPotion = null;
		$this->drinkTicks = 0;
		$this->setShownTradeItem(null);
		$this->setMovementSpeed(self::MOVEMENT_SPEED);
		$this->networkPropertiesDirty = true;
		if($potion !== null){
			foreach($potion->getEffects() as $effect){
				$this->effectManager->add($effect);
			}
			$this->playMobSound(LevelSoundEvent::DISAPPEARED);
		}else{
			$this->effectManager->clear();
			$this->playMobSound(LevelSoundEvent::REAPPEARED);
		}
	}

	public function getTradeOffers() : array{
		return $this->offers;
	}

	public function getTradeTier() : int{
		return 0;
	}

	public function getTierExpRequirements() : array{
		return [0];
	}

	public function getTradeDisplayName() : string{
		return "entity.wandering_trader.name";
	}

	/**
	 * Bedrock behavior.trade_with_player filters: not in water, and on the ground.
	 */
	public function canTradeWith(Player $player) : bool{
		return $this->isAlive() && count($this->offers) > 0 && !$this->isInWater() && $this->onGround && ($this->tradingPlayer === null || $this->tradingPlayer === $player || !$this->tradingPlayer->isConnected());
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

	public function getPlayerReputation(Player $player) : int{
		return 0; //wandering traders don't gossip
	}

	public function onTrade(Player $player, TradeOffer $offer, int $times) : void{
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, ActorEvent::COMPLETE_TRADE, 0, null)]);
		$this->playMobSound(LevelSoundEvent::HAGGLE_YES);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->canTradeWith($player)){
			$this->playMobSound(LevelSoundEvent::HAGGLE_NO);
			return false;
		}
		$this->getNavigation()->stop();
		return $player->setCurrentWindow(new TradeInventory($this, $player));
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled()){
			return;
		}
		$cause = $source->getCause();
		if($cause === EntityDamageEvent::CAUSE_ENTITY_ATTACK || $cause === EntityDamageEvent::CAUSE_PROJECTILE || $cause === EntityDamageEvent::CAUSE_MAGIC){
			//become_scared: minecraft:angry for 5 seconds
			$this->scaredUntil = $this->server->getTick() + self::SCARED_TICKS;
			$this->scaredByPlayer = $source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Player;
		}
		if(!$source instanceof EntityDamageByEntityEvent || !($damager = $source->getDamager()) instanceof Living){
			return;
		}
		//the anger is broadcast to the llamas on its leads (broadcast_filters is_leashed_to, range 10)
		foreach($this->getLeashedLlamas() as $llama){
			if($llama->isAlive()){
				$llama->setAttackTarget($damager);
			}
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setLong(EntityMetadataProperties::TRADING_PLAYER_EID, $this->tradingPlayer?->getId() ?? 0);
		$properties->setGenericFlag(EntityMetadataFlags::ACTION, $this->isDrinking()); //USINGITEM: drinking
	}
}
