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

namespace pocketmine\entity\vehicle;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use function count;

/**
 * The rideable minecart (vanilla minecraft:minecart): one seat; players get in by interacting with it, mobs when they
 * bump into it. A powered activator rail ejects the rider.
 */
class Minecart extends AbstractMinecart{

	public static function getNetworkTypeId() : string{ return EntityIds::MINECART; }

	public function getName() : string{
		return "Minecart";
	}

	public function getMinecartType() : MinecartType{
		return MinecartType::NORMAL;
	}

	public function isRideable() : bool{
		return true;
	}

	public function getRideableSeats() : array{
		//vanilla minecart.json minecraft:rideable
		return [RideableSeat::at(0, -0.2, 0)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Living;
	}

	public function getInteractButtonText(Player $player) : string{
		return count($this->passengers) === 0 && !$player->isSneaking() ? "action.interact.ride.minecart" : "";
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($player->isSneaking() || count($this->passengers) > 0 || $player->isSpectator()){
			return false;
		}
		return $player->mount($this, true);
	}

	protected function onActivatorRail(bool $powered) : void{
		if(!$powered){
			return;
		}
		if(count($this->passengers) > 0){
			foreach($this->passengers as $passenger){
				$this->setBoardingCooldown($passenger);
			}
			$this->ejectPassengers();
		}
		if($this->hurtTime === 0){
			//vanilla shakes the cart (damage 50 doesn't break it: only hits do)
			$this->animateHurt();
			$this->setDamage(50);
		}
	}
}
