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

//Classes added by the chunkutils2 extension of PocketMine-MP Reborn in the network namespace (see chunkutils2-reborn.php).

/**
 * chunkutils2 >= 0.3.9: the outgoing packet batches of the sessions in C++, see NetworkSession::getNativeSendBuffers().
 * Ids are never reused by PocketMine; appends to unknown or closed ids are ignored.
 */
final class NativeSendBuffers{
	/**
	 * Opens a buffer under an id chosen by PHP (ids from 2^62 up are reserved for openNew()).
	 * @throws \InvalidArgumentException if the id is open already or reserved
	 */
	public function open(int $id) : void{}

	/**
	 * chunkutils2 >= 0.3.10: opens a buffer under an id chosen natively and returns it: a slot of a dense table plus a
	 * generation which changes whenever the slot is reused, so an id is never valid twice and lookups are an array index
	 * (cheaper than the ids of open()).
	 */
	public function openNew() : int{ return 0; }

	public function close(int $id) : void{}

	public function isOpen(int $id) : bool{ return false; }

	public function append(int $id, string $buffer) : void{}

	/**
	 * @param int[] $ids
	 * @phpstan-param array<int, int> $ids
	 * @return int how many open buffers got the data
	 */
	public function appendMany(array $ids, string $buffer) : int{ return 0; }

	/**
	 * chunkutils2 >= 0.3.10: the viewer loop of Player::broadcastMovement() / Mob::broadcastMovement():
	 * a viewer gets the movement when its squared distance ($l->x - $x) ** 2 + ($l->y - $y) ** 2 + ($l->z - $z) ** 2
	 * (computed with PHP's int|float arithmetic, ** 2 being the C library's pow()) is <= $fullRateDistanceSquared,
	 * otherwise when ($tick + $viewer->getId()) % ($distanceSquared <= $halfRateDistanceSquared ? 2 : 4) === 0;
	 * the others are skipped. Viewer positions and ids are read from the objects (Entity::$location, Entity::$id).
	 * $sendIds is set to what the PHP loop builds: $sendIds[$key] = $viewerSendIds[$key] ?? 0 for each recipient,
	 * keyed and ordered like $viewers ([] when there is none, and on -1). Nothing is kept in this object.
	 *
	 * @param object[] $viewers       Entity::$hasSpawned
	 * @param int[]    $viewerSendIds Entity::$viewerSendIds (same keys)
	 * @param mixed    $sendIds
	 * @phpstan-param array<int, object> $viewers
	 * @phpstan-param array<int, int>    $viewerSendIds
	 * @phpstan-param-out array<int, int> $sendIds
	 *
	 * @return int (number of recipients << 1) | (1 if a viewer was skipped), or -1 when the PHP loop must run instead
	 *             (nothing selected): a viewer class overriding getId() or not inheriting it from pocketmine\entity\Entity,
	 *             uninitialized/unset properties, property hooks, lazy objects, $fullRateDistanceSquared < 0,
	 *             |thresholds| >= 2^53, $tick + id overflowing
	 */
	public function selectMovement(array $viewers, array $viewerSendIds, int|float $x, int|float $y, int|float $z, int $tick, int $fullRateDistanceSquared, int $halfRateDistanceSquared, &$sendIds) : int{ return -1; }

	/**
	 * chunkutils2 >= 0.3.10: selectMovement() then appendMany($sendIds, $buffer) in one call (nothing is appended on -1).
	 *
	 * @param object[] $viewers
	 * @param int[]    $viewerSendIds
	 * @phpstan-param array<int, object> $viewers
	 * @phpstan-param array<int, int>    $viewerSendIds
	 *
	 * @return int same as selectMovement()
	 */
	public function appendMovement(array $viewers, array $viewerSendIds, string $buffer, int|float $x, int|float $y, int|float $z, int $tick, int $fullRateDistanceSquared, int $halfRateDistanceSquared) : int{ return -1; }

	public function length(int $id) : int{ return 0; }

	/**
	 * Returns the batch of the session and empties it ("" when nothing is queued or the id is not open).
	 */
	public function take(int $id) : string{ return ""; }

	public function count() : int{ return 0; }
}
