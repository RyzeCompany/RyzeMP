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

use pocketmine\block\CreakingHeart;
use pocketmine\block\tile\CreakingHeart as TileCreakingHeart;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\LookDetection;
use pocketmine\entity\ai\navigation\NavigationOptions;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Binary;
use pocketmine\world\particle\CreakingCrumbleParticle;
use pocketmine\world\vibration\GameEvent;
use function count;

/**
 * Creaking (Bedrock values, creaking.json): 1 health, 0.9x2.7, movement 0.4, attack 3 every 2 seconds.
 * - Neutral: strolls slowly until a player within 12 blocks looks at it (120 degree field of view), who becomes its
 *   target (minecraft:looked_at, set_target "once_and_keep_scanning").
 * - Hostile: frozen in place while any player within 24 blocks (not wearing a carved pumpkin) looks at it; when nobody
 *   looks it walks to its target and attacks it.
 * - Calms down when its target is gone or more than 24 blocks away.
 * - Its state ("minecraft:creaking_state") and the sway after a hit ("minecraft:creaking_swaying_ticks", 1 to 6 then
 *   reset) are synced to the client as actor properties.
 *
 * A creaking summoned by a creaking heart ("spawned_by_creaking_heart") remembers the heart's position (NBT
 * "home_pos", like Java) and the heart remembers it ({@link TileCreakingHeart}):
 * - It takes no damage except from the void and /kill-style suicide damage; a hit only makes it sway, and a hit by a
 *   player makes the heart react ({@link CreakingHeart::onCreakingHurt()}). It is fire proof and never despawns.
 * - Home restriction (all_movement) of 32 blocks around the heart: it wanders within it and doesn't chase a target
 *   outside it (it stops at the edge instead of running on and crumbling).
 * - It crumbles (bark particles and death sound, despawns without drops or experience) by day or when more than 34
 *   blocks from its heart, unless it has a name tag; the heart then summons another one when it can. It also crumbles
 *   when its heart no longer knows it (heart replaced, or it forgot a creaking that wasn't loaded in time).
 * - When the heart is broken it twitches for 2.25 seconds, then crumbles.
 * A creaking spawned by a player (spawn egg, summon) has none of this and dies from its single health point.
 *
 * Vibrations: changing state and being hit (entity_act), crumbling (entity_die).
 * A player-spawned creaking's paths avoid damage blocks and lava, and it takes lava damage like any mob that isn't fire
 * proof.
 *
 * Not implemented: crumbling after sharing a block with a player for 5 seconds.
 */
class Creaking extends Monster{
	public const STATE_NEUTRAL = 0;
	public const STATE_HOSTILE_OBSERVED = 1;
	public const STATE_HOSTILE_UNOBSERVED = 2;
	public const STATE_TWITCHING = 3;
	public const STATE_CRUMBLING = 4;

	public const ATTACK_DAMAGE = 3.0;
	public const ATTACK_COOLDOWN = 40;
	public const ACTIVATION_RANGE = 12.0;
	public const OBSERVATION_RANGE = 24.0;
	public const FIELD_OF_VIEW = 120.0;

	/** home_distance above which a heart-bound creaking crumbles */
	public const HEART_DESPAWN_DISTANCE = 34.0;
	/** minecraft:home restriction_radius (all_movement) around the heart of a heart-bound creaking */
	public const HOME_RADIUS = 32;
	/** minecraft:twitching timer: 2.25 seconds */
	public const TWITCH_TICKS = 45;
	/** creaking_swaying_ticks goes from 1 to this, then back to 0 (it sways for exactly 5 ticks) */
	public const MAX_SWAYING_TICKS = 6;

	public const TAG_HOME_POS = "home_pos"; //TAG_IntArray: position of the creaking heart that summoned it

	/** variable_max_auto_step: walks up whole blocks, half blocks while immobile (jump prevented) */
	private const STEP_HEIGHT = 1.0625;
	private const STEP_HEIGHT_JUMP_PREVENTED = 0.5625;

