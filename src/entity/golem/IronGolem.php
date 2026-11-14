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

namespace pocketmine\entity\golem;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MoveTowardsHomeRestrictionGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Hoglin;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\raid\Village;
use pocketmine\world\raid\VillageScanner;
use pocketmine\world\World;
use function ceil;
use function max;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (entities/iron_golem.json): 100 health, 1.4x2.9, movement 0.25, knockback resistance 1, attack 7-21
 * (random) that throws the target up. Attacks monsters except creepers within 16 blocks, and whoever hurts it except
 * creepers (players too, unless it was built by a player: "PlayerCreated"). Repaired by 25 health with iron ingots.
 * No fall damage. Loot (loot_tables/entities/iron_golem.json): 0-2 poppies, 3-5 iron ingots. Golems not built by
 * players also attack players with a reputation of -100 or less with a villager nearby (see
 * {@link VillagerV2::getPlayerReputation()}); villages summon them (see {@link VillagerV2::trySummonGolem()}). Offering
 * flowers: see {@link IronGolemOfferFlowerGoal}. Golems also step in when a villager within 16 blocks is hurt (see
 * {@link IronGolem::onVillagerAttacked()}).
 *
 * Hoglins and zoglins are attacked too unless on Peaceful, and a monster (not a creeper) pushing the golem becomes its
 * target with a 5% chance per tick (target_when_pushed). At night golems patrol the village they are in
 * ({@link IronGolemMoveThroughVillageGoal}); golems summoned by villagers stay in their village (dwelling restriction,
 * {@link IronGolem::getVillage()}).
 */
class IronGolem extends Mob{
	public const TAG_PLAYER_CREATED = "PlayerCreated"; //TAG_Byte
	/** Golems not built by players attack players this disliked by a villager nearby (vanilla: -100). */
	public const HOSTILE_REPUTATION = -100;
	private const VILLAGER_RANGE = 16.0;

	/** Chance that a golem near a villager hurt by a player turns on that player (Bedrock defend_village_target). */
	public const DEFEND_VILLAGE_CHANCE = 0.05;

	/** Highest drop a path goes down (vanilla minecraft:preferred_path max_fall_blocks; 3 for most mobs). */
	private const MAX_FALL_BLOCKS = 1;

	/** TAG_Byte, set on golems summoned by villagers */
	private const TAG_VILLAGE_CREATED = "PmVillageCreated";
	/** Bedrock saves the component groups added by events here (TAG_List<TAG_String>, "+minecraft:village_created"). */
	private const TAG_DEFINITIONS = "definitions";

	/** Ticks between two village lookups (vanilla dweller update_interval_base 60, update_interval_variant 40). */
	private const VILLAGE_CHECK_TICKS = 60;
	private const VILLAGE_CHECK_VARIANT = 40;

	/** Chance per tick a monster other than a creeper pushing the golem becomes its target (target_when_pushed). */
	public const TARGET_WHEN_PUSHED_PERCENT = 5;

