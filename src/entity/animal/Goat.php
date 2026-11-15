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

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Wood;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LongJumpGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Bucket;
use pocketmine\item\GoatHornType;
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
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function cos;
use function deg2rad;
use function floor;
use function max;
use function mt_rand;
use function sin;
use function sqrt;

/**
 * Bedrock values (entities/goat.json): 10 health, 0.9x1.3, movement 0.4, attack 2 (babies 1). Screaming goats
 * (VARIANT 1) are 2% of spawns. Rams (behavior.ram_attack): every 30-300 seconds (screamers 5-15) a goat picks a mob
 * or survival player 4-16 blocks away, waits a second and charges at 1.8x; the target takes the attack damage and a
 * strong knockback (Bedrock knockback_force 2.5, approximated as 2.5x the normal knockback). Charging into a natural log, stone, packed ice or an iron, coal, copper or emerald ore breaks off a horn (GOAT_HORN_COUNT, 2 at
 * most) which drops as a goat horn (screamers drop the screaming kinds). Milked with a bucket; tempted (0.75x) and bred
 * with wheat; takes 10 less fall damage. No loot; XP 1-3. Every 30-60 seconds an idle goat may leap onto a block 2-10 blocks higher within 10 blocks
 * (behavior.jump_to_block, see {@link LongJumpGoal}; JUMP_GOAL_JUMP flag while in the air).
 */
class Goat extends Animal{
	public const TAG_SCREAMER = "IsScreamer"; //TAG_Byte
	public const TAG_HORNS = "GoatHornCount"; //TAG_Int

	/** Java tag overworld_natural_logs (stripped variants share the type ID and are excluded separately) */
	private const NATURAL_LOGS = [
		BlockTypeIds::OAK_LOG => true,
		BlockTypeIds::SPRUCE_LOG => true,
		BlockTypeIds::BIRCH_LOG => true,
		BlockTypeIds::JUNGLE_LOG => true,
		BlockTypeIds::ACACIA_LOG => true,
		BlockTypeIds::DARK_OAK_LOG => true,
		BlockTypeIds::MANGROVE_LOG => true,
		BlockTypeIds::CHERRY_LOG => true,
		BlockTypeIds::PALE_OAK_LOG => true,
		BlockTypeIds::POPLAR_LOG => true,
	];

	private bool $screamer = false;
	private int $horns = 2;
	private int $ramCooldown = 0;
	private LongJumpGoal $longJumpGoal;
	private bool $longJumping = false;
	private bool $ramming = false;

