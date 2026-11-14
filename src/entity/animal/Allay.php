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
use pocketmine\block\Note;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\FlyingMobTrait;
use pocketmine\entity\ai\goal\FollowOwnerGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\sound\AmethystBlockChimeSound;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function max;
use function min;

/**
 * Bedrock values (entities/allay.json): 20 health, 0.35x0.6, flies (flying speed 0.1). Give it an item and it keeps a
 * copy in its hand, follows you (speed 8x, capped), collects matching item entities within 32 blocks (6x) into its
 * one-stack inventory and drops them next to you when it's back within 3 blocks. Using an empty hand on it takes the
 * held item (and the collected items) back (not while sneaking or holding a lead). It follows you once more than 16
 * blocks away, up to 4 blocks, without teleporting, and you can't hurt it. No drops other than its items; no XP.
 * Note blocks: an allay with an item which hears a note block within 16 blocks (not through wool) brings its items
 * to that note block instead and stays within 4 blocks of it, for 30 seconds after the last note
 * (behavior.go_and_give_items_to_noteblock, behavior.stay_near_noteblock).
 * Dancing: allays within 10 blocks of a jukebox which starts playing dance (DANCING flag) until the disc stops or they
 * get farther than 10 blocks. Giving an amethyst shard to a dancing allay duplicates it (chime sound, hearts); both
 * allays then wait 5 minutes before they can duplicate again (Java/Bedrock duplication cooldown, 6000 ticks).
 */
class Allay extends Mob{
	use FlyingMobTrait;

	public const TAG_HELD_ITEM = "HeldItem"; //TAG_Compound
	public const TAG_INVENTORY = "AllayInventory"; //TAG_Compound
	public const TAG_LIKED_PLAYER = "LikedPlayer"; //TAG_String (UUID, PocketMine-specific)
	public const TAG_DUPLICATION_COOLDOWN = "DuplicationCooldown"; //TAG_Int (ticks)

	public const DUPLICATION_COOLDOWN = 6000;
	public const JUKEBOX_RANGE = 10.0;
	public const NOTE_BLOCK_RANGE = 16.0;
	public const NOTE_BLOCK_MEMORY_TICKS = 600;
	/** Distance at which an allay stops approaching the note block it remembers (stay_near_noteblock). */
	public const NOTE_BLOCK_STAY_DISTANCE = 4.0;

