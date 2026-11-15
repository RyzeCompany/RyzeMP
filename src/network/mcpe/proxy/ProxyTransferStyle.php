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

namespace pocketmine\network\mcpe\proxy;

/**
 * @internal
 * How a player changing server through pm-proxy sees the change (transfer-style in pm-proxy's config.yml, or the
 * optional second argument of /server).
 */
final class ProxyTransferStyle{
	/** A dimension change with its loading screen (with a detour when both worlds share a dimension). */
	public const DIMENSION = "dimension";
	/** No loading screen: the terrain is replaced and the player teleported (same dimension only). */
	public const SEAMLESS = "seamless";
	/** Like seamless, with the camera flying up into the sky and down onto the new place (GTA V style). */
	public const CINEMATIC = "cinematic";

	public const ALL = [self::DIMENSION, self::SEAMLESS, self::CINEMATIC];

	private function __construct(){
		//NOOP
	}
}
