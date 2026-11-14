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

use pocketmine\block\BigDripleafHead;
use pocketmine\block\Block;
use pocketmine\block\utils\FroglightType;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\block\WaterLily;
use pocketmine\entity\ai\control\MoveControl;
use pocketmine\entity\ai\control\SwimmingMoveControl;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\CroakGoal;
use pocketmine\entity\ai\goal\FrogEatMobGoal;
use pocketmine\entity\ai\goal\LayEggGoal;
use pocketmine\entity\ai\goal\LongJumpGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MoveToLandGoal;
use pocketmine\entity\ai\goal\MoveToLiquidGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\navigation\RandomPos;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\MagmaCube;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\World;
use function floor;
use function in_array;
use function max;
use function mt_rand;

/**
 * Bedrock values (entities/frog.json): 10 health, 0.5x0.55, walks at 0.1, swims at 0.15 (amphibious), panics at 2x,
 * takes 5 less fall damage,
 * tempted and bred with slime balls. Breeding makes the frog pregnant: it then lays frogspawn on a water source
 * within 10 blocks (which hatches into tadpoles), heading for water within 20 blocks first. Variants (VARIANT): 0
 * temperate, 1 cold, 2 warm, by biome tags when spawned or grown up from a tadpole (see
 * {@link Frog::variantForBiomeTags()}). Frogs go ashore when in water (within 30 blocks), croak from time to time
 * (CROAKING) and jump onto blocks around them every 5-7 seconds, preferably lily pads and big dripleaves
 * (JUMP_GOAL_JUMP while in the air).
 * Frogs hunt small (size 1) slimes and magma cubes they see within 16 blocks and eat them with their tongue
 * ({@link FrogEatMobGoal}); loot_tables/entities/frog.json: a slime ball for a slime, a froglight for a magma cube
 * (ochre from temperate frogs, verdant from cold ones, pearlescent from warm ones).
 */
class Frog extends Animal{
	public const TAG_VARIANT = "Variant"; //TAG_Int
	public const TAG_PREGNANT = "IsPregnant"; //TAG_Byte

	public const VARIANT_TEMPERATE = 0;
	public const VARIANT_COLD = 1;
	public const VARIANT_WARM = 2;

	private int $variant = self::VARIANT_TEMPERATE;
	private bool $pregnant = false;
	private bool $eatingMob = false;
	private bool $croaking = false;
	private bool $longJumping = false;

