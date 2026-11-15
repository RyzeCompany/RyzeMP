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

use pocketmine\block\ActivatorRail;
use pocketmine\block\Block;
use pocketmine\block\PoweredRail;
use pocketmine\block\Rail;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\StraightOnlyRail;
use pocketmine\block\utils\DetectorRailActivator;
use pocketmine\block\Water;
use pocketmine\data\bedrock\block\BlockLegacyMetadata;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\GameRules;
use function abs;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function fmod;
use function max;
use function min;
use function sin;
use function sqrt;
use const M_PI;

/**
 * Base of all minecarts: rail physics, damage, activator rails, pushing and the network data shared by every cart.
 *
 * The rail physics are vanilla's (Java OldMinecartBehavior, the default movement, which Bedrock carts also follow):
 * carts snap onto the rail line, slide down slopes, keep 99.7% (ridden) or 96% (empty) of their speed per tick,
 * powered rails accelerate them by 0.06 per tick (and kick stopped carts away from a solid block), unpowered powered
 * rails brake them, and the top speed is 0.4 blocks per tick (8 m/s), 0.2 in water. Off rails they fall like other
 * entities and keep half of their speed on the ground.
 *
 * Hits add 10 x the damage to an accumulated "damage" value that decays by 1 per tick: the cart breaks above 40 (a few
 * quick punches), at once when hit by a creative player (without drops).
 */
abstract class AbstractMinecart extends Entity implements DetectorRailActivator{
	public const TAG_DISPLAY_STATE = "DisplayState"; //TAG_Compound (block state, custom display block)
	public const TAG_DISPLAY_OFFSET = "DisplayOffset"; //TAG_Int
	public const TAG_FLIPPED = "FlippedRotation"; //TAG_Byte

	/** Network position of a cart: Bedrock clients place minecarts by their middle, 0.35 above the bottom. */
	public const NETWORK_Y_OFFSET = 0.35;
	/**
	 * Network position of a cart on a rail, above the bottom: BDS sends rail.y + 0.5 on flat rails and rail.y + 1.5 - d
	 * along slopes, half a sixteenth higher than the Java cart that rests 1/16 above the rail plus the 0.35 offset.
	 */
	public const NETWORK_Y_OFFSET_ON_RAILS = 0.4375;

	public const MAX_SPEED = 0.4;
	public const MAX_SPEED_IN_WATER = 0.2;
	/** Acceleration of a powered rail on a moving cart, per tick. */
	public const POWERED_RAIL_BOOST = 0.06;
	/** Speed a powered rail gives a stopped cart next to a solid block. */
	public const POWERED_RAIL_START_SPEED = 0.02;
	/** Speed added down a slope per tick. */
	public const SLOPE_ACCELERATION = 0.0078125;
	/** Accumulated damage above which the cart breaks. */
	public const BREAK_DAMAGE = 40.0;
	/** Ticks between two position resyncs of a standing cart with a player close to it. */
	public const RESYNC_INTERVAL = 40;
	/** Distance of the players for which a standing cart resyncs its position. */
	public const RESYNC_DISTANCE = 8.0;
	/** Ticks a passenger ejected by an activator rail can't be picked up again. */
	public const BOARDING_COOLDOWN_TICKS = 60;

	/**
	 * Exits of each rail shape (BlockLegacyMetadata::RAIL_*): the two neighbouring block offsets the rail connects,
	 * dy -1 meaning the lower end of a slope (vanilla AbstractMinecart.EXITS).
	 */
	private const EXITS = [
		BlockLegacyMetadata::RAIL_STRAIGHT_NORTH_SOUTH => [[0, 0, -1], [0, 0, 1]],
		BlockLegacyMetadata::RAIL_STRAIGHT_EAST_WEST => [[-1, 0, 0], [1, 0, 0]],
		BlockLegacyMetadata::RAIL_ASCENDING_EAST => [[-1, -1, 0], [1, 0, 0]],
		BlockLegacyMetadata::RAIL_ASCENDING_WEST => [[-1, 0, 0], [1, -1, 0]],
		BlockLegacyMetadata::RAIL_ASCENDING_NORTH => [[0, 0, -1], [0, -1, 1]],
		BlockLegacyMetadata::RAIL_ASCENDING_SOUTH => [[0, -1, -1], [0, 0, 1]],
		BlockLegacyMetadata::RAIL_CURVE_SOUTHEAST => [[0, 0, 1], [1, 0, 0]],
		BlockLegacyMetadata::RAIL_CURVE_SOUTHWEST => [[0, 0, 1], [-1, 0, 0]],
		BlockLegacyMetadata::RAIL_CURVE_NORTHWEST => [[0, 0, -1], [-1, 0, 0]],
		BlockLegacyMetadata::RAIL_CURVE_NORTHEAST => [[0, 0, -1], [1, 0, 0]],
	];

