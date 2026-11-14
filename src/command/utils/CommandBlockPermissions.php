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

namespace pocketmine\command\utils;

use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissibleBase;

/**
 * Permissions of command blocks and command block minecarts. Like Bedrock, they run at the "game directors" level:
 * every operator command except the server administration ones (operators, bans, kicks, allowlist, stopping or
 * saving the server, transfers and diagnostics), since anyone can trigger them with a button or a pressure plate.
 *
 * @internal
 */
final class CommandBlockPermissions{

	/** Admin-level permissions command blocks don't have. */
	public const DENIED = [
		DefaultPermissionNames::COMMAND_OP_GIVE,
		DefaultPermissionNames::COMMAND_OP_TAKE,
		DefaultPermissionNames::COMMAND_BAN_PLAYER,
		DefaultPermissionNames::COMMAND_BAN_IP,
		DefaultPermissionNames::COMMAND_BAN_LIST,
		DefaultPermissionNames::COMMAND_UNBAN_PLAYER,
		DefaultPermissionNames::COMMAND_UNBAN_IP,
		DefaultPermissionNames::COMMAND_KICK,
		DefaultPermissionNames::COMMAND_STOP,
		DefaultPermissionNames::COMMAND_SAVE_DISABLE,
		DefaultPermissionNames::COMMAND_SAVE_ENABLE,
		DefaultPermissionNames::COMMAND_SAVE_PERFORM,
		DefaultPermissionNames::COMMAND_TRANSFERSERVER,
		DefaultPermissionNames::COMMAND_WHITELIST_ADD,
		DefaultPermissionNames::COMMAND_WHITELIST_REMOVE,
		DefaultPermissionNames::COMMAND_WHITELIST_ENABLE,
		DefaultPermissionNames::COMMAND_WHITELIST_DISABLE,
		DefaultPermissionNames::COMMAND_WHITELIST_LIST,
		DefaultPermissionNames::COMMAND_WHITELIST_RELOAD,
		DefaultPermissionNames::COMMAND_TIMINGS,
		DefaultPermissionNames::COMMAND_DUMPMEMORY,
		DefaultPermissionNames::COMMAND_GC,
	];

	private function __construct(){
		//NOOP
	}

	public static function create() : PermissibleBase{
		$permissions = [DefaultPermissions::ROOT_OPERATOR => true];
		//later root permissions override the children granted by the operator one
		foreach(self::DENIED as $name){
			$permissions[$name] = false;
		}
		return new PermissibleBase($permissions);
	}
}
