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

namespace pocketmine\plugin\faulty;

use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\plugin\ResourceProvider;
use pocketmine\Server;
use function sys_get_temp_dir;

/**
 * Dummy plugin used by the plugin fault isolation tests. Its "plugin file" is this directory, so every class and
 * closure declared in this directory is considered to be plugin code.
 */
final class FaultyTestPlugin extends PluginBase{
	public bool $failOnEnable = false;
	public bool $failOnDisable = false;
	public int $disableCalls = 0;

	public static function create(Server $server, PluginLoader $loader, ResourceProvider $resourceProvider, string $name = "FaultyTest", string $file = __DIR__) : self{
		return new self(
			$loader,
			$server,
			new PluginDescription([
				"name" => $name,
				"version" => "1.2.3",
				"main" => "faultytest\Main", //only a name: plugins may not use the pocketmine namespace
				"api" => "5.0.0"
			]),
			sys_get_temp_dir(),
			$file,
			$resourceProvider
		);
	}

	protected function onEnable() : void{
		if($this->failOnEnable){
			throw new \RuntimeException("onEnable failure");
		}
	}

	protected function onDisable() : void{
		$this->disableCalls++;
		if($this->failOnDisable){
			throw new \RuntimeException("onDisable failure");
		}
	}
}
