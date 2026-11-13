<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\ai\DaylightBurnPolicy;
use pocketmine\item\Durable;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
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
	private bool $aiEnabled = true;

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
	/** Allow plugins to disable AI for custom NPCs without changing their entity type. */
	public function setAiEnabled(bool $enabled) : void{
		$this->aiEnabled = $enabled;
		if(!$enabled){
			$this->goals->stopAll();
			$this->navigation->stop();
		}
	}
	public function isAiEnabled() : bool{ return $this->aiEnabled; }
	public function getGoalSelector() : GoalSelector{ return $this->goals; }
	public function getNavigation() : GroundNavigator{ return $this->navigation; }
	public function getAiTicks() : int{ return $this->aiTicks; }

	/** Undead burning is opt-in for mob types which actually burn in daylight. */
	protected function burnsInDaylight() : bool{ return false; }

	private function tickDaylightBurn() : void{
		if(!$this->burnsInDaylight() || $this->isOnFire() || mt_rand(0, 4) !== 0){return;}
		$world = $this->getWorld();
		$eye = $this->getEyePos();
		$x = $eye->getFloorX(); $y = $eye->getFloorY(); $z = $eye->getFloorZ();
		if(!DaylightBurnPolicy::isExposed(
			$world->getServer()->getDimensionManager()->getDimension($world) === DimensionIds::OVERWORLD,
			$world->getSkyLightReduction(),
			$world->getPotentialBlockSkyLightAt($x, $y, $z),
			$this->isUnderwater(),
			$world->getWeather()->isRainingAt($x, $y, $z)
		)){return;}
		$inventory = $this->getArmorInventory();
		$helmet = $inventory->getHelmet();
		if(!$helmet->isNull()){
			if($helmet instanceof Durable && mt_rand(0,1) === 0){
				$helmet->applyDamage(1);
				$inventory->setHelmet($helmet->isBroken() ? VanillaItems::AIR() : $helmet);
			}
			return;
		}
		$this->setOnFire(8);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->isClosed() || !$this->isAlive()){
			return $changed;
		}
		$this->aiTicks += $tickDiff;
		$w = $this->getWorld();
		if(!$this->aiEnabled || !AiBudget::isEnabledInWorld($w->getFolderName())){ return $changed; }
		if($this->aiTicks % 20 === 0){$this->tickDaylightBurn();}
		if($w->getPlayers() === []){
			if($this->navigation->isNavigating()){ $this->navigation->stop(); }
			return true;
		}
		$evaluate = AiBudget::takeGoalUpdate($w->getServer()->getTick(),$this->aiWaitingSince);
		$this->goals->tick($evaluate);
		$this->navigation->tick();
		return true;
	}
	/** Break goal/navigator links to this Mob on despawn. */
	protected function destroyCycles() : void{
		$this->goals->stopAll();
		unset($this->goals, $this->navigation);
		parent::destroyCycles();
	}
}