	private int $state = self::STATE_NEUTRAL;
	private int $lastAttackTick = -1000;
	private ?Vector3 $heartPos = null;
	private int $twitchTicks = 0;
	private int $swayingTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::CREAKING; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.7, 0.9, 2.3);
	}

	public function getName() : string{
		return "Creaking";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(1);
		$this->setMovementSpeed(0.4);
		parent::initEntity($nbt);
		$this->setStepHeight(self::STEP_HEIGHT);
		$home = $nbt->getTag(self::TAG_HOME_POS);
		if($home instanceof IntArrayTag && count($home->getValue()) === 3){
			//pocketmine/nbt reads IntArray values as unsigned: negative coordinates are sign-extended back
			[$x, $y, $z] = $home->getValue();
			$this->setHeartPosition(new Vector3(Binary::signInt($x), Binary::signInt($y), Binary::signInt($z)));
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if($this->heartPos !== null){
			$nbt->setIntArray(self::TAG_HOME_POS, [$this->heartPos->getFloorX(), $this->heartPos->getFloorY(), $this->heartPos->getFloorZ()]);
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(2, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, self::OBSERVATION_RANGE, self::ATTACK_COOLDOWN));
		$this->goalSelector->addGoal(7, new class($this, 0.3) extends RandomStrollGoal{
			public function canStart() : bool{
				return $this->mob instanceof Creaking && $this->mob->getState() === Creaking::STATE_NEUTRAL && parent::canStart();
			}
		});
	}

	public function getState() : int{
		return $this->state;
	}

	/**
	 * Returns whether the creaking can't move or attack (minecraft:immobile: watched, twitching or crumbling).
	 */
	public function isFrozen() : bool{
		return $this->state === self::STATE_HOSTILE_OBSERVED || $this->state === self::STATE_TWITCHING || $this->state === self::STATE_CRUMBLING;
	}

	private function setState(int $state) : void{
		if($state === $this->state){
			return;
		}
		$this->state = $state;
		$frozen = $this->isFrozen();
		$this->goalSelector->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, $frozen);
		$this->knockbackResistanceAttr->setValue($frozen ? 1.0 : 0.0);
		$this->setStepHeight($frozen ? self::STEP_HEIGHT_JUMP_PREVENTED : self::STEP_HEIGHT);
		if($frozen){
			$this->navigation->stop();
			$this->motion = new Vector3(0, $this->motion->y, 0);
		}
		$this->networkPropertiesDirty = true;
		$this->setActorProperty("minecraft:creaking_state", $state);
		$sound = match($state){
			self::STATE_NEUTRAL => LevelSoundEvent::DEACTIVATE,
			self::STATE_HOSTILE_OBSERVED => LevelSoundEvent::FREEZE,
			self::STATE_HOSTILE_UNOBSERVED => LevelSoundEvent::UNFREEZE,
			default => null
		};
		if($sound !== null){
			$this->playMobSound($sound);
			//become_hostile, become_neutral, on_target_start_looking, on_target_stop_looking: emit_vibration entity_act
			$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_ACTION, $this);
		}
	}

	/**
	 * Returns the position of the creaking heart that summoned this creaking, or null if it wasn't summoned by one.
	 */
	public function getHeartPosition() : ?Vector3{
		return $this->heartPos;
	}

	/**
	 * Returns whether the creaking was summoned by a creaking heart (and is protected by it).
	 */
	public function isHeartBound() : bool{
		return $this->heartPos !== null;
	}

	/**
	 * Ties the creaking to the creaking heart at the given position. Use {@link TileCreakingHeart::setCreaking()} to
	 * bind both sides.
	 */
	public function setHeartPosition(?Vector3 $pos) : void{
		$this->heartPos = $pos?->floor();
		if($pos !== null){
			$this->setPersistent(true);
		}
		//spawned_by_creaking_heart: minecraft:home around the heart, restriction_radius 32, all_movement
		$this->setHomePos($this->heartPos);
		$this->setHomeRestriction($pos !== null ? self::HOME_RADIUS : 0, $pos !== null);
		//spawned_by_creaking_heart: its paths go over lava and into damage blocks (spawned_by_player: they don't)
		$this->navigation->setOptions(NavigationOptions::forEntity(self::getNetworkTypeId())
			->setAvoidDamageBlocks($pos === null)
			->setCanPathOverLava($pos !== null));
	}

	/**
	 * Returns the heart's block entity if its chunk is loaded and it still has this creaking.
	 */
	public function getHeartTile() : ?TileCreakingHeart{
		if($this->heartPos === null){
			return null;
		}
		$tile = $this->getWorld()->getTile($this->heartPos);
		return $tile instanceof TileCreakingHeart && $tile->isBoundTo($this) ? $tile : null;
	}

	public function canDespawn() : bool{
		return $this->heartPos === null && parent::canDespawn();
	}

	public function isFireProof() : bool{
		return $this->heartPos !== null;
	}

	public function canFreeze() : bool{
		return false; //creaking.json freezing_immune
	}

	public function attack(EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		if($this->heartPos !== null && $cause !== EntityDamageEvent::CAUSE_VOID && $cause !== EntityDamageEvent::CAUSE_SUICIDE){
			//damage_sensor "no_but_side_effects_apply": it sways, and the heart reacts to players
			$source->cancel();
			if($source instanceof EntityDamageByEntityEvent && $this->isAlive() && $this->state < self::STATE_TWITCHING){
				//damaged_by_player / damaged_by_entity: emit_vibration entity_act
				$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_ACTION, $this);
			}
			if($source instanceof EntityDamageByEntityEvent && $this->isAlive() && $this->state < self::STATE_TWITCHING && $this->swayingTicks === 0){
				$this->setSwayingTicks(1);
				$damager = $source->getDamager();
				if($damager instanceof Player && $this->heartPos !== null){
					$heart = $this->getWorld()->getBlock($this->heartPos);
					if($heart instanceof CreakingHeart){
						$heart->onCreakingHurt($this);
					}
				}
			}
		}
		parent::attack($source);
	}

	private function setSwayingTicks(int $ticks) : void{
		$this->swayingTicks = $ticks;
		$this->setActorProperty("minecraft:creaking_swaying_ticks", $ticks);
	}

	public function getSwayingTicks() : int{
		return $this->swayingTicks;
	}

	/**
	 * Makes the creaking twitch for {@link Creaking::TWITCH_TICKS} ticks, then crumble (its heart was broken).
	 */
	public function startTwitching() : void{
		if($this->state === self::STATE_TWITCHING || $this->state === self::STATE_CRUMBLING || !$this->isAlive()){
			return;
		}
		$this->twitchTicks = self::TWITCH_TICKS;
		$this->setState(self::STATE_TWITCHING);
	}

	/**
	 * Makes the creaking crumble: bark particles and death sound, then it despawns without drops (minecraft:crumble).
	 */
	public function crumble() : void{
		if($this->state === self::STATE_CRUMBLING || $this->isFlaggedForDespawn() || $this->closed){
			return;
		}
		$this->setState(self::STATE_CRUMBLING);
		$this->getWorld()->addParticle($this->location->add(0, $this->size->getHeight() / 2, 0), new CreakingCrumbleParticle());
		$this->playMobSound(LevelSoundEvent::DEATH);
		$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_DIE, $this);
		$this->flagForDespawn();
	}

	/**
	 * Checks the conditions a heart-bound creaking lives under (environment sensor of "spawned_by_creaking_heart").
	 */
	private function checkHeart() : void{
		$heartPos = $this->heartPos;
		if($heartPos === null){
			return;
		}
		$world = $this->getWorld();
		if(!$world->isChunkLoaded($heartPos->getFloorX() >> 4, $heartPos->getFloorZ() >> 4)){
			return; //can't tell yet
		}
		$tile = $world->getTile($heartPos);
		if(!$tile instanceof TileCreakingHeart || !$tile->isBoundTo($this)){
			$this->crumble(); //no longer bound to a heart
			return;
		}
		$tile->relinkCreaking($this);
		if($this->nameTag === "" && (
			!CreakingHeart::isNaturalNight($world) ||
			$this->location->distanceSquared($heartPos->add(0.5, 0.5, 0.5)) > self::HEART_DESPAWN_DISTANCE ** 2
		)){
			//crumble_and_notify_creaking_heart: the heart forgets it and may summon another one
			$tile->clearCreaking();
			$this->crumble();
		}
	}

	/**
	 * Returns whether a player that can see the creaking is looking at it.
	 *
	 * @phpstan-param \Closure(Player) : bool $filter
	 */
	private function findWatcher(float $range, \Closure $filter) : ?Player{
		foreach($this->getWorld()->getPlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator() || $player->getLocation()->distanceSquared($this->location) > $range ** 2 || !$filter($player)){
				continue;
			}
			if(LookDetection::isInFieldOfView($player, $this, self::FIELD_OF_VIEW)){
				return $player;
			}
		}
		return null;
	}

	/**
	 * Updates the creaking's state from who is looking at it (looked_at_cooldown 0.1 s: called every 2 ticks).
	 */
	public function updateLookedAt() : void{
		if($this->state === self::STATE_TWITCHING || $this->state === self::STATE_CRUMBLING){
			return;
		}
		if($this->state === self::STATE_NEUTRAL){
			$watcher = $this->findWatcher(self::ACTIVATION_RANGE, fn(Player $p) => Sensing::isTargetablePlayer($p));
			if($watcher !== null){
				$this->setAttackTarget($watcher);
				$this->playMobSound(LevelSoundEvent::ACTIVATE);
				$this->setState(self::STATE_HOSTILE_OBSERVED);
			}
			return;
		}
		$target = $this->getAttackTarget();
		if($target === null || !$this->sensing->isValidTarget($target, self::OBSERVATION_RANGE)){
			$this->setAttackTarget(null);
			$this->setState(self::STATE_NEUTRAL);
			return;
		}
		$watched = $this->findWatcher(self::OBSERVATION_RANGE, fn(Player $p) => !LookDetection::wearsCarvedPumpkin($p)) !== null;
		$this->setState($watched ? self::STATE_HOSTILE_OBSERVED : self::STATE_HOSTILE_UNOBSERVED);
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		if($this->isFrozen() || $this->server->getTick() - $this->lastAttackTick < self::ATTACK_COOLDOWN){
			return false;
		}
		$this->lastAttackTick = $this->server->getTick();
		return parent::attackMelee($target, $baseDamage);
	}

	protected function tryChangeMovement() : void{
		parent::tryChangeMovement();
		if($this->isFrozen()){
			$this->motion = $this->motion->withComponents(0, null, 0);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive() || $this->closed || $this->isFlaggedForDespawn()){
			return $hasUpdate;
		}
		if($this->swayingTicks > 0){
			$this->setSwayingTicks($this->swayingTicks >= self::MAX_SWAYING_TICKS ? 0 : $this->swayingTicks + 1);
		}
		if($this->state === self::STATE_TWITCHING){
			$this->twitchTicks -= $tickDiff;
			if($this->twitchTicks <= 0){
				$this->crumble();
			}
			return true;
		}
		if($this->heartPos !== null){
			$this->checkHeart();
			if($this->isFlaggedForDespawn()){
				return true;
			}
		}
		if(($this->server->getTick() + $this->id) % 2 === 0){
			$this->updateLookedAt();
		}
		return $hasUpdate;
	}

	public function getXpDropAmount() : int{
		return 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::CREAKING_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//body_rotation_blocked while immobile
		$properties->setGenericFlag(EntityMetadataFlags::BODY_ROTATION_BLOCKED, $this->isFrozen());
	}
}
