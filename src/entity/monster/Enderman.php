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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\ai\hostile\LookDetection;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\GameRules;
use pocketmine\world\particle\EndermanTeleportParticle;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\world\vibration\GameEvent;
use function count;
use function floor;
use function in_array;
use function max;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (enderman.json): 40 health, 0.6x2.9, movement 0.3 (0.45 angry), attack 7, follow range 64.
 * - Becomes angry for 25 seconds at a player staring at its eyes (within 64 blocks, not wearing a carved pumpkin, for
 *   0.25 seconds; Java stare cone: cosine > 1 - 0.025 / distance) or at whoever hurts it; hunts endermites.
 * - Teleports (minecraft:teleport): randomly within 16 blocks along each axis when hit by a projectile (which doesn't hurt it), when hurt
 *   by something that isn't a mob (90%), in water, randomly in daylight, when its target is more than 16 blocks away
 *   (5% per tick, towards it) and when a staring target comes within 4 blocks.
 * - Never teleports while riding a boat or a minecart (minecraft:teleport is in the not_riding group), and projectiles
 *   hit it then.
 * - Hurt by water and rain (1 damage per 10 ticks in contact with them), which makes it teleport away.
 * - Picks up (5% per second) and places down (0.05% per tick) dirt, sand, flowers and other holdable blocks.
 * Loot: 0-1 ender pearl (+0-1 per Looting level), and the carried block. Experience 5.
 */
class Enderman extends Monster implements Angerable{
	use AngerTrait;

	public const TAG_CARRIED_BLOCK = "carriedBlock"; //TAG_Compound (Bedrock)

	public const ATTACK_DAMAGE = 7.0;
	public const ANGER_TICKS = 500;
	public const STARE_TICKS = 5;
	/** How far along each axis it teleports randomly (Bedrock random_teleport_cube [32, 32, 32], centred on it). */
	public const TELEPORT_RANGE = 16;
	public const TARGET_TELEPORT_DISTANCE = 16.0;

	/** @var int[] block type IDs endermen pick up (behavior.take_block) */
	public const HOLDABLE_BLOCKS = [
		BlockTypeIds::GRASS, BlockTypeIds::DIRT, BlockTypeIds::PODZOL, BlockTypeIds::MYCELIUM, BlockTypeIds::MOSS_BLOCK,
		BlockTypeIds::PALE_MOSS_BLOCK, BlockTypeIds::MUD, BlockTypeIds::MUDDY_MANGROVE_ROOTS, BlockTypeIds::SAND,
		BlockTypeIds::RED_SAND, BlockTypeIds::GRAVEL, BlockTypeIds::BROWN_MUSHROOM, BlockTypeIds::RED_MUSHROOM,
		BlockTypeIds::TNT, BlockTypeIds::CACTUS, BlockTypeIds::CACTUS_FLOWER, BlockTypeIds::CLAY, BlockTypeIds::PUMPKIN,
		BlockTypeIds::CARVED_PUMPKIN, BlockTypeIds::MELON, BlockTypeIds::CRIMSON_FUNGUS, BlockTypeIds::CRIMSON_NYLIUM,
		BlockTypeIds::CRIMSON_ROOTS, BlockTypeIds::WARPED_FUNGUS, BlockTypeIds::WARPED_NYLIUM, BlockTypeIds::WARPED_ROOTS,
		BlockTypeIds::DANDELION, BlockTypeIds::OPEN_EYEBLOSSOM, BlockTypeIds::CLOSED_EYEBLOSSOM, BlockTypeIds::POPPY,
		BlockTypeIds::BLUE_ORCHID, BlockTypeIds::ALLIUM, BlockTypeIds::AZURE_BLUET, BlockTypeIds::RED_TULIP,
		BlockTypeIds::ORANGE_TULIP, BlockTypeIds::WHITE_TULIP, BlockTypeIds::PINK_TULIP, BlockTypeIds::OXEYE_DAISY,
		BlockTypeIds::CORNFLOWER, BlockTypeIds::LILY_OF_THE_VALLEY, BlockTypeIds::WITHER_ROSE, BlockTypeIds::TORCHFLOWER,
		BlockTypeIds::GOLDEN_DANDELION
	];

	private ?Block $carriedBlock = null;
	private ?int $starerId = null;
	private int $stareTicks = 0;
	private int $randomTeleportTicks = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::ENDERMAN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(2.9, 0.6, 2.55);
	}

	public function getName() : string{
		return "Enderman";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(40);
		$this->setMovementSpeed(0.3);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
		$this->setStepHeight(1.0625); //variable_max_auto_step: walks up whole blocks
		$this->randomTeleportTicks = mt_rand(20, 600);
		$carried = $nbt->getCompoundTag(self::TAG_CARRIED_BLOCK);
		if($carried !== null){
			try{
				$stateData = GlobalBlockStateHandlers::getUpgrader()->upgradeBlockStateNbt($carried);
				$block = RuntimeBlockStateRegistry::getInstance()->fromStateId(GlobalBlockStateHandlers::getDeserializer()->deserialize($stateData));
				if($block->getTypeId() !== BlockTypeIds::AIR){
					$this->carriedBlock = $block;
				}
			}catch(BlockStateDeserializeException){
				//forget it
			}
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		if($this->carriedBlock !== null){
			$nbt->setTag(self::TAG_CARRIED_BLOCK, GlobalBlockStateHandlers::getSerializer()->serialize($this->carriedBlock->getStateId())->toNbt());
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(2, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, 64.0));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new AngerTargetGoal($this, $this->getAngerTarget(...), 64.0));
		$this->targetSelector->addGoal(3, new HurtByTargetGoal($this, 64.0));
		//attack_interval 10 seconds
		$this->targetSelector->addGoal(5, new NearestLivingTargetGoal($this, fn(Living $e) => $e instanceof Endermite, 64.0, interval: 10 * 20, requiredClass: Endermite::class));
	}

	public function getCarriedBlock() : ?Block{
		return $this->carriedBlock;
	}

	public function setCarriedBlock(?Block $block) : void{
		$this->carriedBlock = $block;
		$this->networkPropertiesDirty = true;
	}

	protected function onAngerChange(bool $angry) : void{
		$this->setMovementSpeed($angry ? 0.45 : 0.3);
		if($angry){
			$this->playMobSound(LevelSoundEvent::STARE);
		}
	}

	/**
	 * Returns where the enderman would land if it teleported to the given point (vanilla EnderMan::teleport and
	 * LivingEntity::randomTeleport): straight down to the first block it can stand on, which must not be a liquid, with
	 * room for its whole body and no liquid around it. Returns null if it can't go there.
	 */
	public function findTeleportDestination(float $x, float $y, float $z) : ?Vector3{
		$world = $this->getWorld();
		$bx = (int) floor($x);
		$bz = (int) floor($z);
		$by = (int) floor($y);
		if($by >= $world->getMaxY()){
			$by = $world->getMaxY() - 1;
		}
		if(!$world->isInWorld($bx, $by, $bz) || !$world->isChunkLoaded($bx >> 4, $bz >> 4)){
			return null;
		}
		//go down until standing on something solid
		while($by > $world->getMinY() && count($world->getBlockAt($bx, $by - 1, $bz)->getCollisionBoxes()) === 0 && !$world->getBlockAt($bx, $by - 1, $bz) instanceof Liquid){
			--$by;
		}
		$ground = $world->getBlockAt($bx, $by - 1, $bz);
		if($ground instanceof Liquid || count($ground->getCollisionBoxes()) === 0){
			return null;
		}
		$feetY = $by;
		foreach($ground->getCollisionBoxes() as $bb){
			$feetY = max($feetY, $bb->maxY);
		}
		$half = $this->size->getWidth() / 2;
		$box = new AxisAlignedBB($x - $half, $feetY, $z - $half, $x + $half, $feetY + $this->size->getHeight(), $z + $half);
		if(count($world->getBlockCollisionBoxes($box)) > 0){
			return null;
		}
		for($cy = (int) floor($box->minY); $cy <= (int) floor($box->maxY); ++$cy){
			for($cx = (int) floor($box->minX); $cx <= (int) floor($box->maxX); ++$cx){
				for($cz = (int) floor($box->minZ); $cz <= (int) floor($box->maxZ); ++$cz){
					if($world->getBlockAt($cx, $cy, $cz) instanceof Liquid){
						return null;
					}
				}
			}
		}
		return new Vector3($x, $feetY, $z);
	}

	/**
	 * Teleports to the given point if possible (see {@link Enderman::findTeleportDestination()}). Never while riding:
	 * enderman.json only has minecraft:teleport in its not_riding group, so endermen stay in boats and minecarts.
	 */
	public function teleportTo(float $x, float $y, float $z) : bool{
		if($this->isRiding()){
			return false;
		}
		$destination = $this->findTeleportDestination($x, $y, $z);
		if($destination === null){
			return false;
		}
		$world = $this->getWorld();
		$from = $this->location->asVector3();
		$this->navigation->stop();
		if(!$this->teleport($destination)){
			return false;
		}
		$world->addParticle($from, new EndermanTeleportParticle());
		$world->addSound($from, new EndermanTeleportSound());
		$world->addSound($destination, new EndermanTeleportSound());
		$world->addGameEvent($from, GameEvent::TELEPORT, $this); //Java EnderMan.teleport()
		return true;
	}

	/**
	 * Tries to teleport to a random spot in the 32x32x32 cube centred on the enderman (Bedrock random_teleport_cube
	 * [32, 32, 32]: up to 16 blocks along each axis; Java uses a 64-block cube). Returns whether it moved.
	 */
	public function teleportRandomly(int $tries = 16) : bool{
		if($this->isRiding()){
			return false;
		}
		for($i = 0; $i < $tries; ++$i){
			$x = $this->location->x + (mt_rand() / mt_getrandmax() - 0.5) * self::TELEPORT_RANGE * 2;
			$y = $this->location->y + (mt_rand() / mt_getrandmax() - 0.5) * self::TELEPORT_RANGE * 2;
			$z = $this->location->z + (mt_rand() / mt_getrandmax() - 0.5) * self::TELEPORT_RANGE * 2;
			if($this->teleportTo($x, $y, $z)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Teleports to a spot near the entity, on the enderman's side (vanilla teleportTowards).
	 */
	public function teleportTowards(Living $entity, int $tries = 8) : bool{
		if($this->isRiding()){
			return false;
		}
		$d = $this->location->subtractVector($entity->getEyePos()->subtract(0, $this->getEyeHeight(), 0));
		$len = $d->length();
		if($len < 0.0001){
			return $this->teleportRandomly($tries);
		}
		$d = $d->divide($len);
		for($i = 0; $i < $tries; ++$i){
			$x = $this->location->x + (mt_rand() / mt_getrandmax() - 0.5) * 8 - $d->x * 16;
			$y = $this->location->y + mt_rand(-8, 7) - $d->y * 16;
			$z = $this->location->z + (mt_rand() / mt_getrandmax() - 0.5) * 8 - $d->z * 16;
			if($this->teleportTo($x, $y, $z)){
				return true;
			}
		}
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if(EntityDamageEvent::isProjectileCause($source->getCause()) && !$this->isRiding()){
			//teleports_on_projectile_hit: projectiles never hurt it
			$source->cancel();
			$this->teleportRandomly(64);
			return;
		}
		parent::attack($source);
		if($source->isCancelled() || !$this->isAlive()){
			return;
		}
		if($source instanceof EntityDamageByEntityEvent && !$source instanceof EntityDamageByChildEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Living && (!$damager instanceof Player || !$damager->isCreative())){
				$this->setAngryAt($damager, self::ANGER_TICKS);
			}
		}else{
			if(mt_rand(0, 9) !== 0){
				$this->teleportRandomly();
			}
		}
	}

	/**
	 * Returns the player staring at the enderman, if any.
	 */
	private function findStarer() : ?Player{
		foreach($this->getWorld()->getPlayers() as $player){
			$live = self::getLiveLocationOf($player);
			if(
				($live !== null ?
					//the live location, and the distance first: the checks have no side effects, so the first starer is
					//the same, and most players of the world are out of range
					$live->distanceSquared($this->location) <= 64 * 64 && Sensing::isTargetablePlayer($player) :
					//a class overriding getLocation(): the original order
					Sensing::isTargetablePlayer($player) && $player->getLocation()->distanceSquared($this->location) <= 64 * 64
				) &&
				LookDetection::isStaringAt($player, $this)
			){
				return $player;
			}
		}
		return null;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$this->tickAnger($tickDiff);
		//alive and not closed: Mob::entityBaseTick() set the AI tick to this tick's (the server tick)
		$phase = ($this->getAiTick() + $this->id) % 5;
		if($phase === 0){
			$this->checkStare();
		}
		if($phase === 1 && $this->isInContactWithWater()){
			$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 1));
		}

		$target = $this->getAttackTarget();
		if($target !== null){
			$distSq = $target->getLocation()->distanceSquared($this->location);
			if($distSq > self::TARGET_TELEPORT_DISTANCE ** 2 && mt_rand(0, 19) === 0){
				//target_teleport_chance 0.05
				$this->teleportTowards($target);
			}elseif($distSq < 16 && $target instanceof Player && $this->isAngry() && $phase === 2 && LookDetection::isStaringAt($target, $this)){
				$this->teleportRandomly();
			}
		}else{
			if(--$this->randomTeleportTicks <= 0){
				//random_teleports, max_random_teleport_time 30 s
				$this->randomTeleportTicks = mt_rand(20, 600);
				$this->teleportRandomly(1);
			}elseif($phase === 3 && $this->isInDaylightAndBright() && mt_rand(0, 3) === 0){
				//light_teleport_chance 0.05 per tick
				$this->teleportRandomly();
			}
			$this->tickBlockCarrying();
		}
		return true;
	}

	private function isInDaylightAndBright() : bool{
		return $this->getWorld()->getDimension() === Dimension::OVERWORLD && $this->isInDaylight();
	}

	private function checkStare() : void{
		if($this->isAngry()){
			$this->starerId = null;
			$this->stareTicks = 0;
			return;
		}
		$starer = $this->findStarer();
		if($starer === null || $starer->getId() !== $this->starerId){
			//a new stare starts (min_looked_at_duration: it must last 0.25 seconds)
			$this->starerId = $starer?->getId();
			$this->stareTicks = 0;
			return;
		}
		$this->stareTicks += 5;
		if($this->stareTicks >= self::STARE_TICKS){
			$this->setAngryAt($starer, self::ANGER_TICKS);
			$this->setAttackTarget($starer);
			$this->stareTicks = 0;
		}
	}

	/**
	 * behavior.take_block (1/20 per tick, within 2 blocks around and 3 up) and behavior.place_block (1/2000 per tick,
	 * within 1 block around and 2 up, on a full block), both only while mobGriefing is on (affected_by_griefing_rule).
	 */
	private function tickBlockCarrying() : void{
		$world = $this->getWorld();
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		if($this->carriedBlock === null){
			if(mt_rand(0, 19) !== 0 || !GameRules::allowsMobGriefing($world)){
				return;
			}
			$x = $bx + mt_rand(-2, 2);
			$y = $by + mt_rand(0, 3);
			$z = $bz + mt_rand(-2, 2);
			if(!$world->isInWorld($x, $y, $z)){
				return;
			}
			$block = $world->getBlockAt($x, $y, $z);
			if(in_array($block->getTypeId(), self::HOLDABLE_BLOCKS, true)){
				$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
				$this->setCarriedBlock($block);
			}
		}elseif(mt_rand(0, 1999) === 0 && GameRules::allowsMobGriefing($world)){
			$x = $bx + mt_rand(-1, 1);
			$y = $by + mt_rand(0, 2);
			$z = $bz + mt_rand(-1, 1);
			if(!$world->isInWorld($x, $y, $z) || $world->getBlockAt($x, $y, $z)->getTypeId() !== BlockTypeIds::AIR){
				return;
			}
			$below = $world->getBlockAt($x, $y - 1, $z);
			if($below->getTypeId() === BlockTypeIds::AIR || !$below->isFullCube()){
				return;
			}
			$world->setBlockAt($x, $y, $z, $this->carriedBlock);
			$this->setCarriedBlock(null);
		}
	}

	public function getDrops() : array{
		$drops = [VanillaItems::ENDER_PEARL()->setCount(mt_rand(0, 1) + $this->lootingBonus())];
		if($this->carriedBlock !== null){
			$drops[] = $this->carriedBlock->asItem();
		}
		return $drops;
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ENDERMAN_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry());
		//CARRY_BLOCK_STATE (23): network block runtime ID, int as in CloudburstMC Protocol
		$properties->setInt(EntityMetadataProperties::ENDERMAN_HELD_ITEM_ID, $this->carriedBlock !== null ?
			TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->carriedBlock->getStateId()) :
			TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::AIR()->getStateId()));
	}

	protected function networkDataForProtocol(TypeConverter $converter, array $data) : array{
		if(isset($data[EntityMetadataProperties::ENDERMAN_HELD_ITEM_ID])){
			$stateId = ($this->carriedBlock ?? VanillaBlocks::AIR())->getStateId();
			$data[EntityMetadataProperties::ENDERMAN_HELD_ITEM_ID] = new IntMetadataProperty($converter->getBlockTranslator()->internalIdToNetworkId($stateId));
		}
		return $data;
	}
}
