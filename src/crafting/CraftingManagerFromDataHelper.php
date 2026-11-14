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

use pocketmine\crafting\json\ItemStackData;
use pocketmine\crafting\json\PotionContainerChangeRecipeData;
use pocketmine\crafting\json\PotionTypeRecipeData;
use pocketmine\crafting\json\RecipeIngredientData;
use pocketmine\crafting\json\ShapedRecipeData;
use pocketmine\crafting\json\ShapelessRecipeData;
use pocketmine\crafting\json\SmithingTransformRecipeData;
use pocketmine\crafting\json\SmithingTrimRecipeData;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateDeserializeException;
use pocketmine\data\bedrock\item\BlockItemIdMap;
use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use Symfony\Component\Filesystem\Path;
use function base64_decode;
use function count;
use function get_debug_type;
use function is_array;
use function is_object;
use function json_decode;
use function str_ends_with;

final class CraftingManagerFromDataHelper{

	private static function deserializeIngredient(RecipeIngredientData $data) : ?RecipeIngredient{
		if(isset($data->count) && $data->count !== 1){
			//every case we've seen so far where this isn't the case, it's been a bug and the count was ignored anyway
			//e.g. gold blocks crafted from 9 ingots, but each input item individually had a count of 9
			throw new SavedDataLoadingException("Recipe inputs should have a count of exactly 1");
		}
		if(isset($data->tag)){
			return new TagWildcardRecipeIngredient($data->tag);
		}

		$meta = $data->meta ?? null;
		if($meta === RecipeIngredientData::WILDCARD_META_VALUE){
			//this could be an unimplemented item, but it doesn't really matter, since the item shouldn't be able to
			//be obtained anyway - filtering unknown items is only really important for outputs, to prevent players
			//obtaining them
			return new MetaWildcardRecipeIngredient($data->name);
		}

		$itemStack = self::deserializeItemStackFromFields(
			$data->name,
			$meta,
			$data->count ?? null,
			$data->block_states ?? null,
			null,
			[],
			[]
		);
		if($itemStack === null){
			//probably unknown item
			return null;
		}
		return new ExactRecipeIngredient($itemStack);
	}

	public static function deserializeItemStack(ItemStackData $data) : ?Item{
		//count, name, block_name, block_states, meta, nbt, can_place_on, can_destroy
		return self::deserializeItemStackFromFields(
			$data->name,
			$data->meta ?? null,
			$data->count ?? null,
			$data->block_states ?? null,
			$data->nbt ?? null,
			$data->can_place_on ?? [],
			$data->can_destroy ?? []
		);
	}

	/**
	 * @param string[] $canPlaceOn
	 * @param string[] $canDestroy
	 */
	private static function deserializeItemStackFromFields(string $name, ?int $meta, ?int $count, ?string $blockStatesRaw, ?string $nbtRaw, array $canPlaceOn, array $canDestroy) : ?Item{
		$meta ??= 0;
		$count ??= 1;

		$blockName = BlockItemIdMap::getInstance()->lookupBlockId($name);
		if($blockName !== null){
			if($meta !== 0){
				throw new SavedDataLoadingException("Meta should not be specified for blockitems");
			}
			$blockStatesTag = $blockStatesRaw === null ?
				[] :
				(new LittleEndianNbtSerializer())
					->read(ErrorToExceptionHandler::trapAndRemoveFalse(fn() => base64_decode($blockStatesRaw, true)))
					->mustGetCompoundTag()
					->getValue();
			$blockStateData = BlockStateData::current($blockName, $blockStatesTag);
		}else{
			$blockStateData = null;
		}

		$nbt = $nbtRaw === null ? null : (new LittleEndianNbtSerializer())
			->read(ErrorToExceptionHandler::trapAndRemoveFalse(fn() => base64_decode($nbtRaw, true)))
			->mustGetCompoundTag();

		$itemStackData = new SavedItemStackData(
			new SavedItemData(
				$name,
				$meta,
				$blockStateData,
				$nbt
			),
			$count,
			null,
			null,
			$canPlaceOn,
			$canDestroy,
		);

		try{
			return GlobalItemDataHandlers::getDeserializer()->deserializeStack($itemStackData);
		}catch(ItemTypeDeserializeException $e){
			if($blockName !== null && $blockStatesRaw === null){
				//some recipes give a block item without its states (e.g. minecraft:purpur_block without pillar_axis in
				//purpur stairs): the client accepts any state, so use the block's default one
				$defaultStateData = self::getDefaultBlockStateData($blockName);
				if($defaultStateData !== null){
					try{
						return GlobalItemDataHandlers::getDeserializer()->deserializeStack(new SavedItemStackData(
							new SavedItemData($name, $meta, $defaultStateData, $nbt),
							$count,
							null,
							null,
							$canPlaceOn,
							$canDestroy,
						));
					}catch(ItemTypeDeserializeException){
						//fall through
					}
				}
			}
			//probably unknown item
			return null;
		}
	}

