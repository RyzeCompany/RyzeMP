<?php

declare(strict_types=1);

namespace pocketmine\command;

use pocketmine\network\mcpe\protocol\types\command\CommandOverload;

/** Optional structured command API. Parameter types use current-protocol constants. */
interface CommandOverloadProvider{
	/** @return list<CommandOverload> Empty hides the command from this sender. */
	public function getNetworkOverloads(CommandSender $sender) : array;
}
