<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\AntiCheatSession;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use pocketmine\player\Player;
use function is_infinite;
use function is_nan;

/**
 * Validates invariants that must hold before PlayerAuthInput reaches gameplay.
 *
 * This guard only rejects structurally impossible data. Behavioural checks
 * (speed, fly, timer, etc.) consume InputTimeline later and use tolerances.
 */
final class ProtocolGuard{
	public function validatePlayerAuthInput(Player $player, AntiCheatSession $session, PlayerAuthInputPacket $packet) : bool{
		if(!$this->hasFiniteInput($packet)){
			$session->addViolation("protocol.non_finite", 5.0);
			return false;
		}

		if(!$this->isValidInputMode($packet->getInputMode())){
			$session->addViolation("protocol.input_mode", 2.0);
			return false;
		}

		if(!$this->isValidPlayMode($packet->getPlayMode())){
			$session->addViolation("protocol.play_mode", 2.0);
			return false;
		}

		if(!$this->isValidInteractionMode($packet->getInteractionMode())){
			$session->addViolation("protocol.interaction_mode", 2.0);
			return false;
		}

		$latest = $session->getInputTimeline()->getLatest();
		if($latest !== null){
			if($packet->getTick() < $latest->clientTick){
				$session->addViolation("protocol.tick_regression", 3.0);
				return false;
			}
			if($packet->getTick() === $latest->clientTick){
				// Same-tick input is unusual, but don't enforce until we have
				// measurements from real supported clients.
				$session->addViolation("protocol.duplicate_tick", 0.1);
			}
		}

		$session->getInputTimeline()->record($packet);
		return true;
	}

	private function hasFiniteInput(PlayerAuthInputPacket $packet) : bool{
		$position = $packet->getPosition();
		$delta = $packet->getDelta();
		$interactRotation = $packet->getInteractRotation();
		$camera = $packet->getCameraOrientation();
		$rawMove = $packet->getRawMove();

		foreach([
			$position->x, $position->y, $position->z,
			$packet->getPitch(), $packet->getYaw(), $packet->getHeadYaw(),
			$packet->getMoveVecX(), $packet->getMoveVecZ(),
			$delta->x, $delta->y, $delta->z,
			$packet->getAnalogMoveVecX(), $packet->getAnalogMoveVecZ(),
			$interactRotation->x, $interactRotation->y,
			$camera->x, $camera->y, $camera->z,
			$rawMove->x, $rawMove->y
		] as $value){
			if(is_infinite($value) || is_nan($value)){
				return false;
			}
		}

		$vrGaze = $packet->getVrGazeDirection();
		if($vrGaze !== null){
			foreach([$vrGaze->x, $vrGaze->y, $vrGaze->z] as $value){
				if(is_infinite($value) || is_nan($value)){
					return false;
				}
			}
		}

		return true;
	}

	private function isValidInputMode(int $mode) : bool{
		return $mode === InputMode::MOUSE_KEYBOARD ||
			$mode === InputMode::TOUCHSCREEN ||
			$mode === InputMode::GAME_PAD ||
			$mode === InputMode::MOTION_CONTROLLER;
	}

	private function isValidPlayMode(int $mode) : bool{
		return $mode >= PlayMode::NORMAL && $mode <= PlayMode::EXIT_LEVEL_LIVING_ROOM;
	}

	private function isValidInteractionMode(int $mode) : bool{
		return $mode === InteractionMode::TOUCH ||
			$mode === InteractionMode::CROSSHAIR ||
			$mode === InteractionMode::CLASSIC;
	}
}