	protected int $hurtTime = 0;
	protected int $hurtDirection = 1;
	protected float $damage = 0.0;
	protected bool $flipped = false;
	protected bool $onRails = false;
	protected ?Block $customDisplayBlock = null;
	protected ?int $customDisplayOffset = null;
	private bool $broken = false;
	/** Set while this cart moves itself, see {@link AbstractMinecart::move()}. */
	private bool $physicsMove = false;
	private ?RiderInput $riderInput = null;
	/**
	 * Entities that may not be picked up yet (runtime ID => server tick), e.g. just ejected by an activator rail.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $boardingCooldowns = [];

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.7, 0.98); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->flipped = $nbt->getByte(self::TAG_FLIPPED, 0) !== 0;
		if(($displayTag = $nbt->getCompoundTag(self::TAG_DISPLAY_STATE)) !== null){
			try{
				$stateData = GlobalBlockStateHandlers::getUpgrader()->upgradeBlockStateNbt($displayTag);
				$this->customDisplayBlock = RuntimeBlockStateRegistry::getInstance()->fromStateId(GlobalBlockStateHandlers::getDeserializer()->deserialize($stateData));
			}catch(BlockStateDeserializeException){
				$this->customDisplayBlock = null;
			}
		}
		if(($offsetTag = $nbt->getTag(self::TAG_DISPLAY_OFFSET)) instanceof IntTag){
			$this->customDisplayOffset = $offsetTag->getValue();
		}
		if($this->motion->lengthSquared() === 0.0){
			//Bedrock clients run their own physics (pushes, rails) on vehicles without NO_AI, and nothing corrects them
			//while the server's cart stands still. Entity::updateMovement() only sets the flag when the motion changes,
			//so a freshly placed cart would otherwise drift away on the clients.
			$this->setNoClientPredictions(true);
		}
		[$bx, $by, $bz] = $this->getCurrentRailPos();
		$this->onRails = self::getRailShape($this->getWorld()->getBlockAt($bx, $by, $bz)) !== null;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_FLIPPED, $this->flipped ? 1 : 0);
		if($this->customDisplayBlock !== null){
			$nbt->setTag(self::TAG_DISPLAY_STATE, GlobalBlockStateHandlers::getSerializer()->serialize($this->customDisplayBlock->getStateId())->toNbt());
		}
		if($this->customDisplayOffset !== null){
			$nbt->setInt(self::TAG_DISPLAY_OFFSET, $this->customDisplayOffset);
		}
		return $nbt;
	}

	/**
	 * Returns the type of this cart.
	 */
	abstract public function getMinecartType() : MinecartType;

	/**
	 * Returns the items this cart drops when broken by a survival player (Bedrock drops the minecart and the block
	 * separately). Container contents are dropped separately.
	 *
	 * @return Item[]
	 */
	public function getCartDrops() : array{
		return [VanillaItems::MINECART()];
	}

	public function getPickedItem() : ?Item{
		return $this->getMinecartType()->getItem();
	}

	public function getDetectorRailComparatorOutput() : int{
		return 0;
	}

	// ---- display block ----

	/**
	 * Returns the custom block shown inside the cart (vanilla DisplayState), or null for the default look.
	 */
	public function getCustomDisplayBlock() : ?Block{
		return $this->customDisplayBlock === null ? null : clone $this->customDisplayBlock;
	}

