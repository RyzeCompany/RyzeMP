<?php
declare(strict_types=1);
namespace pocketmine\entity\animal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\item\VanillaItems;

final class Cow extends Animal{
	public static function getNetworkTypeId() : string{ return EntityIds::COW; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.4,0.9); }
	public function getName() : string{ return "Cow"; }
	public function getDrops() : array{ return [VanillaItems::RAW_BEEF()->setCount(mt_rand(1,3))]; }
}
