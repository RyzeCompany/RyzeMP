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

namespace pocketmine\event\entity;

use pocketmine\entity\Living;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Called when the world spawns a mob by itself (natural spawning around players, structure spawns, wandering traders,
 * village cats), after the mob was created and before it is shown to players. The mob can still be changed (equipment,
 * name...). Cancelling it closes the mob.
 *
 * @phpstan-extends EntityEvent<Living>
 */
class EntityNaturalSpawnEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	/** Bedrock cluster spawning with the spawn rules (including herd members and phantoms). */
	public const CAUSE_NATURAL = 0;
	/** Structure spawns (e.g. witch huts, nether fortresses) registered by a structure spawn provider. */
	public const CAUSE_STRUCTURE = 1;
	/** A wandering trader and its trader llamas. */
	public const CAUSE_WANDERING_TRADER = 2;
	/** A stray cat of a village. */
	public const CAUSE_VILLAGE_CAT = 3;

	public function __construct(
		Living $entity,
		private int $cause,
		private bool $surface
	){
		$this->entity = $entity;
	}

	/**
	 * One of the CAUSE_* constants.
	 */
	public function getCause() : int{
		return $this->cause;
	}

	/**
	 * Whether the mob spawned on the surface (Bedrock counts surface and underground mobs separately for the caps).
	 */
	public function isSurface() : bool{
		return $this->surface;
	}
}
