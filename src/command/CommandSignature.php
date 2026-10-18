<?php

declare(strict_types=1);

namespace pocketmine\command;

use CortexPE\Commando\args\BaseArgument;
use CortexPE\Commando\traits\ArgumentableTrait;
use CortexPE\Commando\traits\IArgumentable;

/** One complete syntax alternative, parsed by the same Commando parser as plugin commands. */
final class CommandSignature implements IArgumentable{
	use ArgumentableTrait;

	/** @param list<BaseArgument> $arguments */
	public function __construct(private string $name, array $arguments, private ?string $permission = null){
		foreach($arguments as $position => $argument){$this->registerArgument($position, $argument);}
	}

	protected function prepare() : void{}
	public function getName() : string{return $this->name;}
	public function getPermission() : ?string{return $this->permission;}
}
