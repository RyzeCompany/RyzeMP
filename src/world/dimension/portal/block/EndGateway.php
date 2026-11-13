<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal\block;

use pocketmine\entity\Entity;
use pocketmine\Server;

final class EndGateway extends EndPortal{
	public function onEntityInside(Entity $entity) : bool{
		Server::getInstance()->getDimensionManager()->touchEndGateway($entity, $this->position);
		return true;
	}
}
