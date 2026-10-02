<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\AntiCheatSession;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\player\Player;
use function abs;
use function sqrt;

/**
 * Movement telemetry layer.
 *
 * This intentionally does not enforce movement yet. It records measurable
 * anomalies while we collect data across every protocol supported by the fork.
 */
final class MovementValidator{
	public function observe(Player $player, AntiCheatSession $session, PlayerAuthInputPacket $packet, ?int $ping) : void{
		$latest = $session->getInputTimeline()->getLatest();
		$previous = $session->getInputTimeline()->getPrevious();
		if($latest === null || $previous === null || $latest->clientTick !== $packet->getTick()){
			return;
		}

		$tickDelta = $latest->clientTick - $previous->clientTick;
		$timeDelta = $latest->receivedAt - $previous->receivedAt;

		if($tickDelta <= 0){
			$session->addViolation("movement.non_forward_tick", 0.1);
			return;
		}

		$dx = $latest->x - $previous->x;
		$dy = $latest->y - $previous->y;
		$dz = $latest->z - $previous->z;
		$horizontal = sqrt(($dx * $dx) + ($dz * $dz));
		$distance3d = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));

		$horizontalPerTick = $horizontal / $tickDelta;
		$verticalPerTick = $dy / $tickDelta;
		$distancePerTick = $distance3d / $tickDelta;

		$session->recordMovementSample(
			$latest->protocolId,
			$tickDelta,
			$timeDelta,
			$horizontalPerTick,
			$verticalPerTick,
			$distancePerTick,
			$ping,
			$player->isSprinting(),
			$player->isFlying(),
			$player->isSwimming(),
			$player->isGliding()
		);

		// Telemetry-only signals. These are deliberately very broad and are
		// not used for punishment or setback.
		if($timeDelta > 0.0){
			$clientTicksPerSecond = $tickDelta / $timeDelta;
			if($clientTicksPerSecond > 40.0){
				$session->addViolation("movement.timer_observation", 0.1);
			}
		}

		if(!$player->isFlying() && !$player->isGliding() && !$player->isSwimming()){
			if(abs($verticalPerTick) > 4.0){
				$session->addViolation("movement.vertical_observation", 0.1);
			}
			if($horizontalPerTick > 4.0){
				$session->addViolation("movement.horizontal_observation", 0.1);
			}
		}
	}
}
