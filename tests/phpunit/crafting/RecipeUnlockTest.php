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

namespace pocketmine\crafting;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\entity\Entity;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\cache\CraftingDataCache;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeUnlockingRequirement;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe as ProtocolShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\network\mcpe\protocol\UnlockedRecipesPacket;
use pocketmine\player\Player;
use pocketmine\player\RecipeBook;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function array_filter;
use function array_values;
use function count;
use function in_array;

/**
 * Recipe book: unlocking data and priorities from BedrockData (checked against a BDS 1.26.52 CraftingData capture),
 * per-player unlocking, saving and the doLimitedCrafting / recipesunlock game rules.
 */
final class RecipeUnlockTest extends TestCase{
	private static ?CraftingManager $manager = null;

	private static function manager() : CraftingManager{
		return self::$manager ??= CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
	}

	private static function index() : RecipeUnlockIndex{
		return RecipeUnlockIndex::get(self::manager());
	}

	/**
	 * Returns the shaped crafting table recipes making the given item, filtered by an ingredient they must accept.
	 *
	 * @return ShapedRecipe[]
	 * @phpstan-return list<ShapedRecipe>
	 */
	private static function shapedRecipesFor(Item $result, Item $ingredient) : array{
		$found = [];
		foreach(self::manager()->getShapedRecipes() as $recipes){
			foreach($recipes as $recipe){
				if(!$recipe->getResults()[0]->equals($result, true, false)){
					continue;
				}
				foreach($recipe->getIngredientList() as $input){
					if($input->accepts($ingredient)){
						$found[] = $recipe;
						break;
					}
				}
			}
		}
		return $found;
	}

	private static function oakPlanksRecipeId() : string{
		foreach(self::shapedRecipesFor(VanillaBlocks::OAK_PLANKS()->asItem(), VanillaBlocks::OAK_LOG()->asItem()) as $recipe){
			$unlocking = self::manager()->getRecipeUnlocking($recipe);
			if($unlocking !== null && count($unlocking->getUnlockingIngredients()) > 0){
				$id = self::index()->getRecipeId($recipe);
				self::assertNotNull($id);
				return $id;
			}
		}
		self::fail("No oak planks recipe unlocked by logs");
	}

	public function testUnlockingDataMatchesBds() : void{
		$manager = self::manager();

		$planks = self::shapedRecipesFor(VanillaBlocks::OAK_PLANKS()->asItem(), VanillaBlocks::OAK_LOG()->asItem());
		self::assertNotEmpty($planks);
		$unlocking = $manager->getRecipeUnlocking($planks[0]);
		self::assertNotNull($unlocking);
		self::assertSame(RecipeUnlocking::CONTEXT_NONE, $unlocking->getContext());
		self::assertSame(0, $unlocking->getPriority());
		self::assertTrue($unlocking->getUnlockingIngredients()[0]->accepts(VanillaBlocks::OAK_LOG()->asItem()));

		$contexts = [];
		foreach(self::shapedRecipesFor(VanillaBlocks::CRAFTING_TABLE()->asItem(), VanillaBlocks::OAK_PLANKS()->asItem()) as $recipe){
			$contexts[] = $manager->getRecipeUnlocking($recipe)?->getContext();
		}
		self::assertContains(RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED, $contexts, "the crafting table is always unlocked");
		self::assertNotContains(RecipeUnlocking::CONTEXT_PLAYER_IN_WATER, $contexts);

		$boat = self::shapedRecipesFor(VanillaItems::OAK_BOAT(), VanillaBlocks::OAK_PLANKS()->asItem());
		self::assertCount(1, $boat);
		self::assertSame(RecipeUnlocking::CONTEXT_PLAYER_IN_WATER, $manager->getRecipeUnlocking($boat[0])?->getContext());

		$chest = array_values(array_filter(
			self::shapedRecipesFor(VanillaBlocks::CHEST()->asItem(), VanillaBlocks::OAK_PLANKS()->asItem()),
			fn(ShapedRecipe $r) => $manager->getRecipeUnlocking($r)?->getPriority() === 0
		));
		self::assertCount(1, $chest);
		self::assertSame(RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS, $manager->getRecipeUnlocking($chest[0])?->getContext());

		self::assertCount(2, self::index()->getContextRecipes(RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED), "BDS: WorkBench_recipeId and WorkBench_recipeId_from_oak");
		self::assertCount(1, self::index()->getContextRecipes(RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS));
		self::assertGreaterThanOrEqual(10, count(self::index()->getContextRecipes(RecipeUnlocking::CONTEXT_PLAYER_IN_WATER)));
	}

