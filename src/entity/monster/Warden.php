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

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\InvestigateLocationGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\navigation\PathNavigator;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animation\ActorEventAnimation;
use pocketmine\entity\Attribute;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Binary;
use pocketmine\utils\Utils;
use pocketmine\world\particle\SonicExplosionParticle;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\vibration\VibrationListener;
use Ramsey\Uuid\Uuid;
use function abs;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;

/**
 * Bedrock values (Mojang bedrock-samples behavior_pack/entities/warden.json): 500 health, movement 0.3, melee 30
 * (16/30/45 by difficulty), 0.9x2.9, fire immune, no knockback, darkness to players within 20 blocks every 6 s for
 * 13 s, sonic boom 10 damage (ignores armour) with a 3 s animation, emerges in 7 s, digs back in 5.5 s after 60 s
 * without a disturbance, sniffs for 4.16 s every 5-10 s, roars for 4.2 s at a new target, hears vibrations within 16
 * blocks. Loot: a sculk catalyst (loot_tables/entities/warden.json); 5 XP when killed by a player.
 *
 * The warden is blind: it finds targets only through anger ({@link WardenAnger}) raised by vibrations, by being
 * touched or hurt, and by sniffing out nearby players. Emerging, digging, roaring, sniffing and the sonic boom are
 * animated by the client from the EMERGING, DIGGING, ROARING, SNIFFING and SONIC_BOOM flags (their sounds are part of
 * the client animations; the sonic boom charge and blast sounds are sent by the server).
 */
class Warden extends Monster implements VibrationListener{
	public const EMERGE_TICKS = 140;
	public const DIG_TICKS = 110;
	public const ROAR_TICKS = 84;
	public const SNIFF_TICKS = 83;
	public const SONIC_BOOM_TICKS = 60;
	/** Tick of the sonic boom animation at which the blast hits (vanilla Java: 34). */
	public const SONIC_BOOM_HIT_TICK = 34;
	public const SONIC_BOOM_DAMAGE = 10.0;
	public const SONIC_BOOM_COOLDOWN = 40;
	/** No sonic boom during this many ticks after the warden gets a new attack target. */
	public const SONIC_BOOM_TARGET_COOLDOWN = 200;
	public const SONIC_BOOM_RANGE_XZ = 15;
	public const SONIC_BOOM_RANGE_Y = 20;
	public const DIG_IDLE_TICKS = 1200;
	public const LISTEN_RANGE = 16;
	public const VIBRATION_COOLDOWN = 40;
	public const DARKNESS_INTERVAL = 120;
	public const DARKNESS_RANGE = 20;
	public const DARKNESS_DURATION = 260;
	public const SNIFF_RANGE = 24;
	public const SUSPICION_RANGE_XZ = 6;
	public const SUSPICION_RANGE_Y = 20;
	public const FOLLOW_RANGE = 30.0;
	/** Ambient sounds every 2 to 6 seconds (minecraft:ambient_sound_interval value 2, range 4). */
	public const AMBIENT_SOUND_MIN_TICKS = 40;
	public const AMBIENT_SOUND_MAX_TICKS = 120;
	/**
	 * LevelSoundEvent numeric ID of "heartbeat" (376 in the numeric enum used until 1.26.20) for the
	 * HEARTBEAT_LEVEL_SOUND_EVENT metadata, which is still an int.
	 */
	public const HEARTBEAT_SOUND_ID = 376;

	public const STATE_NONE = 0;
	public const STATE_EMERGING = 1;
	public const STATE_DIGGING = 2;
	public const STATE_ROARING = 3;
	public const STATE_SNIFFING = 4;
	public const STATE_SONIC_BOOM = 5;

