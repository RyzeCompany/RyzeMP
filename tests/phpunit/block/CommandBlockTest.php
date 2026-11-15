<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\tile\CommandBlock as TileCommandBlock;
use pocketmine\block\tile\CommandBlockSender;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\CommandBlockType;
use pocketmine\command\CommandSender;
use pocketmine\command\selector\SelectorContext;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\handler\CommandBlockRequests;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_keys;
use function count;
use function sort;
use function str_starts_with;

/**
 * Impulse, repeating and chain command blocks (vanilla audit B0-10).
 */
final class CommandBlockTest extends RedstoneTestCase{
	private const Y = 5;

	/**
	 * Commands run, in order.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $ran = [];

	protected function setUp() : void{
		parent::setUp();
		if(PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR) === null){
			DefaultPermissions::registerCorePermissions();
		}
		$this->floor(-4, -4, 8, 4);
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		//commands starting with "fail" fail, the others succeed
		$server->method("dispatchCommand")->willReturnCallback(function(CommandSender $sender, string $command) : bool{
			self::assertInstanceOf(CommandBlockSender::class, $sender);
			$this->ran[] = $command;
			return !str_starts_with($command, "fail");
		});
	}

	private function placeCommandBlock(int $x, CommandBlock $block, string $command, int $facing = Facing::EAST, bool $conditional = false) : TileCommandBlock{
		$this->set($x, self::Y, 0, $block->setFacing($facing)->setConditional($conditional));
		$tile = $this->world->getTileAt($x, self::Y, 0);
		self::assertInstanceOf(TileCommandBlock::class, $tile);
		$tile->setCommand($command);
		return $tile;
	}

	private function block(int $x) : CommandBlock{
		$block = $this->get($x, self::Y, 0);
		self::assertInstanceOf(CommandBlock::class, $block);
		return $block;
	}

	public function testNewBlockDefaultsMatchBds() : void{
		$impulse = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "");
		$chain = $this->placeCommandBlock(1, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "");
		$repeating = $this->placeCommandBlock(2, VanillaBlocks::REPEATING_COMMAND_BLOCK(), "");
		self::assertFalse($impulse->isAuto());
		self::assertFalse($impulse->isExecuteOnFirstTick());
		self::assertTrue($chain->isAuto());
		self::assertFalse($chain->isExecuteOnFirstTick());
		self::assertFalse($repeating->isAuto());
		self::assertTrue($repeating->isExecuteOnFirstTick());
		self::assertTrue($impulse->isTrackingOutput());
	}

	public function testNbtRoundTripUsesBedrockFields() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say hi");
		$tile->setCustomName("Bob");
		$tile->setTickDelay(7);
		$tile->setAuto(true);
		$nbt = $tile->saveNBT();
		$keys = array_keys($nbt->getValue());
		sort($keys);
		self::assertSame([
			"Command", "CustomName", "ExecuteOnFirstTick", "LPCommandMode", "LPCondionalMode", "LPRedstoneMode", "LastExecution",
			"LastOutput", "LastOutputParams", "PMMPDataVersion", "SuccessCount", "TickDelay", "TrackOutput", "Version", "auto", "conditionMet",
			"id", "powered", "x", "y", "z"
		], $keys);
		self::assertSame("CommandBlock", $nbt->getString("id"));
		self::assertSame(TileCommandBlock::VERSION, $nbt->getInt("Version"));

		$copy = new TileCommandBlock($this->world, new Vector3(3, self::Y, 0));
		$copy->readSaveData($nbt);
		self::assertSame("say hi", $copy->getCommand());
		self::assertSame("Bob", $copy->getCustomName());
		self::assertSame(7, $copy->getTickDelay());
		self::assertTrue($copy->isAuto());
	}

	public function testImpulseRunsOncePerRisingEdge() : void{
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "/say hi");
		$this->runTicks(3);
		self::assertSame([], $this->ran);

		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(5);
		self::assertSame(["say hi"], $this->ran, "runs once when powered");
		$this->runTicks(20);
		self::assertSame(["say hi"], $this->ran, "staying powered doesn't run it again");

		$this->set(0, self::Y, 1, VanillaBlocks::AIR());
		$this->runTicks(3);
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		self::assertSame(["say hi", "say hi"], $this->ran, "a new rising edge runs it again");
	}

	public function testImpulseWaitsForTickDelay() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say hi");
		$tile->setTickDelay(10);
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(8);
		self::assertSame([], $this->ran);
		$this->runTicks(4);
		self::assertSame(["say hi"], $this->ran);
	}

	public function testRepeatingRunsEveryTickDelay() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::REPEATING_COMMAND_BLOCK(), "say tick");
		$tile->setTickDelay(5);
		$tile->setAuto(true);
		$this->block(0)->activate($tile);
		$this->runTicks(21);
		//first run on the first tick (ExecuteOnFirstTick), then every 5 ticks: ticks 1, 6, 11, 16, 21
		self::assertCount(5, $this->ran);

		$tile->setAuto(false);
		$this->runTicks(20);
		self::assertCount(5, $this->ran, "stops when no longer active");
	}

	public function testRepeatingNeedsPowerUnlessAlwaysActive() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::REPEATING_COMMAND_BLOCK(), "say tick");
		$this->runTicks(10);
		self::assertSame([], $this->ran);
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(10);
		self::assertGreaterThanOrEqual(8, count($this->ran));
		$this->set(0, self::Y, 1, VanillaBlocks::AIR());
		$this->runTicks(2);
		$count = count($this->ran);
		$this->runTicks(10);
		self::assertCount($count, $this->ran);
		self::assertFalse($tile->isPowered());
	}

	public function testRepeatingKeepsOneLoopWhenPowerIsToggled() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::REPEATING_COMMAND_BLOCK(), "say tick");
		$tile->setTickDelay(20);
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(5);
		//off and on again before the pending run, several times
		for($i = 0; $i < 3; ++$i){
			$this->set(0, self::Y, 1, VanillaBlocks::AIR());
			$this->runTicks(2);
			$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
			$this->runTicks(1);
		}
		$this->ran = [];
		//the last rising edge ran it on its first tick; then every 20 ticks: 20, 40, 60, 80, 100
		$this->runTicks(100);
		self::assertCount(5, $this->ran);
	}

	public function testRepeatingKeepsOneLoopWhenAlwaysActiveIsSetAgain() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::REPEATING_COMMAND_BLOCK(), "say tick");
		$tile->setTickDelay(10);
		$tile->setAuto(true);
		$block = $this->block(0);
		$block->activate($tile);
		$this->runTicks(3);
		$block->activate($tile);
		$this->runTicks(3);
		$block->activate($tile);
		$this->ran = [];
		$this->runTicks(50);
		//every 10 ticks, from the last activation's first tick: 1, 11, 21, 31, 41
		self::assertCount(5, $this->ran);
	}

	public function testChainRunsAfterTheBlockPointingIntoIt() : void{
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "first");
		$this->placeCommandBlock(1, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "second");
		$this->placeCommandBlock(2, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "third");
		//the chain turns south here, so the next block east isn't part of it
		$this->placeCommandBlock(3, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "fourth", Facing::SOUTH);
		$this->placeCommandBlock(4, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "fifth");
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		self::assertSame(["first", "second", "third", "fourth"], $this->ran);
	}

	public function testConditionalChainChecksTheBlockBehind() : void{
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "fail first");
		$second = $this->placeCommandBlock(1, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "second", Facing::EAST, true);
		$this->placeCommandBlock(2, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "third");
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		//the conditional block is skipped, the chain goes on
		self::assertSame(["fail first", "third"], $this->ran);
		self::assertFalse($second->isConditionMet());
		self::assertSame(0, $second->getSuccessCount());

		$this->ran = [];
		$first = $this->world->getTileAt(0, self::Y, 0);
		self::assertInstanceOf(TileCommandBlock::class, $first);
		$first->setCommand("first");
		$this->set(0, self::Y, 1, VanillaBlocks::AIR());
		$this->runTicks(2);
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		self::assertSame(["first", "second", "third"], $this->ran);
		self::assertTrue($second->isConditionMet());
	}

	public function testChainNeedsPowerWhenNotAlwaysActive() : void{
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "first");
		$chain = $this->placeCommandBlock(1, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "second");
		$chain->setAuto(false);
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		self::assertSame(["first"], $this->ran);
	}

	public function testChainLengthIsCappedByGameRule() : void{
		$this->world->setGameRule(GameRules::MAX_COMMAND_CHAIN_LENGTH, 2);
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "c0");
		for($x = 1; $x <= 4; ++$x){
			$this->placeCommandBlock($x, VanillaBlocks::CHAIN_COMMAND_BLOCK(), "c$x");
		}
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		self::assertSame(["c0", "c1", "c2"], $this->ran);
	}

	public function testLoopingChainStopsAtTheCap() : void{
		$this->world->setGameRule(GameRules::MAX_COMMAND_CHAIN_LENGTH, 10);
		//the impulse block feeds a loop of 4 chain blocks that point into each other
		$this->placeCommandBlock(-1, VanillaBlocks::COMMAND_BLOCK(), "start");
		foreach([[0, 0, Facing::SOUTH], [0, 1, Facing::EAST], [1, 1, Facing::NORTH], [1, 0, Facing::WEST]] as [$x, $z, $facing]){
			$this->set($x, self::Y, $z, VanillaBlocks::CHAIN_COMMAND_BLOCK()->setFacing($facing));
			$tile = $this->world->getTileAt($x, self::Y, $z);
			self::assertInstanceOf(TileCommandBlock::class, $tile);
			$tile->setCommand("loop");
		}
		$this->set(-1, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		//"start" then 10 chain runs
		self::assertSame("start", $this->ran[0]);
		self::assertCount(11, $this->ran);
	}

	public function testDisabledByGameRule() : void{
		$this->world->setGameRule(GameRules::COMMAND_BLOCKS_ENABLED, false);
		$tile = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say hi");
		$this->set(0, self::Y, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(3);
		self::assertSame([], $this->ran);
		self::assertSame(0, $tile->getSuccessCount());
	}

	public function testOutputIsTracked() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say hi");
		$tile->getCommandSender()->sendMessage("Hello");
		self::assertSame("Hello", $tile->getLastOutput());
		self::assertSame(CommandBlockSender::DEFAULT_NAME, $tile->getCommandSender()->getName());
		$tile->setTrackOutput(false);
		$tile->getCommandSender()->sendMessage("Again");
		self::assertSame("", $tile->getLastOutput());
	}

	public function testSelectorsStartFromTheBlock() : void{
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		$server->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$server->method("getOnlinePlayers")->willReturn([]);
		$tile = $this->placeCommandBlock(2, VanillaBlocks::COMMAND_BLOCK(), "say hi");
		$context = SelectorContext::fromSender($tile->getCommandSender());
		self::assertNull($context->getExecutor());
		self::assertSame($this->world, $context->getWorld());
		self::assertTrue($context->getOrigin()->equals(new Vector3(2.5, self::Y + 0.5, 0.5)));
	}

	public function testRelativeCoordinatesStartFromTheBlock() : void{
		$tile = $this->placeCommandBlock(2, VanillaBlocks::COMMAND_BLOCK(), "tp @p ~ ~10 ~");
		$origin = CommandCoordinates::senderOrigin($tile->getCommandSender());
		self::assertNotNull($origin);
		self::assertSame($this->world, $origin->getWorld());
		$pos = CommandCoordinates::parsePosition($origin, "~", "~10", "~");
		self::assertTrue($pos->equals(new Vector3(2.5, self::Y + 10.5, 0.5)));
	}

	public function testAdminCommandsAreNotAllowed() : void{
		$sender = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "")->getCommandSender();
		self::assertTrue($sender->hasPermission(DefaultPermissionNames::COMMAND_TELEPORT_OTHER));
		self::assertTrue($sender->hasPermission(DefaultPermissionNames::COMMAND_GIVE_OTHER));
		self::assertFalse($sender->hasPermission(DefaultPermissionNames::COMMAND_OP_GIVE));
		self::assertFalse($sender->hasPermission(DefaultPermissionNames::COMMAND_STOP));
		self::assertFalse($sender->hasPermission(DefaultPermissionNames::COMMAND_BAN_PLAYER));
		self::assertFalse($sender->hasPermission(DefaultPermissionNames::COMMAND_WHITELIST_ADD));
	}

	public function testBreakingNeedsOperatorInCreative() : void{
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say hi");
		$item = VanillaBlocks::AIR()->asItem();
		self::assertFalse($this->world->useBreakOn(new Vector3(0, self::Y, 0), $item, $this->player(false, true)));
		self::assertInstanceOf(CommandBlock::class, $this->get(0, self::Y, 0));
		self::assertTrue($this->world->useBreakOn(new Vector3(0, self::Y, 0), $item, $this->player(true, true)));
		self::assertNotInstanceOf(CommandBlock::class, $this->get(0, self::Y, 0));
	}

	public function testNeverDrops() : void{
		self::assertSame([], VanillaBlocks::COMMAND_BLOCK()->getDropsForCompatibleTool(VanillaBlocks::AIR()->asItem()));
		self::assertFalse(VanillaBlocks::COMMAND_BLOCK()->getBreakInfo()->isBreakable());
	}

	/**
	 * @phpstan-return Player&MockObject
	 */
	private function player(bool $op, bool $creative) : Player{
		$player = $this->createMock(Player::class);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getLocation")->willReturn(new Location(0, self::Y + 1, 0, $this->world, 0, 0));
		$player->method("isCreative")->willReturn($creative);
		$player->method("hasPermission")->willReturnCallback(fn($perm) => $op && $perm === DefaultPermissions::ROOT_OPERATOR);
		return $player;
	}

