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

namespace pocketmine\entity;

use pocketmine\entity\ai\goal\BreakDoorGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\KineticWeaponGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\StompTurtleEggGoal;
use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\monster\ChargedCreeperHeads;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\HeldItemsTrait;
use pocketmine\entity\monster\Husk;
use pocketmine\entity\monster\MobConversion;
use pocketmine\entity\monster\MobEquipment;
use pocketmine\entity\monster\MobShareables;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\monster\UnderwaterConversionTimer;
use pocketmine\entity\monster\VanillaMethods;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\item\Item;
use pocketmine\item\Spear;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\World;
use function mt_rand;

/**
 * Bedrock values: 20 health, movement 0.23 (baby 0.35), attack 3 (scaled by difficulty), follow range 35, burns in
 * daylight. 5% of naturally spawned zombies are babies (small, fast, 12 XP, never grow up).
 * Some zombies (55% x the regional difficulty special multiplier, see {@link MobShareables::PICKUP_CHANCE}) pick up items lying within
 * 3 blocks while mobGriefing is on (behavior.pickup_items, see {@link MobShareables::zombie()}): anything but glow ink
 * sacs and the ominous banner into an empty hand, better swords, spears and armor in place of theirs (the old one is
 * dropped). Picked items always drop on death, also after a conversion, and the zombie no longer despawns.
 * Loot: 0-2 rotten flesh (+0-1 per Looting level); when killed by a player, 2.5% (+1% per Looting level) chance of an
 * iron ingot, carrot or potato; a baby riding a chicken killed by a player or a pet drops the Lava Chicken music disc
 * (zombie.json, shared by husks and zombie villagers).
 *
 * A zombie 30 seconds underwater starts shaking and becomes a drowned 15 seconds later (start_drowned_transformation,
 * convert_to_drowned): a baby stays a baby, and it keeps its name, equipment and persistence. Husks become zombies the
 * same way; zombie villagers don't convert.
 * Zombies, husks and zombie villagers walk to turtle eggs within 10 blocks and stomp on them (behavior.stomp_turtle_egg,
 * see {@link StompTurtleEggGoal}), and charge with a spear when they hold one (behavior.use_kinetic_weapon, see
 * {@link KineticWeaponGoal}).
 */
class Zombie extends Monster implements Ageable{
	use HeldItemsTrait;

	public const TAG_IS_BABY = "IsBaby"; //TAG_Byte

	public const ATTACK_DAMAGE = 3.0;
	public const FOLLOW_RANGE = 35.0;
	/**
	 * Natural armor of zombies, husks, drowned, zombie villagers and zombified piglins, added to what they wear (BDS
	 * 1.26.60: a fist hit does 0.94 and an iron sword 6.888 to a bare zombie, husk, drowned or zombified piglin).
	 */
	public const NATURAL_ARMOR = 2;

	private bool $baby = false;

	/** Byte tag of the door breakers (vanilla component group minecraft:can_break_doors) */
	private const TAG_CAN_BREAK_DOORS = "CanBreakDoors";
	/** Chance in 100 that a new zombie, husk, drowned or zombie villager breaks doors (vanilla entity_spawned weight 10) */
	private const BREAK_DOORS_CHANCE = 10;
	private bool $canBreakDoors = false;

	/** The timers of the drowned conversion, only for plain zombies (not their subclasses). */
	private ?UnderwaterConversionTimer $drownedConversion = null;
	/** Whether the spear is lowered for a charge (actor flag USINGITEM, which the client animates). */
	private bool $usingSpear = false;