	public function setCustomDisplayBlock(?Block $block) : void{
		$this->customDisplayBlock = $block === null ? null : clone $block;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Height of the display block in sixteenths of a block (vanilla default 6), or null for the default.
	 */
	public function getCustomDisplayOffset() : ?int{
		return $this->customDisplayOffset;
	}

	public function setCustomDisplayOffset(?int $offset) : void{
		$this->customDisplayOffset = $offset;
		$this->networkPropertiesDirty = true;
	}

	// ---- state ----

	public function isOnRails() : bool{
		return $this->onRails;
	}

	public function isFlipped() : bool{
		return $this->flipped;
	}

	public function getHurtTime() : int{ return $this->hurtTime; }

	public function getHurtDirection() : int{ return $this->hurtDirection; }

	/**
	 * Returns the accumulated damage (the cart breaks above {@link AbstractMinecart::BREAK_DAMAGE}).
	 */
	public function getDamage() : float{ return $this->damage; }

	public function setDamage(float $damage) : void{
		$this->damage = max(0.0, $damage);
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Shakes the cart like a hit does (vanilla hurt animation).
	 */
	protected function animateHurt() : void{
		$this->hurtDirection = -$this->hurtDirection;
		$this->hurtTime = 10;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Returns whether the cart is in water (slower, lower top speed).
	 */
	public function isInWater() : bool{
		return $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y + 0.1), (int) floor($this->location->z)) instanceof Water;
	}

	public function getMaxSpeed() : float{
		return $this->isInWater() ? self::MAX_SPEED_IN_WATER : self::MAX_SPEED;
	}

	// ---- damage ----

	public function attack(EntityDamageEvent $source) : void{
		if($this->broken || $this->isFlaggedForDespawn()){
			return;
		}
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
			return;
		}
		$source->call();
		if($source->isCancelled()){
			return;
		}
		$this->setLastDamageCause($source);
		if($this->onDamaged($source)){
			return;
		}
		$this->animateHurt();
		$this->setDamage($this->damage + $source->getFinalDamage() * 10);

		$attacker = $source instanceof EntityDamageByEntityEvent ? $source->getDamager() : null;
		if($attacker instanceof Player && $attacker->isCreative()){
			$this->breakCart(false);
		}elseif($this->damage > self::BREAK_DAMAGE || $this->shouldSourceDestroy($source)){
			$this->breakCart(true);
		}
	}

	/**
	 * Called with an uncancelled damage before it is applied. Returns true if the damage was fully handled (e.g. a TNT
	 * minecart exploding).
	 */
	protected function onDamaged(EntityDamageEvent $source) : bool{
		return false;
	}

	/**
	 * Whether the damage destroys the cart whatever its accumulated damage.
	 */
	protected function shouldSourceDestroy(EntityDamageEvent $source) : bool{
		return false;
	}

	/**
	 * Breaks the cart: drops its items (if $drops and the doentitydrops game rule allows it) and removes it. Container contents are dropped by
	 * {@link AbstractMinecart::onDeath()} in any case.
	 */
	public function breakCart(bool $drops) : void{
		if($this->broken){
			return;
		}
		$this->broken = true;
		if($drops && GameRules::allowsEntityDrops($this->getWorld())){
			$world = $this->getWorld();
			$pos = $this->location->add(0, 0.3, 0);
			foreach($this->getCartDrops() as $item){
				if($this->hasCustomName() && $item->equals(VanillaItems::MINECART(), false, false)){
					$item->setCustomName($this->getNameTag());
				}
				$world->dropItem($pos, $item);
			}
		}
		$this->kill();
	}

	private function hasCustomName() : bool{
		return $this->getNameTag() !== "";
	}

	public function isBroken() : bool{
		return $this->broken;
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		return true;
	}

	// ---- interaction ----

	/**
	 * Called every tick with the input of the player riding this cart.
	 */
	public function handleRiderInput(Player $rider, RiderInput $input) : void{
		$this->riderInput = $input;
	}

	// ---- ticking ----

	public function hasMovementUpdate() : bool{
		//carts check their rails, pushes and passengers every tick, like vanilla
		return $this->isAlive() && !$this->isFlaggedForDespawn();
	}

	protected function tryChangeMovement() : void{
		$this->physicsMove = true;
		try{
			$this->tickMinecart();
		}finally{
			$this->physicsMove = false;
		}
	}

	/**
	 * Carts move themselves in {@link AbstractMinecart::tryChangeMovement()}; the generic movement step of
	 * {@link Entity::onUpdate()} is skipped.
	 */
	protected function move(float $dx, float $dy, float $dz) : void{
		if($this->physicsMove){
			parent::move($dx, $dy, $dz);
		}
	}

