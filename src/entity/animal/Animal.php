<?php
declare(strict_types=1);

namespace pocketmine\entity\animal;

use pocketmine\entity\Ageable;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\SpawnerEntityRegistry;
use pocketmine\entity\ai\AnimalBreedingPolicy;
use pocketmine\event\entity\MobPreSpawnEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;

/**
 * Common age and breeding lifecycle for passive farm animals.
 *
 * The signed Age value is saved in the chunk NBT: negative = growing baby,
 * zero = adult and positive = adult breeding cooldown. This preserves older
 * IsBaby-only saves without requiring any migration.
 */
abstract class Animal extends Mob implements Ageable{
	private int $age = 0;
	private int $loveTicks = 0;

	public function isBaby() : bool{ return $this->age < 0; }
	public function getAge() : int{ return $this->age; }

	public function setAge(int $age) : void{
		$wasBaby = $this->isBaby();
		$this->age = max(-24000, min(6000, $age));
		if($wasBaby !== $this->isBaby()){
			$this->setScale($this->isBaby() ? 0.5 : 1.0);
			$this->networkPropertiesDirty = true;
		}
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->age = 0;
		$this->setAge($nbt->getInt("Age", $nbt->getByte("IsBaby", 0) !== 0 ? -24000 : 0));
		$this->loveTicks = max(0, min(600, $nbt->getInt("InLove", 0)));
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte("IsBaby", $this->isBaby() ? 1 : 0);
		$nbt->setInt("Age", $this->age);
		$nbt->setInt("InLove", $this->loveTicks);
		return $nbt;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$item = $player->getInventory()->getItemInHand();
		if(!AnimalBreedingPolicy::isBreedingFood(static::class, $item->getTypeId())){
			return parent::onInteract($player, $clickPos);
		}
		if($this->age > 0 || ($this->age === 0 && $this->loveTicks > 0)){
			return false;
		}
		if($this->isBaby()){
			// Feeding babies accelerates the remaining growth by 10%.
			$this->setAge(min(0, $this->age + AnimalBreedingPolicy::growthFromFood($this->age)));
		}else{
			$this->loveTicks = 600;
		}
		if($player->hasFiniteResources()){
			$item->pop();
			$player->getInventory()->setItemInHand($item);
		}
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->isClosed() || !$this->isAlive()){return $changed;}
		if($this->age < 0){
			$this->setAge(min(0, $this->age + $tickDiff));
		}elseif($this->age > 0){
			$this->setAge(max(0, $this->age - $tickDiff));
		}
		if($this->loveTicks > 0){
			$this->loveTicks = max(0, $this->loveTicks - $tickDiff);
			if($this->age === 0 && $this->getAiTicks() % 20 === 0){
				$this->tryBreed();
			}
		}
		return $changed;
	}

	private function tryBreed() : void{
		$world = $this->getWorld();
		foreach($world->getNearbyEntities($this->getBoundingBox()->expandedCopy(4.0, 2.0, 4.0), $this) as $entity){
			if(!$entity instanceof Animal || $entity::class !== static::class ||
				$entity->age !== 0 || $entity->loveTicks <= 0 || $entity->isClosed()){
				continue;
			}
			if($entity->getPosition()->distanceSquared($this->getPosition()) > 3.0 ** 2){
				continue;
			}
			$at = $this->getPosition();
			$other = $entity->getPosition();
			$location = new Location(($at->x + $other->x) / 2, $at->y, ($at->z + $other->z) / 2, $world, 0.0, 0.0);
			if(MobPreSpawnEvent::hasHandlers()){
				$ev = new MobPreSpawnEvent(static::getNetworkTypeId(), $location, SpawnReason::BREEDING);
				$ev->call();
				if($ev->isCancelled()){return;}
			}
			$baby = SpawnerEntityRegistry::getInstance()->spawn(static::getNetworkTypeId(), $location);
			if(!$baby instanceof Animal || $baby::class !== static::class){
				$baby?->close();
				return;
			}
			$baby->setAge(-24000);
			$baby->spawnToAll();
			$this->loveTicks = 0;
			$entity->loveTicks = 0;
			$this->setAge(6000);
			$entity->setAge(6000);
			return;
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->isBaby());
	}
}