	public function testCraftingDataSendsPrioritiesAndRequirements() : void{
		Timings::init();
		$packet = CraftingDataCache::getInstance()->getCache(self::manager());
		$ids = [];
		$priorities = [];
		$planksId = self::oakPlanksRecipeId();
		$planksRequirement = null;
		foreach($packet->recipesWithTypeIds as $recipe){
			if(!$recipe instanceof ProtocolShapedRecipe && !$recipe instanceof ProtocolShapelessRecipe){
				continue;
			}
			self::assertArrayNotHasKey($recipe->getRecipeId(), $ids, "recipe IDs are unique");
			$ids[$recipe->getRecipeId()] = true;
			$priorities[$recipe->getPriority()] = true;
			if($recipe->getRecipeId() === $planksId){
				$planksRequirement = $recipe->getUnlockingRequirement();
			}
		}
		self::assertArrayHasKey(-1, $priorities);
		self::assertArrayHasKey(2, $priorities);
		self::assertNotNull($planksRequirement);
		self::assertSame(RecipeUnlockingRequirement::CONTEXT_NONE, $planksRequirement->getContext());
		self::assertNotEmpty($planksRequirement->getUnlockingIngredients());
	}

	public function testRecipeIdsAreStable() : void{
		$ids = static function(CraftingManager $manager) : array{
			$index = RecipeUnlockIndex::get($manager);
			$ids = [];
			foreach($manager->getCraftingRecipeIndex() as $recipe){
				$ids[] = $index->getRecipeId($recipe);
			}
			return $ids;
		};
		$other = CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
		self::assertSame($ids(self::manager()), $ids($other), "the IDs are saved with the players: they must not change between restarts");
	}

