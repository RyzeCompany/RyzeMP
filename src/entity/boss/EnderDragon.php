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

namespace pocketmine\entity\boss;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animation\ActorEventAnimation;
use pocketmine\entity\animation\DeathAnimation;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\DragonBreathCloud;
use pocketmine\entity\object\EndCrystal;
use pocketmine\entity\projectile\DragonFireball;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\dimension\DragonFight;
use pocketmine\world\dimension\EndExitPortal;
use pocketmine\world\format\Chunk;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\vibration\GameEvent;
use function abs;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function max;
use function min;
use function mt_rand;
use function sin;
use function sqrt;
use const M_PI;

/**
 * The ender dragon. Bedrock values (Mojang bedrock-samples behavior_pack/entities/ender_dragon.json and the Minecraft
 * Wiki): 200 health, one 13x4 collision box, flies through blocks destroying them (not End stone, obsidian, bedrock and
 * other dragon-immune blocks), boss bar within 125 blocks, never despawns, immune to fire, falls, drowning, magic,
 * suffocation and the void; only players and explosions hurt it. Hits that don't land near the head deal a quarter of
 * their damage plus one (about 75% less).
 *
 * Phases (Bedrock behaviours dragonholdingpattern, dragonstrafeplayer, dragonchargeplayer, dragonlanding,
 * dragonflaming/dragonscanning, dragontakeoff, dragondeath):
 * - holding: circles the fight centre (0,0 in the End, otherwise where it was spawned) 40-60 blocks away;
 * - strafing: flies at a player within 64 blocks and shoots a dragon fireball (also right after a crystal it was
 *   healing from is destroyed);
 * - charging: dives at a player's position;
 * - landing and perched (End only): lands on the exit portal pillar with a chance of 1 in (3 + crystals left) at each
 *   waypoint; perched, it breathes dragon's breath at players within 20 blocks of the portal (up to 4 times), charges
 *   the nearest player if nobody comes, and takes off after 4 breaths or 50 damage;
 * - dying: stays where it died, drops its experience during the last 2.5 s of a 10 s
 *   animation (12000 the first time in an End, 500 otherwise) and then the exit portal opens.
 *
 * End crystals within 32 blocks heal it by 1 health every 0.5 s; destroying the crystal it heals from hurts it by 10.
 * Head and wing hits: 10 (6/10/15 by difficulty) at the head, wings knock back and hurt by 5 while flying.
 */
class EnderDragon extends Mob{
	public const MAX_HEALTH = 200;
	public const HUD_RANGE = 125.0;
	/** The dragon's AI keeps running while a player is within this distance (the fight area). */
	public const ACTIVE_RANGE = 200;
	public const DEATH_TICKS = 200;
	public const HEAL_RANGE = 32.0;
	public const CRYSTAL_DESTROYED_DAMAGE = 10.0;
	public const HEAD_DAMAGE = 10.0;
	public const WING_DAMAGE = 5.0;
	public const PERCH_ESCAPE_DAMAGE = 50.0;
	public const MAX_BREATHS = 4;
	public const BREATH_TICKS = 60;
	public const STRAFE_RANGE = 64.0;
	public const CHARGE_RANGE = 150.0;
	public const PORTAL_PLAYER_RANGE = 20.0;
	/** Distance from the dragon's centre to its head, in the direction it flies. */
	public const HEAD_OFFSET = 6.0;
	public const HEAD_HIT_RADIUS = 4.0;
	public const FIRST_KILL_XP = 12000;
	public const XP = 500;
	/** Damage modifier type used for the reduction of hits away from the head. */
	public const MODIFIER_BODY_HIT = 1001;

	public const PHASE_HOLDING = 0;
	public const PHASE_STRAFING = 1;
	public const PHASE_CHARGING = 2;
	public const PHASE_LANDING = 3;
	public const PHASE_PERCHED = 4;
	public const PHASE_TAKEOFF = 5;

	private const HOLD_SPEED = 0.6;
	private const CHARGE_SPEED = 0.9;

	private const TAG_HOME = "DragonHome"; //TAG_List of 3 TAG_Double
	private const TAG_PHASE = "DragonPhase"; //TAG_Int
	private const TAG_FIGHT_DRAGON = "DragonFightDragon"; //TAG_Byte