	private function updatePacket(int $mode, string $command, bool $redstoneMode = true, bool $conditional = false) : CommandBlockUpdatePacket{
		$packet = new CommandBlockUpdatePacket();
		$packet->isBlock = true;
		$packet->blockPosition = new BlockPosition(0, self::Y, 0);
		$packet->commandBlockMode = $mode;
		$packet->isRedstoneMode = $redstoneMode;
		$packet->isConditional = $conditional;
		$packet->command = $command;
		$packet->lastOutput = "";
		$packet->name = "";
		$packet->filteredName = "";
		$packet->shouldTrackOutput = true;
		$packet->tickDelay = 0;
		$packet->executeOnFirstTick = false;
		return $packet;
	}

	public function testOnlyOperatorsInCreativeCanEdit() : void{
		$tile = $this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say old");
		$session = $this->createMock(NetworkSession::class);
		$session->method("getTypeConverter")->willReturn(\pocketmine\network\mcpe\convert\TypeConverter::getInstance());

		self::assertFalse(CommandBlockRequests::handleBlockUpdate($session, $this->player(false, true), $this->updatePacket(0, "op")));
		self::assertFalse(CommandBlockRequests::handleBlockUpdate($session, $this->player(true, false), $this->updatePacket(0, "op")));
		self::assertSame("say old", $tile->getCommand());

		self::assertTrue(CommandBlockRequests::handleBlockUpdate($session, $this->player(true, true), $this->updatePacket(CommandBlockType::REPEATING->value, "say new", true, true)));
		self::assertSame("say new", $tile->getCommand());
		$block = $this->block(0);
		self::assertSame(CommandBlockType::REPEATING, $block->getCommandBlockType());
		self::assertTrue($block->isConditional());
		self::assertSame(Facing::EAST, $block->getFacing());
		self::assertSame($tile, $this->world->getTileAt(0, self::Y, 0), "changing the mode keeps the block entity");
	}

