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
use pocketmine\block\InfestedStone;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\GameRules;
use pocketmine\world\particle\MobSpawnParticle;
use pocketmine\world\World;
use function floor;
use function mt_rand;

/**
 * Bedrock values (silverfish.json): 8 health, 0.4x0.3, movement 0.25, attack 1, targets players, snow golems and iron golems
 * within 8 blocks.
 * When hurt it wakes up the silverfish hidden in infested blocks within 10 blocks (behavior.silverfish_wake_up_friends);
 * when idle it may hide in an adjacent stone-like block, turning it into its infested variant
 * (behavior.silverfish_merge_with_stone), only while mobGriefing is on. Hurting one also angers the silverfish within
 * 20 blocks (silverfish_angry broadcast_anger). No loot.
 */
class Silverfish extends Monster{
	public const ATTACK_DAMAGE = 1.0;
	public const WAKE_UP_DELAY = 20;
	private const ANGER_BROADCAST_RANGE = 20.0;

	/** Ticks before waking up friends, -1 when not hurt. */
	private int $wakeUpTicks = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::SILVERFISH; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.3, 0.4, 0.13);
	}

	public function getName() : string{
		return "Silverfish";
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		return $effect === VanillaEffects::INFESTED() || parent::isImmuneToEffect($effect); //silverfish.json mob_effect_immunity
	}

	protected function canStandOnPowderSnow() : bool{
		return true; //can_stand_on_powder_snow
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(8);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new FloatGoal($this));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, 16.0));
		$this->goalSelector->addGoal(5, new class($this) extends Goal{
			public function __construct(private Silverfish $silverfish){}

			public function getFlags() : int{
				return self::FLAG_MOVE;
			}

			public function canStart() : bool{
				return $this->silverfish->getAttackTarget() === null && $this->silverfish->getNavigation()->isDone() && mt_rand(0, 3) === 0;
			}

			public function canContinue() : bool{
				return false;
			}

			public function start() : void{
				$this->silverfish->tryHideInBlock();
			}
		});
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));

		//silverfish_angry: broadcast_anger to the silverfish within 20 blocks, duration -1 (no time limit)
		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this, alertRange: self::ANGER_BROADCAST_RANGE));
		//players, snow golems and iron golems within 8 blocks, attack_interval 10 seconds
		$nearby = MonsterTargets::nearbyTargets($this, [IronGolem::class, SnowGolem::class], MonsterTargets::isGolem(...), 8.0);
		$this->targetSelector->addGoal(2, MonsterTargets::extraTargetGoal($this, MonsterTargets::isGolem(...), $nearby, null, 8.0, false, 10 * 20));
		$this->targetSelector->addGoal(2, MonsterTargets::playerTargetGoal($this, $nearby, null, 8.0, false, 10 * 20));
	}

	protected function isAngryWhenTargeting() : bool{
		//on_target_acquired adds silverfish_angry (minecraft:angry)
		return true;
	}

	/**
	 * Returns the infested variant of a stone-like block, or null if silverfish can't hide in it.
	 */
	public static function getInfestedVariant(Block $block) : ?Block{
		foreach([
			VanillaBlocks::INFESTED_STONE(), VanillaBlocks::INFESTED_COBBLESTONE(), VanillaBlocks::INFESTED_STONE_BRICK(),
			VanillaBlocks::INFESTED_MOSSY_STONE_BRICK(), VanillaBlocks::INFESTED_CRACKED_STONE_BRICK(),
			VanillaBlocks::INFESTED_CHISELED_STONE_BRICK(), VanillaBlocks::INFESTED_DEEPSLATE()
		] as $infested){
			if($infested->getImitatedBlock()->getTypeId() === $block->getTypeId()){
				return $infested;
			}
		}
		return null;
	}

	/**
	 * Hides in a random adjacent stone-like block (it becomes infested and the silverfish disappears). Returns whether
	 * it hid.
	 */
	public function tryHideInBlock() : bool{
		$world = $this->getWorld();
		if(!GameRules::allowsMobGriefing($world)){
			return false; //Java SilverfishMergeWithStoneGoal needs mobGriefing
		}
		$pos = new Vector3((int) floor($this->location->x), (int) floor($this->location->y + 0.5), (int) floor($this->location->z));
		$side = $pos->getSide(Facing::ALL[mt_rand(0, 5)]);
		if(!$world->isInWorld($side->getFloorX(), $side->getFloorY(), $side->getFloorZ())){
			return false;
		}
		$infested = self::getInfestedVariant($world->getBlock($side));
		if($infested === null){
			return false;
		}
		$world->setBlock($side, $infested);
		$world->addParticle($side->add(0.5, 0.5, 0.5), new MobSpawnParticle(1, 1));
		$this->flagForDespawn();
		return true;
	}

	/**
	 * Returns the offsets the wake-up scan visits along one axis, outwards from the silverfish: 0, 1, -1, 2, -2... up
	 * to +-$radius (Java SilverfishWakeUpFriendsGoal).
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function getWakeUpScanOffsets(int $radius) : array{
		$offsets = [0];
		for($i = 1; $i <= $radius; ++$i){
			$offsets[] = $i;
			$offsets[] = -$i;
		}
		return $offsets;
	}

	/**
	 * Wakes up the silverfish hidden in the infested blocks within 10 blocks horizontally and 5 vertically, scanning
	 * outwards from the silverfish (y first, then x, then z) and stopping after each woken block with a 50% chance
	 * (Java SilverfishWakeUpFriendsGoal). With mobGriefing off the blocks turn back into their normal variant and no
	 * silverfish comes out.
	 */
	public function wakeUpFriends() : void{
		$world = $this->getWorld();
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		$griefing = GameRules::allowsMobGriefing($world);
		$horizontal = self::getWakeUpScanOffsets(10);
		foreach(self::getWakeUpScanOffsets(5) as $y){
			foreach($horizontal as $x){
				if(!$world->isInWorld($bx + $x, $by + $y, $bz)){
					continue;
				}
				foreach($horizontal as $z){
					if(!$world->isChunkLoaded(($bx + $x) >> 4, ($bz + $z) >> 4)){
						continue;
					}
					$block = $world->getBlockAt($bx + $x, $by + $y, $bz + $z);
					if($block instanceof InfestedStone){
						if($griefing){
							$world->setBlockAt($bx + $x, $by + $y, $bz + $z, VanillaBlocks::AIR());
							self::spawnFromBlock($world, new Vector3($bx + $x, $by + $y, $bz + $z));
						}else{
							$world->setBlockAt($bx + $x, $by + $y, $bz + $z, $block->getImitatedBlock());
						}
						if(mt_rand(0, 1) === 0){
							return;
						}
					}
				}
			}
		}
	}

	/**
	 * Spawns the silverfish hidden in the infested block at the given position.
	 */
	public static function spawnFromBlock(World $world, Vector3 $blockPos) : Silverfish{
		$silverfish = new Silverfish(Location::fromObject($blockPos->add(0.5, 0, 0.5), $world, mt_rand(0, 359), 0));
		$silverfish->setPersistent(false);
		$silverfish->spawnToAll();
		$world->addParticle($blockPos->add(0.5, 0.5, 0.5), new MobSpawnParticle(1, 1));
		return $silverfish;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->wakeUpTicks < 0 && ($source instanceof EntityDamageByEntityEvent || $source->getCause() === EntityDamageEvent::CAUSE_MAGIC)){
			$this->wakeUpTicks = self::WAKE_UP_DELAY;
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->wakeUpTicks >= 0 && !$this->closed){
			$this->wakeUpTicks -= $tickDiff;
			if($this->wakeUpTicks < 0){
				$this->wakeUpFriends();
			}
			$hasUpdate = true;
		}
		return $hasUpdate;
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::SILVERFISH_SPAWN_EGG();
	}
}