	public static function getNetworkTypeId() : string{ return EntityIds::FROG; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.55, 0.5, 0.4);
	}

	public function getName() : string{
		return "Frog";
	}

	protected function hasBabyForm() : bool{
		return false;
	}

	public static function variantForBiome(World $world, Vector3 $pos) : int{
		return self::variantForBiomeTags(MobBiomeInfo::getTags($world, $pos));
	}

	/**
	 * Returns the variant of a frog spawned or grown up from a tadpole in a biome with these tags (frog.json
	 * entity_spawned and entity_transformed: cold wins over warm).
	 * @internal
	 *
	 * @param string[] $tags
	 */
	public static function variantForBiomeTags(array $tags) : int{
		$has = static fn(string $tag) : bool => in_array($tag, $tags, true);
		if(
			$has("mountain") ||
			($has("mountains") && !$has("meadow") && !$has("cherry_grove") && !$has("stony_peaks")) ||
			$has("ice") || $has("cold") || $has("frozen") || $has("the_end") || $has("deep_dark")
		){
			return self::VARIANT_COLD;
		}
		if(
			$has("desert") || $has("jungle") || $has("savanna") || $has("mesa") || $has("nether") ||
			($has("ocean") && ($has("warm") || $has("lukewarm"))) ||
			$has("mangrove_swamp")
		){
			return self::VARIANT_WARM;
		}
		return self::VARIANT_TEMPERATE;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.1);
		parent::initEntity($nbt);
		$this->variant = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 3 : self::variantForBiome($this->getWorld(), $this->location);
		$this->pregnant = $nbt->getByte(self::TAG_PREGNANT, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		$nbt->setByte(self::TAG_PREGNANT, $this->pregnant ? 1 : 0);
		return $nbt;
	}

	protected function createMoveControl() : MoveControl{
		return new SwimmingMoveControl($this, 0.15);
	}

	public function findWanderPos(int $horizontal, int $vertical, ?Vector3 $awayFrom = null) : ?Vector3{
		return $this->isInWater() ? RandomPos::getWaterPos($this, $horizontal, $vertical, $awayFrom) : parent::findWanderPos($horizontal, $vertical, $awayFrom);
	}

	public function canBreathe() : bool{
		return true;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new PanicGoal($this, 2.0));
		$this->goalSelector->addGoal(2, new LayEggGoal($this, fn() => $this->pregnant, fn() => $this->findSpawnPlace(), fn(Vector3 $pos) => $this->laySpawn($pos)));
		//pregnant: move_to_water within 20 blocks, then lay_egg
		$this->goalSelector->addGoal(3, new MoveToLiquidGoal($this, MoveToLiquidGoal::WATER, 1.0, 20, 5, 1.5, fn() => $this->pregnant));
		$this->goalSelector->addGoal(4, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(5, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(6, new MoveToLandGoal($this, 1.0, 30, 8, 2.0, fn() => !$this->pregnant));
		$this->goalSelector->addGoal(7, new FrogEatMobGoal($this));
		$this->goalSelector->addGoal(9, new CroakGoal($this, function(bool $croaking) : void{
			$this->croaking = $croaking;
			$this->networkPropertiesDirty = true;
		}, 200, 400, 90));
		//jump_to_block: every 5-7 seconds, within 8 blocks sideways and 4 up or down, half the time onto a lily pad or
		//big dripleaf if there is one, never into water
		$this->goalSelector->addGoal(10, new LongJumpGoal($this, 100, 140, function(bool $jumping) : void{
			$this->longJumping = $jumping;
			$this->networkPropertiesDirty = true;
		}, 8, -4, 4, 1.0, 2, fn(Block $block) => $block instanceof WaterLily || $block instanceof BigDripleafHead, 0.5));
		$this->goalSelector->addGoal(11, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(12, new LookAtPlayerGoal($this, 6.0));

		//food is small slimes and magma cubes (both slimes): no search in worlds without slimes
		$this->targetSelector->addGoal(8, new NearestAttackableEntityGoal($this, fn(Living $e) => self::isFood($e), 16.0, true, 10, requiredClass: Slime::class));
	}

	/**
	 * Whether frogs eat this mob: small slimes and small magma cubes.
	 */
	public static function isFood(Living $entity) : bool{
		return $entity instanceof Slime && $entity->getSlimeSize() === 1; //magma cubes are slimes too
	}

	public function isCroaking() : bool{
		return $this->croaking;
	}

	public function isLongJumping() : bool{
		return $this->longJumping;
	}

	public function isEatingMob() : bool{
		return $this->eatingMob;
	}

	public function setEatingMob(bool $eating) : void{
		if($eating !== $this->eatingMob){
			$this->eatingMob = $eating;
			$this->networkPropertiesDirty = true;
			if($eating){
				$this->playMobSound(LevelSoundEvent::TONGUE);
			}
		}
	}

	/**
	 * Swallows the mob: it disappears (no drops of its own) and the frog's loot table drops where it was.
	 */
	public function eatMob(Living $mob) : void{
		$this->setEatingMob(false);
		$this->setAttackTarget(null);
		if($mob->isClosed() || !$mob->isAlive() || !self::isFood($mob)){
			return;
		}
		$drop = $this->getEatDrop($mob);
		$pos = $mob->getPosition();
		$mob->flagForDespawn();
		if($drop !== null){
			$this->getWorld()->dropItem($pos, $drop);
		}
	}

	/**
	 * Loot of loot_tables/entities/frog.json for the eaten mob.
	 */
	public function getEatDrop(Living $mob) : ?Item{
		if($mob instanceof MagmaCube){
			return VanillaBlocks::FROGLIGHT()->setFroglightType(match($this->variant){
				self::VARIANT_COLD => FroglightType::VERDANT,
				self::VARIANT_WARM => FroglightType::PEARLESCENT,
				default => FroglightType::OCHRE
			})->asItem();
		}
		if($mob instanceof Slime){
			return VanillaItems::SLIMEBALL();
		}
		return null;
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	public function isPregnant() : bool{
		return $this->pregnant;
	}

	public function setPregnant(bool $pregnant) : void{
		$this->pregnant = $pregnant;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns a block above a water source (with air in it) within 10 blocks where frogspawn can go.
	 */
	private function findSpawnPlace() : ?Vector3{
		$world = $this->getWorld();
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		for($i = 0; $i < 40; ++$i){
			$x = $bx + mt_rand(-10, 10);
			$y = $by + mt_rand(-3, 3);
			$z = $bz + mt_rand(-10, 10);
			$below = $world->getBlockAt($x, $y - 1, $z);
			if($below instanceof Water && $below->isSource() && $world->getBlockAt($x, $y, $z)->getTypeId() === VanillaBlocks::AIR()->getTypeId()){
				return new Vector3($x, $y, $z);
			}
		}
		return null;
	}

	private function laySpawn(Vector3 $pos) : bool{
		$world = $this->getWorld();
		$below = $world->getBlockAt($pos->getFloorX(), $pos->getFloorY() - 1, $pos->getFloorZ());
		if(!$below instanceof Water || !$below->isSource() || $world->getBlock($pos)->getTypeId() !== VanillaBlocks::AIR()->getTypeId()){
			return false;
		}
		$world->setBlock($pos, VanillaBlocks::FROG_SPAWN());
		//schedules the hatching
		$world->getBlock($pos)->onPostPlace();
		$this->playMobSound(LevelSoundEvent::LAY_SPAWN);
		$this->setPregnant(false);
		return true;
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::SLIMEBALL;
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return max(0.0, parent::calculateFallDamage($fallDistance) - 5); //damage_sensor: fall damage_modifier -5
	}

	protected function spawnBaby(Animal $partner) : ?Animal{
		$this->setPregnant(true);
		return null;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Frog($location);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		$properties->setGenericFlag(EntityMetadataFlags::PREGNANT, $this->pregnant);
		$properties->setGenericFlag(EntityMetadataFlags::EAT_MOB, $this->eatingMob);
		$properties->setGenericFlag(EntityMetadataFlags::CROAKING, $this->croaking);
		$properties->setGenericFlag(EntityMetadataFlags::JUMP_GOAL_JUMP, $this->longJumping);
		//frogs have no babies (they come from tadpoles)
		$properties->setGenericFlag(EntityMetadataFlags::BABY, false);
	}
}
