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

namespace pocketmine\entity\monster;

use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\Ageable;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\OpenDoorGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\ai\hostile\goal\CrossbowAttackGoal;
use pocketmine\entity\ai\hostile\goal\FleeBlockGoal;
use pocketmine\entity\ai\hostile\goal\FleeEntityGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\item\Armor;
use pocketmine\item\Crossbow;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function array_values;
use function count;
use function intdiv;
use function max;
use function min;
use function mt_rand;

/**
 * Bedrock values (piglin.json): 16 health, movement 0.35 (baby 0.42), 0.6x1.9. Adults hold a golden sword (attack 5)
 * or a crossbow (shoots within 8 blocks, backing off within 4), 50/50 (5% of the swords are golden spears), and wear
 * each piece of golden armor with a 10% chance; 5% spawn as babies, which panic when hurt.
 * - Neutral to players wearing a piece of golden armor; attacks the others within 16 blocks, and the wither family (wither skeletons, the wither).
 * - Hurting one angers the piglins within 16 blocks for 30 seconds. So does opening a chest, trapped chest, ender
 *   chest, barrel or shulker box in their sight, or breaking a gold block, gold ore, raw gold block, gilded blackstone
 *   or one of those containers within 16 blocks (see {@link PiglinAi}), gold armor or not.
 * - Bartering: give an adult a gold ingot (interact, even an angry one) or let it pick one up; it holds it in its off
 *   hand and admires it for 8 seconds (admire_item), then throws an item of the bartering table ({@link PiglinBarter})
 *   towards the player. Other gold items it picks up (Java tag piglin_loved, the golden dandelion) are admired too and
 *   kept: golden armor is worn when the slot is free, the rest goes into its 8-slot inventory, dropped on death. Gold
 *   nuggets go straight into the inventory. Being hurt stops the admiring and keeps it from picking anything up for 20
 *   seconds. Babies pick up and admire gold too, but don't barter (they keep the ingot).
 * - Picks up porkchops and eats them (one every 10 seconds).
 * - Picks up gear it prefers to its own (shareables with equip_item, see {@link MobShareables::piglin()}): armor,
 *   swords, spears, a crossbow, a shield, heads, a carved pumpkin or elytra in an empty slot or better than what it has
 *   (its golden gear is the best). The replaced item is dropped; picked gear always drops on death. Admiring gold
 *   drops a shield from the off hand (Java).
 * - Hunts hoglins: an adult which hasn't hunted for 30-120 seconds attacks the nearest adult hoglin within 16 blocks
 *   unless the hoglins outnumber the piglins, and calls the piglins around to join; when the hoglin dies, the piglins
 *   within 16 blocks celebrate (dance) for 10 seconds. Outnumbered piglins walk away from hoglins instead.
 * - Flees zombified piglins and zoglins within 6 blocks, and soul fire, soul torches, soul lanterns and lit soul
 *   campfires within 8 blocks unless it is fighting (avoid_block priority 9, below the attacks).
 * - Chases its targets up to 64 blocks away (follow_range). Stays in peaceful difficulty, harmless.
 * - 1 baby in 10 spawned is a piglin jockey: it looks for a baby hoglin to ride within 16 blocks (see
 *   {@link \pocketmine\entity\riding\Jockeys}).
 * - Turns into a zombified piglin after 15 seconds outside the Nether: it keeps its held and worn items, drops its
 *   inventory and the item it was admiring.
 * Loot: its spawn equipment (8.5%), the gold it picked up and its inventory. Experience 5 (+1-3 per equipped item),
 * baby 1 (+1-2).
 * Not implemented: piglins staring at players holding gold, jealousy when a player takes the gold they wanted,
 * minecart chests.
 */
class Piglin extends Monster implements Ageable, Angerable{
	use HeldItemsTrait;
	use AngerTrait;
	use ZombifiesOutsideNetherTrait;

	public const TAG_IS_BABY = "IsBaby"; //TAG_Byte
	public const TAG_ARMOR = "Armor"; //TAG_List<TAG_Compound>, helmet to boots
	public const TAG_ARMOR_KEPT = "ArmorKept"; //TAG_Byte, bit mask of the armor slots holding picked up gold (always dropped)
	public const TAG_INVENTORY = "Inventory"; //TAG_List<TAG_Compound>

