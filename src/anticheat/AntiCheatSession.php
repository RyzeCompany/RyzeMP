<?php

declare(strict_types=1);

namespace pocketmine\anticheat;

use pocketmine\math\Vector3;

final class AntiCheatSession{
	private ActionBudget $miningBudget;
	private ?Vector3 $miningTarget = null;

	/** @var array<string, float> */
	private array $violations = [];

	public function __construct(){
		// A generous burst is deliberate. This layer protects the server from
		// impossible action spam without punishing normal high-latency clients.
		$this->miningBudget = new ActionBudget(40.0, 25.0);
	}

	public function getMiningBudget() : ActionBudget{
		return $this->miningBudget;
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
}