	private const TAG_EMERGING = "Emerging"; //TAG_Short, ticks left
	private const TAG_DIGGING = "Digging"; //TAG_Short, ticks left
	private const TAG_SNIFFING = "Sniffing"; //TAG_Short, ticks left
	private const TAG_DIG_COOLDOWN = "DigCooldown"; //TAG_Int, idle ticks left before digging back
	private const TAG_SUSPICIOUS_POS = "SuspiciousPos"; //TAG_IntArray x, y, z
	private const TAG_SUSPECTS = "Suspects"; //TAG_List<TAG_Compound>, players only (runtime IDs of mobs are not saved)
	private const TAG_SUSPECT_UUID = "UUID"; //TAG_String
	private const TAG_SUSPECT_ANGER = "Anger"; //TAG_Int
	/** Highest drop a path goes down (vanilla minecraft:preferred_path max_fall_blocks; 3 for most mobs). */
	private const MAX_FALL_BLOCKS = 20;

	public static function getNetworkTypeId() : string{ return EntityIds::WARDEN; }

	private WardenAnger $anger;
	private int $state = self::STATE_NONE;
	private int $stateTicks = 0;
	private ?Vector3 $suspiciousLocation = null;
	/** @phpstan-var array{int, Vector3, int|null, int}|null arrival tick, position, suspect entity ID, anger to add */
	private ?array $pendingVibration = null;
	private int $lastVibrationTick = -1000;
	private int $lastDisturbanceTick = 0;
	private int $sonicBoomCooldownUntil = 0;
	private int $nextSniffTick = 0;
	private ?int $roarTargetId = null;
	private int $heartbeatInterval = 40;
	/** Ambient sound event shown to clients (AMBIENT_SOUND_EVENT metadata), follows the anger level like on BDS. */
	private string $ambientSoundName = "ambient";
	/**
	 * Anger towards players loaded from NBT, applied once they are online in this world.
	 * @var int[]
	 * @phpstan-var array<string, int> UUID => anger
	 */
	private array $savedPlayerAnger = [];

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.9, 0.9, 2.6);
	}

	public function getName() : string{
		return "Warden";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(500);
		$this->setMovementSpeed(0.3);
		$this->anger = new WardenAnger();
		parent::initEntity($nbt);
		$this->knockbackResistanceAttr->setValue(1.0);
		$now = $this->server->getTick();
		$this->lastDisturbanceTick = $now;
		$this->nextSniffTick = $now + mt_rand(100, 200);
		if(($digCooldown = $nbt->getTag(self::TAG_DIG_COOLDOWN)) instanceof IntTag){
			$this->lastDisturbanceTick = $now - (self::DIG_IDLE_TICKS - max(0, min(self::DIG_IDLE_TICKS, $digCooldown->getValue())));
		}
		$suspiciousPos = $nbt->getTag(self::TAG_SUSPICIOUS_POS);
		if($suspiciousPos instanceof IntArrayTag && count($pos = $suspiciousPos->getValue()) === 3){
			//pocketmine/nbt reads IntArray values as unsigned: negative coordinates are sign-extended back
			$this->suspiciousLocation = new Vector3(Binary::signInt($pos[0]), Binary::signInt($pos[1]), Binary::signInt($pos[2]));
		}
		$suspects = $nbt->getTag(self::TAG_SUSPECTS);
		if($suspects instanceof ListTag){
			foreach($suspects as $suspect){
				if($suspect instanceof CompoundTag && ($anger = $suspect->getInt(self::TAG_SUSPECT_ANGER, 0)) > 0 && ($uuid = $suspect->getString(self::TAG_SUSPECT_UUID, "")) !== ""){
					$this->savedPlayerAnger[$uuid] = min(WardenAnger::MAX_ANGER, $anger);
				}
			}
		}
		if(($emerging = $nbt->getShort(self::TAG_EMERGING, 0)) > 0){
			$this->setState(self::STATE_EMERGING, $emerging);
		}elseif(($digging = $nbt->getShort(self::TAG_DIGGING, 0)) > 0){
			$this->setState(self::STATE_DIGGING, $digging);
		}elseif(($sniffing = $nbt->getShort(self::TAG_SNIFFING, 0)) > 0){
			$this->setState(self::STATE_SNIFFING, $sniffing);
		}
		$this->getWorld()->getVibrations()->addListener($this);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$stateTag = match($this->state){
			self::STATE_EMERGING => self::TAG_EMERGING,
			self::STATE_DIGGING => self::TAG_DIGGING,
			self::STATE_SNIFFING => self::TAG_SNIFFING,
			default => null
		};
		if($stateTag !== null){
			$nbt->setShort($stateTag, max(1, $this->stateTicks));
		}
		$nbt->setInt(self::TAG_DIG_COOLDOWN, max(0, self::DIG_IDLE_TICKS - ($this->server->getTick() - $this->lastDisturbanceTick)));
		if($this->suspiciousLocation !== null){
			$nbt->setIntArray(self::TAG_SUSPICIOUS_POS, [$this->suspiciousLocation->getFloorX(), $this->suspiciousLocation->getFloorY(), $this->suspiciousLocation->getFloorZ()]);
		}
		//like Java AngerManagement: suspects are saved by UUID, which only players have here
		$suspects = $this->savedPlayerAnger;
		$world = $this->getWorld();
		foreach($this->anger->getAll() as $id => [$anger, $isPlayer]){
			if($isPlayer === 1 && ($player = $world->getEntity($id)) instanceof Player){
				$suspects[$player->getUniqueId()->toString()] = $anger;
			}
		}
		if(count($suspects) > 0){
			$list = [];
			foreach(Utils::stringifyKeys($suspects) as $uuid => $anger){
				$list[] = CompoundTag::create()
					->setString(self::TAG_SUSPECT_UUID, $uuid)
					->setInt(self::TAG_SUSPECT_ANGER, $anger);
			}
			$nbt->setTag(self::TAG_SUSPECTS, new ListTag($list));
		}
		return $nbt;
	}

	/**
	 * Gives back the anger saved towards players who are now online in this world (checked every second while some
	 * saved anger is left).
	 */
	private function restoreSavedPlayerAnger() : void{
		foreach(Utils::stringifyKeys($this->savedPlayerAnger) as $uuid => $anger){
			try{
				$player = $this->server->getPlayerByUUID(Uuid::fromString($uuid));
			}catch(\InvalidArgumentException){
				unset($this->savedPlayerAnger[$uuid]);
				continue;
			}
			if($player !== null && $player->getWorld() === $this->getWorld()){
				unset($this->savedPlayerAnger[$uuid]);
				if($this->isValidSuspect($player)){
					$this->anger->increase($player->getId(), true, $anger);
				}
			}elseif(--$anger <= 0){
				//decays like the anger of the suspects around
				unset($this->savedPlayerAnger[$uuid]);
			}else{
				$this->savedPlayerAnger[$uuid] = $anger;
			}
		}
	}

	protected function createNavigation() : PathNavigator{
		return new PathNavigator($this, self::MAX_FALL_BLOCKS);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.2, 30.0, self::FOLLOW_RANGE, meleeFov: 360.0));
		$this->goalSelector->addGoal(5, new InvestigateLocationGoal($this, 0.7, fn() => $this->suspiciousLocation, function(Vector3 $reached) : void{
			if($this->suspiciousLocation !== null && $this->suspiciousLocation->equals($reached)){
				$this->suspiciousLocation = null;
			}
		}));
		$this->goalSelector->addGoal(9, new RandomStrollGoal($this, 0.5));
		$this->goalSelector->addGoal(11, new RandomLookAroundGoal($this));
	}

	public function isFireProof() : bool{
		return true;
	}

	public function getAnger() : WardenAnger{
		return $this->anger;
	}

	public function getAngerLevel() : int{
		return $this->anger->getAngerLevel();
	}

	public function getState() : int{
		return $this->state;
	}

	public function getSuspiciousLocation() : ?Vector3{
		return $this->suspiciousLocation;
	}

	public function setSuspiciousLocation(?Vector3 $location) : void{
		$this->suspiciousLocation = $location?->floor();
		if($location !== null){
			$this->markDisturbance();
		}
	}

	public function isEmerging() : bool{ return $this->state === self::STATE_EMERGING; }

	public function isDigging() : bool{ return $this->state === self::STATE_DIGGING; }

	/**
	 * Plays the emerging animation (as when summoned by a sculk shrieker): the warden doesn't move or hear anything
	 * for 7 seconds.
	 */
	public function startEmerging() : void{
		$this->setState(self::STATE_EMERGING, self::EMERGE_TICKS);
	}

	private function setState(int $state, int $ticks) : void{
		if($state !== $this->state){
			$this->state = $state;
			$this->networkPropertiesDirty = true;
		}
		$this->stateTicks = $ticks;
		if($state !== self::STATE_NONE){
			$this->navigation->stop();
		}
		$this->goalSelector->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK, $state !== self::STATE_NONE);
	}

	private function markDisturbance() : void{
		$this->lastDisturbanceTick = $this->server->getTick();
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Suspects and anger
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Returns whether the warden may be angry at the entity: living, in this world, not a warden or an armor stand
	 * (anger_level nuisance_filter: families warden and inanimate), and for players in survival or adventure mode
	 * (Java Warden.canTargetEntity()). Distance doesn't matter: a suspect is only forgotten when its anger decays.
	 */
	public function isValidSuspect(?Entity $entity) : bool{
		if(!$entity instanceof Living || $entity instanceof Warden || $entity instanceof ArmorStand || !$entity->isAlive() || $entity->isClosed() || $entity->getWorld() !== $this->getWorld()){
			return false;
		}
		return !$entity instanceof Player || Sensing::isTargetablePlayer($entity);
	}

	/**
	 * Adds anger towards the entity (ignored for invalid suspects) and returns the new anger.
	 */
	public function increaseAnger(Entity $entity, int $amount) : int{
		if(!$this->isValidSuspect($entity)){
			return 0;
		}
		$this->markDisturbance();
		$anger = $this->anger->increase($entity->getId(), $entity instanceof Player, $amount);
		$this->updateRoarTarget();
		$this->updateAmbientSoundName();
		return $anger;
	}

	private function resolveSuspect(int $entityId) : ?Living{
		$entity = $this->getWorld()->getEntity($entityId);
		return $entity instanceof Living && $this->isValidSuspect($entity) ? $entity : null;
	}

	/**
	 * Chooses whom to roar at: the best suspect the warden is angry at, unless it is already the target. A player
	 * takes over from a mob target; otherwise the current target is kept.
	 */
	private function updateRoarTarget() : void{
		if($this->state === self::STATE_EMERGING || $this->state === self::STATE_DIGGING || $this->state === self::STATE_ROARING){
			return;
		}
		$best = $this->anger->getAngriest(fn(int $id) : bool => $this->resolveSuspect($id) !== null);
		$current = $this->getAttackTarget();
		if($best !== null && $current !== null && (
			$current->getId() === $best ||
			$current instanceof Player ||
			!$this->getWorld()->getEntity($best) instanceof Player
		)){
			$best = null;
		}
		$this->roarTargetId = $best;
	}

	/**
	 * Returns whether the warden is coming out of or going back into the ground: it can't be hurt (except by the void
	 * and /kill), pushed or thrown by explosions then (Java Warden.isInvulnerableTo(), isPushable(),
	 * ignoreExplosion(); Bedrock removes the pushable group while emerging).
	 */
	private function isDiggingOrEmerging() : bool{
		return $this->state === self::STATE_EMERGING || $this->state === self::STATE_DIGGING;
	}

	public function attack(EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		//no fire damage (fire_immune); it can drown like other mobs (breathable total_supply 15)
		if(EntityDamageEvent::isFireCause($cause) || (
			$this->isDiggingOrEmerging() && $cause !== EntityDamageEvent::CAUSE_VOID && $cause !== EntityDamageEvent::CAUSE_SUICIDE
		)){
			$source->cancel();
		}
		parent::attack($source);
		if($source->isCancelled() || !$this->isAlive()){
			return;
		}
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager(); //for projectiles, the shooter
			if($damager !== null){
				$this->increaseAnger($damager, WardenAnger::HURT_ANNOYINGNESS);
			}
		}
	}

	public function setMotion(Vector3 $motion) : bool{
		//explosions and hits don't move it while it is half underground
		return !$this->isDiggingOrEmerging() && parent::setMotion($motion);
	}

	public function canBePushedBy(Entity $entity) : bool{
		return !$this->isDiggingOrEmerging() && parent::canBePushedBy($entity);
	}

	/**
	 * Never despawns (no minecraft:despawn, Java removeWhenFarAway() false): it only leaves by digging back into the
	 * ground after a minute without disturbance.
	 */
	public function canDespawn() : bool{
		return false;
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$this->sonicBoomCooldownUntil = $this->server->getTick() + self::SONIC_BOOM_COOLDOWN;
		return parent::attackMelee($target, $baseDamage);
	}

	public function setAttackTarget(?Living $target) : void{
		$previous = $this->attackTarget;
		parent::setAttackTarget($target);
		if($this->attackTarget !== null && $this->attackTarget !== $previous){
			//a new target is chased and hit in melee first: the sonic boom only comes 10 seconds later (BDS 1.26.52, Java
			//Warden.setAttackTarget())
			$this->sonicBoomCooldownUntil = max($this->sonicBoomCooldownUntil, $this->server->getTick() + self::SONIC_BOOM_TARGET_COOLDOWN);
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Vibrations
	// ---------------------------------------------------------------------------------------------------------------

	public function getListenerPosition() : Vector3{
		return $this->location->add(0, $this->getEyeHeight(), 0);
	}

	public function getListenerRange() : int{
		return self::LISTEN_RANGE;
	}

	public function onVibration(Vector3 $pos, GameEvent $event, ?Entity $source, ?Block $affected, float $distance) : void{
		if(!$this->isAlive() || $this->closed || $this->state === self::STATE_EMERGING || $this->state === self::STATE_DIGGING){
			return;
		}
		if($source instanceof Living && !$this->isValidSuspect($source)){
			//other wardens, armor stands, creative and spectator players are not heard at all (Java
			//Warden.VibrationUser.canReceiveVibration())
			return;
		}
		$now = $this->server->getTick();
		if($this->pendingVibration !== null || $now - $this->lastVibrationTick < self::VIBRATION_COOLDOWN){
			return;
		}
		$suspect = $source;
		$annoyance = WardenAnger::DEFAULT_ANNOYINGNESS;
		if($source instanceof Projectile){
			$suspect = $source->getOwningEntity();
			$annoyance = WardenAnger::PROJECTILE_ANNOYINGNESS;
		}
		$this->pendingVibration = [$now + max(1, (int) floor($distance)), $pos->asVector3(), $suspect?->getId(), $annoyance];
	}

	/**
	 * Returns whether a vibration is travelling to the warden.
	 */
	public function isListening() : bool{
		return $this->pendingVibration !== null;
	}

	private function receiveVibration(Vector3 $pos, ?int $suspectId, int $annoyance) : void{
		$this->lastVibrationTick = $this->server->getTick();
		$this->broadcastAnimation(new ActorEventAnimation($this, ActorEvent::VIBRATION_DETECTED));
		$this->setSuspiciousLocation($pos);
		if($suspectId !== null){
			$suspect = $this->getWorld()->getEntity($suspectId);
			if($suspect !== null){
				$this->increaseAnger($suspect, $annoyance);
			}
		}
		$this->playMobSound($this->getAngerLevel() >= WardenAnger::AGITATED_THRESHOLD ? LevelSoundEvent::LISTENING_ANGRY : LevelSoundEvent::LISTENING);
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Ticking
	// ---------------------------------------------------------------------------------------------------------------

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$now = $this->server->getTick();
		if($this->pendingVibration !== null && $this->pendingVibration[0] <= $now){
			[, $pos, $suspectId, $annoyance] = $this->pendingVibration;
			$this->pendingVibration = null;
			$this->receiveVibration($pos, $suspectId, $annoyance);
		}
		$phase = ($now + $this->id) % 20;
		if($phase === 0){
			$this->secondTick();
		}
		if(($now + $this->id) % self::DARKNESS_INTERVAL === 0 && $this->state !== self::STATE_DIGGING){
			$this->applyDarkness();
		}
		return true;
	}

	/**
	 * Every second: anger decays, targets are checked, touching entities annoy the warden, heartbeat speed.
	 */
	private function secondTick() : void{
		$this->anger->decay(1);
		if(count($this->savedPlayerAnger) > 0){
			$this->restoreSavedPlayerAnger();
		}
		$target = $this->getAttackTarget();
		if($target !== null && (!$this->anger->isAngryAt($target->getId()) || !$this->isValidSuspect($target))){
			$this->setAttackTarget(null);
		}
		//forget suspects which left
		foreach($this->anger->getAll() as $id => $_){
			if($this->resolveSuspect($id) === null){
				$this->anger->forget($id);
			}
		}
		if($this->state === self::STATE_NONE){
			foreach($this->getWorld()->getCollidingEntities($this->boundingBox->expandedCopy(0.1, 0, 0.1), $this) as $entity){
				if($this->isValidSuspect($entity)){
					$this->setSuspiciousLocation($entity->getPosition());
					$this->increaseAnger($entity, WardenAnger::DEFAULT_ANNOYINGNESS);
				}
			}
		}
		$this->updateRoarTarget();

		$interval = (int) (40 - min(1.0, $this->getAngerLevel() / WardenAnger::ANGRY_THRESHOLD) * 30);
		if($interval !== $this->heartbeatInterval){
			$this->heartbeatInterval = $interval;
			$this->networkPropertiesDirty = true;
		}
		$this->updateAmbientSoundName();
	}

	/**
	 * Ambient sound for the anger level (minecraft:ambient_sound_interval event_names): angry from 80, agitated from 40.
	 */
	private function updateAmbientSoundName() : void{
		$anger = $this->getAngerLevel();
		$name = $anger >= WardenAnger::ANGRY_THRESHOLD ? "angry" : ($anger >= WardenAnger::AGITATED_THRESHOLD ? "agitated" : "ambient");
		if($name !== $this->ambientSoundName){
			$this->ambientSoundName = $name;
			$this->networkPropertiesDirty = true;
		}
	}

	private function applyDarkness() : void{
		$rangeSq = self::DARKNESS_RANGE ** 2;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!Sensing::isTargetablePlayer($player) || $player->getLocation()->distanceSquared($this->location) > $rangeSq){
				continue;
			}
			$current = $player->getEffects()->get(VanillaEffects::DARKNESS());
			if($current === null || $current->getDuration() < 200){
				$player->getEffects()->add(new EffectInstance(VanillaEffects::DARKNESS(), self::DARKNESS_DURATION, 0, false));
			}
		}
	}

	protected function tickAi(int $currentTick) : void{
		if($this->state === self::STATE_NONE){
			$this->chooseSpecialState($currentTick);
		}
		if($this->state === self::STATE_NONE){
			parent::tickAi($currentTick);
			return;
		}
		$this->tickSpecialState($currentTick);
		$this->moveControl->stop();
		$this->lookControl->tick();
	}

	private function chooseSpecialState(int $now) : void{
		if($this->roarTargetId !== null){
			if($this->resolveSuspect($this->roarTargetId) !== null){
				$this->setState(self::STATE_ROARING, self::ROAR_TICKS);
				return;
			}
			$this->roarTargetId = null;
		}
		$target = $this->getAttackTarget();
		if($target !== null){
			if($now >= $this->sonicBoomCooldownUntil && $this->isInSonicBoomRange($target)){
				$this->setState(self::STATE_SONIC_BOOM, self::SONIC_BOOM_TICKS);
				$this->playMobSound(LevelSoundEvent::SONIC_CHARGE);
			}
			return;
		}
		if($now - $this->lastDisturbanceTick >= self::DIG_IDLE_TICKS && $this->nameTag === "" && $this->suspiciousLocation === null){
			$this->setState(self::STATE_DIGGING, self::DIG_TICKS);
			return;
		}
		if($this->suspiciousLocation === null && $now >= $this->nextSniffTick){
			$this->nextSniffTick = $now + mt_rand(100, 200);
			if($this->sensing->getNearestPlayer(self::SNIFF_RANGE, fn(Player $p) => Sensing::isTargetablePlayer($p)) !== null){
				$this->setState(self::STATE_SNIFFING, self::SNIFF_TICKS);
			}
		}
	}

	public function isInSonicBoomRange(Entity $target) : bool{
		$t = $target->getLocation();
		$dx = $t->x - $this->location->x;
		$dz = $t->z - $this->location->z;
		return $dx * $dx + $dz * $dz <= self::SONIC_BOOM_RANGE_XZ ** 2 && abs($t->y - $this->location->y) <= self::SONIC_BOOM_RANGE_Y;
	}

	private function tickSpecialState(int $now) : void{
		$ticksLeft = --$this->stateTicks;
		switch($this->state){
			case self::STATE_ROARING:
				$roarTarget = $this->roarTargetId !== null ? $this->resolveSuspect($this->roarTargetId) : null;
				if($roarTarget === null){
					$this->roarTargetId = null;
					$this->setState(self::STATE_NONE, 0);
					return;
				}
				$this->lookControl->setLookAtEntity($roarTarget, 30, 30);
				if($ticksLeft <= 0){
					$this->roarTargetId = null;
					$this->setState(self::STATE_NONE, 0);
					$this->anger->increase($roarTarget->getId(), $roarTarget instanceof Player, WardenAnger::ANGRY_BOOST);
					$this->updateAmbientSoundName();
					$this->setAttackTarget($roarTarget);
					$this->suspiciousLocation = null;
				}
				return;
			case self::STATE_SONIC_BOOM:
				$target = $this->getAttackTarget();
				if($target === null){
					$this->sonicBoomCooldownUntil = $now + self::SONIC_BOOM_COOLDOWN;
					$this->setState(self::STATE_NONE, 0);
					return;
				}
				$this->lookControl->setLookAtEntity($target, 30, 30);
				if(self::SONIC_BOOM_TICKS - $ticksLeft === self::SONIC_BOOM_HIT_TICK && $this->isInSonicBoomRange($target)){
					$this->sonicBoom($target);
				}
				if($ticksLeft <= 0){
					$this->sonicBoomCooldownUntil = $now + self::SONIC_BOOM_COOLDOWN;
					$this->setState(self::STATE_NONE, 0);
				}
				return;
			case self::STATE_SNIFFING:
				if($ticksLeft <= 0){
					$this->setState(self::STATE_NONE, 0);
					$this->finishSniffing();
				}
				return;
			case self::STATE_EMERGING:
				if($ticksLeft <= 0){
					$this->setState(self::STATE_NONE, 0);
					$this->markDisturbance();
				}
				return;
			case self::STATE_DIGGING:
				if($ticksLeft <= 0){
					//gone back underground: no drops
					$this->flagForDespawn();
				}
				return;
		}
	}

	private function finishSniffing() : void{
		$nearest = $this->sensing->getNearestPlayer(self::SNIFF_RANGE, fn(Player $p) => $this->isValidSuspect($p));
		if($nearest === null){
			return;
		}
		$this->setSuspiciousLocation($nearest->getPosition());
		$pos = $nearest->getPosition();
		$dx = $pos->x - $this->location->x;
		$dz = $pos->z - $this->location->z;
		if($dx * $dx + $dz * $dz <= self::SUSPICION_RANGE_XZ ** 2 && abs($pos->y - $this->location->y) <= self::SUSPICION_RANGE_Y){
			$this->increaseAnger($nearest, WardenAnger::DEFAULT_ANNOYINGNESS);
		}
	}

	/**
	 * The sonic boom: goes through blocks, ignores armour (magic damage) and knocks the target back (Bedrock
	 * knockback_horizontal_strength 2.5, knockback_vertical_strength 0.5, knockback_height_cap 0.5).
	 */
	public function sonicBoom(Living $target) : void{
		$from = $this->location->add(0, 1.6, 0);
		$to = $target->getEyePos();
		$diff = $to->subtractVector($from);
		$length = $diff->length();
		$world = $this->getWorld();
		if($length > 0.0001){
			$dir = $diff->divide($length);
			for($i = 1, $steps = (int) floor($length) + 7; $i < $steps; ++$i){
				$world->addParticle($from->addVector($dir->multiply($i)), new SonicExplosionParticle());
			}
		}else{
			$dir = new Vector3(0, 0, 0);
		}
		$this->playMobSound(LevelSoundEvent::SONIC_BOOM);
		$damage = self::getSonicBoomDamage($target instanceof Player, $world->getDifficulty());
		if($damage <= 0){
			return;
		}
		$ev = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_SONIC_BOOM, $damage, [], 0.0);
		$target->attack($ev);
		//the boom's push replaces the event's knockback, unless a plugin set one: then that was applied instead
		if($ev->isCancelled() || !Living::isDefaultKnockback($ev, 0.0)){
			return;
		}
		$knockbackResistance = $target->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE);
		$resistance = 1 - ($knockbackResistance !== null ? $knockbackResistance->getValue() : 0.0);
		if($resistance > 0){
			$motion = $target->getMotion();
			if($target->setMotion(new Vector3(
				$motion->x + $dir->x * 2.5 * $resistance,
				min(0.5, $motion->y + $dir->y * 0.5 * $resistance),
				$motion->z + $dir->z * 2.5 * $resistance
			)) && $target instanceof Mob){
				$target->carryPush();
			}
		}
	}

	/**
	 * Sonic boom damage: the sonic_boom damage type scales with the difficulty for players (6 / 10 / 15 on easy / normal
	 * / hard, like a melee hit), mobs always take 10.
	 */
	public static function getSonicBoomDamage(bool $targetIsPlayer, int $difficulty) : float{
		return $targetIsPlayer ? self::scaleDamageForDifficulty(self::SONIC_BOOM_DAMAGE, $difficulty) : self::SONIC_BOOM_DAMAGE;
	}

	protected function nextAmbientSoundDelay() : int{
		return mt_rand(self::AMBIENT_SOUND_MIN_TICKS, self::AMBIENT_SOUND_MAX_TICKS);
	}

	protected function playAmbientSound() : void{
		//silent while roaring, emerging or digging (Java Warden.getAmbientSound())
		if($this->state === self::STATE_ROARING || $this->isDiggingOrEmerging()){
			return;
		}
		$anger = $this->getAngerLevel();
		$this->playMobSound($anger >= WardenAnger::ANGRY_THRESHOLD ? LevelSoundEvent::ANGRY : ($anger >= WardenAnger::AGITATED_THRESHOLD ? LevelSoundEvent::AGITATED : LevelSoundEvent::AMBIENT));
	}

	public function getDrops() : array{
		return [VanillaBlocks::SCULK_CATALYST()->asItem()];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::EMERGING, $this->state === self::STATE_EMERGING);
		$properties->setGenericFlag(EntityMetadataFlags::DIGGING, $this->state === self::STATE_DIGGING);
		$properties->setGenericFlag(EntityMetadataFlags::ROARING, $this->state === self::STATE_ROARING);
		$properties->setGenericFlag(EntityMetadataFlags::SNIFFING, $this->state === self::STATE_SNIFFING);
		$properties->setGenericFlag(EntityMetadataFlags::SONIC_BOOM, $this->state === self::STATE_SONIC_BOOM);
		$properties->setInt(EntityMetadataProperties::HEARTBEAT_INTERVAL_TICKS, $this->heartbeatInterval);
		$properties->setInt(EntityMetadataProperties::HEARTBEAT_LEVEL_SOUND_EVENT, self::HEARTBEAT_SOUND_ID);
		//BDS 1.26.52 switches it with the anger level (seen as "angry" in captures)
		$properties->setString(EntityMetadataProperties::AMBIENT_SOUND_EVENT, $this->ambientSoundName);
	}

	protected function onDispose() : void{
		if($this->location->isValid()){
			$this->getWorld()->getVibrations()->removeListener($this);
		}
		parent::onDispose();
	}
}
