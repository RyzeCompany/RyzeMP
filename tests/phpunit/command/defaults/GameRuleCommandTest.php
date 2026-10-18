<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use PHPUnit\Framework\TestCase;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use Symfony\Component\Filesystem\Filesystem;

final class GameRuleCommandTest extends TestCase{
	private string $directory;
	private GameRules $rules;
	private World $world;
	private Server $server;
	private array $messages = [];

	protected function setUp() : void{
		$this->directory = sys_get_temp_dir() . "/ryzemp-command-rules-" . bin2hex(random_bytes(12));
		mkdir($this->directory);
		$permissions = \pocketmine\permission\PermissionManager::getInstance();
		if($permissions->getPermission(\pocketmine\permission\DefaultPermissionNames::COMMAND_GAMERULE) === null){
			$permissions->addPermission(new \pocketmine\permission\Permission(\pocketmine\permission\DefaultPermissionNames::COMMAND_GAMERULE));
		}
		$this->rules = new GameRules($this->directory . "/gamerules.yml");
		$this->world = $this->createMock(World::class);
		$this->world->method("getGameRules")->willReturn($this->rules);
		$this->world->method("getFolderName")->willReturn("world_nether");
		$this->world->method("setGameRule")->willReturnCallback(fn($name, $value) => $this->rules->setBool($name, $value));
		$manager = $this->createMock(WorldManager::class);
		$manager->method("getDefaultWorld")->willReturn($this->world);
		$manager->method("getWorldByName")->willReturnCallback(fn($name) => $name === "world_nether" ? $this->world : null);
		$this->server = $this->createMock(Server::class);
		$this->server->method("getWorldManager")->willReturn($manager);
	}
	protected function tearDown() : void{ (new Filesystem())->remove($this->directory); }

	private function sender(bool $permitted = true) : CommandSender{
		$sender = $this->createMock(CommandSender::class);
		$sender->method("hasPermission")->willReturn($permitted);
		$sender->method("getServer")->willReturn($this->server);
		$sender->method("sendMessage")->willReturnCallback(function($message){ $this->messages[] = $message; });
		return $sender;
	}
	public function testConsoleSetExplicitWorldThenQuery() : void{
		$command = new GameRuleCommand();
		$sender = $this->sender();
		$command->execute($sender, "gamerule", ["showCoordinates", "true", "world_nether"]);
		self::assertTrue($this->rules->getBool("showcoordinates"));
		$command->execute($sender, "gamerule", ["showcoordinates"]);
		self::assertStringContainsString("showcoordinates = true (world_nether)", $this->messages[1]);
	}
	public function testPlayerUsesCurrentWorld() : void{
		$sender = $this->createMock(Player::class);
		(new \ReflectionProperty(\pocketmine\entity\Entity::class, "closed"))->setValue($sender, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($sender, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$sender->method("hasPermission")->willReturn(true);
		$sender->method("getWorld")->willReturn($this->world);
		$command = new GameRuleCommand();
		$command->execute($sender, "gr", ["showcoordinates", "true"]);
		self::assertTrue($this->rules->getBool("showcoordinates"));
	}
	public function testInvalidBooleanDoesNotEnableRule() : void{
		$this->expectException(InvalidCommandSyntaxException::class);
		(new GameRuleCommand())->execute($this->sender(), "gamerule", ["showcoordinates", "yes"]);
	}
	public function testMissingWorldAndUnknownRuleDoNotModifyAnything() : void{
		$command = new GameRuleCommand();
		$sender = $this->sender();
		$command->execute($sender, "gamerule", ["showcoordinates", "true", "missing"]);
		$command->execute($sender, "gamerule", ["unsupported", "true"]);
		self::assertFalse($this->rules->getBool("showcoordinates"));
		self::assertCount(2, $this->messages);
	}
	public function testPermissionDeniedDoesNotChangeRule() : void{
		(new GameRuleCommand())->execute($this->sender(false), "gamerule", ["showcoordinates", "true"]);
		self::assertFalse($this->rules->getBool("showcoordinates"));
	}
}
