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

use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\SupportType;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\ShulkerBullet;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\world\vibration\GameEvent;
use function floor;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (shulker.json): 30 health, 1x1 box that never moves, fire immune. Attached to a face of a
 * neighbouring block: the floor, the ceiling or a wall (vanilla attach face, SHULKER_ATTACH_FACE).
 * - Peeks out now and then; opens fully while it has a target within 16 blocks and shoots a homing bullet every 1-3
 *   seconds (see {@link ShulkerBullet}). Closed, it is armored (+20 armor, vanilla COVERED_ARMOR_MODIFIER):
 *   projectiles bounce off it.
 * - When hurt below half health it may teleport (25%) to another spot within 8 blocks where it can attach; it also
 *   moves to another face, or teleports, when the block it is attached to disappears.
 * - Hit by a shulker bullet while open, it teleports and may leave a copy of itself behind (vanilla
 *   hitByShulkerBullet: chance 1 - (shulkers within 8 blocks - 1) / 5).
 * - Colour: VARIANT 16 (undyed) or the dye colour; creative players can dye it (vanilla interact filters).
 * Loot: 0-1 shulker shell (+0-1 per Looting level). Experience 5.
 */
class Shulker extends Monster{
	public const TAG_COLOR = "Color"; //TAG_Byte (inverted dye id, 16 = undyed)
	public const TAG_ATTACH_FACE = "AttachFace"; //TAG_Byte (Facing, like vanilla Java)
	public const UNDYED = 16;

	public const PEEK_OPEN = 100;
	public const PEEK_IDLE = 30;

	/** Armor points while the shell is closed (vanilla COVERED_ARMOR_MODIFIER). */
	public const CLOSED_ARMOR = 20;
	/** Shulkers within this distance of a shulker hit by a bullet lower its chance to duplicate. */
	public const DUPLICATION_SCAN_RADIUS = 8.0;
	/** With this many other shulkers around, a shulker hit by a bullet never duplicates. */
	public const DUPLICATION_LIMIT = 5;

	private int $color = self::UNDYED;
	private int $attachFace = Facing::DOWN;
	private bool $attachmentChecked = false;
	private int $peek = 0;
	private int $peekTicks = 0;
	private int $attackCooldown = 20;