	/**
	 * One tick of vanilla minecart physics (AbstractMinecart.tick() + OldMinecartBehavior.tick()).
	 */
	protected function tickMinecart() : void{
		if($this->hurtTime > 0){
			--$this->hurtTime;
			$this->networkPropertiesDirty = true;
		}
		if($this->damage > 0){
			$this->setDamage($this->damage - 1);
		}

		$oldX = $this->location->x;
		$oldZ = $this->location->z;
		$oldYaw = $this->location->yaw;

		$inWater = $this->isInWater();
		$this->motion = $this->motion->withComponents(null, $this->motion->y - ($inWater ? 0.005 : $this->gravity), null);

		[$bx, $by, $bz] = $this->getCurrentRailPos();
		$world = $this->getWorld();
		$block = $world->getBlockAt($bx, $by, $bz);
		$shape = self::getRailShape($block);
		if($this->onRails !== ($shape !== null)){
			//the network position is higher on a rail
			$this->onRails = $shape !== null;
			$this->forceMovementUpdate = true;
		}
		if($shape !== null){
			$this->moveAlongTrack($bx, $by, $bz, $block, $shape, $inWater);
			if($block instanceof ActivatorRail){
				$this->onActivatorRail($block->isPowered());
			}
		}else{
			$this->comeOffTrack();
		}

		//rotation: along the movement, flipped when turning around
		$pitch = 0.0;
		$yaw = $oldYaw;
		$xDiff = $oldX - $this->location->x;
		$zDiff = $oldZ - $this->location->z;
		if($xDiff * $xDiff + $zDiff * $zDiff > 0.001){
			$yaw = atan2($zDiff, $xDiff) * 180 / M_PI;
			if($this->flipped){
				$yaw += 180;
			}
		}
		$rotDiff = self::wrapDegrees($yaw - $oldYaw);
		if($rotDiff < -170 || $rotDiff >= 170){
			$yaw += 180;
			$this->flipped = !$this->flipped;
		}
		$yaw = fmod($yaw, 360);
		if($yaw < 0){
			$yaw += 360;
		}
		$this->location->yaw = $yaw;
		$this->location->pitch = $pitch;

		$this->pushAndPickupEntities();
		$this->riderInput = null;
	}

	private static function wrapDegrees(float $degrees) : float{
		$degrees = fmod($degrees, 360);
		if($degrees >= 180){
			$degrees -= 360;
		}
		if($degrees < -180){
			$degrees += 360;
		}
		return $degrees;
	}

	/**
	 * Returns the rail shape (BlockLegacyMetadata::RAIL_*) of a rail block, or null if the block isn't a rail.
	 */
	public static function getRailShape(Block $block) : ?int{
		if($block instanceof Rail || $block instanceof StraightOnlyRail){
			return $block->getShape();
		}
		return null;
	}

	/**
	 * Returns whether the rail shape is a slope.
	 */
	public static function isSlope(int $shape) : bool{
		return self::EXITS[$shape][0][1] !== 0 || self::EXITS[$shape][1][1] !== 0;
	}

	/**
	 * Block position of the rail the cart is on: the block at its feet, or the one below if that is a rail.
	 *
	 * @return int[]
	 * @phpstan-return array{int, int, int}
	 */
	public function getCurrentRailPos() : array{
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		if(self::getRailShape($this->getWorld()->getBlockAt($x, $y - 1, $z)) !== null){
			--$y;
		}
		return [$x, $y, $z];
	}

	/**
	 * Point of the rail line under the given position (vanilla OldMinecartBehavior.getPos()), or null if there is no
	 * rail there.
	 */
	public function getRailPoint(float $x, float $y, float $z) : ?Vector3{
		$world = $this->getWorld();
		$xt = (int) floor($x);
		$yt = (int) floor($y);
		$zt = (int) floor($z);
		if(self::getRailShape($world->getBlockAt($xt, $yt - 1, $zt)) !== null){
			--$yt;
		}
		$shape = self::getRailShape($world->getBlockAt($xt, $yt, $zt));
		if($shape === null){
			return null;
		}
		[$exit0, $exit1] = self::EXITS[$shape];
		$x0 = $xt + 0.5 + $exit0[0] * 0.5;
		$y0 = $yt + 0.0625 + $exit0[1] * 0.5;
		$z0 = $zt + 0.5 + $exit0[2] * 0.5;
		$x1 = $xt + 0.5 + $exit1[0] * 0.5;
		$y1 = $yt + 0.0625 + $exit1[1] * 0.5;
		$z1 = $zt + 0.5 + $exit1[2] * 0.5;
		$xD = $x1 - $x0;
		$yD = ($y1 - $y0) * 2;
		$zD = $z1 - $z0;
		if($xD === 0.0){
			$progress = $z - $zt;
		}elseif($zD === 0.0){
			$progress = $x - $xt;
		}else{
			$progress = (($x - $x0) * $xD + ($z - $z0) * $zD) * 2;
		}
		$x = $x0 + $xD * $progress;
		$y = $y0 + $yD * $progress;
		$z = $z0 + $zD * $progress;
		if($yD < 0){
			$y += 1;
		}elseif($yD > 0){
			$y += 0.5;
		}
		return new Vector3($x, $y, $z);
	}