	/**
	 * @param ClientboundPacket[]     $sent
	 * @param array<string, bool|int> $rules
	 */
	private function player(array &$sent, array $rules = []) : Player{
		$player = $this->createMock(Player::class);
		$server = $this->createMock(Server::class);
		$server->method("getCraftingManager")->willReturn(self::manager());
		$session = $this->createMock(NetworkSession::class);
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) use (&$sent) : bool{
			$sent[] = $packet;
			return true;
		});
		$world = $this->createMock(World::class);
		$world->method("getGameRule")->willReturnCallback(fn(string $rule) => $rules[$rule] ?? GameRules::getDefault($rule));
		$inventory = new PlayerInventory($player);
		$armor = new ArmorInventory($player);
		$offHand = new PlayerOffHandInventory($player);
		$player->method("getServer")->willReturn($server);
		$player->method("getNetworkSession")->willReturn($session);
		$player->method("isConnected")->willReturn(true);
		$player->method("getWorld")->willReturn($world);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getArmorInventory")->willReturn($armor);
		$player->method("getOffHandInventory")->willReturn($offHand);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private static function book(Player $player) : RecipeBook{
		$book = new RecipeBook($player);
		$player->getInventory()->getListeners()->add($book);
		$player->getArmorInventory()->getListeners()->add($book);
		$player->getOffHandInventory()->getListeners()->add($book);
		return $book;
	}

	public function testPickingUpALogUnlocksPlanksOnce() : void{
		$sent = [];
		$player = $this->player($sent);
		$book = self::book($player);
		$book->sendInitial();
		self::assertCount(1, $sent);
		self::assertInstanceOf(UnlockedRecipesPacket::class, $sent[0]);
		self::assertSame(UnlockedRecipesPacket::TYPE_INITIALLY_UNLOCKED, $sent[0]->getType());
		self::assertCount(2, $sent[0]->getRecipes(), "only the crafting tables with an empty inventory");

		$planksId = self::oakPlanksRecipeId();
		$player->getInventory()->setItem(0, VanillaBlocks::OAK_LOG()->asItem()->setCount(3));
		self::assertCount(2, $sent);
		self::assertInstanceOf(UnlockedRecipesPacket::class, $sent[1]);
		self::assertSame(UnlockedRecipesPacket::TYPE_NEWLY_UNLOCKED, $sent[1]->getType());
		self::assertContains($planksId, $sent[1]->getRecipes());
		self::assertTrue($book->isUnlocked($planksId));

		$player->getInventory()->setItem(1, VanillaBlocks::OAK_LOG()->asItem());
		$player->getInventory()->setItem(0, VanillaBlocks::OAK_LOG()->asItem()->setCount(5));
		$player->getInventory()->setItem(2, VanillaItems::DIAMOND()->setCustomName("named"));
		$player->getInventory()->setItem(3, VanillaItems::DIAMOND());
		self::assertCount(3, $sent, "one packet for the diamond, none for more logs or a second diamond");

		//saved and loaded with the player data
		$nbt = new CompoundTag();
		$book->save($nbt);
		$loaded = new RecipeBook($this->player($sent));
		$loaded->load($nbt);
		self::assertTrue($loaded->isUnlocked($planksId));
		self::assertSame($book->getUnlockedRecipes(), $loaded->getUnlockedRecipes());
	}

	public function testInitialPacketIncludesInventoryAndSavedRecipes() : void{
		$sent = [];
		$player = $this->player($sent);
		$player->getInventory()->setItem(0, VanillaBlocks::OAK_LOG()->asItem());
		$book = self::book($player);
		$book->sendInitial();
		self::assertCount(1, $sent);
		self::assertInstanceOf(UnlockedRecipesPacket::class, $sent[0]);
		self::assertTrue(in_array(self::oakPlanksRecipeId(), $sent[0]->getRecipes(), true), "an existing player gets what the inventory unlocks");
	}

	public function testContextUnlocks() : void{
		$sent = [];
		$player = $this->player($sent);
		$book = self::book($player);
		$book->sendInitial();
		self::assertTrue($book->isWaitingForWater());
		$book->onEnterWater();
		self::assertFalse($book->isWaitingForWater());
		self::assertInstanceOf(UnlockedRecipesPacket::class, $sent[1]);
		self::assertCount(count(self::index()->getContextRecipes(RecipeUnlocking::CONTEXT_PLAYER_IN_WATER)), $sent[1]->getRecipes());

		$chestId = self::index()->getContextRecipes(RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS)[0];
		for($slot = 0; $slot < RecipeUnlocking::MANY_ITEMS_SLOTS - 1; ++$slot){
			$player->getInventory()->setItem($slot, VanillaBlocks::DIRT()->asItem()->setCount(64));
		}
		self::assertFalse($book->isUnlocked($chestId), "9 slots");
		$player->getInventory()->setItem(RecipeUnlocking::MANY_ITEMS_SLOTS - 1, VanillaBlocks::DIRT()->asItem());
		self::assertTrue($book->isUnlocked($chestId), "10 slots");
	}

	public function testLimitedCrafting() : void{
		$sent = [];
		$planks = self::shapedRecipesFor(VanillaBlocks::OAK_PLANKS()->asItem(), VanillaBlocks::OAK_LOG()->asItem())[0];
		$table = null;
		foreach(self::shapedRecipesFor(VanillaBlocks::CRAFTING_TABLE()->asItem(), VanillaBlocks::OAK_PLANKS()->asItem()) as $recipe){
			if(self::manager()->getRecipeUnlocking($recipe)?->getContext() === RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED){
				$table = $recipe;
			}
		}
		self::assertNotNull($table);

		$book = new RecipeBook($this->player($sent));
		self::assertTrue($book->canCraft($planks), "doLimitedCrafting is off by default");

		$limited = new RecipeBook($this->player($sent, [GameRules::DO_LIMITED_CRAFTING => true]));
		self::assertFalse($limited->canCraft($planks));
		self::assertTrue($limited->canCraft($table), "always unlocked");
		self::assertTrue($limited->canCraft(new FireworkRecipe()), "special recipes aren't in the recipe book");
		$limited->unlockRecipes([self::oakPlanksRecipeId()]);
		self::assertTrue($limited->canCraft($planks));

		$everything = new RecipeBook($this->player($sent, [GameRules::DO_LIMITED_CRAFTING => true, GameRules::RECIPES_UNLOCK => false]));
		self::assertTrue($everything->canCraft($planks), "recipesunlock false unlocks every recipe");
	}
}
