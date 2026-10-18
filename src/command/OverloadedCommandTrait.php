<?php

declare(strict_types=1);

namespace pocketmine\command;

use CortexPE\Commando\args\BaseArgument;
use CortexPE\Commando\NetworkCommandBuilder;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;

/** Server-owned structured commands; plugins can use this or Commando BaseCommand. */
trait OverloadedCommandTrait{
	/** @var list<CommandSignature> */
	private array $signatures = [];

	/** @param list<BaseArgument> $arguments */
	final public function registerOverload(array $arguments, ?string $permission = null) : void{
		$this->signatures[] = new CommandSignature($this->getName(), $arguments, $permission);
	}

	/** @return list<CommandSignature> */
	final public function getSignatures() : array{return $this->signatures;}

	/** @return list<CommandOverload> */
	final public function getNetworkOverloads(CommandSender $sender) : array{
		if(!$this->testPermissionSilent($sender)){return [];}
		$result = [];
		foreach($this->signatures as $signature){
			$permission = $signature->getPermission();
			if($permission === null || $sender->hasPermission($permission)){
				array_push($result, ...NetworkCommandBuilder::buildArguments($signature, $sender));
			}
		}
		return $result;
	}

	final public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$this->testPermission($sender)){return false;}
		foreach($this->signatures as $signature){
			$parsed = $signature->parseArguments($args, $sender);
			if($parsed["errors"] !== []){continue;}
			$permission = $signature->getPermission();
			if($permission !== null && !$this->testPermission($sender, $permission)){return false;}
			// Keep quoted names, casing, relative coordinates and NBT tokens exactly as entered.
			return $this->executeParsed($sender, $commandLabel, $args);
		}
		throw new InvalidCommandSyntaxException();
	}

	/** @param list<string> $args */
	abstract protected function executeParsed(CommandSender $sender, string $commandLabel, array $args);
}
