<?php
declare(strict_types=1);
namespace pocketmine\entity\animal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\item\VanillaItems;

final class Sheep extends Animal{
	public static function getNetworkTypeId() : string{ return EntityIds::SHEEP; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.3,0.9); }
	public function getName() : string{ return "Sheep"; }
	public function getDrops() : array{ return [VanillaItems::RAW_MUTTON()->setCount(mt_rand(1,2))]; }
}
