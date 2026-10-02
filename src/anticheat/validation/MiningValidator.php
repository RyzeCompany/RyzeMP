<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\AntiCheatSession;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\player\Player;

final class MiningValidator{
	private const MAX_SURVIVAL_DISTANCE = 8.0;
	private const MAX_CREATIVE_DISTANCE = 14.0;

	public function validate(Player $player, AntiCheatSession $session, int $action, Vector3 $pos) : bool{
		if(!$session->getMiningBudget()->consume()){
			$session->addViolation("mining.action_rate", 1.0);
			return false;
		}

		if($this->actionHasBlockPosition($action)){
			$maxDistance = $player->isCreative() ? self::MAX_CREATIVE_DISTANCE : self::MAX_SURVIVAL_DISTANCE;
			$target = $pos->add(0.5, 0.5, 0.5);
			if($target->distanceSquared($player->getLocation()) > $maxDistance * $maxDistance){
				$session->addViolation("mining.reach", 2.0);
				return false;
			}
		}

		switch($action){
			case PlayerAction::START_BREAK:
			case PlayerAction::CONTINUE_DESTROY_BLOCK:
				$session->setMiningTarget($pos);
				break;

			case PlayerAction::PREDICT_DESTROY_BLOCK:
				$target = $session->getMiningTarget();
				if(
					$target !== null &&
					$target->distanceSquared($pos) > 0.0001
				){
					// Record this for telemetry, but don't reject it yet. Bedrock
					// can pipeline mining actions under latency and this needs
					// real-server data before becoming an enforcement check.
					$session->addViolation("mining.sequence", 0.25);
				}
				$session->setMiningTarget(null);
				break;

			case PlayerAction::ABORT_BREAK:
			case PlayerAction::STOP_BREAK:
				$session->setMiningTarget(null);
				break;
		}

		return true;
	}

	private function actionHasBlockPosition(int $action) : bool{
		return match($action){
			PlayerAction::START_BREAK,
			PlayerAction::CONTINUE_DESTROY_BLOCK,
			PlayerAction::CRACK_BREAK,
			PlayerAction::PREDICT_DESTROY_BLOCK => true,
			default => false
		};
	}
}
