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

namespace pocketmine\entity\projectile;

use pocketmine\block\Water;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\FishingRod;
use pocketmine\item\Item;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\ServerProperties;
use pocketmine\world\sound\WaterSplashSound;
use function floor;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

/**
 * Fishing bobber (vanilla FishingHook). Cast with a fishing rod; it floats on water, where after 5 to 30 seconds (5
 * seconds less per level of Lure; faster in the rain, slower under a roof) a fish swims to it, then bites within 1 to 4
 * seconds and stays on the hook for 1 to 2 seconds. Reeling in while a fish bites gives an item of the fishing loot
 * table (see {@link FishingLoot}; the jungle tables when cast in a jungle biome) thrown towards the player, and 1 to 6 experience.
 * A hooked mob or item is pulled towards the player instead. The rod loses 1 durability per catch, 5 per pulled mob, 3
 * per pulled item and 2 when the bobber was stuck in a block. The bobber
 * disappears when the player stops holding the rod, dies or goes more than 32 blocks away.
 */
class FishingHook extends Projectile implements NeverSavedWithChunkEntity{
	private const MAX_DISTANCE = 32.0;

	/** @var self[] player entity ID => their bobber */
	private static array $active = [];

	private ?Entity $hookedEntity = null;
	private bool $bobbing = false;
	private int $nibble = 0;
	private int $timeUntilHooked = 0;
	private int $timeUntilLured = 0;
	/** Cast in a jungle biome: Bedrock's fishing_hook.json switches to jungle_fishing.json when it spawns. */
	private bool $jungle;
	private int $lureTicks;
	private int $luck;

	public static function getNetworkTypeId() : string{ return EntityIds::FISHING_HOOK; }

	public function __construct(Location $location, ?Entity $shootingEntity, ?Item $rod = null, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->lureTicks = $rod !== null ? 100 * $rod->getEnchantmentLevel(VanillaEnchantments::LURE()) : 0;
		$this->luck = $rod !== null ? $rod->getEnchantmentLevel(VanillaEnchantments::LUCK_OF_THE_SEA()) : 0;
		$this->jungle = MobBiomeInfo::hasTag($this->getWorld(), $this->location, "jungle");
		if($shootingEntity instanceof Player){
			if(isset(self::$active[$shootingEntity->getId()])){
				self::$active[$shootingEntity->getId()]->flagForDespawn();
			}
			self::$active[$shootingEntity->getId()] = $this;
		}
	}

	/**
	 * Returns the bobber the player has out, if any.
	 */
	public static function getActive(Player $player) : ?self{
		$hook = self::$active[$player->getId()] ?? null;
		return $hook !== null && !$hook->isClosed() && !$hook->isFlaggedForDespawn() ? $hook : null;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.08; }

	protected function getInitialGravity() : float{ return 0.03; }

	/** The hook floats on its own (see entityBaseTick()): water doesn't slow it down. */
	protected function getLiquidInertia() : float{ return 1.0 - $this->drag; }

	public function getHookedEntity() : ?Entity{
		return $this->hookedEntity;
	}

	public function isBobbing() : bool{
		return $this->bobbing;
	}

	/**
	 * Whether a fish is on the hook right now (reeling in catches it).
	 */
	public function isBiting() : bool{
		return $this->nibble > 0;
	}

	protected function onDispose() : void{
		$owner = $this->getOwningEntityId();
		if($owner !== null && (self::$active[$owner] ?? null) === $this){
			unset(self::$active[$owner]);
		}
		parent::onDispose();
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		//the bobber hooks the mob instead of hurting it
		$this->hookedEntity = $entityHit;
		$this->motion = Vector3::zero();
	}

	public function canCollideWith(Entity $entity) : bool{
		// Respect the existing fishing.pvp-enabled flag and global PvP setting.
		if($entity instanceof Player && (
			!$this->server->getConfigGroup()->getPropertyBool("fishing.pvp-enabled", true) ||
			!$this->server->getConfigGroup()->getConfigBool(ServerProperties::PVP, true)
		)){
			return false;
		}
		return $this->hookedEntity === null && !$this->bobbing && $entity->getId() !== $this->getOwningEntityId() && parent::canCollideWith($entity);
	}

	private static function randomFloat() : float{
		return mt_rand() / mt_getrandmax();
	}

	private function shouldStayOut(Player $owner) : bool{
		if(!$owner->isAlive() || $owner->isClosed() || $owner->getWorld() !== $this->getWorld()){
			return false;
		}
		if(!$owner->getInventory()->getItemInHand() instanceof FishingRod && !$owner->getOffHandInventory()->getItem(0) instanceof FishingRod){
			return false;
		}
		// Preserve previous RyzeMP's max-distance setting (bounded as before).
		$range = max(2, min(64, $owner->getServer()->getConfigGroup()->getPropertyInt("fishing.max-distance", (int) self::MAX_DISTANCE)));
		return $owner->getPosition()->distanceSquared($this->location) <= $range ** 2;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed){
			return false;
		}
		$owner = $this->getOwningEntity();
		if(!$owner instanceof Player || !$this->shouldStayOut($owner)){
			$this->flagForDespawn();
			return false;
		}

