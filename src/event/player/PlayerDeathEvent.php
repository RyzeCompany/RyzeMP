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

namespace pocketmine\event\player;

use pocketmine\block\Bed;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\RespawnAnchor;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\object\FireworkRocket;
use pocketmine\entity\projectile\Egg;
use pocketmine\entity\projectile\IceBomb;
use pocketmine\entity\projectile\LlamaSpit;
use pocketmine\entity\projectile\ShulkerBullet;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\projectile\Trident;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\item\Item;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Translatable;
use pocketmine\player\Player;
use function method_exists;
use function str_starts_with;
use function strlen;
use function substr;

class PlayerDeathEvent extends EntityDeathEvent{
	protected Player $player;

	private Translatable|string $deathMessage;
	private Translatable|string $deathScreenMessage;
	private bool $keepInventory = false;
	private bool $keepXp = false;

	/**
	 * @param Item[]                   $drops
	 * @param string|Translatable|null $deathMessage Null will cause the default vanilla message to be used
	 */
	public function __construct(Player $entity, array $drops, int $xp, Translatable|string|null $deathMessage){
		parent::__construct($entity, $drops, $xp);
		$this->player = $entity;
		$this->deathMessage = $deathMessage ?? self::deriveMessage($entity->getDisplayName(), $entity->getLastDamageCause());
		$this->deathScreenMessage = $this->deathMessage;
	}

