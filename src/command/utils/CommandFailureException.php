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

use pocketmine\lang\Translatable;

/**
 * A command failure with a message for the sender (usually a vanilla commands.* translation), e.g. an unknown block
 * name or an invalid block state. The command map shows the message to the sender in red, so commands may let it
 * propagate.
 */
class CommandFailureException extends CommandException{

	public function __construct(
		private Translatable $translatable,
		?\Throwable $previous = null
	){
		parent::__construct($translatable->getText(), 0, $previous);
	}

	/**
	 * Message for the command sender.
	 */
	public function getTranslatable() : Translatable{
		return $this->translatable;
	}
}
