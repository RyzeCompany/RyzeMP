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

use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\data\bedrock\ItemTagToIdMap;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\item\Item;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function array_values;
use function chr;
use function count;
use function hash;
use function implode;
use function is_scalar;
use function ksort;
use function ord;
use function spl_object_id;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Recipe book lookups of a crafting manager, built once and rebuilt when recipes change:
 * - the recipe IDs sent to clients (CraftingData, UnlockedRecipes) and saved with the players' unlocked recipes.
 *   They are derived from the recipe's contents, so they stay the same across restarts and recipe reorders.
 * - which recipes an item unlocks (an index by item name, so an inventory change never scans all recipes).
 *
 * @internal
 */
final class RecipeUnlockIndex{
	/**
	 * @var self[]
	 * @phpstan-var array<int, self>
	 */
	private static array $indexes = [];
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private static array $watchedManagers = [];

	public static function get(CraftingManager $manager) : self{
		$id = spl_object_id($manager);
		if(!isset(self::$indexes[$id])){
			if(!isset(self::$watchedManagers[$id])){
				self::$watchedManagers[$id] = true;
				$manager->getDestructorCallbacks()->add(static function() use ($id) : void{
					unset(self::$indexes[$id], self::$watchedManagers[$id]);
				});
				$manager->getRecipeRegisteredCallbacks()->add(static function() use ($id) : void{
					unset(self::$indexes[$id]);
				});
			}
			self::$indexes[$id] = new self($manager);
		}
		return self::$indexes[$id];
	}

	/**
	 * Recipe IDs by recipe number (the order in which the recipes were added).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $ids = [];
	/**
	 * @var int[] recipe ID => recipe number
	 * @phpstan-var array<string, int>
	 */
	private array $numbers = [];
	/**
	 * @var int[] spl_object_id(recipe) => recipe number
	 * @phpstan-var array<int, int>
	 */
	private array $numbersByRecipe = [];
	/** One byte per recipe number: RecipeUnlocking::CONTEXT_* + 1, or 0 for recipes without unlocking data. */
	private string $contexts = "";
	/**
	 * @var string[][] RecipeUnlocking::CONTEXT_* => recipe IDs, built on first use
	 * @phpstan-var array<int, list<string>>
	 */
	private array $contextRecipes = [];
	/**
	 * Unlocking ingredients, each once.
	 * @var RecipeIngredient[]
	 * @phpstan-var list<RecipeIngredient>
	 */
	private array $ingredients = [];
	/**
	 * Unlocking ingredients by the item names they accept: (ingredient index << 24) | recipe number.
	 * @phpstan-var array<string, list<int>>
	 */
	private array $unlockingByName = [];
	/**
	 * Unlocking ingredients of unknown types (plugins), checked against every item, same encoding.
	 * @phpstan-var list<int>
	 */
	private array $otherUnlocking = [];
	/**
	 * @var string[][] item state ID => IDs of the recipes the item unlocks (filled on demand)
	 * @phpstan-var array<int, list<string>>
	 */
	private array $unlockedByStateId = [];

	private const NUMBER_BITS = 24;
	private const NUMBER_MASK = (1 << self::NUMBER_BITS) - 1;

