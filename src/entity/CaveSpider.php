<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\World;

/** VanillaMine's cave spider bite: poison lasts seven seconds on normal, fifteen on hard. */
class CaveSpider extends Spider{
	public static function getNetworkTypeId() : string{ return EntityIds::CAVE_SPIDER; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.5, 0.7); }
	public function getName() : string{ return "Cave Spider"; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(12);
		if($this->getHealth() > 12){$this->setHealth(12);}
	}

	public static function getPoisonTicks(int $difficulty) : int{
		return match($difficulty){
			World::DIFFICULTY_NORMAL => 140,
			World::DIFFICULTY_HARD => 300,
			default => 0
		};
	}

	public function attackMelee(Player $target) : bool{
		$hit = parent::attackMelee($target);
		$ticks = self::getPoisonTicks($this->getWorld()->getDifficulty());
		if($hit && $ticks > 0 && $target->isAlive()){
			$target->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), $ticks));
		}
		return $hit;
	}
}