	private ?Item $heldItem = null;
	private ?Item $collected = null;
	private ?UuidInterface $likedPlayer = null;
	private ?Player $likedPlayerCache = null;
	private int $pickupCooldown = 0;
	private int $duplicationCooldown = 0;
	/** Jukebox the allay is dancing to. */
	private ?Vector3 $jukebox = null;
	private int $danceCheckTicks = 0;
	/** Note block heard last, remembered until $noteBlockUntil (server tick). */
	private ?Vector3 $noteBlock = null;
	private int $noteBlockUntil = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::ALLAY; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.6, 0.35, 0.36);
	}

	public function canBeLeashed() : bool{
		return true;
	}

	public function getName() : string{
		return "Allay";
	}

	protected function getFlyingSpeed() : float{
		return 0.1;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.1);
		parent::initEntity($nbt);
		$this->initFlying();
		$this->heldItem = self::readItem($nbt->getCompoundTag(self::TAG_HELD_ITEM));
		$this->collected = self::readItem($nbt->getCompoundTag(self::TAG_INVENTORY));
		$uuid = $nbt->getString(self::TAG_LIKED_PLAYER, "");
		$this->likedPlayer = $uuid !== "" && Uuid::isValid($uuid) ? Uuid::fromString($uuid) : null;
		$this->duplicationCooldown = max(0, $nbt->getInt(self::TAG_DUPLICATION_COOLDOWN, 0));
	}

	private static function readItem(?CompoundTag $tag) : ?Item{
		if($tag === null){
			return null;
		}
		try{
			$item = Item::nbtDeserialize($tag);
			return $item->isNull() ? null : $item;
		}catch(SavedDataLoadingException){
			return null;
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if($this->heldItem !== null){
			$nbt->setTag(self::TAG_HELD_ITEM, $this->heldItem->nbtSerialize());
		}
		if($this->collected !== null){
			$nbt->setTag(self::TAG_INVENTORY, $this->collected->nbtSerialize());
		}
		if($this->likedPlayer !== null){
			$nbt->setString(self::TAG_LIKED_PLAYER, $this->likedPlayer->toString());
		}
		if($this->duplicationCooldown > 0){
			$nbt->setInt(self::TAG_DUPLICATION_COOLDOWN, $this->duplicationCooldown);
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new PanicGoal($this, 2.0));
		$this->goalSelector->addGoal(2, new class($this) extends Goal{
			private ?ItemEntity $target = null;

			public function __construct(private Allay $allay){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{
				$this->target = $this->allay->findWantedItem();
				return $this->target !== null;
			}

			public function canContinue() : bool{
				$target = $this->target;
				return $target !== null && !$target->isClosed() && !$target->isFlaggedForDespawn() && $this->allay->canCollect($target->getItem());
			}

			public function tick() : void{
				$target = $this->target;
				if($target === null){
					return;
				}
				if($target->getBoundingBox()->expandedCopy(1.0, 1.0, 1.0)->intersectsWith($this->allay->getBoundingBox())){
					$this->allay->collect($target);
					$this->target = null;
					return;
				}
				$this->allay->getNavigation()->moveToEntity($target, 6.0, 0.5);
			}

			public function stop() : void{
				$this->target = null;
				$this->allay->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(3, new class($this) extends Goal{
			public function __construct(private Allay $allay){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{
				return $this->allay->getCollectedItem() !== null && $this->allay->getNoteBlock() !== null;
			}

			public function tick() : void{
				$noteBlock = $this->allay->getNoteBlock();
				if($noteBlock === null){
					return;
				}
				$target = $noteBlock->add(0.5, 1.5, 0.5);
				if($target->distanceSquared($this->allay->getLiveLocation()) <= 4){
					$this->allay->giveItemsToNoteBlock($noteBlock);
					$this->allay->getNavigation()->stop();
				}elseif($this->allay->getNavigation()->isDone()){
					$this->allay->getNavigation()->moveTo($target->x, $target->y, $target->z, 6.0, 1.0);
				}
			}

			public function stop() : void{
				$this->allay->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(4, new class($this) extends Goal{
			public function __construct(private Allay $allay){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			public function canStart() : bool{
				return $this->allay->getCollectedItem() !== null && $this->allay->getLikedPlayer() !== null && $this->allay->getNoteBlock() === null;
			}

			public function tick() : void{
				$player = $this->allay->getLikedPlayer();
				if($player === null){
					return;
				}
				if($player->getLocation()->distanceSquared($this->allay->getLiveLocation()) <= 9){
					$this->allay->giveItemsTo($player);
					$this->allay->getNavigation()->stop();
				}else{
					$this->allay->getNavigation()->moveToEntity($player, 6.0, 2.0);
				}
			}

			public function stop() : void{
				$this->allay->getNavigation()->stop();
			}
		});
		$this->goalSelector->addGoal(5, new class($this) extends Goal{
			public function __construct(private Allay $allay){}

			public function getFlags() : int{ return self::FLAG_MOVE; }

			private function isFar() : bool{
				$noteBlock = $this->allay->getNoteBlock();
				$d = Allay::NOTE_BLOCK_STAY_DISTANCE;
				return $noteBlock !== null && $noteBlock->add(0.5, 1.5, 0.5)->distanceSquared($this->allay->getLiveLocation()) > $d * $d;
			}

			public function canStart() : bool{
				return $this->allay->getHeldItem() !== null && $this->isFar();
			}

			public function tick() : void{
				$noteBlock = $this->allay->getNoteBlock();
				if($noteBlock !== null && $this->allay->getNavigation()->isDone()){
					$this->allay->getNavigation()->moveTo($noteBlock->x + 0.5, $noteBlock->y + 1.5, $noteBlock->z + 0.5, 8.0, 2.0);
				}
			}

			public function stop() : void{
				$this->allay->getNavigation()->stop();
			}
		});
		//while it remembers a note block, the allay stays near it instead of following its player (behavior.follow_owner:
		//starts beyond 16 blocks, stops within 4, never teleports)
		$this->goalSelector->addGoal(6, new FollowOwnerGoal($this, fn() : ?Living => $this->getNoteBlock() === null ? $this->getLikedPlayer() : null, 8.0, 16.0, 4.0, 0.0, true));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
		$this->goalSelector->addGoal(9, new RandomStrollGoal($this, 1.0, 60, 8, 3));
	}

	public function getHeldItem() : ?Item{
		return $this->heldItem !== null ? clone $this->heldItem : null;
	}

	public function getCollectedItem() : ?Item{
		return $this->collected !== null ? clone $this->collected : null;
	}

	public function getLikedPlayer() : ?Player{
		if($this->likedPlayer === null || $this->heldItem === null){
			return null;
		}
		$cached = $this->likedPlayerCache;
		if($cached === null || !$cached->isConnected() || !$cached->getUniqueId()->equals($this->likedPlayer)){
			$cached = $this->likedPlayerCache = $this->server->getPlayerByUUID($this->likedPlayer);
		}
		return $cached !== null && $cached->isAlive() && $cached->getWorld() === $this->getWorld() ? $cached : null;
	}

	/**
	 * Whether the allay can take this item now (same item as the one in its hand, room left).
	 */
	public function canCollect(Item $item) : bool{
		$held = $this->heldItem;
		if($held === null || !$item->canStackWith($held)){
			return false;
		}
		$collected = $this->collected;
		return $collected === null || $collected->getCount() < $collected->getMaxStackSize();
	}

	/**
	 * Nearest item entity matching the held item within 32 blocks, looked for once every second or so.
	 */
	public function findWantedItem() : ?ItemEntity{
		if($this->heldItem === null || $this->pickupCooldown > 0 || !$this->canCollect($this->heldItem) || !GameRules::allowsMobGriefing($this->getWorld())){
			return null;
		}
		$this->pickupCooldown = 20;
		$loc = $this->location;
		$best = null;
		$bestDist = 32.0 * 32.0;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - 32, $loc->y - 16, $loc->z - 32, $loc->x + 32, $loc->y + 16, $loc->z + 32), $this) as $entity){
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn() && $entity->getPickupDelay() <= 0 && $this->canCollect($entity->getItem())){
				$d = $entity->getLocation()->distanceSquared($loc);
				if($d < $bestDist){
					$best = $entity;
					$bestDist = $d;
				}
			}
		}
		return $best;
	}

	public function collect(ItemEntity $entity) : void{
		$item = $entity->getItem();
		$collected = $this->collected ?? (clone $item)->setCount(0);
		$room = $collected->getMaxStackSize() - $collected->getCount();
		$take = min($room, $item->getCount());
		if($take <= 0){
			return;
		}
		$collected->setCount($collected->getCount() + $take);
		$this->collected = $collected;
		if($take >= $item->getCount()){
			$entity->flagForDespawn();
		}else{
			$entity->setStackSize($item->getCount() - $take);
		}
	}

	public function giveItemsTo(Player $player) : void{
		$collected = $this->collected;
		if($collected === null){
			return;
		}
		$this->collected = null;
		$motion = $player->getPosition()->subtractVector($this->location)->normalize()->multiply(0.3);
		$entity = $this->getWorld()->dropItem($this->location->add(0, 0.2, 0), $collected, $motion, 40);
		$entity?->setOwner($player->getName());
		$this->pickupCooldown = 60;
	}

	/**
	 * Drops the collected items on the note block (the allay is next to it).
	 */
	public function giveItemsToNoteBlock(Vector3 $noteBlock) : void{
		$collected = $this->collected;
		if($collected === null){
			return;
		}
		$this->collected = null;
		$motion = $noteBlock->add(0.5, 1.0, 0.5)->subtractVector($this->location)->normalize()->multiply(0.2);
		$this->getWorld()->dropItem($this->location->add(0, 0.2, 0), $collected, $motion, 40);
		$this->pickupCooldown = 60;
	}

	/**
	 * Note block the allay brings its items to, if it heard one in the last 30 seconds (and it is still there).
	 */
	public function getNoteBlock() : ?Vector3{
		$pos = $this->noteBlock;
		if($pos === null){
			return null;
		}
		if($this->server->getTick() >= $this->noteBlockUntil || !$this->getWorld()->getBlock($pos) instanceof Note){
			$this->noteBlock = null;
			return null;
		}
		return $pos;
	}

	/**
	 * The allay heard the note block at the position: it brings its items there for the next 30 seconds.
	 */
	public function hearNoteBlock(Vector3 $pos) : void{
		$this->noteBlock = $pos->floor();
		$this->noteBlockUntil = $this->server->getTick() + self::NOTE_BLOCK_MEMORY_TICKS;
	}

	/**
	 * Called when a note block plays: allays holding an item within 16 blocks hear it, unless wool is in the way.
	 */
	public static function onNoteBlockPlayed(World $world, Vector3 $pos) : void{
		$r = self::NOTE_BLOCK_RANGE;
		$center = $pos->floor()->add(0.5, 0.5, 0.5);
		foreach($world->getNearbyEntities(new AxisAlignedBB($center->x - $r, $center->y - $r, $center->z - $r, $center->x + $r, $center->y + $r, $center->z + $r)) as $entity){
			if(!$entity instanceof Allay || !$entity->isAlive() || $entity->heldItem === null || $entity->location->distanceSquared($center) > $r * $r){
				continue;
			}
			$eye = $entity->location->add(0, $entity->getEyeHeight(), 0);
			if($world->getVibrations()->isOccluded($center->getFloorX(), $center->getFloorY(), $center->getFloorZ(), $eye->getFloorX(), $eye->getFloorY(), $eye->getFloorZ())){
				continue;
			}
			$entity->hearNoteBlock($center);
		}
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
	 * Called when a jukebox starts playing a disc: the allays within 10 blocks dance.
	 */
	public static function onJukeboxPlay(World $world, Vector3 $pos) : void{
		$r = self::JUKEBOX_RANGE;
		$center = $pos->floor()->add(0.5, 0.5, 0.5);
		foreach($world->getNearbyEntities(new AxisAlignedBB($center->x - $r, $center->y - $r, $center->z - $r, $center->x + $r, $center->y + $r, $center->z + $r)) as $entity){
			if($entity instanceof Allay && $entity->isAlive() && $entity->location->distanceSquared($center) <= $r * $r){
				$entity->startDancing($pos);
			}
		}
	}

	/**
	 * Whether the allay should still dance: its jukebox is still playing and within 10 blocks.
	 */
	private function isJukeboxStillPlaying() : bool{
		$pos = $this->jukebox;
		if($pos === null){
			return false;
		}
		$r = self::JUKEBOX_RANGE;
		if($pos->add(0.5, 0.5, 0.5)->distanceSquared($this->location) > $r * $r){
			return false;
		}
		$block = $this->getWorld()->getBlock($pos);
		return $block instanceof Jukebox && $block->isPlaying();
	}

	public function getDuplicationCooldown() : int{
		return $this->duplicationCooldown;
	}

	public function setDuplicationCooldown(int $ticks) : void{
		$this->duplicationCooldown = max(0, $ticks);
	}

	public function canDuplicate() : bool{
		return $this->isDancing() && $this->duplicationCooldown <= 0;
	}

	/**
	 * Splits the allay in two (a dancing allay was given an amethyst shard). The new allay holds nothing; both wait
	 * 5 minutes before duplicating again.
	 */
	public function duplicate() : Allay{
		$world = $this->getWorld();
		$copy = new Allay(Location::fromObject($this->location, $world, $this->location->yaw, 0));
		$copy->setDuplicationCooldown(self::DUPLICATION_COOLDOWN);
		$copy->setPersistent(true);
		$copy->spawnToAll();
		$this->duplicationCooldown = self::DUPLICATION_COOLDOWN;
		$world->addSound($this->location, new AmethystBlockChimeSound());
		$world->addParticle($this->location->add(0, 0.5, 0), new HeartParticle());
		return $copy;
	}

	/**
	 * damage_sensor: no damage from the player who gave it its item (is_owner), by hand or by projectile.
	 */
	public function attack(EntityDamageEvent $source) : void{
		if($this->likedPlayer !== null && $source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Player && $damager->getUniqueId()->equals($this->likedPlayer)){
				$source->cancel();
			}
		}
		parent::attack($source);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->getTypeId() === ItemTypeIds::AMETHYST_SHARD && $this->canDuplicate()){
			$this->consumeHeldItem($player);
			$this->duplicate();
			return true;
		}
		if($player->isSneaking() || $item->getTypeId() === ItemTypeIds::LEAD){
			//interact filters: not while sneaking, not with a lead in hand
			return false;
		}
		if($this->heldItem === null){
			if($item->isNull()){
				return false;
			}
			$this->heldItem = (clone $item)->setCount(1);
			$this->likedPlayer = $player->getUniqueId();
			$this->likedPlayerCache = $player;
			$this->setPersistent(true);
			$this->consumeHeldItem($player);
			$this->sendHeldItem($this->getViewers());
			return true;
		}
		if($item->isNull()){
			$held = $this->heldItem;
			$this->heldItem = null;
			$this->likedPlayer = null;
			foreach([$held, $this->collected] as $give){
				if($give !== null){
					foreach($player->getInventory()->addItem($give) as $leftover){
						$this->getWorld()->dropItem($player->getLocation(), $leftover);
					}
				}
			}
			$this->collected = null;
			$this->sendHeldItem($this->getViewers());
			return true;
		}
		return false;
	}

	public function getDrops() : array{
		$drops = [];
		foreach([$this->heldItem, $this->collected] as $item){
			if($item !== null){
				$drops[] = clone $item;
			}
		}
		return $drops;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->pickupCooldown > 0){
			$this->pickupCooldown -= $tickDiff;
		}
		if($this->duplicationCooldown > 0){
			$this->duplicationCooldown = max(0, $this->duplicationCooldown - $tickDiff);
		}
		if($this->jukebox !== null && ($this->danceCheckTicks -= $tickDiff) <= 0){
			$this->danceCheckTicks = 20;
			if(!$this->isJukeboxStillPlaying()){
				$this->stopDancing();
			}
		}
		return $hasUpdate;
	}

	/**
	 * @param Player[] $players
	 */
	private function sendHeldItem(array $players) : void{
		foreach($players as $player){
			$session = $player->getNetworkSession();
			$session->sendDataPacket(MobEquipmentPacket::create(
				$this->getId(),
				ItemStackWrapper::legacy($session->getTypeConverter()->coreItemStackToNet($this->heldItem ?? VanillaItems::AIR())),
				0,
				0,
				ContainerIds::INVENTORY
			));
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::DANCING, $this->jukebox !== null);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->heldItem !== null){
			$this->sendHeldItem([$player]);
		}
	}

	protected function onDispose() : void{
		$this->likedPlayerCache = null;
		parent::onDispose();
	}

	protected function makesFlapVibrations() : bool{
		return true;
	}
}
