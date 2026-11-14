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

use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionColor;
use pocketmine\network\mcpe\protocol\types\camera\CameraFadeInstructionTime;
use pocketmine\network\mcpe\protocol\types\camera\CameraPreset;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEase;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEaseType;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionRotation;
use pocketmine\player\Player;

/**
 * @internal
 * Camera moves of the "cinematic" transfer style: the camera flies up from the player into the sky on the server the
 * player leaves, and comes down from the sky onto the player on the server it arrives at (like GTA V's character
 * switch). Uses the "minecraft:free" camera preset.
 *
 * At the top of the climb the screen goes black for about a second, just while the old server's terrain is replaced;
 * the camera comes down onto the player as soon as the new server's terrain is there.
 */
final class ProxyCamera{
	/** Height of the camera above the player at the top of the move. */
	public const HEIGHT = 40.0;
	/** Duration of each move, in seconds. */
	public const DURATION = 2.0;
	/** Duration of each move, in server ticks. The camera is released one tick after the move ends. */
	public const DURATION_TICKS = 41;
	/** Ticks after the start of the climb at which the screen starts fading to black. */
	public const FADE_OUT_TICK = 35;
	/** Ticks after the start of the climb at which the player leaves (the screen is black by then). */
	public const LEAVE_TICK = 41;

	/** Fade to black at the top of the climb (seconds): black when the player leaves, at LEAVE_TICK. */
	private const FADE_IN = 0.3;
	/** How long the screen stays black at most while the server changes (seconds); the new server ends it sooner. */
	private const FADE_HOLD = 1.0;
	/** Fade back from black (seconds). */
	private const REVEAL = 0.3;
	/** On arrival, black while the client draws the new terrain, then the reveal (seconds). */
	private const ARRIVAL_HOLD = 0.25;
	private const ARRIVAL_REVEAL = 0.35;

	private const FREE_PRESET = 0;

	private function __construct(){
		//NOOP
	}

	private static function sendPresets(NetworkSession $session) : void{
		$session->sendDataPacket(CameraPresetsPacket::create([
			new CameraPreset("minecraft:free", "", null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null),
		]));
	}

	private static function set(NetworkSession $session, Vector3 $position, ?Vector3 $facing, ?CameraSetInstructionRotation $rotation, ?float $ease) : void{
		$session->sendDataPacket(CameraInstructionPacket::create(
			new CameraSetInstruction(
				preset: self::FREE_PRESET,
				ease: $ease !== null ? new CameraSetInstructionEase(CameraSetInstructionEaseType::IN_OUT_CUBIC, $ease) : null,
				cameraPosition: $position,
				rotation: $rotation,
				facingPosition: $facing,
				viewOffset: null,
				entityOffset: null,
				default: null,
				ignoreStartingValuesComponent: false
			),
			null, null, null, null, null, null, null, null
		));
	}

	private static function fade(NetworkSession $session, float $in, float $stay, float $out) : void{
		$session->sendDataPacket(CameraInstructionPacket::create(
			null,
			null,
			new CameraFadeInstruction(new CameraFadeInstructionTime($in, $stay, $out), new CameraFadeInstructionColor(0.0, 0.0, 0.0)),
			null, null, null, null, null, null
		));
	}

	/**
	 * Above the player, looking down at it. A tiny horizontal offset avoids looking exactly straight down.
	 */
	private static function skyPosition(Player $player) : Vector3{
		return $player->getEyePos()->add(0.01, self::HEIGHT, 0.01);
	}

	private static function rotation(Location $location) : CameraSetInstructionRotation{
		return new CameraSetInstructionRotation($location->getPitch(), $location->getYaw());
	}

	/**
	 * Leaving: the camera flies up from the player into the sky.
	 */
	public static function flyUp(NetworkSession $session, Player $player) : void{
		self::sendPresets($session);
		$eye = $player->getEyePos();
		self::set($session, $eye, null, self::rotation($player->getLocation()), null);
		self::set($session, self::skyPosition($player), $eye, null, self::DURATION);
	}

	/**
	 * Near the top of the climb: the screen goes black, and stays black while the player changes server.
	 */
	public static function fadeOut(NetworkSession $session) : void{
		self::fade($session, self::FADE_IN, self::FADE_HOLD, self::REVEAL);
	}

	/**
	 * Arriving (once the terrain is loaded): the camera appears above the player and comes down onto it. The screen
	 * stays black a moment longer, so that the old terrain is never seen while the client draws the new one.
	 */
	public static function flyDown(NetworkSession $session, Player $player) : void{
		self::sendPresets($session);
		self::fade($session, 0.0, self::ARRIVAL_HOLD, self::ARRIVAL_REVEAL);
		self::set($session, self::skyPosition($player), $player->getEyePos(), null, null);
		self::set($session, $player->getEyePos(), null, self::rotation($player->getLocation()), self::DURATION);
	}

	/**
	 * Back to the normal first-person camera.
	 *
	 * No teleport to line the head up with the camera: a MovePlayer teleport makes the server wait for the client to
	 * confirm the position, and a client still in the free camera may not, which left players unable to move.
	 */
	public static function release(NetworkSession $session) : void{
		$session->sendDataPacket(CameraInstructionPacket::create(null, true, null, null, null, null, null, null, null));
	}
}
