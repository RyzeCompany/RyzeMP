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

namespace pocketmine\entity\animal;

use pocketmine\block\Carpet;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FollowCaravanGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\OwnerHurtByTargetGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RangedAttackGoal;
use pocketmine\entity\ai\ProjectileAim;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\projectile\LlamaSpit;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\HorseInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function max;
use function min;
use function mt_rand;

/**
 * Bedrock values (entities/llama.json): 15-30 health, movement 0.25, 0.9x1.87, follow range 40. Spits (1 damage,
 * every 2 seconds, from up to 64 blocks) at whoever hurts it or its owner and at untamed wolves within 10 blocks;
 * wolves run away from llamas. Broken in like a horse but with a max temper of 30 (wheat +3, hay bale +6). Tempted
 * and bred (tamed) with hay bales; wheat and hay heal it and make babies grow. Coat VARIANT 0-3 (creamy, white, brown,
 * gray); strength 1-5 (STRENGTH, add_attributes weights 32/32/32/2/2). A tamed adult wears a wool carpet (decor, in the saddle
 * slot of its inventory, shown in the body slot; shears take it off) and takes a chest of 3 slots per strength (vanilla
 * additional_slots_per_strength, see {@link AbstractHorse}).
 * Caravans ({@link FollowCaravanGoal}): leading a llama on a lead makes up to 10 llamas off a lead line up behind it;
 * a llama in a caravan doesn't get angry when hurt (in_caravan damage_sensor).
 * Loot (loot_tables/entities/llama.json): 0-2 leather (+0-1 per Looting level); XP 1-3.
 */
class Llama extends AbstractHorse{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_STRENGTH = "Strength"; //TAG_Int

