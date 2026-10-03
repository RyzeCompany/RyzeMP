<?php

declare(strict_types=1);

namespace pocketmine\anticheat;

use pocketmine\anticheat\impulse\ImpulseTracker;
use pocketmine\anticheat\timeline\CombatSample;
use pocketmine\anticheat\timeline\InputTimeline;
use pocketmine\anticheat\timeline\MovementSample;
use function array_shift;
use function count;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use function microtime;

final class AntiCheatSession{
	public const MODE_OBSERVE = "OBSERVE";
	public const MODE_BLOCK = "BLOCK";

	private \WeakReference $playerRef;
	/** @var array<string, float> */
	private array $lastAlertAt = [];
	private ActionBudget $miningBudget;
	private ActionBudget $combatBudget;
	private InputTimeline $inputTimeline;
	private ImpulseTracker $impulseTracker;
	private ?Vector3 $miningTarget = null;
	private int $airTicks = 0;

	/** @var list<MovementSample> */
	private array $movementSamples = [];

	/** @var list<CombatSample> */
	private array $combatSamples = [];

	/** @var array<string, float> */
	private array $violations = [];

	public function __construct(Player $player){
		$this->playerRef = \WeakReference::create($player);
		// A generous burst is deliberate. This layer protects the server from
		// impossible action spam without punishing normal high-latency clients.
		$this->miningBudget = new ActionBudget(40.0, 25.0);
		$this->combatBudget = new ActionBudget(40.0, 30.0);
		$this->inputTimeline = new InputTimeline();
		$this->impulseTracker = new ImpulseTracker();
	}

	public function getMiningBudget() : ActionBudget{
		return $this->miningBudget;
	}

	public function getCombatBudget() : ActionBudget{
		return $this->combatBudget;
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

	public function addViolation(string $check, float $weight = 1.0, string $mode = self::MODE_OBSERVE, string $details = "") : float{
		$total = $this->violations[$check] = ($this->violations[$check] ?? 0.0) + $weight;

		$now = microtime(true);
		$alertKey = $mode . ":" . $check;
		if(($this->lastAlertAt[$alertKey] ?? 0.0) + 1.0 <= $now){
			$this->lastAlertAt[$alertKey] = $now;
			$player = $this->playerRef->get();
			if($player instanceof Player && $player->isConnected()){
				AntiCheatManager::getInstance()->notifyViolation($player, $check, $weight, $total, $mode, $details);
			}
		}

		return $total;
	}

	public function getViolationLevel(string $check) : float{
		return $this->violations[$check] ?? 0.0;
	}

	public function getAirTicks() : int{
		return $this->airTicks;
	}

	public function addAirTicks(int $ticks) : int{
		return $this->airTicks += $ticks;
	}

	public function resetAirTicks() : void{
		$this->airTicks = 0;
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

	public function recordCombatSample(
		int $protocolId,
		int $targetRuntimeId,
		?int $clientTick,
		float $receivedAt,
		float $reach,
		float $directionDot,
		?int $ping
	) : void{
		$this->combatSamples[] = new CombatSample(
			$protocolId,
			$targetRuntimeId,
			$clientTick,
			$receivedAt,
			$reach,
			$directionDot,
			$ping
		);
		if(count($this->combatSamples) > 80){
			array_shift($this->combatSamples);
		}
	}

	/**
	 * @return list<CombatSample>
	 */
	public function getCombatSamples() : array{
		return $this->combatSamples;
	}

}
