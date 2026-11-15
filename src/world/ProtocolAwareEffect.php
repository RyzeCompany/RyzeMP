<?php

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\network\mcpe\convert\TypeConverter;

/** A sound or particle whose packet contains version-specific runtime IDs. */
interface ProtocolAwareEffect{
	public function setTypeConverter(TypeConverter $converter) : void;
}