	public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.74);
	}

	public function getName() : string{
		return "Zombie";
	}

	public function getArmorPoints() : int{
		return parent::getArmorPoints() + self::NATURAL_ARMOR;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.23);
		parent::initEntity($nbt);
		$this->setBaby($nbt->getByte(self::TAG_IS_BABY, 0) !== 0);
		$this->loadHeldItems($nbt);
		$fresh = MobEquipment::isFreshSpawn($nbt);
		if($fresh){
			$this->pickSpawnEquipment();
		}
		//behavior.pickup_items priority: 6, zombified piglins 7, zombie villagers 8
		match(true){
			$this instanceof Drowned => $this->initItemPickup($nbt, MobShareables::drowned(), 6),
			$this instanceof ZombifiedPiglin => $this->initItemPickup($nbt, MobShareables::zombifiedPiglin(), 7),
			default => $this->initItemPickup($nbt, MobShareables::zombie(), $this instanceof ZombieVillager ? 8 : 6)
		};
		$this->initDoorBreaking($nbt, $fresh);
		if(static::class === self::class){
			//vanilla zombies only: plugin subclasses keep what they are
			$this->drownedConversion = UnderwaterConversionTimer::fromNBT($nbt);
		}
	}

	/**
	 * Vanilla minecraft:can_break_doors: 10% of new zombies, husks, drowned and zombie villagers get
	 * annotation.break_door (break_time 12 seconds, hard difficulty): their paths go through closed wooden doors (all
	 * their kind have navigation can_break_doors) and they break them (see {@link BreakDoorGoal}). Saved, so a zombie
	 * keeps it. Zombified piglins open doors instead.
	 */
	private function initDoorBreaking(CompoundTag $nbt, bool $fresh) : void{
		if($this instanceof ZombifiedPiglin){
			return;
		}
		$tag = $nbt->getTag(self::TAG_CAN_BREAK_DOORS);
		$this->canBreakDoors = $tag instanceof ByteTag ? $tag->getValue() !== 0 : $fresh && mt_rand(0, 99) < self::BREAK_DOORS_CHANCE;
		if($this->canBreakDoors){
			$this->getNavigation()->getPathFinder()->setCanBreakDoors(true);
			$this->goalSelector->addGoal(1, new BreakDoorGoal($this));
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_IS_BABY, $this->baby ? 1 : 0);
		if(!$this instanceof ZombifiedPiglin){
			$nbt->setByte(self::TAG_CAN_BREAK_DOORS, $this->canBreakDoors ? 1 : 0);
		}
		$this->saveHeldItems($nbt);
		$this->drownedConversion?->saveNBT($nbt);
		return $nbt;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		$timer = $this->drownedConversion;
		if($timer === null || $this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		switch($timer->tick($this, $tickDiff)){
			case UnderwaterConversionTimer::STATE_STARTED:
				$this->networkPropertiesDirty = true;
				return true;
			case UnderwaterConversionTimer::STATE_CONVERTING:
				return true;
			case UnderwaterConversionTimer::STATE_DONE:
				$this->convertToDrowned();
				return true;
		}
		return $hasUpdate;
	}

	/**
	 * Turns into minecraft:drowned<minecraft:as_adult> (or as_baby), keeping the equipment (preserve_equipment), the
	 * name and the persistence. As with all conversions, no door breaking roll (that is entity_spawned only) and no
	 * spawn equipment; picked up items still always drop.
	 */
	private function convertToDrowned() : void{
		$drowned = new Drowned($this->location->asLocation(), MobEquipment::getConversionData($this)->setByte(self::TAG_CAN_BREAK_DOORS, 0));
		$drowned->setBaby($this->baby);
		$drowned->setMainHandItem($this->getMainHandItem());
		$drowned->setOffHandItem($this->getOffHandItem());
		MobConversion::copyState($this, $drowned);
		$drowned->spawnToAll();
		$drowned->playMobSound(LevelSoundEvent::CONVERT_TO_DROWNED);
		$this->flagForDespawn();
	}

	/**
	 * Zombies, husks and zombie villagers don't drown (breathable breathes_water): they sink and walk on the bottom.
	 */
	public function canBreathe() : bool{
		return true;
	}

	protected function registerGoals() : void{
		//no FloatGoal: zombies sink in water (no behavior.float)
		$villager = $this instanceof ZombieVillager;
		//zombie villagers have their own priorities: stomp_turtle_egg 4 and use_kinetic_weapon 6 before melee_box_attack 7
		//(zombies and husks: use_kinetic_weapon 3, melee_box_attack 4, stomp_turtle_egg 5)
		$this->registerKineticWeaponGoals($villager ? 6 : 3);
		$this->goalSelector->addGoal($villager ? 7 : 4, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, self::FOLLOW_RANGE, canSpreadOnFire: true));
		$this->goalSelector->addGoal($villager ? 4 : 5, new StompTurtleEggGoal($this));
		$this->goalSelector->addGoal($villager ? 9 : 7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal($villager ? 10 : 8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal($villager ? 11 : 9, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//reselect_targets; players are noticed up to max_dist 35, not the within_radius 25 of the old packs: BDS 1.26.60
		//targets a standing player 31 blocks away, and not a sneaking one (35 x 0.8 = 28; VanillaBot targeting_sneak)
		$this->targetSelector->addGoal(2, new NearestAttackableTargetGoal($this, self::FOLLOW_RANGE, true, 10, forgetTicks: $this->getTargetForgetTicks(), reselect: true, maxDistance: self::FOLLOW_RANGE));
		$this->targetSelector->addGoal(3, self::entityTargetGoal($this, $villager));
	}

	/**
	 * Vanilla behavior.use_kinetic_weapon: spear charges, with faster and longer runs on a zombie horse (zombies) or a
	 * camel husk (husks), which the rider steers (hijack_mount_navigation).
	 */
	private function registerKineticWeaponGoals(int $priority) : void{
		$mainHand = fn() : Item => $this->getMainHandItem();
		//getMainHandItem() returns a copy: the goals ask the held item itself whether it is a spear, unless a subclass
		//changes getMainHandItem()
		$holdsSpear = VanillaMethods::isDeclaredBy(static::class, "getMainHandItem", [self::class]) ? fn() : bool => $this->mainHandItem instanceof Spear : null;
		$onUsing = function(bool $using) : void{
			$this->usingSpear = $using;
			$this->networkPropertiesDirty = true;
		};
		$mountClass = match(true){
			$this instanceof Husk => CamelHusk::class,
			$this instanceof ZombieVillager => null,
			default => ZombieHorse::class
		};
		if($mountClass === null){
			$this->goalSelector->addGoal($priority, new KineticWeaponGoal($this, $mainHand, self::ATTACK_DAMAGE, onUsingChanged: $onUsing, holdsSpear: $holdsSpear));
			return;
		}
		//on_zombie_horse x1.4 / on_camel_husk x4: approach 12, reposition 8-9, cooldown 11-13
		$speed = $mountClass === CamelHusk::class ? 4.0 : 1.4;
		$this->goalSelector->addGoal($priority, new KineticWeaponGoal($this, $mainHand, self::ATTACK_DAMAGE, 12.0, [8, 9], [11, 13], $speed, $speed, $speed, fn() => $this->getVehicle() instanceof $mountClass, $onUsing, $holdsSpear));
		$this->goalSelector->addGoal($priority, new KineticWeaponGoal($this, $mainHand, self::ATTACK_DAMAGE, condition: fn() => !$this->getVehicle() instanceof $mountClass, onUsingChanged: $onUsing, holdsSpear: $holdsSpear));
	}

	/**
	 * Targets the other entity_types of zombie.json nearest_attackable_target within 35 blocks with one search: snow
	 * golems, iron golems and baby turtles out of the water, which must be seen, and villagers and wandering traders,
	 * unseen ones too unless $villagersMustSee (zombie villagers list them with the golems). The search is skipped while
	 * the world has none of them.
	 */
	private static function entityTargetGoal(Mob $mob, bool $villagersMustSee) : NearestAttackableEntityGoal{
		return new NearestAttackableEntityGoal(
			$mob,
			fn(Living $e) => $e instanceof VillagerV2 || $e instanceof Villager || $e instanceof WanderingTrader ?
				!$villagersMustSee || $mob->getSensing()->canSee($e) :
				($e instanceof IronGolem || $e instanceof SnowGolem || ($e instanceof Turtle && $e->isBaby() && !$e->isInWater())) && $mob->getSensing()->canSee($e),
			self::FOLLOW_RANGE,
			false,
			10,
			fn() => self::worldHasEntityTargets($mob->getWorld()),
			reselect: true
		);
	}

	private static function worldHasEntityTargets(World $world) : bool{
		return $world->hasEntitiesOfClass(VillagerV2::class) || $world->hasEntitiesOfClass(Villager::class) || $world->hasEntitiesOfClass(WanderingTrader::class) ||
			$world->hasEntitiesOfClass(IronGolem::class) || $world->hasEntitiesOfClass(SnowGolem::class) || $world->hasEntitiesOfClass(Turtle::class);
	}

	/**
	 * Ticks an unseen player stays targeted (Bedrock must_see_forget_duration): 17 seconds for zombies, the default 3 for
	 * husks and zombie villagers.
	 */
	private function getTargetForgetTicks() : int{
		return $this instanceof Husk || $this instanceof ZombieVillager ? NearestAttackableTargetGoal::DEFAULT_FORGET_TICKS : 17 * 20;
	}

	/**
	 * Whether zombies (and husks, drowned and zombie villagers) hunt the entity besides players: villagers, wandering
	 * traders, iron golems and snow golems (Bedrock zombie.json nearest_attackable_target).
	 */
	public static function isVillageTarget(Living $entity) : bool{
		return $entity instanceof VillagerV2 || $entity instanceof Villager || $entity instanceof WanderingTrader ||
			$entity instanceof IronGolem || $entity instanceof SnowGolem;
	}

	/**
	 * The goal that makes zombie-like mobs target villagers and golems within 35 blocks. Villagers don't have to be
	 * seen (vanilla NearestAttackableTargetGoal(AbstractVillager, false)), so zombies find them behind walls and doors.
	 */
	public static function villageTargetGoal(Mob $mob) : NearestAttackableEntityGoal{
		return new NearestAttackableEntityGoal($mob, fn(Living $e) => self::isVillageTarget($e), self::FOLLOW_RANGE, false, 10, reselect: true);
	}

	public function isBaby() : bool{
		return $this->baby;
	}

	public function setBaby(bool $baby) : void{
		$this->baby = $baby;
		$this->setScale($baby ? 0.5 : 1.0);
		if($baby){
			//Bedrock baby collision box 0.98 x 1.96 at scale 0.5 (all the zombie kinds)
			$adult = $this->getInitialSizeInfo();
			$this->setSize((new EntitySizeInfo(1.96, 0.98, $adult->getEyeHeight() * 1.96 / $adult->getHeight()))->scale(0.5));
		}
		$this->setMovementSpeed($baby ? 0.35 : 0.23);
		$this->networkPropertiesDirty = true;
	}

	public function makeSpawnBaby() : bool{
		$this->setBaby(true);
		return true;
	}

	public function burnsInDaylight() : bool{
		return true;
	}

	/**
	 * Returns the Bedrock equipment table the mob spawns with (minecraft:equipment), or null for none.
	 */
	protected function getSpawnEquipmentTable() : ?string{
		return "zombie_equipment";
	}

	/**
	 * Gives the mob its spawn equipment (see {@link Zombie::getSpawnEquipmentTable()}). Called when a new mob is
	 * created, not when one is loaded from saved data.
	 */
	protected function pickSpawnEquipment() : void{
		$table = $this->getSpawnEquipmentTable();
		if($table !== null){
			$this->equipFromTable($table);
		}
	}

	/**
	 * Replaces the equipment by the one of zombie riders (vanilla zombie_rider / husk spawn_as_rider:
	 * zombie_rider_equipment, an iron spear and maybe armor).
	 */
	public function equipAsRider() : void{
		$this->equipFromTable("zombie_rider_equipment");
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 2) + $this->lootingBonus())
		];

		if($this->baby && $this->getVehicle() instanceof Chicken && $this->wasRecentlyHitByPlayer()){
			//zombie.json: a baby riding a chicken, killed by a player or a pet
			$drops[] = VanillaItems::RECORD_LAVA_CHICKEN();
		}

		if($this->wasRecentlyHitByPlayer() && mt_rand(0, 999) < 25 + 10 * $this->getLootingLevel()){
			$drops[] = match(mt_rand(0, 2)){
				0 => VanillaItems::IRON_INGOT(),
				1 => VanillaItems::CARROT(),
				default => VanillaItems::POTATO()
			};
		}

		$head = ChargedCreeperHeads::getHeadDrop($this);
		if($head !== null){
			$drops[] = $head;
		}

		return [...$drops, ...$this->getEquipmentDrops()];
	}

	public function getXpDropAmount() : int{
		//5 (baby 12) + 1-3 per equipped item, when killed by a player
		return $this->equipmentXp($this->baby ? 12 : 5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ZOMBIE_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
		if($this->drownedConversion !== null){
			//flag 40 is ActorFlags::SHAKING (gophertunnel EntityDataFlagShaking), named VIBRATING in BedrockProtocol
			$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->drownedConversion->isConverting());
		}
		//flag 4 is ActorFlags::USINGITEM (named ACTION here): the client lowers the spear while it is set
		$properties->setGenericFlag(EntityMetadataFlags::ACTION, $this->usingSpear);
	}
}
