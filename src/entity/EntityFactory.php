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

use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\LegacyEntityIdToStringIdMap;
use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\data\bedrock\PotionTypeIds;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\EntityDataHelper as Helper;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\HostileMobs;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Spider;
use pocketmine\entity\monster\Warden;
use pocketmine\entity\object\AreaEffectCloud;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\object\EndCrystal;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\OminousItemSpawner;
use pocketmine\entity\object\Painting;
use pocketmine\entity\object\PaintingMotive;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\DangerousWitherSkull;
use pocketmine\entity\projectile\DragonFireball;
use pocketmine\entity\projectile\Egg;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\projectile\ExperienceBottle;
use pocketmine\entity\projectile\IceBomb;
use pocketmine\entity\projectile\SmallFireball;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\entity\projectile\Trident;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\entity\projectile\WitherSkull;
use pocketmine\entity\vehicle\Boats;
use pocketmine\entity\vehicle\Minecarts;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtException;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\World;
use function count;
use function reset;
use function str_starts_with;

/**
 * This class manages the creation of entities loaded from disk.
 * You need to register your entity into this factory if you want to load/save your entity on disk (saving with chunks).
 */
final class EntityFactory{
	use SingletonTrait;

	public const TAG_IDENTIFIER = "identifier"; //TAG_String
	public const TAG_LEGACY_ID = "id"; //TAG_Int

	/**
	 * @var \Closure[] save ID => creator function
	 * @phpstan-var array<int|string, \Closure(World, CompoundTag) : Entity>
	 */
	private array $creationFuncs = [];
	/**
	 * @var string[]
	 * @phpstan-var array<class-string<Entity>, string>
	 */
	private array $saveNames = [];

	/** Save names of the PocketMine-MP 5.44.3 mobs that VanillaMine rewrote with their vanilla AI. */
	private const REWRITTEN_SAVE_NAMES = ["Squid", "minecraft:squid", "Villager", "minecraft:villager", "Zombie", "minecraft:zombie"];

