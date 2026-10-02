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

use pocketmine\entity\Entity;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\player\Player;

/**
 * A non-damaging fishing interaction. Cancelling prevents the hook or the pull.
 *
 * Inherits the projectile damage event so existing combat protection listeners
 * can reject it. It is dispatched directly, never passed to Entity::attack():
 * damage modifiers do not remove health, trigger hurt immunity or add knockback.
 * Plugins which ignore zero-damage events should handle this event explicitly.
 */
class FishingHookEntityEvent extends EntityDamageByChildEntityEvent{
	public const ACTION_HOOK = 0;
	public const ACTION_PULL = 1;

	public function __construct(
		Player $owner,
		FishingHook $hook,
		Player $target,
		private int $action
	){
		parent::__construct($owner, $hook, $target, self::CAUSE_PROJECTILE, 0);
		$this->setKnockBack(0);
	}

	public function getAction() : int{
		return $this->action;
	}

	protected function addAttackerModifiers(Entity $damager) : void{
		//This event authorizes movement, so Strength and Weakness do not apply.
	}
}
