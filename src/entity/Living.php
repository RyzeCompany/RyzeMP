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

namespace pocketmine\entity;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BubbleColumn;
use pocketmine\block\PointedDripstone;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\animation\DeathAnimation;
use pocketmine\entity\animation\HurtAnimation;
use pocketmine\entity\animation\LivingTotemUseAnimation;
use pocketmine\entity\animation\RespawnAnimation;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\effect\DamageAwareEffect;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityFrostWalkerEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\item\Armor;
use pocketmine\item\Durable;
use pocketmine\item\Elytra;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaArmorMaterials;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\timings\Timings;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Binary;
use pocketmine\utils\Limits;
use pocketmine\utils\Utils;
use pocketmine\world\GameRules;
use pocketmine\world\sound\BurpSound;
use pocketmine\world\sound\EntityLandSound;
use pocketmine\world\sound\EntityLongFallSound;
use pocketmine\world\sound\EntityShortFallSound;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\TotemUseSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function abs;
use function array_shift;
use function atan2;
use function ceil;
use function count;
use function floor;
use function ksort;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function round;
use function sqrt;
use const M_PI;
use const SORT_NUMERIC;

abstract class Living extends Entity{
	protected const DEFAULT_BREATH_TICKS = 300;
	/** Ticks in powder snow to freeze completely (vanilla getTicksRequiredToFreeze()). */
	public const FREEZE_TICKS = 140;

	protected int $ticksFrozen = 0;
	private bool $inPowderSnow = false;

	/** Distance walked since the last footstep vibration. */
	private float $stepDistance = 0.0;

	/**
	 * The default knockback multiplier when an entity is hit by another entity.
	 * Larger values knock the entity back with increased velocity.
	 */
	public const DEFAULT_KNOCKBACK_FORCE = 0.4;
	/**
	 * Limit of an entity's vertical knockback velocity when hit by another entity. Without this limit, the entity
	 * may be knocked far up into the air with large knockback forces.
	 */
	public const DEFAULT_KNOCKBACK_VERTICAL_LIMIT = 0.4;

	/** Knockback like PocketMine-MP 5.44.3 (player.knockback: pmmp, the default) or like vanilla (vanilla). */
	private static bool $vanillaKnockback = false;

	/** How long a mob or player that hurt this entity keeps the kill credit (vanilla 100 ticks). */
	private const RECENT_ATTACKER_TICKS = 100;

	private const TAG_LEGACY_HEALTH = "HealF"; //TAG_Float
	private const TAG_HEALTH = "Health"; //TAG_Float
	private const TAG_BREATH_TICKS = "Air"; //TAG_Short
	private const TAG_ACTIVE_EFFECTS = "ActiveEffects"; //TAG_List<TAG_Compound>
	private const TAG_SPAWN_REASON = "VanillaMineSpawnReason"; //TAG_String
	private const TAG_EFFECT_ID = "Id"; //TAG_Byte
	private const TAG_EFFECT_DURATION = "Duration"; //TAG_Int
	private const TAG_EFFECT_AMPLIFIER = "Amplifier"; //TAG_Byte
	private const TAG_EFFECT_SHOW_PARTICLES = "ShowParticles"; //TAG_Byte
	private const TAG_EFFECT_AMBIENT = "Ambient"; //TAG_Byte
	/**
	 * Bedrock "Armor" list of every mob: helmet, chestplate, leggings, boots; empty slots are saved like BDS does (no
	 * name, count 0). Humans keep their armor in their "Inventory" list instead (slots 100-103).
	 */
	private const TAG_ARMOR_SLOTS = "Armor"; //TAG_List<TAG_Compound>
	private const ARMOR_SLOT_ORDER = [ArmorInventory::SLOT_HEAD, ArmorInventory::SLOT_CHEST, ArmorInventory::SLOT_LEGS, ArmorInventory::SLOT_FEET];

	/** Why this mob came into the world: one of the {@link SpawnReason} constants, or a plugin's own reason. */
	private string $spawnReason = SpawnReason::DEFAULT;

	protected int $attackTime = 0;
	/**
	 * The hit that started or last raised the invulnerability window, and its full damage before any reduction
	 * (critical hit, Strength and weapon enchantments included): a later hit in the window only deals what it has more.
	 */
	private ?EntityDamageEvent $lastHurtEvent = null;
	private float $lastHurtDamage = 0.0;
	/** Mob or player that last hurt this entity, and the server tick of that hit (death message kill credit). */
	private ?Living $recentAttacker = null;
	private int $recentAttackerTick = 0;

	public int $deadTicks = 0;
	protected int $maxDeadTicks = 25;

	protected float $jumpVelocity = 0.42;

	protected EffectManager $effectManager;

	protected ArmorInventory $armorInventory;

	/**
	 * Class name => whether the class keeps the eye block checks of the base tick (see keepsEyeChecks()).
	 * @var bool[]
	 * @phpstan-var array<class-string, bool>
	 */
	private static array $plainEyeChecksByClass = [];
	/** The answer for this entity's class (see above), kept here: it is asked on every tick. */
	private ?bool $plainEyeChecks = null;

	protected bool $breathing = true;
	protected int $breathTicks = self::DEFAULT_BREATH_TICKS;
	protected int $maxBreathTicks = self::DEFAULT_BREATH_TICKS;

	protected Attribute $healthAttr;
	protected Attribute $absorptionAttr;
	protected Attribute $knockbackResistanceAttr;
	protected Attribute $moveSpeedAttr;
	/** Movement speed without the multipliers (see {@link Living::setMovementSpeedMultiplier()}). */
	private float $baseMovementSpeed = 0.0;
	/** The attribute value last worked out from the base speed: another value was set from outside. */
	private float $appliedMovementSpeed = -1.0;
	/** @var float[] movement speed multipliers by id */
	private array $movementSpeedMultipliers = [];

	public const SPEED_MODIFIER_SPRINTING = "sprinting";
	public const SPEED_MODIFIER_SPEED_EFFECT = "speed_effect";
	public const SPEED_MODIFIER_SLOWNESS_EFFECT = "slowness_effect";
	/** Speed multiplier while sprinting (vanilla +30% ADD_MULTIPLIED_TOTAL). */
	public const SPRINT_SPEED_MULTIPLIER = 1.3;

	protected bool $sprinting = false;
	protected bool $sneaking = false;
	protected bool $gliding = false;

	/**
	 * Fall distance above this Y doesn't count (set when launched by a wind charge), or null.
	 * @see Living::setFallDamageCutoffY()
	 */
	protected ?float $fallDamageCutoffY = null;
	private int $fallDamageCutoffSetTick = 0;
	protected bool $swimming = false;
	private bool $crawling = false;