	public static function getNetworkTypeId() : string{ return EntityIds::GOAT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.3, 0.9, 1.1);
	}

	public function getName() : string{
		return "Goat";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.4);
		parent::initEntity($nbt);
		$this->screamer = $nbt->getTag(self::TAG_SCREAMER) !== null ? $nbt->getByte(self::TAG_SCREAMER) !== 0 : mt_rand(0, 49) === 0;
		$this->horns = max(0, $nbt->getInt(self::TAG_HORNS, 2));
		$this->resetRamCooldown();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_SCREAMER, $this->screamer ? 1 : 0);
		$nbt->setInt(self::TAG_HORNS, $this->horns);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 0.6));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 0.75, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(5, new class($this) extends Goal{
			private ?Living $target = null;
			private int $ticks = 0;
			private Vector3 $direction;

			public function __construct(private Goat $goat){
				$this->direction = Vector3::zero();
			}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP; }

			public function canStart() : bool{
				if(!$this->goat->canRam()){
					return false;
				}
				$this->target = $this->goat->findRamTarget();
				return $this->target !== null;
			}

			public function canContinue() : bool{
				return $this->target !== null && $this->ticks < 80 && $this->target->isAlive() && !$this->target->isClosed();
			}

			public function start() : void{
				$this->ticks = 0;
				$this->goat->getNavigation()->stop();
				$this->goat->playMobSound($this->goat->isScreamer() ? LevelSoundEvent::PRE_RAM_SCREAMER : LevelSoundEvent::PRE_RAM);
			}

			public function stop() : void{
				$this->target = null;
				$this->goat->getNavigation()->stop();
				$this->goat->setRamming(false);
				$this->goat->resetRamCooldown();
			}

			public function tick() : void{
				$target = $this->target;
				if($target === null){
					return;
				}
				++$this->ticks;
				$this->goat->getLookControl()->setLookAtEntity($target, 30.0);
				if($this->ticks < 20){
					//lowering its head
					$loc = $this->goat->getLiveLocation();
					$dx = $target->getLocation()->x - $loc->x;
					$dz = $target->getLocation()->z - $loc->z;
					$len = sqrt($dx * $dx + $dz * $dz);
					$this->direction = $len > 0.001 ? new Vector3($dx / $len, 0, $dz / $len) : new Vector3(-sin(deg2rad($loc->yaw)), 0, cos(deg2rad($loc->yaw)));
					return;
				}
				$this->goat->setRamming(true);
				$loc = $this->goat->getLiveLocation();
				$this->goat->getMoveControl()->setWantedPosition($loc->x + $this->direction->x * 3, $loc->y, $loc->z + $this->direction->z * 3, 1.8);
				if($this->goat->getBoundingBox()->expandedCopy(0.5, 0.25, 0.5)->intersectsWith($target->getBoundingBox())){
					$this->goat->ramInto($target, $this->direction);
					$this->target = null;
				}elseif($this->goat->isCollidedHorizontally && $this->ticks > 22){
					$this->goat->ramBlock($this->direction);
					$this->target = null;
				}
			}
		});
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.0));
		//jump_to_block: cooldown_range 30-60 seconds
		$this->longJumpGoal = new LongJumpGoal($this, 600, 1200, function(bool $jumping) : void{
			$this->longJumping = $jumping;
			$this->networkPropertiesDirty = true;
		});
		$this->goalSelector->addGoal(8, $this->longJumpGoal);
		$this->goalSelector->addGoal(9, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(10, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(11, new RandomLookAroundGoal($this));
	}

	public function isScreamer() : bool{
		return $this->screamer;
	}

	public function setScreamer(bool $screamer) : void{
		$this->screamer = $screamer;
		$this->networkPropertiesDirty = true;
	}

	public function getHornCount() : int{
		return $this->horns;
	}

	public function setHornCount(int $horns) : void{
		$this->horns = max(0, $horns);
		$this->networkPropertiesDirty = true;
	}

	public function resetRamCooldown() : void{
		$this->ramCooldown = $this->screamer ? mt_rand(100, 300) : mt_rand(600, 6000);
	}

	public function canRam() : bool{
		return $this->ramCooldown <= 0 && $this->onGround && !$this->isInWater();
	}

	public function setRamming(bool $ramming) : void{
		if($this->ramming !== $ramming){
			$this->ramming = $ramming;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Picks a mob or a survival player 4 to 16 blocks away in sight (not goats).
	 */
	public function findRamTarget() : ?Living{
		$loc = $this->location;
		$best = null;
		$bestDist = 16.0 * 16.0;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - 16, $loc->y - 4, $loc->z - 16, $loc->x + 16, $loc->y + 4, $loc->z + 16), $this) as $entity){
			if(!$entity instanceof Living || $entity instanceof Goat || !$entity->isAlive() || ($entity instanceof Player && !Sensing::isTargetablePlayer($entity))){
				continue;
			}
			$d = $entity->getLocation()->distanceSquared($loc);
			if($d >= 16 && $d < $bestDist && $this->sensing->canSee($entity)){
				$best = $entity;
				$bestDist = $d;
			}
		}
		return $best;
	}

	public function ramInto(Living $target, Vector3 $direction) : void{
		$ev = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $this->isBaby() ? 1 : 2, [], 0.0);
		$target->attack($ev);
		//the ram's push replaces the event's knockback, unless a plugin set one: then that was applied instead
		if(!$ev->isCancelled() && Living::isDefaultKnockback($ev, 0.0)){
			$target->knockBack($direction->x, $direction->z, 1.0, 0.4);
		}
		$this->playMobSound($this->screamer ? LevelSoundEvent::RAM_IMPACT_SCREAMER : LevelSoundEvent::RAM_IMPACT);
	}

	public function ramBlock(Vector3 $direction) : void{
		$loc = $this->location;
		$block = $this->getWorld()->getBlockAt((int) floor($loc->x + $direction->x * 1.0), (int) floor($loc->y + 0.5), (int) floor($loc->z + $direction->z * 1.0));
		$this->playMobSound($this->screamer ? LevelSoundEvent::RAM_IMPACT_SCREAMER : LevelSoundEvent::RAM_IMPACT);
		if($this->horns > 0 && self::breaksHorn($block)){
			$this->setHornCount($this->horns - 1);
			$this->getWorld()->dropItem($loc->add(0, 1, 0), $this->createHorn());
			$this->playMobSound(LevelSoundEvent::HORN_BREAK);
		}
	}

	/**
	 * Blocks that break a horn off (Java tag snaps_goat_horn, Bedrock the same): overworld natural logs (not stripped,
	 * not wood/hyphae, not nether stems), stone, packed ice, iron, coal, copper and emerald ore.
	 */
	public static function breaksHorn(Block $block) : bool{
		$id = $block->getTypeId();
		if(isset(self::NATURAL_LOGS[$id])){
			return !($block instanceof Wood && $block->isStripped());
		}
		return $id === BlockTypeIds::STONE || $id === BlockTypeIds::PACKED_ICE || $id === BlockTypeIds::IRON_ORE ||
			$id === BlockTypeIds::COAL_ORE || $id === BlockTypeIds::COPPER_ORE || $id === BlockTypeIds::EMERALD_ORE;
	}

	public function createHorn() : Item{
		$types = $this->screamer ?
			[GoatHornType::ADMIRE, GoatHornType::CALL, GoatHornType::YEARN, GoatHornType::DREAM] :
			[GoatHornType::PONDER, GoatHornType::SING, GoatHornType::SEEK, GoatHornType::FEEL];
		return VanillaItems::GOAT_HORN()->setHornType($types[mt_rand(0, 3)]);
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::WHEAT;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Goat($location);
		$baby->setScreamer(self::inheritScreamer($this->screamer, $partner instanceof Goat && $partner->isScreamer()));
		return $baby;
	}

	/**
	 * goat.json genetics (use_simplified_breeding, mutation_rate 0.02, alleles 1-100, screamer 1-2): the kid takes the
	 * allele of a random parent, or 2% of the time a random allele (screamer 2% of those).
	 */
	public static function inheritScreamer(bool $parentA, bool $parentB) : bool{
		if(mt_rand(1, 100) <= 2){
			return mt_rand(1, 100) <= 2;
		}
		return mt_rand(0, 1) === 0 ? $parentA : $parentB;
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return max(0.0, parent::calculateFallDamage($fallDistance) - 10);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Bucket && $item->getTypeId() === ItemTypeIds::BUCKET && !$this->isBaby()){
			$this->exchangeHeldItem($player, VanillaItems::MILK_BUCKET());
			$this->playMobSound($this->screamer ? LevelSoundEvent::MILK_SCREAMER : LevelSoundEvent::MILK);
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ramCooldown > 0){
			$this->ramCooldown -= $tickDiff;
		}
		return $hasUpdate;
	}

	protected function playAmbientSound() : void{
		$this->playMobSound($this->screamer ? LevelSoundEvent::AMBIENT_SCREAMER : LevelSoundEvent::AMBIENT);
	}

	protected function destroyCycles() : void{
		unset($this->longJumpGoal);
		parent::destroyCycles();
	}

	public function getLongJumpGoal() : LongJumpGoal{
		return $this->longJumpGoal;
	}

	public function isLongJumping() : bool{
		return $this->longJumping;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->screamer ? 1 : 0);
		$properties->setInt(EntityMetadataProperties::GOAT_HORN_COUNT, $this->horns);
		$properties->setGenericFlag(EntityMetadataFlags::RAM_ATTACK, $this->ramming);
		$properties->setGenericFlag(EntityMetadataFlags::JUMP_GOAL_JUMP, $this->longJumping);
	}
}
