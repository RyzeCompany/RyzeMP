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

namespace pocketmine\entity\golem;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\utils\SupportType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\RangedAttackGoal;
use pocketmine\entity\ai\ProjectileAim;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\projectile\Snowball;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\vibration\GameEvent;
use function floor;
use function intdiv;
use function mt_rand;

/**
 * Bedrock values (entities/snow_golem.json): 4 health, 0.4x1.8, movement 0.2. Throws a snowball every second at
 * monsters within 6 blocks (shooting range 10, 1.25x). Leaves a trail of snow layers in biomes colder than 0.81, and
 * takes 1 damage per tick in biomes warmer than 1.0 (without fire resistance) and in water or rain, which the hurt
 * cooldown limits to 1 every 10 ticks. No fall damage. Shears take its pumpkin off (SHEARED, drops a carved pumpkin).
 * Loot (loot_tables/entities/snowman.json): 0-15 snowballs.
 */
class SnowGolem extends Mob{
	public const TAG_SHEARED = "Sheared"; //TAG_Byte

	private bool $sheared = false;

	public static function getNetworkTypeId() : string{ return EntityIds::SNOW_GOLEM; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.8, 0.4, 1.6);
	}

	public function canBeLeashed() : bool{
		return true;
	}

	public function canFreeze() : bool{
		return false; //vanilla freeze_immune_entity_types
	}

	public function getName() : string{
		return "Snow Golem";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(4);
		$this->setMovementSpeed(0.2);
		parent::initEntity($nbt);
		$this->sheared = $nbt->getByte(self::TAG_SHEARED, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_SHEARED, $this->sheared ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new RangedAttackGoal($this, 1.25, 20, 20, 10.0, $this->throwSnowball(...)));
		$this->goalSelector->addGoal(2, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(3, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(4, new RandomLookAroundGoal($this));
		$this->targetSelector->addGoal(1, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Monster, 6.0, true, 10, requiredClass: Monster::class));
	}

	public function throwSnowball(Living $target, float $distance) : void{
		$aim = ProjectileAim::aim($this, $target, 1.6, 0.06);
		if($aim === null){
			return;
		}
		[$location, $motion] = $aim;
		$snowball = new Snowball($location, $this);
		$snowball->setMotion($motion);
		$snowball->spawnToAll();
		$this->playMobSound(LevelSoundEvent::THROW);
	}

	public function isSheared() : bool{
		return $this->sheared;
	}

	public function setSheared(bool $sheared) : void{
		$this->sheared = $sheared;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Shears the pumpkin off (players and dispensers). Returns false if it has none.
	 */
	public function shear() : bool{
		if($this->sheared || !$this->isAlive()){
			return false;
		}
		$this->setSheared(true);
		$this->getWorld()->dropItem($this->location->add(0, 1.6, 0), VanillaBlocks::CARVED_PUMPKIN()->asItem());
		$this->playMobSound(LevelSoundEvent::SHEAR);
		return true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item instanceof Shears && $this->shear()){
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			if($player->hasFiniteResources()){
				$item->applyDamage(1);
				if($item->isBroken()){
					$player->broadcastSound(new ItemBreakSound());
				}
				$player->getInventory()->setItemInHand($item);
			}
			return true;
		}
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_FALL){
			$source->cancel(); //damage_sensor: no fall damage
		}
		parent::attack($source);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $hasUpdate;
		}
		$world = $this->getWorld();
		$temperature = MobBiomeInfo::getTemperature($world, $this->location);
		//hurt_on_condition: 1 damage per tick, so as often as the hurt cooldown lets it (every 10 ticks)
		if($this->attackTime <= 0){
			if($temperature > 1.0 && !$this->effectManager->has(VanillaEffects::FIRE_RESISTANCE())){
				$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_CUSTOM, 1));
			}elseif($this->isInContactWithWater()){
				$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 1));
			}
		}
		if($temperature < 0.81 && $this->onGround && GameRules::allowsMobGriefing($world)){
			//vanilla checks the four corners of a 0.5 block square around the feet
			$y = (int) floor($this->location->y);
			for($i = 0; $i < 4; ++$i){
				$x = (int) floor($this->location->x + (($i % 2) * 2 - 1) * 0.25);
				$z = (int) floor($this->location->z + ((intdiv($i, 2) % 2) * 2 - 1) * 0.25);
				if($world->isInWorld($x, $y, $z) && $world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::AIR && $world->getBlockAt($x, $y - 1, $z)->getSupportType(Facing::UP) === SupportType::FULL){
					$world->setBlockAt($x, $y, $z, VanillaBlocks::SNOW_LAYER());
				}
			}
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		$count = mt_rand(0, 15);
		return $count > 0 ? [VanillaItems::SNOWBALL()->setCount($count)] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SHEARED, $this->sheared);
	}
}
