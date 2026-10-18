<?php

declare(strict_types=1);

namespace pocketmine\command;

/** Structured commands without a plugin owner, using native Commando signatures. */
abstract class OverloadedCommand extends Command implements CommandOverloadProvider{
	use OverloadedCommandTrait;
}
