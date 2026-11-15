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

use pocketmine\block\Jukebox;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\entity\ai\FlyingMobTrait;
use pocketmine\entity\ai\goal\FindPlayerMountGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowMobGoal;
use pocketmine\entity\ai\goal\FollowOwnerGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\SitWhenOrderedGoal;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
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
use function floor;
use function in_array;
use function mt_rand;

/**
 * Bedrock values (entities/parrot.json): 6 health, 0.5x1, flies (random_fly 15 blocks around, 1 up or down); tamed
 * with seeds (1 in 3), then sits when ordered (and lands) and follows its owner (5 blocks, teleports beyond 12).
 * Cookies poison it to death. Five colours (VARIANT 0-4). Not breedable.
 * Loot (loot_tables/entities/parrot.json): 1-2 feathers; XP 1-3.
 *
 * Wild parrots follow other mobs (follow_mob: 20 blocks, stopping 3 away). Tamed parrots which aren't sitting land on
 * the shoulders of a nearby player (find_mount: within 16 blocks, 2 blocks to get on, 5 s delay; the player's two
 * rideable seats take family parrot_tame), and get off when the player jumps, falls, gets hurt, flies, glides, swims,
 * sleeps or goes into water. While on a shoulder the parrot doesn't think (look_at_player is removed in vanilla).
 *
 * Parrots within 3 blocks of a jukebox which starts playing dance (DANCING flag) until it stops or they get farther
 * than 3.46 blocks (Java Parrot.setRecordPlayingNearby; Bedrock has no component for it). Imitating mobs is not
 * implemented.
 */
class Parrot extends TameableAnimal{
	use FlyingMobTrait;

	public const TAG_VARIANT = "Variant"; //TAG_Int

	private const SEEDS = [ItemTypeIds::WHEAT_SEEDS, ItemTypeIds::PUMPKIN_SEEDS, ItemTypeIds::MELON_SEEDS, ItemTypeIds::BEETROOT_SEEDS, ItemTypeIds::PITCHER_POD, ItemTypeIds::TORCHFLOWER_SEEDS];

	/** Jukeboxes starting to play reach the parrots in the block grown by this much (Java JukeboxSongPlayer). */
	private const JUKEBOX_NOTIFY_RANGE = 3.0;
	/** Parrots stop dancing beyond this distance from the jukebox's centre (Java Parrot.aiStep). */
	private const JUKEBOX_DANCE_DISTANCE = 3.46;

	private int $variant = 0;
	/** Jukebox the parrot is dancing to. */
	private ?Vector3 $jukebox = null;
	private int $danceCheckTicks = 0;
	private ?FindPlayerMountGoal $shoulderGoal = null;