	public static function getNetworkTypeId() : string{ return EntityIds::ENDER_DRAGON; }

	private BossBar $bossBar;
	private int $phase = self::PHASE_HOLDING;
	private int $phaseTicks = 0;
	private Vector3 $home;
	private ?Vector3 $waypoint = null;
	private float $circleAngle = 0.0;
	private int $circleDirection = 1;
	private ?Vector3 $chargeTarget = null;
	private ?int $strafeTargetId = null;
	private ?EndCrystal $healingCrystal = null;
	private int $crystalsAlive = 0;
	private float $damageWhilePerched = 0.0;
	private int $breaths = 0;
	private int $flameTicks = 0;
	private int $deathXp = 0;
	private bool $forceHeadHit = false;
	/**
	 * Whether this is the dragon of the End fight (spawned by it), whose death opens the exit portal and drops the egg;
	 * a summoned dragon isn't (BDS). Null for a dragon saved before this was recorded: it is the fight's if one is on.
	 */
	private ?bool $fightDragon = false;

	/**
	 * @var bool[] block state ID => whether the dragon destroys blocks in that state
	 * @phpstan-var array<int, bool>
	 */
	private static array $destroyableStates = [];

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(4.0, 13.0, 2.0);
	}

	protected function getInitialGravity() : float{ return 0.0; }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	public function getName() : string{
		return "Ender Dragon";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(self::MAX_HEALTH);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->setHasGravity(false);
		$this->keepMovement = true; //flies through blocks
		$this->maxDeadTicks = self::DEATH_TICKS;
		$this->knockbackResistanceAttr->setValue(1.0);
		$homeTag = $nbt->getListTag(self::TAG_HOME, DoubleTag::class);
		$home = [];
		if($homeTag !== null){
			foreach($homeTag as $tag){
				$home[] = $tag->getValue();
			}
		}
		$this->home = count($home) === 3 ? new Vector3($home[0], $home[1], $home[2]) : $this->location->asVector3();
		$this->circleAngle = mt_rand(0, 359) / 180 * M_PI;
		$this->circleDirection = mt_rand(0, 1) === 0 ? 1 : -1;
		$phase = $nbt->getInt(self::TAG_PHASE, self::PHASE_HOLDING);
		//perching and dying are not resumed after a reload
		$this->phase = $phase === self::PHASE_STRAFING || $phase === self::PHASE_CHARGING ? self::PHASE_HOLDING : ($phase === self::PHASE_PERCHED || $phase === self::PHASE_LANDING ? self::PHASE_TAKEOFF : self::PHASE_HOLDING);
		$fightTag = $nbt->getTag(self::TAG_FIGHT_DRAGON);
		$this->fightDragon = $fightTag instanceof ByteTag ? $fightTag->getValue() !== 0 : ($nbt->getTag(self::TAG_PHASE) !== null ? null : false);
		$this->bossBar = new BossBar($this, self::HUD_RANGE, $this->getDisplayName());
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_HOME, new ListTag([
			new DoubleTag($this->home->x),
			new DoubleTag($this->home->y),
			new DoubleTag($this->home->z)
		]));
		$nbt->setInt(self::TAG_PHASE, $this->phase);
		if($this->fightDragon !== null){
			$nbt->setByte(self::TAG_FIGHT_DRAGON, $this->fightDragon ? 1 : 0);
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		//the dragon's AI is in tickAi()
	}

	public function getBossBar() : BossBar{ return $this->bossBar; }

	/**
	 * Returns whether this is the dragon of the End fight: its death opens the exit portal, drops the egg and spawns a
	 * gateway. Dragons summoned by commands or plugins aren't, like on BDS.
	 */
	public function isFightDragon() : bool{
		if($this->fightDragon !== null){
			return $this->fightDragon;
		}
		$fight = DragonFight::getInstance();
		return $this->isInEnd() && $fight->isStarted($this->getWorld()) && !$fight->isDragonKilled($this->getWorld());
	}

	public function setFightDragon(bool $fightDragon) : void{
		$this->fightDragon = $fightDragon;
	}

	public function getPhase() : int{ return $this->phase; }

	public function getHome() : Vector3{ return $this->home; }

	public function getCrystalsAlive() : int{ return $this->crystalsAlive; }

	public function getHealingCrystal() : ?EndCrystal{ return $this->healingCrystal; }

	public function isFireProof() : bool{ return true; }

	public function canBreathe() : bool{ return true; }

	public function canDespawn() : bool{ return false; }

	public function canBeRenamed() : bool{ return false; }

	/**
	 * Whether the dragon fights in an End world (the fight is centred on the exit portal at 0,0 and it can perch).
	 */
	public function isInEnd() : bool{
		return $this->getWorld()->getDimension() === Dimension::END;
	}

	/**
	 * Centre of the fight: the exit portal's origin in the End, otherwise where the dragon was spawned.
	 */
	public function getFightCenter() : Vector3{
		return $this->isInEnd() ? DragonFight::getInstance()->getExitPortalOrigin($this->getWorld()) : $this->home;
	}

	/**
	 * Where the dragon perches (on top of the exit portal pillar), or null outside the End.
	 */
	public function getPerchPosition() : ?Vector3{
		return $this->isInEnd() ? EndExitPortal::getPillarTop(DragonFight::getInstance()->getExitPortalOrigin($this->getWorld()))->add(0.5, 0, 0.5) : null;
	}

	public function setPhase(int $phase) : void{
		$this->phase = $phase;
		$this->phaseTicks = 0;
		$this->waypoint = null;
		if($phase !== self::PHASE_PERCHED){
			$this->flameTicks = 0;
		}
		if($phase === self::PHASE_TAKEOFF || $phase === self::PHASE_HOLDING){
			$this->damageWhilePerched = 0.0;
		}
	}

	/**
	 * Direction the dragon faces (its head), from the yaw. The dragon's model is turned around: its yaw is 180 degrees
	 * off its heading, like in Java (and PowerNukkitX).
	 */
	public function getHeadingVector() : Vector3{
		$heading = deg2rad($this->location->yaw - 180);
		return new Vector3(-sin($heading), 0, cos($heading));
	}

	public function getHeadPosition() : Vector3{
		return $this->location->add(0, 1.5, 0)->addVector($this->getHeadingVector()->multiply(self::HEAD_OFFSET));
	}

	/**
	 * Turns the dragon to face the given direction (its yaw is 180 degrees off, see {@link getHeadingVector()}).
	 */
	private function face(float $dx, float $dy, float $dz) : void{
		if(abs($dx) < 0.0001 && abs($dz) < 0.0001){
			return;
		}
		$heading = atan2($dz, $dx) / M_PI * 180 - 90;
		$yaw = $heading + 180;
		if($yaw >= 360){
			$yaw -= 360;
		}
		$pitch = -atan2($dy, sqrt($dx * $dx + $dz * $dz)) / M_PI * 180;
		$this->setHeadRotation($yaw, $yaw, max(-30, min(30, $pitch)));
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Damage
	// ---------------------------------------------------------------------------------------------------------------

	public function attack(EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		$explosion = $cause === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION || $cause === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION;
		switch($cause){
			case EntityDamageEvent::CAUSE_FIRE:
			case EntityDamageEvent::CAUSE_FIRE_TICK:
			case EntityDamageEvent::CAUSE_LAVA:
			case EntityDamageEvent::CAUSE_FALL:
			case EntityDamageEvent::CAUSE_DROWNING:
			case EntityDamageEvent::CAUSE_SUFFOCATION:
			case EntityDamageEvent::CAUSE_MAGIC:
			case EntityDamageEvent::CAUSE_VOID:
			case EntityDamageEvent::CAUSE_CONTACT:
			case EntityDamageEvent::CAUSE_FLY_INTO_WALL:
			case EntityDamageEvent::CAUSE_MAGMA:
			case EntityDamageEvent::CAUSE_CAMPFIRE:
			case EntityDamageEvent::CAUSE_SWEET_BERRY_BUSH:
			case EntityDamageEvent::CAUSE_STALAGMITE:
			case EntityDamageEvent::CAUSE_WITHER:
			case EntityDamageEvent::CAUSE_THORNS:
			case EntityDamageEvent::CAUSE_SONIC_BOOM:
			case EntityDamageEvent::CAUSE_DRAGON_BREATH:
				$source->cancel();
		}
		$hitPoint = null;
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			$child = $source instanceof EntityDamageByChildEntityEvent ? $source->getChild() : null;
			if(!$damager instanceof Player && !$explosion){
				$source->cancel(); //only players and explosions hurt the dragon
			}
			if($this->phase === self::PHASE_PERCHED && $child instanceof Projectile){
				$source->cancel(); //perched, it deflects projectiles
			}
			$hitPoint = $child !== null ? $child->getPosition() : ($damager?->getEyePos());
		}elseif($source instanceof EntityDamageByBlockEvent){
			//bed and respawn anchor explosions: the exploding block is where it was hit (a bed at the head does full damage)
			$hitPoint = $source->getDamager()->getPosition()->add(0.5, 0.5, 0.5);
		}
		if(!$source->isCancelled() && !$this->forceHeadHit && $cause !== EntityDamageEvent::CAUSE_SUICIDE && ($hitPoint === null || $hitPoint->distance($this->getHeadPosition()) > self::HEAD_HIT_RADIUS)){
			$damage = $source->getBaseDamage();
			$reduced = $damage / 4 + min($damage, 1.0);
			if($reduced < $damage){
				$source->setModifier($reduced - $damage, self::MODIFIER_BODY_HIT);
			}
		}
		$healthBefore = $this->getHealth();
		parent::attack($source);
		if($source->isCancelled() || !$this->isAlive()){
			return;
		}
		if($this->phase === self::PHASE_PERCHED){
			$this->damageWhilePerched += $healthBefore - $this->getHealth();
			if($this->damageWhilePerched > self::PERCH_ESCAPE_DAMAGE){
				$this->setPhase(self::PHASE_TAKEOFF);
			}
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// AI
	// ---------------------------------------------------------------------------------------------------------------

	protected function updateActivation() : bool{
		if(!AiBudget::requiresNearbyPlayer()){
			return true;
		}
		$range = self::ACTIVE_RANGE * self::ACTIVE_RANGE;
		foreach($this->getWorld()->getPlayers() as $player){
			if($player->getLocation()->distanceSquared($this->location) <= $range){
				return true;
			}
		}
		return false;
	}

	public function hasMovementUpdate() : bool{
		return $this->isAlive() || parent::hasMovementUpdate();
	}

	protected function pushesEntities() : bool{
		//no pushable component, and its wings knock entities back instead (see the AI)
		return false;
	}

	protected function tryChangeMovement() : void{
		//flight: the motion is set by the AI
	}

	protected function checkBlockIntersections() : void{
		//the dragon doesn't use portals, press plates... (and its box is huge)
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->closed && $this->isAlive()){
			$this->bossBar->tick();
		}
		return $hasUpdate;
	}

	protected function tickAi(int $currentTick) : void{
		++$this->phaseTicks;
		$phase = ($currentTick + $this->id) % 10;
		if($phase === 0){
			$this->sensing->reset();
			$this->updateCrystals();
		}
		switch($this->phase){
			case self::PHASE_HOLDING:
				$this->tickHolding();
				break;
			case self::PHASE_STRAFING:
				$this->tickStrafing();
				break;
			case self::PHASE_CHARGING:
				$this->tickCharging();
				break;
			case self::PHASE_LANDING:
				$this->tickLanding();
				break;
			case self::PHASE_PERCHED:
				$this->tickPerched();
				break;
			case self::PHASE_TAKEOFF:
				$this->tickTakeoff();
				break;
		}
		if($phase === 5 && $this->phase !== self::PHASE_PERCHED){
			$this->destroyBlocks();
		}
		if($currentTick % 4 === 0){
			$this->hitEntities();
		}
		if($this->phase !== self::PHASE_PERCHED && ($currentTick + $this->id) % 20 === 0){
			$this->playMobSound(LevelSoundEvent::FLAP);
		}
	}

	/**
	 * Steers towards the position at the given speed, turning progressively.
	 */
	private function flyTo(Vector3 $target, float $speed) : float{
		$diff = $target->subtractVector($this->location);
		$dist = $diff->length();
		if($dist < 0.001){
			$this->motion = Vector3::zero();
			return 0.0;
		}
		$speed = min($speed, max(0.15, $dist * 0.3));
		$desired = $diff->multiply($speed / $dist);
		$this->motion = $this->motion->addVector($desired->subtractVector($this->motion)->multiply(0.15));
		$this->face($this->motion->x, $this->motion->y, $this->motion->z);
		return $dist;
	}

	private function nextWaypoint() : void{
		$center = $this->getFightCenter();
		$inEnd = $this->isInEnd();
		$this->circleAngle += $this->circleDirection * mt_rand(20, 40) / 180 * M_PI;
		$radius = $inEnd ? mt_rand(40, 60) : mt_rand(20, 36);
		$this->waypoint = new Vector3(
			$center->x + cos($this->circleAngle) * $radius,
			$center->y + ($inEnd ? mt_rand(15, 35) : mt_rand(10, 25)),
			$center->z + sin($this->circleAngle) * $radius
		);
	}

	private function nearestTargetablePlayer(Vector3 $from, float $range) : ?Player{
		$best = null;
		$bestDist = $range * $range;
		foreach($this->getWorld()->getPlayers() as $player){
			if(!Sensing::isTargetablePlayer($player)){
				continue;
			}
			$d = $player->getPosition()->distanceSquared($from);
			if($d <= $bestDist){
				$best = $player;
				$bestDist = $d;
			}
		}
		return $best;
	}

	private function tickHolding() : void{
		if($this->waypoint === null){
			$this->nextWaypoint();
		}
		$waypoint = $this->waypoint;
		if($waypoint === null){
			return;
		}
		if($this->flyTo($waypoint, self::HOLD_SPEED) > 10){
			return;
		}
		//waypoint reached: decide what to do next
		$this->nextWaypoint();
		if($this->getPerchPosition() !== null && mt_rand(0, 2 + $this->crystalsAlive) === 0){
			$this->setPhase(self::PHASE_LANDING);
			return;
		}
		$player = $this->nearestTargetablePlayer($this->location, self::STRAFE_RANGE);
		if($player !== null && mt_rand(0, 3) === 0){
			$this->startStrafing($player);
		}elseif(!$this->isInEnd() && $player !== null && mt_rand(0, 5) === 0){
			$this->startCharging($player->getPosition());
		}
	}

	public function startStrafing(Living $target) : void{
		$this->setPhase(self::PHASE_STRAFING);
		$this->strafeTargetId = $target->getId();
	}

	public function startCharging(Vector3 $target) : void{
		$this->setPhase(self::PHASE_CHARGING);
		$this->chargeTarget = $target->asVector3();
	}

	private function tickStrafing() : void{
		$target = $this->strafeTargetId !== null ? $this->getWorld()->getEntity($this->strafeTargetId) : null;
		if(!$target instanceof Living || !$target->isAlive() || ($target instanceof Player && !Sensing::isTargetablePlayer($target)) || $this->phaseTicks > 200){
			$this->setPhase(self::PHASE_HOLDING);
			return;
		}
		$aim = $target->getPosition()->add(0, 10, 0);
		$dist = $this->flyTo($aim, self::HOLD_SPEED);
		if($this->phaseTicks >= 20 && ($dist < 48 || $this->phaseTicks > 120) && $this->sensing->canSee($target)){
			$this->shootFireball($target->getEyePos());
			$this->setPhase(self::PHASE_HOLDING);
		}
	}

	private function tickCharging() : void{
		$target = $this->chargeTarget;
		if($target === null || $this->phaseTicks > 100){
			$this->setPhase(self::PHASE_HOLDING);
			return;
		}
		if($this->flyTo($target, self::CHARGE_SPEED) < 4){
			$this->setPhase(self::PHASE_HOLDING);
		}
	}

	private function tickLanding() : void{
		$perch = $this->getPerchPosition();
		if($perch === null || $this->phaseTicks > 600){
			$this->setPhase(self::PHASE_HOLDING);
			return;
		}
		$dx = $perch->x - $this->location->x;
		$dz = $perch->z - $this->location->z;
		if($dx * $dx + $dz * $dz > 36){
			$this->flyTo($perch->add(0, 10, 0), self::HOLD_SPEED);
			return;
		}
		if($this->flyTo($perch, 0.3) < 0.6){
			$this->setPhase(self::PHASE_PERCHED);
			$this->motion = Vector3::zero();
			$this->setPosition($perch);
			$this->breaths = 0;
		}
	}

	private function tickPerched() : void{
		$this->motion = Vector3::zero();
		$perch = $this->getPerchPosition();
		if($perch === null){
			$this->setPhase(self::PHASE_TAKEOFF);
			return;
		}
		if($this->flameTicks > 0){
			if(--$this->flameTicks % 5 === 0){
				$this->broadcastAnimation(new ActorEventAnimation($this, ActorEvent::DRAGON_PUKE));
			}
			if($this->flameTicks === 0){
				++$this->breaths;
				$this->phaseTicks = 0;
			}
			return;
		}
		if($this->breaths >= self::MAX_BREATHS){
			$this->setPhase(self::PHASE_TAKEOFF);
			return;
		}
		$player = $this->nearestTargetablePlayer($perch, self::PORTAL_PLAYER_RANGE);
		if($player !== null){
			$p = $player->getPosition();
			$this->face($p->x - $this->location->x, 0, $p->z - $this->location->z);
			if($this->phaseTicks >= 25){
				$this->breathe();
			}
			return;
		}
		if($this->phaseTicks >= 100){
			$far = $this->nearestTargetablePlayer($this->location, self::CHARGE_RANGE);
			if($far !== null){
				$this->startCharging($far->getPosition());
			}else{
				$this->setPhase(self::PHASE_TAKEOFF);
			}
		}
	}

	/**
	 * Breathes dragon's breath in front of its head for 3 seconds (perched).
	 */
	public function breathe() : void{
		$this->flameTicks = self::BREATH_TICKS;
		$this->playMobSound(LevelSoundEvent::AMBIENT);
		$head = $this->getHeadPosition();
		$world = $this->getWorld();
		$ground = $world->getHighestBlockAt((int) floor($head->x), (int) floor($head->z));
		$y = $ground !== null && $ground < $head->y ? $ground + 1 : $head->y - 3;
		$cloud = new DragonBreathCloud(Location::fromObject(new Vector3($head->x, $y, $head->z), $world), null, 5.0, 200);
		$cloud->setOwningEntity($this);
		$cloud->spawnToAll();
		$this->broadcastAnimation(new ActorEventAnimation($this, ActorEvent::DRAGON_PUKE));
	}

	private function tickTakeoff() : void{
		$perch = $this->getPerchPosition() ?? $this->home;
		$target = $perch->add(0, 25, 0)->addVector($this->getHeadingVector()->multiply(20));
		if($this->location->y >= $perch->y + 20 || $this->phaseTicks > 200){
			$this->setPhase(self::PHASE_HOLDING);
			return;
		}
		$this->flyTo($target, 0.4);
	}

	/**
	 * Fires a dragon fireball from the head at the position.
	 */
	public function shootFireball(Vector3 $at) : ?DragonFireball{
		$from = $this->getHeadPosition();
		$diff = $at->subtractVector($from);
		$len = $diff->length();
		if($len < 0.0001){
			return null;
		}
		$dir = $diff->divide($len);
		$world = $this->getWorld();
		$fireball = new DragonFireball(Location::fromObject($from, $world), $this);
		$fireball->setMotion($dir->multiply(DragonFireball::SPEED));
		$ev = new ProjectileLaunchEvent($fireball);
		$ev->call();
		if($ev->isCancelled()){
			$fireball->flagForDespawn();
			return null;
		}
		$fireball->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
		$world->addGameEvent($from, GameEvent::PROJECTILE_SHOOT, $this);
		return $fireball;
	}

	/**
	 * Every half second: finds the End crystal healing the dragon, heals it, and reacts to the destruction of the
	 * crystal it was healing from.
	 */
	private function updateCrystals() : void{
		$healing = $this->healingCrystal;
		if($healing !== null && ($healing->isClosed() || !$healing->isAlive() || $healing->isFlaggedForDespawn())){
			$this->healingCrystal = null;
			$this->onHealingCrystalDestroyed($healing);
		}
		$best = null;
		$bestDist = self::HEAL_RANGE ** 2;
		$alive = 0;
		foreach($this->getWorld()->getEntities() as $entity){
			if(!$entity instanceof EndCrystal || $entity->isClosed() || !$entity->isAlive() || $entity->isFlaggedForDespawn()){
				continue;
			}
			++$alive;
			$d = $entity->getPosition()->distanceSquared($this->location);
			if($d < $bestDist){
				$best = $entity;
				$bestDist = $d;
			}
		}
		$this->crystalsAlive = $alive;
		$this->healingCrystal = $best;
		if($best !== null && $this->getHealth() < $this->getMaxHealth()){
			$this->heal(new EntityRegainHealthEvent($this, 1.0, EntityRegainHealthEvent::CAUSE_MAGIC));
		}
	}

	private function onHealingCrystalDestroyed(EndCrystal $crystal) : void{
		$cause = $crystal->getLastDamageCause();
		$damager = $cause instanceof EntityDamageByEntityEvent ? $cause->getDamager() : null;
		if($damager instanceof Player){
			$ev = new EntityDamageByEntityEvent($damager, $this, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, self::CRYSTAL_DESTROYED_DAMAGE, [], 0.0);
		}else{
			$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, self::CRYSTAL_DESTROYED_DAMAGE);
		}
		//vanilla: the crystal's explosion hurts the head (no body reduction)
		$this->forceHeadHit = true;
		try{
			$this->attack($ev);
		}finally{
			$this->forceHeadHit = false;
		}
		if($this->isAlive() && $this->phase === self::PHASE_HOLDING){
			$target = $damager instanceof Player ? $damager : $this->nearestTargetablePlayer($this->location, self::STRAFE_RANGE);
			if($target !== null){
				$this->startStrafing($target);
			}
		}
	}

	/**
	 * Head and wing attacks against the mobs and players the dragon flies into.
	 */
	private function hitEntities() : void{
		$head = $this->getHeadPosition();
		$world = $this->getWorld();
		$box = $this->boundingBox->expandedCopy(1, 1, 1);
		foreach($world->getCollidingEntities($box, $this) as $entity){
			if(!$entity instanceof Living || $entity instanceof EnderDragon || !$entity->isAlive() || ($entity instanceof Player && !Sensing::isTargetablePlayer($entity))){
				continue;
			}
			$pos = $entity->getPosition();
			if($pos->distanceSquared($head) <= 9){
				$this->attackMelee($entity, self::HEAD_DAMAGE);
				continue;
			}
			$dx = $pos->x - $this->location->x;
			$dz = $pos->z - $this->location->z;
			$distSq = max($dx * $dx + $dz * $dz, 0.1);
			$push = min(4.0 / $distSq, 2.0);
			$motion = $entity->getMotion();
			if($entity->setMotion(new Vector3($motion->x + $dx * $push / sqrt($distSq), 0.2, $motion->z + $dz * $push / sqrt($distSq))) && $entity instanceof Mob){
				$entity->carryPush(); //the wings push it, with or without a hit
			}
			if($this->phase !== self::PHASE_PERCHED){
				$this->attackMelee($entity, self::WING_DAMAGE);
			}
		}
	}

	/**
	 * Destroys the blocks around the dragon's body (vanilla: no drops), except dragon-immune and unbreakable blocks.
	 */
	private function destroyBlocks() : void{
		$world = $this->getWorld();
		if(!GameRules::allowsMobGriefing($world)){
			return; //vanilla: no block destruction with mobGriefing off
		}
		$c = $this->location;
		$air = VanillaBlocks::AIR();
		$broke = null;
		$minY = max($world->getMinY(), (int) floor($c->y));
		$maxY = min($world->getMaxY() - 1, (int) floor($c->y + 3));
		for($x = (int) floor($c->x - 3); $x <= (int) floor($c->x + 3); ++$x){
			for($z = (int) floor($c->z - 3); $z <= (int) floor($c->z + 3); ++$z){
				$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
				if($chunk === null){
					continue;
				}
				for($y = $minY; $y <= $maxY; ++$y){
					//state IDs are checked first: scanning the air the dragon flies through must be cheap
					if(!self::isDestroyable($chunk->getBlockStateId($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK))){
						continue;
					}
					$broke = $world->getBlockAt($x, $y, $z, addToCache: false);
					$world->setBlockAt($x, $y, $z, $air);
				}
			}
		}
		if($broke !== null){
			$world->addParticle($broke->getPosition()->add(0.5, 0.5, 0.5), new BlockBreakParticle($broke));
		}
	}

	private static function isDestroyable(int $stateId) : bool{
		if(!isset(self::$destroyableStates[$stateId])){
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
			self::$destroyableStates[$stateId] = !self::isImmuneBlock($block->getTypeId()) && !$block instanceof Liquid && $block->getBreakInfo()->isBreakable();
		}
		return self::$destroyableStates[$stateId];
	}

	/**
	 * Blocks the dragon can't destroy (vanilla dragon_immune tag), besides unbreakable blocks.
	 */
	public static function isImmuneBlock(int $typeId) : bool{
		return match($typeId){
			BlockTypeIds::AIR, BlockTypeIds::END_STONE, BlockTypeIds::OBSIDIAN, BlockTypeIds::CRYING_OBSIDIAN, BlockTypeIds::IRON_BARS,
			BlockTypeIds::RESPAWN_ANCHOR, BlockTypeIds::BEDROCK, BlockTypeIds::END_PORTAL, BlockTypeIds::END_PORTAL_FRAME => true,
			default => false
		};
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Death
	// ---------------------------------------------------------------------------------------------------------------

	public function getXpDropAmount() : int{
		if(!$this->isInEnd()){
			return self::XP;
		}
		return DragonFight::getInstance()->getKills($this->getWorld()) === 0 ? self::FIRST_KILL_XP : self::XP;
	}

	public function getDrops() : array{
		return [];
	}

	protected function onDeath() : void{
		$ev = new EntityDeathEvent($this, $this->getDrops(), $this->getXpDropAmount());
		$ev->call();
		foreach($ev->getDrops() as $item){
			$this->getWorld()->dropItem($this->location, $item);
		}
		$this->deathXp = $ev->getXpDropAmount();
		//BDS keeps the bar at 0% during the death animation: it goes away when the dragon is removed (onDispose())
		$this->bossBar->tick();
		$this->playMobSound(LevelSoundEvent::DEATH);
		$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_DIE, $this);
		$this->applyEffectsOnDeath();
		//BDS: the death event (3) in the tick of the death, the dragon death beams (37) on the next one
		$this->broadcastAnimation(new DeathAnimation($this));
		$this->motion = Vector3::zero();
		$this->deadTicks = 0;
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		for($i = 0; $i < $tickDiff && $this->deadTicks < self::DEATH_TICKS; ++$i){
			++$this->deadTicks;
			$this->tickDeath();
		}
		return $this->deadTicks >= self::DEATH_TICKS;
	}

	private function tickDeath() : void{
		$t = $this->deadTicks;
		if($t === 1){
			$this->broadcastAnimation(new ActorEventAnimation($this, ActorEvent::ENDER_DRAGON_DEATH));
		}
		//BDS 1.26.60: the dying dragon stays where it died (it neither flies to the portal nor rises), so its experience
		//falls there
		$world = $this->getWorld();
		if($t > 150 && $t % 5 === 0){
			$world->dropExperience($this->location, (int) floor($this->deathXp * 0.08));
		}
		if($t === self::DEATH_TICKS){
			$world->dropExperience($this->location, (int) floor($this->deathXp * 0.2));
			if($this->isInEnd() && $this->isFightDragon()){
				DragonFight::getInstance()->onDragonKilled($world);
			}
			$this->despawnFromAll();
		}
	}

	protected function onDispose() : void{
		if(isset($this->bossBar)){
			$this->bossBar->hideAll();
		}
		$this->healingCrystal = null;
		parent::onDispose();
	}
}
