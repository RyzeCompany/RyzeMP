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

use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BegGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowOwnerGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LeapAtTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\OwnerHurtByTargetGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\SitWhenOrderedGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Durable;
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function ceil;
use function count;
use function in_array;
use function max;
use function mt_rand;

/**
 * Bedrock values (entities/wolf.json): 8 health (40 when tamed), 0.6x0.8, movement 0.3, attack 3 (4 when tamed).
 * Tamed with bones (1 in 3), then sits when ordered, follows its owner (teleports beyond 12 blocks), attacks what
 * hurts the owner or what the owner attacks; bred (tamed, full health) with meat; red collar dyeable by the owner
 * (COLOR). Any player can heal any wolf with meat or fish (healable). Puppies take the variant of a random parent and
 * the mix of their parents' collar colours (combine_parent_colors, like dyes are crafted). Variants (VARIANT 0-8) by
 * biome as in 1.20.5+, sound variant property.
 *
 * Wild wolves hunt sheep, rabbits, foxes, skeletons and baby turtles and flee from llamas. A wild wolf that takes a
 * target (prey, or whoever hurt it) becomes angry for 25 seconds (red eyes, on_target_acquired -> become_angry): it
 * can't be tamed nor leashed and hunts nothing new, and the wild wolves within 20 blocks turn on the same target
 * (broadcast_anger).
 *
 * Wolves beg (behavior.beg, {@link BegGoal}): they look at a player within 8 blocks holding a bone or meat with their
 * head tilted (INTERESTED). Out of water they shake themselves dry (ActorEvent SHAKE_WET).
 *
 * Wolf armor (group wolf_armorable: tamed adults; the owner, not sneaking): using it puts it on (shown in the body
 * slot of MobArmorEquipmentPacket, saved, dropped on death); shears take it off; an armadillo scute repairs 8
 * durability of damaged armor on a sitting wolf. The armor takes the damage instead of the wolf (Java
 * Wolf::actuallyHurt(), {@link Wolf::MODIFIER_WOLF_ARMOR}) and loses that much durability (cracking sounds at 95%,
 * 69% and 32% left) until it breaks; drowning, freezing, magic, wither, thorns, starvation, suffocation, the void
 * and /kill go through (Java bypasses_wolf_armor).
 */
class Wolf extends TameableAnimal{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_COLLAR_COLOR = "CollarColor"; //TAG_Byte
	public const TAG_ANGRY_TICKS = "AngerTime"; //TAG_Int
	public const TAG_SOUND_VARIANT = "SoundVariant"; //TAG_Int
	public const TAG_WOLF_ARMOR = "WolfArmor"; //TAG_Compound (item, PocketMine)

	/** Damage modifier key of the damage the wolf armor took instead of the wolf. */
	public const MODIFIER_WOLF_ARMOR = 1002;
	/** minecraft:angry duration: 25 seconds. */
	public const ANGER_TICKS = 500;
	/** minecraft:angry broadcast_range. */
	public const ANGER_BROADCAST_RANGE = 20.0;
	/** repair_entity_item amount of an armadillo scute. */
	public const ARMOR_REPAIR = 8;