	public static function getNetworkTypeId() : string{ return EntityIds::PARROT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.0, 0.5, 0.6);
	}

	public function getName() : string{
		return "Parrot";
	}

	protected function hasBabyForm() : bool{
		return false;
	}

	protected function getFlyingSpeed() : float{
		return 0.2;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(6);
		$this->setMovementSpeed(0.4);
		parent::initEntity($nbt);
		$this->initFlying();
		$this->variant = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 5 : mt_rand(0, 4);
		$this->setHasGravity($this->sitting);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25));
		$this->goalSelector->addGoal(2, new SitWhenOrderedGoal($this));
		$this->goalSelector->addGoal(3, new FollowOwnerGoal($this, fn() : ?Living => $this->isSitting() ? null : $this->getOwner(), 1.0, 5.0, 1.0, 12.0, true));
		//random_fly is only in parrot_wild: tamed parrots stay with their owner (and look for a shoulder)
		$this->goalSelector->addGoal(3, new class($this) extends RandomStrollGoal{
			public function __construct(private Parrot $parrot){
				parent::__construct($parrot, 1.0, 60, 15, 1);
			}

			public function canStart() : bool{
				return !$this->parrot->isTamed() && parent::canStart();
			}
		});
		//parrot_tame find_mount, parrot_wild follow_mob
		$this->shoulderGoal = new FindPlayerMountGoal($this, 16.0, 2.0, 100, fn() : bool => $this->isTamed() && !$this->isSitting());
		$this->goalSelector->addGoal(4, $this->shoulderGoal);
		$this->goalSelector->addGoal(4, new FollowMobGoal($this, 1.0, 3.0, 20.0, fn(Living $entity) : bool => !$entity instanceof Parrot && !$entity->isInvisible(), onlyWhen: fn() : bool => !$this->isTamed()));
		$this->goalSelector->addGoal(4, new LookAtPlayerGoal($this, 6.0));
	}

	public function isDancing() : bool{
		return $this->jukebox !== null;
	}

	/**
	 * Starts dancing to the jukebox at the position.
	 */
	public function startDancing(Vector3 $jukebox) : void{
		if($this->jukebox === null){
			$this->networkPropertiesDirty = true;
		}
		$this->jukebox = $jukebox->floor();
		$this->danceCheckTicks = 20;
	}

	public function stopDancing() : void{
		if($this->jukebox !== null){
			$this->jukebox = null;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Called when a jukebox starts or stops playing: the parrots around it start or stop dancing (one entity query per
	 * event).
	 *
	 * @internal
	 */
	public static function onJukeboxChange(World $world, Vector3 $pos, bool $playing) : void{
		$r = self::JUKEBOX_NOTIFY_RANGE;
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		foreach($world->getNearbyEntities(new AxisAlignedBB($x - $r, $y - $r, $z - $r, $x + 1 + $r, $y + 1 + $r, $z + 1 + $r)) as $entity){
			if($entity instanceof Parrot && $entity->isAlive()){
				if($playing){
					$entity->startDancing($pos);
				}elseif($entity->jukebox !== null && $entity->jukebox->equals(new Vector3($x, $y, $z))){
					$entity->stopDancing();
				}
			}
		}
	}

	/**
	 * Whether the parrot should still dance: its jukebox is still there, playing, and close enough.
	 */
	private function isJukeboxStillPlaying() : bool{
		$pos = $this->jukebox;
		if($pos === null || $pos->add(0.5, 0.5, 0.5)->distanceSquared($this->location) > self::JUKEBOX_DANCE_DISTANCE ** 2){
			return false;
		}
		$block = $this->getWorld()->getBlock($pos);
		return $block instanceof Jukebox && $block->isPlaying();
	}

	/**
	 * Whether a parrot on the shoulders of the player has to get off: the player jumped or fell (in the air), flies,
	 * glides, swims, sleeps, is in water or is gone. Getting hurt is handled by the player.
	 */
	private static function mustLeaveShoulder(Player $player) : bool{
		if(!$player->isAlive() || $player->isClosed() || $player->isSpectator() || $player->isFlying() || $player->isGliding() || $player->isSwimming() || $player->isSleeping() || $player->getFallDistance() > 0.5){
			return true;
		}
		$pos = $player->getPosition();
		return LiquidLayer::hasWaterAt($player->getWorld(), (int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
	}

	protected function onMount(Entity $vehicle) : void{
		parent::onMount($vehicle);
		if($vehicle instanceof Player){
			//nothing to do on a shoulder: the goals stop until it gets off
			$this->goalSelector->stopAll();
			$this->navigation->stop();
			$this->moveControl->stop();
			$this->networkPropertiesDirty = true;
		}
	}

	protected function onDismount(Entity $vehicle) : void{
		parent::onDismount($vehicle);
		if($vehicle instanceof Player){
			$this->shoulderGoal?->restartDelay();
			$this->networkPropertiesDirty = true;
		}
	}

	protected function tickAi(int $currentTick) : void{
		if(!$this->vehicle instanceof Player){
			parent::tickAi($currentTick);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->vehicle instanceof Player && self::mustLeaveShoulder($this->vehicle)){
			$this->dismount(true);
		}
		if($this->jukebox !== null && ($this->danceCheckTicks -= $tickDiff) <= 0){
			$this->danceCheckTicks = 20;
			if(!$this->isJukeboxStillPlaying()){
				$this->stopDancing();
			}
		}
		return $hasUpdate;
	}

	protected function requiresTameToBreed() : bool{
		return false;
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	public function setSitting(bool $sitting) : void{
		parent::setSitting($sitting);
		//a sitting parrot lands
		$this->setHasGravity($this->sitting);
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Parrot($location);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->getTypeId() === ItemTypeIds::COOKIE){
			$this->consumeHeldItem($player);
			$this->effectManager->add(new EffectInstance(VanillaEffects::FATAL_POISON(), 1000, 0));
			return true;
		}
		if(!$this->isTamed()){
			if(in_array($item->getTypeId(), self::SEEDS, true)){
				$this->consumeHeldItem($player);
				$this->tryTame($player, 1 / 3);
				return true;
			}
			return false;
		}
		if(!$this->isOwner($player)){
			return false;
		}
		$this->setSitting(!$this->isSitting());
		return true;
	}

	protected function getAdultDrops() : array{
		return [VanillaItems::FEATHER()->setCount(mt_rand(1, 2))];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
		$properties->setGenericFlag(EntityMetadataFlags::DANCING, $this->jukebox !== null);
	}

	protected function makesFlapVibrations() : bool{
		return true;
	}
}
