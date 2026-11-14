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

namespace pocketmine\entity\npc;

/**
 * What a villager does at a time of day (the component groups switched by the villager_v2 "minecraft:scheduler").
 */
enum VillagerActivity : string{
	/** Work at the job site (farmers also harvest and replant crops). */
	case WORK = "work";
	/** Meet the other villagers at the bell and gossip. */
	case GATHER = "gather";
	/** Stroll around. */
	case WANDER = "wander";
	/** Go back to the claimed bed. */
	case HOME = "home";
	/** Sleep in the claimed bed. */
	case SLEEP = "sleep";
	/** Babies run around and chase each other. */
	case PLAY = "play";
}
