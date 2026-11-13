<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\world\generator\InvalidGeneratorOptionsException;

/** Population cannot continue safely with the pinned cache currently on disk. */
final class InvalidVanillaDataException extends InvalidGeneratorOptionsException{
}
