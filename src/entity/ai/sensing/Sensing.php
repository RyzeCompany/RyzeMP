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

namespace pocketmine\entity\ai\sensing;

use pocketmine\block\MobHead;
use pocketmine\block\utils\MobHeadType;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ArmorStand;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\ItemBlock;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use function max;
use function str_starts_with;

/**
 * Line of sight and target lookups for a mob. Line of sight results are cached until the next AI update, so several
 * goals asking about the same entity cost one ray trace.
 */
final class Sensing{
	/**
	 * @var bool[] entity ID => visible
	 * @phpstan-var array<int, bool>
	 */
	private array $seen = [];
	/** AI tick of the last reset (the last AI update evaluating goals). */
	private int $resetTick = -1;
	/** Whether the mob's class keeps Entity::getWorld() (see {@link Sensing::keepsGetWorld()}). */
	private bool $mobKeepsGetWorld;

	/**
	 * Whether entity classes keep Entity::getWorld() (don't override it), by class.
	 * @var bool[]
	 * @phpstan-var array<class-string<Entity>, bool>
	 */
	private static array $keepsGetWorldByClass = [];

	public function __construct(
		private Mob $mob
	){
		$this->mobKeepsGetWorld = self::keepsGetWorld($mob);
	}

	/**
	 * Returns whether the entity's world is the world of its location object, as for every class that doesn't override
	 * Entity::getWorld(): checked once per class.
	 */
	private static function keepsGetWorld(Entity $entity) : bool{
		return self::$keepsGetWorldByClass[$entity::class] ??= (new \ReflectionMethod($entity, "getWorld"))->getDeclaringClass()->getName() === Entity::class;
	}

	/**
	 * Forgets cached line of sight results; called at the start of every AI update.
	 */
	public function reset() : void{
		$this->seen = [];
		$this->resetTick = $this->mob->getAiTick();
	}

	/**
	 * Returns whether the mob's goals are evaluated on this tick (the AI budget granted its AI update and the line of
	 * sight cache was just reset). Running goals doing periodic searches (target reselection...) do them only then, like
	 * goals starting.
	 */
	public function isEvaluationTick() : bool{
		return $this->resetTick === $this->mob->getAiTick();
	}

	public function canSee(Entity $entity) : bool{
		$id = $entity->getId();
		return $this->seen[$id] ??= self::hasLineOfSight($this->mob, $entity);
	}