	private function __construct(CraftingManager $manager){
		$ingredientIndexes = [];
		foreach($manager->getCraftingRecipeIndex() as $recipe){
			if($recipe instanceof ShapedRecipe){
				$parts = ["shaped", $recipe->getWidth() . "x" . $recipe->getHeight()];
				for($y = 0, $height = $recipe->getHeight(); $y < $height; ++$y){
					for($x = 0, $width = $recipe->getWidth(); $x < $width; ++$x){
						$ingredient = $recipe->getIngredient($x, $y);
						$parts[] = $ingredient === null ? "-" : self::ingredientKey($ingredient);
					}
				}
			}elseif($recipe instanceof ShapelessRecipe){
				$parts = [$recipe instanceof UserDataShapelessRecipe ? "userdata" : "shapeless", $recipe->getType()->name];
				foreach($recipe->getIngredientList() as $ingredient){
					$parts[] = self::ingredientKey($ingredient);
				}
			}else{
				continue; //special recipes are not in the recipe book
			}
			$parts[] = "=>";
			foreach($recipe->getResults() as $result){
				$parts[] = self::itemKey($result) . "x" . $result->getCount();
			}
			$this->add($manager, $ingredientIndexes, $recipe, $recipe->getResults()[0] ?? null, $parts);
		}

		foreach(FurnaceType::cases() as $furnaceType){
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				$result = $recipe->getResult();
				$this->add($manager, $ingredientIndexes, $recipe, $result, [
					"furnace", $furnaceType->name, self::ingredientKey($recipe->getInput()), "=>", self::itemKey($result) . "x" . $result->getCount()
				]);
			}
		}
	}

	/**
	 * @param string[] $parts             the recipe's contents
	 * @param int[]    $ingredientIndexes spl_object_id(ingredient) => index in {@link RecipeUnlockIndex::$ingredients}
	 * @phpstan-param list<string>    $parts
	 * @phpstan-param array<int, int> $ingredientIndexes
	 */
	private function add(CraftingManager $manager, array &$ingredientIndexes, CraftingRecipe|FurnaceRecipe $recipe, ?Item $result, array $parts) : void{
		$name = $result !== null ? self::itemName($result) : "recipe";
		if(str_starts_with($name, "minecraft:")){
			$name = substr($name, strlen("minecraft:"));
		}
		$base = $name . "_" . substr(hash("xxh3", implode(";", $parts)), 0, 10);
		$id = $base;
		//identical recipes (duplicates in the data) get the same contents: number them in registration order
		for($i = 2; isset($this->numbers[$id]); ++$i){
			$id = $base . "_" . $i;
		}
		$number = count($this->ids);
		if($number > self::NUMBER_MASK){
			throw new \OverflowException("Too many recipes");
		}
		$this->ids[] = $id;
		$this->numbers[$id] = $number;
		$this->numbersByRecipe[spl_object_id($recipe)] = $number;

		$unlocking = $manager->getRecipeUnlocking($recipe);
		if($unlocking === null){
			$this->contexts .= "\x00";
			return;
		}
		$this->contexts .= chr($unlocking->getContext() + 1);
		foreach($unlocking->getUnlockingIngredients() as $ingredient){
			$objectId = spl_object_id($ingredient);
			$ingredientIndex = $ingredientIndexes[$objectId] ?? null;
			if($ingredientIndex === null){
				$ingredientIndex = $ingredientIndexes[$objectId] = count($this->ingredients);
				$this->ingredients[] = $ingredient;
			}
			$entry = ($ingredientIndex << self::NUMBER_BITS) | $number;
			if($ingredient instanceof ExactRecipeIngredient){
				$this->unlockingByName[self::itemName($ingredient->getItem())][] = $entry;
			}elseif($ingredient instanceof MetaWildcardRecipeIngredient){
				$this->unlockingByName[$ingredient->getItemId()][] = $entry;
			}elseif($ingredient instanceof TagWildcardRecipeIngredient){
				foreach(ItemTagToIdMap::getInstance()->getIdsForTag($ingredient->getTagName()) as $itemId){
					$this->unlockingByName[$itemId][] = $entry;
				}
			}else{
				$this->otherUnlocking[] = $entry;
			}
		}
	}

	/**
	 * The item as the unlocking ingredients see it: the unlocking ingredients have no NBT and accept any count, so
	 * renamed or enchanted items unlock the same recipes.
	 */
	public static function unlockProbe(Item $item) : Item{
		$probe = clone $item;
		$probe->setCount(1);
		try{
			$probe->clearNamedTag();
		}catch(SavedDataLoadingException){
			//items that can't lose their NBT (firework rockets and stars): a fresh item of the same type
			try{
				$serializer = GlobalItemDataHandlers::getSerializer();
				$probe = GlobalItemDataHandlers::getDeserializer()->deserializeType($serializer->serializeType($item));
			}catch(ItemTypeSerializeException|ItemTypeDeserializeException){
				//keeps its NBT
			}
		}
		return $probe;
	}

	private static function itemName(Item $item) : string{
		try{
			return GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName();
		}catch(ItemTypeSerializeException){
			return "unknown";
		}
	}

	/**
	 * Name, meta and block states of the item: stable across versions, unlike the runtime type IDs.
	 */
	private static function itemKey(Item $item) : string{
		try{
			$data = GlobalItemDataHandlers::getSerializer()->serializeType($item);
		}catch(ItemTypeSerializeException){
			return (string) $item;
		}
		$key = $data->getName() . ":" . $data->getMeta();
		$block = $data->getBlock();
		if($block !== null){
			$states = [];
			foreach(Utils::stringifyKeys($block->getStates()) as $stateName => $tag){
				$value = $tag->getValue();
				$states[$stateName] = $stateName . "=" . (is_scalar($value) ? (string) $value : "?");
			}
			ksort($states);
			$key .= "[" . implode(",", $states) . "]";
		}
		return $key;
	}

	private static function ingredientKey(RecipeIngredient $ingredient) : string{
		return match(true){
			$ingredient instanceof ExactRecipeIngredient => self::itemKey($ingredient->getItem()),
			$ingredient instanceof MetaWildcardRecipeIngredient => $ingredient->getItemId() . ":*",
			$ingredient instanceof TagWildcardRecipeIngredient => "#" . $ingredient->getTagName(),
			default => (string) $ingredient
		};
	}

	/**
	 * Returns the ID of a crafting or furnace recipe, or null if it isn't in the recipe book (special recipes).
	 */
	public function getRecipeId(CraftingRecipe|FurnaceRecipe $recipe) : ?string{
		$number = $this->numbersByRecipe[spl_object_id($recipe)] ?? null;
		return $number !== null ? $this->ids[$number] : null;
	}

	public function isKnownRecipe(string $recipeId) : bool{
		return isset($this->numbers[$recipeId]);
	}

	/**
	 * Returns the recipe's RecipeUnlocking::CONTEXT_*, or null if the recipe has no unlocking data (always unlocked).
	 */
	public function getContext(string $recipeId) : ?int{
		$number = $this->numbers[$recipeId] ?? null;
		if($number === null){
			return null;
		}
		$context = ord($this->contexts[$number]);
		return $context !== 0 ? $context - 1 : null;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getContextRecipes(int $context) : array{
		if(isset($this->contextRecipes[$context])){
			return $this->contextRecipes[$context];
		}
		if($context < 0 || $context > 254){
			return [];
		}
		$byte = chr($context + 1);
		$recipeIds = [];
		$offset = 0;
		while(($number = strpos($this->contexts, $byte, $offset)) !== false){
			$recipeIds[] = $this->ids[$number];
			$offset = $number + 1;
		}
		return $this->contextRecipes[$context] = $recipeIds;
	}

	/**
	 * Returns the IDs of the recipes that having this item unlocks.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getRecipesUnlockedBy(Item $item) : array{
		if($item->isNull()){
			return [];
		}
		$stateId = $item->getStateId();
		if(isset($this->unlockedByStateId[$stateId])){
			return $this->unlockedByStateId[$stateId];
		}

		$probe = self::unlockProbe($item);
		$candidates = $this->unlockingByName[self::itemName($probe)] ?? [];
		if(count($this->otherUnlocking) > 0){
			$candidates = [...$candidates, ...$this->otherUnlocking];
		}
		$recipeIds = [];
		foreach($candidates as $entry){
			$number = $entry & self::NUMBER_MASK;
			if(!isset($recipeIds[$number]) && $this->ingredients[$entry >> self::NUMBER_BITS]->accepts($probe)){
				$recipeIds[$number] = $this->ids[$number];
			}
		}
		return $this->unlockedByStateId[$stateId] = array_values($recipeIds);
	}
}