	/**
	 * @return Player
	 */
	public function getEntity(){
		return $this->player;
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	public function getDeathMessage() : Translatable|string{
		return $this->deathMessage;
	}

	public function setDeathMessage(Translatable|string $deathMessage) : void{
		$this->deathMessage = $deathMessage;
	}

	public function getDeathScreenMessage() : Translatable|string{
		return $this->deathScreenMessage;
	}

	public function setDeathScreenMessage(Translatable|string $deathScreenMessage) : void{
		$this->deathScreenMessage = $deathScreenMessage;
	}

	public function getKeepInventory() : bool{
		return $this->keepInventory;
	}

	public function setKeepInventory(bool $keepInventory) : void{
		$this->keepInventory = $keepInventory;
	}

	public function getKeepXp() : bool{
		return $this->keepXp;
	}

	public function setKeepXp(bool $keepXp) : void{
		$this->keepXp = $keepXp;
	}

	/**
	 * Returns the vanilla death message for the given death cause.
	 */
	public static function deriveMessage(string $name, ?EntityDamageEvent $deathCause) : Translatable{
		if($deathCause === null){
			return KnownTranslationFactory::death_attack_generic($name);
		}
		$cause = $deathCause->getCause();
		$damager = $deathCause instanceof EntityDamageByEntityEvent ? $deathCause->getDamager() : null;
		switch($cause){
			case EntityDamageEvent::CAUSE_ENTITY_ATTACK:
				if($damager instanceof Living){
					$weapon = self::getNamedWeapon($damager);
					if($deathCause->getModifier(EntityDamageEvent::MODIFIER_SMASH) > 0){
						//the smash attack of a mace (Bedrock mace_smash)
						return $weapon !== null ?
							KnownTranslationFactory::death_attack_maceSmash_player_item($name, self::getEntityName($damager), $weapon) :
							KnownTranslationFactory::death_attack_maceSmash_player($name, self::getEntityName($damager));
					}
					if($damager instanceof Player){
						return $weapon !== null ?
							KnownTranslationFactory::death_attack_player_item($name, $damager->getDisplayName(), $weapon) :
							KnownTranslationFactory::death_attack_player($name, $damager->getDisplayName());
					}
					return $weapon !== null ?
						KnownTranslationFactory::death_attack_mob_item($name, self::getEntityName($damager), $weapon) :
						KnownTranslationFactory::death_attack_mob($name, self::getEntityName($damager));
				}
				break;

			case EntityDamageEvent::CAUSE_PROJECTILE:
			case EntityDamageEvent::CAUSE_FIREBALL:
			case EntityDamageEvent::CAUSE_WITHER_SKULL:
				if($damager !== null){
					return self::deriveProjectileMessage($name, $deathCause, $damager);
				}
				break;

			case EntityDamageEvent::CAUSE_THORNS:
				if($damager !== null){
					return KnownTranslationFactory::death_attack_thorns($name, self::getEntityName($damager));
				}
				break;

			case EntityDamageEvent::CAUSE_SONIC_BOOM:
				$killer = $damager instanceof Living ? $damager : self::getKillCredit($deathCause);
				return $killer !== null ?
					KnownTranslationFactory::death_attack_sonicBoom_player($name, self::getEntityName($killer)) :
					KnownTranslationFactory::death_attack_sonicBoom($name);

			case EntityDamageEvent::CAUSE_SUICIDE:
				return KnownTranslationFactory::death_attack_generic($name);
			case EntityDamageEvent::CAUSE_VOID:
				return KnownTranslationFactory::death_attack_outOfWorld($name);

			case EntityDamageEvent::CAUSE_FLY_INTO_WALL:
				return KnownTranslationFactory::death_attack_flyIntoWall($name);

			case EntityDamageEvent::CAUSE_FALL:
				if($deathCause->getFinalDamage() > 2){
					return KnownTranslationFactory::death_fell_accident_generic($name);
				}
				return KnownTranslationFactory::death_attack_fall($name);

			case EntityDamageEvent::CAUSE_STALAGMITE:
				return KnownTranslationFactory::death_attack_stalagmite($name);

			case EntityDamageEvent::CAUSE_STALACTITE:
				return KnownTranslationFactory::death_attack_stalactite($name);

			case EntityDamageEvent::CAUSE_SUFFOCATION:
				return KnownTranslationFactory::death_attack_inWall($name);

			case EntityDamageEvent::CAUSE_LAVA:
				$killer = self::getKillCredit($deathCause);
				return $killer !== null ?
					KnownTranslationFactory::death_attack_lava_player($name, self::getEntityName($killer)) :
					KnownTranslationFactory::death_attack_lava($name);

			case EntityDamageEvent::CAUSE_FIRE:
			case EntityDamageEvent::CAUSE_CAMPFIRE:
				$killer = self::getKillCredit($deathCause);
				return $killer !== null ?
					KnownTranslationFactory::death_attack_inFire_player($name, self::getEntityName($killer)) :
					KnownTranslationFactory::death_attack_inFire($name);

			case EntityDamageEvent::CAUSE_FIRE_TICK:
				$killer = self::getKillCredit($deathCause);
				return $killer !== null ?
					KnownTranslationFactory::death_attack_onFire_player($name, self::getEntityName($killer)) :
					KnownTranslationFactory::death_attack_onFire($name);

			case EntityDamageEvent::CAUSE_MAGMA:
				if(SulfurCube::isHotContactDamage($deathCause)){
					//death_message_override of the hot sulfur cube's area attack
					return KnownTranslationFactory::death_attack_sulfurCube_hot($name);
				}
				$killer = self::getKillCredit($deathCause);
				return $killer !== null ?
					KnownTranslationFactory::death_attack_magma_player($name, self::getEntityName($killer)) :
					KnownTranslationFactory::death_attack_magma($name);

			case EntityDamageEvent::CAUSE_DROWNING:
				$killer = self::getKillCredit($deathCause);
				return $killer !== null ?
					KnownTranslationFactory::death_attack_drown_player($name, self::getEntityName($killer)) :
					KnownTranslationFactory::death_attack_drown($name);

			case EntityDamageEvent::CAUSE_CONTACT:
				if($deathCause instanceof EntityDamageByBlockEvent){
					if($deathCause->getDamager()->getTypeId() === BlockTypeIds::CACTUS){
						$killer = self::getKillCredit($deathCause);
						return $killer !== null ?
							KnownTranslationFactory::death_attack_cactus_player($name, self::getEntityName($killer)) :
							KnownTranslationFactory::death_attack_cactus($name);
					}
				}
				break;

			case EntityDamageEvent::CAUSE_SWEET_BERRY_BUSH:
				return KnownTranslationFactory::death_attack_sweetBerry($name);

			case EntityDamageEvent::CAUSE_STARVATION:
				return KnownTranslationFactory::death_attack_starve($name);

			case EntityDamageEvent::CAUSE_WITHER:
				return KnownTranslationFactory::death_attack_wither($name);

			case EntityDamageEvent::CAUSE_BLOCK_EXPLOSION:
			case EntityDamageEvent::CAUSE_ENTITY_EXPLOSION:
				if($deathCause instanceof EntityDamageByBlockEvent){
					$block = $deathCause->getDamager();
					if($block instanceof Bed || $block instanceof RespawnAnchor){
						return KnownTranslationFactory::death_attack_explosion_by_bed($name);
					}
				}
				if($damager instanceof FireworkRocket){
					return KnownTranslationFactory::death_attack_fireworks($name);
				}
				//a fireball or wither skull explosion is credited to whoever shot it
				$killer = $damager instanceof Living ? $damager : $damager?->getOwningEntity();
				if($killer instanceof Living){
					return KnownTranslationFactory::death_attack_explosion_player($name, self::getEntityName($killer));
				}
				return KnownTranslationFactory::death_attack_explosion($name);

			case EntityDamageEvent::CAUSE_MAGIC:
				//evoker fangs, guardian beams and thrown potions of Harming name who used them
				if($damager instanceof Living){
					$weapon = self::getNamedWeapon($damager);
					return $weapon !== null ?
						KnownTranslationFactory::death_attack_indirectMagic_item($name, self::getEntityName($damager), $weapon) :
						KnownTranslationFactory::death_attack_indirectMagic($name, self::getEntityName($damager));
				}
				return KnownTranslationFactory::death_attack_magic($name);

			case EntityDamageEvent::CAUSE_DRAGON_BREATH:
				//Bedrock has no dragon's breath message
				return KnownTranslationFactory::death_attack_magic($name);

			case EntityDamageEvent::CAUSE_LIGHTNING:
				return KnownTranslationFactory::death_attack_lightningBolt($name);

			case EntityDamageEvent::CAUSE_FREEZE:
				return KnownTranslationFactory::death_attack_freeze($name);

			case EntityDamageEvent::CAUSE_FALLING_BLOCK:
				if($damager instanceof FallingBlock){
					if($damager->getBlock()->getTypeId() === BlockTypeIds::ANVIL){
						return KnownTranslationFactory::death_attack_anvil($name);
					}else{
						return KnownTranslationFactory::death_attack_fallingBlock($name);
					}
				}
				break;

			case EntityDamageEvent::CAUSE_CUSTOM:
				break;

			default:
				break;
		}

		return KnownTranslationFactory::death_attack_generic($name);
	}

	/**
	 * The message of a projectile hit, by kind of projectile, naming the shooter (or the projectile itself when nobody
	 * shot it, like an arrow from a dispenser).
	 */
	private static function deriveProjectileMessage(string $name, EntityDamageEvent $deathCause, Entity $damager) : Translatable{
		$child = $deathCause instanceof EntityDamageByChildEntityEvent ? $deathCause->getChild() : $damager;
		$shooter = $damager !== $child ? $damager : null;
		$killerName = self::getEntityName($damager);
		$weapon = $shooter instanceof Living ? self::getNamedWeapon($shooter) : null;

		if($deathCause->getCause() === EntityDamageEvent::CAUSE_FIREBALL){
			if($shooter === null){
				//a fire charge from a dispenser (vanilla unattributed_fireball)
				return KnownTranslationFactory::death_attack_onFire($name);
			}
			return $weapon !== null ?
				KnownTranslationFactory::death_attack_fireball_item($name, $killerName, $weapon) :
				KnownTranslationFactory::death_attack_fireball($name, $killerName);
		}
		if($child instanceof Trident){
			return KnownTranslationFactory::death_attack_trident($name, $killerName);
		}
		if($child instanceof LlamaSpit){
			return KnownTranslationFactory::death_attack_spit($name, $killerName);
		}
		if($child instanceof Snowball || $child instanceof Egg || $child instanceof IceBomb){
			return $weapon !== null ?
				KnownTranslationFactory::death_attack_thrown_item($name, $killerName, $weapon) :
				KnownTranslationFactory::death_attack_thrown($name, $killerName);
		}
		if($child instanceof ShulkerBullet || $child instanceof WindCharge){
			//vanilla mob_projectile and wind_charge use the melee message
			return KnownTranslationFactory::death_attack_mob($name, $killerName);
		}
		//arrows, and wither skulls (Bedrock has no wither skull message)
		return $weapon !== null ?
			KnownTranslationFactory::death_attack_arrow_item($name, $killerName, $weapon) :
			KnownTranslationFactory::death_attack_arrow($name, $killerName);
	}

	/**
	 * Returns the mob or player that hurt the victim shortly before it died of an environmental cause (vanilla kill
	 * credit), for the "whilst trying to escape" messages.
	 */
	private static function getKillCredit(EntityDamageEvent $deathCause) : ?Living{
		$victim = $deathCause->getEntity();
		return $victim instanceof Living ? $victim->getRecentAttacker() : null;
	}

	/**
	 * Returns the name of an entity for a death message: players and named mobs by their name, other mobs and
	 * projectiles by their vanilla name key (entity.<id>.name), which each client translates into its own language.
	 */
	private static function getEntityName(Entity $entity) : Translatable|string{
		if($entity instanceof Player){
			return $entity->getDisplayName();
		}
		$nameTag = $entity->getNameTag();
		if($nameTag !== ""){
			return $nameTag;
		}
		$id = $entity::getNetworkTypeId();
		if(str_starts_with($id, "minecraft:")){
			return new Translatable("entity." . substr($id, strlen("minecraft:")) . ".name");
		}
		return $entity instanceof Living ? $entity->getDisplayName() : $id;
	}

	/**
	 * Returns the custom name of the item the entity holds in its main hand, or null if it holds nothing or an item
	 * without a custom name (the "using [weapon]" messages).
	 */
	private static function getNamedWeapon(Living $entity) : ?string{
		if($entity instanceof Human){
			$item = $entity->getInventory()->getItemInHand();
		}elseif(method_exists($entity, "getMainHandItem")){
			$item = $entity->getMainHandItem();
		}else{
			return null;
		}
		return $item instanceof Item && !$item->isNull() && $item->hasCustomName() ? $item->getCustomName() : null;
	}
}
