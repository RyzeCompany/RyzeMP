<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

/** @internal Validates a shared pinned cache around one complete population task. */
interface ValidatedPopulationGenerator{
	public function beginPopulation() : void;

	public function validatePopulationResult() : void;

	public function endPopulation() : void;
}
