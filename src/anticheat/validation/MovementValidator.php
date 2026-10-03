<?php

declare(strict_types=1);

namespace pocketmine\anticheat\validation;

use pocketmine\anticheat\AntiCheatSession;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\player\Player;
use function count;
use function max;
use function number_format;
use function sqrt;

/**
 * Movement telemetry layer.
 *
 * V2 deliberately remains telemetry-only. It uses sustained windows instead
 * of single packet deltas so network batching/jitter doesn't become a flag.
 */
final class MovementValidator{
	private const TIMER_WINDOW_FRAMES = 20;
	private const TIMER_MIN_SECONDS = 0.45;
	private const TIMER_OBSERVE_TPS = 28.0;

	private const SPEED_OBSERVE_PER_TICK = 0.90;
	private const FLY_MIN_AIR_TICKS = 20;
	private const FLY_MIN_VERTICAL_PER_TICK = -0.04;

	public function observe(Player $player, AntiCheatSession $session, PlayerAuthInputPacket $packet, ?int $ping) : void{
		$latest = $session->getInputTimeline()->getLatest();
		$previous = $session->getInputTimeline()->getPrevious();
		if($latest === null || $previous === null || $latest->clientTick !== $packet->getTick()){
			return;
		}

		$tickDelta = $latest->clientTick - $previous->clientTick;
		$timeDelta = $latest->receivedAt - $previous->receivedAt;

		if($tickDelta <= 0){
			$session->addViolation(
				"movement.non_forward_tick",
				0.1,
				AntiCheatSession::MODE_OBSERVE,
				"tickDelta=" . $tickDelta
			);
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

		$hasActiveImpulse = $session->getImpulseTracker()->hasActiveImpulse();

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

		$this->observeTimerWindow($session);

		$movementExempt =
			$hasActiveImpulse ||
			$player->getAllowFlight() ||
			$player->isFlying() ||
			$player->isGliding() ||
			$player->isSwimming() ||
			$player->isSpectator();

		if($movementExempt){
			$session->resetAirTicks();
			return;
		}

		if($player->isOnGround()){
			$session->resetAirTicks();
		}else{
			$airTicks = $session->addAirTicks($tickDelta);
			if($airTicks >= self::FLY_MIN_AIR_TICKS && $verticalPerTick >= self::FLY_MIN_VERTICAL_PER_TICK){
				$session->addViolation(
					"movement.fly_observation",
					0.25,
					AntiCheatSession::MODE_OBSERVE,
					"airTicks=" . $airTicks .
					" vertical=" . number_format($verticalPerTick, 3) .
					" horizontal=" . number_format($horizontalPerTick, 3)
				);
			}
		}

		if($horizontalPerTick > self::SPEED_OBSERVE_PER_TICK){
			$session->addViolation(
				"movement.speed_observation",
				0.20,
				AntiCheatSession::MODE_OBSERVE,
				"horizontal=" . number_format($horizontalPerTick, 3) .
				" limit=" . number_format(self::SPEED_OBSERVE_PER_TICK, 2) .
				" tickDelta=" . $tickDelta
			);
		}
	}

	private function observeTimerWindow(AntiCheatSession $session) : void{
		$frames = $session->getInputTimeline()->getFrames();
		$count = count($frames);
		if($count < self::TIMER_WINDOW_FRAMES){
			return;
		}

		$latest = $frames[$count - 1];
		$oldest = $frames[$count - self::TIMER_WINDOW_FRAMES];
		$tickDelta = $latest->clientTick - $oldest->clientTick;
		$timeDelta = $latest->receivedAt - $oldest->receivedAt;

		if($tickDelta <= 0 || $timeDelta < self::TIMER_MIN_SECONDS){
			return;
		}

		$clientTicksPerSecond = $tickDelta / $timeDelta;
		if($clientTicksPerSecond > self::TIMER_OBSERVE_TPS){
			$session->addViolation(
				"movement.timer_observation",
				0.15,
				AntiCheatSession::MODE_OBSERVE,
				"clientTPS=" . number_format($clientTicksPerSecond, 2) .
				" window=" . number_format($timeDelta, 2) . "s" .
				" ticks=" . $tickDelta
			);
		}
	}
}
