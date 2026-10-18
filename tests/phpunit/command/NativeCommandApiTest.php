<?php

declare(strict_types=1);

namespace pocketmine\command;

use Closure;
use CortexPE\Commando\BaseCommand;
use CortexPE\Commando\BaseSubCommand;
use CortexPE\Commando\NetworkCommandBuilder;
use CortexPE\Commando\PacketHooker;
use CortexPE\Commando\args\BooleanArgument;
use CortexPE\Commando\args\BlockPositionArgument;
use CortexPE\Commando\args\IntegerArgument;
use CortexPE\Commando\args\RawStringArgument;
use CortexPE\Commando\args\TextArgument;
use CortexPE\Commando\args\Vector3Argument;
use CortexPE\Commando\constraint\BaseConstraint;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\plugin\Plugin;

final class NativeCommandApiTest extends TestCase{
	private CommandSender $sender;
	private Plugin $plugin;
	private array $permissions;
	private array $messages = [];

	protected function setUp() : void{
		$this->permissions = ["tests.command.root" => true, "tests.command.admin" => false];
		foreach(array_keys($this->permissions) as $name){
			if(PermissionManager::getInstance()->getPermission($name) === null){PermissionManager::getInstance()->addPermission(new Permission($name));}
		}
		$this->plugin = $this->createMock(Plugin::class);
		$this->plugin->method("isEnabled")->willReturn(true);
		$this->sender = $this->createMock(CommandSender::class);
		$this->sender->method("hasPermission")->willReturnCallback(fn(string $name) : bool => $this->permissions[$name] ?? false);
		$this->sender->method("sendMessage")->willReturnCallback(function($text) : void{$this->messages[] = (string) $text;});
	}

	private function command(Closure $prepare, ?Plugin $plugin = null) : TestNativeCommand{
		return new TestNativeCommand($plugin ?? $this->plugin, $prepare);
	}

