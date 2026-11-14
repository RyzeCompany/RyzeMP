<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\ai\goal\EndermiteAttackGoal;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\World;

/** VanillaMine endermite: a small hostile spawned occasionally by a landing ender pearl. */
class Endermite extends Mob{
	public static function getNetworkTypeId() : string{ return EntityIds::ENDERMITE; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.3, 0.4); }
	public function getName() : string{ return "Endermite"; }

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(8);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		$this->getGoalSelector()->addGoal(1, new EndermiteAttackGoal($this));
		parent::registerGoals();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			$this->flagForDespawn();
			return false;
		}
		return $changed;
	}

	public function attackMelee(Player $target) : bool{
		$event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2.0);
		$target->attack($event);
		return !$event->isCancelled();
	}

	public function getDrops() : array{ return []; }
	public function getXpDropAmount() : int{
		$cause = $this->getLastDamageCause();
		return $cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Player ? 3 : 0;
	}
	public function getPickedItem() : ?Item{ return null; }
}
