<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use PHPUnit\Framework\TestCase;
use pocketmine\anticheat\timeline\InputFrame;
use pocketmine\anticheat\validation\MovementValidator;

final class MovementTelemetryWindowTest extends TestCase{
	/** @return list<InputFrame> */
	private function frames(float $secondsPerTick, int $count) : array{
		$frames = [];
		for($i = 0; $i < $count; ++$i){
			$frames[] = new InputFrame($i, 2193, 0, 0, 0, $i * $secondsPerTick, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
		}
		return $frames;
	}

	public function testDoesNotScoreShortBurst() : void{
		self::assertNull(MovementValidator::calculateClientTicksPerSecond($this->frames(0.03, 20)));
	}

	public function testNormalTickRateIsNotSuspicious() : void{
		self::assertEqualsWithDelta(20.0, MovementValidator::calculateClientTicksPerSecond($this->frames(0.05, 40)), 0.01);
	}

	public function testSustainedFastClockIsStillObservable() : void{
		self::assertGreaterThan(28.0, MovementValidator::calculateClientTicksPerSecond($this->frames(0.032, 40)));
	}

	public function testTooShortTimeWindowIsIgnored() : void{
		self::assertNull(MovementValidator::calculateClientTicksPerSecond($this->frames(0.02, 40)));
	}
}
