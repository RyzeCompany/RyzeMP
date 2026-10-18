<?php

declare(strict_types=1);

namespace CortexPE\Commando;

use CortexPE\Commando\traits\IArgumentable;
use pocketmine\command\CommandSender;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;

/** Native RyzeMP integration, derived from Commando 3.3.0 PacketHooker. */
final class NetworkCommandBuilder{
	/** @return list<CommandOverload> */
	public static function build(BaseCommand $command, CommandSender $sender) : array{
		if(!$command->getOwningPlugin()->isEnabled() || !$command->testPermissionSilent($sender)){return [];}
		foreach($command->getConstraints() as $constraint){if(!$constraint->isVisibleTo($sender)){return [];}}
		$overloads = [];
		foreach($command->getSubCommands() as $label => $sub){
			if($label !== $sub->getName() || !$sub->testPermissionSilent($sender)){continue;}
			foreach($sub->getConstraints() as $constraint){if(!$constraint->isVisibleTo($sender)){continue 2;}}
			// Include registered aliases in the accepted enum as well as the primary name.
			$names = array_values(array_unique([$sub->getName(), ...$sub->getAliases()]));
			$prefix = CommandParameter::enum($label, new CommandHardEnum($command->getName() . ":" . $label, $names), 0);
			foreach(self::arguments($sub, $sender) as $overload){
				$overloads[] = new CommandOverload(false, [$prefix, ...$overload->getParameters()]);
			}
		}
		return [...$overloads, ...self::arguments($command, $sender)];
	}

	/** @return list<CommandOverload> */
	private static function arguments(IArgumentable $command, CommandSender $sender) : array{
		$sets = [[]];
		foreach($command->getArgumentList() as $alternatives){
			$next = [];
			foreach($sets as $set){foreach($alternatives as $argument){
				$parameter = clone $argument->getNetworkParameterDataFor($sender);
				if($parameter->enum instanceof CommandHardEnum && $parameter->enum->getName() === ""){
					$parameter->enum = new CommandHardEnum("enum#" . spl_object_id($parameter->enum), $parameter->enum->getValues());
				}
				$next[] = [...$set, $parameter];
			}}
			$sets = $next;
		}
		return array_map(static fn(array $parameters) : CommandOverload => new CommandOverload(false, $parameters), $sets);
	}
}
