<?php

declare(strict_types=1);

namespace pocketmine\anticheat;

use pocketmine\anticheat\impulse\ImpulseTracker;
use pocketmine\anticheat\timeline\InputTimeline;
use pocketmine\anticheat\timeline\MovementSample;
use function array_shift;
use function count;
use pocketmine\math\Vector3;

final class AntiCheatSession{
	private ActionBudget $miningBudget;
	private InputTimeline $inputTimeline;
	private ImpulseTracker $impulseTracker;
	private ?Vector3 $miningTarget = null;

	/** @var list<MovementSample> */
	private array $movementSamples = [];

	/** @var array<string, float> */
	private array $violations = [];

	public function __construct(){
		// A generous burst is deliberate. This layer protects the server from
		// impossible action spam without punishing normal high-latency clients.
		$this->miningBudget = new ActionBudget(40.0, 25.0);
		$this->inputTimeline = new InputTimeline();
		$this->impulseTracker = new ImpulseTracker();
	}

	public function getMiningBudget() : ActionBudget{
		return $this->miningBudget;
	}

	public function getInputTimeline() : InputTimeline{
		return $this->inputTimeline;
	}

	public function getImpulseTracker() : ImpulseTracker{
		return $this->impulseTracker;
	}

	public function getMiningTarget() : ?Vector3{
		return $this->miningTarget;
	}

	public function setMiningTarget(?Vector3 $target) : void{
		$this->miningTarget = $target;
	}

	public function addViolation(string $check, float $weight = 1.0) : float{
		return $this->violations[$check] = ($this->violations[$check] ?? 0.0) + $weight;
	}

	public function getViolationLevel(string $check) : float{
		return $this->violations[$check] ?? 0.0;
	}
	public function recordMovementSample(
		int $protocolId,
		int $tickDelta,
		float $timeDelta,
		float $horizontalPerTick,
		float $verticalPerTick,
		float $distancePerTick,
		?int $ping,
		bool $sprinting,
		bool $flying,
		bool $swimming,
		bool $gliding
	) : void{
		$this->movementSamples[] = new MovementSample(
			$protocolId,
			$tickDelta,
			$timeDelta,
			$horizontalPerTick,
			$verticalPerTick,
			$distancePerTick,
			$ping,
			$sprinting,
			$flying,
			$swimming,
			$gliding
		);
		if(count($this->movementSamples) > 120){
			array_shift($this->movementSamples);
		}
	}

	/**
	 * @return list<MovementSample>
	 */
	public function getMovementSamples() : array{
		return $this->movementSamples;
	}

}
