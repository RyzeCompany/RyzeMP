<?php

declare(strict_types=1);

namespace CortexPE\Commando;

use pocketmine\plugin\Plugin;

/**
 * Compatibility entry point for plugins using Commando's traditional setup.
 * RyzeMP emits metadata natively; registering an outgoing packet interceptor
 * would convert argument IDs twice and bypass the per-session protocol boundary.
 */
class PacketHooker{
	public static function isRegistered() : bool{return true;}
	public static function register(Plugin $registrant) : void{}
}
