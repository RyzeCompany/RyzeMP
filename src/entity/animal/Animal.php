<?php
declare(strict_types=1);
namespace pocketmine\entity\animal;

use pocketmine\entity\Ageable;
use pocketmine\entity\Mob;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;

/** Lightweight passive animal with vanilla Bedrock baby flag persisted in NBT. */
abstract class Animal extends Mob implements Ageable{
	private bool $baby=false;
	public function isBaby() : bool{ return $this->baby; }
	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->baby=$nbt->getByte("IsBaby",0)!==0;
	}
	public function saveNBT() : CompoundTag{
		$nbt=parent::saveNBT();$nbt->setByte("IsBaby",$this->baby?1:0);
		return $nbt;
	}
	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY,$this->baby);
	}
}
