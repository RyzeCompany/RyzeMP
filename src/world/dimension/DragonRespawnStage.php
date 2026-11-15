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

namespace pocketmine\world\dimension;

/**
 * Stages of the ender dragon respawn sequence (vanilla DragonRespawnStage), started by placing four End crystals on
 * the exit portal after the dragon was killed. The values are the names vanilla saves.
 *
 * - START: the crystals aim their beams above the exit portal
 * - PREPARING_TO_SUMMON_PILLARS: 5 seconds with dragon growls
 * - SUMMONING_PILLARS: every 2 seconds the beams aim at the next obsidian pillar, which is rebuilt with an explosion
 *   (with its crystal, and its iron bar cage for the caged ones)
 * - SUMMONING_DRAGON: the beams aim above the portal again; after 5 seconds the four crystals explode and the new
 *   dragon appears (END)
 */
enum DragonRespawnStage : string{
	case START = "start";
	case PREPARING_TO_SUMMON_PILLARS = "preparing_to_summon_pillars";
	case SUMMONING_PILLARS = "summoning_pillars";
	case SUMMONING_DRAGON = "summoning_dragon";
	case END = "end";
}