	/**
	 * Vanilla OldMinecartBehavior.moveAlongTrack().
	 */
	protected function moveAlongTrack(int $bx, int $by, int $bz, Block $block, int $shape, bool $inWater) : void{
		$this->resetFallDistance();
		$x = $this->location->x;
		$z = $this->location->z;
		$oldPos = $this->getRailPoint($x, $this->location->y, $z);
		$y = (float) $by;

		$powerTrack = false;
		$haltTrack = false;
		if($block instanceof PoweredRail){
			$powerTrack = $block->isPowered();
			$haltTrack = !$powerTrack;
		}

		$slide = self::SLOPE_ACCELERATION * ($inWater ? 0.2 : 1.0);
		$mx = $this->motion->x;
		$my = $this->motion->y;
		$mz = $this->motion->z;
		switch($shape){
			case BlockLegacyMetadata::RAIL_ASCENDING_EAST:
				$mx -= $slide;
				++$y;
				break;
			case BlockLegacyMetadata::RAIL_ASCENDING_WEST:
				$mx += $slide;
				++$y;
				break;
			case BlockLegacyMetadata::RAIL_ASCENDING_NORTH:
				$mz += $slide;
				++$y;
				break;
			case BlockLegacyMetadata::RAIL_ASCENDING_SOUTH:
				$mz -= $slide;
				++$y;
				break;
		}

		[$exit0, $exit1] = self::EXITS[$shape];
		$xD = (float) ($exit1[0] - $exit0[0]);
		$zD = (float) ($exit1[2] - $exit0[2]);
		$length = sqrt($xD * $xD + $zD * $zD);
		if($mx * $xD + $mz * $zD < 0){
			$xD = -$xD;
			$zD = -$zD;
		}
		$pow = min(2.0, sqrt($mx * $mx + $mz * $mz));
		$mx = $pow * $xD / $length;
		$mz = $pow * $zD / $length;

		//a player riding the cart pushes it forwards when it (almost) stands still
		$rider = $this->getControllingPassenger();
		$input = $this->riderInput;
		if($rider instanceof Player && $input !== null && $input->forward > 0 && $mx * $mx + $mz * $mz < 0.01){
			$yaw = deg2rad($input->yaw);
			$mx += -sin($yaw) * 0.1;
			$mz += cos($yaw) * 0.1;
			$haltTrack = false;
		}

		if($haltTrack){
			if(sqrt($mx * $mx + $mz * $mz) < 0.03){
				$mx = $my = $mz = 0.0;
			}else{
				$mx *= 0.5;
				$my = 0.0;
				$mz *= 0.5;
			}
		}

		$x0 = $bx + 0.5 + $exit0[0] * 0.5;
		$z0 = $bz + 0.5 + $exit0[2] * 0.5;
		$x1 = $bx + 0.5 + $exit1[0] * 0.5;
		$z1 = $bz + 0.5 + $exit1[2] * 0.5;
		$lxD = $x1 - $x0;
		$lzD = $z1 - $z0;
		if($lxD === 0.0){
			$progress = $z - $bz;
		}elseif($lzD === 0.0){
			$progress = $x - $bx;
		}else{
			$progress = (($x - $x0) * $lxD + ($z - $z0) * $lzD) * 2;
		}
		$x = $x0 + $lxD * $progress;
		$z = $z0 + $lzD * $progress;
		$this->setPosition(new Vector3($x, $y, $z));

		$scale = count($this->passengers) > 0 ? 0.75 : 1.0;
		$maxSpeed = $inWater ? self::MAX_SPEED_IN_WATER : self::MAX_SPEED;
		$this->motion = new Vector3($mx, $my, $mz);
		$this->move(
			max(-$maxSpeed, min($maxSpeed, $scale * $mx)),
			0.0,
			max(-$maxSpeed, min($maxSpeed, $scale * $mz))
		);
		if($this->closed){
			return;
		}
		$mx = $this->motion->x;
		$my = $this->motion->y;
		$mz = $this->motion->z;

		$cx = $this->location->x;
		$cy = $this->location->y;
		$cz = $this->location->z;
		if($exit0[1] !== 0 && (int) floor($cx) - $bx === $exit0[0] && (int) floor($cz) - $bz === $exit0[2]){
			$cy += $exit0[1];
			$this->setPosition(new Vector3($cx, $cy, $cz));
		}elseif($exit1[1] !== 0 && (int) floor($cx) - $bx === $exit1[0] && (int) floor($cz) - $bz === $exit1[2]){
			$cy += $exit1[1];
			$this->setPosition(new Vector3($cx, $cy, $cz));
		}

		//natural slowdown (the vertical speed is dropped on rails)
		$slowdown = $this->getSlowdownFactor($inWater);
		$mx *= $slowdown;
		$my = 0.0;
		$mz *= $slowdown;

		$newPos = $this->getRailPoint($cx, $cy, $cz);
		if($newPos !== null && $oldPos !== null){
			$speed = ($oldPos->y - $newPos->y) * 0.05;
			$horizontal = sqrt($mx * $mx + $mz * $mz);
			if($horizontal > 0){
				$mx *= ($horizontal + $speed) / $horizontal;
				$mz *= ($horizontal + $speed) / $horizontal;
			}
			$this->setPosition(new Vector3($cx, $newPos->y, $cz));
		}

		$xn = (int) floor($cx);
		$zn = (int) floor($cz);
		if($xn !== $bx || $zn !== $bz){
			$horizontal = sqrt($mx * $mx + $mz * $mz);
			$mx = $horizontal * ($xn - $bx);
			$mz = $horizontal * ($zn - $bz);
		}

		if($powerTrack){
			$horizontal = sqrt($mx * $mx + $mz * $mz);
			if($horizontal > 0.01){
				$mx += $mx / $horizontal * self::POWERED_RAIL_BOOST;
				$mz += $mz / $horizontal * self::POWERED_RAIL_BOOST;
			}elseif($shape === BlockLegacyMetadata::RAIL_STRAIGHT_EAST_WEST){
				if($this->isConductorAt($bx - 1, $by, $bz)){
					$mx = self::POWERED_RAIL_START_SPEED;
				}elseif($this->isConductorAt($bx + 1, $by, $bz)){
					$mx = -self::POWERED_RAIL_START_SPEED;
				}
			}elseif($shape === BlockLegacyMetadata::RAIL_STRAIGHT_NORTH_SOUTH){
				if($this->isConductorAt($bx, $by, $bz - 1)){
					$mz = self::POWERED_RAIL_START_SPEED;
				}elseif($this->isConductorAt($bx, $by, $bz + 1)){
					$mz = -self::POWERED_RAIL_START_SPEED;
				}
			}
		}
		$this->motion = new Vector3($mx, $my, $mz);
	}