	public const ATTACK_DAMAGE = 5.0;
	public const ANGER_TICKS = 600;
	/** follow_range: how far a target is chased */
	private const FOLLOW_RANGE = 64.0;
	public const ADMIRE_TICKS = 160;
	/** admire_item cooldown_after_being_attacked */
	public const ADMIRE_COOLDOWN_TICKS = 400;
	public const INVENTORY_SIZE = 8;
	/** @deprecated spawn armor follows the Bedrock tables now (see equipSpawnGear()) */
	public const ARMOR_CHANCE = 10;
	public const WANTED_ITEM_RANGE = 10.0;
	public const HUNT_RANGE = 16.0;
	public const CELEBRATE_TICKS = 200;
	public const ATE_RECENTLY_TICKS = 200;

	private bool $baby = false;
	private bool $charging = false;
	private bool $countedByPiglinAi = false;
	private int $admireTicks = 0;
	private int $admireCooldown = 0;
	private ?int $admiredFrom = null;
	/** @phpstan-var list<Item> */
	private array $inventory = [];
	private int $armorKept = 0;
	private int $huntCooldown = 0;
	private int $celebrateTicks = 0;
	private int $celebrateSoundTicks = 0;
	private int $ateRecentlyTicks = 0;
	private int $wantedItemCheckTicks = 0;
	private int $censusTicks = 0;
	private bool $hoglinsOutnumber = false;
	private ?Vector3 $repellent = null;
	private int $repellentCheckTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::PIGLIN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	public function getName() : string{
		return "Piglin";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(16);
		$this->setMovementSpeed(0.35);
		$this->setFollowRange(self::FOLLOW_RANGE);
		parent::initEntity($nbt);
		$this->loadHeldItems($nbt);
		$this->initItemPickup($nbt, MobShareables::piglin(), null); //its own pickup goal (range 10, gold first)
		$this->loadZombificationImmunity($nbt);
		if($nbt->getTag(self::TAG_IS_BABY) === null){
			$this->setBaby(mt_rand(0, 99) < 5);
		}else{
			$this->setBaby($nbt->getByte(self::TAG_IS_BABY) !== 0);
		}
		if($nbt->getListTag("Mainhand") === null && !$this->baby){
			$this->equipSpawnGear();
		}
		//the armor is loaded and saved by Living ("Armor", same format)
		$this->armorKept = $nbt->getByte(self::TAG_ARMOR_KEPT, 0);
		$inventoryTag = $nbt->getListTag(self::TAG_INVENTORY, CompoundTag::class);
		if($inventoryTag !== null){
			foreach($inventoryTag as $itemTag){
				if(($item = self::loadItem($itemTag)) !== null && count($this->inventory) < self::INVENTORY_SIZE){
					$this->inventory[] = $item;
				}
			}
		}
		//vanilla: freshly spawned piglins don't hunt for 30-120 seconds
		$this->huntCooldown = mt_rand(600, 2400);
		PiglinAi::onPiglinCreated();
		$this->countedByPiglinAi = true;
	}

	protected function onDispose() : void{
		if($this->countedByPiglinAi){
			$this->countedByPiglinAi = false;
			PiglinAi::onPiglinDisposed();
		}
		parent::onDispose();
	}

	private static function loadItem(CompoundTag $tag) : ?Item{
		try{
			$item = Item::nbtDeserialize($tag);
		}catch(SavedDataLoadingException){
			return null;
		}
		return $item->isNull() ? null : $item;
	}