	private int $coat = 0;
	private int $strength = 1;
	/** The llama it follows in a caravan, and the one following it (not saved, like vanilla). */
	private ?Llama $caravanHead = null;
	private ?Llama $caravanTail = null;
	/** Server tick of the last hit taken in a caravan, which it doesn't retaliate against. */
	private int $caravanHitTick = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::LLAMA; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.87, 0.9, 1.7);
	}

	public function getName() : string{
		return "Llama";
	}

	public function getSpawnBabyChance() : int{
		return 10; //entity_spawned: 90 adult / 10 baby (trader llamas too)
	}

	protected function randomStats() : array{
		return [mt_rand(15, 30), 0.25, 0.5];
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->coat = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 4 : mt_rand(0, 3);
		//before the parent: the size of the inventory depends on the strength
		$this->strength = $nbt->getTag(self::TAG_STRENGTH) !== null ? max(1, $nbt->getInt(self::TAG_STRENGTH) % 6) : self::randomStrength();
		$this->setFollowRange(40);
		parent::initEntity($nbt);
	}

	/**
	 * Strength of a new llama (add_attributes: weights 32/32/32/2/2 for 1-5).
	 */
	public static function randomStrength() : int{
		$roll = mt_rand(0, 99);
		return match(true){
			$roll < 32 => 1,
			$roll < 64 => 2,
			$roll < 96 => 3,
			$roll < 98 => 4,
			default => 5
		};
	}

	protected function getMaxTemper() : int{
		return 30; //tamemount max_temper (Java Llama.getMaxTemper)
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->coat);
		$nbt->setInt(self::TAG_STRENGTH, $this->strength);
		return $nbt;
	}

	protected function registerGoals() : void{
		parent::registerGoals();
		//llama_angry ranged_attack attack_radius 64 (the target is dropped beyond the follow range, 40)
		$this->goalSelector->addGoal(2, new RangedAttackGoal($this, 1.25, 40, 40, 64.0, $this->spitAt(...)));
		$this->goalSelector->addGoal(3, new FollowCaravanGoal($this, 2.1, 10));
		//behavior.panic has priority 4 (below follow_caravan) for llamas
		$this->goalSelector->addGoal(4, new PanicGoal($this, 1.2));
		$this->targetSelector->addGoal(1, new class($this, 40.0, $this->getIgnoredAttackers(), ignoresHit: fn() => $this->inCaravan() || $this->caravanHitTick === $this->getLastAttackedTick()) extends HurtByTargetGoal{
			/**
			 * @phpstan-param list<class-string<Living>> $ignoredAttackers
			 * @phpstan-param \Closure() : bool $ignoresHit
			 */
			public function __construct(Mob $mob, float $range, array $ignoredAttackers, private \Closure $ignoresHit){
				parent::__construct($mob, $range, $ignoredAttackers);
			}

			public function canStart() : bool{
				//in_caravan: the damage_sensor doesn't make it angry
				return !($this->ignoresHit)() && parent::canStart();
			}
		});
		//hurt_by_target hurt_owner: a tamed llama spits at whoever hurts its owner
		$this->targetSelector->addGoal(1, new OwnerHurtByTargetGoal($this, false, 40.0));
		$this->targetSelector->addGoal(2, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Wolf && !$e->isTamed(), 10.0, interval: 16 * 20, requiredClass: Wolf::class));
	}

	/**
	 * Attackers it doesn't spit back at.
	 *
	 * @return string[]
	 * @phpstan-return list<class-string<Living>>
	 */
	protected function getIgnoredAttackers() : array{
		return [];
	}

	protected function isAngryWhenTargeting() : bool{
		//llama_angry (hurt) and llama_angry_wolf (on_target_acquired) both have minecraft:angry
		return true;
	}

	protected function panicsWhenHurt() : bool{
		return false; //registered with its own priority, see registerGoals()
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->inCaravan()){
			$this->caravanHitTick = $this->getLastAttackedTick();
		}
	}

	/**
	 * Whether it follows another llama in a caravan.
	 */
	public function inCaravan() : bool{
		return $this->caravanHead !== null;
	}

	/**
	 * The llama it follows in a caravan, if any.
	 */
	public function getCaravanHead() : ?Llama{
		return $this->caravanHead;
	}

	/**
	 * Whether a llama follows it in a caravan.
	 */
	public function hasCaravanTail() : bool{
		$tail = $this->caravanTail;
		return $tail !== null && !$tail->isClosed() && $tail->caravanHead === $this;
	}

	/**
	 * Joins a caravan behind the llama (vanilla join_caravan).
	 */
	public function joinCaravan(Llama $head) : void{
		$this->leaveCaravan();
		$this->caravanHead = $head;
		$head->caravanTail = $this;
	}

	/**
	 * Leaves its caravan (vanilla leave_caravan); the llamas behind it stop following when they notice.
	 */
	public function leaveCaravan() : void{
		$head = $this->caravanHead;
		if($head !== null){
			if($head->caravanTail === $this){
				$head->caravanTail = null;
			}
			$this->caravanHead = null;
		}
	}

	protected function onDispose() : void{
		$this->leaveCaravan();
		$this->caravanTail = null;
		parent::onDispose();
	}

	public function spitAt(Living $target, float $distance) : void{
		$aim = ProjectileAim::aim($this, $target, 1.5, 0.05);
		if($aim === null){
			return;
		}
		[$location, $motion] = $aim;
		$spit = new LlamaSpit($location, $this);
		$spit->setBaseDamage(1);
		$spit->setMotion($motion);
		$spit->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
	}

	public function canWearSaddle() : bool{
		return false;
	}

	public function canHaveChest() : bool{
		return true;
	}

	public function getChestSize() : int{
		return 3 * $this->strength;
	}

	protected function getContainerSlotsPerStrength() : int{
		return 3;
	}

	protected function acceptsInEquipSlot(Item $item) : bool{
		return self::isCarpet($item);
	}

	/**
	 * Whether the item is a wool carpet (llama decor; moss carpets are not).
	 */
	public static function isCarpet(Item $item) : bool{
		return $item instanceof ItemBlock && $item->getBlock() instanceof Carpet;
	}

	/**
	 * Returns the carpet worn (air if none).
	 */
	public function getCarpet() : Item{
		return $this->inventory->getItem(HorseInventory::SLOT_EQUIP);
	}

	public function setCarpet(Item $carpet) : void{
		$this->inventory->setItem(HorseInventory::SLOT_EQUIP, $carpet);
	}

	public function getBodyItem() : Item{
		return $this->getCarpet();
	}

	protected function getEquipSound(int $slot, Item $item) : string{
		return LevelSoundEvent::ARMOR_EQUIP_GENERIC;
	}

	protected function getUnequipSound(int $slot) : string{
		//removecarpet plays armor.unequip_generic (llama_unchested) or unsaddle (llama_chested)
		return $this->isChested() ? LevelSoundEvent::UNSADDLE : LevelSoundEvent::ARMOR_UNEQUIP_GENERIC;
	}

	protected function dismountsRidersUnderwater() : bool{
		return false; //its behavior.float has no time_under_water_to_dismount_passengers
	}

	protected function getRiderSeat() : RideableSeat{
		return RideableSeat::at(0, 1.17, -0.3);
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return !$this->isBaby() && $entity instanceof Player;
	}

	protected function isTemptItem(Item $item) : bool{
		return $item->getTypeId() === VanillaBlocks::HAY_BALE()->asItem()->getTypeId();
	}

	protected function getFood(Item $item) : ?array{
		return match(true){
			$item->getTypeId() === ItemTypeIds::WHEAT => [2, 0.1, 3],
			$this->isTemptItem($item) => [10, 0.9, 6],
			default => null
		};
	}

	protected function breedsWith(Animal $other) : bool{
		return $other instanceof Llama;
	}

	public function getCoat() : int{ return $this->coat; }

	public function setCoat(int $coat) : void{
		$this->coat = $coat;
		$this->networkPropertiesDirty = true;
	}

	public function getStrength() : int{ return $this->strength; }

	public function setStrength(int $strength) : void{
		$this->strength = max(1, min(5, $strength));
		$this->resizeInventory();
		$this->networkPropertiesDirty = true;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Llama($location);
		$baby->setCoat(mt_rand(0, 1) === 0 || !$partner instanceof Llama ? $this->coat : $partner->getCoat());
		if($partner instanceof Llama){
			//Java: random strength up to the stronger parent's, plus a 3% chance of one more
			$baby->setStrength(mt_rand(1, max($this->strength, $partner->getStrength())) + (Utils::getRandomFloat() < 0.03 ? 1 : 0));
		}
		$this->inheritStats($baby, $partner);
		return $baby;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->coat);
		$properties->setInt(EntityMetadataProperties::STRENGTH, $this->strength);
		$properties->setInt(EntityMetadataProperties::MAX_STRENGTH, 5);
	}
}
