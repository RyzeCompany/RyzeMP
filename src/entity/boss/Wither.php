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

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\animation\DeathAnimation;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Ghast;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Undead;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\DangerousWitherSkull;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\Trident;
use pocketmine\entity\projectile\WitherSkull;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
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
 * Bedrock values (Mojang bedrock-samples behavior_pack/entities/wither.json and the Minecraft Wiki): health 300/450/600
 * (easy/normal/hard), 1x3 hitbox, flies, fire immune, takes no damage from undead mobs, boss bar within 55 blocks,
 * drops a Nether star (which never despawns) and always 50 XP.
 *
 * Summoned (or spawned from an egg) it is invulnerable for 11 seconds while its health fills up, then explodes
 * (power 7). It hovers about 5 blocks above its target (whoever dealt it the most damage, else players, then any
 * living mob that isn't undead or a ghast within 70 blocks) and fires volleys of three black skulls and a blue one
 * from its heads, moving to a new spot between volleys. It regenerates 1 health per second and 5 per skull kill.
 * Below half health it explodes once, gets its armour (immune to arrows and tridents), stops hovering above its
 * target and dashes at it after every second volley, breaking blocks (not obsidian). When hurt it breaks the blocks
 * around it a second later. Its block breaking follows mobGriefing. Dying, it explodes again (power 7) before
 * dropping its loot.
 */
class Wither extends Monster implements Undead{
	public const SPAWN_INVULNERABLE_TICKS = 220;
	public const EXPLOSION_POWER = 7.0;
	public const HUD_RANGE = 55.0;
	public const TARGET_RANGE = 70.0;
	public const HOVER_HEIGHT = 5.0;
	public const SHOT_INTERVAL = 10;
	public const VOLLEY_PAUSE = 40;
	public const DASH_TICKS = 30;
	/** Base dash damage (8.5/15/22.5 by difficulty). */
	public const DASH_DAMAGE = 15.0;
	public const DEATH_TICKS = 40;
	private const BREAK_DELAY = 20;
	private const FLY_SPEED = 0.35;
	/** Health regained every {@link self::REGEN_INTERVAL} ticks once spawned (Java WitherBoss.customServerAiStep). */
	public const REGEN_HEALTH = 1.0;
	public const REGEN_INTERVAL = 20;

	private const TAG_INVULNERABLE = "Invul"; //TAG_Int
	private const TAG_ARMORED = "Armored"; //TAG_Byte

	public static function getNetworkTypeId() : string{ return EntityIds::WITHER; }

	private BossBar $bossBar;
	private int $invulnerableTicks = 0;
	private bool $armored = false;
	private int $shotCooldown = self::SHOT_INTERVAL;
	private int $shotIndex = 0;
	private int $volleys = 0;
	private int $breakBlocksIn = 0;
	private int $dashTicks = 0;
	private bool $dashHit = false;
	private ?Vector3 $wanted = null;
	/** ID of the target the wanted position was picked around, null while wandering. */
	private ?int $wantedForTarget = null;
	private int $wanderTicks = 0;
	private Vector3 $home;
	/**
	 * Damage dealt to the wither by each attacker, for behavior.wither_target_highest_damage.
	 * @var float[]
	 * @phpstan-var array<int, float>
	 */
	private array $damageByAttacker = [];

	/**
	 * Loot and experience of the death, dropped when the death animation ends (after the death explosion).
	 * @var Item[]
	 */
	private array $deathDrops = [];
	private int $deathXp = 0;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(3.0, 1.0, 2.6);
	}

	protected function getInitialGravity() : float{ return 0.0; }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	public function canFreeze() : bool{
		return false; //vanilla freeze_immune_entity_types
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		return $effect === VanillaEffects::WITHER() || parent::isImmuneToEffect($effect); //vanilla wither_immune_to
	}

	public function getName() : string{
		return "Wither";
	}

	protected function isImmuneToBlockSlowdown(Block $block) : bool{
		return true; //vanilla: cobwebs and bushes don't slow the wither down
	}

	public static function maxHealthForDifficulty(int $difficulty) : int{
		return match($difficulty){
			World::DIFFICULTY_PEACEFUL, World::DIFFICULTY_EASY => 300,
			World::DIFFICULTY_HARD => 600,
			default => 450
		};
	}

	/**
	 * Returns whether the entity is undead (wither, zombie, skeleton or any mob implementing {@link Undead}).
	 */
	public static function isUndead(?Entity $entity) : bool{
		return $entity instanceof Undead || $entity instanceof Zombie || $entity instanceof Skeleton;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(self::maxHealthForDifficulty($this->location->getWorld()->getDifficulty()));
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$this->setHasGravity(false);
		$this->setCanClimb(false);
		$this->maxDeadTicks = self::DEATH_TICKS;
		$this->knockbackResistanceAttr->setValue(1.0);
		$this->home = $this->location->asVector3();
		$this->invulnerableTicks = $nbt->getInt(self::TAG_INVULNERABLE, self::SPAWN_INVULNERABLE_TICKS);
		$this->armored = $nbt->getByte(self::TAG_ARMORED, 0) !== 0;
		if($this->invulnerableTicks > 0){
			$this->updateSpawnHealth();
		}
		$this->bossBar = new BossBar($this, self::HUD_RANGE, $this->getDisplayName());
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_INVULNERABLE, $this->invulnerableTicks);
		$nbt->setByte(self::TAG_ARMORED, $this->armored ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		//the wither's AI is in tickAi()
	}

	public function getBossBar() : BossBar{
		return $this->bossBar;
	}

	public function getInvulnerableTicks() : int{
		return $this->invulnerableTicks;
	}

	public function isArmored() : bool{
		return $this->armored;
	}

	public function isDashing() : bool{
		return $this->dashTicks > 0;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function canDespawn() : bool{
		return false;
	}

	public function setNameTag(string $name) : void{
		parent::setNameTag($name);
		if(isset($this->bossBar)){
			$this->bossBar->setTitle($this->getDisplayName());
		}
	}

	private function updateSpawnHealth() : void{
		$max = $this->getMaxHealth();
		$this->setHealth(max(1.0, $max * (1 - $this->invulnerableTicks / self::SPAWN_INVULNERABLE_TICKS)));
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Damage
	// ---------------------------------------------------------------------------------------------------------------

	public function attack(EntityDamageEvent $source) : void{
		$cause = $source->getCause();
		if($this->invulnerableTicks > 0 && $cause !== EntityDamageEvent::CAUSE_VOID && $cause !== EntityDamageEvent::CAUSE_SUICIDE){
			$source->cancel();
		}
		$damager = null;
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Projectile){
				$damager = $damager->getOwningEntity();
			}
			if($damager === $this || self::isUndead($damager)){
				$source->cancel(); //Bedrock damage_sensor: undead deal no damage (also our own skulls and explosions)
			}
			if($this->armored && $source instanceof EntityDamageByChildEntityEvent){
				$child = $source->getChild();
				if($child instanceof Arrow || $child instanceof Trident){
					$source->cancel();
				}
			}
		}
		$healthBefore = $this->getHealth();
		parent::attack($source);
		if($source->isCancelled() || !$this->isAlive()){
			return;
		}
		if($damager instanceof Living && $damager !== $this){
			$id = $damager->getId();
			$this->damageByAttacker[$id] = ($this->damageByAttacker[$id] ?? 0.0) + $source->getFinalDamage();
		}
		$this->breakBlocksIn = self::BREAK_DELAY;
		$half = $this->getMaxHealth() / 2;
		if(!$this->armored && $healthBefore > $half && $this->getHealth() <= $half){
			$this->armored = true;
			$this->networkPropertiesDirty = true;
			$this->explode();
		}
	}

	public function explode() : void{
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_POWER);
		$ev->call();
		if($ev->isCancelled()){
			return;
		}
		$explosion = new Explosion(Position::fromObject($this->location->add(0, 1.5, 0), $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance());
		if($ev->isBlockBreaking()){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}

	// ---------------------------------------------------------------------------------------------------------------
	// AI
	// ---------------------------------------------------------------------------------------------------------------

	public function hasMovementUpdate() : bool{
		return $this->isAlive() || parent::hasMovementUpdate();
	}

	protected function tryChangeMovement() : void{
		//flight: the motion is set by the AI; only damp it a little
		$this->motion = $this->motion->multiply(0.91);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->closed && $this->isAlive()){
			$this->bossBar->tick();
		}
		return $hasUpdate;
	}

	protected function tickAi(int $currentTick) : void{
		if($this->invulnerableTicks > 0){
			$this->tickSpawning();
			return;
		}
		if(($currentTick + $this->id) % 10 === 0){
			$this->sensing->reset();
			$this->updateTarget(($currentTick + $this->id) % 40 === 0);
			if(($currentTick + $this->id) % self::REGEN_INTERVAL === 0 && $this->getHealth() < $this->getMaxHealth()){
				$this->heal(new EntityRegainHealthEvent($this, self::REGEN_HEALTH, EntityRegainHealthEvent::CAUSE_REGEN));
			}
		}
		if($this->breakBlocksIn > 0 && --$this->breakBlocksIn === 0){
			$this->breakBlocksAround($this->boundingBox->expandedCopy(1, 0, 1)->extend(Facing::UP, 1), false);
		}
		$target = $this->getAttackTarget();
		if($this->dashTicks > 0){
			$this->tickDash($target);
			return;
		}
		if($target !== null){
			$this->tickAttack($target);
		}else{
			$this->tickWander();
		}
		$this->flyTowardsWanted();
	}

	private function tickSpawning() : void{
		--$this->invulnerableTicks;
		$this->updateSpawnHealth();
		$this->motion = Vector3::zero();
		if($this->invulnerableTicks % 5 === 0){
			$this->networkPropertiesDirty = true;
		}
		if($this->invulnerableTicks === 0){
			$this->setHealth($this->getMaxHealth());
			$this->explode();
			$this->playMobSound(LevelSoundEvent::SPAWN);
		}
	}

	public function isValidTarget(?Living $entity) : bool{
		//ghasts can't be attacked (ghast.json cannot_be_attacked)
		if($entity === null || $entity === $this || self::isUndead($entity) || $entity instanceof ArmorStand || $entity instanceof Ghast){
			return false;
		}
		return $this->sensing->isValidTarget($entity, self::TARGET_RANGE);
	}

	/**
	 * Returns the valid target that dealt the wither the most damage (behavior.wither_target_highest_damage), dropping
	 * the attackers that are gone.
	 */
	private function findTopDamager() : ?Living{
		$best = null;
		$bestDamage = 0.0;
		$world = $this->getWorld();
		foreach($this->damageByAttacker as $id => $damage){
			$entity = $world->getEntity($id);
			if(!$entity instanceof Living || !$entity->isAlive()){
				unset($this->damageByAttacker[$id]);
				continue;
			}
			if($damage > $bestDamage && $this->isValidTarget($entity)){
				$best = $entity;
				$bestDamage = $damage;
			}
		}
		return $best;
	}

	private function updateTarget(bool $scanMobs) : void{
		$target = $this->getAttackTarget();
		if($target !== null && !$this->isValidTarget($target)){
			$this->setAttackTarget($target = null);
		}
		if(count($this->damageByAttacker) > 0){
			$top = $this->findTopDamager();
			if($top !== null){
				if($top !== $target){
					$this->setAttackTarget($top);
				}
				return;
			}
		}
		if($target !== null){
			return;
		}
		$attacker = $this->getLastAttacker();
		if($attacker !== null && $this->isValidTarget($attacker)){
			$this->setAttackTarget($attacker);
			return;
		}
		$player = $this->sensing->getNearestPlayer(self::TARGET_RANGE, fn(Player $p) : bool => Sensing::isTargetablePlayer($p) && $this->sensing->canSee($p), true);
		if($player !== null){
			$this->setAttackTarget($player);
			return;
		}
		if($scanMobs){
			$best = null;
			$bestDist = self::TARGET_RANGE ** 2;
			$range = self::TARGET_RANGE;
			foreach($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy($range, $range, $range), $this) as $entity){
				if(!$entity instanceof Living || $entity instanceof Player || !$this->isValidTarget($entity)){
					continue;
				}
				$d = $entity->getLocation()->distanceSquared($this->location);
				if($d < $bestDist && $this->sensing->canSee($entity)){
					$best = $entity;
					$bestDist = $d;
				}
			}
			if($best !== null){
				$this->setAttackTarget($best);
			}
		}
	}

	private function pickAttackPosition(Living $target) : void{
		$angle = mt_rand(0, 359) / 180 * M_PI;
		$radius = mt_rand(4, 10);
		$t = $target->getLocation();
		//Java WitherBoss.aiStep: once armored it only rises while below its target instead of hovering above it
		$y = $this->armored ? max($this->location->y, $t->y) : $t->y + self::HOVER_HEIGHT;
		$this->wanted = new Vector3($t->x + cos($angle) * $radius, $y, $t->z + sin($angle) * $radius);
		$this->wantedForTarget = $target->getId();
	}

	private function tickAttack(Living $target) : void{
		if($this->wanted === null || $this->wantedForTarget !== $target->getId()){
			$this->pickAttackPosition($target);
		}
		$t = $target->getLocation();
		$this->lookAtPosition($t->x, $t->y + $target->getEyeHeight(), $t->z);
		if(--$this->shotCooldown > 0){
			return;
		}
		$dangerous = $this->shotIndex === 3;
		$this->shootSkull($target->getEyePos(), $dangerous, $this->shotIndex % 3);
		if(++$this->shotIndex < 4){
			$this->shotCooldown = self::SHOT_INTERVAL;
			return;
		}
		$this->shotIndex = 0;
		++$this->volleys;
		$this->shotCooldown = self::VOLLEY_PAUSE;
		$this->pickAttackPosition($target);
		if($this->armored && $this->volleys % 2 === 0){
			$this->dashTicks = self::DASH_TICKS;
			$this->dashHit = false;
			$this->networkPropertiesDirty = true;
		}
	}

	private function tickWander() : void{
		if($this->wanted === null || $this->wantedForTarget !== null || --$this->wanderTicks <= 0){
			$this->wantedForTarget = null;
			$this->wanderTicks = mt_rand(80, 160);
			$this->wanted = $this->home->add(mt_rand(-10, 10), mt_rand(2, 6), mt_rand(-10, 10));
		}
		$this->lookAtPosition($this->wanted->x, $this->wanted->y, $this->wanted->z);
	}

	private function flyTowardsWanted() : void{
		$wanted = $this->wanted;
		if($wanted === null){
			return;
		}
		$diff = $wanted->subtractVector($this->location);
		$dist = $diff->length();
		if($dist < 0.5){
			$this->motion = $this->motion->multiply(0.5);
			return;
		}
		$speed = min(self::FLY_SPEED, $dist * 0.1);
		$desired = $diff->multiply($speed / $dist);
		$this->motion = $this->motion->addVector($desired->subtractVector($this->motion)->multiply(0.2));
	}

	private function tickDash(?Living $target) : void{
		--$this->dashTicks;
		if($target === null || $this->dashHit){
			$this->endDash();
			return;
		}
		$diff = $target->getLocation()->add(0, 0.5, 0)->subtractVector($this->location);
		$dist = $diff->length();
		$this->lookAtPosition($target->getLocation()->x, $target->getEyePos()->y, $target->getLocation()->z);
		if($dist < 2.0){
			$this->dashHit = true;
			$this->attackMelee($target, self::DASH_DAMAGE);
			$this->endDash();
			return;
		}
		$this->motion = $diff->multiply(1.0 / $dist);
		$this->breakBlocksAround($this->boundingBox->expandedCopy(1, 0.5, 1)->offset($this->motion->x, $this->motion->y, $this->motion->z), true);
		if($this->dashTicks <= 0){
			$this->endDash();
		}
	}

	private function endDash() : void{
		$this->dashTicks = 0;
		$this->networkPropertiesDirty = true;
		$this->motion = $this->motion->multiply(0.3);
	}

	/**
	 * Breaks the breakable blocks in the box, with drops (vanilla mob griefing). Obsidian survives dashes.
	 */
	private function breakBlocksAround(AxisAlignedBB $box, bool $dash) : void{
		$world = $this->getWorld();
		if(!GameRules::allowsMobGriefing($world)){
			return;
		}
		$broke = false;
		for($x = (int) floor($box->minX); $x <= (int) floor($box->maxX); ++$x){
			for($y = (int) floor($box->minY); $y <= (int) floor($box->maxY); ++$y){
				for($z = (int) floor($box->minZ); $z <= (int) floor($box->maxZ); ++$z){
					if(!$world->isInWorld($x, $y, $z)){
						continue;
					}
					$block = $world->getBlockAt($x, $y, $z);
					$id = $block->getTypeId();
					if($id === BlockTypeIds::AIR || $block instanceof Liquid || !$block->getBreakInfo()->isBreakable()){
						continue;
					}
					if($dash && ($id === BlockTypeIds::OBSIDIAN || $id === BlockTypeIds::CRYING_OBSIDIAN)){
						continue;
					}
					if($world->useBreakOn(new Vector3($x, $y, $z), createParticles: true)){
						$broke = true;
					}
				}
			}
		}
		if($broke){
			$this->playMobSound(LevelSoundEvent::BREAK_BLOCK);
		}
	}

	private function lookAtPosition(float $x, float $y, float $z) : void{
		$dx = $x - $this->location->x;
		$dz = $z - $this->location->z;
		$dy = $y - ($this->location->y + $this->getEyeHeight());
		$yaw = atan2($dz, $dx) / M_PI * 180 - 90;
		$pitch = -atan2($dy, sqrt($dx * $dx + $dz * $dz)) / M_PI * 180;
		$this->setHeadRotation($yaw, $yaw, $pitch);
	}

	/**
	 * Position of one of the three heads: 0 is the middle one, 1 and 2 the side heads.
	 */
	public function getHeadPosition(int $head) : Vector3{
		if($head === 0){
			return $this->location->add(0, 3.0, 0);
		}
		$angle = deg2rad($this->location->yaw + ($head === 1 ? 90 : -90));
		return $this->location->add(-sin($angle) * 1.3, 2.2, cos($angle) * 1.3);
	}

	/**
	 * Fires a skull from the given head at the position.
	 */
	public function shootSkull(Vector3 $at, bool $dangerous, int $head = 0) : ?WitherSkull{
		$from = $this->getHeadPosition($head);
		$diff = $at->subtractVector($from);
		$len = $diff->length();
		if($len < 0.0001){
			return null;
		}
		$dir = $diff->divide($len);
		$yaw = atan2($dir->z, $dir->x) / M_PI * 180 - 90;
		$pitch = -atan2($dir->y, sqrt($dir->x ** 2 + $dir->z ** 2)) / M_PI * 180;
		$location = Location::fromObject($from, $this->getWorld(), $yaw, $pitch);
		$skull = $dangerous ? new DangerousWitherSkull($location, $this) : new WitherSkull($location, $this);
		$skull->setMotion($dir->multiply($dangerous ? DangerousWitherSkull::SPEED : WitherSkull::SPEED));
		$ev = new ProjectileLaunchEvent($skull);
		$ev->call();
		if($ev->isCancelled()){
			$skull->flagForDespawn();
			return null;
		}
		$skull->spawnToAll();
		$this->playMobSound(LevelSoundEvent::SHOOT);
		$this->getWorld()->addGameEvent($from, GameEvent::PROJECTILE_SHOOT, $this);
		return $skull;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Death
	// ---------------------------------------------------------------------------------------------------------------

	public function getDrops() : array{
		return [VanillaItems::NETHER_STAR()];
	}

	public function getXpDropAmount() : int{
		return 50; //Bedrock: always, however it dies
	}

	protected function onDeath() : void{
		$ev = new EntityDeathEvent($this, $this->getDrops(), $this->getXpDropAmount());
		$ev->call();
		$this->deathDrops = $ev->getDrops();
		$this->deathXp = $ev->getXpDropAmount();
		$this->bossBar->hideAll();
		$this->bossBar->setVisible(false);
		$this->playMobSound(LevelSoundEvent::DEATH);
		$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_DIE, $this);
		$this->applyEffectsOnDeath();
		$this->broadcastAnimation(new DeathAnimation($this));
		$this->motion = Vector3::zero();
	}

	protected function endDeathAnimation() : void{
		$this->explode();
		$world = $this->getWorld();
		foreach($this->deathDrops as $item){
			$entity = $world->dropItem($this->location->add(0, 1, 0), $item);
			$entity?->setDespawnDelay(ItemEntity::NEVER_DESPAWN);
		}
		$world->dropExperience($this->location, $this->deathXp);
		$this->deathDrops = [];
		parent::endDeathAnimation();
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Network
	// ---------------------------------------------------------------------------------------------------------------

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$targetId = $this->getTargetEntityId() ?? 0;
		$properties->setInt(EntityMetadataProperties::WITHER_INVULNERABLE_TICKS, $this->invulnerableTicks);
		$properties->setLong(EntityMetadataProperties::WITHER_TARGET_1, $targetId);
		$properties->setLong(EntityMetadataProperties::WITHER_TARGET_2, $targetId);
		$properties->setLong(EntityMetadataProperties::WITHER_TARGET_3, $targetId);
		$properties->setShort(EntityMetadataProperties::WITHER_AERIAL_ATTACK, 0);
		$properties->setGenericFlag(EntityMetadataFlags::POWERED, $this->armored);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_DASH, $this->dashTicks > 0);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_FLY, true);
	}

	protected function onDispose() : void{
		if(isset($this->bossBar)){
			$this->bossBar->hideAll();
		}
		parent::onDispose();
	}
}
