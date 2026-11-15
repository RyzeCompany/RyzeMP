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

namespace pocketmine\item;

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\FireworkRocket as FireworkEntity;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityShootCrossbowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\sound\CrossbowLoadSound;
use pocketmine\world\sound\CrossbowShootSound;
use pocketmine\world\vibration\GameEvent;
use function cos;
use function count;
use function deg2rad;
use function max;
use function mt_rand;
use function sin;

class Crossbow extends Tool implements Chargeable{

	private const TAG_CHARGED_ITEM = "chargedItem"; // TAG_Compound

	private ?Item $chargedItem = null;

	/**
	 * Ticks of tolerance when the client reports the charge as complete: start and end of the charge reach the
	 * server with network jitter, so the measured duration can be a tick or two short of the real one.
	 */
	private const CHARGE_TOLERANCE_TICKS = 2;

	private const ARROW_SPEED = 3.15;
	/** Java CrossbowItem.getShootingPower(): rockets fly slower than arrows */
	private const FIREWORK_SPEED = 1.6;
	/** durability per projectile shot (Java CrossbowItem.getDurabilityUse()) */
	private const ARROW_DURABILITY_USE = 1;
	private const FIREWORK_DURABILITY_USE = 3;
	/** multishot: left, center, right */
	private const MULTISHOT_YAW_OFFSETS = [-10, 0, 10];

	/**
	 * A firework rocket in the off hand is loaded before any arrow (Bedrock loads rockets from the off hand only).
	 */
	private static function findOffHandFirework(Player $player) : ?FireworkRocket{
		$offHand = $player->getOffHandInventory()->getItem(0);
		return $offHand instanceof FireworkRocket && !$offHand->isNull() ? $offHand : null;
	}

