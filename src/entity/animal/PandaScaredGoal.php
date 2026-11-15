<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\goal\Goal;

/**
 * Bedrock behavior.scared of worried pandas: during a thunderstorm the panda stops, sits down and covers its face
 * (SCARED flag, shaking in the client's sitting animation), unless it is in water (Java Panda.tick()).
 *
 * @internal
 */
final class PandaScaredGoal extends Goal{

	public function __construct(private Panda $panda){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP;
	}

	private function shouldBeScared() : bool{
		$panda = $this->panda;
		return $panda->getPersonality() === Panda::GENE_WORRIED && !$panda->isInWater() && $panda->getWorld()->getWeather()->isThundering();
	}

	public function canStart() : bool{
		return $this->shouldBeScared();
	}

	public function canContinue() : bool{
		return $this->shouldBeScared();
	}

	public function start() : void{
		$this->panda->getNavigation()->stop();
		$this->panda->setScared(true);
	}

	public function stop() : void{
		$this->panda->setScared(false);
	}

	public function tick() : void{
		//sits again when something made it stand up (Java Panda.tick() sits a worried panda every tick of a storm)
		if(!$this->panda->isSitting()){
			$this->panda->sitDown();
		}
	}
}
