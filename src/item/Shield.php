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

use pocketmine\block\Planks;
use pocketmine\block\tile\Banner as TileBanner;
use pocketmine\block\utils\BannerPatternLayer;
use pocketmine\block\utils\DyeColor;
use pocketmine\data\bedrock\BannerPatternTypeIdMap;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function sin;

/**
 * Blocks melee attacks, projectiles and explosions coming from the front while raised.
 *
 * Vanilla (https://minecraft.wiki/w/Shield): 336 durability on Java, 337 on Bedrock; repaired with planks. On Bedrock
 * the shield is raised by sneaking with it in either hand; it starts blocking 5 ticks after being raised. Blocked
 * attacks of 3 or more damage cost 1 + the damage rounded down (Java BlocksAttacks$ItemDamageFunction: threshold 3,
 * base 1, factor 1). A hit from an axe disables the shield for 5 seconds.
 *
 * A shield crafted with a banner shows the banner's base colour and patterns. Bedrock stores them like a banner block
 * entity does: "Base" (inverted dye colour ID) and "Patterns" (list of {Pattern, Color}).
 */
class Shield extends Durable implements Releasable{

	/** Ticks the shield must be raised before it blocks (Java value; Bedrock's delay isn't documented). */
	public const BLOCK_DELAY_TICKS = 5;
	/** Ticks a shield stays disabled after being hit by an axe. */
	public const AXE_DISABLE_TICKS = 100;
	/** Blocked attacks below this damage don't wear the shield down. */
	public const MIN_DAMAGE_FOR_DURABILITY_LOSS = 3.0;

	public const TAG_BASE = TileBanner::TAG_BASE;
	public const TAG_PATTERNS = TileBanner::TAG_PATTERNS;

	private ?DyeColor $baseColor = null;
	/**
	 * @var BannerPatternLayer[]
	 * @phpstan-var list<BannerPatternLayer>
	 */
	private array $patterns = [];

	/**
	 * Returns the base colour of the banner applied to this shield, or null if no banner was applied.
	 */
	public function getBaseColor() : ?DyeColor{
		return $this->baseColor;
	}

	/** @return $this */
	public function setBaseColor(?DyeColor $color) : self{
		$this->baseColor = $color;
		return $this;
	}

	/**
	 * @return BannerPatternLayer[]
	 * @phpstan-return list<BannerPatternLayer>
	 */
	public function getPatterns() : array{
		return $this->patterns;
	}

	/**
	 * @param BannerPatternLayer[] $patterns
	 * @phpstan-param list<BannerPatternLayer> $patterns
	 *
	 * @return $this
	 */
	public function setPatterns(array $patterns) : self{
		$this->patterns = $patterns;
		return $this;
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);

		$colorIdMap = DyeColorIdMap::getInstance();
		$baseTag = $tag->getTag(self::TAG_BASE);
		$this->baseColor = $baseTag instanceof IntTag ? $colorIdMap->fromInvertedId($baseTag->getValue()) : null;

		$this->patterns = [];
		$patterns = $tag->getListTag(self::TAG_PATTERNS, CompoundTag::class);
		if($patterns !== null){
			$patternIdMap = BannerPatternTypeIdMap::getInstance();
			foreach($patterns as $t){
				$patternType = $patternIdMap->fromId($t->getString(TileBanner::TAG_PATTERN_NAME, ""));
				if($patternType === null){
					continue;
				}
				$patternColor = $colorIdMap->fromInvertedId($t->getInt(TileBanner::TAG_PATTERN_COLOR, 0)) ?? DyeColor::BLACK;
				$this->patterns[] = new BannerPatternLayer($patternType, $patternColor);
			}
		}
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);

		$colorIdMap = DyeColorIdMap::getInstance();
		if($this->baseColor !== null){
			$tag->setInt(self::TAG_BASE, $colorIdMap->toInvertedId($this->baseColor));
		}else{
			$tag->removeTag(self::TAG_BASE);
		}

		if(count($this->patterns) > 0){
			$patterns = new ListTag();
			$patternIdMap = BannerPatternTypeIdMap::getInstance();
			foreach($this->patterns as $pattern){
				$patterns->push(CompoundTag::create()
					->setString(TileBanner::TAG_PATTERN_NAME, $patternIdMap->toId($pattern->getType()))
					->setInt(TileBanner::TAG_PATTERN_COLOR, $colorIdMap->toInvertedId($pattern->getColor()))
				);
			}
			$tag->setTag(self::TAG_PATTERNS, $patterns);
		}else{
			$tag->removeTag(self::TAG_PATTERNS);
		}
	}

	public function getMaxDurability() : int{
		return 337;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getCooldownTag() : ?string{
		return ItemCooldownTags::SHIELD;
	}

	public function canStartUsingItem(Player $player) : bool{
		return !$player->hasItemCooldown($this);
	}

	/**
	 * Returns whether the given item can repair this shield in an anvil.
	 */
	public function isValidRepairMaterial(Item $item) : bool{
		return $item instanceof ItemBlock && $item->getBlock() instanceof Planks;
	}

	/**
	 * Returns the durability a blocked attack of the given damage costs.
	 */
	public static function getDurabilityCost(float $damage) : int{
		return $damage >= self::MIN_DAMAGE_FOR_DURABILITY_LOSS ? 1 + (int) floor($damage) : 0;
	}

	/**
	 * Returns where an attack a shield could block comes from, or null if a shield can't block it: the attacker or
	 * projectile for melee and projectile attacks, the exploding entity or block for explosions (vanilla: explosions
	 * are not in the shield's bypasses_shield damage types, so a raised shield facing the blast stops all its damage).
	 */
	public static function getBlockableAttackSource(EntityDamageEvent $source) : ?Vector3{
		switch($source->getCause()){
			case EntityDamageEvent::CAUSE_ENTITY_ATTACK:
			case EntityDamageEvent::CAUSE_PROJECTILE:
			case EntityDamageEvent::CAUSE_FIREBALL:
			case EntityDamageEvent::CAUSE_WITHER_SKULL:
			case EntityDamageEvent::CAUSE_ENTITY_EXPLOSION:
				if(!$source instanceof EntityDamageByEntityEvent){
					return null;
				}
				$entity = $source instanceof EntityDamageByChildEntityEvent ? $source->getChild() : $source->getDamager();
				return $entity?->getPosition();
			case EntityDamageEvent::CAUSE_BLOCK_EXPLOSION:
				if($source instanceof EntityDamageByEntityEvent){ //TNT minecart, plugins
					return $source->getDamager()?->getPosition();
				}
				if($source instanceof EntityDamageByBlockEvent){ //beds and respawn anchors
					return $source->getDamager()->getPosition()->add(0.5, 0.5, 0.5);
				}
				return null;
			default:
				return null;
		}
	}

	/**
	 * Returns whether an attack from $sourcePosition can be blocked by a shield held by an entity at
	 * $defenderPosition facing $yaw. Only the horizontal direction counts (pitch is ignored): the attack must come
	 * from the half-space in front of the defender, i.e. within 90 degrees of where it faces.
	 */
	public static function isAttackFromFront(float $yaw, Vector3 $defenderPosition, Vector3 $sourcePosition) : bool{
		$facingX = -sin(deg2rad($yaw));
		$facingZ = cos(deg2rad($yaw));
		$toSourceX = $sourcePosition->x - $defenderPosition->x;
		$toSourceZ = $sourcePosition->z - $defenderPosition->z;
		return $facingX * $toSourceX + $facingZ * $toSourceZ > 0;
	}
}
