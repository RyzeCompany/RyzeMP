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

namespace pocketmine\entity\animal;

use pocketmine\block\Lava;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MoveToLiquidGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\riding\RiderInput;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use function count;
use function floor;
use function max;
use function mt_rand;

/**
 * Bedrock values (strider.json): 20 health, 0.9x1.7, movement 0.16 on land and 0.32 on lava, fire immune, walks on
 * lava (stays on its surface). Out of lava it is cold: it shivers (ActorFlags::SHAKING) and turns purple. Hurt by
 * water and rain. Panics when hurt, follows players holding warped fungus (or warped fungus on a stick), bred with warped
 * fungus; half of them spawn as babies.
 * Off lava it walks back to the nearest lava surface within 16 blocks (10 up or down, move_to_liquid).
 * Loot (adult): 2-5 string (+0-1 per Looting level when not saddled), and its saddle. Experience 1-3. Shears take
 * the saddle off (not sneaking, nobody riding it).
 * Riding: a saddled adult carries a player (seat 1.7 up, 0.2 back), steered with a warped fungus on a stick held in the
 * hand (it walks where the rider looks; using the stick boosts it 1.35x for 16 seconds and costs 1 durability, vanilla
 * boostable component). Ridden speed (approximate, Java): 0.28 blocks per tick on lava, 0.18 when cold. Jockeys (see
 * {@link \pocketmine\entity\riding\Jockeys}): a zombified piglin on a saddled strider, a baby strider on an adult.
 */
class Strider extends Animal{
	public const TAG_SADDLED = "Saddled"; //TAG_Byte

	private bool $saddled = false;
	private bool $onLava = false;
	private bool $cold = false;

