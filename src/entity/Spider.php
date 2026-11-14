<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\ai\goal\SpiderAttackGoal;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\world\World;

/** Bedrock spider: neutral in bright light, angry when provoked and able to climb a wall. */
class Spider extends Mob{
	private const NEUTRAL_LIGHT = 12;
	private int $angerEndTick = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::SPIDER; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.9, 1.4); }
	public function getName() : string{ return "Spider"; }

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(16);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		$this->getGoalSelector()->addGoal(1, new SpiderAttackGoal($this));
		parent::registerGoals();
	}

	public function isInBrightLight() : bool{
		$p = $this->getPosition();
		return $this->getWorld()->getFullLightAt($p->getFloorX(), $p->getFloorY() + 1, $p->getFloorZ()) >= self::NEUTRAL_LIGHT;
	}

	public function isAngry() : bool{ return $this->getWorld()->getServer()->getTick() < $this->angerEndTick; }

	public function becomeAngry() : void{
		$this->angerEndTick = $this->getWorld()->getServer()->getTick() + mt_rand(140, 260);
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->isAlive() && $source instanceof EntityDamageByEntityEvent &&
			$source->getDamager() instanceof Living){
			$this->becomeAngry();
		}
	}

	/** Returns false when a plugin or invulnerability cancelled the hit. */
	public function attackMelee(Player $target) : bool{
		$event = new EntityDamageByEntityEvent($this, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2.0);
		$target->attack($event);
		return !$event->isCancelled();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			$this->flagForDespawn();
			return false;
		}
		if($this->isAlive() && $this->isCollidedHorizontally && $this->getNavigation()->isNavigating()){
			$this->setCanClimbWalls(true);
			$this->setMotion($this->getMotion()->withComponents(null, max(0.2, $this->getMotion()->y), null));
			$this->resetFallDistance();
		}elseif($this->canClimbWalls() && $this->isOnGround()){
			$this->setCanClimbWalls(false);
		}
		return $changed;
	}

	public function getDrops() : array{
		$drops = [VanillaItems::STRING()->setCount(mt_rand(0, 2))];
		$cause = $this->getLastDamageCause();
		if($cause instanceof EntityDamageByEntityEvent && $cause->getDamager() instanceof Player){
			$drops[] = VanillaItems::SPIDER_EYE()->setCount(mt_rand(0, 1));
		}
		return $drops;
	}

	public function getXpDropAmount() : int{ return 5; }
	public function getPickedItem() : ?Item{ return null; }
}
