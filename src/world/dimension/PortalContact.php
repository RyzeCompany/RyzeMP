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
 * @internal
 * Portal state of one entity, see PortalTravel.
 */
final class PortalContact{
	/** Last server tick the entity was inside a portal. */
	public int $lastTick = -1000;
	/** Consecutive ticks spent inside a portal. */
	public int $ticksInside = 0;
	/** Set after travelling: the entity must leave the portal before it works again. */
	public bool $needsExit = false;
	/** Waiting for the destination terrain to generate. */
	public bool $travelling = false;
	public int $cooldownUntil = 0;
	/**
	 * Whether the cooldown starts again while the entity stays in a portal (the portal cooldown of non-player entities,
	 * vanilla Entity.setAsInsidePortal()); retries after a failed trip don't.
	 */
	public bool $renewCooldown = false;
	/** The player is watching the End credits: it leaves the End once the client closes them. */
	public bool $watchingCredits = false;
}
