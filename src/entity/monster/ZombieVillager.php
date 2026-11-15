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

use pocketmine\block\Bed;
use pocketmine\block\BlockTypeIds;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\Villager;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function floor;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (zombie_villager_v2.json): a zombie with a villager profession (VARIANT 0-14) and biome skin
 * (MARK_VARIANT 0-6, the villager type).
 *
 * Curing (vanilla ZombieVillager): feeding a golden apple to a zombie villager that has Weakness (e.g. from a splash
 * potion) shows the "Cure" button, gives it Strength I for 5 minutes (Bedrock to_villager spell_effects) and makes it shake for 3 to 5 minutes (3600-6000 ticks). Each
 * tick there is a 1% chance to look for beds and iron bars within 4 blocks: each one found (up to 14) has a 30% chance
 * to speed the cure up by one tick per tick. The villager it turns back into keeps everything it had when it was
 * zombified (profession, trades, level, gossip; see {@link ZombieVillager::fromVillager()}) and gives the curer the
 * cure discount. The items the zombie villager picked up are dropped (Bedrock transformation drop_equipment).
 */
class ZombieVillager extends Zombie{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_MARK_VARIANT = "MarkVariant"; //TAG_Int
	public const TAG_CONVERSION_TICKS = "ConversionTicks"; //TAG_Int (PocketMine)
	public const TAG_CURER = "PmCurer"; //TAG_String, UUID of the player who is curing it
	public const TAG_VILLAGER_DATA = "PmVillagerData"; //TAG_Compound, the villager it was before being zombified

	public const PROFESSION_COUNT = 15;
	public const SKIN_COUNT = 7;

	public const CURE_MIN_TICKS = 3600;
	public const CURE_MAX_TICKS = 6000;
	private const CURE_BLOCK_RADIUS = 4;
	private const CURE_BLOCK_MAX = 14;
	/** Bedrock to_villager spell_effects: Strength (amplifier 0) for 300 seconds, on every difficulty. */
	private const CURE_STRENGTH_TICKS = 6000;

	private int $profession = 0;
	private int $skin = 0;
	/** Ticks left before becoming a villager, -1 when not being cured. */
	private int $conversionTicks = -1;
	/** UUID of the player curing the zombie villager: the villager will give them the cure discount. */
	private ?string $curer = null;
	/** Saved data of the villager this zombie villager was, restored when it is cured. */
	private ?CompoundTag $villagerData = null;

