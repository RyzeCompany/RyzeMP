<?php
declare(strict_types=1);
namespace pocketmine\entity\animal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\item\VanillaItems;

final class Chicken extends Animal{
	public static function getNetworkTypeId() : string{ return EntityIds::CHICKEN; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.7,0.4); }
	public function getName() : string{ return "Chicken"; }
	public function getDrops() : array{
		return [VanillaItems::RAW_CHICKEN(),VanillaItems::FEATHER()->setCount(mt_rand(0,2))];
	}
}
