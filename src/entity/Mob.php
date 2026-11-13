<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\navigation\GroundNavigator;
use pocketmine\nbt\tag\CompoundTag;

/**
 * A living actor controlled on the server, without replacing PM5 Entity APIs.
 * Goal selection and path search obey separate fair per-tick budgets.
 */
abstract class Mob extends Living{
	private GoalSelector $goals;
	private GroundNavigator $navigation;
	private int $aiWaitingSince = -1;
	private int $aiTicks = 0;

	public function __construct(Location $location, ?CompoundTag $nbt = null){
		parent::__construct($location,$nbt);
		$this->goals = new GoalSelector(4,$this->getId() % 4);
		$this->navigation = new GroundNavigator($this);
		$this->setStepHeight(0.6);
		$this->registerGoals();
	}

	protected function registerGoals() : void{
		$this->goals->addGoal(10,new RandomStrollGoal($this));
	}
	public function getGoalSelector() : GoalSelector{ return $this->goals; }
	public function getNavigation() : GroundNavigator{ return $this->navigation; }
	public function getAiTicks() : int{ return $this->aiTicks; }

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->isClosed() || !$this->isAlive()){
			return $changed;
		}
		$this->aiTicks += $tickDiff;
		$w = $this->getWorld();
		if($w->getPlayers() === []){
			if($this->navigation->isNavigating()){ $this->navigation->stop(); }
			return true;
		}
		$evaluate = AiBudget::takeGoalUpdate($w->getServer()->getTick(),$this->aiWaitingSince);
		$this->goals->tick($evaluate);
		$this->navigation->tick();
		return true;
	}
}
