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

use pocketmine\block\Bed;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Chest;
use pocketmine\block\Furnace;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\TrappedChest;
use pocketmine\block\utils\DyeColor;
use pocketmine\crafting\FurnaceType;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\DropGiftForOwnerGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowOwnerGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LeapAtTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\PetSleepWithOwnerGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\SitOnBlockGoal;
use pocketmine\entity\ai\goal\SitWhenOrderedGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\World;
use function count;
use function max;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (entities/cat.json): 10 health (20 when tamed), 0.6x0.7 at scale 0.8 (babies 0.4), movement 0.3,
 * 4 damage, no fall damage. Tamed with raw cod or salmon (1 in 3), then sits when ordered, follows its owner, is bred
 * with fish (tamed, full health; sitting cats too: breedable allow_sitting) and healed with fish (2 health). Wild cats
 * hunt rabbits and baby turtles within 8 blocks and run from players within 10 blocks (walk 0.8, sprint 1.33).
 * 11 coats (VARIANT 0-10): spawn eggs pick any of them (entity_spawned), village cats any but the all black one
 * (spawn_from_village), and the witch hut cat is all black (a cat spawned by a structure: spawn_midnight_cat).
 * Dyeable collar (COLOR) once tamed. Sound variant property (default or royal), picked at random when a cat spawns,
 * and again when a kitten grows up (bred kittens have the default one until then).
 * Village cats stay in their village (move_towards_dwelling_restriction: within
 * {@link Cat::VILLAGE_DWELLING_RADIUS} blocks of where they appeared) while wild.
 * Loot (loot_tables/entities/cat.json): 0-2 string.
 * Tamed cats sleep on their owner's bed while the owner sleeps (SLEEPING flag, {@link PetSleepWithOwnerGoal}) and
 * may bring a morning gift from loot_tables/entities/cat_gift.json when the owner wakes up
 * ({@link DropGiftForOwnerGoal}); they also sit on chests, lit furnaces and bed feet ({@link SitOnBlockGoal}), which
 * keeps the chest closed. Creepers and phantoms avoid cats (see {@link \pocketmine\entity\monster\Creeper} and
 * {@link \pocketmine\entity\monster\Phantom}).
 */
class Cat extends TameableAnimal{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_COLLAR_COLOR = "CollarColor"; //TAG_Byte
	public const TAG_GIFT_FOR_OWNER = "GiftForOwner"; //TAG_Byte (PocketMine; Bedrock keeps the component group)
	public const TAG_SOUND_VARIANT = "SoundVariant"; //TAG_Int

	/** drop_item_chance of behavior.drop_item_for */
	public const GIFT_CHANCE = 0.7;
	/** Radius around their spawn point that wild village cats stay in (dwelling restriction, like villagers). */
	public const VILLAGE_DWELLING_RADIUS = 32;

	public const SOUND_VARIANTS = ["default", "royal"];

	public const VARIANT_WHITE = 0;
	public const VARIANT_TUXEDO = 1;
	public const VARIANT_RED = 2;
	public const VARIANT_SIAMESE = 3;
	public const VARIANT_BRITISH = 4;
	public const VARIANT_CALICO = 5;
	public const VARIANT_PERSIAN = 6;
	public const VARIANT_RAGDOLL = 7;
	public const VARIANT_TABBY = 8;
	public const VARIANT_BLACK = 9;
	public const VARIANT_JELLIE = 10;

	private int $variant = self::VARIANT_TABBY;
	private DyeColor $collarColor = DyeColor::RED;
	private bool $sleepingWithOwner = false;
	private bool $giftForOwner = false;
	private ?Vector3 $seat = null;
	/** Created without saved data and not ticked yet: the spawn reason may still pick the coat. */
	private bool $freshSpawn = false;

