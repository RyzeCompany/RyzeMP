<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandOverloadProvider;
use pocketmine\command\OverloadedCommandTrait;
use pocketmine\lang\Translatable;

/** Common structured execution path for all built-in server commands. */
abstract class NativeCommand extends VanillaCommand implements CommandOverloadProvider{
	use OverloadedCommandTrait;

	public function __construct(string $name, Translatable|string $description = "", Translatable|string|null $usageMessage = null, array $aliases = []){
		parent::__construct($name, $description, $usageMessage, $aliases);
		NativeCommandDefinitions::register($this);
	}
}