	/**
	 * Returns whether nothing blocks the view between the eyes of the two entities: opaque full blocks, and the collision
	 * boxes of doors, trapdoors and fence gates (vanilla ClipContext.Block.COLLIDER: a closed door hides what is behind
	 * it, an open one only where its panel is). Glass, leaves and other see-through blocks don't block it.
	 */
	public static function hasLineOfSight(Entity $from, Entity $to) : bool{
		$world = $from->getWorld();
		if($to->getWorld() !== $world){
			return false;
		}
		$start = $from->getEyePos();
		$end = $to->getEyePos();
		$distSq = $start->distanceSquared($end);
		if($distSq > 128 * 128){
			return false;
		}
		if($distSq < 0.000001){
			return true; //same eye position: nothing in between (and VoxelRayTrace needs a direction)
		}
		foreach(VoxelRayTrace::betweenPoints($start, $end) as $pos){
			$x = (int) $pos->x;
			$y = (int) $pos->y;
			$z = (int) $pos->z;
			$flags = PathBlockTypes::at($world, $x, $y, $z);
			if(($flags & PathBlockTypes::OPAQUE) !== 0 || (($flags & PathBlockTypes::SIGHT_BY_SHAPE) !== 0 && self::hitsCollisionBoxes($world, $x, $y, $z, $start, $end))){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns whether the segment crosses a collision box of the block (doors, trapdoors and fence gates only, so the
	 * Block object is only built for the rare rays going through one).
	 */
	private static function hitsCollisionBoxes(World $world, int $x, int $y, int $z, Vector3 $start, Vector3 $end) : bool{
		foreach($world->getBlockAt($x, $y, $z)->getCollisionBoxes() as $bb){
			if($bb->calculateIntercept($start, $end) !== null){
				return true;
			}
		}
		return false;
	}

	/** Invisibility never cuts the range at which mobs notice an entity below this (vanilla TargetingConditions). */
	private const MIN_DETECTION_RANGE = 2.0;

	/**
	 * Returns how visible the entity is to the looking mob, as a factor of the range it would be noticed at (vanilla
	 * LivingEntity::getVisibilityPercent(), Bedrock target_sneak_visibility_multiplier, target_invisible_multiplier and
	 * minecraft:is_hidden_when_invisible): 0.8 while sneaking, times 0.7 x the share of the 4 armour slots it fills when
	 * invisible (at least 0.1: 0.07 without armour), and half that for a skeleton, zombie, creeper, piglin or piglin
	 * brute when it wears that mob's head.
	 */
	public static function getVisibilityFactor(Living $target, Entity $looker) : float{
		$head = match($looker::getNetworkTypeId()){
			EntityIds::SKELETON => MobHeadType::SKELETON,
			EntityIds::ZOMBIE => MobHeadType::ZOMBIE,
			EntityIds::CREEPER => MobHeadType::CREEPER,
			EntityIds::PIGLIN, EntityIds::PIGLIN_BRUTE => MobHeadType::PIGLIN,
			default => null
		};
		$invisible = $target->isInvisible();
		$sneaking = $target->isSneaking();
		if(!$invisible && !$sneaking && $head === null){
			return 1.0;
		}
		$factor = $sneaking ? 0.8 : 1.0;
		$armor = $target->getArmorInventory();
		if($invisible){
			$worn = 0;
			for($slot = ArmorInventory::SLOT_HEAD; $slot <= ArmorInventory::SLOT_FEET; ++$slot){
				if(!$armor->isSlotEmpty($slot)){
					++$worn;
				}
			}
			$factor *= 0.7 * max(0.1, $worn / 4);
		}
		if($head !== null && !$armor->isSlotEmpty(ArmorInventory::SLOT_HEAD)){
			$helmet = $armor->getHelmet();
			if($helmet instanceof ItemBlock && ($block = $helmet->getBlock()) instanceof MobHead && $block->getMobHeadType() === $head){
				$factor *= 0.5;
			}
		}
		return $factor;
	}

	/**
	 * Returns whether the looking mob notices the entity $distanceSquared away when it notices visible ones up to $range
	 * blocks away: sneaking, invisibility and mob heads shorten that range (see {@link Sensing::getVisibilityFactor()}),
	 * down to 2 blocks. Only for picking new targets: targets already chosen are not lost by turning invisible.
	 */
	public static function canDetect(Entity $looker, Living $target, float $range, float $distanceSquared) : bool{
		if($distanceSquared <= self::MIN_DETECTION_RANGE * self::MIN_DETECTION_RANGE){
			return true;
		}
		$factor = self::getVisibilityFactor($target, $looker);
		if($factor >= 1.0){
			return true;
		}
		$detection = max($range * $factor, self::MIN_DETECTION_RANGE);
		return $distanceSquared <= $detection * $detection;
	}

	/**
	 * Returns whether mobs never target the entity whatever their filters accept: armor stands (vanilla
	 * ArmorStand::attackable(), Bedrock "inanimate" family).
	 */
	public static function isNeverTargeted(Living $entity) : bool{
		return $entity instanceof ArmorStand;
	}

	/** mobs.ignore-plugin-npcs */
	private static bool $ignorePluginNpcs = true;

	/**
	 * Sets whether mobs leave plugin NPCs alone (mobs.ignore-plugin-npcs in pocketmine.yml).
	 */
	public static function setIgnorePluginNpcs(bool $ignore) : void{
		self::$ignorePluginNpcs = $ignore;
	}

	/**
	 * Returns whether mobs must leave the entity alone because it is a plugin's NPC ({@link Sensing::looksLikePluginNpc()}),
	 * unless mobs.ignore-plugin-npcs is off. E.g. a shop NPC that extends Villager is not chased by zombies. Plugins can
	 * decide for each target with EntityTargetEvent.
	 */
	public static function isPluginNpc(Living $entity) : bool{
		return self::$ignorePluginNpcs && self::looksLikePluginNpc($entity);
	}

	/**
	 * Returns whether the entity looks like a plugin's NPC (shopkeepers, menus, lobby mobs...): a living entity of a
	 * plugin class that has any name, or that stands still for clients (setNoClientPredictions(), e.g. Slapper), or
	 * that is not saved with the world (setCanSaveWithChunk(false), e.g. Shopkeepers). Mobs of mob plugins (e.g. Mobs)
	 * have none of these.
	 */
	public static function looksLikePluginNpc(Living $entity) : bool{
		if($entity instanceof Player || str_starts_with($entity::class, "pocketmine\\")){
			return false;
		}
		return $entity->getNameTag() !== "" || $entity->hasNoClientPredictions() || !$entity->canSaveWithChunk();
	}

	/**
	 * Returns whether the player can be targeted by hostile mobs (alive, survival or adventure).
	 */
	public static function isTargetablePlayer(Player $player) : bool{
		$mode = $player->getGamemode();
		return $player->isAlive() && !$player->isClosed() && $player->isConnected() && ($mode === GameMode::SURVIVAL || $mode === GameMode::ADVENTURE);
	}

	/**
	 * Returns the nearest player within range matching the filter (default: any alive player not in spectator mode).
	 *
	 * @param bool       $testInvisible      whether sneaking and invisible players (and players wearing the mob's head) are
	 *                                       only noticed closer (see {@link Sensing::canDetect()}): for picking targets
	 * @param float|null $maxDistanceSquared only players at most this far away (squared) are looked at, when that is less
	 *                                       than $range (which still decides how close invisible players are noticed):
	 *                                       looking for a target closer than the current one
	 *
	 * @phpstan-param (\Closure(Player) : bool)|null $filter
	 */
	public function getNearestPlayer(float $range, ?\Closure $filter = null, bool $testInvisible = false, ?float $maxDistanceSquared = null) : ?Player{
		$loc = $this->mob->getLiveLocation();
		$best = null;
		$bestDist = $range * $range;
		if($maxDistanceSquared !== null && $maxDistanceSquared < $bestDist){
			$bestDist = $maxDistanceSquared;
		}
		foreach($this->mob->getWorld()->getPlayers() as $player){
			$live = Mob::getLiveLocationOf($player);
			if($live !== null){
				//the live location, and the distance before isAlive() and isSpectator() (no side effects): most players
				//of the world are out of range
				$d = $live->distanceSquared($loc);
				if(!($d <= $bestDist) || !$player->isAlive() || $player->isSpectator()){
					continue;
				}
			}else{
				//a class overriding getLocation(): the original order
				if(!$player->isAlive() || $player->isSpectator()){
					continue;
				}
				$d = $player->getLocation()->distanceSquared($loc);
				if(!($d <= $bestDist)){
					continue;
				}
			}
			if($testInvisible && !self::canDetect($this->mob, $player, $range, $d)){
				continue;
			}
			if($filter === null || $filter($player)){
				$best = $player;
				$bestDist = $d;
			}
		}
		return $best;
	}

	/**
	 * Returns whether the living entity is still a valid target at most $range blocks away.
	 */
	public function isValidTarget(?Living $target, float $range) : bool{
		//a mob is never its own target (vanilla TargetingConditions), e.g. a shulker hit by its own bullet
		if($target === null || $target === $this->mob || !$target->isAlive() || $target->isClosed()){
			return false;
		}
		//asked every tick by the goals keeping a target: the worlds are compared on the live locations, without the calls
		//of getWorld() (whose check of the world can't fail here: the target isn't closed, the mob is ticking), unless a
		//class overrides getWorld() or getLocation()
		$mobLoc = $this->mob->getLiveLocation();
		$targetLoc = Mob::getLiveLocationOf($target);
		if($targetLoc !== null && $this->mobKeepsGetWorld && self::keepsGetWorld($target) ? $targetLoc->world !== $mobLoc->world : $target->getWorld() !== $this->mob->getWorld()){
			return false;
		}
		if(($target instanceof Player && !self::isTargetablePlayer($target)) || self::isPluginNpc($target) || self::isNeverTargeted($target)){
			return false;
		}
		return ($targetLoc ?? $target->getLocation())->distanceSquared($mobLoc) <= $range * $range;
	}

	public static function eyeDistanceSquared(Entity $a, Vector3 $b) : float{
		return $a->getEyePos()->distanceSquared($b);
	}
}
