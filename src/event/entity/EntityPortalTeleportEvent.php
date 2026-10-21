<?php

declare(strict_types=1);

namespace pocketmine\event\entity;

use pocketmine\entity\Entity;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\utils\Utils;
use pocketmine\world\dimension\portal\PortalType;
use pocketmine\world\Position;

/** Fired before a portal teleport. A changed destination is populated and safety-checked again. */
final class EntityPortalTeleportEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(Entity $entity, private PortalType $portalType, private Position $to, private ?float $yaw = null, private ?float $pitch = null){
		$this->entity = $entity;
		$this->to = clone $to;
	}

	public function getPortalType() : PortalType{ return $this->portalType; }
	public function getTo() : Position{ return clone $this->to; }
	public function getYaw() : ?float{ return $this->yaw; }
	public function getPitch() : ?float{ return $this->pitch; }
	public function setTo(Position $to, ?float $yaw = null, ?float $pitch = null) : void{
		Utils::checkVector3NotInfOrNaN($to);
		if(($yaw !== null && !is_finite($yaw)) || ($pitch !== null && (!is_finite($pitch) || $pitch < -90 || $pitch > 90))){
			throw new \InvalidArgumentException("Portal rotation must be finite and pitch between -90 and 90");
		}
		$this->to = clone $to;
		$this->yaw = $yaw;
		$this->pitch = $pitch;
	}
}