	public function testTurningAlwaysActiveOnRunsAnImpulseBlock() : void{
		$this->placeCommandBlock(0, VanillaBlocks::COMMAND_BLOCK(), "say old");
		$session = $this->createMock(NetworkSession::class);
		self::assertTrue(CommandBlockRequests::handleBlockUpdate($session, $this->player(true, true), $this->updatePacket(0, "say hi", false)));
		$this->runTicks(3);
		self::assertSame(["say hi"], $this->ran);
		$this->runTicks(10);
		self::assertSame(["say hi"], $this->ran);
	}

	public function testPlacementNeedsOperatorInCreative() : void{
		$this->set(0, self::Y, 0, VanillaBlocks::AIR());
		$item = VanillaBlocks::COMMAND_BLOCK()->asItem();
		$survivalOp = $this->player(true, false);
		self::assertFalse($this->world->useItemOn(new Vector3(0, self::Y - 1, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5), $survivalOp));
		self::assertNotInstanceOf(CommandBlock::class, $this->get(0, self::Y, 0));
	}

	public function testTileFactoryKnowsTheSaveId() : void{
		$nbt = CompoundTag::create()
			->setString("id", "CommandBlock")
			->setInt("x", 6)->setInt("y", self::Y)->setInt("z", 0)
			->setString("Command", "say loaded")
			->setByte("auto", 1);
		$tile = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileCommandBlock::class, $tile);
		self::assertSame("say loaded", $tile->getCommand());
		self::assertTrue($tile->isAuto());
	}
}
