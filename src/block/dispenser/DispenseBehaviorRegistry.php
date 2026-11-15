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

namespace pocketmine\block\dispenser;

use pocketmine\block\Azalea;
use pocketmine\block\Bamboo;
use pocketmine\block\BambooSapling;
use pocketmine\block\BaseBigDripleaf;
use pocketmine\block\Beehive;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Bush;
use pocketmine\block\CakeWithCandle;
use pocketmine\block\Campfire;
use pocketmine\block\Candle;
use pocketmine\block\CarvedPumpkin;
use pocketmine\block\CaveVines;
use pocketmine\block\CocoaBlock;
use pocketmine\block\Crops;
use pocketmine\block\Dirt;
use pocketmine\block\DoublePitcherCrop;
use pocketmine\block\DoublePlant;
use pocketmine\block\DryGrass;
use pocketmine\block\FireflyBush;
use pocketmine\block\Flower;
use pocketmine\block\GlowLichen;
use pocketmine\block\Grass;
use pocketmine\block\Kelp;
use pocketmine\block\Leaves;
use pocketmine\block\Liquid;
use pocketmine\block\MangrovePropagule;
use pocketmine\block\MobHead;
use pocketmine\block\Moss;
use pocketmine\block\NetherFungus;
use pocketmine\block\Netherrack;
use pocketmine\block\NetherVines;
use pocketmine\block\Nylium;
use pocketmine\block\PaleHangingMoss;
use pocketmine\block\PaleMossCarpet;
use pocketmine\block\PinkPetals;
use pocketmine\block\PitcherCrop;
use pocketmine\block\PowderSnow;
use pocketmine\block\RedMushroom;
use pocketmine\block\RespawnAnchor;
use pocketmine\block\Sapling;
use pocketmine\block\Seagrass;
use pocketmine\block\SeaPickle;
use pocketmine\block\ShelfMushroom;
use pocketmine\block\ShulkerBox;
use pocketmine\block\SmallDripleaf;
use pocketmine\block\SoulFire;
use pocketmine\block\Sugarcane;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\TallGrass;
use pocketmine\block\tile\ShulkerBox as TileShulkerBox;
use pocketmine\block\TNT;
use pocketmine\block\TorchflowerCrop;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\DirtType;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\UltraWarmHelper;
use pocketmine\block\utils\UnderwaterBoneMeal;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Mooshroom;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\boss\WitherSummonPattern;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\CopperGolem;
use pocketmine\entity\golem\GolemConstruction;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Leashable;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Bogged;
use pocketmine\entity\monster\Illager;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\object\FireworkRocket as FireworkEntity;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\Egg as EggEntity;
use pocketmine\entity\projectile\ExperienceBottle as ExperienceBottleEntity;
use pocketmine\entity\projectile\IceBomb as IceBombEntity;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\Snowball as SnowballEntity;
use pocketmine\entity\projectile\SplashPotion as SplashPotionEntity;
use pocketmine\entity\projectile\Trident as TridentEntity;
use pocketmine\entity\projectile\WindCharge as WindChargeEntity;
use pocketmine\entity\vehicle\AbstractMinecart;
use pocketmine\entity\vehicle\BoatVariant;
use pocketmine\entity\vehicle\MinecartType;
use pocketmine\entity\Zombie;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\Armor;
use pocketmine\item\ArrowAmmo;
use pocketmine\item\Boat;
use pocketmine\item\ChestBoat;
use pocketmine\item\Durable;
use pocketmine\item\Egg;
use pocketmine\item\FireworkRocket;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\LiquidBucket;
use pocketmine\item\Minecart;
use pocketmine\item\MobBucket;
use pocketmine\item\Potion;
use pocketmine\item\PotionType;
use pocketmine\item\PowderSnowBucket;
use pocketmine\item\Raft;
use pocketmine\item\SpawnEgg;
use pocketmine\item\SplashPotion;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\dimension\NetherPortalShape;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BoneMealUseParticle;
use pocketmine\world\sound\BlazeShootSound;
use pocketmine\world\sound\BucketEmptyPowderSnowSound;
use pocketmine\world\sound\BucketFillPowderSnowSound;
use pocketmine\world\sound\FlintSteelSound;
use pocketmine\world\sound\IgniteSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function atan2;
use function cos;
use function count;
use function floor;
use function intdiv;
use function mt_rand;
use function rad2deg;
use function sin;
use const M_PI;

/**
 * Chooses what a dispenser does with each item. Items without a registered behaviour are dropped.
 *
 * Behaviours follow the Minecraft Wiki "Dispenser" page (Bedrock): projectiles are shot, liquids and blocks are
 * placed, tools are used on the block or the entities in front (shears, brushes, flint and steel...), armor is equipped
 * on a player, armor stand or armor-wearing mob in front. Minecarts are put on the rail in front (or below the block in
 * front), boats on the water in front, armor stands in front.
 */
final class DispenseBehaviorRegistry{
	use SingletonTrait;