	/**
	 * Vanilla spawn equipment (spawn_adult: ranged or melee, 1:1): piglin_gear_ranged (a crossbow) or piglin_gear_melee
	 * (a golden sword, 5% a golden spear), the weapon 10% enchanted x regional difficulty; on Normal and Hard, with a
	 * chance of 15% x regional difficulty, golden armor (armor_set_gold: a helmet, then each next piece 50%, 60% on Hard,
	 * every piece 25% enchanted x regional difficulty).
	 */
	private function equipSpawnGear() : void{
		$this->equipFromTable(mt_rand(0, 1) === 0 ? "piglin_gear_ranged" : "piglin_gear_melee");
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_IS_BABY, $this->baby ? 1 : 0);
		$this->saveHeldItems($nbt);
		$this->saveZombificationImmunity($nbt);
		$nbt->setByte(self::TAG_ARMOR_KEPT, $this->armorKept);
		$inventory = [];
		foreach($this->inventory as $item){
			$inventory[] = $item->nbtSerialize();
		}
		$nbt->setTag(self::TAG_INVENTORY, new ListTag($inventory, NBT::TAG_Compound));
		return $nbt;
	}

	protected function registerGoals() : void{
		//annotation.open_door: opens the wooden doors on its paths
		$this->goalSelector->addGoal(0, new OpenDoorGoal($this));
		$this->goalSelector->addGoal(1, new class($this, 1.1) extends PanicGoal{
			protected function shouldPanic() : bool{
				return $this->mob instanceof Piglin && $this->mob->isBaby() && parent::shouldPanic();
			}
		});
		$this->goalSelector->addGoal(2, new class($this) extends Goal{
			public function __construct(private Piglin $piglin){}

			public function getFlags() : int{
				return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP;
			}

			public function canStart() : bool{
				return $this->piglin->isAdmiring();
			}

			public function start() : void{
				$this->piglin->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(3, new FleeEntityGoal($this, $this->findZombifiedThreat(...), 6.0, 1.2));
		$this->goalSelector->addGoal(5, new FleeEntityGoal($this, $this->findOutnumberingHoglin(...), 8.0, 1.0));
		$this->goalSelector->addGoal(6, new class($this) extends Goal{
			public function __construct(private Piglin $piglin){}

			public function getFlags() : int{
				return self::FLAG_MOVE | self::FLAG_JUMP;
			}

			public function canStart() : bool{
				return $this->piglin->isCelebrating() && $this->piglin->getAttackTarget() === null;
			}

			public function start() : void{
				$this->piglin->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(7, new class($this) extends Goal{
			private ?ItemEntity $target = null;

			public function __construct(private Piglin $piglin){}

			public function getFlags() : int{
				return self::FLAG_MOVE;
			}

			public function canStart() : bool{
				$this->target = $this->piglin->findWantedItem();
				return $this->target !== null;
			}

			public function canContinue() : bool{
				$target = $this->target;
				return $target !== null && !$target->isClosed() && !$target->isFlaggedForDespawn() && $this->piglin->getAttackTarget() === null && $this->piglin->wantsItem($target->getItem());
			}

			public function tick() : void{
				$target = $this->target;
				if($target === null){
					return;
				}
				if($target->getBoundingBox()->expandedCopy(1.0, 0.5, 1.0)->intersectsWith($this->piglin->getBoundingBox())){
					$this->piglin->pickUp($target);
					$this->target = null;
					return;
				}
				$this->piglin->getNavigation()->moveToEntity($target, 1.0, 0.5);
			}

			public function stop() : void{
				$this->target = null;
				$this->piglin->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(8, new class($this, 1.0, 8.0, 4.0, $this->setCharging(...), $this->shootCrossbow(...)) extends CrossbowAttackGoal{
			public function canStart() : bool{
				//chases as far as the follow range (64), the shooting itself stays within 8 blocks
				return $this->mob instanceof Piglin && $this->mob->hasCrossbow() && $this->mob->getSensing()->isValidTarget($this->mob->getAttackTarget(), $this->mob->getFollowRange());
			}
		});
		$this->goalSelector->addGoal(9, new class($this, 1.0, self::ATTACK_DAMAGE, self::FOLLOW_RANGE) extends MeleeAttackGoal{
			public function canStart() : bool{
				return $this->mob instanceof Piglin && !$this->mob->hasCrossbow() && parent::canStart();
			}
		});
		//avoid_block priority 9, like melee_box_attack and below ranged_attack (8): a fighting piglin doesn't flee soul fire
		$this->goalSelector->addGoal(9, new FleeBlockGoal($this, $this->findRepellent(...), 1.0));
		$this->goalSelector->addGoal(10, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(11, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(12, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new AngerTargetGoal($this, $this->getAngerTarget(...), self::FOLLOW_RANGE));
		//(wouldAttack() only accepts players and the wither family, and nobody while the piglin is a baby or admires an
		//item: the search looks at nothing else, unless a subclass changes wouldAttack())
		$vanillaFilter = VanillaMethods::isDeclaredBy(static::class, "wouldAttack", [self::class]);
		$this->targetSelector->addGoal(7, new NearestLivingTargetGoal($this, $this->wouldAttack(...), 16.0,
			candidateClasses: $vanillaFilter ? [Player::class, WitherSkeleton::class, Wither::class] : null,
			filterCondition: $vanillaFilter ? fn() : bool => !$this->baby && !$this->isAdmiring() : null
		));
	}

	/**
	 * Whether the piglin attacks the entity on sight: adults not busy admiring attack the wither family (wither
	 * skeletons and the wither) and players without golden armor.
	 */
	public function wouldAttack(Living $entity) : bool{
		return !$this->baby && !$this->isAdmiring() && (($entity instanceof Player && !self::wearsGold($entity)) || $entity instanceof WitherSkeleton || $entity instanceof Wither);
	}

	/**
	 * Piglins leave alone players wearing at least one piece of golden armor.
	 */
	public static function wearsGold(Living $entity) : bool{
		foreach($entity->getArmorInventory()->getContents() as $item){
			if(match($item->getTypeId()){
				ItemTypeIds::GOLDEN_HELMET, ItemTypeIds::GOLDEN_CHESTPLATE, ItemTypeIds::GOLDEN_LEGGINGS, ItemTypeIds::GOLDEN_BOOTS => true,
				default => false
			}){
				return true;
			}
		}
		return false;
	}

	private function findZombifiedThreat() : ?Entity{
		$world = $this->getWorld();
		if(!$world->hasEntitiesOfClass(ZombifiedPiglin::class) && !$world->hasEntitiesOfClass(Zoglin::class)){
			//asked on every goal evaluation: no search while the world has none of them (cached class counts)
			return null;
		}
		$loc = $this->location;
		$best = null;
		$bestDist = 36.0;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - 6, $loc->y - 3, $loc->z - 6, $loc->x + 6, $loc->y + 3, $loc->z + 6), $this) as $entity){
			if(($entity instanceof ZombifiedPiglin || $entity instanceof Zoglin) && $entity->isAlive()){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	/**
	 * Nearest soul fire, soul torch, soul lantern or lit soul campfire within 8 blocks (4 up or down), looked for once
	 * a second.
	 */
	public function findRepellent() : ?Vector3{
		if($this->repellentCheckTicks <= 0){
			$this->repellentCheckTicks = 20;
			$this->repellent = PiglinAi::findNearestBlock($this->getWorld(), $this->location, 8, 4, PiglinAi::getPiglinRepellents(), PiglinAi::isActivePiglinRepellent(...));
		}
		return $this->repellent;
	}

	/**
	 * The nearest adult hoglin within 8 blocks when the adult hoglins around outnumber the adult piglins.
	 */
	private function findOutnumberingHoglin() : ?Entity{
		if($this->baby || !$this->hoglinsOutnumber){
			return null;
		}
		return $this->findNearestHoglin(8.0, false);
	}

	private function findNearestHoglin(float $range, bool $mustSee) : ?Hoglin{
		if(!$this->getWorld()->hasEntitiesOfClass(Hoglin::class)){
			return null;
		}
		$loc = $this->location;
		$best = null;
		$bestDist = $range * $range;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $range, $loc->y - 4, $loc->z - $range, $loc->x + $range, $loc->y + 4, $loc->z + $range), $this) as $entity){
			if($entity instanceof Hoglin && $entity->isAlive() && !$entity->isBaby()){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist && (!$mustSee || $this->sensing->canSee($entity))){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	/**
	 * Counts the adult piglins (this one included) and adult hoglins within 16 blocks.
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public function countAdultsAround() : array{
		$loc = $this->location;
		$r = self::HUNT_RANGE;
		$piglins = 1;
		$hoglins = 0;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this) as $entity){
			if(!$entity->isAlive() || $entity->getLocation()->distanceSquared($loc) > $r * $r){
				continue;
			}
			if(($entity instanceof Piglin && !$entity->isBaby()) || $entity instanceof PiglinBrute){
				++$piglins;
			}elseif($entity instanceof Hoglin && !$entity->isBaby()){
				++$hoglins;
			}
		}
		return [$piglins, $hoglins];
	}

	/**
	 * Starts a hunt when possible: an adult not on hunt cooldown, not busy, which sees an adult hoglin and isn't
	 * outnumbered. The adult piglins around join the hunt, and none of them hunts again for 30-120 seconds.
	 */
	public function tryHunting() : bool{
		if($this->baby || $this->huntCooldown > 0 || $this->hoglinsOutnumber || $this->isAngry() || $this->isAdmiring() || $this->getAttackTarget() !== null){
			return false;
		}
		$hoglin = $this->findNearestHoglin(self::HUNT_RANGE, true);
		if($hoglin === null){
			return false;
		}
		$this->setAngryAt($hoglin, self::ANGER_TICKS);
		$this->huntCooldown = mt_rand(600, 2400);
		$loc = $this->location;
		$r = self::HUNT_RANGE;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this) as $entity){
			if($entity instanceof Piglin && $entity->isAlive() && !$entity->isBaby()){
				$entity->huntCooldown = mt_rand(600, 2400);
				if(!$entity->isAngry() && !$entity->isAdmiring()){
					$entity->setAngryAt($hoglin, self::ANGER_TICKS);
				}
			}
		}
		return true;
	}

	public function getHuntCooldown() : int{
		return $this->huntCooldown;
	}

	public function setHuntCooldown(int $ticks) : void{
		$this->huntCooldown = max(0, $ticks);
	}

	public function isCelebrating() : bool{
		return $this->celebrateTicks > 0;
	}

	/**
	 * Dances for 10 seconds (celebrate_hunt), after a hoglin hunt.
	 */
	public function startCelebrating() : void{
		if(!$this->isCelebrating()){
			$this->networkPropertiesDirty = true;
			$this->celebrateSoundTicks = 0;
		}
		$this->celebrateTicks = self::CELEBRATE_TICKS;
	}

	public function isBaby() : bool{
		return $this->baby;
	}

	public function setBaby(bool $baby) : void{
		$this->baby = $baby;
		$this->setScale($baby ? 0.5 : 1.0);
		if($baby){
			$this->setSize((new EntitySizeInfo(1.96, 0.98, 1.671))->scale(0.5)); //baby collision box 0.98 x 1.96 at scale 0.5
		}
		$this->setMovementSpeed($baby ? 0.42 : 0.35);
		$this->networkPropertiesDirty = true;
	}

	public function makeSpawnBaby() : bool{
		$this->setBaby(true);
		//the spawn gear of the adult it was created as (babies spawn with nothing)
		$this->setMainHandItem(VanillaItems::AIR());
		$this->armorInventory->clearAll();
		return true;
	}

	public function hasCrossbow() : bool{
		return $this->getMainHandItem() instanceof Crossbow;
	}

	public function setCharging(bool $charging) : void{
		if($this->charging !== $charging){
			$this->charging = $charging;
			$this->networkPropertiesDirty = true;
			$this->playMobSound($charging ? LevelSoundEvent::CROSSBOW_LOADING_START : LevelSoundEvent::CROSSBOW_LOADING_END);
		}
	}

	public function shootCrossbow(Living $target, float $distance) : void{
		if($target instanceof Player && $this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			//piglins stay in Peaceful, where mob attacks (arrows included) never hurt players
			return;
		}
		Aim::shootArrow($this, $target);
		$this->playMobSound(LevelSoundEvent::CROSSBOW_SHOOT);
	}

	public function isAdmiring() : bool{
		return $this->admireTicks > 0;
	}

	/**
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function getInventoryContents() : array{
		return $this->inventory;
	}

	/**
	 * Whether the piglin would pick up the item now: gold it loves when it's free to admire it, porkchops when it
	 * hasn't eaten recently, and gear better than its own; nothing for 20 seconds after being attacked
	 * (behavior.pickup_items cooldown_after_being_attacked).
	 */
	public function wantsItem(Item $item) : bool{
		if(!$this->isAlive() || $this->admireCooldown > 0){
			return false;
		}
		if(PiglinAi::isFood($item)){
			return $this->ateRecentlyTicks <= 0;
		}
		if(PiglinAi::isStoredItem($item)){
			//shareables stored_in_inventory without admire: gold nuggets go straight into the inventory
			return !$this->isAdmiring() && $this->canStore($item);
		}
		if(!PiglinAi::isLovedItem($item)){
			//shareables gear (equip_item): better armor, weapons, a shield, heads...
			return !$this->isAdmiring() && $this->wantsToPickUp($item);
		}
		if($this->isAdmiring()){
			return false;
		}
		return $item->getTypeId() === ItemTypeIds::GOLD_INGOT || $this->canKeep($item);
	}

	private function canKeep(Item $item) : bool{
		if($item instanceof Armor && $this->armorInventory->getItem($item->getArmorSlot())->isNull()){
			return true;
		}
		return $this->canStore($item);
	}

	/**
	 * Whether the item fits in the 8-slot inventory: a free slot, or a stack of the same item with room left.
	 */
	private function canStore(Item $item) : bool{
		if(count($this->inventory) < self::INVENTORY_SIZE){
			return true;
		}
		foreach($this->inventory as $stored){
			if($stored->canStackWith($item) && $stored->getCount() < $stored->getMaxStackSize()){
				return true;
			}
		}
		return false;
	}

	/**
	 * Puts the item into the inventory, onto stacks of the same item first. Returns what didn't fit (null if all did).
	 */
	private function store(Item $item) : ?Item{
		$item = clone $item;
		foreach($this->inventory as $stored){
			if($item->isNull()){
				break;
			}
			if($stored->canStackWith($item) && ($room = $stored->getMaxStackSize() - $stored->getCount()) > 0){
				$moved = min($room, $item->getCount());
				$stored->setCount($stored->getCount() + $moved);
				$item->setCount($item->getCount() - $moved);
			}
		}
		if($item->isNull()){
			return null;
		}
		if(count($this->inventory) < self::INVENTORY_SIZE){
			$this->inventory[] = $item;
			return null;
		}
		return $item;
	}

	/**
	 * Nearest wanted item lying within 10 blocks, looked for once a second while the piglin has no target.
	 */
	public function findWantedItem() : ?ItemEntity{
		if(--$this->wantedItemCheckTicks > 0 || $this->getAttackTarget() !== null || $this->isAdmiring() || !GameRules::allowsMobGriefing($this->getWorld())){
			return null;
		}
		//asked once per goal evaluation: look about once a second
		$this->wantedItemCheckTicks = intdiv(20, Mob::GOAL_EVALUATION_INTERVAL);
		$loc = $this->location;
		$r = self::WANTED_ITEM_RANGE;
		$best = null;
		$bestDist = $r * $r;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - 4, $loc->z - $r, $loc->x + $r, $loc->y + 4, $loc->z + $r), $this) as $entity){
			if($entity instanceof ItemEntity && PickupItemsGoal::isAvailable($entity) && $this->wantsItem($entity->getItem())){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	/**
	 * Picks one item of the stack up: porkchops are eaten, gold is admired, other gear is equipped (the item it replaces
	 * is dropped; picked gear always drops on death).
	 */
	public function pickUp(ItemEntity $entity) : bool{
		$item = $entity->getItem();
		if(!PickupItemsGoal::isAvailable($entity) || !$this->wantsItem($item) || !GameRules::allowsMobGriefing($this->getWorld())){
			return false;
		}
		if(!PiglinAi::isFood($item) && !PiglinAi::isLovedItem($item) && !PiglinAi::isStoredItem($item)){
			if(!$this->pickUpItemEntity($entity)){
				return false;
			}
			//picked armor always drops (the slot of the item equipped, which a plugin may have changed)
			$this->armorKept |= $this->guaranteedDropSlots & ((1 << MobEquipment::SLOT_MAINHAND) - 1);
			return true;
		}
		$ev = new EntityItemPickupEvent($this, $entity, (clone $item)->setCount(1), null);
		$ev->call();
		//a plugin may have changed the item: that one is eaten or admired
		$one = $ev->getItem();
		if($ev->isCancelled() || $one->isNull()){
			return false;
		}
		NetworkBroadcastUtils::broadcastEntityEvent(
			$entity->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $this, $entity)
		);
		if($item->getCount() > 1){
			$entity->setStackSize($item->getCount() - 1);
		}else{
			$entity->flagForDespawn();
		}
		if(PiglinAi::isFood($one)){
			$this->ateRecentlyTicks = self::ATE_RECENTLY_TICKS;
			$this->playMobSound(LevelSoundEvent::EAT);
			return true;
		}
		if(PiglinAi::isStoredItem($one)){
			$left = $this->store($one);
			if($left !== null){
				$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $left);
			}
			return true;
		}
		$thrower = $entity->getThrower() !== "" ? $this->server->getPlayerExact($entity->getThrower()) : null;
		$this->startAdmiring($one, $thrower);
		return true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		//interactable_piglin: an adult takes a gold ingot from a player's hand, even when angry
		if($this->canBarterWith($item)){
			$this->consumeHeldItem($player);
			$this->startAdmiring(VanillaItems::GOLD_INGOT(), $player);
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		return $this->canBarterWith($item) ? "action.interact.barter" : null;
	}

	private function canBarterWith(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::GOLD_INGOT && !$this->baby && $this->wantsItem($item);
	}

	/**
	 * Holds the item (default: a gold ingot) in its off hand and admires it; afterwards it barters it (gold ingots) or
	 * keeps it. $from is the player who gave or threw it, who gets the bartered item.
	 */
	public function startAdmiring(?Item $item = null, ?Player $from = null) : void{
		if(!$this->isAdmiring() && $this->offHandItem !== null){
			//the admired item goes in the off hand: a shield there is dropped (Java PiglinAi.holdInOffHand)
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), clone $this->offHandItem);
			$this->setGuaranteedDrop(MobEquipment::SLOT_OFFHAND, false);
		}
		$this->admireTicks = self::ADMIRE_TICKS;
		$this->admiredFrom = $from?->getId();
		$this->setOffHandItem($item ?? VanillaItems::GOLD_INGOT());
		$this->navigation->stop();
		$this->networkPropertiesDirty = true;
		$this->playMobSound(LevelSoundEvent::ADMIRE);
	}

	/**
	 * Returns a random item of the bartering table.
	 */
	public static function getBarterLoot() : Item{
		return PiglinBarter::roll();
	}

	private function finishAdmiring() : void{
		$item = $this->getOffHandItem();
		$this->admireTicks = 0;
		$this->setOffHandItem(VanillaItems::AIR());
		$this->networkPropertiesDirty = true;
		if($item->isNull()){
			return;
		}
		if($item->getTypeId() === ItemTypeIds::GOLD_INGOT && !$this->baby){
			$this->throwItem(self::getBarterLoot());
		}else{
			$this->keep($item);
		}
		$this->admiredFrom = null;
	}

	/**
	 * Stops admiring without bartering (hurt): the item is kept.
	 */
	private function interruptAdmiring() : void{
		$item = $this->getOffHandItem();
		$this->admireTicks = 0;
		$this->admiredFrom = null;
		$this->setOffHandItem(VanillaItems::AIR());
		$this->networkPropertiesDirty = true;
		if(!$item->isNull()){
			$this->keep($item);
		}
	}

	/**
	 * Wears golden armor when the slot is free, otherwise puts the item into the inventory (dropped when full).
	 */
	private function keep(Item $item) : void{
		if($item instanceof Armor && $this->armorInventory->getItem($slot = $item->getArmorSlot())->isNull()){
			$this->armorInventory->setItem($slot, $item);
			$this->armorKept |= 1 << $slot;
			return;
		}
		$left = $this->store($item);
		if($left !== null){
			$this->throwItem($left);
		}
	}

	/**
	 * Throws the item towards the player who gave the gold (or the nearest player within 16 blocks).
	 */
	private function throwItem(Item $item) : void{
		$player = $this->admiredFrom !== null ? $this->getWorld()->getEntity($this->admiredFrom) : null;
		if(!$player instanceof Player || !$player->isAlive() || $player->getPosition()->distanceSquared($this->location) > 16 * 16){
			$player = $this->sensing->getNearestPlayer(16.0);
		}
		$from = $this->location->add(0, $this->getEyeHeight() - 0.3, 0);
		if($player !== null){
			$diff = $player->getPosition()->subtractVector($this->location);
			$motion = $diff->lengthSquared() > 0.01 ? $diff->normalize()->multiply(min(0.3, max(0.1, ($diff->length() - 1.0) / 15)))->add(0, 0.2, 0) : new Vector3(0, 0.2, 0);
		}else{
			$motion = new Vector3(mt_rand(-10, 10) / 100, 0.2, mt_rand(-10, 10) / 100);
		}
		$this->getWorld()->dropItem($from, $item, $motion, 40);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$source instanceof EntityDamageByEntityEvent){
			return;
		}
		$damager = $source->getDamager();
		if($damager instanceof Living && !$damager instanceof Piglin && (!$damager instanceof Player || !$damager->isCreative())){
			$this->admireCooldown = self::ADMIRE_COOLDOWN_TICKS;
			if($this->isAdmiring()){
				//vanilla: being attacked interrupts the admiring, the gold is kept
				$this->interruptAdmiring();
			}
			$this->setAngryAt($damager, self::ANGER_TICKS);
			$this->alertNearby($damager, 16.0, Piglin::class, self::ANGER_TICKS);
			$this->alertNearby($damager, 16.0, PiglinBrute::class, PiglinBrute::ANGER_TICKS);
		}
	}

	protected function onAngerChange(bool $angry) : void{
		if($angry){
			$this->playMobSound(LevelSoundEvent::ANGRY);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$this->tickAnger($tickDiff);
		if($this->admireTicks > 0){
			$this->admireTicks -= $tickDiff;
			if($this->admireTicks <= 0){
				$this->finishAdmiring();
			}
			$hasUpdate = true;
		}
		if($this->admireCooldown > 0){
			$this->admireCooldown -= $tickDiff;
		}
		if($this->ateRecentlyTicks > 0){
			$this->ateRecentlyTicks -= $tickDiff;
		}
		if($this->huntCooldown > 0){
			$this->huntCooldown -= $tickDiff;
		}
		$this->repellentCheckTicks -= $tickDiff;
		if($this->celebrateTicks > 0){
			$this->celebrateTicks -= $tickDiff;
			if($this->celebrateTicks <= 0){
				$this->networkPropertiesDirty = true;
			}elseif(($this->celebrateSoundTicks -= $tickDiff) <= 0){
				$this->celebrateSoundTicks = mt_rand(40, 100);
				$this->playMobSound(LevelSoundEvent::CELEBRATE);
			}
		}
		if(($this->censusTicks -= $tickDiff) <= 0){
			$this->censusTicks = 20;
			if(!$this->baby){
				//(no count while the world has no hoglins: they can't outnumber the piglin itself, and the count has no
				//side effects; unless a subclass changes countAdultsAround())
				if($this->getWorld()->hasEntitiesOfClass(Hoglin::class) || !VanillaMethods::isDeclaredBy(static::class, "countAdultsAround", [self::class])){
					[$piglins, $hoglins] = $this->countAdultsAround();
					$this->hoglinsOutnumber = $hoglins > $piglins;
				}else{
					$this->hoglinsOutnumber = false;
				}
				$this->tryHunting();
			}
			//a player who put golden armor on is left alone, unless the piglin is angry at it
			$target = $this->getAttackTarget();
			if($target instanceof Player && self::wearsGold($target) && $target !== $this->getAngerTarget()){
				$this->setAttackTarget(null);
			}
		}
		$this->tickZombification($tickDiff);
		return $hasUpdate;
	}

	protected function zombify() : void{
		$world = $this->getWorld();
		$dropAt = $this->location->add(0, 0.5, 0);
		if($this->isAdmiring()){
			//Java PiglinAi.cancelAdmiring: the admired item is dropped
			$admired = $this->offHandItem;
			$this->admireTicks = 0;
			$this->admiredFrom = null;
			$this->offHandItem = null;
			if($admired !== null){
				$world->dropItem($dropAt, $admired);
			}
		}
		//transformation drop_inventory
		foreach($this->inventory as $item){
			$world->dropItem($dropAt, $item);
		}
		$this->inventory = [];
		//preserve_equipment: both hands and the armor; picked up gear (and kept golden armor) still always drops
		$data = MobEquipment::getConversionData($this);
		$data->setByte(MobEquipment::TAG_GUARANTEED_DROPS, $data->getByte(MobEquipment::TAG_GUARANTEED_DROPS, 0) | $this->armorKept);
		$zombified = new ZombifiedPiglin($this->location->asLocation(), $data);
		$zombified->setBaby($this->baby);
		$zombified->setMainHandItem($this->getMainHandItem());
		$zombified->setOffHandItem($this->getOffHandItem());
		MobConversion::copyState($this, $zombified);
		$zombified->spawnToAll();
		$zombified->playMobSound(LevelSoundEvent::CONVERTED_TO_ZOMBIFIED);
		$this->flagForDespawn();
	}

	public function getDrops() : array{
		$drops = $this->getHeldItemDrops();
		$chance = 85 + 10 * $this->getLootingLevel();
		foreach($this->armorInventory->getContents() as $slot => $item){
			if(($this->armorKept & (1 << $slot)) !== 0 || ($this->wasRecentlyHitByPlayer() && mt_rand(0, 999) < $chance)){
				$drops[] = $item;
			}
		}
		foreach($this->inventory as $item){
			$drops[] = $item;
		}
		$head = ChargedCreeperHeads::getHeadDrop($this);
		if($head !== null){
			$drops[] = $head;
		}
		return array_values($drops);
	}

	public function getXpDropAmount() : int{
		if(!$this->wasRecentlyHitByPlayer()){
			return 0;
		}
		if($this->baby){
			$xp = 1;
			for($i = $this->getEquipmentCount(); $i > 0; --$i){
				$xp += mt_rand(1, 2);
			}
			return $xp;
		}
		return $this->equipmentXp(5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::PIGLIN_SPAWN_EGG();
	}

	protected function despawnsInPeaceful() : bool{
		//spawn rules difficulty_filter min peaceful: they stay (and deal no damage)
		return false;
	}

	protected function isAngryWhenTargeting() : bool{
		//on_target_acquired adds the angry group (minecraft:angry)
		return true;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
		$properties->setGenericFlag(EntityMetadataFlags::ADMIRING, $this->isAdmiring());
		$properties->setGenericFlag(EntityMetadataFlags::CHARGE_ATTACK, $this->charging);
		$properties->setGenericFlag(EntityMetadataFlags::CELEBRATING, $this->isCelebrating());
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->isZombifying());
	}
}
