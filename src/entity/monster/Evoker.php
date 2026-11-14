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

use pocketmine\block\utils\DyeColor;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\OpenDoorGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\EvokerCastGoal;
use pocketmine\entity\ai\hostile\goal\EvokerWololoGoal;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\EvokerFangs;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function atan2;
use function cos;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function sin;
use const M_PI;

/**
 * Bedrock values (evocation_illager.json): 24 health, movement 0.5, keeps 8 blocks away from players, casts fangs and
 * summons vexes (see {@link EvokerCastGoal}). Experience 10.
 * Loot: 0-1 emerald (+0-1 per Looting level) and a totem of undying.
 * Without a target it casts the "wololo" spell on blue sheep within 16 blocks, turning them red (see
 * {@link EvokerWololoGoal}).
 */
class Evoker extends Illager{
	public const MAX_VEXES = 8;
	public const VEX_CAP_RADIUS = 16;
	/** Natural armor (BDS 1.26.60: a fist hit does 0.94 and a wooden sword 4.92, like on a zombie; vindicators have none). */
	public const NATURAL_ARMOR = 2;

	/** ARGB #FF664D59 (fangs) and #FFB3B3CC (vexes) as signed 32-bit ints */
	private const COLOR_FANGS = 0xFF664D59 - 0x100000000;
	private const COLOR_VEX = 0xFFB3B3CC - 0x100000000;
	/** ARGB #FFB38033 (wololo) */
	private const COLOR_WOLOLO = 0xFFB38033 - 0x100000000;

	private int $castingSpell = -1;
	/**
	 * Fangs waiting to appear: [server tick, x, y, z, yaw].
	 * @phpstan-var list<array{int, float, float, float, float}>
	 */
	private array $pendingFangs = [];