	public function testTraditionalCommandsRetainOptionalRawTextAndVersionConversion() : void{
		$command = $this->createMock(Command::class);
		$old = CommandMetadata::forProtocol($command, $this->sender, 589);
		$new = CommandMetadata::forProtocol($command, $this->sender, PHP_INT_MAX);
		self::assertTrue($old[0]->getParameters()[0]->isOptional);
		self::assertSame(58, $old[0]->getParameters()[0]->paramType);
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_RAWTEXT, $new[0]->getParameters()[0]->paramType);
	}

	public function testStructuredTypesAreConvertedWithoutMutatingSharedMetadata() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{
			$cmd->registerArgument(0, new RawStringArgument("player"));
			$cmd->registerArgument(1, new IntegerArgument("amount"));
		});
		$old = CommandMetadata::forProtocol($command, $this->sender, 589);
		$new = CommandMetadata::forProtocol($command, $this->sender, PHP_INT_MAX);
		self::assertSame(44, $old[0]->getParameters()[0]->paramType);
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_STRING, $new[0]->getParameters()[0]->paramType);
		self::assertSame(AvailableCommandsPacket::ARG_TYPE_STRING, $command->getArgumentList()[0][0]->getNetworkParameterData()->paramType);
		self::assertNotSame($old[0]->getParameters()[0], $new[0]->getParameters()[0]);
	}

	public function testSubcommandsRespectPermissionsAndAdvertiseAliases() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{
			$cmd->registerSubCommand(new TestNativeSubCommand("who", ["info", "i"], null));
			$cmd->registerSubCommand(new TestNativeSubCommand("admin", [], "tests.command.admin"));
		});
		$overloads = NetworkCommandBuilder::build($command, $this->sender);
		self::assertCount(2, $overloads); // who plus the root's no-argument help
		self::assertSame(["who", "info", "i"], $overloads[0]->getParameters()[0]->enum->getValues());
		$this->permissions["tests.command.admin"] = true;
		self::assertCount(3, NetworkCommandBuilder::build($command, $this->sender));
		$command->execute($this->sender, "sample", ["INFO"]);
		self::assertSame(1, $command->getSubCommands()["who"]->runs);
	}

	public function testTypedParsingAndErrorsDoNotRunInvalidCommands() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{
			$cmd->registerArgument(0, new IntegerArgument("amount"));
			$cmd->registerArgument(1, new BooleanArgument("enabled"));
		});
		$command->execute($this->sender, "sample", ["15", "true"]);
		self::assertSame(["amount" => 15, "enabled" => true], $command->calls[0]);
		self::assertSame(["15", "true"], $command->getRawArguments());
		$command->execute($this->sender, "sample", ["bad", "true"]);
		$command->execute($this->sender, "sample", ["15"]);
		$command->execute($this->sender, "sample", ["15", "true", "extra"]);
		self::assertCount(1, $command->calls);
		self::assertCount(3, $this->messages);
	}

	public function testRootConstraintsProtectSubcommandExecutionAndVisibility() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{
			$cmd->registerSubCommand(new TestNativeSubCommand("run", [], null));
			$cmd->addConstraint(new TestDeniedConstraint($cmd));
		});
		self::assertSame([], $command->getNetworkOverloads($this->sender));
		$command->execute($this->sender, "sample", ["run"]);
		self::assertSame(0, $command->getSubCommands()["run"]->runs);
		self::assertSame(["constraint denied"], $this->messages);
	}

	public function testDisabledPluginCannotExecuteOrAdvertiseCommands() : void{
		$plugin = $this->createMock(Plugin::class);$plugin->method("isEnabled")->willReturn(false);
		$command = $this->command(static function() : void{}, $plugin);
		$command->execute($this->sender, "sample", []);
		self::assertSame([], $command->calls);
		self::assertSame([], $command->getNetworkOverloads($this->sender));
	}

	public function testCustomPermissionErrorIsActuallySent() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{$cmd->registerSubCommand(new TestNativeSubCommand("admin", [], "tests.command.admin"));});
		$command->setPermissionMessage("custom denied");$command->execute($this->sender, "sample", ["admin"]);
		self::assertSame(["custom denied"], $this->messages);
	}

	public function testIntegerOverflowIsRejectedAndLeadingZeroesRemainValid() : void{
		$arg = new IntegerArgument("value");
		self::assertTrue($arg->canParse("0015", $this->sender));
		self::assertTrue($arg->canParse((string) PHP_INT_MIN, $this->sender));
		self::assertTrue($arg->canParse((string) PHP_INT_MAX, $this->sender));
		self::assertFalse($arg->canParse("9999999999999999999999999", $this->sender));
		self::assertFalse($arg->canParse("10\n", $this->sender));
	}

	public function testSenderAwareDynamicArgumentIsRebuiltForEveryMetadataRequest() : void{
		$values = ["Alpha"];
		$argument = new class("faction", false, function() use (&$values) : array{return $values;}) extends RawStringArgument{
			public function __construct(string $name, bool $optional, private Closure $values){parent::__construct($name, $optional);}
			public function getNetworkParameterDataFor(CommandSender $sender) : CommandParameter{
				return CommandParameter::softEnum($this->name, new \pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum("factions", ($this->values)()), 0, $this->optional);
			}
		};
		$command = $this->command(static function(BaseCommand $cmd) use ($argument) : void{$cmd->registerArgument(0, $argument);});
		$first = $command->getNetworkOverloads($this->sender);$values[] = "Beta";
		$second = $command->getNetworkOverloads($this->sender);
		self::assertSame(["Alpha"], $first[0]->getParameters()[0]->enum->getValues());
		self::assertSame(["Alpha", "Beta"], $second[0]->getParameters()[0]->enum->getValues());
	}

	public function testArgumentAlternativesKeepRegistrationOrderBeforeGreedyText() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{
			$cmd->registerArgument(0, new IntegerArgument("number"));$cmd->registerArgument(0, new TextArgument("text"));
		});
		$command->execute($this->sender, "sample", ["12"]);
		$command->execute($this->sender, "sample", ["multi", "word"]);
		self::assertSame([["number" => 12], ["text" => "multi word"]], $command->calls);
	}

	public function testSessionPublishesStructuredMetadataThroughNativePacketAssembly() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{$cmd->registerArgument(0, new RawStringArgument("player"));});
		$command->setAliases(["samplealias"]);
		$map = $this->createMock(SimpleCommandMap::class);$map->method("getCommands")->willReturn(["sample" => $command, "samplealias" => $command]);
		$server = $this->createMock(\pocketmine\Server::class);$server->method("getCommandMap")->willReturn($map);
		$player = $this->createMock(\pocketmine\player\Player::class);
		(new \ReflectionProperty(\pocketmine\entity\Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(\pocketmine\player\Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$player->method("hasPermission")->willReturn(true);
		$session = $this->createPartialMock(\pocketmine\network\mcpe\NetworkSession::class, ["sendDataPacket", "getProtocolId"]);
		(new \ReflectionProperty(\pocketmine\network\mcpe\NetworkSession::class, "server"))->setValue($session, $server);
		(new \ReflectionProperty(\pocketmine\network\mcpe\NetworkSession::class, "player"))->setValue($session, $player);
		$session->method("getProtocolId")->willReturn(589);$packets = [];
		$session->method("sendDataPacket")->willReturnCallback(function($packet) use (&$packets) : bool{$packets[] = $packet;return true;});
		$session->syncAvailableCommands();
		$data = \pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler::disassemble($packets[0]);
		self::assertCount(1, $data->commandData);
		self::assertSame(44, $data->commandData[0]->getOverloads()[0]->getParameters()[0]->paramType);
		self::assertContains("samplealias", $data->commandData[0]->aliases->getValues());
	}

	public function testNativeSetupNeedsNoOutgoingInterceptor() : void{
		self::assertSame(1, CommandMetadata::API_VERSION);self::assertTrue(PacketHooker::isRegistered());
		PacketHooker::register($this->plugin);PacketHooker::register($this->plugin);
		self::assertTrue(PacketHooker::isRegistered());
	}

	public function testRelativeCoordinatesWorkForPluginPositionArguments() : void{
		$player = $this->createMock(\pocketmine\\player\\Player::class);
		$vector = new Vector3Argument("position");
		$block = new BlockPositionArgument("block");
		self::assertTrue($vector->canParse("~ ~1 ~-2.5", $player));
		self::assertTrue($vector->canParse("~+.5 ~-0.25 ~+2", $player));
		self::assertTrue($block->canParse("~ ~-1 ~+2", $player));
		self::assertFalse($block->canParse("~ 1.5 ~", $player));
		self::assertFalse($vector->canParse("~ ~1 ~-2.5", $this->sender));
		self::assertTrue($vector->canParse("1 2.5 -3", $this->sender));
	}

	public function testMixedCaseSubcommandAliasesAreExecutableAndSuggested() : void{
		$command = $this->command(static function(BaseCommand $cmd) : void{
			$cmd->registerSubCommand(new TestNativeSubCommand("Who", ["INFO", "Alias"], null));
		});
		$command->execute($this->sender, "sample", ["info"]);
		$command->execute($this->sender, "sample", ["WHO"]);
		$command->execute($this->sender, "sample", ["aLiAs"]);
		self::assertSame(3, $command->getSubCommands()["who"]->runs);
		$overloads = $command->getNetworkOverloads($this->sender);
		self::assertSame(["Who", "INFO", "Alias"], $overloads[0]->getParameters()[0]->enum->getValues());
	}
}

final class TestNativeCommand extends BaseCommand{
	public array $calls = [];
	public function __construct(Plugin $plugin, private Closure $setup){parent::__construct($plugin, "sample");}
	protected function prepare() : void{$this->setPermission("tests.command.root");($this->setup)($this);}
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void{$this->calls[] = $args;}
}
final class TestNativeSubCommand extends BaseSubCommand{
	public int $runs = 0;
	public function __construct(string $name, array $aliases, private ?string $permission){parent::__construct($name, "", $aliases);}
	protected function prepare() : void{if($this->permission !== null){$this->setPermission($this->permission);}}
	public function onRun(CommandSender $sender, string $aliasUsed, array $args) : void{++$this->runs;}
}
final class TestDeniedConstraint extends BaseConstraint{
	public function test(CommandSender $sender, string $aliasUsed, array $args) : bool{return false;}
	public function isVisibleTo(CommandSender $sender) : bool{return false;}
	public function onFailure(CommandSender $sender, string $aliasUsed, array $args) : void{$sender->sendMessage("constraint denied");}
}