		if($this->hookedEntity !== null){
			if($this->hookedEntity->isClosed() || !$this->hookedEntity->isAlive() || $this->hookedEntity->getWorld() !== $this->getWorld()){
				$this->hookedEntity = null;
			}else{
				$pos = $this->hookedEntity->getPosition();
				$this->setPosition(new Vector3($pos->x, $pos->y + $this->hookedEntity->getSize()->getHeight() * 0.8, $pos->z));
				return true;
			}
		}

		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		$block = $world->getBlockAt($x, $y, $z);
		if($block instanceof Water){
			if(!$this->bobbing){
				$this->bobbing = true;
				$this->gravity = 0.0;
				$this->motion = $this->motion->multiply(0.3);
				$this->blockHit = null;
				$world->addSound($this->location, new WaterSplashSound(0.25));
			}
			//float at the surface (vanilla: y + motion - block y - fluid height, nudged by a random fraction)
			$surface = $y + 1 - $block->getFluidHeightPercent();
			$offset = $this->location->y + $this->motion->y - $surface;
			$this->motion = new Vector3($this->motion->x * 0.9, $this->motion->y - $offset * self::randomFloat() * 0.2, $this->motion->z * 0.9);
			$this->catchingFish($x, $y, $z, $tickDiff);
		}elseif($this->bobbing){
			//the water is gone
			$this->bobbing = false;
			$this->gravity = 0.03;
			$this->nibble = $this->timeUntilHooked = $this->timeUntilLured = 0;
		}
		return true;
	}

	/**
	 * Vanilla FishingHook.catchingFish(): counts down to the fish approaching, biting, and escaping.
	 */
	private function catchingFish(int $x, int $y, int $z, int $tickDiff) : void{
		$world = $this->getWorld();
		$speed = 1;
		if($this->isInRain() && self::randomFloat() < 0.25){
			++$speed;
		}
		if(!$world->getWeather()->isExposedToSky($x, $y + 1, $z) && self::randomFloat() < 0.5){
			--$speed;
		}
		$speed *= $tickDiff;

		if($this->nibble > 0){
			$this->nibble -= $tickDiff;
			if($this->nibble <= 0){
				//the fish got away
				$this->nibble = $this->timeUntilHooked = $this->timeUntilLured = 0;
			}
		}elseif($this->timeUntilHooked > 0){
			$this->timeUntilHooked -= $speed;
			if($this->timeUntilHooked <= 0){
				$this->nibble = mt_rand(20, 39);
				$this->motion = $this->motion->add(0, -0.4 * (0.6 + self::randomFloat() * 0.4), 0);
				$world->addSound($this->location, new WaterSplashSound(0.25));
				$this->broadcastActorEvent(ActorEvent::FISH_HOOK_HOOK);
			}elseif($this->timeUntilHooked % 4 === 0){
				$this->broadcastActorEvent(ActorEvent::FISH_HOOK_TEASE);
			}
		}elseif($this->timeUntilLured > 0){
			$this->timeUntilLured -= $speed;
			if($this->timeUntilLured <= 0){
				$this->timeUntilHooked = mt_rand(20, 80);
				$this->broadcastActorEvent(ActorEvent::FISH_HOOK_POSITION);
			}elseif($this->timeUntilLured < 20 * $tickDiff || mt_rand(0, 9) === 0){
				$this->broadcastActorEvent(ActorEvent::FISH_HOOK_BUBBLE);
			}
		}else{
			$this->timeUntilLured = mt_rand(100, 600) - $this->lureTicks;
		}
	}

	private function broadcastActorEvent(int $event) : void{
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, $event, 0, null)]);
	}

	/**
	 * Reels the line in (the player used the rod again): pulls the hooked entity, or catches the fish that is biting.
	 * Returns the durability the rod loses.
	 */
	public function retrieve(Player $player) : int{
		$damage = 0;
		$world = $this->getWorld();
		if($this->hookedEntity !== null){
			$entity = $this->hookedEntity;
			$pull = $player->getPosition()->subtractVector($entity->getPosition())->multiply(0.1);
			if($entity->setMotion($entity->getMotion()->addVector($pull)) && $entity instanceof Mob){
				$entity->carryPush();
			}
			$damage = $entity instanceof ItemEntity ? 3 : 5;
		}elseif($this->nibble > 0){
			$item = FishingLoot::roll($this->luck, $this->jungle);
			$dx = $player->getPosition()->x - $this->location->x;
			$dy = $player->getPosition()->y - $this->location->y;
			$dz = $player->getPosition()->z - $this->location->z;
			$motion = new Vector3($dx * 0.1, $dy * 0.1 + sqrt(sqrt($dx * $dx + $dy * $dy + $dz * $dz)) * 0.08, $dz * 0.1);
			$world->dropItem($this->location, $item, $motion, 0);
			$world->dropExperience($player->getPosition()->add(0, 0.5, 0.5), mt_rand(1, 6));
			$damage = 1;
		}
		if($this->blockHit !== null){
			$damage = 2;
		}
		$this->flagForDespawn();
		return $damage;
	}
}