	/**
	 * @var DispenseBehavior[]
	 * @phpstan-var array<int, DispenseBehavior>
	 */
	private array $byTypeId = [];

	private DispenseBehavior $default;
	private DispenseBehavior $armor;
	private DispenseBehavior $potion;
	private DispenseBehavior $waterBottle;
	private DispenseBehavior $liquidBucket;
	private DispenseBehavior $spawnEgg;
	private DispenseBehavior $shulkerBox;
	private DispenseBehavior $tnt;

	public function __construct(){
		$this->default = new DefaultDispenseBehavior();
		$this->registerVanilla();
	}

	/**
	 * Sets the behaviour of an item type, replacing any previous one.
	 */
	public function register(int $itemTypeId, DispenseBehavior $behavior) : void{
		$this->byTypeId[$itemTypeId] = $behavior;
	}

	public function getDefault() : DispenseBehavior{
		return $this->default;
	}

	public function get(Item $item) : DispenseBehavior{
		if(isset($this->byTypeId[$item->getTypeId()])){
			return $this->byTypeId[$item->getTypeId()];
		}
		if($item instanceof Armor || self::isWearableBlock($item)){
			return $this->armor;
		}
		if($item instanceof SplashPotion){
			return $this->potion;
		}
		if($item instanceof Potion){
			return $this->waterBottle;
		}
		if($item instanceof LiquidBucket || $item instanceof PowderSnowBucket){
			return $this->liquidBucket;
		}
		if($item instanceof SpawnEgg){
			return $this->spawnEgg;
		}
		if($item instanceof ItemBlock){
			$block = $item->getBlock();
			if($block instanceof ShulkerBox){
				return $this->shulkerBox;
			}
			if($block instanceof TNT){
				return $this->tnt;
			}
		}
		return $this->default;
	}

	private static function isWearableBlock(Item $item) : bool{
		if(!$item instanceof ItemBlock){
			return false;
		}
		$typeId = $item->getBlock()->getTypeId();
		return $typeId === BlockTypeIds::CARVED_PUMPKIN || $typeId === BlockTypeIds::MOB_HEAD;
	}

