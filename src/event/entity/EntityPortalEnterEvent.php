<?php

declare(strict_types=1);

namespace pocketmine\event\entity;

use pocketmine\entity\Entity;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\world\dimension\portal\PortalType;
use pocketmine\world\Position;

/** Fired once per continuous portal contact, before preparing the destination. */
final class EntityPortalEnterEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(Entity $entity, private PortalType $portalType, private Position $portalPosition, private int $teleportDelay){
		$this->entity = $entity;
		$this->portalPosition = clone $portalPosition;
	}

	public function getPortalType() : PortalType{ return $this->portalType; }
	public function getPortalPosition() : Position{ return clone $this->portalPosition; }
	public function getTeleportDelay() : int{ return $this->teleportDelay; }
	public function setTeleportDelay(int $ticks) : void{
		if($ticks < 0 || $ticks > 12000){
			throw new \InvalidArgumentException("Portal delay must be between 0 and 12000 ticks");
		}
		$this->teleportDelay = $ticks;
	}
}
