<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

/** The portal block type activating a link, independent of the world's dimension. */
enum PortalType{
	case NETHER;
	case END;
}