	public static function getNetworkTypeId() : string{ return EntityIds::EVOCATION_ILLAGER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	public function getName() : string{
		return "Evoker";
	}

	public function getArmorPoints() : int{
		return parent::getArmorPoints() + self::NATURAL_ARMOR;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(24);
		$this->setMovementSpeed(0.5);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		//navigation can_open_doors: opens the wooden doors on its paths
		$this->goalSelector->addGoal(0, new OpenDoorGoal($this));
		$this->registerCommonGoals();
		$this->goalSelector->addGoal(1, new EvokerCastGoal($this));
		$this->goalSelector->addGoal(3, new EvokerWololoGoal($this));
		//avoid_mob_type: any player within 8 blocks (walking at 0.6), creakings within 8 (sprinting at 1.2)
		$this->goalSelector->addGoal(5, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Player, 8.0, 0.6, 1.0, playersOnly: true));
		$this->registerCreakingAvoidance(5);
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 3.0));
		$this->goalSelector->addGoal(10, new RandomLookAroundGoal($this));
		//max_dist 20
		$this->registerTargetGoals(20.0);
	}

	protected function getRaidVictimRange() : float{
		return 20.0;
	}

	public function isCasting() : bool{
		return $this->castingSpell !== -1;
	}

	public function startCasting(int $spell) : void{
		$this->castingSpell = $spell;
		$this->networkPropertiesDirty = true;
		$this->playMobSound(LevelSoundEvent::CAST_SPELL);
		if($spell !== EvokerWololoGoal::SPELL_WOLOLO){
			$this->playMobSound($spell === EvokerCastGoal::SPELL_VEX ? LevelSoundEvent::PREPARE_SUMMON : LevelSoundEvent::PREPARE_ATTACK);
		}
	}

	public function getCastingSpell() : int{
		return $this->castingSpell;
	}

	/**
	 * The wololo spell takes effect: the sheep turns red if it is still alive, blue and within range.
	 */
	public function castWololo(?Sheep $sheep) : bool{
		$this->playMobSound(LevelSoundEvent::PREPARE_WOLOLO);
		if($sheep === null || $sheep->isClosed() || !$sheep->isAlive() || $sheep->getColor() !== DyeColor::BLUE || $sheep->getWorld() !== $this->getWorld()){
			return false;
		}
		$r = EvokerWololoGoal::RANGE + 2;
		if($sheep->getLocation()->distanceSquared($this->location) > $r * $r){
			return false;
		}
		$sheep->setColor(DyeColor::RED);
		return true;
	}

	public function stopCasting() : void{
		if($this->castingSpell !== -1){
			$this->castingSpell = -1;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Returns whether there is room for more vexes (vanilla summon_cap: 8 within 16 blocks).
	 */
	public function canSummonVexes() : bool{
		$r = self::VEX_CAP_RADIUS;
		$loc = $this->location;
		$count = 0;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r)) as $entity){
			if($entity instanceof Vex && $entity->isAlive()){
				++$count;
			}
		}
		return $count + 3 <= self::MAX_VEXES;
	}

	public function summonVexes() : void{
		$world = $this->getWorld();
		for($i = 0; $i < 3; ++$i){
			$angle = $i * 2 * M_PI / 3;
			$vex = new Vex(Location::fromObject($this->location->add(cos($angle), 1, sin($angle)), $world, mt_rand(0, 359), 0));
			$vex->setOwningEntity($this);
			$vex->setLimitedLife(true);
			$vex->setAttackTarget($this->getAttackTarget());
			$vex->spawnToAll();
		}
	}

	/**
	 * Casts the fangs at the target: a double circle around the evoker when the target is close, otherwise a line of
	 * 16 fangs towards it, one per tick.
	 */
	public function summonFangs(Living $target) : void{
		$now = $this->server->getTick();
		$loc = $this->location;
		$tLoc = $target->getLocation();
		$minY = min($tLoc->y, $loc->y);
		$maxY = max($tLoc->y, $loc->y) + 1;
		$yaw = atan2($tLoc->z - $loc->z, $tLoc->x - $loc->x);
		if($loc->distanceSquared($tLoc) < 9){
			for($i = 0; $i < 5; ++$i){
				$a = $yaw + $i * M_PI * 0.4;
				$this->queueFang($now, $loc->x + cos($a) * 1.5, $loc->z + sin($a) * 1.5, $minY, $maxY, $a);
			}
			for($i = 0; $i < 8; ++$i){
				$a = $yaw + $i * M_PI * 2 / 8 + M_PI * 2 / 5;
				$this->queueFang($now + 3, $loc->x + cos($a) * 2.5, $loc->z + sin($a) * 2.5, $minY, $maxY, $a);
			}
		}else{
			for($i = 0; $i < 16; ++$i){
				$d = 1.25 * ($i + 1);
				$this->queueFang($now + $i, $loc->x + cos($yaw) * $d, $loc->z + sin($yaw) * $d, $minY, $maxY, $yaw);
			}
		}
	}

	private function queueFang(int $tick, float $x, float $z, float $minY, float $maxY, float $yawRadians) : void{
		//find the ground between the evoker's and the target's heights (vanilla createSpellEntity)
		$world = $this->getWorld();
		$bx = (int) floor($x);
		$bz = (int) floor($z);
		for($y = (int) floor($maxY); $y >= (int) floor($minY) - 1; --$y){
			$below = PathBlockTypes::at($world, $bx, $y - 1, $bz);
			$here = PathBlockTypes::at($world, $bx, $y, $bz);
			if(($below & PathBlockTypes::FLOOR) !== 0 && ($here & PathBlockTypes::PASSABLE) !== 0){
				$this->pendingFangs[] = [$tick, $x, (float) $y, $z, $yawRadians * 180 / M_PI - 90];
				return;
			}
		}
	}

	/**
	 * @return int number of fangs still waiting to appear
	 */
	public function getPendingFangCount() : int{
		return count($this->pendingFangs);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(count($this->pendingFangs) > 0){
			$now = $this->server->getTick();
			$world = $this->getWorld();
			$left = [];
			foreach($this->pendingFangs as $fang){
				[$tick, $x, $y, $z, $yaw] = $fang;
				if($tick > $now){
					$left[] = $fang;
					continue;
				}
				if($this->isAlive()){
					(new EvokerFangs(new Location($x, $y, $z, $world, $yaw, 0), $this))->spawnToAll();
				}
			}
			$this->pendingFangs = $left;
			$hasUpdate = true;
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		return [
			VanillaItems::EMERALD()->setCount(mt_rand(0, 1) + $this->lootingBonus()),
			VanillaItems::TOTEM(),
			...$this->getRaiderDrops()
		];
	}

	/**
	 * Bedrock experience_reward on_death 10, whoever killed it.
	 */
	public function getXpDropAmount() : int{
		return 10;
	}

	/**
	 * Evokers never despawn (Bedrock minecraft:persistent in their base components), in raids too.
	 */
	public function canDespawn() : bool{
		return false;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::EVOKER_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//flag 42 is ActorFlags::CASTING (query.is_casting)
		$properties->setGenericFlag(EntityMetadataFlags::EVOKER_SPELL, $this->isCasting());
		$properties->setInt(EntityMetadataProperties::EVOKER_SPELL_CASTING_COLOR, match($this->castingSpell){
			EvokerCastGoal::SPELL_VEX => self::COLOR_VEX,
			EvokerCastGoal::SPELL_FANGS => self::COLOR_FANGS,
			EvokerWololoGoal::SPELL_WOLOLO => self::COLOR_WOLOLO,
			default => 0
		});
	}
}