	/**
	 * The "minecraft:" save names of VanillaMine's own mobs (see the end of the constructor). A plugin that registers
	 * one of them again still loads, but saved entities with that name keep loading as ours.
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $keepBuiltIn = [];

	public function __construct(){
		//define legacy save IDs first - use them for saving for maximum compatibility with Minecraft PC
		//TODO: index them by version to allow proper multi-save compatibility

		$this->register(AreaEffectCloud::class, function(World $world, CompoundTag $nbt) : AreaEffectCloud{
			return new AreaEffectCloud(Helper::parseLocation($nbt, $world), $nbt);
		}, ['AreaEffectCloud', 'minecraft:area_effect_cloud']);

		$this->register(ArmorStand::class, function(World $world, CompoundTag $nbt) : ArmorStand{
			return new ArmorStand(Helper::parseLocation($nbt, $world), $nbt);
		}, ['ArmorStand', 'minecraft:armor_stand']);

		$this->register(Arrow::class, function(World $world, CompoundTag $nbt) : Arrow{
			return new Arrow(Helper::parseLocation($nbt, $world), null, $nbt->getByte(Arrow::TAG_CRIT, 0) === 1, $nbt);
		}, ['Arrow', 'minecraft:arrow']);

		$this->register(Egg::class, function(World $world, CompoundTag $nbt) : Egg{
			return new Egg(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['Egg', 'minecraft:egg']);

		$this->register(EndCrystal::class, function(World $world, CompoundTag $nbt) : EndCrystal{
			return new EndCrystal(Helper::parseLocation($nbt, $world), $nbt);
		}, ['EnderCrystal', 'minecraft:ender_crystal']);

		$this->register(EnderPearl::class, function(World $world, CompoundTag $nbt) : EnderPearl{
			return new EnderPearl(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['ThrownEnderpearl', 'minecraft:ender_pearl']);

		$this->register(ExperienceBottle::class, function(World $world, CompoundTag $nbt) : ExperienceBottle{
			return new ExperienceBottle(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['ThrownExpBottle', 'minecraft:xp_bottle']);

		$this->register(ExperienceOrb::class, function(World $world, CompoundTag $nbt) : ExperienceOrb{
			$value = 1;
			if(($valuePcTag = $nbt->getTag(ExperienceOrb::TAG_VALUE_PC)) instanceof ShortTag){ //PC
				$value = $valuePcTag->getValue();
			}elseif(($valuePeTag = $nbt->getTag(ExperienceOrb::TAG_VALUE_PE)) instanceof IntTag){ //PE save format
				$value = $valuePeTag->getValue();
			}

			return new ExperienceOrb(Helper::parseLocation($nbt, $world), $value, $nbt);
		}, ['XPOrb', 'minecraft:xp_orb']);

		$this->register(FallingBlock::class, function(World $world, CompoundTag $nbt) : FallingBlock{
			return new FallingBlock(Helper::parseLocation($nbt, $world), FallingBlock::parseBlockNBT(RuntimeBlockStateRegistry::getInstance(), $nbt), $nbt);
		}, ['FallingSand', 'minecraft:falling_block']);

		$this->register(IceBomb::class, function(World $world, CompoundTag $nbt) : IceBomb{
			return new IceBomb(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['minecraft:ice_bomb']);

		$this->register(ItemEntity::class, function(World $world, CompoundTag $nbt) : ItemEntity{
			$itemTag = $nbt->getCompoundTag(ItemEntity::TAG_ITEM);
			if($itemTag === null){
				throw new SavedDataLoadingException("Expected \"" . ItemEntity::TAG_ITEM . "\" NBT tag not found");
			}

			$item = Item::nbtDeserialize($itemTag);
			if($item->isNull()){
				throw new SavedDataLoadingException("Item is invalid");
			}
			return new ItemEntity(Helper::parseLocation($nbt, $world), $item, $nbt);
		}, ['Item', 'minecraft:item']);

		$this->register(OminousItemSpawner::class, function(World $world, CompoundTag $nbt) : OminousItemSpawner{
			return new OminousItemSpawner(Helper::parseLocation($nbt, $world), VanillaItems::AIR(), $nbt);
		}, ['OminousItemSpawner', 'minecraft:ominous_item_spawner']);

		$this->register(Painting::class, function(World $world, CompoundTag $nbt) : Painting{
			$motive = PaintingMotive::getMotiveByName($nbt->getString(Painting::TAG_MOTIVE));
			if($motive === null){
				throw new SavedDataLoadingException("Unknown painting motive");
			}
			$blockIn = new Vector3($nbt->getInt(Painting::TAG_TILE_X), $nbt->getInt(Painting::TAG_TILE_Y), $nbt->getInt(Painting::TAG_TILE_Z));
			if(($directionTag = $nbt->getTag(Painting::TAG_DIRECTION_BE)) instanceof ByteTag){
				$facing = Painting::DATA_TO_FACING[$directionTag->getValue()] ?? Facing::NORTH;
			}elseif(($facingTag = $nbt->getTag(Painting::TAG_FACING_JE)) instanceof ByteTag){
				$facing = Painting::DATA_TO_FACING[$facingTag->getValue()] ?? Facing::NORTH;
			}else{
				throw new SavedDataLoadingException("Missing facing info");
			}

			return new Painting(Helper::parseLocation($nbt, $world), $blockIn, $facing, $motive, $nbt);
		}, ['Painting', 'minecraft:painting']);

		$this->register(PrimedTNT::class, function(World $world, CompoundTag $nbt) : PrimedTNT{
			return new PrimedTNT(Helper::parseLocation($nbt, $world), $nbt);
		}, ['PrimedTnt', 'PrimedTNT', 'minecraft:tnt']);

		$this->register(Snowball::class, function(World $world, CompoundTag $nbt) : Snowball{
			return new Snowball(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['Snowball', 'minecraft:snowball']);

		$this->register(WindCharge::class, function(World $world, CompoundTag $nbt) : WindCharge{
			return new WindCharge(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['minecraft:wind_charge_projectile']);

		$this->register(SmallFireball::class, function(World $world, CompoundTag $nbt) : SmallFireball{
			return new SmallFireball(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['SmallFireball', 'minecraft:small_fireball']);

		$this->register(SplashPotion::class, function(World $world, CompoundTag $nbt) : SplashPotion{
			$potionType = PotionTypeIdMap::getInstance()->fromId($nbt->getShort(SplashPotion::TAG_POTION_ID, PotionTypeIds::WATER));
			if($potionType === null){
				throw new SavedDataLoadingException("No such potion type");
			}
			return new SplashPotion(Helper::parseLocation($nbt, $world), null, $potionType, $nbt);
		}, ['ThrownPotion', 'minecraft:potion', 'thrownpotion']);

		$this->register(Trident::class, function(World $world, CompoundTag $nbt) : Trident{
			$itemTag = $nbt->getCompoundTag(Trident::TAG_ITEM);
			if($itemTag === null){
				throw new SavedDataLoadingException("Expected \"" . Trident::TAG_ITEM . "\" NBT tag not found");
			}

			$item = Item::nbtDeserialize($itemTag);
			if($item->isNull()){
				throw new SavedDataLoadingException("Trident item is invalid");
			}
			return new Trident(Helper::parseLocation($nbt, $world), $item, null, $nbt);
		}, [
			'minecraft:trident', //java
			'minecraft:thrown_trident', //bedrock
			'Trident', //backwards compat for people who used #4547 before it was merged, since it was sitting around for 4 years...
			'ThrownTrident' //as above
		]);

		$this->register(Squid::class, function(World $world, CompoundTag $nbt) : Squid{
			return new Squid(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Squid', 'minecraft:squid']);

		$this->register(Villager::class, function(World $world, CompoundTag $nbt) : Villager{
			return new Villager(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Villager', 'minecraft:villager']);

		$this->register(Zombie::class, function(World $world, CompoundTag $nbt) : Zombie{
			return new Zombie(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Zombie', 'minecraft:zombie']);

		$this->register(Chicken::class, function(World $world, CompoundTag $nbt) : Chicken{
			return new Chicken(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Chicken', 'minecraft:chicken']);

		$this->register(Cow::class, function(World $world, CompoundTag $nbt) : Cow{
			return new Cow(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Cow', 'minecraft:cow']);

		$this->register(Pig::class, function(World $world, CompoundTag $nbt) : Pig{
			return new Pig(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Pig', 'minecraft:pig']);

		$this->register(Sheep::class, function(World $world, CompoundTag $nbt) : Sheep{
			return new Sheep(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Sheep', 'minecraft:sheep']);

		$this->register(Creeper::class, function(World $world, CompoundTag $nbt) : Creeper{
			return new Creeper(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Creeper', 'minecraft:creeper']);

		$this->register(Skeleton::class, function(World $world, CompoundTag $nbt) : Skeleton{
			return new Skeleton(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Skeleton', 'minecraft:skeleton']);

		$this->register(Spider::class, function(World $world, CompoundTag $nbt) : Spider{
			return new Spider(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Spider', 'minecraft:spider']);

		$this->register(Human::class, function(World $world, CompoundTag $nbt) : Human{
			return new Human(Helper::parseLocation($nbt, $world), Human::parseSkinNBT($nbt), $nbt);
		}, ['Human']);

		HostileMobs::registerEntities($this);
		MobsPhase2::registerSaveData($this);
		Minecarts::registerEntities($this);
		Boats::registerEntities($this);
		$this->registerBossMobs();
	}

	/**
	 * Mobs phase 4: the bosses (ender dragon, wither, warden) and their projectiles.
	 */
	private function registerBossMobs() : void{
		$this->register(Warden::class, function(World $world, CompoundTag $nbt) : Warden{
			return new Warden(Helper::parseLocation($nbt, $world), $nbt);
		}, ['Warden', 'minecraft:warden']);

		$this->register(Wither::class, function(World $world, CompoundTag $nbt) : Wither{
			return new Wither(Helper::parseLocation($nbt, $world), $nbt);
		}, ['WitherBoss', 'minecraft:wither']);

		$this->register(WitherSkull::class, function(World $world, CompoundTag $nbt) : WitherSkull{
			return new WitherSkull(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['WitherSkull', 'minecraft:wither_skull']);

		$this->register(DangerousWitherSkull::class, function(World $world, CompoundTag $nbt) : DangerousWitherSkull{
			return new DangerousWitherSkull(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['WitherSkullDangerous', 'minecraft:wither_skull_dangerous']);

		$this->register(EnderDragon::class, function(World $world, CompoundTag $nbt) : EnderDragon{
			return new EnderDragon(Helper::parseLocation($nbt, $world), $nbt);
		}, ['EnderDragon', 'minecraft:ender_dragon']);

		$this->register(DragonFireball::class, function(World $world, CompoundTag $nbt) : DragonFireball{
			return new DragonFireball(Helper::parseLocation($nbt, $world), null, $nbt);
		}, ['DragonFireball', 'minecraft:dragon_fireball']);

		//Plugins written for PocketMine-MP 5.44.3 may register mobs that VanillaMine now has (e.g. the Mobs plugin's
		//"Creeper"), and both must keep working. VanillaMine's own mobs (the added ones, plus squid, villager and zombie,
		//which got their vanilla AI) save under their "minecraft:" name, and only those names are kept from plugins: the
		//short names ("Creeper") stay free for the plugin, so its saved mobs come back as its own and ours as ours.
		$ours = [];
		foreach([...AddedEntitySaveNames::LIST, ...self::REWRITTEN_SAVE_NAMES] as $name){
			$ours[$name] = true;
		}
		foreach($this->saveNames as $className => $saveName){
			if(!isset($ours[$saveName])){
				continue;
			}
			$creationFunc = $this->creationFuncs[$saveName];
			foreach($this->creationFuncs as $name => $func){
				$name = (string) $name; //numeric save names become int keys
				if($func === $creationFunc && str_starts_with($name, "minecraft:")){
					$this->saveNames[$className] = $name;
					$this->keepBuiltIn[$name] = true;
				}
			}
		}
	}

	/**
	 * Registers an entity type into the index.
	 *
	 * @param string   $className Class that extends Entity
	 * @param string[] $saveNames An array of save names which this entity might be saved under.
	 * @phpstan-param class-string<Entity> $className
	 * @phpstan-param list<string> $saveNames
	 * @phpstan-param \Closure(World $world, CompoundTag $nbt) : Entity $creationFunc
	 *
	 * NOTE: The first save name in the $saveNames array will be used when saving the entity to disk.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function register(string $className, \Closure $creationFunc, array $saveNames) : void{
		if(count($saveNames) === 0){
			throw new \InvalidArgumentException("At least one save name must be provided");
		}
		Utils::testValidInstance($className, Entity::class);
		Utils::validateCallableSignature(new CallbackType(
			new ReturnType(Entity::class),
			new ParameterType("world", World::class),
			new ParameterType("nbt", CompoundTag::class)
		), $creationFunc);

		foreach($saveNames as $name){
			if(isset($this->keepBuiltIn[$name])){
				\GlobalLogger::get()->warning("A plugin tried to register the entity \"$name\" ($className), which this server already provides: saved \"$name\" entities keep loading as the built-in one (register it under a name of its own)");
				continue;
			}
			$this->creationFuncs[$name] = $creationFunc;
		}

		$this->saveNames[$className] = reset($saveNames);
	}

	/**
	 * @phpstan-param class-string<Entity> $class
	 */
	public function isRegistered(string $class) : bool{
		return isset($this->saveNames[$class]);
	}

	/**
	 * Creates an entity from data stored on a chunk.
	 *
	 * @throws SavedDataLoadingException
	 * @internal
	 */
	public function createFromData(World $world, CompoundTag $nbt) : ?Entity{
		try{
			$saveId = $nbt->getTag(self::TAG_IDENTIFIER) ?? $nbt->getTag(self::TAG_LEGACY_ID);
			$func = null;
			if($saveId instanceof StringTag){
				$func = $this->creationFuncs[$saveId->getValue()] ?? null;
			}elseif($saveId instanceof IntTag){ //legacy MCPE format
				$stringId = LegacyEntityIdToStringIdMap::getInstance()->legacyToString($saveId->getValue() & 0xff);
				$func = $stringId !== null ? $this->creationFuncs[$stringId] ?? null : null;
			}
			if($func === null){
				return null;
			}
			/** @var Entity $entity */
			$entity = $func($world, $nbt);

			return $entity;
		}catch(NbtException $e){
			throw new SavedDataLoadingException($e->getMessage(), 0, $e);
		}
	}

	public function injectSaveId(string $class, CompoundTag $saveData) : void{
		if(isset($this->saveNames[$class])){
			$saveData->setTag(self::TAG_IDENTIFIER, new StringTag($this->saveNames[$class]));
		}else{
			throw new \InvalidArgumentException("Entity $class is not registered");
		}
	}

	/**
	 * @phpstan-param class-string<Entity> $class
	 */
	public function getSaveId(string $class) : string{
		if(isset($this->saveNames[$class])){
			return $this->saveNames[$class];
		}
		throw new \InvalidArgumentException("Entity $class is not registered");
	}
}
