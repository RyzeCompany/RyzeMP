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

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;

/**
 * The spectator abilities layer, as BDS 1.26.52 sends it (UpdateAbilities and AddPlayer, layer listed before the base
 * layer) for a player in game type 6: no building, mining, doors, containers or attacking, invulnerable, flying, not
 * allowed to toggle flight, no instabuild, no clip. It sets no speeds; those come from the base layer.
 *
 * @internal
 */
final class SpectatorAbilities{

	private function __construct(){
		//NOOP
	}

	/**
	 * Flying and no-clip follow the server state (both true unless a plugin changed them), the rest is fixed.
	 */
	public static function layer(bool $flying = true, bool $noClip = true) : AbilitiesLayer{
		return new AbilitiesLayer(AbilitiesLayer::LAYER_SPECTATOR, [
			AbilitiesLayer::ABILITY_BUILD => false,
			AbilitiesLayer::ABILITY_MINE => false,
			AbilitiesLayer::ABILITY_DOORS_AND_SWITCHES => false,
			AbilitiesLayer::ABILITY_OPEN_CONTAINERS => false,
			AbilitiesLayer::ABILITY_ATTACK_PLAYERS => false,
			AbilitiesLayer::ABILITY_ATTACK_MOBS => false,
			AbilitiesLayer::ABILITY_INVULNERABLE => true,
			AbilitiesLayer::ABILITY_FLYING => $flying,
			AbilitiesLayer::ABILITY_ALLOW_FLIGHT => false,
			AbilitiesLayer::ABILITY_INFINITE_RESOURCES => false,
			AbilitiesLayer::ABILITY_NO_CLIP => $noClip,
		], null, null, null);
	}
}