	public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE_VILLAGER_V2; }

	/**
	 * Creates (without spawning) the zombie villager a villager turns into when a zombie kills it. It remembers the
	 * villager, which comes back as it was when cured.
	 */
	public static function fromVillager(VillagerV2 $villager) : self{
		//vanilla entity_transformed adds no can_break_doors (only entity_spawned rolls it)
		$zombie = new self(
			Location::fromObject($villager->getPosition(), $villager->getWorld(), $villager->getLocation()->yaw, $villager->getLocation()->pitch),
			CompoundTag::create()->setByte("CanBreakDoors", 0)
		);
		$zombie->setProfession($villager->getProfession());
		$zombie->skin = $villager->getVillagerType() % self::SKIN_COUNT;
		$zombie->setBaby($villager->isBaby());
		$zombie->villagerData = $villager->saveNBT();
		if($villager->getNameTag() !== ""){
			$zombie->setNameTag($villager->getNameTag());
		}
		$zombie->setPersistent(true);
		return $zombie;
	}

	public function getName() : string{
		return "Zombie Villager";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->profession = $nbt->getInt(self::TAG_VARIANT, mt_rand(0, self::PROFESSION_COUNT - 1)) % self::PROFESSION_COUNT;
		$this->skin = $nbt->getInt(self::TAG_MARK_VARIANT, mt_rand(0, self::SKIN_COUNT - 1)) % self::SKIN_COUNT;
		$this->conversionTicks = $nbt->getInt(self::TAG_CONVERSION_TICKS, -1);
		$curer = $nbt->getString(self::TAG_CURER, "");
		$this->curer = $curer !== "" ? $curer : null;
		$this->villagerData = $nbt->getCompoundTag(self::TAG_VILLAGER_DATA);
	}

	/**
	 * zombie_villager_v2.json has no equipment table: zombie villagers spawn empty-handed.
	 */
	protected function getSpawnEquipmentTable() : ?string{
		return null;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->profession);
		$nbt->setInt(self::TAG_MARK_VARIANT, $this->skin);
		$nbt->setInt(self::TAG_CONVERSION_TICKS, $this->conversionTicks);
		if($this->curer !== null){
			$nbt->setString(self::TAG_CURER, $this->curer);
		}
		if($this->villagerData !== null){
			$nbt->setTag(self::TAG_VILLAGER_DATA, clone $this->villagerData);
		}
		return $nbt;
	}

	public function getProfession() : int{ return $this->profession; }

	public function setProfession(int $profession) : void{
		$this->profession = $profession;
		$this->networkPropertiesDirty = true;
	}

	public function isConverting() : bool{
		return $this->conversionTicks >= 0;
	}

	public function getConversionTicks() : int{
		return $this->conversionTicks;
	}

	/**
	 * Whether the golden apple in the player's hand would start the cure.
	 */
	private function canBeCuredWith(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::GOLDEN_APPLE && !$this->isConverting() && $this->getEffects()->has(VanillaEffects::WEAKNESS());
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		return $this->canBeCuredWith($item) ? "action.interact.cure" : null;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->canBeCuredWith($item)){
			$this->consumeHeldItem($player);
			$this->startConversion(mt_rand(self::CURE_MIN_TICKS, self::CURE_MAX_TICKS), $player->getUniqueId()->toString());
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	/**
	 * Starts curing the zombie villager: it becomes a villager after the given number of ticks, which gives the curing
	 * player (UUID) the cure discount.
	 */
	public function startConversion(int $ticks, ?string $curer = null) : void{
		$this->conversionTicks = $ticks;
		$this->curer = $curer;
		$effects = $this->getEffects();
		$effects->remove(VanillaEffects::WEAKNESS());
		$effects->add(new EffectInstance(VanillaEffects::STRENGTH(), self::CURE_STRENGTH_TICKS));
		$this->playMobSound(LevelSoundEvent::REMEDY);
		$this->networkPropertiesDirty = true;
	}

	/**
	 * How many ticks of the cure pass this tick (vanilla getConversionProgress()): 1, plus up to 14 when beds or iron
	 * bars are near, checked 1% of the time.
	 */
	public function getConversionSpeed() : int{
		$speed = 1;
		if(mt_rand() / mt_getrandmax() >= 0.01){
			return $speed;
		}
		$world = $this->getWorld();
		$found = 0;
		$r = self::CURE_BLOCK_RADIUS;
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		for($bx = $x - $r; $bx < $x + $r && $found < self::CURE_BLOCK_MAX; ++$bx){
			for($by = $y - $r; $by < $y + $r && $found < self::CURE_BLOCK_MAX; ++$by){
				for($bz = $z - $r; $bz < $z + $r && $found < self::CURE_BLOCK_MAX; ++$bz){
					if(!$world->isInWorld($bx, $by, $bz)){
						continue;
					}
					$block = $world->getBlockAt($bx, $by, $bz);
					if($block instanceof Bed || $block->getTypeId() === BlockTypeIds::IRON_BARS){
						if(mt_rand(0, 9) < 3){
							++$speed;
						}
						++$found;
					}
				}
			}
		}
		return $speed;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive() || $this->conversionTicks < 0){
			return $hasUpdate;
		}
		$this->conversionTicks -= $this->getConversionSpeed() * $tickDiff;
		if($this->conversionTicks < 0){
			$this->finishConversion();
		}
		return true;
	}

	/**
	 * Turns into a villager now: the villager it was before being zombified if known, otherwise a new villager of the
	 * same profession and type.
	 */
	public function finishConversion() : ?Living{
		$location = $this->location->asLocation();
		if($this->villagerData !== null){
			//saved when it died: comes back with full health, no effects and not burning
			$data = clone $this->villagerData;
			$data->removeTag("Health", "HealF", "ActiveEffects", "Fire");
			$villager = new VillagerV2($location, $data);
		}else{
			$registry = EntitySpawnRegistry::getInstance();
			$villager = $registry->create(EntityIds::VILLAGER_V2, $location) ?? $registry->create(EntityIds::VILLAGER, $location);
			if($villager instanceof VillagerV2){
				$villager->setAge($this->isBaby() ? -24000 : 0); //before the profession: a new villager may be a baby
				$villager->setProfession($this->profession); //same Bedrock profession ids
				$villager->setVillagerType($this->skin);
			}elseif($villager instanceof Villager){
				$villager->setProfession(self::toLegacyProfession($this->profession));
			}
		}
		if($villager === null){
			return null;
		}
		if($villager instanceof VillagerV2 && $this->curer !== null){
			$villager->onCuredBy($this->curer);
		}
		if($this->getNameTag() !== ""){
			$villager->setNameTag($this->getNameTag());
		}
		$villager->spawnToAll();
		if($villager instanceof Living){
			$villager->getEffects()->add(new EffectInstance(VanillaEffects::NAUSEA(), 200));
		}
		$this->playMobSound(LevelSoundEvent::UNFECT);
		//transformation drop_equipment: the items it picked up are not lost
		$this->dropAllEquipment();
		$this->flagForDespawn();
		return $villager instanceof Living ? $villager : null;
	}

	/**
	 * Maps a Bedrock villager_v2 profession to the five professions of {@link Villager}.
	 */
	public static function toLegacyProfession(int $profession) : int{
		return match($profession){
			5, 6 => Villager::PROFESSION_LIBRARIAN,
			7 => Villager::PROFESSION_PRIEST,
			8, 9, 10 => Villager::PROFESSION_BLACKSMITH,
			11, 12 => Villager::PROFESSION_BUTCHER,
			default => Villager::PROFESSION_FARMER
		};
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ZOMBIE_VILLAGER_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->profession);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->skin);
		//flag 40 is ActorFlags::SHAKING, named VIBRATING in BedrockProtocol
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->isConverting());
		$properties->setGenericFlag(EntityMetadataFlags::CONVERTING, $this->isConverting());
	}
}
