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

use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\SwellGoal;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Ocelot;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Explosive;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\item\FlintSteel;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\Position;
use pocketmine\world\sound\IgniteSound;
use pocketmine\world\sound\ItemBreakSound;
use function max;
use function min;
use function mt_rand;

/**
 * Bedrock values: 20 health, movement 0.2 (approaches its target at 1.25x), fuse 1.5 seconds started within 2.5 blocks
 * of its target and cancelled beyond 6 blocks, explosion power 3 (6 when charged: struck by lightning, POWERED flag).
 * Can be ignited with flint and steel. Runs away from cats and ocelots within 6 blocks (behavior.avoid_mob_type,
 * sprinting at 1.2x).
 * Loot: 0-2 gunpowder (+0-1 per Looting level), and a music disc when killed by a skeleton's arrow.
 * Lightning charges it without hurting it. The explosion is centred at its feet; a charged creeper's explosion makes
 * the zombies, skeletons, wither skeletons, creepers and piglins it kills drop their head ({@link ChargedCreeperHeads}).
 */
class Creeper extends Monster implements Explosive{
	public const TAG_POWERED = "powered"; //TAG_Byte
	public const TAG_FUSE = "Fuse"; //TAG_Short
	public const TAG_IGNITED = "ignited"; //TAG_Byte

	public const DEFAULT_FUSE = 30;
	public const EXPLOSION_POWER = 3.0;

	private int $swell = 0;
	private int $swellDirection = -1;
	private int $fuse = self::DEFAULT_FUSE;
	private bool $powered = false;
	private bool $ignited = false;
	private bool $exploded = false;

	public static function getNetworkTypeId() : string{ return EntityIds::CREEPER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.8, 0.6, 1.7);
	}

	public function getName() : string{
		return "Creeper";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.2);
		parent::initEntity($nbt);
		$this->powered = $nbt->getByte(self::TAG_POWERED, 0) !== 0;
		$this->fuse = max(1, $nbt->getShort(self::TAG_FUSE, self::DEFAULT_FUSE));
		$this->ignited = $nbt->getByte(self::TAG_IGNITED, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_POWERED, $this->powered ? 1 : 0);
		$nbt->setShort(self::TAG_FUSE, $this->fuse);
		$nbt->setByte(self::TAG_IGNITED, $this->ignited ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(2, new SwellGoal($this));
		$this->goalSelector->addGoal(3, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Cat || $e instanceof Ocelot, 6.0, 1.0, 1.2));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.25, 0.0, 16.0));
		$this->goalSelector->addGoal(5, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(6, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(6, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new NearestAttackableTargetGoal($this, 16.0, true, 10));
		$this->targetSelector->addGoal(2, new HurtByTargetGoal($this));
	}

	/**
	 * Lightning makes a charged creeper. Bedrock's damage_sensor cancels the strike's damage for creepers (deals_damage
	 * "no" for the lightning family, Java hurts them), so the bolt that charges a creeper can't kill it.
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		if(!$this->isClosed() && !$this->isFlaggedForDespawn()){
			$this->setPowered(true);
		}
	}

	public function isPowered() : bool{ return $this->powered; }

	public function setPowered(bool $powered) : void{
		$this->powered = $powered;
		$this->networkPropertiesDirty = true;
	}

	public function getSwell() : int{ return $this->swell; }

	public function getFuse() : int{ return $this->fuse; }

	public function getSwellDirection() : int{ return $this->swellDirection; }

	public function setSwellDirection(int $direction) : void{
		if($direction > 0 && $this->swellDirection <= 0 && $this->swell === 0){
			$this->playMobSound(LevelSoundEvent::FUSE);
		}
		if(($direction > 0) !== ($this->swellDirection > 0)){
			$this->networkPropertiesDirty = true;
		}
		$this->swellDirection = $direction;
	}

	/**
	 * Whether the creeper was ignited by flint and steel: it explodes whatever happens.
	 */
	public function isIgnited() : bool{ return $this->ignited; }

	public function ignite() : void{
		if(!$this->ignited){
			$this->ignited = true;
			$this->networkPropertiesDirty = true;
			$this->broadcastSound(new IgniteSound());
		}
	}

	public function isSwelling() : bool{
		return $this->ignited || $this->swellDirection > 0;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof FlintSteel && !$this->ignited){
			$this->ignite();
			if($player->hasFiniteResources()){
				$item->applyDamage(1);
				if($item->isBroken()){
					$player->broadcastSound(new ItemBreakSound());
				}
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive() || $this->exploded){
			return $hasUpdate;
		}
		if($this->ignited && $this->swellDirection <= 0){
			$this->setSwellDirection(1);
		}
		$this->swell = max(0, min($this->fuse, $this->swell + ($this->swellDirection > 0 ? $tickDiff : -$tickDiff)));
		if($this->swell >= $this->fuse){
			$this->explode();
		}
		return $hasUpdate;
	}

	public function explode() : void{
		if($this->exploded){
			return;
		}
		$this->exploded = true;
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_POWER * ($this->powered ? 2 : 1));
		$ev->call();
		if($ev->isCancelled()){
			$this->exploded = false;
			$this->swell = 0;
			$this->ignited = false;
			$this->setSwellDirection(-1);
			return;
		}
		$this->flagForDespawn();
		//at the feet, like Java's explodeCreeper (getX(), getY(), getZ()) and Bedrock's explode component
		$explosion = new Explosion(Position::fromObject($this->location, $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance());
		if($ev->isBlockBreaking()){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}

	public function hasExploded() : bool{
		return $this->exploded;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->exploded){
			$source->cancel();
			return;
		}
		parent::attack($source);
	}

	public function getDrops() : array{
		if($this->exploded){
			return [];
		}
		$drops = [VanillaItems::GUNPOWDER()->setCount(mt_rand(0, 2) + $this->lootingBonus())];
		$cause = $this->lastDamageCause;
		if($cause instanceof EntityDamageByChildEntityEvent && $cause->getDamager() instanceof Skeleton){
			$discs = [
				VanillaItems::RECORD_13(), VanillaItems::RECORD_CAT(), VanillaItems::RECORD_BLOCKS(), VanillaItems::RECORD_CHIRP(),
				VanillaItems::RECORD_FAR(), VanillaItems::RECORD_MALL(), VanillaItems::RECORD_MELLOHI(), VanillaItems::RECORD_STAL(),
				VanillaItems::RECORD_STRAD(), VanillaItems::RECORD_WARD(), VanillaItems::RECORD_11(), VanillaItems::RECORD_WAIT()
			];
			$drops[] = $discs[mt_rand(0, 11)];
		}
		$head = ChargedCreeperHeads::getHeadDrop($this);
		if($head !== null){
			$drops[] = $head;
		}
		return $drops;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->isSwelling());
		$properties->setGenericFlag(EntityMetadataFlags::POWERED, $this->powered);
		$properties->setInt(EntityMetadataProperties::FUSE_LENGTH, $this->fuse);
	}
}