	/**
	 * Only plays the loading sounds. The crossbow is loaded in onChargeComplete(), when the client reports that its
	 * charge finished: loading it here on the server's own clock replaced the held item, which reset the
	 * "using item" state, so the client's charge-complete signal arrived as a fresh use and fired the crossbow.
	 */
	public function continueUsing(Player $player, int $useDuration) : void{
		if($this->chargedItem !== null){
			return;
		}
		$quickCharge = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE()) > 0;
		$chargeDuration = $this->getChargeDuration();
		if($useDuration <= 1){
			$player->getWorld()->addSound($player->getPosition(), new CrossbowLoadSound(CrossbowLoadSound::LOADING_START, $quickCharge));
		}elseif($useDuration === $chargeDuration){
			$player->getWorld()->addSound($player->getPosition(), new CrossbowLoadSound(CrossbowLoadSound::LOADING_END, $quickCharge));
		}elseif($useDuration < $chargeDuration && ((int) ($useDuration * 25.0 / $chargeDuration)) % 16 === 0){
			$player->getWorld()->addSound($player->getPosition(), new CrossbowLoadSound(CrossbowLoadSound::LOADING_MIDDLE, $quickCharge));
		}
	}

	public function onChargeComplete(Player $player, int $useDuration) : bool{
		if($this->chargedItem !== null || $useDuration < $this->getChargeDuration() - self::CHARGE_TOLERANCE_TICKS){
			return false;
		}
		$firework = self::findOffHandFirework($player);
		if($firework !== null){
			if($player->hasFiniteResources()){
				$remaining = clone $firework;
				$player->getOffHandInventory()->setItem(0, $remaining->setCount($remaining->getCount() - 1));
			}
			$this->setCharged((clone $firework)->setCount(1));
			return true;
		}
		$ammo = ArrowAmmo::find($player);
		$arrow = $ammo?->getItem() ?? VanillaItems::ARROW();
		if($player->hasFiniteResources()){
			if($ammo === null){
				return false;
			}
			$ammo->consume();
		}
		$this->setCharged($arrow);
		return true;
	}

	public function canStartUsingItem(Player $player) : bool{
		return !$player->hasFiniteResources() || self::findOffHandFirework($player) !== null || ArrowAmmo::find($player) !== null;
	}

	public function getMaxDurability() : int{
		return 466;
	}

	public function setCharged(?Item $item) : void{
		$this->chargedItem = $item !== null ? clone $item : null;
	}

	public function isCharged() : bool{
		return $this->chargedItem !== null;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		if($this->chargedItem === null){
			return ItemUseResult::NONE;
		}
		$location = $player->getLocation();
		$entities = [];
		$multishot = $this->hasEnchantment(VanillaEnchantments::MULTISHOT());
		$isFirework = $this->chargedItem instanceof FireworkRocket;
		foreach(($multishot ? self::MULTISHOT_YAW_OFFSETS : [0]) as $yawOffset){
			$yaw = $location->yaw + $yawOffset;
			$pitch = $location->pitch;
			if($yawOffset === 0){
				$direction = $player->getDirectionVector();
			}else{
				$yawRad = deg2rad($yaw);
				$pitchRad = deg2rad($pitch);
				$xz = cos($pitchRad);
				$direction = (new Vector3(-$xz * sin($yawRad), -sin($pitchRad), $xz * cos($yawRad)))->normalize();
			}

			if($this->chargedItem instanceof FireworkRocket){
				$entity = $this->createFireworkEntity($player, $this->chargedItem, $yaw, $pitch, $direction);
			}else{
				$entity = new ArrowEntity(Location::fromObject(
					$player->getEyePos(),
					$player->getWorld(),
					($yaw > 180 ? 360 : 0) - $yaw,
					-$pitch
				), $player, true);
				$entity->setMotion($direction->multiply(self::ARROW_SPEED));
				$entity->setOwningEntity($player);
				ArrowAmmo::applyTip($this->chargedItem, $entity);
				$entity->setPierceLevel($this->getEnchantmentLevel(VanillaEnchantments::PIERCING()));
				//only the center arrow of a multishot can be picked up
				$entity->setPickupMode($player->hasFiniteResources() && $yawOffset === 0 ? ArrowEntity::PICKUP_ANY : ArrowEntity::PICKUP_CREATIVE);
			}
			$entities[] = $entity;
		}
		if(count($entities) === 0){
			throw new AssumptionFailedError("This should never happen");
		}
		$ev = new EntityShootCrossbowEvent($player, $this, $entities);
		$ev->call();
		if($ev->isCancelled()){
			foreach($ev->getProjectiles() as $projectile){
				$projectile->flagForDespawn();
			}
			return ItemUseResult::FAIL;
		}
		foreach($ev->getProjectiles() as $projectile){
			if($projectile instanceof Projectile){ //This might have changed by plugins
				$launchEv = new ProjectileLaunchEvent($projectile);
				$launchEv->call();
				if($launchEv->isCancelled()){
					$projectile->flagForDespawn();
					return ItemUseResult::FAIL;
				}
				$projectile->spawnToAll();
			}elseif($projectile instanceof FireworkEntity){
				$projectile->spawnToAll();
			}
		}
		$player->getWorld()->addSound($player->getPosition(), new CrossbowShootSound());
		$player->getWorld()->addGameEvent($player->getPosition(), GameEvent::PROJECTILE_SHOOT, $player);
		if($player->hasFiniteResources()){
			$this->applyDamage(count($entities) * ($isFirework ? self::FIREWORK_DURABILITY_USE : self::ARROW_DURABILITY_USE));
		}
		$this->chargedItem = null;
		return ItemUseResult::SUCCESS;
	}

	/**
	 * A rocket shot from a crossbow flies straight at 1.6 blocks/tick from just below the eyes and explodes on impact
	 * or at the end of its flight (Java CrossbowItem.createProjectile()).
	 */
	private function createFireworkEntity(Player $player, FireworkRocket $rocket, float $yaw, float $pitch, Vector3 $direction) : Entity{
		$flightTicks = (($rocket->getFlightTimeMultiplier() + 1) * 10) + mt_rand(0, 5) + mt_rand(0, 6);
		$entity = new FireworkEntity(Location::fromObject(
			$player->getEyePos()->subtract(0, 0.15, 0),
			$player->getWorld(),
			$yaw,
			$pitch
		), $flightTicks, $rocket->getExplosions());
		$entity->setOwningEntity($player);
		$entity->setShotAsProjectile(true);
		$entity->setMotion($direction->multiply(self::FIREWORK_SPEED));
		return $entity;
	}

	public function getChargeDuration() : int{
		$quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
		return max(1, 25 - (5 * $quickChargeLevel));
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		if($this->chargedItem !== null){
			$tag->setTag(self::TAG_CHARGED_ITEM, $this->chargedItem->nbtSerialize(-1));
		}else{
			$tag->removeTag(self::TAG_CHARGED_ITEM);
		}
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		if(($chargedItemTag = $tag->getCompoundTag(self::TAG_CHARGED_ITEM)) !== null){
			$this->chargedItem = Item::nbtDeserialize($chargedItemTag);
		}
	}

	public function getFuelTime() : int{
		return 300;
	}
}