	private ?int $frostWalkerLevel = null;
	/** Knockback resistance added to the attribute by the worn armour (netherite: 0.1 per piece). */
	private float $armorKnockbackResistance = 0.0;
	/** The knockback resistance attribute value set with the armour share, null when the armour adds nothing. */
	private ?float $knockbackResistanceWithArmor = null;

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.08; }

	abstract public function getName() : string;

	public function canBeRenamed() : bool{
		return true;
	}

	/**
	 * Returns why this mob came into the world (like Paper's Entity::getEntitySpawnReason()): one of the
	 * {@link SpawnReason} constants, or a plugin's own reason. Saved with the mob.
	 */
	public function getSpawnReason() : string{
		return $this->spawnReason;
	}

	public function setSpawnReason(string $reason) : void{
		$this->spawnReason = $reason;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->spawnReason = $nbt->getString(self::TAG_SPAWN_REASON, SpawnReason::DEFAULT);

		$this->effectManager = new EffectManager($this);
		$this->effectManager->getEffectAddHooks()->add(function() : void{ $this->networkPropertiesDirty = true; });
		$this->effectManager->getEffectRemoveHooks()->add(function() : void{ $this->networkPropertiesDirty = true; });

		$this->armorInventory = new ArmorInventory($this);
		if(!$this instanceof Human){
			$this->loadArmorSlots($nbt);
		}
		$this->armorInventory->getListeners()->add(CallbackInventoryListener::onAnyChange(fn() => NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onMobArmorChange($recipients, $this)
		)));
		$this->armorInventory->getListeners()->add(new CallbackInventoryListener(
			onSlotChange: function(Inventory $inventory, int $slot, Item $oldItem) : void{
				if($slot === ArmorInventory::SLOT_FEET){
					$this->frostWalkerLevel = null;
				}
				$newItem = $inventory->getItem($slot);
				if($this->ticksLived > 0 && !$this->closed && ($oldItem->isNull() !== $newItem->isNull() || !$oldItem->equals($newItem, true, false))){
					//Java LivingEntity.onEquipItem(): putting on or taking off armour (wear doesn't count)
					$this->getWorld()->addGameEvent($this->location, $newItem->isNull() ? GameEvent::UNEQUIP : GameEvent::EQUIP, $this);
				}
			},
			onContentChange: function() : void{ $this->frostWalkerLevel = null; }
		));
		$this->armorInventory->getListeners()->add(CallbackInventoryListener::onAnyChange(fn() => $this->updateArmorKnockbackResistance()));
		$this->updateArmorKnockbackResistance(); //armour loaded above, before the listener

		$health = $this->getMaxHealth();

		if(($healFTag = $nbt->getTag(self::TAG_LEGACY_HEALTH)) instanceof FloatTag){
			$health = $healFTag->getValue();
		}elseif(($healthTag = $nbt->getTag(self::TAG_HEALTH)) instanceof ShortTag){
			$health = $healthTag->getValue(); //Older versions of PocketMine-MP incorrectly saved this as a short instead of a float
		}elseif($healthTag instanceof FloatTag){
			$health = $healthTag->getValue();
		}

		$this->setHealth($health);

		$this->setAirSupplyTicks($nbt->getShort(self::TAG_BREATH_TICKS, self::DEFAULT_BREATH_TICKS));

		$activeEffectsTag = $nbt->getListTag(self::TAG_ACTIVE_EFFECTS, CompoundTag::class);
		if($activeEffectsTag !== null){
			foreach($activeEffectsTag as $e){
				$effect = EffectIdMap::getInstance()->fromId($e->getByte(self::TAG_EFFECT_ID));
				if($effect === null){
					continue;
				}

				$duration = $e->getInt(self::TAG_EFFECT_DURATION);
				$this->effectManager->add(new EffectInstance(
					$effect,
					$duration === -1 ? Limits::INT32_MAX : $duration,
					Binary::unsignByte($e->getByte(self::TAG_EFFECT_AMPLIFIER)),
					$e->getByte(self::TAG_EFFECT_SHOW_PARTICLES, 1) !== 0,
					$e->getByte(self::TAG_EFFECT_AMBIENT, 0) !== 0,
					infinite: $duration === -1
				));
			}
		}
	}

	protected function addAttributes() : void{
		$this->attributeMap->add($this->healthAttr = AttributeFactory::getInstance()->mustGet(Attribute::HEALTH));
		$this->attributeMap->add(AttributeFactory::getInstance()->mustGet(Attribute::FOLLOW_RANGE));
		$this->attributeMap->add($this->knockbackResistanceAttr = AttributeFactory::getInstance()->mustGet(Attribute::KNOCKBACK_RESISTANCE));
		$this->attributeMap->add($this->moveSpeedAttr = AttributeFactory::getInstance()->mustGet(Attribute::MOVEMENT_SPEED));
		$this->attributeMap->add(AttributeFactory::getInstance()->mustGet(Attribute::ATTACK_DAMAGE));
		$this->attributeMap->add($this->absorptionAttr = AttributeFactory::getInstance()->mustGet(Attribute::ABSORPTION));
	}

	/**
	 * Returns the name used to describe this entity in chat and command outputs.
	 */
	public function getDisplayName() : string{
		return $this->nameTag !== "" ? $this->nameTag : $this->getName();
	}

	public function setHealth(float $amount) : void{
		$wasAlive = $this->isAlive();
		$oldHealth = $this->getHealth();
		parent::setHealth($amount);
		//the exact health, like BDS 1.26.60 (17.5 after a 2.5 hit; it was rounded up to 18)
		$this->healthAttr->setValue($this->getHealth(), true);
		if($this->getHealth() !== $oldHealth && !$this instanceof Player && count($this->hasSpawned) > 0){
			$this->broadcastHealth();
		}
		if($this->isAlive() && !$wasAlive){
			$this->broadcastAnimation(new RespawnAnimation($this));
		}
	}

	/**
	 * Sends the exact health of this mob to its viewers, like BDS: the client shows it on the health bar of a ridden
	 * mob (horses, camels...). Players get their own health from their attribute map instead.
	 */
	private function broadcastHealth() : void{
		$health = clone $this->healthAttr;
		$health->setValue($this->getHealth(), true);
		NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->syncAttributes($recipients, $this, [$health])
		);
	}

	public function getMaxHealth() : int{
		return (int) $this->healthAttr->getMaxValue();
	}

	public function setMaxHealth(int $amount) : void{
		$this->healthAttr->setMaxValue($amount)->setDefaultValue($amount);
	}

	public function getAbsorption() : float{
		return $this->absorptionAttr->getValue();
	}

	public function setAbsorption(float $absorption) : void{
		$this->absorptionAttr->setValue($absorption);
	}

	public function getSneakOffset() : float{
		return 0.0;
	}

	public function isSneaking() : bool{
		return $this->sneaking;
	}

	public function setSneaking(bool $value = true) : void{
		$this->sneaking = $value;
		$this->networkPropertiesDirty = true;
		$this->recalculateSize();
	}

	public function isSprinting() : bool{
		return $this->sprinting;
	}

	public function setSprinting(bool $value = true) : void{
		if($value !== $this->isSprinting()){
			$this->sprinting = $value;
			$this->networkPropertiesDirty = true;
			$this->setMovementSpeedMultiplier(self::SPEED_MODIFIER_SPRINTING, $value ? self::SPRINT_SPEED_MULTIPLIER : 1.0);
			$this->moveSpeedAttr->markSynchronized(false); //TODO: reevaluate this hack
		}
	}

	public function isGliding() : bool{
		return $this->gliding;
	}

	public function setGliding(bool $value = true) : void{
		$this->gliding = $value;
		$this->networkPropertiesDirty = true;
		$this->recalculateSize();
	}

	public function isSwimming() : bool{
		return $this->swimming;
	}

	public function setSwimming(bool $value = true) : void{
		$this->swimming = $value;
		$this->networkPropertiesDirty = true;
		$this->recalculateSize();
	}

	/**
	 * Whether the entity crawls (a player forced into a gap 1 block high): it then has the swimming size.
	 */
	public function isCrawling() : bool{
		return $this->crawling;
	}

	public function setCrawling(bool $value = true) : void{
		if($value !== $this->crawling){
			$this->crawling = $value;
			$this->networkPropertiesDirty = true;
			$this->recalculateSize();
		}
	}

	private function recalculateSize() : void{
		$size = $this->getInitialSizeInfo();
		if($this->isSwimming() || $this->isGliding() || $this->crawling){
			$width = $size->getWidth();
			$this->setSize((new EntitySizeInfo($width, $width, $width * 0.9))->scale($this->getScale()));
		}elseif($this->isSneaking()){
			$this->setSize((new EntitySizeInfo($size->getHeight() - $this->getSneakOffset(), $size->getWidth(), $size->getEyeHeight() - $this->getSneakOffset()))->scale($this->getScale()));
		}else{
			$this->setSize($size->scale($this->getScale()));
		}
	}

	/**
	 * Returns the movement speed with the sprint and effect multipliers applied (the attribute value).
	 */
	public function getMovementSpeed() : float{
		return $this->moveSpeedAttr->getValue();
	}

	/**
	 * Sets the movement speed without the sprint and effect multipliers (vanilla attribute base value): the attribute
	 * value is this speed times the active multipliers (see {@link Living::setMovementSpeedMultiplier()}).
	 */
	public function setMovementSpeed(float $v, bool $fit = false) : void{
		$attr = $this->moveSpeedAttr;
		if($v > $attr->getMaxValue() || $v < $attr->getMinValue()){
			if(!$fit){
				throw new \InvalidArgumentException("Value $v is outside the range " . $attr->getMinValue() . " - " . $attr->getMaxValue());
			}
			$v = min(max($v, $attr->getMinValue()), $attr->getMaxValue());
		}
		$this->baseMovementSpeed = $v;
		$this->updateMovementSpeed();
	}

	/**
	 * Returns the movement speed without the sprint and effect multipliers.
	 */
	public function getBaseMovementSpeed() : float{
		$this->syncBaseMovementSpeed();
		return $this->baseMovementSpeed;
	}

	/**
	 * Sets a multiplier of the movement speed (vanilla ADD_MULTIPLIED_TOTAL attribute modifier), 1 to remove it. The
	 * speed is worked out again from the base speed, so adding and removing multipliers in any order never drifts, and
	 * a multiplier below 0 counts as 0 (Slowness 7 and up stops the mob).
	 *
	 * @internal used by sprinting and the Speed and Slowness effects
	 */
	public function setMovementSpeedMultiplier(string $id, float $multiplier) : void{
		$this->syncBaseMovementSpeed();
		if($multiplier === 1.0){
			unset($this->movementSpeedMultipliers[$id]);
		}else{
			$this->movementSpeedMultipliers[$id] = max(0.0, $multiplier);
		}
		$this->updateMovementSpeed();
	}

	/**
	 * The attribute was set from outside (a reset on respawn, a plugin): its value becomes the base speed.
	 */
	private function syncBaseMovementSpeed() : void{
		$value = $this->moveSpeedAttr->getValue();
		if($value !== $this->appliedMovementSpeed){
			$this->baseMovementSpeed = $value;
			$this->appliedMovementSpeed = $value;
			if(count($this->movementSpeedMultipliers) !== 0){
				$this->updateMovementSpeed();
			}
		}
	}

	private function updateMovementSpeed() : void{
		$speed = $this->baseMovementSpeed;
		foreach($this->movementSpeedMultipliers as $multiplier){
			$speed *= $multiplier;
		}
		$this->moveSpeedAttr->setValue($speed, true);
		$this->appliedMovementSpeed = $this->moveSpeedAttr->getValue();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setFloat(self::TAG_HEALTH, $this->getHealth());
		if($this->spawnReason !== SpawnReason::DEFAULT){
			$nbt->setString(self::TAG_SPAWN_REASON, $this->spawnReason);
		}

		$nbt->setShort(self::TAG_BREATH_TICKS, $this->getAirSupplyTicks());

		if(count($this->effectManager->all()) > 0){
			$effects = [];
			foreach($this->effectManager->all() as $effect){
				$effects[] = CompoundTag::create()
					->setByte(self::TAG_EFFECT_ID, EffectIdMap::getInstance()->toId($effect->getType()))
					->setByte(self::TAG_EFFECT_AMPLIFIER, Binary::signByte($effect->getAmplifier()))
					->setInt(self::TAG_EFFECT_DURATION, $effect->isInfinite() ? -1 : $effect->getDuration())
					->setByte(self::TAG_EFFECT_AMBIENT, $effect->isAmbient() ? 1 : 0)
					->setByte(self::TAG_EFFECT_SHOW_PARTICLES, $effect->isVisible() ? 1 : 0);
			}

			$nbt->setTag(self::TAG_ACTIVE_EFFECTS, new ListTag($effects));
		}

		if(!$this instanceof Human){
			$armor = [];
			foreach(self::ARMOR_SLOT_ORDER as $slot){
				$item = $this->armorInventory->getItem($slot);
				$armor[] = $item->isNull() ? CompoundTag::create()->setString("Name", "")->setByte("Count", 0) : $item->nbtSerialize();
			}
			$nbt->setTag(self::TAG_ARMOR_SLOTS, new ListTag($armor, NBT::TAG_Compound));
		}

		return $nbt;
	}

	private function loadArmorSlots(CompoundTag $nbt) : void{
		$armorTag = $nbt->getListTag(self::TAG_ARMOR_SLOTS, CompoundTag::class);
		if($armorTag === null){
			return;
		}
		foreach($armorTag as $i => $itemTag){
			if(!isset(self::ARMOR_SLOT_ORDER[$i]) || $itemTag->getString("Name", "") === "" && $itemTag->getTag("id") === null){
				continue; //empty slot
			}
			if(!($item = Item::safeNbtDeserialize($itemTag, "Mob armor slot $i"))->isNull()){
				$this->armorInventory->setItem(self::ARMOR_SLOT_ORDER[$i], $item);
			}
		}
	}

	public function getEffects() : EffectManager{
		return $this->effectManager;
	}

	/**
	 * Causes the mob to consume the given Consumable object, applying applicable effects, health bonuses, food bonuses,
	 * etc.
	 */
	public function consumeObject(Consumable $consumable) : bool{
		$this->applyConsumptionResults($consumable);
		return true;
	}

	/**
	 * Applies effects from consuming the object. This shouldn't do any can-consume checks (those are expected to be
	 * handled by the caller).
	 */
	protected function applyConsumptionResults(Consumable $consumable) : void{
		foreach($consumable->getAdditionalEffects() as $effect){
			$this->effectManager->add($effect);
		}
		if($consumable instanceof FoodSource){
			$this->broadcastSound(new BurpSound());
		}
		$this->getWorld()->addGameEvent($this->location, $consumable instanceof FoodSource ? GameEvent::EAT : GameEvent::DRINK, $this);

		$consumable->onConsume($this);
	}

	/**
	 * Returns the initial upwards velocity of a jumping entity in blocks/tick, including additional velocity due to effects.
	 */
	public function getJumpVelocity() : float{
		return $this->jumpVelocity + ((($jumpBoost = $this->effectManager->get(VanillaEffects::JUMP_BOOST())) !== null ? $jumpBoost->getEffectLevel() : 0) / 10);
	}

	/**
	 * Called when the entity jumps from the ground. This method adds upwards velocity to the entity.
	 */
	public function jump() : void{
		if($this->onGround){
			$this->motion = $this->motion->withComponents(null, $this->getJumpVelocity(), null); //Y motion should already be 0 if we're jumping from the ground.
		}
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return ceil($fallDistance - 3 - (($jumpBoost = $this->effectManager->get(VanillaEffects::JUMP_BOOST())) !== null ? $jumpBoost->getEffectLevel() : 0));
	}

	/**
	 * Makes the entity take fall damage only for the distance it falls below the given Y, until it next lands.
	 * Used by wind charges, whose bursts negate the fall damage of the launched entities (vanilla).
	 */
	public function setFallDamageCutoffY(?float $y) : void{
		$this->fallDamageCutoffY = $y;
		$this->fallDamageCutoffSetTick = $this->ticksLived;
	}

	public function getFallDamageCutoffY() : ?float{
		return $this->fallDamageCutoffY;
	}

	protected function onHitGround() : ?float{
		if($this->fallDamageCutoffY !== null){
			$this->fallDistance = min($this->fallDistance, max(0, $this->fallDamageCutoffY - $this->location->y));
			$this->fallDamageCutoffY = null;
		}

		$fallBlockPos = $this->location->floor();
		$fallBlock = $this->getWorld()->getBlock($fallBlockPos);
		if(count($fallBlock->getCollisionBoxes()) === 0){
			$fallBlockPos = $fallBlockPos->down();
			$fallBlock = $this->getWorld()->getBlock($fallBlockPos);
		}
		$newVerticalVelocity = $fallBlock->onEntityLand($this);

		$damage = $this->calculateFallDamage($this->fallDistance);
		if($damage > 0){
			$ev = new EntityDamageEvent($this, $fallBlock instanceof PointedDripstone && $fallBlock->isStalagmiteTip() ? EntityDamageEvent::CAUSE_STALAGMITE : EntityDamageEvent::CAUSE_FALL, $damage);
			$this->attack($ev);

			$this->broadcastSound($damage > 4 ?
				new EntityLongFallSound($this) :
				new EntityShortFallSound($this)
			);
		}elseif($fallBlock->getTypeId() !== BlockTypeIds::AIR){
			$this->broadcastSound(new EntityLandSound($this, $fallBlock));
		}
		return $newVerticalVelocity;
	}

	/**
	 * Returns how many armour points this mob has. Armour points provide a percentage reduction to damage.
	 * For mobs which can wear armour, this should return the sum total of the armour points provided by their
	 * equipment.
	 */
	public function getArmorPoints() : int{
		$total = 0;
		foreach($this->armorInventory->getContents() as $item){
			$total += $item->getDefensePoints();
		}

		return $total;
	}

	/**
	 * Returns the highest level of the specified enchantment on any armour piece that the entity is currently wearing.
	 */
	public function getHighestArmorEnchantmentLevel(Enchantment $enchantment) : int{
		$result = 0;
		foreach($this->armorInventory->getContents() as $item){
			$result = max($result, $item->getEnchantmentLevel($enchantment));
		}

		return $result;
	}

	public function getArmorInventory() : ArmorInventory{
		return $this->armorInventory;
	}

	/**
	 * Returns the mob or player that hurt this entity within the last 5 seconds (vanilla kill credit, used by the
	 * "whilst trying to escape" death messages), or null.
	 */
	public function getRecentAttacker() : ?Living{
		$attacker = $this->recentAttacker;
		if($attacker === null){
			return null;
		}
		if($attacker->isClosed() || $this->server->getTick() - $this->recentAttackerTick > self::RECENT_ATTACKER_TICKS){
			$this->recentAttacker = null;
			return null;
		}
		return $attacker;
	}

	/**
	 * Returns the mob or player a hit is credited to: the damager, or for an explosion or another entity without a
	 * mind of its own (a wither skull, primed TNT...), whoever fired or lit it (vanilla causing entity).
	 */
	private static function getDamagingMob(EntityDamageByEntityEvent $source) : ?Living{
		$damager = $source->getDamager();
		if($damager !== null && !$damager instanceof Living){
			$damager = $damager->getOwningEntity();
		}
		return $damager instanceof Living ? $damager : null;
	}

	public function setOnFire(int $seconds) : void{
		parent::setOnFire($seconds - (int) min($seconds, $seconds * $this->getHighestArmorEnchantmentLevel(VanillaEnchantments::FIRE_PROTECTION()) * 0.15));
	}

	/**
	 * Called prior to EntityDamageEvent execution to apply modifications to the event's damage, such as reduction due
	 * to effects or armour.
	 */
	public function applyDamageModifiers(EntityDamageEvent $source) : void{
		if($this->lastDamageCause !== null && $this->attackTime > 0){
			//vanilla compares the full damage of both hits, not the weapon's base damage: a critical hit right after a
			//normal one still lands for the difference
			$lastHurt = $this->lastHurtEvent === $this->lastDamageCause ? $this->lastHurtDamage : $this->lastDamageCause->getBaseDamage();
			if($lastHurt >= $source->getFinalDamage()){
				$source->cancel();
			}
			$source->setModifier(-$lastHurt, EntityDamageEvent::MODIFIER_PREVIOUS_DAMAGE_COOLDOWN);
		}
		if($source->canBeReducedByArmor()){
			$armorReduction = CombatRules::getArmorReduction($source->getFinalDamage(), $this->getArmorPoints(), $this->getArmorToughness());
			if($source instanceof EntityDamageByEntityEvent){
				//Breach: armor loses a flat share of its effectiveness
				$armorReduction = max(0, $armorReduction - $source->getArmorEffectivenessReduction());
			}
			$source->setModifier(-$source->getFinalDamage() * $armorReduction, EntityDamageEvent::MODIFIER_ARMOR);
		}

		$cause = $source->getCause();
		if(($resistance = $this->effectManager->get(VanillaEffects::RESISTANCE())) !== null && $cause !== EntityDamageEvent::CAUSE_VOID && $cause !== EntityDamageEvent::CAUSE_SUICIDE){
			$source->setModifier(-$source->getFinalDamage() * min(1, 0.2 * $resistance->getEffectLevel()), EntityDamageEvent::MODIFIER_RESISTANCE);
		}

		if($source->canBeReducedByArmorEnchantments()){
			$totalEpf = 0;
			foreach($this->armorInventory->getContents() as $item){
				if($item instanceof Armor){
					$totalEpf += $item->getBedrockEnchantmentProtectionFactor($source);
				}
			}
			//Bedrock: 4% per point, at most 20 points, no random part (BDS 1.26.60: diamond armour with Protection IV on
			//every piece takes an iron sword's 7 down to 0.68)
			$source->setModifier(-$source->getFinalDamage() * min($totalEpf, 20) * 0.04, EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS);
		}

		$source->setModifier(-min($this->getAbsorption(), $source->getFinalDamage()), EntityDamageEvent::MODIFIER_ABSORPTION);

		if(($cause === EntityDamageEvent::CAUSE_FALLING_BLOCK || $cause === EntityDamageEvent::CAUSE_STALACTITE) && $this->armorInventory->getHelmet() instanceof Armor){
			$source->setModifier(-($source->getFinalDamage() / 4), EntityDamageEvent::MODIFIER_ARMOR_HELMET);
		}

		if($cause !== EntityDamageEvent::CAUSE_SUICIDE && $cause !== EntityDamageEvent::CAUSE_VOID && $this->holdsTotemOfUndying()){
			$compensation = $this->getHealth() - $source->getFinalDamage() - 1;
			if($compensation <= -1){
				$source->setModifier($compensation, EntityDamageEvent::MODIFIER_TOTEM);
			}
		}
	}

	/**
	 * Returns the total armour toughness of the worn armour.
	 */
	private function getArmorToughness() : int{
		$total = 0;
		foreach($this->armorInventory->getContents() as $item){
			if($item instanceof Armor){
				$total += $item->getToughness();
			}
		}
		return $total;
	}

	/**
	 * Adds the knockback resistance of the worn armour (netherite: 0.1 per piece) to the knockback resistance
	 * attribute, like vanilla's attribute modifiers. Called when the armour changes, so knockback and sonic booms just
	 * read the attribute. Explosions push regardless (vanilla: separate explosion_knockback_resistance).
	 */
	protected function updateArmorKnockbackResistance() : void{
		$resistance = 0.0;
		//with PocketMine-MP 5.44.3 knockback (player.knockback: pmmp) armour doesn't resist it
		if(self::$vanillaKnockback){
			foreach($this->armorInventory->getContents() as $item){
				if($item instanceof Armor){
					$resistance += $item->getKnockbackResistance();
				}
			}
		}
		$base = $this->knockbackResistanceAttr->getValue();
		if($this->knockbackResistanceWithArmor !== null && $base === $this->knockbackResistanceWithArmor){
			//still the value set here: take the old armour share out (otherwise it was reset or set to a new base)
			$base -= $this->armorKnockbackResistance;
		}elseif($resistance === 0.0){
			$this->knockbackResistanceWithArmor = null;
			$this->armorKnockbackResistance = 0.0;
			return;
		}
		$this->knockbackResistanceAttr->setValue(round($base + $resistance, 6), true);
		$this->armorKnockbackResistance = $resistance;
		$this->knockbackResistanceWithArmor = $resistance === 0.0 ? null : $this->knockbackResistanceAttr->getValue();
	}

	/**
	 * Returns whether this entity holds a totem of undying (main hand or off hand) that saves it from a lethal hit.
	 * Vanilla: any mob holding one is saved, not only players.
	 */
	protected function holdsTotemOfUndying() : bool{
		return false;
	}

	/**
	 * Removes one held totem of undying (main hand first) after it saved this entity.
	 */
	protected function consumeTotemOfUndying() : void{

	}

	/**
	 * A totem of undying saved this entity from death: health 1 (already applied by the damage modifier), effects
	 * replaced by the totem's, totem consumed, CONSUME_TOTEM actor event and sound.
	 */
	private function useTotemOfUndying() : void{
		$this->effectManager->clear();

		$this->effectManager->add(new EffectInstance(VanillaEffects::REGENERATION(), 40 * 20, 1));
		$this->effectManager->add(new EffectInstance(VanillaEffects::FIRE_RESISTANCE(), 40 * 20, 1));
		$this->effectManager->add(new EffectInstance(VanillaEffects::ABSORPTION(), 5 * 20, 1));

		$this->broadcastAnimation(new LivingTotemUseAnimation($this));
		$this->broadcastSound(new TotemUseSound());

		$this->consumeTotemOfUndying();
	}

	/**
	 * Called after EntityDamageEvent execution to apply post-hurt effects, such as reducing absorption or modifying
	 * armour durability.
	 * This will not be called by damage sources causing death.
	 */
	protected function applyPostDamageEffects(EntityDamageEvent $source) : void{
		$this->setAbsorption(max(0, $this->getAbsorption() + $source->getModifier(EntityDamageEvent::MODIFIER_ABSORPTION)));
		if($source->canBeReducedByArmor()){
			$this->damageArmor($source->getBaseDamage());
		}

		if($source instanceof EntityDamageByEntityEvent && ($attacker = $source->getDamager()) !== null){
			$damage = 0;
			foreach($this->armorInventory->getContents() as $k => $item){
				if($item instanceof Armor && ($thornsLevel = $item->getEnchantmentLevel(VanillaEnchantments::THORNS())) > 0){
					if(mt_rand(0, 99) < $thornsLevel * 15){
						$this->damageItem($item, 3);
						$damage += ($thornsLevel > 10 ? $thornsLevel - 10 : 1 + mt_rand(0, 3));
					}else{
						$this->damageItem($item, 1); //thorns causes an extra +1 durability loss even if it didn't activate
					}

					$this->armorInventory->setItem($k, $item);
				}
			}

			if($damage > 0){
				$attacker->attack(new EntityDamageByEntityEvent($this, $attacker, EntityDamageEvent::CAUSE_THORNS, $damage));
			}

			if($source->getModifier(EntityDamageEvent::MODIFIER_ARMOR_HELMET) < 0){
				$helmet = $this->armorInventory->getHelmet();
				if($helmet instanceof Armor){
					$finalDamage = $source->getFinalDamage();
					$this->damageItem($helmet, (int) round($finalDamage * 4 + Utils::getRandomFloat() * $finalDamage * 2));
					$this->armorInventory->setHelmet($helmet);
				}
			}
		}

		if($source->getModifier(EntityDamageEvent::MODIFIER_TOTEM) < 0){ //Totem prevented death
			$this->useTotemOfUndying();
		}

		//effects that act on damage (Infested); all() is a copy, so they may change the effects safely
		foreach($this->effectManager->all() as $instance){
			$type = $instance->getType();
			if($type instanceof DamageAwareEffect){
				$type->onEntityDamaged($this, $instance, $source);
			}
		}
	}

	/**
	 * Damages the worn armour according to the amount of damage given. Each 4 points (rounded down) deals 1 damage
	 * point to each armour piece, but never less than 1 total.
	 */
	public function damageArmor(float $damage) : void{
		$durabilityRemoved = (int) max(floor($damage / 4), 1);

		$armor = $this->armorInventory->getContents();
		foreach($armor as $slotId => $item){
			if($item instanceof Armor && !$item instanceof Elytra){ //elytra are not worn down by attacks
				$oldItem = clone $item;
				$this->damageItem($item, $durabilityRemoved);
				if(!$item->equalsExact($oldItem)){
					$this->armorInventory->setItem($slotId, $item);
				}
			}
		}
	}

	private function damageItem(Durable $item, int $durabilityRemoved) : void{
		$item->applyDamage($durabilityRemoved);
		if($item->isBroken()){
			$this->broadcastSound(new ItemBreakSound());
		}
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->noDamageTicks > 0 && $source->getCause() !== EntityDamageEvent::CAUSE_SUICIDE){
			$source->cancel();
		}

		if($this->effectManager->has(VanillaEffects::FIRE_RESISTANCE()) && EntityDamageEvent::isFireCause($source->getCause())){
			$source->cancel();
		}

		$fullDamage = $source->getFinalDamage();
		if($source->getCause() !== EntityDamageEvent::CAUSE_SUICIDE){
			$this->applyDamageModifiers($source);
		}

		if($source instanceof EntityDamageByEntityEvent && (
			$source->getCause() === EntityDamageEvent::CAUSE_BLOCK_EXPLOSION ||
			$source->getCause() === EntityDamageEvent::CAUSE_ENTITY_EXPLOSION)
		){
			//TODO: knockback should not just apply for entity damage sources
			//this doesn't matter for TNT right now because the PrimedTNT entity is considered the source, not the block.
			$base = $source->getKnockBack();
			$source->setKnockBack($base - min($base, $base * $this->getHighestArmorEnchantmentLevel(VanillaEnchantments::BLAST_PROTECTION()) * 0.15));
		}

		parent::attack($source);

		if($source->isCancelled()){
			return;
		}
		$this->lastHurtEvent = $source;
		$this->lastHurtDamage = $fullDamage;
		if($source instanceof EntityDamageByEntityEvent && ($attacker = self::getDamagingMob($source)) !== null && $attacker !== $this){
			$this->recentAttacker = $attacker;
			$this->recentAttackerTick = $this->server->getTick();
		}

		if($this->attackTime <= 0){
			//this logic only applies if the entity was cold attacked

			$this->attackTime = $source->getAttackCooldown();
			$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_DAMAGE, $this);

			if($source instanceof EntityDamageByChildEntityEvent){
				$e = $source->getChild();
				if($e !== null){
					$motion = $e->getMotion();
					$this->knockBack($motion->x, $motion->z, $source->getKnockBack(), $source->getVerticalKnockBackLimit());
				}
			}elseif($source instanceof EntityDamageByEntityEvent){
				$e = $source->getDamager();
				if($e !== null){
					$deltaX = $this->location->x - $e->location->x;
					$deltaZ = $this->location->z - $e->location->z;
					$this->knockBack($deltaX, $deltaZ, $source->getKnockBack(), $source->getVerticalKnockBackLimit());
				}
			}

			if($this->isAlive()){
				$this->doHitAnimation();
			}
		}

		if($this->isAlive()){
			$this->applyPostDamageEffects($source);
		}
	}

	protected function doHitAnimation() : void{
		$this->broadcastAnimation(new HurtAnimation($this));
	}

	/**
	 * Returns whether knockback follows vanilla (player.knockback: vanilla in pocketmine.yml) instead of
	 * PocketMine-MP 5.44.3 (pmmp, the default).
	 */
	public static function hasVanillaKnockback() : bool{
		return self::$vanillaKnockback;
	}

	/**
	 * @internal Set from player.knockback in pocketmine.yml when the server starts (and by tests). Entities created
	 * before keep the armour knockback resistance of the old mode until their armour changes.
	 */
	public static function setVanillaKnockback(bool $vanilla) : void{
		self::$vanillaKnockback = $vanilla;
	}

	/**
	 * Returns whether a plugin left the knockback of this hit alone: the vanilla extras on top of it (a sprint hit,
	 * a golem's toss, a goat's ram...) only apply then, otherwise the plugin's values are applied as they are.
	 */
	public static function isDefaultKnockback(EntityDamageByEntityEvent $source, float $force = self::DEFAULT_KNOCKBACK_FORCE) : bool{
		return $source->getKnockBack() === $force && $source->getVerticalKnockBackLimit() === self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT;
	}

	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		$f = sqrt($x * $x + $z * $z);
		if($f <= 0){
			return;
		}
		if(mt_rand() / mt_getrandmax() > $this->knockbackResistanceAttr->getValue()){
			$f = 1 / $f;

			$motionX = $this->motion->x / 2;
			$motionY = $this->motion->y / 2;
			$motionZ = $this->motion->z / 2;
			$motionX += $x * $f * $force;
			$motionY += $force;
			$motionZ += $z * $f * $force;

			$verticalLimit ??= $force;
			if($motionY > $verticalLimit){
				$motionY = $verticalLimit;
			}

			$this->setMotion(new Vector3($motionX, $motionY, $motionZ));
		}
	}

	protected function onDeath() : void{
		$loot = $this->getWorld()->getGameRule(GameRules::DO_MOB_LOOT) !== false;
		$ev = new EntityDeathEvent($this, $loot ? $this->getDrops() : [], $loot ? $this->getXpDropAmount() : 0);
		$ev->call();
		foreach($ev->getDrops() as $item){
			$this->getWorld()->dropItem($this->location, $item);
		}

		//TODO: check death conditions (must have been damaged by player < 5 seconds from death)
		$xp = $ev->getXpDropAmount();
		if(!$this->getWorld()->getVibrations()->absorbDeathExperience($this, $xp)){ //a sculk catalyst nearby takes it
			$this->getWorld()->dropExperience($this->location, $xp);
		}
		$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_DIE, $this);

		$this->dropWitherRoseIfKilledByWither();
		$this->applyEffectsOnDeath();
		$this->startDeathAnimation();
	}

	/**
	 * A mob or player killed by the Wither (its skulls, its explosion, or anything shortly after the Wither hurt it)
	 * drops a wither rose. Bedrock always drops it as an item (Java places the block when mobGriefing allows).
	 */
	protected function dropWitherRoseIfKilledByWither() : void{
		$cause = $this->lastDamageCause;
		$killer = $cause instanceof EntityDamageByEntityEvent ? self::getDamagingMob($cause) : null;
		if($killer === null){
			$killer = $this->getRecentAttacker();
		}
		if($killer instanceof Wither){
			$this->getWorld()->dropItem($this->location, VanillaBlocks::WITHER_ROSE()->asItem());
		}
	}

	/**
	 * Lets the active effects act on the death of this entity (Wind Charged, Weaving...).
	 */
	protected function applyEffectsOnDeath() : void{
		foreach($this->effectManager->all() as $instance){
			$instance->getType()->onEntityDeath($this, $instance);
		}
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		if($this->deadTicks < $this->maxDeadTicks){
			$this->deadTicks += $tickDiff;
			if($this->deadTicks >= $this->maxDeadTicks){
				$this->endDeathAnimation();
			}
		}

		return $this->deadTicks >= $this->maxDeadTicks;
	}

	protected function startDeathAnimation() : void{
		$this->broadcastAnimation(new DeathAnimation($this));
	}

	protected function endDeathAnimation() : void{
		$this->despawnFromAll();
	}

	/**
	 * Called by powder snow for entities inside it, every tick (see {@link Living::doFreezeTick()}).
	 */
	public function setInPowderSnow() : void{
		$this->inPowderSnow = true;
	}

	/**
	 * Ticks spent freezing, from 0 to {@link Living::FREEZE_TICKS} (fully frozen).
	 */
	public function getTicksFrozen() : int{
		return $this->ticksFrozen;
	}

	public function setTicksFrozen(int $ticks) : void{
		$ticks = max(0, min(self::FREEZE_TICKS, $ticks));
		if($ticks !== $this->ticksFrozen){
			$this->ticksFrozen = $ticks;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Whether powder snow freezes this entity: not while it wears any leather armor piece (vanilla).
	 */
	public function canFreeze() : bool{
		foreach($this->armorInventory->getContents() as $item){
			if($item instanceof Armor && $item->getMaterial() === VanillaArmorMaterials::LEATHER()){
				return false;
			}
		}
		return true;
	}

	/**
	 * Damage taken every 2 seconds while fully frozen (vanilla: 5 for blazes, magma cubes and striders).
	 */
	protected function getFreezeDamage() : float{
		return 1.0;
	}

	/**
	 * Vanilla freezing: inside powder snow the frozen time grows by 1 per tick up to 140 ticks, outside it drops by 2
	 * per tick. Fully frozen entities take freeze damage every 40 ticks. Clients show the frost overlay and the
	 * shivering from FREEZING_EFFECT_STRENGTH.
	 */
	protected function doFreezeTick(int $tickDiff) : bool{
		$inPowderSnow = $this->inPowderSnow;
		$this->inPowderSnow = false;
		if(!$inPowderSnow && ($this->ticksFrozen > 0 || $this instanceof Player)){
			//players only check the blocks they touch when they move: look at the feet and head blocks too (every tick
			//for every player: by block state ID, no Block objects)
			$world = $this->getWorld();
			$x = (int) floor($this->location->x);
			$z = (int) floor($this->location->z);
			$inPowderSnow = (self::blockFlagsAt($world, $x, (int) floor($this->location->y), $z) & self::BLOCK_FLAG_POWDER_SNOW) !== 0 ||
				(self::blockFlagsAt($world, $x, (int) floor($this->location->y + $this->size->getEyeHeight()), $z) & self::BLOCK_FLAG_POWDER_SNOW) !== 0;
		}
		if($inPowderSnow && $this->canFreeze()){
			$this->setTicksFrozen($this->ticksFrozen + $tickDiff);
		}elseif($this->ticksFrozen > 0){
			$this->setTicksFrozen($this->ticksFrozen - 2 * $tickDiff);
		}else{
			return false;
		}
		if($this->ticksFrozen >= self::FREEZE_TICKS && ($this->ticksLived % 40) < $tickDiff){
			$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FREEZE, $this->getFreezeDamage()));
		}
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		//see Entity::onUpdate(): the timer only runs while timings are enabled
		$timed = TimingsHandler::isEnabled();
		if($timed){
			Timings::$livingEntityBaseTick->startTiming();
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->isAlive()){
			if($this->effectManager->tick($tickDiff)){
				$hasUpdate = true;
			}

			if($this->plainEyeChecks ??= (self::$plainEyeChecksByClass[static::class] ??= self::keepsEyeChecks($this))){
				//the suffocation check and the air supply (isUnderwater(), through canBreathe()) look at the same block, the
				//one at the eyes: its state is read once for both. Nothing that runs between the two checks can change it,
				//unless the entity suffocates (the damage runs event handlers): the air supply then reads it again
				$world = $this->getWorld();
				$eyeX = (int) floor($this->location->x);
				$eyeY = (int) floor($this->location->y + $this->getEyeHeight());
				$eyeZ = (int) floor($this->location->z);
				$eyeFlags = self::blockFlagsAt($world, $eyeX, $eyeY, $eyeZ);
				if($this->isInsideOfSolidAtEye($world, $eyeX, $eyeY, $eyeZ, $eyeFlags)){
					$hasUpdate = true;
					$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
					$this->attack($ev);
					$airChanged = $this->doAirSupplyTick($tickDiff);
				}else{
					//doAirSupplyTick() with canBreathe() checking the block already read
					$airChanged = $this->applyAirSupplyTick($tickDiff, $this->getAirSupplyTicks(), $this->canBreatheAtEye($world, $eyeX, $eyeY, $eyeZ, $eyeFlags));
				}
				if($airChanged){
					$hasUpdate = true;
				}
			}else{
				if($this->isInsideOfSolid()){
					$hasUpdate = true;
					$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
					$this->attack($ev);
				}

				if($this->doAirSupplyTick($tickDiff)){
					$hasUpdate = true;
				}
			}

			if($this->doFreezeTick($tickDiff)){
				$hasUpdate = true;
			}

			foreach($this->armorInventory->getContents() as $index => $item){
				$oldItem = clone $item;
				if($item->onTickWorn($this)){
					$hasUpdate = true;
					if(!$item->equalsExact($oldItem)){
						$this->armorInventory->setItem($index, $item);
					}
				}
			}
		}

		if($this->attackTime > 0){
			$this->attackTime -= $tickDiff;
		}

		if($this->fallDamageCutoffY !== null && $this->onGround && $this->ticksLived - $this->fallDamageCutoffSetTick > 10){
			//landed without falling (e.g. pushed sideways): the cutoff only applies to the fall right after the burst
			$this->fallDamageCutoffY = null;
		}

		if($timed){
			Timings::$livingEntityBaseTick->stopTiming();
		}

		return $hasUpdate;
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		$oldX = $this->location->x;
		$oldZ = $this->location->z;

		parent::move($dx, $dy, $dz);

		//footsteps, swimming and flapping make vibrations (Java Entity.move(): one every 1/0.6 blocks travelled
		//horizontally; in the air the distance adds up until the entity lands, unless it flaps its wings)
		$this->stepDistance += sqrt(($this->location->x - $oldX) ** 2 + ($this->location->z - $oldZ) ** 2) * 0.6;
		if($this->stepDistance >= 1){
			$event = $this->getMovementGameEvent();
			if($event !== null){
				$this->stepDistance = 0;
				$this->getWorld()->addGameEvent($this->location, $event, $this);
			}
		}

		//vanilla Frost Walker only works while standing on something (not while jumping or falling over water)
		if($this->onGround && ($frostWalkerLevel = $this->getFrostWalkerLevel()) > 0 && (abs($this->location->x - $oldX) > self::MOTION_THRESHOLD || abs($this->location->z - $oldZ) > self::MOTION_THRESHOLD)){
			$this->applyFrostWalker($frostWalkerLevel);
		}
	}

	/**
	 * Returns the vibration made by moving now: swimming in water, a footstep on the ground, or nothing (in the air,
	 * where the distance keeps adding up until the entity lands). Mobs that flap their wings override this.
	 *
	 * @internal
	 */
	protected function getMovementGameEvent() : ?GameEvent{
		$flags = self::blockFlagsAt($this->getWorld(), (int) floor($this->location->x), (int) floor($this->location->y), (int) floor($this->location->z));
		if(($flags & self::BLOCK_FLAG_WATER) !== 0){
			return GameEvent::SWIM;
		}
		return $this->onGround ? GameEvent::STEP : null;
	}

	protected function applyFrostWalker(int $level) : void{
		$radius = $level + 2;
		$world = $this->getWorld();

		$baseX = $this->location->getFloorX();
		$y = $this->location->getFloorY() - 1;
		$baseZ = $this->location->getFloorZ();

		$liquid = VanillaBlocks::WATER();
		$targetBlock = VanillaBlocks::FROSTED_ICE();
		if(EntityFrostWalkerEvent::hasHandlers()){
			$ev = new EntityFrostWalkerEvent($this, $radius, $liquid, $targetBlock);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}
			$radius = $ev->getRadius();
			$liquid = $ev->getLiquid();
			$targetBlock = $ev->getTargetBlock();
		}

		//a disc, not a square (Java ReplaceDisk: block centres closer than the radius to the entity, horizontally)
		$posX = $this->location->x;
		$posZ = $this->location->z;
		$radiusSquared = $radius * $radius;
		for($x = $baseX - $radius; $x <= $baseX + $radius; $x++){
			$dx = $x + 0.5 - $posX;
			for($z = $baseZ - $radius; $z <= $baseZ + $radius; $z++){
				$dz = $z + 0.5 - $posZ;
				if($dx * $dx + $dz * $dz >= $radiusSquared){
					continue;
				}
				$block = $world->getBlockAt($x, $y, $z);
				if(
					!$block->isSameState($liquid) ||
					$world->getBlockAt($x, $y + 1, $z)->getTypeId() !== BlockTypeIds::AIR ||
					count($world->getNearbyEntities(AxisAlignedBB::one()->offset($x, $y, $z))) !== 0
				){
					continue;
				}
				$world->setBlockAt($x, $y, $z, $targetBlock);
			}
		}
	}

	public function getFrostWalkerLevel() : int{
		return $this->frostWalkerLevel ??= $this->armorInventory->getBoots()->getEnchantmentLevel(VanillaEnchantments::FROST_WALKER());
	}

	/**
	 * Ticks the entity's air supply, consuming it when underwater and regenerating it when out of water.
	 */
	protected function doAirSupplyTick(int $tickDiff) : bool{
		return $this->applyAirSupplyTick($tickDiff, $this->getAirSupplyTicks(), $this->canBreathe());
	}

	/**
	 * The rest of {@link Living::doAirSupplyTick()} once the air supply and canBreathe() are known (asked in that order).
	 */
	private function applyAirSupplyTick(int $tickDiff, int $ticks, bool $canBreathe) : bool{
		$oldTicks = $ticks;
		if(!$canBreathe){
			$this->setBreathing(false);

			if(($respirationLevel = $this->armorInventory->getHelmet()->getEnchantmentLevel(VanillaEnchantments::RESPIRATION())) <= 0 ||
				Utils::getRandomFloat() <= (1 / ($respirationLevel + 1))
			){
				$ticks -= $tickDiff;
				if($ticks <= -20){
					$ticks = 0;
					$this->onAirExpired();
				}
			}
		}elseif(!$this->isBreathing()){
			if($ticks < ($max = $this->getMaxAirSupplyTicks())){
				$ticks += $tickDiff * 4; //a full 15 s supply comes back in 3.75 s (player.json minecraft:breathable inhale_time)
			}
			if($ticks >= $max){
				$ticks = $max;
				$this->setBreathing(true);
			}
		}

		if($ticks !== $oldTicks){
			$this->setAirSupplyTicks($ticks);
		}

		return $ticks !== $oldTicks;
	}

	/**
	 * Returns whether the entity can currently breathe.
	 */
	public function canBreathe() : bool{
		//checked every tick: most entities have no effect at all, then the three effect lookups (all false) are skipped;
		//the other checks keep their order
		$effects = $this->effectManager;
		$hasEffects = count($effects->all()) > 0;
		return ($hasEffects && ($effects->has(VanillaEffects::WATER_BREATHING()) || $effects->has(VanillaEffects::CONDUIT_POWER()))) || !$this->isUnderwater() ||
			($hasEffects && $effects->has(VanillaEffects::BREATH_OF_THE_NAUTILUS())) ||
			($this->getVehicle()?->givesUnderwaterBreathingTo($this) ?? false) || $this->isEyeInBubbleColumn();
	}

	/**
	 * {@link Living::canBreathe()} with the block at the eyes already read (see entityBaseTick()): the same checks in the
	 * same order.
	 */
	private function canBreatheAtEye(World $world, int $eyeX, int $eyeY, int $eyeZ, int $eyeFlags) : bool{
		$effects = $this->effectManager;
		$hasEffects = count($effects->all()) > 0;
		return ($hasEffects && ($effects->has(VanillaEffects::WATER_BREATHING()) || $effects->has(VanillaEffects::CONDUIT_POWER()))) || !$this->isUnderwaterAtEye($world, $eyeX, $eyeY, $eyeZ, $eyeFlags) ||
			($hasEffects && $effects->has(VanillaEffects::BREATH_OF_THE_NAUTILUS())) ||
			($this->getVehicle()?->givesUnderwaterBreathingTo($this) ?? false) || $this->isEyeInBubbleColumn();
	}

	/**
	 * Returns whether the entity's class keeps the suffocation and air supply checks of Living and Entity, which the base
	 * tick then runs with one read of the block at the eyes (see entityBaseTick()).
	 */
	private static function keepsEyeChecks(Living $entity) : bool{
		foreach([
			"isInsideOfSolid" => Entity::class,
			"isUnderwater" => Entity::class,
			"doAirSupplyTick" => Living::class,
			"getAirSupplyTicks" => Living::class,
			"canBreathe" => Living::class
		] as $method => $class){
			if((new \ReflectionMethod($entity, $method))->getDeclaringClass()->getName() !== $class){
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the entity's eyes are in a bubble column, where air-breathing mobs and players get their air back as if
	 * they were out of the water (vanilla).
	 */
	protected function isEyeInBubbleColumn() : bool{
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y + $this->getEyeHeight());
		$z = (int) floor($this->location->z);
		return (self::blockFlagsAt($this->getWorld(), $x, $y, $z) & self::BLOCK_FLAG_BUBBLE_COLUMN) !== 0 &&
			$this->getWorld()->getBlockAt($x, $y, $z) instanceof BubbleColumn;
	}

	/**
	 * Returns whether the entity is currently breathing or not. If this is false, the entity's air supply will be used.
	 */
	public function isBreathing() : bool{
		return $this->breathing;
	}

	/**
	 * Sets whether the entity is currently breathing. If false, it will cause the entity's air supply to be used.
	 * For players, this also shows the oxygen bar.
	 */
	public function setBreathing(bool $value = true) : void{
		$this->breathing = $value;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns the number of ticks remaining in the entity's air supply. Note that the entity may survive longer than
	 * this amount of time without damage due to enchantments such as Respiration.
	 */
	public function getAirSupplyTicks() : int{
		return $this->breathTicks;
	}

	/**
	 * Sets the number of air ticks left in the entity's air supply.
	 */
	public function setAirSupplyTicks(int $ticks) : void{
		$this->breathTicks = $ticks;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns the maximum amount of air ticks the entity's air supply can contain.
	 */
	public function getMaxAirSupplyTicks() : int{
		return $this->maxBreathTicks;
	}

	/**
	 * Sets the maximum amount of air ticks the air supply can hold.
	 */
	public function setMaxAirSupplyTicks(int $ticks) : void{
		$this->maxBreathTicks = $ticks;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Called when the entity's air supply ticks reaches -20 or lower. The entity will usually take damage at this point
	 * and then the supply is reset to 0, so this method will be called roughly every second.
	 */
	public function onAirExpired() : void{
		$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 2);
		$this->attack($ev);
	}

	/**
	 * @return Item[]
	 */
	public function getDrops() : array{
		return [];
	}

	/**
	 * Returns the amount of XP this mob will drop on death.
	 */
	public function getXpDropAmount() : int{
		return 0;
	}

	/**
	 * @param true[] $transparent
	 * @phpstan-param array<int, true> $transparent
	 *
	 * @return Block[]
	 */
	public function getLineOfSight(int $maxDistance, int $maxLength = 0, array $transparent = []) : array{
		if($maxDistance > 120){
			$maxDistance = 120;
		}

		if(count($transparent) === 0){
			$transparent = null;
		}

		$blocks = [];
		$nextIndex = 0;

		foreach(VoxelRayTrace::inDirection($this->location->add(0, $this->size->getEyeHeight(), 0), $this->getDirectionVector(), $maxDistance) as $vector3){
			$block = $this->getWorld()->getBlockAt($vector3->x, $vector3->y, $vector3->z);
			$blocks[$nextIndex++] = $block;

			if($maxLength !== 0 && count($blocks) > $maxLength){
				array_shift($blocks);
				--$nextIndex;
			}

			$id = $block->getTypeId();

			if($transparent === null){
				if($id !== BlockTypeIds::AIR){
					break;
				}
			}else{
				if(!isset($transparent[$id])){
					break;
				}
			}
		}

		return $blocks;
	}

	/**
	 * @param true[] $transparent
	 * @phpstan-param array<int, true> $transparent
	 */
	public function getTargetBlock(int $maxDistance, array $transparent = []) : ?Block{
		$line = $this->getLineOfSight($maxDistance, 1, $transparent);
		if(count($line) > 0){
			return array_shift($line);
		}

		return null;
	}

	/**
	 * Changes the entity's yaw and pitch to make it look at the specified Vector3 position. For mobs, this will cause
	 * their heads to turn.
	 */
	public function lookAt(Vector3 $target) : void{
		$xDist = $target->x - $this->location->x;
		$zDist = $target->z - $this->location->z;

		$horizontal = sqrt($xDist ** 2 + $zDist ** 2);
		$vertical = $target->y - ($this->location->y + $this->getEyeHeight());
		$pitch = -atan2($vertical, $horizontal) / M_PI * 180; //negative is up, positive is down

		$yaw = atan2($zDist, $xDist) / M_PI * 180 - 90;
		if($yaw < 0){
			$yaw += 360.0;
		}

		$this->setRotation($yaw, $pitch);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);

		$networkSession = $player->getNetworkSession();
		$networkSession->getEntityEventBroadcaster()->onMobArmorChange([$networkSession], $this);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$visibleEffects = [];
		foreach ($this->effectManager->all() as $effect) {
			if (!$effect->isVisible() || !$effect->getType()->hasBubbles()) {
				continue;
			}
			$visibleEffects[EffectIdMap::getInstance()->toId($effect->getType())] = $effect->isAmbient();
		}

		//TODO: HACK! the client may not be able to identify effects if they are not sorted.
		ksort($visibleEffects, SORT_NUMERIC);

		$effectsData = 0;
		$packedEffectsCount = 0;
		foreach ($visibleEffects as $effectId => $isAmbient) {
			$effectsData = ($effectsData << 7) |
				(($effectId & 0x3f) << 1) | //Why not use 7 bits instead of only 6? mojang...
				($isAmbient ? 1 : 0);

			if (++$packedEffectsCount >= 8) {
				break;
			}
		}
		$properties->setLong(EntityMetadataProperties::VISIBLE_MOB_EFFECTS, $effectsData);

		$properties->setShort(EntityMetadataProperties::AIR, $this->breathTicks);
		$properties->setShort(EntityMetadataProperties::MAX_AIR, $this->maxBreathTicks);
		$properties->setFloat(EntityMetadataProperties::FREEZING_EFFECT_STRENGTH, $this->ticksFrozen / self::FREEZE_TICKS);

		$properties->setGenericFlag(EntityMetadataFlags::BREATHING, $this->breathing);
		$properties->setGenericFlag(EntityMetadataFlags::SNEAKING, $this->sneaking);
		$properties->setGenericFlag(EntityMetadataFlags::SPRINTING, $this->sprinting);
		$properties->setGenericFlag(EntityMetadataFlags::GLIDING, $this->gliding);
		$properties->setGenericFlag(EntityMetadataFlags::SWIMMING, $this->swimming);
		$properties->setGenericFlag(EntityMetadataFlags::CRAWLING, $this->crawling);
	}

	protected function onDispose() : void{
		$this->armorInventory->removeAllViewers();
		$this->effectManager->getEffectAddHooks()->clear();
		$this->effectManager->getEffectRemoveHooks()->clear();
		parent::onDispose();
	}

	protected function destroyCycles() : void{
		$this->recentAttacker = null;
		unset(
			$this->armorInventory,
			$this->effectManager
		);
		parent::destroyCycles();
	}
}