	private bool $playerCreated = false;
	private bool $offeringFlower = false;
	private bool $villageCreated = false;
	private ?Village $village = null;
	private int $nextVillageCheck = 0;
	private int $dwellingRadius = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::IRON_GOLEM; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.9, 1.4, 2.5);
	}

	public function canBeLeashed() : bool{
		return true;
	}

	public function getName() : string{
		return "Iron Golem";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(100);
		$this->setMovementSpeed(0.25);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
		$this->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue(1.0);
		$this->playerCreated = $nbt->getByte(self::TAG_PLAYER_CREATED, 0) !== 0;
		$this->villageCreated = $nbt->getByte(self::TAG_VILLAGE_CREATED, 0) !== 0;
		$definitions = $nbt->getTag(self::TAG_DEFINITIONS);
		if($definitions instanceof ListTag){
			//golems from Bedrock worlds
			foreach($definitions as $tag){
				if($tag instanceof StringTag){
					if($tag->getValue() === "+minecraft:village_created"){
						$this->villageCreated = true;
					}elseif($tag->getValue() === "+minecraft:player_created" && $nbt->getTag(self::TAG_PLAYER_CREATED) === null){
						$this->playerCreated = true;
					}
				}
			}
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_PLAYER_CREATED, $this->playerCreated ? 1 : 0);
		if($this->villageCreated){
			$nbt->setByte(self::TAG_VILLAGE_CREATED, 1);
		}
		return $nbt;
	}

	protected function createNavigation() : PathNavigator{
		return new PathNavigator($this, self::MAX_FALL_BLOCKS);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new MeleeAttackGoal($this, 1.0, 1.0, 64.0));
		$this->goalSelector->addGoal(3, new IronGolemMoveThroughVillageGoal($this));
		//move_towards_dwelling_restriction: village golems that strayed walk back (the village is refreshed first)
		$this->goalSelector->addGoal(4, new class($this) extends MoveTowardsHomeRestrictionGoal{
			public function __construct(private IronGolem $golem){
				parent::__construct($golem, 1.0, retryCooldown: 100);
			}

			public function canStart() : bool{
				if(!$this->golem->isVillageCreated()){
					return false;
				}
				$this->golem->getVillage();
				return parent::canStart();
			}
		});
		$this->goalSelector->addGoal(5, new IronGolemOfferFlowerGoal($this));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));
		$this->targetSelector->addGoal(2, new HurtByTargetGoal($this, 64.0));
		//the filter only accepts monsters: the search is skipped in worlds without any (requiredClass)
		$this->targetSelector->addGoal(3, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Monster && !$e instanceof Creeper, 16.0, true, 10, requiredClass: Monster::class));
		//hoglins are animals here, but iron golems attack them (and zoglins, which are monsters) unless on Peaceful
		$this->targetSelector->addGoal(3, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Hoglin, 16.0, true, 10, fn() => $this->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL, Hoglin::class));
		$this->targetSelector->addGoal(3, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Player && $this->isDislikedByVillagers($e), 16.0, true, 20, fn() => !$this->playerCreated));
	}

	/**
	 * Whether the golem was summoned by villagers (vanilla component group minecraft:village_created): it stays in its
	 * village (dwelling restriction, see {@link IronGolem::getVillage()}).
	 */
	public function isVillageCreated() : bool{
		return $this->villageCreated;
	}

	public function setVillageCreated(bool $villageCreated) : void{
		$this->villageCreated = $villageCreated;
		$this->nextVillageCheck = 0;
		if(!$villageCreated){
			$this->dwellingRadius = 0;
		}
	}

	/**
	 * Returns the village the golem is in, looked up again every 3-5 seconds (vanilla dweller update_interval 60 +
	 * 0-40 ticks). A village golem looks for its village around its home first, so that it finds it again after
	 * straying; if that village is gone it joins the one it is in (can_migrate). Its home becomes the village centre
	 * and its restriction radius covers the village boundary.
	 *
	 * @internal
	 */
	public function getVillage() : ?Village{
		$tick = $this->getAiTick();
		if($tick < $this->nextVillageCheck){
			return $this->village;
		}
		$this->nextVillageCheck = $tick + self::VILLAGE_CHECK_TICKS + mt_rand(0, self::VILLAGE_CHECK_VARIANT);
		$world = $this->getWorld();
		$village = null;
		$home = $this->getHomePos();
		if($this->villageCreated && $home !== null){
			$village = VillageScanner::findVillageAt($world, $home);
		}
		$village ??= VillageScanner::findVillageAt($world, $this->location);
		$this->village = $village;
		if($this->villageCreated){
			if($village === null){
				$this->dwellingRadius = 0;
			}else{
				$this->setHomePos($village->getCenter());
				$bounds = $village->getBounds();
				$this->dwellingRadius = (int) ceil(max($bounds->maxX - $bounds->minX, $bounds->maxZ - $bounds->minZ) / 2);
			}
		}
		return $village;
	}

	public function getHomeRadius() : int{
		return $this->villageCreated ? $this->dwellingRadius : parent::getHomeRadius();
	}

	public function isPlayerCreated() : bool{
		return $this->playerCreated;
	}

	public function setPlayerCreated(bool $playerCreated) : void{
		$this->playerCreated = $playerCreated;
	}

	public function isOfferingFlower() : bool{
		return $this->offeringFlower;
	}

	/**
	 * Holds out or withdraws a poppy (IRON_GOLEM_OFFER_FLOWER / IRON_GOLEM_WITHDRAW_FLOWER actor events).
	 */
	public function setOfferingFlower(bool $offering) : void{
		if($this->offeringFlower === $offering){
			return;
		}
		$this->offeringFlower = $offering;
		TakeFlowerGoal::setOffering($this, $offering);
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, $offering ? ActorEvent::IRON_GOLEM_OFFER_FLOWER : ActorEvent::IRON_GOLEM_WITHDRAW_FLOWER, 0, null)]);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->offeringFlower){
			$player->getNetworkSession()->sendDataPacket(ActorEventPacket::create($this->id, ActorEvent::IRON_GOLEM_OFFER_FLOWER, 0, null));
		}
	}

	/**
	 * Called when a villager within 16 blocks is hurt by an entity. Golems without a target go after monsters and
	 * other mobs that hurt villagers; players only make golems that were not built by players hostile, with a 5%
	 * chance per hit (Bedrock defend_village_target attack_chance). Players that villagers dislike are attacked anyway
	 * (see {@link IronGolem::isDislikedByVillagers()}).
	 */
	public function onVillagerAttacked(Living $attacker) : void{
		if(!$this->isAlive() || !$attacker->isAlive() || $attacker instanceof IronGolem || $this->getAttackTarget() !== null){
			return;
		}
		if($attacker instanceof Player){
			if($this->playerCreated || !$attacker->hasFiniteResources() || mt_rand() / mt_getrandmax() >= self::DEFEND_VILLAGE_CHANCE){
				return;
			}
		}
		$this->setAttackTarget($attacker);
	}

	/**
	 * Whether a villager near the golem has a reputation of {@link IronGolem::HOSTILE_REPUTATION} or less for the player.
	 */
	public function isDislikedByVillagers(Player $player) : bool{
		$loc = $this->location;
		$r = self::VILLAGER_RANGE;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			if($entity instanceof VillagerV2 && $entity->isAlive() && $entity->getPlayerReputation($player) <= self::HOSTILE_REPUTATION){
				return true;
			}
		}
		return false;
	}

	/**
	 * target_when_pushed: a monster other than a creeper pushing the golem becomes its target with a 5% chance per tick.
	 */
	protected function onEntityContact(Entity $entity) : void{
		if(
			$entity instanceof Monster && !$entity instanceof Creeper && $this->getAttackTarget() === null &&
			mt_rand(1, 100) <= self::TARGET_WHEN_PUSHED_PERCENT && $entity->isAlive()
		){
			$this->setAttackTarget($entity);
		}
	}

	protected function onDispose() : void{
		TakeFlowerGoal::setOffering($this, false);
		$this->village = null;
		parent::onDispose();
	}

	public function setAttackTarget(?Living $target) : void{
		if($target instanceof Creeper || ($target instanceof Player && $this->playerCreated)){
			$target = null;
		}
		parent::setAttackTarget($target);
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, mt_rand(7, 21));
		if($hit && $this->lastMeleeHitHasDefaultKnockback()){
			$target->setMotion($target->getMotion()->add(0, 0.4, 0));
			//BDS plays "throw" and "attack" (the latter from Mob::attackMelee())
			$this->playMobSound(LevelSoundEvent::THROW);
		}
		return $hit;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_FALL || $source->getCause() === EntityDamageEvent::CAUSE_DROWNING){
			$source->cancel();
		}
		parent::attack($source);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->getTypeId() === ItemTypeIds::IRON_INGOT && $this->getHealth() < $this->getMaxHealth()){
			$this->consumeHeldItem($player);
			$this->heal(new EntityRegainHealthEvent($this, 25, EntityRegainHealthEvent::CAUSE_CUSTOM));
			$this->playMobSound(LevelSoundEvent::IRONGOLEM_REPAIR);
			return true;
		}
		return false;
	}

	public function getDrops() : array{
		$drops = [VanillaItems::IRON_INGOT()->setCount(mt_rand(3, 5))];
		$poppies = mt_rand(0, 2);
		if($poppies > 0){
			$drops[] = VanillaBlocks::POPPY()->asItem()->setCount($poppies);
		}
		return $drops;
	}
}
