<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\ai\goal\SkeletonRangedAttackGoal;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;

/**
 * Server-owned skeleton: a ranged hostile with Bedrock actor ID and native PM5
 * arrow projectiles. No replacement of network sessions or entity save format.
 */
class Skeleton extends Mob{
	public static function getNetworkTypeId() : string{ return EntityIds::SKELETON; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.99, 0.6); }
	public function getName() : string{ return "Skeleton"; }

	protected function registerGoals() : void{
		$this->getGoalSelector()->addGoal(1, new SkeletonRangedAttackGoal($this));
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

	public function getDrops() : array{
		return [
			VanillaItems::BONE()->setCount(mt_rand(0, 2)),
			VanillaItems::ARROW()->setCount(mt_rand(0, 2))
		];
	}

	public function getXpDropAmount() : int{ return 5; }
	public function getPickedItem() : ?Item{ return VanillaItems::SKELETON_SPAWN_EGG(); }
}