	private function registerVanilla() : void{
		$arrow = new ProjectileDispenseBehavior(function(Location $location, Item $item) : ArrowEntity{
			$entity = new ArrowEntity($location, null, false);
			ArrowAmmo::applyTip($item, $entity);
			return $entity;
		});
		$this->register(ItemTypeIds::ARROW, $arrow);
		$this->register(ItemTypeIds::TIPPED_ARROW, $arrow);

		$throwable = fn(\Closure $create) => new ProjectileDispenseBehavior($create);
		$this->register(ItemTypeIds::SNOWBALL, $throwable(fn(Location $location, Item $item) => new SnowballEntity($location, null)));
		$egg = $throwable(function(Location $location, Item $item) : EggEntity{
			$entity = new EggEntity($location, null);
			if($item instanceof Egg){
				$entity->setEggItem($item);
			}
			return $entity;
		});
		$this->register(ItemTypeIds::EGG, $egg);
		$this->register(ItemTypeIds::BLUE_EGG, $egg);
		$this->register(ItemTypeIds::BROWN_EGG, $egg);
		$this->register(ItemTypeIds::ICE_BOMB, $throwable(fn(Location $location, Item $item) => new IceBombEntity($location, null)));
		$this->register(ItemTypeIds::WIND_CHARGE, $throwable(fn(Location $location, Item $item) => new WindChargeEntity($location, null)));
		//thrown bottles and potions fly further and straighter (vanilla: power x1.25, uncertainty x0.5)
		$this->register(ItemTypeIds::EXPERIENCE_BOTTLE, new ProjectileDispenseBehavior(
			fn(Location $location, Item $item) => new ExperienceBottleEntity($location, null), 1.375, 3.0
		));
		$this->potion = new ProjectileDispenseBehavior(function(Location $location, Item $item) : SplashPotionEntity{
			$type = $item instanceof SplashPotion ? $item->getType() : PotionType::WATER;
			$entity = new SplashPotionEntity($location, null, $type);
			$entity->setLinger($item instanceof SplashPotion && $item->willLinger());
			return $entity;
		}, 1.375, 3.0);
		//Bedrock only
		$this->register(ItemTypeIds::TRIDENT, new ProjectileDispenseBehavior(
			fn(Location $location, Item $item) => new TridentEntity($location, $item, null), 2.5, 1.0
		));
		$this->register(ItemTypeIds::FIREWORK_ROCKET, new ProjectileDispenseBehavior(function(Location $location, Item $item) : FireworkEntity{
			$flight = $item instanceof FireworkRocket ? $item->getFlightTimeMultiplier() : 1;
			$explosions = $item instanceof FireworkRocket ? $item->getExplosions() : [];
			return new FireworkEntity($location, ($flight + 1) * 10 + mt_rand(0, 5) + mt_rand(0, 6), $explosions);
		}, 0.5, 1.0));
		$this->register(ItemTypeIds::FIRE_CHARGE, new ProjectileDispenseBehavior(
			fn(Location $location, Item $item) => new SmallFireball($location, null), 1.0, 1.0, new BlazeShootSound()
		));

		$this->armor = new CallbackDispenseBehavior($this->dispenseArmor(...));
		$this->waterBottle = new CallbackDispenseBehavior($this->dispenseWaterBottle(...));
		$this->liquidBucket = new CallbackDispenseBehavior($this->dispenseLiquidBucket(...));
		$this->spawnEgg = new CallbackDispenseBehavior($this->dispenseSpawnEgg(...));
		$this->shulkerBox = new CallbackDispenseBehavior($this->dispenseShulkerBox(...));
		$this->tnt = new CallbackDispenseBehavior($this->dispenseTnt(...));

		$this->register(ItemTypeIds::BUCKET, new CallbackDispenseBehavior($this->dispenseEmptyBucket(...)));
		$this->register(ItemTypeIds::GLASS_BOTTLE, new CallbackDispenseBehavior($this->dispenseGlassBottle(...)));
		$this->register(ItemTypeIds::BONE_MEAL, new CallbackDispenseBehavior($this->dispenseBoneMeal(...)));
		$this->register(ItemTypeIds::FLINT_AND_STEEL, new CallbackDispenseBehavior($this->dispenseFlintAndSteel(...)));
		$this->register(ItemTypeIds::HONEYCOMB, new CallbackDispenseBehavior($this->dispenseHoneycomb(...)));
		$this->register(ItemTypeIds::fromBlockTypeId(BlockTypeIds::GLOWSTONE), new CallbackDispenseBehavior($this->dispenseGlowstone(...)));
		$minecart = new CallbackDispenseBehavior($this->dispenseMinecart(...));
		foreach(MinecartType::cases() as $type){
			$this->register($type->getItem()->getTypeId(), $minecart);
		}
		$boat = new CallbackDispenseBehavior($this->dispenseBoat(...));
		foreach(BoatVariant::cases() as $variant){
			$this->register($variant->getBoatItem()->getTypeId(), $boat);
			$this->register($variant->getChestBoatItem()->getTypeId(), $boat);
		}
		$this->register(ItemTypeIds::SHEARS, new CallbackDispenseBehavior($this->dispenseShears(...)));
		$this->register(ItemTypeIds::BRUSH, new CallbackDispenseBehavior($this->dispenseBrush(...)));
		$this->register(ItemTypeIds::ARMOR_STAND, new CallbackDispenseBehavior($this->dispenseArmorStand(...)));
		$animalEquipment = new AnimalEquipmentDispenseBehavior($this->default);
		foreach(AnimalEquipmentDispenseBehavior::getItemTypeIds() as $typeId){
			$this->register($typeId, $animalEquipment);
		}
	}

	/**
	 * The block space in front of the dispenser: entities in it are the targets of shears, armor, brushes...
	 */
	private static function getTargetBox(DispenseContext $context) : AxisAlignedBB{
		$target = $context->getTargetPosition();
		return new AxisAlignedBB($target->x, $target->y, $target->z, $target->x + 1, $target->y + 1, $target->z + 1);
	}

	/**
	 * Damages a tool used by the dispenser; a broken tool leaves the slot.
	 */
	private static function damageTool(Item $stack, int $amount) : Item{
		if($stack instanceof Durable){
			$stack->applyDamage($amount);
		}
		return $stack;
	}

	private static function consumeOne(Item $stack) : Item{
		return $stack->setCount($stack->getCount() - 1);
	}

	/**
	 * Replaces one item of the stack with the given item: the slot gets the result if the stack had a single item,
	 * otherwise the result goes elsewhere in the dispenser or is dropped.
	 */
	private static function exchangeOne(DispenseContext $context, Item $stack, Item $result) : Item{
		if($stack->getCount() <= 1){
			return $result;
		}
		$context->addOrDrop($result);
		return self::consumeOne($stack);
	}