	private function isConductorAt(int $x, int $y, int $z) : bool{
		$block = $this->getWorld()->getBlockAt($x, $y, $z);
		return $block->isSolid() && $block->isRedstoneConductor();
	}

	/**
	 * Fraction of the horizontal speed kept per tick on rails (vanilla applyNaturalSlowdown()).
	 */
	protected function getSlowdownFactor(bool $inWater) : float{
		$factor = count($this->passengers) > 0 ? 0.997 : 0.96;
		return $inWater ? $factor * 0.95 : $factor;
	}

	/**
	 * Movement without rails (vanilla comeOffTrack()).
	 */
	protected function comeOffTrack() : void{
		$max = $this->getMaxSpeed();
		$m = $this->motion;
		$m = new Vector3(max(-$max, min($max, $m->x)), $m->y, max(-$max, min($max, $m->z)));
		if($this->onGround){
			$m = $m->multiply(0.5);
		}
		$this->motion = $m;
		$this->move($m->x, $m->y, $m->z);
		if(!$this->closed && !$this->onGround){
			$this->motion = $this->motion->multiply(0.95);
		}
	}

	// ---- activator rails ----

	/**
	 * Called every tick the cart is on an activator rail (vanilla activateMinecart()).
	 */
	protected function onActivatorRail(bool $powered) : void{

	}

	// ---- pushing and pick-up ----

	/**
	 * Whether entities can get in this cart (plain minecarts).
	 */
	public function isRideable() : bool{
		return false;
	}

