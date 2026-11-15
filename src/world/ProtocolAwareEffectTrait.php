<?php

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\network\mcpe\convert\TypeConverter;

trait ProtocolAwareEffectTrait{
	private ?TypeConverter $effectTypeConverter = null;

	public function setTypeConverter(TypeConverter $converter) : void{
		$this->effectTypeConverter = $converter;
	}

	protected function getTypeConverter() : TypeConverter{
		return $this->effectTypeConverter ?? TypeConverter::getInstance();
	}
}