	/**
	 * Equips armor, carved pumpkins and heads on the first player, armor stand or armor-wearing mob (zombies,
	 * skeletons, piglins and illagers of all kinds: vanilla canEquipWithDispenser, Bedrock mobs which render armor) in
	 * front with that slot empty (vanilla EquipmentDispenseItemBehavior): on a mob the item always drops and the mob
	 * becomes persistent. A carved pumpkin or wither skeleton skull is placed instead when it completes a golem or a wither.
	 * Otherwise the item is dropped.
	 */
	private function dispenseArmor(DispenseContext $context, Item $stack) : ?Item{
		if($stack instanceof ItemBlock && $this->placeGolemOrWitherHead($context, $stack)){
			$context->playEffects();
			return self::consumeOne($stack);
		}
		$slot = $stack instanceof Armor ? $stack->getArmorSlot() : ArmorInventory::SLOT_HEAD;
		foreach($context->getWorld()->getNearbyEntities(self::getTargetBox($context)) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || ($entity instanceof Player && $entity->isSpectator())){
				continue;
			}
			$mob = $entity instanceof Zombie || $entity instanceof Skeleton || $entity instanceof WitherSkeleton ||
				$entity instanceof Piglin || $entity instanceof PiglinBrute || $entity instanceof Illager ? $entity : null;
			if($mob === null && !$entity instanceof Player && !$entity instanceof ArmorStand){
				continue; //cows, villagers, golems... don't wear armor
			}
			$armorInventory = $entity->getArmorInventory();
			if(!$armorInventory->getItem($slot)->isNull()){
				continue;
			}
			$item = (clone $stack)->setCount(1);
			if($mob !== null){
				$mob->equipDispensedArmor($slot, $item);
			}else{
				$armorInventory->setItem($slot, $item);
			}
			$context->playEffects();
			return self::consumeOne($stack);
		}
		return $this->default->dispense($context, $stack);
	}

	/**
	 * Places a carved pumpkin or wither skeleton skull in the empty block in front when it completes a snow, iron or
	 * copper golem or a wither, and builds it (Java DispenseItemBehavior carved pumpkin and wither skull behaviours).
	 */
	private function placeGolemOrWitherHead(DispenseContext $context, ItemBlock $stack) : bool{
		$world = $context->getWorld();
		$target = $context->getTargetBlock();
		if($target->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}
		$pos = $target->getPosition();
		$facing = $context->getFacing();
		$block = $stack->getBlock();
		if($block instanceof CarvedPumpkin){
			$x = $pos->getFloorX();
			$y = $pos->getFloorY();
			$z = $pos->getFloorZ();
			$head = Facing::axis($facing) !== Axis::Y ? (clone $block)->setFacing($facing) : clone $block;
			$getBlock = fn(int $bx, int $by, int $bz) : Block => $bx === $x && $by === $y && $bz === $z ? $head :
				($world->isInWorld($bx, $by, $bz) ? $world->getBlockAt($bx, $by, $bz) : VanillaBlocks::AIR());
			if(GolemConstruction::findPattern($getBlock, $x, $y, $z) === null){
				return false;
			}
			$world->setBlock($pos, $head);
			$world->addGameEvent($pos, GameEvent::BLOCK_PLACE);
			GolemConstruction::trySpawnGolem($world, $pos);
			return true;
		}
		if($block instanceof MobHead && $block->getMobHeadType() === MobHeadType::WITHER_SKELETON){
			if($world->getDifficulty() === World::DIFFICULTY_PEACEFUL || WitherSummonPattern::findIfPlaced($world, $pos) === null){
				return false;
			}
			//a floor skull turned like the dispenser (Java RotationSegment.convertToSegment(direction))
			$yaw = match($facing){
				Facing::SOUTH => 0,
				Facing::WEST => 90,
				Facing::NORTH => 180,
				default => 270
			};
			$world->setBlock($pos, (clone $block)->setFacing(Facing::UP)->setRotation(intdiv($yaw * 16, 360)));
			$world->addGameEvent($pos, GameEvent::BLOCK_PLACE);
			WitherSummonPattern::trySummon($world, $pos);
			return true;
		}
		return false;
	}

	/**
	 * Places an armor stand in front, turned away from the dispenser (Java DispenseItemBehavior armor stand).
	 */
	private function dispenseArmorStand(DispenseContext $context, Item $stack) : Item{
		$world = $context->getWorld();
		$pos = $context->getTargetPosition();
		$yaw = match($context->getFacing()){
			Facing::SOUTH => 0.0,
			Facing::WEST => 90.0,
			Facing::NORTH => 180.0,
			default => 270.0
		};
		$entity = new ArmorStand(Location::fromObject($pos->add(0.5, 0, 0.5), $world, $yaw, 0));
		if($stack->hasCustomName()){
			$entity->setNameTag($stack->getCustomName());
		}
		$entity->spawnToAll();
		$entity->playPlaceSound();
		$world->addGameEvent($pos, GameEvent::ENTITY_PLACE);
		$context->playEffects();
		return self::consumeOne($stack);
	}

	/**
	 * Java ShearsDispenseItemBehavior: harvests a full beehive in front (3 honeycomb, like a player), otherwise cuts
	 * the lead of a leashed mob there or shears the first shearable mob (sheep, mooshroom, snow golem, bogged, copper
	 * golem flower, sulfur cube block). The shears take 1 damage; nothing to shear makes the dispenser fail.
	 */
	private function dispenseShears(DispenseContext $context, Item $stack) : ?Item{
		$world = $context->getWorld();
		$target = $context->getTargetBlock();
		$pos = $target->getPosition();
		if($target instanceof Beehive && $target->getHoneyLevel() >= Beehive::MAX_HONEY_LEVEL){
			//onInteract() damages the shears
			$tool = clone $stack;
			if($target->onInteract($tool, Facing::opposite($context->getFacing()), new Vector3(0.5, 0.5, 0.5))){
				$world->addGameEvent($pos, GameEvent::SHEAR);
				$context->playEffects();
				return $tool;
			}
		}
		foreach($world->getNearbyEntities(self::getTargetBox($context)) as $entity){
			if($entity instanceof Player && $entity->isSpectator()){
				continue;
			}
			if($entity instanceof Leashable && $entity->isLeashed()){
				$entity->unleash(true);
			}elseif(!$entity->isAlive() || !self::shearEntity($entity)){
				continue;
			}
			$world->addGameEvent($pos, GameEvent::SHEAR);
			$context->playEffects();
			return self::damageTool($stack, 1);
		}
		return null;
	}

	private static function shearEntity(Entity $entity) : bool{
		return match(true){
			$entity instanceof Sheep => $entity->shear(),
			$entity instanceof Mooshroom => $entity->shear() !== null,
			$entity instanceof SnowGolem => $entity->shear(),
			$entity instanceof Bogged => $entity->shear(),
			$entity instanceof CopperGolem => $entity->shear(),
			$entity instanceof SulfurCube => $entity->ejectBlock() !== null,
			default => false
		};
	}

	/**
	 * Brushes a scute off the first adult armadillo in front; the brush loses 16 durability (Java
	 * DispenseItemBehavior brush, Bedrock 1.20.80+).
	 */
	private function dispenseBrush(DispenseContext $context, Item $stack) : ?Item{
		foreach($context->getWorld()->getNearbyEntities(self::getTargetBox($context)) as $entity){
			if($entity instanceof Armadillo && $entity->brush()){
				$context->playEffects();
				return self::damageTool($stack, 16);
			}
		}
		return null;
	}

	private function dispenseWaterBottle(DispenseContext $context, Item $stack) : ?Item{
		$target = $context->getTargetBlock();
		if($stack instanceof Potion && $stack->getType() === PotionType::WATER && $target instanceof Dirt && (
			$target->getDirtType() === DirtType::NORMAL || $target->getDirtType() === DirtType::COARSE || $target->getDirtType() === DirtType::ROOTED
		)){
			$context->getWorld()->setBlock($target->getPosition(), VanillaBlocks::MUD());
			$context->playEffects();
			return self::exchangeOne($context, $stack, VanillaItems::GLASS_BOTTLE());
		}
		return $this->default->dispense($context, $stack);
	}

	/**
	 * Java DispenseItemBehavior glass bottle: a full beehive in front gives a honey bottle (like a player), any water
	 * (flowing, or held by a waterlogged block) a water bottle; otherwise the bottle is dropped.
	 */
	private function dispenseGlassBottle(DispenseContext $context, Item $stack) : ?Item{
		$target = $context->getTargetBlock();
		if($target instanceof Beehive && $target->getHoneyLevel() >= Beehive::MAX_HONEY_LEVEL){
			$one = (clone $stack)->setCount(1);
			$returned = [];
			if($target->onInteract($one, Facing::opposite($context->getFacing()), new Vector3(0.5, 0.5, 0.5), null, $returned) && count($returned) > 0){
				$context->playEffects();
				return self::exchangeOne($context, $stack, $returned[0]);
			}
		}
		if(LiquidLayer::isWater($target)){
			$context->getWorld()->addGameEvent($context->getPosition(), GameEvent::FLUID_PICKUP);
			$context->playEffects();
			return self::exchangeOne($context, $stack, VanillaItems::POTION()->setType(PotionType::WATER));
		}
		return $this->default->dispense($context, $stack);
	}

	private function dispenseEmptyBucket(DispenseContext $context, Item $stack) : ?Item{
		$world = $context->getWorld();
		$target = $context->getTargetBlock();
		if($target instanceof Liquid && $target->isSource()){
			$result = match($target->getTypeId()){
				BlockTypeIds::WATER => VanillaItems::WATER_BUCKET(),
				BlockTypeIds::LAVA => VanillaItems::LAVA_BUCKET(),
				default => null
			};
			if($result !== null){
				$world->setBlock($target->getPosition(), VanillaBlocks::AIR());
				$world->addSound($target->getPosition()->add(0.5, 0.5, 0.5), $target->getBucketFillSound());
				$context->playEffects();
				return self::exchangeOne($context, $stack, $result);
			}
		}
		if(WaterloggedHelper::canDrain($target)){
			//Java BucketPickup: the block in front stays, dry
			WaterloggedHelper::drain($target);
			$world->addSound($target->getPosition()->add(0.5, 0.5, 0.5), VanillaBlocks::WATER()->getBucketFillSound());
			$world->addGameEvent($target->getPosition(), GameEvent::FLUID_PICKUP);
			$context->playEffects();
			return self::exchangeOne($context, $stack, VanillaItems::WATER_BUCKET());
		}
		if($target instanceof PowderSnow){
			$world->setBlock($target->getPosition(), VanillaBlocks::AIR());
			$world->addSound($target->getPosition()->add(0.5, 0.5, 0.5), new BucketFillPowderSnowSound());
			$context->playEffects();
			return self::exchangeOne($context, $stack, VanillaItems::POWDER_SNOW_BUCKET());
		}
		return $this->default->dispense($context, $stack);
	}

	private function dispenseLiquidBucket(DispenseContext $context, Item $stack) : ?Item{
		$world = $context->getWorld();
		$target = $context->getTargetBlock();
		$pos = $target->getPosition();
		$water = $stack instanceof LiquidBucket && $stack->getLiquid() instanceof Water;
		if($water && UltraWarmHelper::isUltraWarm($pos) && (WaterloggedHelper::canWaterlog($target) || $target->canBeReplaced())){
			//Java DispensibleContainerItem.emptyContents(): water evaporates in an ultra-warm dimension, the bucket is
			//emptied anyway; liquids count as replaceable there, so a lava source in front fizzes the water away too
			UltraWarmHelper::playEvaporation($world, $pos);
			if($stack instanceof MobBucket){
				$stack->releaseMob($world, $pos->add(0.5, 0, 0.5));
			}
			$context->playEffects();
			return self::exchangeOne($context, $stack, VanillaItems::BUCKET());
		}
		if($water && WaterloggedHelper::canWaterlog($target)){
			//Java DispensibleContainerItem.emptyContents(): the water goes into the waterloggable block in front
			WaterloggedHelper::waterlog($target);
			$world->addSound($pos->add(0.5, 0.5, 0.5), $stack->getLiquid()->getBucketEmptySound());
			$world->addGameEvent($pos, GameEvent::FLUID_PLACE);
			if($stack instanceof MobBucket){
				$stack->releaseMob($world, $pos->add(0.5, 0, 0.5));
			}
			$context->playEffects();
			return self::exchangeOne($context, $stack, VanillaItems::BUCKET());
		}
		if(!$target->canBeReplaced() || ($target instanceof Liquid && $target->isSource())){
			return $this->default->dispense($context, $stack);
		}
		if($stack instanceof LiquidBucket){
			$liquid = $stack->getLiquid();
			$world->setBlock($pos, $liquid->getFlowingForm());
			$world->addSound($pos->add(0.5, 0.5, 0.5), $liquid->getBucketEmptySound());
			if($stack instanceof MobBucket){
				$stack->releaseMob($world, $pos->add(0.5, 0, 0.5));
			}
		}else{
			$world->setBlock($pos, VanillaBlocks::POWDER_SNOW());
			$world->addSound($pos->add(0.5, 0.5, 0.5), new BucketEmptyPowderSnowSound());
		}
		$context->playEffects();
		return self::exchangeOne($context, $stack, VanillaItems::BUCKET());
	}

	/**
	 * Blocks which react to bone meal in their onInteract() without needing a player.
	 */
	private static function isFertilizable(Block $block) : bool{
		return
			$block instanceof Crops ||
			$block instanceof Sapling ||
			$block instanceof MangrovePropagule ||
			$block instanceof Azalea ||
			$block instanceof Grass ||
			$block instanceof TallGrass ||
			$block instanceof DryGrass ||
			$block instanceof Bamboo ||
			$block instanceof BambooSapling ||
			$block instanceof BaseBigDripleaf ||
			$block instanceof SmallDripleaf ||
			$block instanceof CaveVines ||
			$block instanceof CocoaBlock ||
			$block instanceof DoublePitcherCrop ||
			$block instanceof PitcherCrop ||
			$block instanceof TorchflowerCrop ||
			$block instanceof GlowLichen ||
			$block instanceof Moss ||
			$block instanceof PaleMossCarpet ||
			$block instanceof PaleHangingMoss ||
			$block instanceof NetherFungus ||
			$block instanceof NetherVines ||
			$block instanceof Netherrack ||
			$block instanceof Nylium ||
			$block instanceof PinkPetals ||
			$block instanceof SeaPickle ||
			$block instanceof ShelfMushroom ||
			$block instanceof Sugarcane ||
			$block instanceof SweetBerryBush ||
			$block instanceof RedMushroom ||
			$block instanceof Leaves ||
			$block instanceof Kelp ||
			$block instanceof Seagrass ||
			$block instanceof Flower ||
			$block instanceof DoublePlant ||
			$block instanceof Bush ||
			$block instanceof FireflyBush ||
			($block instanceof Dirt && $block->getDirtType() === DirtType::ROOTED);
	}

	private function dispenseBoneMeal(DispenseContext $context, Item $stack) : ?Item{
		$target = $context->getTargetBlock();
		if(!self::isFertilizable($target)){
			//vanilla falls back to growing water plants in the water in front
			$one = (clone $stack)->setCount(1);
			$pos = $target->getPosition();
			if(!$target instanceof Water || !UnderwaterBoneMeal::grow($context->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), null, $one, null) || !$one->isNull()){
				return null;
			}
			$context->playEffects();
			$context->getWorld()->addParticle($pos, new BoneMealUseParticle());
			return self::consumeOne($stack);
		}
		$one = (clone $stack)->setCount(1);
		$target->onInteract($one, Facing::opposite($context->getFacing()), new Vector3(0.5, 0.5, 0.5));
		if(!$one->isNull()){
			return null;
		}
		$context->playEffects();
		$context->getWorld()->addParticle($target->getPosition(), new BoneMealUseParticle());
		return self::consumeOne($stack);
	}

	/**
	 * Java FlintAndSteelDispenseItemBehavior: primes an explosive sulfur cube in front, otherwise places fire where
	 * fire can stay (on a full face, next to something flammable) or where it lights an empty portal frame, otherwise
	 * lights an unlit campfire, candle or candle cake, otherwise primes TNT. 1 durability per use.
	 */
	private function dispenseFlintAndSteel(DispenseContext $context, Item $stack) : ?Item{
		$world = $context->getWorld();
		$target = $context->getTargetBlock();
		$pos = $target->getPosition();
		$primed = false;
		foreach($world->getNearbyEntities(self::getTargetBox($context)) as $entity){
			if($entity instanceof SulfurCube && $entity->prime()){
				$primed = true;
				break;
			}
		}
		if($primed){
			//primed above
		}elseif($target->getTypeId() === BlockTypeIds::AIR && self::canPlaceFire($world, $target)){
			//new fire in an empty obsidian frame lights the portal (see NetherPortalShape::onFirePlaced())
			$world->setBlock($pos, SoulFire::canBeSupportedBy($world->getBlock($pos->down())) ? VanillaBlocks::SOUL_FIRE() : VanillaBlocks::FIRE());
			$world->addSound($pos->add(0.5, 0.5, 0.5), new FlintSteelSound());
			$world->addGameEvent($pos, GameEvent::BLOCK_PLACE);
		}elseif($target instanceof Campfire && !$target->isLit() && !LiquidLayer::holdsWater($target)){
			$tool = clone $stack;
			$target->onInteract($tool, Facing::opposite($context->getFacing()), new Vector3(0.5, 0.5, 0.5));
			$world->addGameEvent($pos, GameEvent::BLOCK_CHANGE);
		}elseif(($target instanceof Candle || $target instanceof CakeWithCandle) && !$target->isLit() && !LiquidLayer::holdsWater($target)){
			$world->setBlock($pos, $target->setLit(true));
			$world->addSound($pos, new FlintSteelSound());
			$world->addGameEvent($pos, GameEvent::BLOCK_CHANGE);
		}elseif($target instanceof TNT && $world->getGameRule(GameRules::TNT_EXPLODES) !== false){
			$target->ignite();
		}else{
			return null;
		}
		$context->playEffects();
		return self::damageTool($stack, 1);
	}

	/**
	 * Vanilla BaseFireBlock.canBePlacedAt() for an empty block: fire can stay there (a full top face below, or a
	 * flammable block next to it), or it is inside an empty nether portal frame.
	 */
	private static function canPlaceFire(World $world, Block $air) : bool{
		$below = $air->getSide(Facing::DOWN);
		if($below->getSupportType(Facing::UP) === SupportType::FULL || SoulFire::canBeSupportedBy($below)){
			return true;
		}
		foreach(Facing::ALL as $face){
			if($air->getSide($face)->isFlammable()){
				return true;
			}
		}
		return $world->getDimension() !== Dimension::END && NetherPortalShape::findIgnitable($world, $air->getPosition()) !== null;
	}

	private function dispenseHoneycomb(DispenseContext $context, Item $stack) : ?Item{
		$target = $context->getTargetBlock();
		if(!$target instanceof CopperMaterial || $target->isWaxed()){
			return null;
		}
		$target->setWaxed(true);
		$context->getWorld()->setBlock($target->getPosition(), $target);
		$context->playEffects();
		return self::consumeOne($stack);
	}

	private function dispenseGlowstone(DispenseContext $context, Item $stack) : ?Item{
		$target = $context->getTargetBlock();
		if(!$target instanceof RespawnAnchor){
			return $this->default->dispense($context, $stack);
		}
		$one = (clone $stack)->setCount(1);
		$target->onInteract($one, Facing::opposite($context->getFacing()), new Vector3(0.5, 0.5, 0.5));
		if(!$one->isNull()){
			return null; //fully charged
		}
		$context->playEffects();
		return self::consumeOne($stack);
	}

	private function dispenseSpawnEgg(DispenseContext $context, Item $stack) : ?Item{
		if(!$stack instanceof SpawnEgg){
			return $this->default->dispense($context, $stack);
		}
		if($stack->spawnEntity($context->getWorld(), $context->getTargetPosition()->add(0.5, 0, 0.5), Utils::getRandomFloat() * 360, 0) === null){
			return null; //no entity implementation for this mob
		}
		//Java DispenseItemBehavior: ENTITY_PLACE at the dispenser; spawn eggs make no click
		$context->getWorld()->addGameEvent($context->getPosition(), GameEvent::ENTITY_PLACE);
		return self::consumeOne($stack);
	}

	private function dispenseShulkerBox(DispenseContext $context, Item $stack) : ?Item{
		$world = $context->getWorld();
		$target = $context->getTargetBlock();
		$block = $stack->getBlock();
		if(!$block instanceof ShulkerBox || !$target->canBeReplaced()){
			return null;
		}
		$pos = $target->getPosition();
		$facing = $context->getFacing();
		//vanilla: the box opens towards the dispenser's facing, or upwards if it would not be supported from below
		if($facing !== Facing::UP && $world->getBlock($pos->down())->canBeReplaced()){
			$facing = Facing::UP;
		}
		$world->setBlock($pos, $block->setFacing($facing));
		$tile = $world->getTile($pos);
		if($tile instanceof TileShulkerBox){
			$tile->copyDataFromItem($stack);
		}
		$context->playEffects();
		return self::consumeOne($stack);
	}

	/**
	 * Puts the minecart on the rail in front, or on a rail below the air in front (vanilla
	 * MinecartDispenseItemBehavior); otherwise the item is dropped.
	 */
	private function dispenseMinecart(DispenseContext $context, Item $stack) : ?Item{
		if(!$stack instanceof Minecart){
			return $this->default->dispense($context, $stack);
		}
		$world = $context->getWorld();
		$facing = $context->getFacing();
		[$dx, $dy, $dz] = Facing::OFFSET[$facing];
		$center = $context->getPosition()->add(0.5, 0.5, 0.5);
		$front = $context->getTargetBlock();
		$shape = AbstractMinecart::getRailShape($front);
		$rail = $front;
		if($shape !== null){
			$yOffset = AbstractMinecart::isSlope($shape) ? 0.6 : 0.1;
		}elseif($front->getTypeId() === BlockTypeIds::AIR){
			$rail = $world->getBlock($front->getPosition()->down());
			$belowShape = AbstractMinecart::getRailShape($rail);
			if($belowShape === null){
				return $this->default->dispense($context, $stack);
			}
			$yOffset = $facing !== Facing::DOWN && AbstractMinecart::isSlope($belowShape) ? -0.4 : -0.9;
		}else{
			return $this->default->dispense($context, $stack);
		}
		$pos = new Vector3($center->x + $dx * 1.125, floor($center->y) + $dy + $yOffset, $center->z + $dz * 1.125);
		$stack->spawnMinecart($world, $pos, AbstractMinecart::getRailYaw($rail));
		$context->playEffects();
		return self::consumeOne($stack);
	}

	/**
	 * Puts the boat on the water in front, or on the water below the air in front (vanilla BoatDispenseItemBehavior),
	 * facing away from the dispenser; otherwise the item is dropped.
	 */
	private function dispenseBoat(DispenseContext $context, Item $stack) : ?Item{
		if(!$stack instanceof Boat && !$stack instanceof ChestBoat && !$stack instanceof Raft){
			return $this->default->dispense($context, $stack);
		}
		$world = $context->getWorld();
		$facing = $context->getFacing();
		[$dx, $dy, $dz] = Facing::OFFSET[$facing];
		$center = $context->getPosition()->add(0.5, 0.5, 0.5);
		$front = $context->getTargetBlock();
		if($front instanceof Water){
			$yOffset = 1.0;
		}elseif($front->getTypeId() === BlockTypeIds::AIR && $world->getBlock($front->getPosition()->down()) instanceof Water){
			$yOffset = 0.0;
		}else{
			return $this->default->dispense($context, $stack);
		}
		$distance = 0.5625 + 1.4 / 2;
		$pos = new Vector3($center->x + $dx * $distance, $center->y + $dy * 1.125 + $yOffset,$center->z + $dz * $distance);
		//the boat faces away from the dispenser; its Bedrock yaw is 90 degrees more than vanilla's
		$javaYaw = $dx === 0 && $dz === 0 ? 0.0 : rad2deg(atan2(-$dx, $dz));
		$stack->spawnBoat($world, $pos, $javaYaw + 90);
		$context->playEffects();
		return self::consumeOne($stack);
	}

	private function dispenseTnt(DispenseContext $context, Item $stack) : Item{
		$world = $context->getWorld();
		$block = $stack->getBlock();
		$target = $context->getTargetPosition();
		$mot = Utils::getRandomFloat() * M_PI * 2;
		$tnt = new PrimedTNT(Location::fromObject($target->add(0.5, 0, 0.5), $world));
		$tnt->setFuse(80);
		$tnt->setWorksUnderwater($block instanceof TNT && $block->worksUnderwater());
		$tnt->setMotion(new Vector3(-sin($mot) * 0.02, 0.2, -cos($mot) * 0.02));
		$tnt->spawnToAll();
		$tnt->broadcastSound(new IgniteSound());
		$context->playEffects();
		return self::consumeOne($stack);
	}
}