	public static function getNetworkTypeId() : string{ return EntityIds::CAT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.7, 0.6, 0.5);
	}

	public function getName() : string{
		return "Cat";
	}

	public function getSpawnBabyChance() : int{
		return 25; //entity_spawned: 3 adult / 1 baby
	}

	protected function getAdultScale() : float{
		return 0.8;
	}

	protected function getBabyScale() : float{
		return 0.4;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		if($nbt->getTag(self::TAG_VARIANT) !== null){
			$this->variant = $nbt->getInt(self::TAG_VARIANT) % 11;
		}else{
			//entity_spawned: the 11 coats, equally likely; the spawn reason may pick again (see setSpawnReason())
			$this->variant = mt_rand(self::VARIANT_WHITE, self::VARIANT_JELLIE);
			$this->freshSpawn = true;
		}
		$this->collarColor = DyeColorIdMap::getInstance()->fromId($nbt->getByte(self::TAG_COLLAR_COLOR, 14)) ?? DyeColor::RED;
		$this->giftForOwner = $nbt->getByte(self::TAG_GIFT_FOR_OWNER, 0) !== 0;
		if($this->isTamed()){
			$this->setMaxHealth(20);
		}
		$soundVariant = $nbt->getInt(self::TAG_SOUND_VARIANT, -1);
		$this->setActorProperty("minecraft:sound_variant", $soundVariant >= 0 && $soundVariant < count(self::SOUND_VARIANTS) ? $soundVariant : self::randomSoundVariant());
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		$nbt->setByte(self::TAG_COLLAR_COLOR, DyeColorIdMap::getInstance()->toId($this->collarColor));
		if($this->giftForOwner){
			$nbt->setByte(self::TAG_GIFT_FOR_OWNER, 1);
		}
		$nbt->setInt(self::TAG_SOUND_VARIANT, $this->getActorProperty("minecraft:sound_variant"));
		return $nbt;
	}

	/**
	 * randomize_sound_variant: default or royal, equally likely.
	 */
	private static function randomSoundVariant() : int{
		return mt_rand(0, count(self::SOUND_VARIANTS) - 1);
	}

	public function setSpawnReason(string $reason) : void{
		parent::setSpawnReason($reason);
		if(!$this->freshSpawn){
			return;
		}
		if($reason === SpawnReason::VILLAGE_CAT){
			//spawn_from_village: any coat but the all black one; kittens keep the default sound until they grow up
			$this->setVariant(mt_rand(self::VARIANT_WHITE, self::VARIANT_BLACK));
			if($this->variant === self::VARIANT_BLACK){
				$this->setVariant(self::VARIANT_JELLIE);
			}
			if($this->isBaby()){
				$this->setActorProperty("minecraft:sound_variant", 0);
			}
			$this->setHomeHere();
		}elseif($reason === SpawnReason::STRUCTURE){
			//spawn_midnight_cat: the witch hut cat, an all black adult
			$this->setVariant(self::VARIANT_BLACK);
			if($this->isBaby()){
				$this->setAge(0);
			}
		}elseif($reason === SpawnReason::BREEDING){
			//entity_born: kittens have the default sound until they grow up
			$this->setActorProperty("minecraft:sound_variant", 0);
		}
	}

	protected function onGrowUp() : void{
		parent::onGrowUp();
		//ageable_grow_up: randomize_sound_variant
		$this->setActorProperty("minecraft:sound_variant", self::randomSoundVariant());
	}

	protected function onFirstUpdate(int $currentTick) : void{
		$this->freshSpawn = false;
		parent::onFirstUpdate($currentTick);
	}

	public function getHomeRadius() : int{
		//cat_wild move_towards_dwelling_restriction; the home is where the village cat appeared
		return !$this->isTamed() && $this->getSpawnReason() === SpawnReason::VILLAGE_CAT ? self::VILLAGE_DWELLING_RADIUS : 0;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25));
		$this->goalSelector->addGoal(1, new DropGiftForOwnerGoal($this));
		$this->goalSelector->addGoal(2, new PetSleepWithOwnerGoal($this));
		//breedable allow_sitting: before stay_while_sitting, so that sitting cats in love still go to their partner
		$this->goalSelector->addGoal(2, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new SitOnBlockGoal($this));
		$this->goalSelector->addGoal(3, new SitWhenOrderedGoal($this));
		$this->goalSelector->addGoal(3, new LeapAtTargetGoal($this, 0.3));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 0.8, 4.0, reachMultiplier: 2.0)); //ocelotattack, attack_damage 4
		$this->goalSelector->addGoal(4, new FollowOwnerGoal($this, fn() : ?Living => $this->isSitting() ? null : $this->getOwner(), 1.0, 10.0, 2.0));
		//within_radius 16; wild cats can_get_scared
		$this->goalSelector->addGoal(5, new TemptGoal($this, 0.5, fn(Item $item) => $this->isBreedingItem($item), 16.0, canGetScared: fn() => !$this->isTamed()));
		//cat_wild: avoid_mob_type players within 10 blocks, then back to the village
		$this->goalSelector->addGoal(6, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Player, 10.0, 0.8, 1.33, fn() => !$this->isTamed(), true));
		$this->goalSelector->addGoal(7, new MoveTowardsHomeRestrictionGoal($this, 1.0, retryCooldown: 100));
		$this->goalSelector->addGoal(7, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 6.0));
		//max_dist 8 (within_radius 16: how far it is followed). The prey are rabbits and baby turtles: no search in
		//worlds without either (the filter would turn every entity down)
		$this->targetSelector->addGoal(1, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Rabbit || ($e instanceof Turtle && $e->isBaby() && !$e->isInWater()), 8.0, true, 10, fn() => !$this->isTamed() && $this->worldHasPrey(), reselect: true, maxDistance: 16.0));
	}

	private function worldHasPrey() : bool{
		$world = $this->getWorld();
		return $world->hasEntitiesOfClass(Rabbit::class) || $world->hasEntitiesOfClass(Turtle::class);
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0; //damage_sensor: fall deals no damage
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

	protected function onTamedChanged() : void{
		$this->setMaxHealth($this->isTamed() ? 20 : 10);
		if($this->isTamed()){
			$this->setHealth(20);
		}
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::RAW_FISH || $item->getTypeId() === ItemTypeIds::RAW_SALMON;
	}

	protected function canFallInLove(Player $player) : bool{
		return parent::canFallInLove($player) && $this->getHealth() >= $this->getMaxHealth();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Cat($location);
		$baby->setVariant(mt_rand(0, 1) === 0 || !$partner instanceof Cat ? $this->variant : $partner->getVariant());
		$owner = $this->getOwner();
		if($owner !== null){
			$baby->setOwner($owner);
		}
		return $baby;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->isTamed()){
			if($this->isBreedingItem($item) && !$this->isBaby()){
				$this->consumeHeldItem($player);
				$this->tryTame($player, 1 / 3);
				return true;
			}
			return parent::onInteractWithItem($player, $item);
		}
		if(!$this->isOwner($player)){
			return false;
		}
		if($item instanceof Dye){
			if($item->getColor() !== $this->collarColor){
				$this->setCollarColor($item->getColor());
				$this->consumeHeldItem($player);
			}
			return true;
		}
		if($this->isBreedingItem($item) && $this->getHealth() < $this->getMaxHealth()){
			$this->consumeHeldItem($player);
			$this->heal(new EntityRegainHealthEvent($this, 2, EntityRegainHealthEvent::CAUSE_EATING));
			return true;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		$this->setSitting(!$this->isSitting());
		return true;
	}

	public function isSleepingWithOwner() : bool{
		return $this->sleepingWithOwner;
	}

	/**
	 * Hops on the given bed block and falls asleep there (the owner is sleeping in the bed).
	 */
	public function sleepWithOwnerOn(Vector3 $bed) : void{
		$this->hopOnto($bed);
		$this->sleepingWithOwner = true;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Wakes the cat up. If the owner woke up too, the cat now has a morning gift for them (on_wake_with_owner).
	 */
	public function stopSleepingWithOwner(bool $ownerWokeUp) : void{
		if(!$this->sleepingWithOwner){
			return;
		}
		$this->sleepingWithOwner = false;
		$this->networkPropertiesDirty = true;
		if($ownerWokeUp){
			$this->giftForOwner = true;
		}
	}

	public function hasGiftForOwner() : bool{
		return $this->giftForOwner;
	}

	public function setGiftForOwner(bool $gift) : void{
		$this->giftForOwner = $gift;
	}

	/**
	 * Drop attempt of the morning gift: 70% of the time an item of the cat_gift loot table lands next to the owner.
	 * The cat has no gift left afterwards either way.
	 */
	public function offerGift(Player $owner) : ?ItemEntity{
		if(!$this->giftForOwner){
			return null;
		}
		$this->giftForOwner = false;
		if(mt_rand() / mt_getrandmax() >= self::GIFT_CHANCE){
			return null;
		}
		$motion = $owner->getPosition()->subtractVector($this->location)->normalize()->multiply(0.2)->add(0, 0.2, 0);
		return $this->getWorld()->dropItem($this->location->add(0, 0.3, 0), self::rollGift(), $motion, 0);
	}

	/**
	 * One roll of loot_tables/entities/cat_gift.json.
	 */
	public static function rollGift() : Item{
		$roll = mt_rand(1, 62);
		return match(true){
			$roll <= 10 => VanillaItems::RABBIT_HIDE(),
			$roll <= 20 => VanillaItems::RABBIT_FOOT(),
			$roll <= 30 => VanillaItems::RAW_CHICKEN(),
			$roll <= 40 => VanillaItems::FEATHER(),
			$roll <= 50 => VanillaItems::ROTTEN_FLESH(),
			$roll <= 60 => VanillaItems::STRING(),
			default => VanillaItems::PHANTOM_MEMBRANE()
		};
	}

	/**
	 * Block the cat sits on (chest, lit furnace or bed), if any.
	 */
	public function getSeat() : ?Vector3{
		return $this->seat;
	}

	/**
	 * Hops on the given block and sits there, or gets up (null).
	 */
	public function setSittingOnBlock(?Vector3 $block) : void{
		if($block !== null){
			$this->hopOnto($block);
		}
		if(($this->seat === null) !== ($block === null)){
			$this->networkPropertiesDirty = true;
		}
		$this->seat = $block?->floor();
	}

	/**
	 * Moves the cat on top of the block (cats hop onto beds and chests).
	 */
	private function hopOnto(Vector3 $block) : void{
		$block = $block->floor();
		$top = $block->y + 1;
		$boxes = $this->getWorld()->getBlock($block)->getCollisionBoxes();
		if(count($boxes) > 0){
			$top = $block->y;
			foreach($boxes as $box){
				$top = max($top, $box->maxY);
			}
		}
		$this->teleport(new Vector3($block->x + 0.5, $top, $block->z + 0.5));
	}

	/**
	 * Whether cats like to sit on the block at the position: a chest nobody is using, a lit furnace or the foot of a
	 * bed, with nothing on top.
	 */
	public static function isSeat(World $world, int $x, int $y, int $z) : bool{
		$block = $world->getBlockAt($x, $y, $z);
		if($block instanceof Chest){
			if($block instanceof TrappedChest){
				return false;
			}
			$tile = $world->getTileAt($x, $y, $z);
			if(!$tile instanceof TileChest || count($tile->getInventory()->getViewers()) > 0){
				return false;
			}
		}elseif($block instanceof Furnace){
			if(!$block->isLit() || $block->getFurnaceType() !== FurnaceType::FURNACE){
				return false;
			}
		}elseif($block instanceof Bed){
			if($block->isHeadPart()){
				return false;
			}
		}else{
			return false;
		}
		return $world->isInWorld($x, $y + 1, $z) && $world->getBlockAt($x, $y + 1, $z)->getTypeId() === BlockTypeIds::AIR;
	}

	/**
	 * Whether a cat (other than $except) sits on the block at the position. Chests with a cat on top don't open.
	 */
	public static function isCatSittingOn(World $world, int $x, int $y, int $z, ?Cat $except = null) : bool{
		foreach($world->getNearbyEntities(new AxisAlignedBB($x, $y + 0.5, $z, $x + 1, $y + 2, $z + 1), $except) as $entity){
			$seat = $entity instanceof Cat ? $entity->getSeat() : null;
			if($seat !== null && $seat->getFloorX() === $x && $seat->getFloorY() === $y && $seat->getFloorZ() === $z){
				return true;
			}
		}
		return false;
	}

	protected function getAdultDrops() : array{
		$count = mt_rand(0, 2);
		return $count > 0 ? [VanillaItems::STRING()->setCount($count)] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		//vanilla only sets the collar colour when tamed (minecraft:color in the tame group); the client tints the wild
		//texture with it, so a wild animal with colour 14 rendered all red
		$properties->setByte(EntityMetadataProperties::COLOR, $this->isTamed() ? DyeColorIdMap::getInstance()->toId($this->collarColor) : 0);
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->isSitting() || $this->seat !== null);
		$properties->setGenericFlag(EntityMetadataFlags::SLEEPING, $this->sleepingWithOwner);
	}
}
