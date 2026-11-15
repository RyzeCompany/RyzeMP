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

namespace pocketmine\world\gamerule;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Crops;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\defaults\GameruleCommand;
use pocketmine\entity\ai\hostile\goal\EvokerWololoGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\ai\villager\HarvestCropsGoal;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Entity;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Evoker;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Ravager;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\object\ArmorStand;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\vehicle\Boat;
use pocketmine\entity\vehicle\Minecart;
use pocketmine\entity\vehicle\MinecartCommandBlock;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\item\VanillaItems;
use pocketmine\lang\Translatable;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\GameRules;
use function array_filter;
use function count;

/**
 * What the game rules added for vanilla parity change in the world: mobgriefing (sheep, villagers, snow golems,
 * evokers, ravagers, item pickup), doentitydrops, projectilescanbreakblocks, dotiledrops experience and the command
 * feedback rules.
 */
final class GameRuleEffectsTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * @phpstan-return list<ItemEntity>
	 */
	private function itemEntities() : array{
		$items = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				$items[] = $entity;
			}
		}
		return $items;
	}

	private function setPrivate(object $object, string $property, mixed $value) : void{
		(new \ReflectionProperty($object, $property))->setValue($object, $value);
	}

	/**
	 * @phpstan-return Player&MockObject
	 */
	private function mockPlayer() : Player{
		$player = $this->createMock(Player::class);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	public function testNewRulesHaveTheBedrockDefaults() : void{
		//BDS 1.26.52 /gamerule
		$expected = [
			GameRules::DO_ENTITY_DROPS => true,
			GameRules::PROJECTILES_CAN_BREAK_BLOCKS => true,
			GameRules::COMMAND_BLOCK_OUTPUT => true,
			GameRules::SEND_COMMAND_FEEDBACK => true,
			GameRules::COMMAND_BLOCKS_ENABLED => true,
			GameRules::MAX_COMMAND_CHAIN_LENGTH => 65535,
			GameRules::FUNCTION_COMMAND_LIMIT => 10000,
			GameRules::SHOW_TAGS => true,
			GameRules::SHOW_BORDER_EFFECT => true,
			GameRules::SHOW_RECIPE_MESSAGES => true,
		];
		foreach($expected as $rule => $value){
			self::assertSame($value, GameRules::getDefault($rule), $rule);
			self::assertSame($value, $this->world->getGameRule($rule), $rule);
		}
		foreach([GameRules::SHOW_TAGS, GameRules::SHOW_BORDER_EFFECT, GameRules::SHOW_RECIPE_MESSAGES] as $rule){
			self::assertTrue(GameRules::isSentToClients($rule), "$rule is applied by the client");
		}
		self::assertSame(GameRules::SHOW_BORDER_EFFECT, GameRules::normalizeName("showBorderEffect"));
		self::assertTrue($this->world->setGameRule("maxCommandChainLength", 100));
		self::assertSame(100, $this->world->getGameRule(GameRules::MAX_COMMAND_CHAIN_LENGTH));
	}

	public function testSheepEatsButLeavesTheGrassWithoutMobGriefing() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$this->set(0, 0, 0, VanillaBlocks::GRASS());
		$sheep = new Sheep($this->loc(0.5, 1, 0.5));
		$sheep->setSheared(true);
		$sheep->setAge(Animal::BABY_AGE); //babies eat 50x more often
		for($i = 0; $i < 3000 && $sheep->isSheared(); ++$i){
			$this->runTicks(1);
			if($sheep->getPosition()->distanceSquared(new Vector3(0.5, 1, 0.5)) > 0.01){
				$sheep->teleport(new Vector3(0.5, 1, 0.5));
			}
		}
		self::assertFalse($sheep->isSheared(), "the sheep still eats and regrows its wool");
		self::assertSame(BlockTypeIds::GRASS, $this->get(0, 0, 0)->getTypeId(), "the grass stays");
	}

	public function testFarmerDoesNotHarvestWithoutMobGriefing() : void{
		$this->set(2, 0, 2, VanillaBlocks::FARMLAND());
		$this->set(2, 1, 2, VanillaBlocks::WHEAT()->setAge(Crops::MAX_AGE));
		$farmer = new VillagerV2($this->loc(0.5, 1, 0.5));
		$farmer->setAge(0);
		$farmer->setProfession(VillagerV2::PROFESSION_FARMER);
		$farmer->spawnToAll();
		$this->world->setTime(1000);
		$goal = new HarvestCropsGoal($farmer);

		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$this->setPrivate($goal, "nextSearch", 0);
		self::assertFalse($goal->canStart());

		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		$this->setPrivate($goal, "nextSearch", 0);
		self::assertTrue($goal->canStart(), "control: the grown wheat is found with mobgriefing on");
	}

	private function snowLayersAround(int $radius) : int{
		$count = 0;
		for($x = -$radius; $x <= $radius; ++$x){
			for($z = -$radius; $z <= $radius; ++$z){
				if($this->get($x, 1, $z)->getTypeId() === BlockTypeIds::SNOW_LAYER){
					++$count;
				}
			}
		}
		return $count;
	}

	public function testSnowGolemTrail() : void{
		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		$golem = new SnowGolem($this->loc(0.5, 1, 0.5));
		$golem->spawnToAll();
		$this->runTicks(40);
		self::assertSame(0, $this->snowLayersAround(12), "no trail without mobgriefing");

		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		$this->runTicks(40);
		self::assertGreaterThan(0, $this->snowLayersAround(12), "control: the golem leaves snow with mobgriefing on");
	}

	public function testEvokerDoesNotConvertSheepWithoutMobGriefing() : void{
		$evoker = new Evoker($this->loc(0.5, 1, 0.5));
		$evoker->spawnToAll();
		$sheep = new Sheep($this->loc(4.5, 1, 0.5));
		$sheep->setColor(DyeColor::BLUE);
		$sheep->spawnToAll();
		$goal = new EvokerWololoGoal($evoker);

		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		self::assertFalse($goal->canStart());
		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		self::assertTrue($goal->canStart(), "control: the blue sheep is a target with mobgriefing on");
	}

	public function testRavagerKeepsLeavesWithoutMobGriefing() : void{
		$ravager = new Ravager($this->loc(0.5, 1, 0.5));
		$ravager->spawnToAll();
		$this->set(1, 1, 0, VanillaBlocks::OAK_LEAVES());

		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		self::assertSame(0, $ravager->breakBlocksInTheWay());
		self::assertSame(BlockTypeIds::OAK_LEAVES, $this->get(1, 1, 0)->getTypeId());

		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		self::assertSame(1, $ravager->breakBlocksInTheWay(), "control");
		self::assertSame(BlockTypeIds::AIR, $this->get(1, 1, 0)->getTypeId());
	}

	public function testPiglinDoesNotPickUpGoldWithoutMobGriefing() : void{
		$piglin = new Piglin($this->loc(0.5, 1, 0.5));
		$piglin->setBaby(false); //babies don't want gold
		$piglin->spawnToAll();
		$gold = $this->world->dropItem(new Vector3(1.5, 1, 0.5), VanillaItems::GOLD_INGOT(), new Vector3(0, 0, 0), 0);
		self::assertNotNull($gold);

		$this->world->setGameRule(GameRules::MOB_GRIEFING, false);
		self::assertFalse($piglin->pickUp($gold));
		self::assertFalse($gold->isFlaggedForDespawn());

		$this->world->setGameRule(GameRules::MOB_GRIEFING, true);
		self::assertTrue($piglin->pickUp($gold), "control");
	}

	public function testVehiclesAndArmorStandsDropNothingWithoutEntityDrops() : void{
		$this->world->setGameRule(GameRules::DO_ENTITY_DROPS, false);
		$boat = new Boat($this->loc(0.5, 1, 0.5));
		$boat->spawnToAll();
		$boat->breakBoat(true);
		$cart = new Minecart($this->loc(4.5, 1, 0.5));
		$cart->spawnToAll();
		$cart->breakCart(true);
		$stand = new ArmorStand($this->loc(8.5, 1, 0.5));
		$stand->spawnToAll();
		$stand->kill();
		$this->runTicks(30);
		self::assertSame([], $this->itemEntities());
	}

	public function testVehiclesAndArmorStandsDropWithEntityDrops() : void{
		$boat = new Boat($this->loc(0.5, 1, 0.5));
		$boat->spawnToAll();
		$boat->breakBoat(true);
		$cart = new Minecart($this->loc(4.5, 1, 0.5));
		$cart->spawnToAll();
		$cart->breakCart(true);
		$stand = new ArmorStand($this->loc(8.5, 1, 0.5));
		$stand->spawnToAll();
		$stand->kill();
		$this->runTicks(30);
		$items = $this->itemEntities();
		self::assertCount(3, $items, "control: a boat, a minecart and an armor stand");
		self::assertCount(1, array_filter($items, fn(ItemEntity $e) => $e->getItem()->equals(VanillaItems::ARMOR_STAND())));
	}

	public function testArrowDoesNotBreakAPotWithoutProjectileBlockBreaking() : void{
		$this->set(0, 1, 0, VanillaBlocks::DECORATED_POT());
		$arrow = new Arrow($this->loc(0.5, 3, 0.5), null, false);
		$hit = new RayTraceResult($this->get(0, 1, 0)->getCollisionBoxes()[0], Facing::UP, new Vector3(0.5, 2, 0.5));

		$this->world->setGameRule(GameRules::PROJECTILES_CAN_BREAK_BLOCKS, false);
		$this->get(0, 1, 0)->onProjectileHit($arrow, $hit);
		self::assertSame(BlockTypeIds::DECORATED_POT, $this->get(0, 1, 0)->getTypeId());

		$this->world->setGameRule(GameRules::PROJECTILES_CAN_BREAK_BLOCKS, true);
		$this->get(0, 1, 0)->onProjectileHit($arrow, $hit);
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId(), "control: the pot shatters");
		$arrow->flagForDespawn();
	}

	/**
	 * Breaks a diamond ore as a survival player and returns the experience BlockBreakEvent offered (the event is
	 * cancelled, so nothing else happens).
	 */
	private function oreExperience() : int{
		$this->set(3, 1, 3, VanillaBlocks::DIAMOND_ORE());
		$player = $this->mockPlayer();
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("isSurvival")->willReturn(true);
		$player->method("isCreative")->willReturn(false);
		$player->method("isSpectator")->willReturn(false);
		$player->method("isAdventure")->willReturn(false);
		$xp = -1;
		$plugin = $this->createMock(Plugin::class);
		$plugin->method("isEnabled")->willReturn(true);
		$handlers = HandlerListManager::global()->getListFor(BlockBreakEvent::class);
		$listener = new RegisteredListener(function(BlockBreakEvent $ev) use (&$xp) : void{
			$xp = $ev->getXpDropAmount();
			$ev->cancel();
		}, EventPriority::NORMAL, $plugin, false, new TimingsHandler("test"));
		$handlers->register($listener);
		try{
			$item = VanillaItems::DIAMOND_PICKAXE();
			$this->world->useBreakOn(new Vector3(3, 1, 3), $item, $player);
		}finally{
			$handlers->unregister($listener);
		}
		return $xp;
	}

	public function testTileDropsOffStopsBlockExperience() : void{
		$this->world->setGameRule(GameRules::DO_TILE_DROPS, false);
		self::assertSame(0, $this->oreExperience());
		$this->world->setGameRule(GameRules::DO_TILE_DROPS, true);
		self::assertGreaterThan(0, $this->oreExperience(), "control: diamond ore gives 3-7 experience");
	}

	/**
	 * @phpstan-param \ArrayObject<int, Translatable|string> $received
	 * @phpstan-return CommandSender&MockObject
	 */
	private function admin(\ArrayObject $received) : CommandSender{
		$admin = $this->createMock(CommandSender::class);
		$admin->method("sendMessage")->willReturnCallback(function(Translatable|string $message) use ($received) : void{
			$received[] = $message;
		});
		return $admin;
	}

	/**
	 * @phpstan-param \ArrayObject<int, Translatable|string> $sourceMessages
	 * @phpstan-param \ArrayObject<int, Translatable|string> $adminMessages
	 */
	private function playerSource(\ArrayObject $sourceMessages, \ArrayObject $adminMessages) : Player{
		/** @var Server&MockObject $server */
		$server = $this->world->getServer();
		$admin = $this->admin($adminMessages);
		$server->method("getBroadcastChannelSubscribers")->willReturn([$admin]);
		$player = $this->mockPlayer();
		$player->method("getServer")->willReturn($server);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getName")->willReturn("Op");
		$player->method("sendMessage")->willReturnCallback(function(Translatable|string $message) use ($sourceMessages) : void{
			$sourceMessages[] = $message;
		});
		return $player;
	}

	public function testSendCommandFeedback() : void{
		/** @phpstan-var \ArrayObject<int, Translatable|string> $toSource */
		$toSource = new \ArrayObject();
		/** @phpstan-var \ArrayObject<int, Translatable|string> $toAdmin */
		$toAdmin = new \ArrayObject();
		$player = $this->playerSource($toSource, $toAdmin);

		Command::broadcastCommandMessage($player, "done");
		self::assertCount(1, $toSource);
		self::assertCount(1, $toAdmin, "other admins are told by default");

		$this->world->setGameRule(GameRules::SEND_COMMAND_FEEDBACK, false);
		Command::broadcastCommandMessage($player, "done");
		self::assertCount(1, $toSource, "no feedback to the player");
		self::assertCount(1, $toAdmin, "no echo to the other admins");
	}

	public function testCommandBlocksEnabled() : void{
		if(PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR) === null){
			DefaultPermissions::registerCorePermissions();
		}
		/** @var Server&MockObject $server */
		$server = $this->world->getServer();
		/** @phpstan-var \ArrayObject<int, string> $dispatched */
		$dispatched = new \ArrayObject();
		$server->method("dispatchCommand")->willReturnCallback(function(CommandSender $sender, string $command) use ($dispatched) : bool{
			$dispatched[] = $command;
			return true;
		});
		$cart = new MinecartCommandBlock($this->loc(0.5, 1, 0.5));
		$cart->setCommand("say hi");

		$this->world->setGameRule(GameRules::COMMAND_BLOCKS_ENABLED, false);
		self::assertFalse($cart->runCommand());
		self::assertCount(0, $dispatched);

		$this->world->setGameRule(GameRules::COMMAND_BLOCKS_ENABLED, true);
		self::assertTrue($cart->runCommand(), "control");
		self::assertCount(1, $dispatched);
		$cart->flagForDespawn();
	}

	public function testCommandBlockOutput() : void{
		if(PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR) === null){
			DefaultPermissions::registerCorePermissions();
		}
		/** @phpstan-var \ArrayObject<int, Translatable|string> $toAdmin */
		$toAdmin = new \ArrayObject();
		/** @var Server&MockObject $server */
		$server = $this->world->getServer();
		$server->method("getBroadcastChannelSubscribers")->willReturn([$this->admin($toAdmin)]);
		$cart = new MinecartCommandBlock($this->loc(0.5, 1, 0.5));
		$sender = $cart->getCommandSender();

		Command::broadcastCommandMessage($sender, "done");
		self::assertCount(1, $toAdmin, "admins see command block output by default");
		self::assertSame("done", $cart->getLastOutput());

		$this->world->setGameRule(GameRules::COMMAND_BLOCK_OUTPUT, false);
		$cart->setLastOutput("");
		Command::broadcastCommandMessage($sender, "done");
		self::assertCount(1, $toAdmin, "commandblockoutput off: not told");
		self::assertSame("done", $cart->getLastOutput(), "the cart still keeps its output");
		$cart->flagForDespawn();
	}

	public function testGameruleCommandAcceptsTheNewRules() : void{
		if(PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR) === null){
			DefaultPermissions::registerCorePermissions();
		}
		/** @phpstan-var \ArrayObject<int, Translatable|string> $toSource */
		$toSource = new \ArrayObject();
		$player = $this->playerSource($toSource, new \ArrayObject());
		$command = new GameruleCommand();
		$command->execute($player, "gamerule", ["doEntityDrops", "false"]);
		self::assertFalse($this->world->getGameRule(GameRules::DO_ENTITY_DROPS));
		$command->execute($player, "gamerule", ["functioncommandlimit", "5"]);
		self::assertSame(5, $this->world->getGameRule(GameRules::FUNCTION_COMMAND_LIMIT));
		$command->execute($player, "gamerule", ["showtags", "12"]);
		self::assertTrue($this->world->getGameRule(GameRules::SHOW_TAGS), "invalid value rejected");

		$names = [];
		foreach($command->getOverloads() as $overload){
			foreach($overload as $parameter){
				foreach($parameter->getEnumValues() as $value){
					$names[] = $value;
				}
			}
		}
		foreach([GameRules::DO_ENTITY_DROPS, GameRules::PROJECTILES_CAN_BREAK_BLOCKS, GameRules::SEND_COMMAND_FEEDBACK, GameRules::MAX_COMMAND_CHAIN_LENGTH, GameRules::SHOW_RECIPE_MESSAGES] as $rule){
			self::assertContains($rule, $names, "$rule is offered by /gamerule");
		}
		self::assertGreaterThan(0, count($toSource));
	}
}
