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

namespace pocketmine\entity\ai;

use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\Server;
use pocketmine\YmlServerProperties;
use function max;

/**
 * Server-wide limits on mob AI work per tick (pocketmine.yml "mobs" section).
 *
 * Mobs over the AI update budget keep following their current path and look target, but don't evaluate new goals
 * until a later tick. Path searches over their budget are retried by the navigator on a later tick.
 *
 * Both budgets serve the requests that waited longest first (see {@link FairTickBudget}): callers pass the tick their
 * request was first refused on, so that in crowds (raids, farms, 100+ mobs) the mobs late in the world's update order
 * get their turn instead of being refused on every tick.
 */
final class AiBudget{
	public const DEFAULT_MAX_AI_UPDATES_PER_TICK = 200;
	public const DEFAULT_MAX_PATH_SEARCHES_PER_TICK = 10;

	private static bool $loaded = false;
	private static bool $enabled = true;
	private static int $maxAiUpdates = self::DEFAULT_MAX_AI_UPDATES_PER_TICK;
	private static int $maxPathSearches = self::DEFAULT_MAX_PATH_SEARCHES_PER_TICK;
	private static int $maxPathNodes = 400;
	private static bool $requireNearbyPlayer = true;

	private static ?FairTickBudget $aiUpdates = null;
	private static ?FairTickBudget $pathSearches = null;

	private function __construct(){
		//NOOP
	}

	public static function load(Server $server) : void{
		if(self::$loaded){
			return;
		}
		self::$loaded = true;
		$cfg = $server->getConfigGroup();
		self::$enabled = $cfg->getPropertyBool(YmlServerProperties::MOBS_ENABLED, true);
		self::$maxAiUpdates = max(1, $cfg->getPropertyInt(YmlServerProperties::MOBS_MAX_AI_UPDATES_PER_TICK, self::DEFAULT_MAX_AI_UPDATES_PER_TICK));
		self::$maxPathSearches = max(1, $cfg->getPropertyInt(YmlServerProperties::MOBS_MAX_PATH_SEARCHES_PER_TICK, self::DEFAULT_MAX_PATH_SEARCHES_PER_TICK));
		self::$maxPathNodes = max(16, $cfg->getPropertyInt(YmlServerProperties::MOBS_MAX_PATH_NODES, 400));
		Sensing::setIgnorePluginNpcs($cfg->getPropertyBool(YmlServerProperties::MOBS_IGNORE_PLUGIN_NPCS, true));
		self::resetBudgets();
	}

	/**
	 * Overrides the configuration (tests, plugins).
	 */
	public static function configure(bool $enabled, int $maxAiUpdates, int $maxPathSearches, int $maxPathNodes = 400, bool $requireNearbyPlayer = true) : void{
		self::$loaded = true;
		self::$enabled = $enabled;
		self::$maxAiUpdates = max(1, $maxAiUpdates);
		self::$maxPathSearches = max(1, $maxPathSearches);
		self::$maxPathNodes = max(16, $maxPathNodes);
		self::$requireNearbyPlayer = $requireNearbyPlayer;
		self::resetBudgets();
	}

	private static function resetBudgets() : void{
		(self::$aiUpdates ??= new FairTickBudget(self::$maxAiUpdates))->reset(self::$maxAiUpdates);
		(self::$pathSearches ??= new FairTickBudget(self::$maxPathSearches))->reset(self::$maxPathSearches);
	}

	/**
	 * Whether mobs only run their AI with a player nearby (always true on a server; tests turn it off).
	 */
	public static function requiresNearbyPlayer() : bool{
		return self::$requireNearbyPlayer;
	}

	/**
	 * Whether mob AI runs at all (mobs.enabled).
	 */
	public static function isEnabled() : bool{
		return self::$enabled;
	}

	public static function getMaxPathNodes() : int{
		return self::$maxPathNodes;
	}

	/**
	 * Takes one full AI update (goal evaluation) from this tick's budget. Returns false if it's exhausted, or reserved
	 * for mobs that have been waiting longer.
	 *
	 * @param int $waitingSince tick on which this mob's update was first refused, -1 if it wasn't: a refused mob asks
	 *                          again on the next ticks with it, and the mobs waiting longest are served first
	 */
	public static function tryAiUpdate(int $currentTick, int $waitingSince = -1) : bool{
		return (self::$aiUpdates ??= new FairTickBudget(self::$maxAiUpdates))->tryTake($currentTick, $waitingSince);
	}

	/**
	 * Takes one path search from this tick's budget. Returns false if it's exhausted, or reserved for searches that have
	 * been waiting longer.
	 *
	 * @param int $waitingSince tick on which this search was first refused, -1 if it wasn't (see
	 *                          {@link AiBudget::tryAiUpdate()})
	 */
	public static function tryPathSearch(int $currentTick, int $waitingSince = -1) : bool{
		return (self::$pathSearches ??= new FairTickBudget(self::$maxPathSearches))->tryTake($currentTick, $waitingSince);
	}
}
