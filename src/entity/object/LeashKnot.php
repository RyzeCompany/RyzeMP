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

namespace pocketmine\entity\object;

use pocketmine\block\Fence;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Leashable;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\sound\MobSound;
use pocketmine\world\World;
use function floor;

/**
 * The knot of leads tied to a fence (vanilla LeashFenceKnotEntity). It holds the leashed mobs until it is punched, a
 * player uses it (taking the mobs back or untying the leads), or the fence is gone; then the leads drop. Knots are not
 * saved: mobs remember the fence and tie themselves to a new knot when loaded (see {@link Mob::setLeashHolder()}).
 */
class LeashKnot extends Entity implements NeverSavedWithChunkEntity{
	/** Mobs a player leads within this range get tied to the fence they use. */
	public const TIE_RANGE = 7.0;

	public static function getNetworkTypeId() : string{ return EntityIds::LEASH_KNOT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.5, 0.375); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	/**
	 * Whether leads can be tied to the block (fences).
	 */
	public static function canTieTo(World $world, Vector3 $pos) : bool{
		$block = $world->getBlock($pos);
		return $block instanceof Fence;
	}

	public function getFencePosition() : Vector3{
		return new Vector3((int) floor($this->location->x), (int) floor($this->location->y), (int) floor($this->location->z));
	}

	/**
	 * Returns the knot on the fence, creating it if needed.
	 */
	public static function getOrCreate(World $world, Vector3 $fence) : self{
		$x = $fence->getFloorX();
		$y = $fence->getFloorY();
		$z = $fence->getFloorZ();
		foreach($world->getNearbyEntities(new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1)) as $entity){
			if($entity instanceof self && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				return $entity;
			}
		}
		$knot = new self(new Location($x + 0.5, $y + 0.25, $z + 0.5, $world, 0, 0));
		$knot->spawnToAll();
		$world->addSound($knot->getPosition(), new MobSound(LevelSoundEvent::LEASHKNOT_PLACE, EntityIds::LEASH_KNOT));
		return $knot;
	}

	/**
	 * Returns the mobs (and squids) whose lead the entity holds within the range.
	 *
	 * @return Leashable[]
	 */
	public static function getMobsLeashedTo(Entity $holder, float $range) : array{
		$pos = $holder->getPosition();
		$result = [];
		foreach($holder->getWorld()->getNearbyEntities(new AxisAlignedBB($pos->x - $range, $pos->y - $range, $pos->z - $range, $pos->x + $range, $pos->y + $range, $pos->z + $range)) as $entity){
			if($entity instanceof Leashable && $entity->getLeashHolder() === $holder){
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * Ties the mobs the player leads to this knot. Returns whether there were any.
	 */
	public function tieMobsOf(Player $player) : bool{
		$mobs = self::getMobsLeashedTo($player, self::TIE_RANGE);
		foreach($mobs as $mob){
			$mob->setLeashHolder($this);
		}
		return $mobs !== [];
	}

	/**
	 * Unties every mob (their leads drop if $dropLeads) and removes the knot.
	 */
	public function untie(bool $dropLeads = true) : void{
		foreach(self::getMobsLeashedTo($this, 12.0) as $mob){
			$mob->unleash($dropLeads);
		}
		$this->getWorld()->addSound($this->location, new MobSound(LevelSoundEvent::LEASHKNOT_BREAK, EntityIds::LEASH_KNOT));
		$this->flagForDespawn();
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($this->tieMobsOf($player)){
			return true;
		}
		$this->untie();
		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
			return;
		}
		$source->call();
		if(!$source->isCancelled()){
			//a broken knot drops the leads only with doentitydrops
			$this->untie(GameRules::allowsEntityDrops($this->getWorld()));
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->closed && !$this->isFlaggedForDespawn() && ($this->server->getTick() + $this->id) % 20 === 0){
			if(!self::canTieTo($this->getWorld(), $this->getFencePosition())){
				$this->untie();
			}elseif(self::getMobsLeashedTo($this, 12.0) === []){
				$this->flagForDespawn();
			}
		}
		return true;
	}
}