	/**
	 * Returns the default state of the given block (the state of its legacy meta 0), or null if it has none.
	 */
	private static function getDefaultBlockStateData(string $blockName) : ?BlockStateData{
		try{
			return GlobalBlockStateHandlers::getUpgrader()->upgradeStringIdMeta($blockName, 0);
		}catch(BlockStateDeserializeException){
			return null;
		}
	}

	/**
	 * @return mixed[]
	 *
	 * @phpstan-template TData of object
	 * @phpstan-param class-string<TData> $modelCLass
	 * @phpstan-return list<TData>
	 */
	public static function loadJsonArrayOfObjectsFile(string $filePath, string $modelCLass) : array{
		$recipes = json_decode(Filesystem::fileGetContents($filePath));
		if(!is_array($recipes)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($recipes));
		}

		$mapper = new \JsonMapper();
		$mapper->bStrictObjectTypeChecking = false; //to allow hydrating ItemStackData - since this is only used for offline data it's safe
		$mapper->bExceptionOnUndefinedProperty = true;
		$mapper->bExceptionOnMissingData = true;

		return self::loadJsonObjectListIntoModel($mapper, $modelCLass, $recipes);
	}

	/**
	 * @phpstan-template TRecipeData of object
	 * @phpstan-param class-string<TRecipeData> $modelClass
	 * @phpstan-return TRecipeData
	 */
	private static function loadJsonObjectIntoModel(\JsonMapper $mapper, string $modelClass, object $data) : object{
		//JsonMapper does this for subtypes, but not for the base type :(
		try{
			return $mapper->map($data, (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor());
		}catch(\JsonMapper_Exception $e){
			throw new SavedDataLoadingException($e->getMessage(), 0, $e);
		}
	}

	/**
	 * @param mixed[] $data
	 * @return object[]
	 *
	 * @phpstan-template TRecipeData of object
	 * @phpstan-param class-string<TRecipeData> $modelClass
	 * @phpstan-return list<TRecipeData>
	 */
	private static function loadJsonObjectListIntoModel(\JsonMapper $mapper, string $modelClass, array $data) : array{
		$result = [];
		foreach(Utils::promoteKeys($data) as $i => $item){
			if(!is_object($item)){
				throw new SavedDataLoadingException("Invalid entry at index $i: expected object, got " . get_debug_type($item));
			}
			try{
				$result[] = self::loadJsonObjectIntoModel($mapper, $modelClass, $item);
			}catch(SavedDataLoadingException $e){
				throw new SavedDataLoadingException("Invalid entry at index $i: " . $e->getMessage(), 0, $e);
			}
		}
		return $result;
	}

	/**
	 * Recipe book data of a recipe: BedrockData keeps the unlocking ingredients and the priority, but not the
	 * unlocking contexts, so these come from the BDS 1.26.52 CraftingData (recipes without unlocking ingredients are
	 * never unlocked, except the plain crafting table, the plain chest and the boats).
	 */
	private static function deserializeUnlocking(ShapedRecipeData|ShapelessRecipeData $data) : RecipeUnlocking{
		if(count($data->unlockingIngredients) > 0){
			$ingredients = [];
			foreach($data->unlockingIngredients as $ingredientData){
				$ingredient = self::deserializeIngredient($ingredientData);
				if($ingredient !== null){
					$ingredients[] = $ingredient;
				}
			}
			return new RecipeUnlocking($data->priority, RecipeUnlocking::CONTEXT_NONE, $ingredients);
		}
		$context = RecipeUnlocking::CONTEXT_NONE;
		if($data->block === "crafting_table" && count($data->output) === 1){
			$output = $data->output[0]->name;
			if($output === "minecraft:crafting_table" && $data->priority < 2){
				$context = RecipeUnlocking::CONTEXT_ALWAYS_UNLOCKED;
			}elseif($output === "minecraft:chest" && $data->priority === 0){
				$context = RecipeUnlocking::CONTEXT_PLAYER_HAS_MANY_ITEMS;
			}elseif((str_ends_with($output, "_boat") || str_ends_with($output, "_raft")) && !str_ends_with($output, "chest_boat") && !str_ends_with($output, "chest_raft")){
				$context = RecipeUnlocking::CONTEXT_PLAYER_IN_WATER;
			}
		}
		return new RecipeUnlocking($data->priority, $context);
	}

	public static function make(string $directoryPath) : CraftingManager{
		$result = new CraftingManager();

		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'shapeless_crafting.json'), ShapelessRecipeData::class) as $recipe){
			$recipeType = match($recipe->block){
				"crafting_table" => ShapelessRecipeType::CRAFTING,
				"stonecutter" => ShapelessRecipeType::STONECUTTER,
				"smithing_table" => ShapelessRecipeType::SMITHING,
				"cartography_table" => ShapelessRecipeType::CARTOGRAPHY,
				"furnace" => FurnaceType::FURNACE,
				"blast_furnace" => FurnaceType::BLAST_FURNACE,
				"smoker" => FurnaceType::SMOKER,
				"campfire" => FurnaceType::CAMPFIRE,
				"soul_campfire" => FurnaceType::SOUL_CAMPFIRE,
				default => null
			};
			if($recipeType === null){
				continue;
			}
			$inputs = [];
			foreach($recipe->input as $inputData){
				$input = self::deserializeIngredient($inputData);
				if($input === null){ //unknown input item
					continue 2;
				}
				$inputs[] = $input;
			}
			$outputs = [];
			foreach($recipe->output as $outputData){
				$output = self::deserializeItemStack($outputData);
				if($output === null){ //unknown output item
					continue 2;
				}
				$outputs[] = $output;
			}
			if($recipeType instanceof FurnaceType){
				if(count($inputs) !== 1 || count($outputs) !== 1){
					throw new SavedDataLoadingException("Furnace recipes must have exactly 1 input and 1 output");
				}

				//BedrockData has no experience values; campfires give none
				$experience = $recipeType === FurnaceType::CAMPFIRE || $recipeType === FurnaceType::SOUL_CAMPFIRE ?
					0.0 :
					FurnaceExperienceTable::EXPERIENCE[$recipe->output[0]->name] ?? 0.0;
				$result->getFurnaceRecipeManager($recipeType)->register($registered = new FurnaceRecipe(
					$outputs[0],
					$inputs[0],
					$experience
				));
			}else{
				$result->registerShapelessRecipe($registered = new ShapelessRecipe(
					$inputs,
					$outputs,
					$recipeType
				));
			}
			$result->setRecipeUnlocking($registered, self::deserializeUnlocking($recipe));
		}
		//dyeing bundles and shulker boxes: shapeless recipes whose result keeps the container's data
		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'shapeless_shulker_box.json'), ShapelessRecipeData::class) as $recipe){
			if($recipe->block !== "crafting_table"){
				continue;
			}
			$inputs = [];
			foreach($recipe->input as $inputData){
				$input = self::deserializeIngredient($inputData);
				if($input === null){ //unknown input item
					continue 2;
				}
				$inputs[] = $input;
			}
			$outputs = [];
			foreach($recipe->output as $outputData){
				$output = self::deserializeItemStack($outputData);
				if($output === null){ //unknown output item
					continue 2;
				}
				$outputs[] = $output;
			}
			$result->registerShapelessRecipe($registered = new UserDataShapelessRecipe($inputs, $outputs, ShapelessRecipeType::CRAFTING));
			$result->setRecipeUnlocking($registered, self::deserializeUnlocking($recipe));
		}
		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'shaped_crafting.json'), ShapedRecipeData::class) as $recipe){
			if($recipe->block !== "crafting_table"){ //TODO: filter others out for now to avoid breaking economics
				continue;
			}
			$inputs = [];
			foreach(Utils::stringifyKeys($recipe->input) as $symbol => $inputData){
				$input = self::deserializeIngredient($inputData);
				if($input === null){ //unknown input item
					continue 2;
				}
				$inputs[$symbol] = $input;
			}
			$outputs = [];
			foreach($recipe->output as $outputData){
				$output = self::deserializeItemStack($outputData);
				if($output === null){ //unknown output item
					continue 2;
				}
				$outputs[] = $output;
			}
			$result->registerShapedRecipe($registered = new ShapedRecipe(
				$recipe->shape,
				$inputs,
				$outputs
			));
			$result->setRecipeUnlocking($registered, self::deserializeUnlocking($recipe));
		}

		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'potion_type.json'), PotionTypeRecipeData::class) as $recipe){
			$input = self::deserializeIngredient($recipe->input);
			$ingredient = self::deserializeIngredient($recipe->ingredient);
			$output = self::deserializeItemStack($recipe->output);
			if($input === null || $ingredient === null || $output === null){
				continue;
			}
			$result->registerPotionTypeRecipe(new PotionTypeRecipe(
				$input,
				$ingredient,
				$output
			));
		}
		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'potion_container_change.json'), PotionContainerChangeRecipeData::class) as $recipe){
			$ingredient = self::deserializeIngredient($recipe->ingredient);
			if($ingredient === null){
				continue;
			}

			$inputId = $recipe->input_item_name;
			$outputId = $recipe->output_item_name;

			//TODO: this is a really awful way to just check if an ID is recognized ...
			if(
				self::deserializeItemStackFromFields($inputId, null, null, null, null, [], []) === null ||
				self::deserializeItemStackFromFields($outputId, null, null, null, null, [], []) === null
			){
				//unknown item
				continue;
			}
			$result->registerPotionContainerChangeRecipe(new PotionContainerChangeRecipe(
				$inputId,
				$ingredient,
				$outputId
			));
		}
		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'smithing.json'), SmithingTransformRecipeData::class) as $recipe){
			$input = self::deserializeIngredient($recipe->input);
			$template = self::deserializeIngredient($recipe->template);
			$addition = self::deserializeIngredient($recipe->addition);
			$output = self::deserializeItemStack($recipe->output);

			if($input === null || $template === null || $addition === null || $output === null){
				continue;
			}
			$result->registerSmithingRecipe(new SmithingTransformRecipe($input, $addition, $template, $output));
		}
		foreach(self::loadJsonArrayOfObjectsFile(Path::join($directoryPath, 'smithing_trim.json'), SmithingTrimRecipeData::class) as $recipe){
			$input = self::deserializeIngredient($recipe->input);
			$addition = self::deserializeIngredient($recipe->addition);
			$template = self::deserializeIngredient($recipe->template);

			if($input === null || $template === null || $addition === null){
				continue;
			}
			$result->registerSmithingRecipe(new SmithingTrimRecipe($input, $addition, $template));
		}
		$result->registerSpecialCraftingRecipe(new DecoratedPotRecipe());
		$result->registerSpecialCraftingRecipe(new FireworkRecipe());
		foreach(CartographyMapRecipe::all() as $recipe){
			$result->registerSpecialCraftingRecipe($recipe);
		}
		$result->registerSpecialCraftingRecipe(new RepairItemRecipe());
		$result->registerSpecialCraftingRecipe(new BookCloningRecipe());
		$result->registerSpecialCraftingRecipe(new BannerDuplicateRecipe());
		$result->registerSpecialCraftingRecipe(new BannerAddPatternRecipe());
		$result->registerSpecialCraftingRecipe(new ShieldDecorationRecipe());
		foreach(MapCraftingRecipe::all() as $recipe){
			$result->registerSpecialCraftingRecipe($recipe);
		}
		return $result;
	}
}