	public static function getNetworkTypeId() : string{ return EntityIds::SHULKER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.0, 1.0, 0.5);
	}

	public function getName() : string{
		return "Shulker";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(30);
		$this->setMovementSpeed(0.0);
		parent::initEntity($nbt);
		$this->setHasGravity(false);
		$this->knockbackResistanceAttr->setValue(1.0);
		$color = $nbt->getByte(self::TAG_COLOR, self::UNDYED);
		$this->color = $color >= 0 && $color <= self::UNDYED ? $color : self::UNDYED;
		$face = $nbt->getByte(self::TAG_ATTACH_FACE, Facing::DOWN);
		$this->attachFace = isset(Facing::OFFSET[$face]) ? $face : Facing::DOWN;
		//sit in the middle of a block
		$this->location = Location::fromObject(new Vector3(floor($this->location->x) + 0.5, floor($this->location->y), floor($this->location->z) + 0.5), $this->getWorld(), 0, 0);
		$this->recalculateBoundingBox();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_COLOR, $this->color);
		$nbt->setByte(self::TAG_ATTACH_FACE, $this->attachFace);
		return $nbt;
	}

	protected function registerGoals() : void{
		//bullets hit whatever they meet: shulkers don't fight each other (or themselves) over it
		$this->targetSelector->addGoal(2, new HurtByTargetGoal($this, 16.0, [Shulker::class]));
		$this->targetSelector->addGoal(3, new NearestAttackableTargetGoal($this, 16.0, true, 10));
	}

	public function getColor() : ?DyeColor{
		return $this->color === self::UNDYED ? null : DyeColorIdMap::getInstance()->fromInvertedId($this->color);
	}

	public function setColor(?DyeColor $color) : void{
		$this->color = $color === null ? self::UNDYED : DyeColorIdMap::getInstance()->toInvertedId($color);
		$this->networkPropertiesDirty = true;
	}

	public function getPeek() : int{
		return $this->peek;
	}

	public function isShellClosed() : bool{
		return $this->peek === 0;
	}

	/**
	 * Returns the side of the shulker its block is on (a {@link Facing} constant): DOWN when it sits on the floor, UP
	 * when it hangs from the ceiling.
	 */
	public function getAttachFace() : int{
		return $this->attachFace;
	}

	public function setAttachFace(int $face) : void{
		if(!isset(Facing::OFFSET[$face])){
			throw new \InvalidArgumentException("Invalid face $face");
		}
		if($face !== $this->attachFace){
			$this->attachFace = $face;
			$this->networkPropertiesDirty = true;
		}
	}

	public function getArmorPoints() : int{
		return parent::getArmorPoints() + ($this->isShellClosed() ? self::CLOSED_ARMOR : 0);
	}

	public function setPeek(int $peek) : void{
		if($peek === $this->peek){
			return;
		}
		if(($peek === 0) !== ($this->peek === 0)){
			$this->playMobSound($peek === 0 ? LevelSoundEvent::SHULKER_CLOSE : LevelSoundEvent::SHULKER_OPEN);
		}
		$this->peek = $peek;
		$this->networkPropertiesDirty = true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Dye && $player->isCreative()){
			$this->setColor($item->getColor());
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	public function hasMovementUpdate() : bool{
		//only moves when falling (nothing to attach to)
		return $this->gravityEnabled && !$this->onGround;
	}

	protected function tryChangeMovement() : void{
		if(!$this->gravityEnabled){
			$this->motion = Vector3::zero();
			return;
		}
		$this->motion = new Vector3(0, ($this->motion->y - $this->gravity) * (1 - $this->drag), 0);
		if($this->onGround){
			$this->setHasGravity(false);
			$this->setAttachFace(Facing::DOWN);
		}
	}

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		//attached to its block
	}

	public function canBePushedBy(Entity $entity) : bool{
		//attached to its block (shulker.json has no pushable component): it only pushes the others away
		return false;
	}

	/**
	 * Returns whether a shulker can stay at the given block position attached to the given face (vanilla canStayAt):
	 * room for it, a block whose touching side is full on that face, and room to open its shell on the other side.
	 */
	public function canStayAt(int $x, int $y, int $z, int $face) : bool{
		$world = $this->getWorld();
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$here = PathBlockTypes::at($world, $x, $y, $z);
		if(($here & PathBlockTypes::PASSABLE) === 0 || ($here & (PathBlockTypes::WATER | PathBlockTypes::DANGER)) !== 0){
			return false;
		}
		[$dx, $dy, $dz] = Facing::OFFSET[$face];
		if((PathBlockTypes::at($world, $x - $dx, $y - $dy, $z - $dz) & PathBlockTypes::PASSABLE) === 0){
			return false; //no room to open
		}
		$sx = $x + $dx;
		$sy = $y + $dy;
		$sz = $z + $dz;
		if(!$world->isInWorld($sx, $sy, $sz)){
			return false;
		}
		if(!$world->isChunkLoaded($sx >> Chunk::COORD_BIT_SIZE, $sz >> Chunk::COORD_BIT_SIZE)){
			return true; //unknown: keep it where it is until the chunk loads
		}
		return $world->getBlockAt($sx, $sy, $sz)->getSupportType(Facing::opposite($face)) === SupportType::FULL;
	}

	/**
	 * Returns the first face a shulker could attach to at the given block position (vanilla order: down, up, north,
	 * south, west, east), or null if there is none.
	 */
	public function findAttachFace(int $x, int $y, int $z) : ?int{
		foreach(Facing::ALL as $face){
			if($this->canStayAt($x, $y, $z, $face)){
				return $face;
			}
		}
		return null;
	}

	/**
	 * Returns whether a shulker can sit at the given block position: room for it and a block face to attach to.
	 */
	public function canAttachAt(int $x, int $y, int $z) : bool{
		return $this->findAttachFace($x, $y, $z) !== null;
	}

	/**
	 * Teleports to a random free spot within 8 blocks where it can attach (vanilla: 5 tries). Returns whether it moved.
	 */
	public function tryTeleport() : bool{
		if(!$this->isAlive()){
			return false;
		}
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		for($i = 0; $i < 5; ++$i){
			$x = $bx + mt_rand(-8, 8);
			$y = $by + mt_rand(-8, 8);
			$z = $bz + mt_rand(-8, 8);
			if($x === $bx && $y === $by && $z === $bz){
				continue;
			}
			$face = $this->findAttachFace($x, $y, $z);
			if($face !== null){
				$from = $this->location->asVector3();
				if($this->teleport(new Vector3($x + 0.5, $y, $z + 0.5))){
					$this->setAttachFace($face);
					$this->getWorld()->addSound($from, new EndermanTeleportSound());
					$this->getWorld()->addGameEvent($from, GameEvent::TELEPORT, $this); //Java Shulker.teleportSomewhere()
					$this->setPeek(0);
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Vanilla hitByShulkerBullet(): an open shulker hit by a bullet teleports, and may leave a copy of itself (same
	 * colour) where it was; every other shulker within 8 blocks lowers the chance by 1/5.
	 */
	private function onHitByShulkerBullet() : void{
		if($this->isShellClosed()){
			return;
		}
		$oldLocation = $this->location->asLocation();
		$oldBox = $this->boundingBox->expandedCopy(self::DUPLICATION_SCAN_RADIUS, self::DUPLICATION_SCAN_RADIUS, self::DUPLICATION_SCAN_RADIUS);
		$oldFace = $this->attachFace;
		if(!$this->tryTeleport()){
			return;
		}
		$shulkers = 0;
		foreach($this->getWorld()->getNearbyEntities($oldBox) as $entity){
			if($entity instanceof Shulker && $entity->isAlive()){
				++$shulkers;
			}
		}
		$failureChance = ($shulkers - 1) / self::DUPLICATION_LIMIT;
		if(mt_rand() / mt_getrandmax() < $failureChance){
			return;
		}
		$copy = new Shulker($oldLocation);
		$copy->color = $this->color;
		$copy->attachFace = $oldFace;
		$copy->setSpawnReason(SpawnReason::BREEDING);
		$copy->spawnToAll();
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->isShellClosed() && EntityDamageEvent::isProjectileCause($source->getCause())){
			//closed shell: projectiles bounce off
			$source->cancel();
			return;
		}
		parent::attack($source);
		if(!$source->isCancelled() && $this->isAlive()){
			if($this->getHealth() < $this->getMaxHealth() / 2 && mt_rand(0, 3) === 0){
				$this->tryTeleport();
			}elseif($source instanceof EntityDamageByChildEntityEvent && $source->getChild() instanceof ShulkerBullet){
				$this->onHitByShulkerBullet();
			}
		}
		if(!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Living){
			$this->setPeek(self::PEEK_OPEN);
		}
	}

	/**
	 * Shoots a homing bullet at the target.
	 */
	public function shootBullet(Living $target) : ShulkerBullet{
		$bullet = new ShulkerBullet(Location::fromObject($this->location->add(0, 0.5, 0), $this->getWorld(), 0, 0), $this, $target);
		$bullet->setMotion(new Vector3(0, 0.2, 0));
		$bullet->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
		return $bullet;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if((!$this->attachmentChecked || ($this->server->getTick() + $this->id) % 20 === 0) && !$this->gravityEnabled){
			$this->attachmentChecked = true;
			$x = (int) floor($this->location->x);
			$y = (int) floor($this->location->y);
			$z = (int) floor($this->location->z);
			if(!$this->canStayAt($x, $y, $z, $this->attachFace)){
				//vanilla findNewAttachment(): another face of the same spot, or somewhere else
				$face = $this->findAttachFace($x, $y, $z);
				if($face !== null){
					$this->setAttachFace($face);
				}elseif(!$this->tryTeleport()){
					//nowhere to attach: fall
					$this->setHasGravity(true);
				}
			}
		}
		$target = $this->getAttackTarget();
		if($target !== null && $this->sensing->isValidTarget($target, 16.0)){
			$this->setPeek(self::PEEK_OPEN);
			$this->lookControl->setLookAtEntity($target);
			if(--$this->attackCooldown <= 0){
				$this->attackCooldown = mt_rand(20, 60);
				if($this->sensing->canSee($target)){
					$this->shootBullet($target);
				}
			}
		}elseif($this->peekTicks > 0){
			if(--$this->peekTicks <= 0){
				$this->setPeek(0);
			}
		}elseif(mt_rand(0, 39) === 0){
			//peek out for 1-3 seconds
			$this->peekTicks = 20 * mt_rand(1, 3);
			$this->setPeek(self::PEEK_IDLE);
		}elseif($this->peek === self::PEEK_OPEN){
			$this->setPeek(0);
		}
		return true;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function getDrops() : array{
		return [VanillaItems::SHULKER_SHELL()->setCount(mt_rand(0, 1) + $this->lootingBonus())];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::SHULKER_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->color);
		$properties->setInt(EntityMetadataProperties::SHULKER_PEEK_ID, $this->peek);
		$properties->setByte(EntityMetadataProperties::SHULKER_ATTACH_FACE, $this->attachFace);
		$loc = $this->location;
		$properties->setBlockPos(EntityMetadataProperties::SHULKER_ATTACH_POS, new BlockPosition((int) floor($loc->x), (int) floor($loc->y), (int) floor($loc->z)));
	}
}