	/**
	 * Vanilla pushAndPickupEntities(), with the Bedrock "pull_in_entities" of the rideable minecart: mobs walking into
	 * an empty cart get in.
	 */
	protected function pushAndPickupEntities() : void{
		if($this->closed){
			return;
		}
		$box = $this->boundingBox->expandedCopy(0.2, 0, 0.2);
		$tick = $this->server->getTick();
		foreach($this->boardingCooldowns as $id => $until){
			if($until <= $tick){
				unset($this->boardingCooldowns[$id]);
			}
		}
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if($entity->isClosed() || !$entity->isAlive() || $entity->isFlaggedForDespawn() || $this->hasPassenger($entity) || $entity->hasPassenger($this)){
				continue;
			}
			if($entity instanceof AbstractMinecart){
				$entity->pushBy($this);
				continue;
			}
			if(!$entity instanceof Living || ($entity instanceof Player && $entity->isSpectator())){
				continue;
			}
			if($entity->getVehicle() !== null){
				continue;
			}
			if($this->canPickUp($entity) && $entity->mount($this)){
				continue;
			}
			//players and mobs push carts with their own collision box (vanilla LivingEntity.pushEntities()): the 0.2
			//margin is only for carts. With it a player standing next to a cart pushed it along its rail (off the
			//rail when it was the last piece) as soon as it was placed.
			if($entity->getBoundingBox()->intersectsWith($this->boundingBox)){
				$this->pushBy($entity);
			}
		}
	}

	/**
	 * Whether the entity gets in this cart when they bump into each other.
	 */
	protected function canPickUp(Entity $entity) : bool{
		return $this->isRideable() &&
			count($this->passengers) === 0 &&
			$entity instanceof Mob &&
			!$entity instanceof EnderDragon && !$entity instanceof Wither &&
			!isset($this->boardingCooldowns[$entity->getId()]) &&
			$this->canAddPassenger($entity);
	}

	/**
	 * Prevents the entity from being picked up by this cart for the given number of ticks.
	 */
	public function setBoardingCooldown(Entity $entity, int $ticks = self::BOARDING_COOLDOWN_TICKS) : void{
		$until = $this->server->getTick() + $ticks;
		$this->boardingCooldowns[$entity->getId()] = max($until, $this->boardingCooldowns[$entity->getId()] ?? 0);
	}

	/**
	 * Pushes this cart away from the entity (vanilla AbstractMinecart.push(Entity)): carts push each other along their
	 * rails, other entities get a quarter of the push.
	 */
	public function pushBy(Entity $entity) : void{
		if($this->hasPassenger($entity)){
			return;
		}
		$xa = $entity->location->x - $this->location->x;
		$za = $entity->location->z - $this->location->z;
		$dd = $xa * $xa + $za * $za;
		if($dd < 1.0E-4){
			return;
		}
		$dd = sqrt($dd);
		$xa /= $dd;
		$za /= $dd;
		$pow = min(1.0, 1 / $dd);
		$xa *= $pow * 0.1 * 0.5;
		$za *= $pow * 0.1 * 0.5;
		if($entity instanceof AbstractMinecart){
			$this->pushOtherMinecart($entity, $xa, $za);
			return;
		}
		$this->motion = $this->motion->add(-$xa, 0, -$za);
		if($entity instanceof Living && !$entity instanceof Player){
			$entity->addMotion($xa / 4, 0, $za / 4);
		}
	}

	private function pushOtherMinecart(AbstractMinecart $other, float $xa, float $za) : void{
		$xo = $other->location->x - $this->location->x;
		$zo = $other->location->z - $this->location->z;
		$length = sqrt($xo * $xo + $zo * $zo);
		if($length < 1.0E-4){
			return;
		}
		$yaw = deg2rad($this->location->yaw);
		$dot = abs(($xo / $length) * cos($yaw) + ($zo / $length) * sin($yaw));
		if($dot < 0.8){
			return;
		}
		$movement = $this->motion;
		$otherMovement = $other->motion;
		$xdd = ($otherMovement->x + $movement->x) / 2;
		$zdd = ($otherMovement->z + $movement->z) / 2;
		$this->motion = new Vector3($movement->x * 0.2 + $xdd - $xa, $movement->y, $movement->z * 0.2 + $zdd - $za);
		$other->motion = new Vector3($otherMovement->x * 0.2 + $xdd + $xa, $otherMovement->y, $otherMovement->z * 0.2 + $zdd + $za);
	}

	protected function onPassengerRemoved(Entity $passenger) : void{
		parent::onPassengerRemoved($passenger);
		$this->setBoardingCooldown($passenger, 10);
	}

	// ---- riding ----

	public function getPassengerPosition(Entity $passenger) : Vector3{
		//vanilla seats are relative to the network position, which is the middle of the cart
		return parent::getPassengerPosition($passenger)->add(0, $this->onRails ? self::NETWORK_Y_OFFSET_ON_RAILS : self::NETWORK_Y_OFFSET, 0);
	}

	// ---- network ----

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, $this->onRails ? self::NETWORK_Y_OFFSET_ON_RAILS : self::NETWORK_Y_OFFSET, 0);
	}

	public function getHeadYaw() : float{
		//MoveActorAbsolute: the head yaw of things that aren't mobs is 0
		return 0.0;
	}

	protected function createMovementPacket() : MoveActorAbsolutePacket{
		$packet = parent::createMovementPacket();
		if($this->onRails){
			//carts on rails rest on them, although the server moves them without vertical collisions
			$packet->flags |= MoveActorAbsolutePacket::FLAG_GROUND;
		}
		return $packet;
	}

	protected function updateMovement(bool $teleport = false) : void{
		parent::updateMovement($teleport);
		if($this->ticksLived % self::RESYNC_INTERVAL !== 0 || $this->motion->lengthSquared() !== 0.0){
			return;
		}
		foreach($this->hasSpawned as $viewer){
			if($viewer->getWorld() === $this->getWorld() && $viewer->getLocation()->distanceSquared($this->location) < self::RESYNC_DISTANCE ** 2){
				//a client may have pushed a standing cart on its own (clients predict vehicle collisions): put it back
				//where the server has it every few seconds while someone is close
				$this->broadcastMovement();
				return;
			}
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::HURT_TIME, $this->hurtTime);
		$properties->setInt(EntityMetadataProperties::HURT_DIRECTION, $this->hurtDirection);
		//structural integrity: the hurt animation (minecart.animation.json) rocks the cart by 40 minus this value, so it
		//is what is left of the cart's 40 points, not the damage taken
		$properties->setInt(EntityMetadataProperties::HEALTH, (int) max(0, self::BREAK_DAMAGE - $this->damage));
		if($this->customDisplayBlock !== null){
			$properties->setInt(EntityMetadataProperties::MINECART_DISPLAY_BLOCK, TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->customDisplayBlock->getStateId()));
			$properties->setInt(EntityMetadataProperties::MINECART_DISPLAY_OFFSET, $this->customDisplayOffset ?? 6);
			$properties->setByte(EntityMetadataProperties::MINECART_HAS_DISPLAY, 1);
		}elseif($this->customDisplayOffset !== null){
			$properties->setInt(EntityMetadataProperties::MINECART_DISPLAY_OFFSET, $this->customDisplayOffset);
		}
	}

	protected function networkDataForProtocol(TypeConverter $converter, array $data) : array{
		if($this->customDisplayBlock !== null && isset($data[EntityMetadataProperties::MINECART_DISPLAY_BLOCK])){
			$data[EntityMetadataProperties::MINECART_DISPLAY_BLOCK] = new IntMetadataProperty($converter->getBlockTranslator()->internalIdToNetworkId($this->customDisplayBlock->getStateId()));
		}
		return $data;
	}

	/**
	 * Creates the cart at the position of a rail block, like a minecart item used on it (vanilla MinecartItem.useOn()):
	 * in the middle of the block, just above the rail (half a block higher on slopes).
	 */
	public static function getPlacementPosition(Block $rail) : ?Vector3{
		$shape = self::getRailShape($rail);
		if($shape === null){
			return null;
		}
		$pos = $rail->getPosition();
		return new Vector3($pos->x + 0.5, $pos->y + 0.0625 + (self::isSlope($shape) ? 0.5 : 0.0), $pos->z + 0.5);
	}

	/**
	 * Yaw of a cart standing on the given rail: along the rail (carts face along X at yaw 0, like vanilla).
	 */
	public static function getRailYaw(Block $rail) : float{
		$shape = self::getRailShape($rail);
		return match($shape){
			BlockLegacyMetadata::RAIL_STRAIGHT_NORTH_SOUTH,
			BlockLegacyMetadata::RAIL_ASCENDING_NORTH,
			BlockLegacyMetadata::RAIL_ASCENDING_SOUTH => 90.0,
			default => 0.0
		};
	}

	/**
	 * Creates a minecart of the given type (not spawned).
	 */
	public static function create(MinecartType $type, Location $location) : AbstractMinecart{
		return $type->createEntity($location);
	}

	/**
	 * Area used to find the cart in a block space (hoppers, detector rails).
	 */
	public static function blockArea(int $x, int $y, int $z) : AxisAlignedBB{
		return new AxisAlignedBB($x, $y, $z, $x + 1, $y + 1, $z + 1);
	}
}
