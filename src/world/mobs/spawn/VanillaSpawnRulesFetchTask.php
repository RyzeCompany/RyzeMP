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

namespace pocketmine\world\mobs\spawn;

use pocketmine\scheduler\AsyncTask;
use function is_string;

/**
 * Downloads Mojang's spawn rules (see {@link VanillaSpawnRules::fetch()}) on an async worker: about 60 small files
 * (270 KB), a few seconds once per Bedrock version.
 */
final class VanillaSpawnRulesFetchTask extends AsyncTask{

	public function __construct(
		private string $directory
	){}

	public function onRun() : void{
		try{
			VanillaSpawnRules::fetch($this->directory);
			$this->setResult(null);
		}catch(\RuntimeException $e){
			$this->setResult($e->getMessage());
		}
	}

	public function onCompletion() : void{
		$result = $this->getResult();
		VanillaSpawnRules::onFetchCompleted($this->directory, is_string($result) ? $result : null);
	}
}
