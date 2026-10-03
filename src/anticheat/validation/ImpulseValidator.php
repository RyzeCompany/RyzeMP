<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\AntiCheatSession;
use pocketmine\player\Player;
use function abs;
use function max;
use function microtime;
use function min;
use function sqrt;

/**
 * Telemetry-only validation of server-authoritative impulses.
 *
 * It never rejects movement. The goal is to learn how supported client
 * versions respond to knockback, fishing pulls and arbitrary server motion
 * before any AntiKnockback enforcement is enabled.
 */
final class ImpulseValidator{
	public function observe(Player $player, AntiCheatSession $session, ?int $ping) : void{
		$tracker = $session->getImpulseTracker();
		$impulse = $tracker->getOldestPending();
		$latest = $session->getInputTimeline()->getLatest();
		if($impulse === null || $latest === null){
			return;
		}

		$age = microtime(true) - $impulse->createdAt;
		$latencyAllowance = min(0.5, max(0, $ping ?? 0) / 1000);
		$evaluationDelay = 0.25 + $latencyAllowance;
		if($age < $evaluationDelay){
			return;
		}

		$dx = $latest->x - $impulse->originX;
		$dy = $latest->y - $impulse->originY;
		$dz = $latest->z - $impulse->originZ;

		$expectedHorizontal = sqrt(($impulse->x * $impulse->x) + ($impulse->z * $impulse->z));
		$observedHorizontalProjection = 0.0;
		if($expectedHorizontal > 0.0001){
			$observedHorizontalProjection =
				(($dx * $impulse->x) + ($dz * $impulse->z)) / $expectedHorizontal;
		}

		$respondedHorizontally = $expectedHorizontal <= 0.0001 || $observedHorizontalProjection > 0.015;
		$respondedVertically = abs($impulse->y) <= 0.05 || ($impulse->y > 0 ? $dy > 0.01 : $dy < -0.01);

		if(!$respondedHorizontally && !$respondedVertically){
			$session->addViolation("impulse.no_response." . $impulse->source, 0.25);
		}elseif(!$respondedHorizontally){
			$session->addViolation("impulse.low_horizontal_response." . $impulse->source, 0.1);
		}elseif(!$respondedVertically){
			$session->addViolation("impulse.low_vertical_response." . $impulse->source, 0.1);
		}

		$tracker->discardOldest();
	}
}
