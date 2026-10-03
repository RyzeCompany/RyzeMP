<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\AntiCheatSession;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use function cos;
use function deg2rad;
use function is_infinite;
use function is_nan;
use function max;
use function min;
use function microtime;
use function number_format;
use function sin;
use function sqrt;

final class CombatValidator{
	private const BASE_TELEMETRY_REACH = 3.80;
	private const MAX_TELEMETRY_REACH = 4.60;
	private const HARD_REACH = 8.5;

	public function validateAttack(
		Player $player,
		Entity $target,
		UseItemOnEntityTransactionData $data,
		AntiCheatSession $session,
		int $protocolId,
		?int $ping
	) : bool{
		if(!$session->getCombatBudget()->consume()){
			$session->addViolation("combat.action_rate", 1.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		if($target === $player || $target->getWorld() !== $player->getWorld()){
			$session->addViolation("combat.invalid_target", 3.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		if(!$this->hasFiniteTransactionVectors($data)){
			$session->addViolation("combat.non_finite", 5.0, AntiCheatSession::MODE_BLOCK);
			return false;
		}

		$frame = $session->getInputTimeline()->getLatest();
		$eye = $frame !== null ?
			new Vector3($frame->x, $frame->y, $frame->z) :
			$player->getEyePos();

		$closest = $this->closestPointOnBox($eye, $target->getBoundingBox());
		$reach = sqrt($eye->distanceSquared($closest));

		$direction = $frame !== null ?
			$this->directionFromRotation($frame->yaw, $frame->pitch) :
			$player->getDirectionVector();

		$toTarget = $closest->subtractVector($eye);
		$targetDistance = sqrt($toTarget->lengthSquared());
		$directionDot = $targetDistance > 0.0001 ? $direction->dot($toTarget->multiply(1 / $targetDistance)) : 1.0;

		$clientTick = $frame?->clientTick;
		$session->recordCombatSample(
			$protocolId,
			$target->getId(),
			$clientTick,
			microtime(true),
			$reach,
			$directionDot,
			$ping
		);

		if($reach > self::HARD_REACH){
			$session->addViolation(
				"combat.hard_reach",
				3.0,
				AntiCheatSession::MODE_BLOCK,
				"reach=" . number_format($reach, 2) .
				" limit=" . number_format(self::HARD_REACH, 2) .
				" direction=" . number_format($directionDot, 2)
			);
			return false;
		}

		$telemetryReach = $this->getTelemetryReachLimit($ping);
		if($reach > $telemetryReach){
			$session->addViolation(
				"combat.reach_observation",
				0.25,
				AntiCheatSession::MODE_OBSERVE,
				"reach=" . number_format($reach, 2) .
				" limit=" . number_format($telemetryReach, 2) .
				" direction=" . number_format($directionDot, 2)
			);
		}

		if($directionDot < 0.0){
			$session->addViolation(
				"combat.direction_observation",
				0.15,
				AntiCheatSession::MODE_OBSERVE,
				"direction=" . number_format($directionDot, 2) .
				" reach=" . number_format($reach, 2)
			);
		}

		$this->observeAttackPattern($session, $target->getId(), $clientTick);
		return true;
	}

	private function observeAttackPattern(AntiCheatSession $session, int $targetRuntimeId, ?int $clientTick) : void{
		$recent = $session->getCombatSamples();
		$count = count($recent);
		if($count < 2){
			return;
		}

		$latest = $recent[$count - 1];
		$windowStart = $latest->receivedAt - 0.10;
		$targets = [];
		$attacks = 0;

		for($i = $count - 1; $i >= 0; --$i){
			$sample = $recent[$i];
			if($sample->receivedAt < $windowStart){
				break;
			}
			++$attacks;
			$targets[$sample->targetRuntimeId] = true;
		}

		if($attacks >= 4){
			$session->addViolation("combat.burst_observation", 0.1);
		}
		if(count($targets) >= 3){
			$session->addViolation("combat.multi_target_observation", 0.25);
		}

		if($clientTick !== null){
			$sameTickTargets = [];
			$sameTickAttacks = 0;
			for($i = $count - 1; $i >= 0; --$i){
				$sample = $recent[$i];
				if($sample->clientTick !== $clientTick){
					break;
				}
				++$sameTickAttacks;
				$sameTickTargets[$sample->targetRuntimeId] = true;
			}
			if($sameTickAttacks >= 3){
				$session->addViolation("combat.same_tick_burst", 0.2);
			}
			if(count($sameTickTargets) >= 3){
				$session->addViolation("combat.same_tick_multi_target", 0.4);
			}
		}
	}

	private function hasFiniteTransactionVectors(UseItemOnEntityTransactionData $data) : bool{
		foreach([$data->getPlayerPosition(), $data->getClickPosition()] as $vector){
			foreach([$vector->x, $vector->y, $vector->z] as $value){
				if(is_infinite($value) || is_nan($value)){
					return false;
				}
			}
		}
		return true;
	}

	private function getTelemetryReachLimit(?int $ping) : float{
		// This is telemetry-only. A small latency allowance prevents ordinary
		// target interpolation/desync from looking like reach.
		$latencyAllowance = min(0.80, max(0, $ping ?? 0) / 1000 * 1.5);
		return min(self::MAX_TELEMETRY_REACH, self::BASE_TELEMETRY_REACH + $latencyAllowance);
	}

	private function closestPointOnBox(Vector3 $point, AxisAlignedBB $bb) : Vector3{
		return new Vector3(
			max($bb->minX, min($point->x, $bb->maxX)),
			max($bb->minY, min($point->y, $bb->maxY)),
			max($bb->minZ, min($point->z, $bb->maxZ))
		);
	}

	private function directionFromRotation(float $yaw, float $pitch) : Vector3{
		$y = -sin(deg2rad($pitch));
		$xz = cos(deg2rad($pitch));
		$x = -$xz * sin(deg2rad($yaw));
		$z = $xz * cos(deg2rad($yaw));
		return (new Vector3($x, $y, $z))->normalize();
	}
}