	/** Causes the wolf armor doesn't take (Java damage type tag bypasses_wolf_armor). */
	private const ARMOR_BYPASSING_CAUSES = [
		EntityDamageEvent::CAUSE_SUFFOCATION, EntityDamageEvent::CAUSE_DROWNING, EntityDamageEvent::CAUSE_VOID,
		EntityDamageEvent::CAUSE_SUICIDE, EntityDamageEvent::CAUSE_MAGIC, EntityDamageEvent::CAUSE_STARVATION,
		EntityDamageEvent::CAUSE_FREEZE, EntityDamageEvent::CAUSE_WITHER, EntityDamageEvent::CAUSE_THORNS
	];
	/** Reductions that don't apply when the armor takes the hit (Java skips them: the armor gets the damage before them). */
	private const ARMOR_SKIPPED_MODIFIERS = [
		EntityDamageEvent::MODIFIER_ARMOR, EntityDamageEvent::MODIFIER_RESISTANCE, EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS,
		EntityDamageEvent::MODIFIER_ABSORPTION, EntityDamageEvent::MODIFIER_ARMOR_HELMET, EntityDamageEvent::MODIFIER_TOTEM
	];
	/** Fractions of the durability left below which the armor looks more cracked (Java Crackiness.WOLF_ARMOR). */
	private const CRACK_FRACTIONS = [0.95, 0.69, 0.32];
	/** Collar colours of puppies whose parents wear different ones: the dye crafted from both (two-dye recipes). */
	private const COLLAR_MIXES = [
		[DyeColor::RED, DyeColor::YELLOW, DyeColor::ORANGE],
		[DyeColor::RED, DyeColor::WHITE, DyeColor::PINK],
		[DyeColor::BLUE, DyeColor::WHITE, DyeColor::LIGHT_BLUE],
		[DyeColor::GREEN, DyeColor::WHITE, DyeColor::LIME],
		[DyeColor::BLACK, DyeColor::WHITE, DyeColor::GRAY],
		[DyeColor::GRAY, DyeColor::WHITE, DyeColor::LIGHT_GRAY],
		[DyeColor::BLUE, DyeColor::RED, DyeColor::PURPLE],
		[DyeColor::BLUE, DyeColor::GREEN, DyeColor::CYAN],
		[DyeColor::PURPLE, DyeColor::PINK, DyeColor::MAGENTA]
	];
	/** Ticks of the shaking animation (Java: shakeAnim up to 2 by 0.05 per tick). */
	private const SHAKE_TICKS = 40;

	public const VARIANT_PALE = 0;
	public const VARIANT_ASHEN = 1;
	public const VARIANT_BLACK = 2;
	public const VARIANT_CHESTNUT = 3;
	public const VARIANT_RUSTY = 4;
	public const VARIANT_SNOWY = 5;
	public const VARIANT_SPOTTED = 6;
	public const VARIANT_STRIPED = 7;
	public const VARIANT_WOODS = 8;

	public const SOUND_VARIANTS = ["default", "big", "cute", "grumpy", "mad", "puglin", "sad"];

	/** Healing per food (Bedrock healable component). */
	private const FOOD_HEAL = [
		ItemTypeIds::RAW_PORKCHOP => 6, ItemTypeIds::COOKED_PORKCHOP => 16, ItemTypeIds::RAW_FISH => 4, ItemTypeIds::RAW_SALMON => 4,
		ItemTypeIds::CLOWNFISH => 2, ItemTypeIds::PUFFERFISH => 2, ItemTypeIds::COOKED_FISH => 10, ItemTypeIds::COOKED_SALMON => 12,
		ItemTypeIds::RAW_BEEF => 6, ItemTypeIds::STEAK => 16, ItemTypeIds::RAW_CHICKEN => 4, ItemTypeIds::COOKED_CHICKEN => 12,
		ItemTypeIds::RAW_MUTTON => 4, ItemTypeIds::COOKED_MUTTON => 12, ItemTypeIds::ROTTEN_FLESH => 8, ItemTypeIds::RAW_RABBIT => 6,
		ItemTypeIds::COOKED_RABBIT => 10, ItemTypeIds::RABBIT_STEW => 20,
	];
	/** Breeding food: meat, not fish (Bedrock breedable component). */
	private const BREEDING_FOOD = [
		ItemTypeIds::RAW_CHICKEN, ItemTypeIds::COOKED_CHICKEN, ItemTypeIds::RAW_BEEF, ItemTypeIds::STEAK, ItemTypeIds::RAW_MUTTON,
		ItemTypeIds::COOKED_MUTTON, ItemTypeIds::RAW_PORKCHOP, ItemTypeIds::COOKED_PORKCHOP, ItemTypeIds::RAW_RABBIT,
		ItemTypeIds::COOKED_RABBIT, ItemTypeIds::ROTTEN_FLESH,
	];

