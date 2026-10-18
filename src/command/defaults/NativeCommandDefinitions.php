<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\args\NativeArgument as A;
use pocketmine\permission\DefaultPermissionNames as P;

/** Explicit signatures for every command registered by SimpleCommandMap. */
final class NativeCommandDefinitions{
	public static function register(NativeCommand $command) : void{
		$s = static fn(string $name, bool $optional = false) => new A($name, "string", $optional);
		$p = static fn(string $name = "player", bool $optional = false) => new A($name, "player", $optional);
		$i = static fn(string $name, bool $optional = false) => new A($name, "integer", $optional);
		$n = static fn(string $name) => new A($name, "number");
		$t = static fn(string $name, bool $optional = false) => new A($name, "text", $optional);
		$e = static fn(string $name, array $values, bool $optional = false) => new A($name, "enum", $optional, $values);
		$v = static fn() => new A("position", "position");
		// Permissions on self/other targets remain enforced by fetchPermittedPlayerTarget.
		$signatures = match($command->getName()){
			"ban", "ban-ip", "kick" => [[$s("target"), $t("reason", true)]],
			"banlist" => [[$e("type", ["players", "ips"], true)]],
			"clear" => [[$p(optional: true), $s("item", true), $i("maxCount", true)]],
			"gamemode" => [[$s("mode"), $p(optional: true)]],
			"defaultgamemode", "difficulty" => [[$s("value")]],
			"deop", "op", "pardon", "pardon-ip" => [[$s("target")]],
			"dumpmemory" => [[$s("path", true)]],
			"effect" => [[$p(), $s("effect"), new A("seconds", "duration", true), $i("amplifier", true), $e("hideParticles", ["true", "false", "on", "off", "t", "f", "1", "0"], true)]],
			"enchant" => [[$p(), $s("enchantment"), $i("level", true)]],
			"gamerule" => [[$s("rule", true), $e("value", ["true", "false"], true), new A("world", "world", true)]],
			"give" => [[$p(), $s("item"), $i("amount", true), $t("nbt", true)]],
			"help" => [[$t("commandOrPage", true)]],
			"kill" => [[$p(optional: true)]],
			"me", "say" => [[$t("message")]],
			"particle" => [[$s("particle"), $v(), $n("dx"), $n("dy"), $n("dz"), $i("count", true), $s("data", true)]],
			"setworldspawn" => [[], [$v()]],
			"spawnpoint" => [[$p(optional: true)], [$p(), $v()]],
			"tp" => [[$p("destination")], [$p(), $p("destination")], [$v()], [$v(), $n("yaw"), $n("pitch")], [$p(), $v()], [$p(), $v(), $n("yaw"), $n("pitch")]],
			"tell" => [[$p(), $t("message")]],
			"timings" => [[$e("action", ["on", "off", "reset", "merged", "report", "paste"])]],
			"title" => [[$p(), $e("action", ["clear", "reset"])], [$p(), $e("action", ["title", "subtitle", "actionbar"]), $t("text")], [$p(), $e("action", ["times"]), $i("fadeIn"), $i("stay"), $i("fadeOut")]],
			"transferserver" => [[$s("address"), $i("port", true)]],
			"version" => [[$t("plugin", true)]],
			"xp" => [[new A("amount", "xp"), $p(optional: true)]],
			"gc", "list", "plugins", "save-all", "save-off", "save-on", "seed", "status", "stop" => [[]],
			"time", "whitelist" => [], // Each branch has its own permission below.
			default => throw new \LogicException("Missing native command signature: " . $command->getName())
		};
		foreach($signatures as $arguments){$command->registerOverload($arguments);}
		if($command->getName() === "time"){
			foreach(["start" => P::COMMAND_TIME_START, "stop" => P::COMMAND_TIME_STOP, "query" => P::COMMAND_TIME_QUERY] as $action => $permission){
				$command->registerOverload([$e("action", [$action])], $permission);
			}
			$command->registerOverload([$e("action", ["set"]), new A("time", "time")], P::COMMAND_TIME_SET);
			$command->registerOverload([$e("action", ["add"]), $i("ticks")], P::COMMAND_TIME_ADD);
		}elseif($command->getName() === "whitelist"){
			foreach(["reload" => P::COMMAND_WHITELIST_RELOAD, "on" => P::COMMAND_WHITELIST_ENABLE, "off" => P::COMMAND_WHITELIST_DISABLE, "list" => P::COMMAND_WHITELIST_LIST, "add" => P::COMMAND_WHITELIST_ADD, "remove" => P::COMMAND_WHITELIST_REMOVE] as $action => $permission){
				$arguments = [$e("action", [$action])];
				if($action === "add" || $action === "remove"){$arguments[] = $s("player");}
				$command->registerOverload($arguments, $permission);
			}
		}
	}
}
