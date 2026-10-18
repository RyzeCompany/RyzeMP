<?php

declare(strict_types=1);

namespace pocketmine\command;

use CortexPE\Commando\args\IntegerArgument;
use CortexPE\Commando\args\TextArgument;
use PHPUnit\Framework\TestCase;
use pocketmine\command\args\NativeArgument;
use pocketmine\command\defaults\NativeCommand;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\Server;

final class NativeServerCommandsTest extends TestCase{
	private CommandSender $sender;
	private SimpleCommandMap $map;

	protected function setUp() : void{
		foreach((new \ReflectionClass(DefaultPermissionNames::class))->getConstants() as $name){
			if(PermissionManager::getInstance()->getPermission($name) === null){PermissionManager::getInstance()->addPermission(new Permission($name));}
		}
		$server = $this->createMock(Server::class);
		$server->method("getOnlinePlayers")->willReturn([]);
		$this->sender = $this->createMock(CommandSender::class);
		$this->sender->method("hasPermission")->willReturn(true);
		$this->sender->method("getServer")->willReturn($server);
		$this->map = new SimpleCommandMap($server);
	}

	public function testEveryBuiltInUsesTheStructuredApiAndPublishesSignatures() : void{
		$roots = [];
		foreach($this->map->getCommands() as $label => $command){
			if($label !== "pocketmine:" . $command->getName()){continue;}
			self::assertInstanceOf(NativeCommand::class, $command, $label);
			self::assertNotEmpty($command->getSignatures(), $label);
			// World suggestions need a loaded world manager; this command is covered by the real PHAR smoke.
			if($command->getName() !== "gamerule"){
				self::assertNotEmpty(CommandMetadata::forProtocol($command, $this->sender, 589), $label);
				self::assertNotEmpty(CommandMetadata::forProtocol($command, $this->sender, PHP_INT_MAX), $label);
			}
			$roots[] = $command->getName();
		}
		self::assertCount(42, $roots);
		foreach(["?" => "help", "teleport" => "tp", "w" => "tell", "msg" => "tell", "gr" => "gamerule", "pl" => "plugins", "unban" => "pardon", "suicide" => "kill", "ver" => "version"] as $alias => $name){
			self::assertSame($this->map->getCommand($name), $this->map->getCommand($alias));
		}
	}

	public function testExistingSyntaxVariantsRemainParseable() : void{
		$cases = [
			"ban" => [["Space Name", "a", "reason"]], "ban-ip" => [["127.0.0.1"]], "banlist" => [[], ["ips"]],
			"clear" => [[], ["@s", "stone", "-1"]], "defaultgamemode" => [["survival"]], "deop" => [["Name"]], "difficulty" => [["normal"]],
			"dumpmemory" => [[], ["/tmp/dump"]], "effect" => [["@s", "clear"], ["@s", "speed", "infinite", "1", "true"]],
			"enchant" => [["@s", "sharpness"], ["@s", "sharpness", "5"]], "gamerule" => [[], ["showcoordinates", "true", "world"]],
			"gamemode" => [["1"], ["creative", "Space Name"]], "gc" => [[]], "give" => [["@s", "stone"], ["Name", "stone", "32", '{display:{Name:"Hello World"}}']],
			"help" => [[], ["give"], ["2"]], "kick" => [["Name", "multi", "word", "reason"]], "kill" => [[], ["@s"]], "list" => [[]],
			"me" => [["hello", "world"]], "op" => [["Name"]], "pardon" => [["Name"]], "pardon-ip" => [["127.0.0.1"]],
			"particle" => [["flame", "~", "~1", "~-2", "0.1", "0", "0.2", "3"]], "plugins" => [[]], "save-all" => [[]], "save-off" => [[]], "save-on" => [[]],
			"say" => [["Hello", "world"]], "seed" => [[]], "setworldspawn" => [[], ["~", "64", "~-2"]], "spawnpoint" => [[], ["Name"], ["Name", "~", "70", "~"]],
			"status" => [[]], "stop" => [[]], "tp" => [["Target"], ["Name", "Target"], ["~", "64", "~"], ["~", "64", "~", "90", "0"], ["Name", "~", "64", "~"], ["Name", "~", "64", "~", "90", "0"]],
			"tell" => [["Space Name", "Hello", "world"]], "time" => [["query"], ["start"], ["stop"], ["set", "noon"], ["set", "1000"], ["add", "20"]],
			"timings" => [["on"], ["report"]], "title" => [["@s", "clear"], ["@s", "title", "Hello", "world"], ["@s", "times", "10", "40", "10"]],
			"transferserver" => [["example.org"], ["example.org", "19133"]], "version" => [[], ["Plugin", "Name"]],
			"whitelist" => [["list"], ["on"], ["add", "Name"], ["remove", "Name"]], "xp" => [["10"], ["-5L", "@s"]]
		];
		self::assertCount(42, $cases);
		foreach($cases as $name => $variants){
			foreach($variants as $args){
				$matches = array_filter($this->map->getCommand($name)->getSignatures(), fn(CommandSignature $signature) : bool => $signature->parseArguments($args, $this->sender)["errors"] === []);
				self::assertNotEmpty($matches, $name . " " . implode(" ", $args));
			}
		}
	}