	private int $variant = self::VARIANT_PALE;
	private DyeColor $collarColor = DyeColor::RED;
	private int $angryTicks = 0;
	private ?Durable $armor = null;
	/** Begging (behavior.beg), see {@link BegGoal}. */
	private bool $interested = false;
	/** Was in water and hasn't shaken itself dry yet. */
	private bool $wet = false;
	private int $shakeTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::WOLF; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.8, 0.6, 0.68);
	}

	public function getName() : string{
		return "Wolf";
	}

	public function getSpawnBabyChance() : int{
		return 10; //entity_spawned: 9 adult / 1 baby
	}

	/**
	 * Wolf variant of a biome (Java 1.20.5 rules, Bedrock biome names).
	 */
	public static function variantForBiome(World $world, Vector3 $pos) : int{
		$name = MobBiomeInfo::getName($world, $pos);
		$tags = MobBiomeInfo::getTags($world, $pos);
		return match(true){
			$name === "mega_taiga" || $name === "mega_taiga_hills" => self::VARIANT_BLACK,
			$name === "redwood_taiga_mutated" || $name === "redwood_taiga_hills_mutated" => self::VARIANT_CHESTNUT,
			$name === "jungle_edge" || $name === "jungle_edge_mutated" => self::VARIANT_RUSTY,
			$name === "grove" => self::VARIANT_SNOWY,
			in_array("savanna", $tags, true) && in_array("plateau", $tags, true) => self::VARIANT_SPOTTED,
			$name === "mesa_plateau_stone" || $name === "mesa_plateau_stone_mutated" => self::VARIANT_STRIPED,
			$name === "forest" || $name === "forest_hills" => self::VARIANT_WOODS,
			in_array("taiga", $tags, true) && in_array("cold", $tags, true) => self::VARIANT_ASHEN,
			default => self::VARIANT_PALE
		};
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(8);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->variant = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 9 : self::variantForBiome($this->getWorld(), $this->location);
		$this->collarColor = DyeColorIdMap::getInstance()->fromId($nbt->getByte(self::TAG_COLLAR_COLOR, DyeColorIdMap::getInstance()->toId(DyeColor::RED))) ?? DyeColor::RED;
		$this->angryTicks = $nbt->getInt(self::TAG_ANGRY_TICKS, 0);
		if($this->isTamed()){
			$this->setMaxHealth(40);
		}
		$soundVariant = $nbt->getInt(self::TAG_SOUND_VARIANT, -1);
		$this->setActorProperty("minecraft:sound_variant", $soundVariant >= 0 && $soundVariant < count(self::SOUND_VARIANTS) ? $soundVariant : mt_rand(0, count(self::SOUND_VARIANTS) - 1));
		if(($armorTag = $nbt->getCompoundTag(self::TAG_WOLF_ARMOR)) !== null){
			$armor = Item::nbtDeserialize($armorTag);
			$this->armor = $armor instanceof Durable && !$armor->isNull() ? $armor : null;
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_SOUND_VARIANT, $this->getActorProperty("minecraft:sound_variant"));
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		$nbt->setByte(self::TAG_COLLAR_COLOR, DyeColorIdMap::getInstance()->toId($this->collarColor));
		$nbt->setInt(self::TAG_ANGRY_TICKS, $this->angryTicks);
		if($this->armor !== null){
			$nbt->setTag(self::TAG_WOLF_ARMOR, $this->armor->nbtSerialize());
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25, fn() => $this->isBaby()));
		//adults only flee fire-like damage, never hits (damage_sources, ignore_mob_damage)
		$this->goalSelector->addGoal(2, new PanicGoal($this, 1.25, null, PanicGoal::FIRE_CAUSES, true));
		$this->goalSelector->addGoal(3, new SitWhenOrderedGoal($this));
		//wolf_wild, which an angry wolf loses
		$this->goalSelector->addGoal(3, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Llama, 24.0, 1.5, 1.5, fn() => !$this->isTamed() && !$this->isAngry() && $this->getWorld()->hasEntitiesOfClass(Llama::class)));
		$this->goalSelector->addGoal(4, new LeapAtTargetGoal($this, 0.4));
		//attack 3 (4 when tamed, see attackMelee())
		$this->goalSelector->addGoal(5, new MeleeAttackGoal($this, 1.0, 3.0));
		$this->goalSelector->addGoal(6, new FollowOwnerGoal($this, fn() : ?Living => $this->isSitting() ? null : $this->getOwner(), 1.0, 10.0, 2.0));
		$this->goalSelector->addGoal(7, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(9, new BegGoal($this));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(10, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new OwnerHurtByTargetGoal($this));
		$this->targetSelector->addGoal(2, new OwnerHurtByTargetGoal($this, true));
		$this->targetSelector->addGoal(3, new HurtByTargetGoal($this));
		//wolf_wild: an angry wolf keeps its target but looks for no other (no reselection either)
		$this->targetSelector->addGoal(4, new NearestAttackableEntityGoal($this, fn(Living $e) => self::isPrey($e), 16.0, true, 10, fn() => !$this->isTamed() && !$this->isBaby() && !$this->isAngry() && self::worldHasPrey($this->getWorld()), reselect: true));
		$this->targetSelector->addGoal(5, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Skeleton, 16.0, true, 10, fn() => $this->isTamed() && !$this->isSitting(), Skeleton::class));
	}

	public static function isPrey(Living $entity) : bool{
		return $entity instanceof Sheep || $entity instanceof Rabbit || $entity instanceof Fox || $entity instanceof Skeleton ||
			($entity instanceof Turtle && $entity->isBaby() && !$entity->isInWater());
	}

	/**
	 * Whether the world holds any entity of a class {@link Wolf::isPrey()} may accept: the hunt doesn't look around in
	 * worlds without any.
	 */
	private static function worldHasPrey(World $world) : bool{
		return $world->hasEntitiesOfClass(Sheep::class) || $world->hasEntitiesOfClass(Rabbit::class) || $world->hasEntitiesOfClass(Fox::class) ||
			$world->hasEntitiesOfClass(Skeleton::class) || $world->hasEntitiesOfClass(Turtle::class);
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		return parent::attackMelee($target, $this->isTamed() ? 4.0 : 3.0);
	}

	protected function onTamedChanged() : void{
		if($this->isTamed()){
			$this->setMaxHealth(40);
			$this->setHealth(40);
			$this->angryTicks = 0;
		}else{
			$this->setMaxHealth(8);
		}
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	public function getCollarColor() : DyeColor{
		return $this->collarColor;
	}

	public function setCollarColor(DyeColor $color) : void{
		$this->collarColor = $color;
		$this->networkPropertiesDirty = true;
	}

	public function isAngry() : bool{
		return $this->angryTicks > 0;
	}

	public function setAngry(int $ticks) : void{
		$wasAngry = $this->isAngry();
		$this->angryTicks = $ticks;
		if($wasAngry !== $this->isAngry()){
			$this->networkPropertiesDirty = true;
			if(!$wasAngry){
				//become_angry removes wolf_leashable: the lead comes off
				$this->unleash(true);
			}
		}
	}

	public function isBreedingItem(Item $item) : bool{
		return in_array($item->getTypeId(), self::BREEDING_FOOD, true);
	}

	/**
	 * Returns whether wolves beg for the item (behavior.beg items: a bone or meat).
	 */
	public static function isBegItem(Item $item) : bool{
		$typeId = $item->getTypeId();
		return $typeId === ItemTypeIds::BONE || in_array($typeId, self::BREEDING_FOOD, true);
	}

	/**
	 * Whether the wolf is begging (head tilted, INTERESTED flag).
	 */
	public function isInterested() : bool{
		return $this->interested;
	}

	public function setInterested(bool $interested) : void{
		if($this->interested !== $interested){
			$this->interested = $interested;
			$this->networkPropertiesDirty = true;
		}
	}

	protected function canFallInLove(Player $player) : bool{
		return parent::canFallInLove($player) && $this->getHealth() >= $this->getMaxHealth();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Wolf($location);
		$other = $partner instanceof Wolf ? $partner : $this;
		$baby->setVariant(mt_rand(0, 1) === 0 ? $this->variant : $other->variant);
		$owner = $this->getOwner();
		if($owner !== null){
			$baby->setOwner($owner);
			$baby->setCollarColor(self::mixCollarColors($this->collarColor, $other->collarColor));
		}
		return $baby;
	}

	/**
	 * Collar colour of a puppy (offspring combine_parent_colors): the dye crafted from the parents' two dyes, or the
	 * colour of a random parent when no recipe mixes them.
	 */
	public static function mixCollarColors(DyeColor $a, DyeColor $b) : DyeColor{
		if($a === $b){
			return $a;
		}
		foreach(self::COLLAR_MIXES as [$first, $second, $mix]){
			if(($a === $first && $b === $second) || ($a === $second && $b === $first)){
				return $mix;
			}
		}
		return mt_rand(0, 1) === 0 ? $a : $b;
	}

	public function canBeLeashed() : bool{
		//become_angry removes wolf_leashable
		return !$this->isAngry();
	}

	public function setAttackTarget(?Living $target) : void{
		$previous = $this->attackTarget;
		parent::setAttackTarget($target);
		$target = $this->attackTarget;
		if($target !== null && $target !== $previous && !$this->isTamed() && !$this->isAngry() && $this->isAlive()){
			//wolf_wild on_target_acquired: become_angry
			$this->becomeAngry($target);
		}
	}

	/**
	 * Gets angry (minecraft:angry, 25 seconds) and makes the calm wild wolves within 20 blocks angry at the target too
	 * (broadcast_anger).
	 */
	private function becomeAngry(Living $target) : void{
		$this->setAngry(self::ANGER_TICKS);
		$r = self::ANGER_BROADCAST_RANGE;
		$loc = $this->location;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r / 2, $loc->z - $r, $loc->x + $r, $loc->y + $r / 2, $loc->z + $r), $this) as $other){
			if($other instanceof Wolf && !$other->isTamed() && !$other->isAngry() && $other->isAlive() && $other !== $target){
				//angry first: taking the target doesn't broadcast again
				$other->setAngry(self::ANGER_TICKS);
				$other->setAttackTarget($target);
			}
		}
	}

	public function applyDamageModifiers(EntityDamageEvent $source) : void{
		parent::applyDamageModifiers($source);
		if($this->armor === null || in_array($source->getCause(), self::ARMOR_BYPASSING_CAUSES, true)){
			return;
		}
		//Java Wolf::actuallyHurt(): the armor takes the hit instead of the wolf, before any reduction
		foreach(self::ARMOR_SKIPPED_MODIFIERS as $modifier){
			if($source->isApplicable($modifier)){
				$source->setModifier(0.0, $modifier);
			}
		}
		$source->setModifier(-$source->getFinalDamage(), self::MODIFIER_WOLF_ARMOR);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled()){
			return;
		}
		$absorbed = -$source->getModifier(self::MODIFIER_WOLF_ARMOR);
		if($absorbed > 0){
			$this->hurtWolfArmor((int) ceil($absorbed));
		}
		if(!$this->isAlive()){
			return;
		}
		if($this->isSitting() && $source instanceof EntityDamageByEntityEvent){
			$this->setSitting(false);
		}
		if(!$this->isTamed() && $source instanceof EntityDamageByEntityEvent && ($damager = $source->getDamager()) instanceof Living && $damager !== $this){
			if($this->isAngry()){
				$this->setAngry(self::ANGER_TICKS);
			}else{
				//the pack turns on the attacker too
				$this->becomeAngry($damager);
			}
		}
	}

	/**
	 * Returns the wolf armor worn, or null.
	 */
	public function getWolfArmor() : ?Item{
		return $this->armor !== null ? clone $this->armor : null;
	}

	/**
	 * Puts the wolf armor on (shown to viewers) or takes it off (null), without sounds.
	 */
	public function setWolfArmor(?Item $armor) : void{
		$this->armor = $armor instanceof Durable && !$armor->isNull() ? clone $armor : null;
		foreach($this->hasSpawned as $player){
			$this->sendWolfArmor($player);
		}
	}

	/**
	 * The armor loses durability for the damage it took (Unbreaking applies); it cracks a little more past some
	 * thresholds and breaks at the end.
	 */
	private function hurtWolfArmor(int $damage) : void{
		$armor = $this->armor;
		if($armor === null || $damage <= 0){
			return;
		}
		$before = self::crackLevel($armor);
		$armor->applyDamage($damage);
		if($armor->isBroken()){
			$this->setWolfArmor(null);
			$this->playMobSound(LevelSoundEvent::ARMOR_BREAK_WOLF);
			return;
		}
		if(self::crackLevel($armor) !== $before){
			$this->playMobSound(LevelSoundEvent::ARMOR_CRACK_WOLF);
		}
		$this->setWolfArmor($armor);
	}

	/**
	 * Crack level (0-3) of the armor, from the share of its durability left (Java Crackiness.WOLF_ARMOR; the client
	 * draws the cracks from the item's damage).
	 */
	private static function crackLevel(Durable $armor) : int{
		//Bedrock max_damage: PocketMine durabilities count one more use
		$max = max(1, $armor->getMaxDurability() - 1);
		$left = ($max - $armor->getDamage()) / $max;
		$level = 0;
		foreach(self::CRACK_FRACTIONS as $fraction){
			if($left < $fraction){
				++$level;
			}
		}
		return $level;
	}

	private function sendWolfArmor(Player $player) : void{
		$session = $player->getNetworkSession();
		$converter = $session->getTypeConverter();
		$inventory = $this->armorInventory;
		$session->sendDataPacket(MobArmorEquipmentPacket::create(
			$this->getId(),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inventory->getHelmet())),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inventory->getChestplate())),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inventory->getLeggings())),
			ItemStackWrapper::legacy($converter->coreItemStackToNet($inventory->getBoots())),
			$this->armor !== null ? ItemStackWrapper::legacy($converter->coreItemStackToNet($this->armor)) : new ItemStackWrapper(0, ItemStack::null())
		));
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->armor !== null){
			$this->sendWolfArmor($player);
		}
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		if($this->armor !== null){
			$drops[] = clone $this->armor;
		}
		return $drops;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->angryTicks > 0){
			$this->setAngry($this->getAttackTarget() !== null ? $this->angryTicks : $this->angryTicks - $tickDiff);
		}
		if($this->shakeTicks > 0){
			$this->shakeTicks -= $tickDiff;
		}
		if(($this->ticksLived & 3) === 0 && $this->isAlive()){
			$this->checkWetness();
		}
		return $hasUpdate;
	}

	/**
	 * Out of water, a wet wolf standing still on the ground shakes itself dry (Java Wolf::aiStep(), ActorEvent
	 * SHAKE_WET); back in the water while shaking, it stops (SHAKE_WETNESS_STOP). Checked every 4 ticks.
	 */
	private function checkWetness() : void{
		if($this->isInWater()){
			if($this->shakeTicks > 0){
				$this->shakeTicks = 0;
				$this->broadcastActorEvent(ActorEvent::SHAKE_WETNESS_STOP);
			}
			$this->wet = true;
		}elseif($this->wet && $this->shakeTicks <= 0 && $this->isOnGround() && $this->getNavigation()->isDone()){
			$this->wet = false;
			$this->shakeTicks = self::SHAKE_TICKS;
			$this->broadcastActorEvent(ActorEvent::SHAKE_WET);
			$this->playMobSound(LevelSoundEvent::SHAKE);
		}
	}

	/**
	 * Whether the wolf wears armor that can be put on, taken off or repaired by the player now (wolf_armorable: a tamed
	 * adult and its owner, not sneaking).
	 */
	private function isArmorableBy(Player $player) : bool{
		return $this->isTamed() && !$this->isBaby() && $this->isOwner($player) && !$player->isSneaking();
	}

	private function canRepairArmorWith(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::ARMADILLO_SCUTE && $this->isSitting() && $this->armor !== null && $this->armor->getDamage() > 0;
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($this->isArmorableBy($player)){
			$typeId = $item->getTypeId();
			if($typeId === ItemTypeIds::WOLF_ARMOR && $this->armor === null){
				return "action.interact.equipwolfarmor";
			}
			if($typeId === ItemTypeIds::SHEARS && $this->armor !== null){
				return "action.interact.removewolfarmor";
			}
			if($this->canRepairArmorWith($item)){
				return "action.interact.repairwolfarmor";
			}
		}
		return null;
	}

	/**
	 * The wolf_armorable interactions: putting wolf armor on, taking it off with shears, repairing it with an armadillo
	 * scute (sitting wolf). Returns whether one happened.
	 */
	private function interactArmor(Player $player, Item $item) : bool{
		if(!$this->isArmorableBy($player)){
			return false;
		}
		$typeId = $item->getTypeId();
		if($typeId === ItemTypeIds::WOLF_ARMOR && $this->armor === null){
			$this->setWolfArmor((clone $item)->setCount(1));
			$this->consumeHeldItem($player);
			$this->playMobSound(LevelSoundEvent::ARMOR_EQUIP_WOLF);
			return true;
		}
		if($typeId === ItemTypeIds::SHEARS && $this->armor !== null){
			$armor = $this->armor;
			$this->setWolfArmor(null);
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $armor);
			$this->damageHeldItem($player);
			$this->playMobSound(LevelSoundEvent::ARMOR_UNEQUIP_WOLF);
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			return true;
		}
		if($this->canRepairArmorWith($item) && $this->armor !== null){
			$armor = $this->armor;
			$armor->setDamage(max(0, $armor->getDamage() - self::ARMOR_REPAIR));
			$this->setWolfArmor($armor);
			$this->consumeHeldItem($player);
			$this->playMobSound(LevelSoundEvent::ARMOR_REPAIR_WOLF);
			return true;
		}
		return false;
	}

	/**
	 * Heals the wolf with the food (healable: any player, wild or tamed wolf, below full health). Returns whether it ate.
	 */
	private function tryHeal(Player $player, Item $item) : bool{
		$heal = self::FOOD_HEAL[$item->getTypeId()] ?? null;
		if($heal === null || $this->getHealth() >= $this->getMaxHealth()){
			return false;
		}
		$this->consumeHeldItem($player);
		$this->heal(new EntityRegainHealthEvent($this, $heal, EntityRegainHealthEvent::CAUSE_EATING));
		return true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->isTamed()){
			if($item->getTypeId() === ItemTypeIds::BONE && !$this->isAngry()){
				$this->consumeHeldItem($player);
				$this->tryTame($player, 1 / 3);
				return true;
			}
			return $this->tryHeal($player, $item) || parent::onInteractWithItem($player, $item);
		}
		if(!$this->isOwner($player)){
			return $this->tryHeal($player, $item);
		}
		if($this->interactArmor($player, $item)){
			return true;
		}
		if($item instanceof Dye){
			if($item->getColor() !== $this->collarColor){
				$this->setCollarColor($item->getColor());
				$this->consumeHeldItem($player);
			}
			return true;
		}
		if($this->tryHeal($player, $item) || parent::onInteractWithItem($player, $item)){
			return true;
		}
		$this->setSitting(!$this->isSitting());
		return true;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		//vanilla only sets the collar colour when tamed (minecraft:color in the tame group); the client tints the wild
		//texture with it, so a wild animal with colour 14 rendered all red
		$properties->setByte(EntityMetadataProperties::COLOR, $this->isTamed() ? DyeColorIdMap::getInstance()->toId($this->collarColor) : 0);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry());
		$properties->setGenericFlag(EntityMetadataFlags::INTERESTED, $this->interested);
	}
}
