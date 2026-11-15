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

namespace pocketmine\entity\vehicle;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Explosive;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\item\Durable;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\FlintSteel;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\sound\IgniteSound;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

/**
 * Minecart with TNT (vanilla minecraft:tnt_minecart). It is primed (4 second fuse) by a powered activator rail, or by
 * a player using flint and steel or a fire charge on it; fire, lava, explosions and survival hits while it moves
 * prime it with a short random fuse instead of breaking it. It explodes at once when hit by a burning arrow, when it
 * crashes into a block at speed, or when it lands after falling 3 blocks or more. The explosion grows with the speed
 * of the cart: power 3 (Bedrock) plus up to 1.5 x the speed in blocks per tick (vanilla formula).
 * While the tntexplodes game rule is off nothing primes or sets it off (vanilla tnt_minecart.json filters), but
 * mobgriefing doesn't stop its explosions from breaking blocks.
 */
class MinecartTNT extends AbstractMinecart implements Explosive{
	public const TAG_FUSE = "Fuse"; //TAG_Short, -1 when not primed

	/** Fuse when primed, in ticks (vanilla tnt_minecart fuse_length 4 seconds). */
	public const FUSE_TICKS = 80;
	/** Explosion power of a still cart (vanilla tnt_minecart minecraft:explode power). */
	public const BASE_EXPLOSION_POWER = 3.0;
	/** Minimum horizontal speed squared for a crash or a hit to set the cart off. */
	public const SPEED_SQUARED_THRESHOLD = 0.01;
	/** Minimum fall distance that makes the cart explode when it lands. */
	public const FALL_EXPLOSION_DISTANCE = 3.0;

	protected int $fuse = -1;
	private bool $exploded = false;

	public static function getNetworkTypeId() : string{ return EntityIds::TNT_MINECART; }

	public function getName() : string{
		return "Minecart with TNT";
	}

	public function getMinecartType() : MinecartType{
		return MinecartType::TNT;
	}

	public function getCartDrops() : array{
		return [VanillaItems::MINECART(), VanillaBlocks::TNT()->asItem()];
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->fuse = max(-1, $nbt->getShort(self::TAG_FUSE, -1));
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setShort(self::TAG_FUSE, $this->fuse);
		return $nbt;
	}

	public function isPrimed() : bool{
		return $this->fuse >= 0;
	}

	/**
	 * Ticks left before the explosion, or -1 if the cart isn't primed.
	 */
	public function getFuse() : int{
		return $this->fuse;
	}

	/**
	 * Primes the cart with the given fuse. Does nothing if it already is primed.
	 */
	public function prime(int $fuse = self::FUSE_TICKS) : void{
		if($this->fuse >= 0 || $this->exploded || !$this->tntExplodes()){
			return;
		}
		$this->fuse = max(0, $fuse);
		$this->networkPropertiesDirty = true;
		$this->broadcastSound(new IgniteSound());
	}

	public function hasExploded() : bool{
		return $this->exploded;
	}

	private function tntExplodes() : bool{
		return $this->getWorld()->getGameRule(GameRules::TNT_EXPLODES) !== false;
	}

	protected function tickMinecart() : void{
		$speedBefore = $this->motion->x ** 2 + $this->motion->z ** 2;
		parent::tickMinecart();
		if($this->closed || $this->exploded){
			return;
		}
		if($this->fuse > 0){
			--$this->fuse;
			$this->networkPropertiesDirty = true;
		}elseif($this->fuse === 0){
			$this->explodeWithSpeed($this->motion->x ** 2 + $this->motion->z ** 2);
			return;
		}
		if($this->isCollidedHorizontally && $speedBefore >= self::SPEED_SQUARED_THRESHOLD){
			$this->explodeWithSpeed($speedBefore);
		}
	}

	protected function onHitGround() : ?float{
		if($this->fallDistance >= self::FALL_EXPLOSION_DISTANCE){
			$power = $this->fallDistance / 10;
			$this->explodeWithSpeed($power * $power);
		}
		return parent::onHitGround();
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($this->isPrimed() || $player->isSpectator() || !$this->tntExplodes()){
			return false;
		}
		$item = $player->getInventory()->getItemInHand();
		if($item->getTypeId() === ItemTypeIds::FIRE_CHARGE){
			if(!$player->isCreative()){
				$item->pop();
				$player->getInventory()->setItemInHand($item);
			}
			$this->prime();
			return true;
		}
		if($item instanceof FlintSteel || $item->hasEnchantment(VanillaEnchantments::FIRE_ASPECT())){
			if($item instanceof Durable && !$player->isCreative()){
				$item->applyDamage(1);
				$player->getInventory()->setItemInHand($item);
			}
			$this->prime();
			return true;
		}
		return false;
	}

	protected function onActivatorRail(bool $powered) : void{
		if($powered){
			$this->prime();
		}
	}

	protected function onDamaged(EntityDamageEvent $source) : bool{
		if($source instanceof EntityDamageByChildEntityEvent){
			$projectile = $source->getChild();
			if($projectile instanceof Projectile && $projectile->isOnFire()){
				$this->explodeWithSpeed($projectile->getMotion()->lengthSquared());
				return true;
			}
		}
		return false;
	}

	private static function ignites(?EntityDamageEvent $source) : bool{
		if($source === null){
			return false;
		}
		if($source instanceof EntityDamageByChildEntityEvent && ($child = $source->getChild()) instanceof Projectile){
			return $child->isOnFire();
		}
		$cause = $source->getCause();
		return EntityDamageEvent::isFireCause($cause) || $cause === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION || $cause === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION;
	}

	protected function shouldSourceDestroy(EntityDamageEvent $source) : bool{
		return self::ignites($source);
	}

	/**
	 * Broken by a survival hit while moving fast, or by fire or an explosion, the cart is primed with a short random
	 * fuse instead (vanilla MinecartTNT.destroy()).
	 */
	public function breakCart(bool $drops) : void{
		if($drops && !$this->exploded && $this->tntExplodes() && (self::ignites($this->getLastDamageCause()) || $this->motion->x ** 2 + $this->motion->z ** 2 >= self::SPEED_SQUARED_THRESHOLD)){
			$fuse = mt_rand(0, 19) + mt_rand(0, 19);
			if($this->fuse < 0){
				$this->prime($fuse);
			}else{
				$this->fuse = min($this->fuse, $fuse);
			}
			return;
		}
		parent::breakCart($drops);
	}

	public function explode() : void{
		$this->explodeWithSpeed($this->motion->x ** 2 + $this->motion->z ** 2);
	}

	/**
	 * Explodes with the power given by the speed (squared, in blocks per tick) and removes the cart.
	 */
	public function explodeWithSpeed(float $speedSquared) : void{
		if($this->exploded || $this->closed){
			return;
		}
		if(!$this->tntExplodes()){
			if($this->fuse >= 0){
				//primed before the rule was turned off: it goes out
				$this->fuse = -1;
				$this->networkPropertiesDirty = true;
			}
			return;
		}
		$this->exploded = true;
		$speed = min(sqrt($speedSquared), 5.0);
		$power = self::BASE_EXPLOSION_POWER + (mt_rand() / mt_getrandmax()) * 1.5 * $speed;
		$ev = new EntityPreExplodeEvent($this, $power);
		$ev->call();
		if(!$ev->isCancelled()){
			$explosion = new Explosion(Position::fromObject($this->location->add(0, $this->size->getHeight() / 2, 0), $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance());
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
		$this->flagForDespawn();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->fuse >= 0);
		$properties->setInt(EntityMetadataProperties::FUSE_LENGTH, max(0, $this->fuse));
	}
}