	public function testInvalidTypesCountsAndIncompleteBranchesNeverReachHandlers() : void{
		foreach(["give" => ["@s", "stone", "NaN"], "tp" => ["Name", "~", "bad", "~"], "title" => ["@s", "times", "10", "20"], "time" => ["set"], "whitelist" => ["add"], "enchant" => ["@s", "sharpness", "1e9999"]] as $name => $args){
			try{$this->map->getCommand($name)->execute($this->sender, $name, $args);self::fail("Accepted invalid " . $name);}
			catch(InvalidCommandSyntaxException){self::assertTrue(true);}
		}
	}

	public function testPermissionSpecificBranchesAreHidden() : void{
		$sender = $this->createMock(CommandSender::class);
		$sender->method("hasPermission")->willReturnCallback(static fn(string $name) : bool => in_array($name, [DefaultPermissionNames::COMMAND_TIME_QUERY, DefaultPermissionNames::COMMAND_WHITELIST_LIST], true));
		foreach(["time" => "query", "whitelist" => "list"] as $name => $action){
			$overloads = $this->map->getCommand($name)->getNetworkOverloads($sender);
			self::assertCount(1, $overloads);
			self::assertSame([$action], $overloads[0]->getParameters()[0]->enum->getValues());
		}
	}

	public function testSharedParserPreservesOriginalTokensForTheHandler() : void{
		$command = new class("give-test") extends OverloadedCommand{
			public array $received = [];
			protected function executeParsed(CommandSender $sender, string $commandLabel, array $args){$this->received = $args;return true;}
		};
		$command->setPermission(DefaultPermissionNames::COMMAND_GIVE_SELF);
		$command->registerOverload([new NativeArgument("player"), new IntegerArgument("count"), new TextArgument("nbt")]);
		$args = ["Space Name", "0010", '{Name:"Hello World"}'];
		self::assertTrue($command->execute($this->sender, "give-test", $args));
		self::assertSame($args, $command->received);
	}

	public function testEnchantPublishesRegisteredVanillaNamesForOldAndCurrentProtocols() : void{
		$command = $this->map->getCommand("enchant");
		foreach([589, PHP_INT_MAX] as $protocol){
			$parameters = CommandMetadata::forProtocol($command, $this->sender, $protocol)[0]->getParameters();
			self::assertSame("enchantment", $parameters[1]->paramName);
			self::assertInstanceOf(CommandSoftEnum::class, $parameters[1]->enum);
			self::assertSame("native:enchantment", $parameters[1]->enum->getName());
			self::assertContains("sharpness", $parameters[1]->enum->getValues());
			self::assertContains("unbreaking", $parameters[1]->enum->getValues());
			self::assertSame(array_map("strval", StringToEnchantmentParser::getInstance()->getKnownAliases()), $parameters[1]->enum->getValues());
		}
		// Suggestions do not replace the handler's parser, normalization or not-found message.
		foreach(["minecraft:sharpness", "SHARPNESS", "unregistered_enchantment"] as $name){
			self::assertSame([], $command->getSignatures()[0]->parseArguments(["@s", $name, "1"], $this->sender)["errors"]);
		}
	}

	public function testEnchantSuggestionsIncludePluginAliasesRegisteredAfterCommandConstruction() : void{
		$original = StringToEnchantmentParser::getInstance();
		$parser = clone $original;
		StringToEnchantmentParser::setInstance($parser);
		try{
			$command = $this->map->getCommand("enchant");
			$before = CommandMetadata::forProtocol($command, $this->sender, 589)[0]->getParameters()[1]->enum;
			self::assertNotContains("native_api_test_custom", $before->getValues());
			$parser->register("Native API Test Custom", static fn() => VanillaEnchantments::UNBREAKING());
			$parser->registerAlias("native_api_test_custom", "native_api_test_alias");
			$parser->register("12345", static fn() => VanillaEnchantments::UNBREAKING());
			foreach([589, PHP_INT_MAX] as $protocol){
				$enum = CommandMetadata::forProtocol($command, $this->sender, $protocol)[0]->getParameters()[1]->enum;
				self::assertInstanceOf(CommandSoftEnum::class, $enum);
				self::assertContains("sharpness", $enum->getValues());
				self::assertContains("native_api_test_custom", $enum->getValues());
				self::assertContains("native_api_test_alias", $enum->getValues());
				self::assertContains("12345", $enum->getValues());
			}
			self::assertNotContains("native_api_test_custom", $before->getValues(), "Existing metadata must remain unchanged");
		}finally{
			StringToEnchantmentParser::setInstance($original);
		}
	}
}