	public static function getNetworkTypeId() : string{ return EntityIds::STRIDER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.7, 0.9, 1.6);
	}

	protected function getFreezeDamage() : float{
		return 5.0; //vanilla freeze_hurts_extra_types
	}

	public function getName() : string{
		return "Strider";
	}

	protected function isSlowedByLava() : bool{
		return false; //it walks on lava (see tryChangeMovement())
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.16);
		parent::initEntity($nbt);
		$this->saddled = $nbt->getByte(self::TAG_SADDLED, 0) !== 0;
		if($nbt->getTag(self::TAG_AGE) === null && mt_rand(0, 1) === 0){
			$this->setAge(self::BABY_AGE);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_SADDLED, $this->saddled ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(3, new PanicGoal($this, 1.1));
		$this->goalSelector->addGoal(4, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(5, new TemptGoal($this, 1.2, fn(Item $item) => $this->isBreedingItem($item) || $item->getTypeId() === ItemTypeIds::WARPED_FUNGUS_ON_A_STICK));
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(7, new class($this) extends Goal{
			private int $timeout = 0;
			private ?Vector3 $target = null;

			public function __construct(private Strider $strider){}

			public function getFlags() : int{
				return self::FLAG_MOVE;
			}

			public function canStart() : bool{
				if(!$this->strider->isOnLava() || mt_rand(0, 39) !== 0){
					return false;
				}
				//a spot on the lava around it (its paths go over lava, vanilla can_walk_in_lava)
				$loc = $this->strider->getLocation();
				$world = $this->strider->getWorld();
				$y = (int) floor($loc->y + 0.01);
				for($try = 0; $try < 10; ++$try){
					$x = (int) floor($loc->x) + mt_rand(-8, 8);
					$z = (int) floor($loc->z) + mt_rand(-8, 8);
					if($world->isInWorld($x, $y - 1, $z) && $world->getBlockAt($x, $y - 1, $z) instanceof Lava && $world->getBlockAt($x, $y, $z)->getCollisionBoxes() === []){
						$this->target = new Vector3($x + 0.5, $y, $z + 0.5);
						return true;
					}
				}
				return false;
			}

			public function canContinue() : bool{
				return --$this->timeout > 0 && !$this->strider->getNavigation()->isDone();
			}

			public function start() : void{
				$this->timeout = 200;
				$target = $this->target;
				if($target !== null){
					$this->strider->getNavigation()->moveTo($target->x, $target->y, $target->z, 0.8);
				}
			}

			public function stop() : void{
				$this->target = null;
				$this->strider->getNavigation()->stop();
			}
		});
		//move_to_liquid (material_type Lava): a strider off lava walks back onto it
		$this->goalSelector->addGoal(7, new MoveToLiquidGoal($this, MoveToLiquidGoal::LAVA, 1.0, 16, 10, 0.9, null, fn() => $this->onLava));
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(10, new RandomLookAroundGoal($this));
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === VanillaBlocks::WARPED_FUNGUS()->asItem()->getTypeId();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new Strider($location);
	}

	public function isSaddled() : bool{
		return $this->saddled;
	}

	public function setSaddled(bool $saddled) : void{
		$this->saddled = $saddled;
		$this->networkPropertiesDirty = true;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->getTypeId() === ItemTypeIds::SADDLE && !$this->saddled && !$this->isBaby()){
			$this->consumeHeldItem($player);
			$this->setSaddled(true);
			$this->playMobSound(LevelSoundEvent::SADDLE);
			return true;
		}
		if($this->canShearSaddle($player, $item)){
			//strider_saddled interact: the saddle drops 1.7 blocks up
			$this->setSaddled(false);
			$this->getWorld()->dropItem($this->location->add(0, 1.7, 0), VanillaItems::SADDLE());
			$this->playMobSound(LevelSoundEvent::UNSADDLE);
			$this->damageHeldItem($player);
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			return true;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		return $this->saddled && !$this->isBaby() && count($this->passengers) === 0 && $this->tryRide($player);
	}

	public function getRideableSeats() : array{
		if($this->isBaby()){
			return [];
		}
		//a baby strider (parent jockey) sits in the middle
		return [($this->passengers[0] ?? null) instanceof Strider ? RideableSeat::at(0, 1.7, 0) : RideableSeat::at(0, 1.7, -0.2)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		if($this->isBaby()){
			return false;
		}
		return match(true){
			$entity instanceof Player => $this->saddled,
			$entity instanceof ZombifiedPiglin => !$entity->isBaby(),
			$entity instanceof Strider => $entity->isBaby(),
			default => false
		};
	}

	private function canShearSaddle(Player $player, Item $item) : bool{
		return $this->saddled && $item->getTypeId() === ItemTypeIds::SHEARS && !$player->isSneaking() && count($this->passengers) === 0;
	}

	protected function getItemInteractButtonText(Player $player, Item $item) : ?string{
		if($this->canShearSaddle($player, $item)){
			return "action.interact.removesaddle";
		}
		return $item->getTypeId() === ItemTypeIds::SADDLE && !$this->saddled && !$this->isBaby() ? "action.interact.saddle" : null;
	}

	protected function getRideInteractText() : string{
		return "action.interact.ride.strider";
	}

	public function canBeSteeredBy(Player $rider) : bool{
		return $this->saddled && $rider->getInventory()->getItemInHand()->getTypeId() === ItemTypeIds::WARPED_FUNGUS_ON_A_STICK;
	}

	protected function getRiddenInput(RiderInput $input) : array{
		return [1.0, 0.0]; //walks where the rider looks
	}

	protected function getRiddenSpeed(Player $rider) : float{
		return $this->onLava ? 0.28 : 0.18;
	}

	protected function getBoostItemTypeId() : ?int{
		return ItemTypeIds::WARPED_FUNGUS_ON_A_STICK;
	}

	protected function getBoostProperties() : array{
		return [16 * 20, 1];
	}

	public function isOnLava() : bool{
		return $this->onLava;
	}

	/**
	 * Whether the strider is out of lava and shivering.
	 */
	public function isCold() : bool{
		return $this->cold;
	}

	public function isFireProof() : bool{
		return true;
	}

	/**
	 * Returns the height of the lava surface in the strider's column (feet block or the block below), or null.
	 */
	private function getLavaSurface() : ?float{
		$world = $this->getWorld();
		$x = (int) floor($this->location->x);
		$z = (int) floor($this->location->z);
		$y = (int) floor($this->location->y);
		foreach([$y, $y - 1] as $by){
			$block = $world->getBlockAt($x, $by, $z);
			if($block instanceof Lava){
				return $by + 1 - ($block->getFluidHeightPercent() - 0.1111111) + 0.0;
			}
		}
		return null;
	}

	protected function tryChangeMovement() : void{
		$surface = $this->isAlive() ? $this->getLavaSurface() : null;
		$wasOnLava = $this->onLava;
		$this->onLava = $surface !== null && $this->location->y <= $surface + 0.05;
		if($this->onLava !== $wasOnLava){
			$this->setMovementSpeed($this->onLava ? 0.32 : 0.16);
		}
		parent::tryChangeMovement();
		if($surface === null || !$this->onLava){
			return;
		}
		//stand on the lava surface: rise when sunk, and walk on it as on the ground
		$control = $this->moveControl;
		$my = max($this->motion->y, ($surface - $this->location->y) * 0.5);
		$this->motion = $this->motion->withComponents(
			$control->isMoving() ? $control->getVelocityX() : $this->motion->x * 0.5,
			$my,
			$control->isMoving() ? $control->getVelocityZ() : $this->motion->z * 0.5
		);
		$this->fallDistance = 0;
	}

	public function hasMovementUpdate() : bool{
		return parent::hasMovementUpdate() || $this->onLava;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$cold = !$this->onLava;
		if($cold !== $this->cold){
			$this->cold = $cold;
			$this->networkPropertiesDirty = true;
		}
		if(($this->server->getTick() + $this->id) % 10 === 0 && $this->isInContactWithWater()){
			$this->attack(new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 1));
		}
		return $hasUpdate;
	}

	protected function getAdultDrops() : array{
		if($this->saddled){
			//strider_saddled.json: no looting_enchant on the string
			return [VanillaItems::STRING()->setCount(mt_rand(2, 5)), VanillaItems::SADDLE()];
		}
		return [VanillaItems::STRING()->setCount(mt_rand(2, 5) + $this->lootingBonus())];
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::STRIDER_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::SADDLED, $this->saddled);
		//flag 40 is ActorFlags::SHAKING (query.is_shaking: cold texture), named VIBRATING in BedrockProtocol
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->cold);
	}
}
