<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\protocol\ProtocolCapabilities;
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
	public function validatePlayerAuthInput(Player $player, AntiCheatSession $session, PlayerAuthInputPacket $packet, int $protocolId) : bool{
		if(!$this->hasFiniteInput($packet, $protocolId)){
			$session->addViolation("protocol.non_finite", 5.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		if(!$this->isValidInputMode($packet->getInputMode())){
			$session->addViolation("protocol.input_mode", 2.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		if(!$this->isValidPlayMode($packet->getPlayMode())){
			$session->addViolation("protocol.play_mode", 2.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		if(!$this->isValidInteractionMode($packet->getInteractionMode())){
			$session->addViolation("protocol.interaction_mode", 2.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		$latest = $session->getInputTimeline()->getLatest();
		if($latest !== null){
			if($packet->getTick() < $latest->clientTick){
				// Record but don't enforce yet. The accepted protocol range is
				// broad and we want real-client measurements before treating
				// reordering as malicious.
				$session->addViolation("protocol.tick_regression", 0.5);
				return true;
			}
			if($packet->getTick() === $latest->clientTick){
				// Same-tick input is unusual, but don't enforce until we have
				// measurements from real supported clients.
				$session->addViolation("protocol.duplicate_tick", 0.1);
			}
		}

		$session->getInputTimeline()->record($packet, $protocolId);
		return true;
	}

	private function hasFiniteInput(PlayerAuthInputPacket $packet, int $protocolId) : bool{
		$capabilities = ProtocolCapabilities::fromProtocolId($protocolId);
		$position = $packet->getPosition();
		$delta = $packet->getDelta();

		$values = [
			$position->x, $position->y, $position->z,
			$packet->getPitch(), $packet->getYaw(), $packet->getHeadYaw(),
			$packet->getMoveVecX(), $packet->getMoveVecZ(),
			$delta->x, $delta->y, $delta->z,
			$packet->getAnalogMoveVecX(), $packet->getAnalogMoveVecZ()
		];

		if($capabilities->hasInteractRotation && $capabilities->hasCameraOrientation){
			$interactRotation = $packet->getInteractRotation();
			$camera = $packet->getCameraOrientation();
			$values[] = $interactRotation->x;
			$values[] = $interactRotation->y;
			$values[] = $camera->x;
			$values[] = $camera->y;
			$values[] = $camera->z;
		}else{
			$vrGaze = $packet->getVrGazeDirection();
			if($vrGaze !== null){
				$values[] = $vrGaze->x;
				$values[] = $vrGaze->y;
				$values[] = $vrGaze->z;
			}
		}

		if($capabilities->hasRawMove){
			$rawMove = $packet->getRawMove();
			$values[] = $rawMove->x;
			$values[] = $rawMove->y;
		}

		foreach($values as $value){
			if(is_infinite($value) || is_nan($value)){
				return false;
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
