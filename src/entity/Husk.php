<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\monster\UnderwaterConversionTimer;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\world\World;

/** VanillaMine husk: daylight immune, hunger on hit, converts to zombie after immersion. */
class Husk extends Zombie{
	private UnderwaterConversionTimer $conversion;

	public static function getNetworkTypeId() : string{ return EntityIds::HUSK; }
	public function getName() : string{ return "Husk"; }
	protected function burnsInDaylight() : bool{ return false; }
	public function canBreathe() : bool{ return true; }
	public function getPickedItem() : ?Item{ return null; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->conversion = UnderwaterConversionTimer::fromNBT($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->conversion->saveNBT($nbt);
		return $nbt;
	}

	public function attackMelee(Player $target) : bool{
		$hit = parent::attackMelee($target);
		if($hit && $target->isAlive()){
			$target->getEffects()->add(new EffectInstance(VanillaEffects::HUNGER(), 600));
		}
		return $hit;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->isClosed() || !$this->isAlive() || $this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return $changed;
		}
		$before = $this->conversion->isConverting();
		if($this->conversion->tick($this, $tickDiff) === UnderwaterConversionTimer::STATE_DONE){
			$this->convertToZombie();
		}elseif($before !== $this->conversion->isConverting()){
			$this->networkPropertiesDirty = true;
		}
		return $changed;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->conversion->isConverting());
	}

	private function convertToZombie() : void{
		$world = $this->getWorld();
		$zombie = new Zombie($this->getLocation(), $this->saveNBT());
		$zombie->setCanSaveWithChunk($this->canSaveWithChunk());
		$world->transferNaturalMob($this, $zombie);
		$zombie->spawnToAll();
		$this->flagForDespawn();
	}
}
