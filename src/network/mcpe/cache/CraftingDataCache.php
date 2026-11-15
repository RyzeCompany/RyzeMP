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

namespace pocketmine\network\mcpe\cache;

use pmmp\encoding\BE;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\crafting\CartographyMapRecipe;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\DecoratedPotRecipe;
use pocketmine\crafting\FurnaceType;
use pocketmine\crafting\MultiCraftingRecipe;
use pocketmine\crafting\RecipeUnlockIndex;
use pocketmine\crafting\RecipeUnlocking;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\crafting\SmithingTransformRecipe;
use pocketmine\crafting\SmithingTrimRecipe;
use pocketmine\crafting\UserDataShapelessRecipe;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\types\recipe\CraftingRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\MultiRecipe as ProtocolMultiRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionContainerChangeRecipe as ProtocolPotionContainerChangeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionTypeRecipe as ProtocolPotionTypeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeUnlockingRequirement;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe as ProtocolShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\SmithingTransformRecipe as ProtocolSmithingTransformRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\SmithingTrimRecipe as ProtocolSmithingTrimRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use Ramsey\Uuid\Uuid;
use function array_map;
use function spl_object_id;

final class CraftingDataCache{
	use SingletonTrait;

	/**
	 * @var CraftingDataPacket[]
	 * @phpstan-var array<int, CraftingDataPacket>
	 */
	private array $caches = [];
	/**
	 * Encoded CraftingDataPackets: crafting manager object ID => type converter object ID => packet bytes.
	 * @var string[][]
	 * @phpstan-var array<int, array<int, string>>
	 */
	private array $encodedCaches = [];

	/**
	 * The client doesn't like recipes with ID 0 (as of 1.21.100) and complains about them in the content log
	 * This doesn't actually affect the function of the recipe, but it is annoying, so this offset fixes it
	 */
	public const RECIPE_ID_OFFSET = 1;

	public function getCache(CraftingManager $manager) : CraftingDataPacket{
		$id = spl_object_id($manager);
		if(!isset($this->caches[$id])){
			$manager->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id], $this->encodedCaches[$id]);
			});
			$manager->getRecipeRegisteredCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id], $this->encodedCaches[$id]);
			});
			$this->caches[$id] = $this->buildCraftingDataCache($manager);
		}
		return $this->caches[$id];
	}

	/**
	 * Returns the packet of {@link CraftingDataCache::getCache()} encoded as {@link NetworkSession::encodePacketTimed()}
	 * encodes it, so that each join sends the same bytes without encoding them again (~25 ms per join). Built once per
	 * crafting manager and type converter; forgotten with the packet when recipes change.
	 *
	 * @internal
	 */
	public function getEncodedCache(CraftingManager $manager, TypeConverter $typeConverter) : string{
		$packet = $this->getCache($manager);
		return $this->encodedCaches[spl_object_id($manager)][spl_object_id($typeConverter)] ??= NetworkSession::encodePacketTimed(new ByteBufferWriter(), $packet);
	}

	/**
	 * Forgets the encoded packets of the crafting manager (the next {@link CraftingDataCache::getEncodedCache()} encodes
	 * the cached packet again): used when the packet was given to DataPacketSendEvent handlers, which may change it.
	 *
	 * @internal
	 */
	public function clearEncodedCache(CraftingManager $manager) : void{
		unset($this->encodedCaches[spl_object_id($manager)]);
	}

	/**
	 * Rebuilds the cached CraftingDataPacket.
	 */
	private function buildCraftingDataCache(CraftingManager $manager) : CraftingDataPacket{
		Timings::$craftingDataCacheRebuild->startTiming();

		$nullUUID = Uuid::fromString(Uuid::NIL);
		$converter = TypeConverter::getInstance();
		$recipesWithTypeIds = [];

		$unlockIndex = RecipeUnlockIndex::get($manager);
		$recipeNetId = self::RECIPE_ID_OFFSET;
		foreach($manager->getCraftingRecipeIndex() as $index => $recipe){
			//the client doesn't like recipes with an ID of 0, so we need to offset them
			$recipeNetId = $index + self::RECIPE_ID_OFFSET;
			if($recipe instanceof ShapelessRecipe){
				$typeTag = match($recipe->getType()){
					ShapelessRecipeType::CRAFTING => CraftingRecipeBlockName::CRAFTING_TABLE,
					ShapelessRecipeType::STONECUTTER => CraftingRecipeBlockName::STONECUTTER,
					ShapelessRecipeType::CARTOGRAPHY => CraftingRecipeBlockName::CARTOGRAPHY_TABLE,
					ShapelessRecipeType::SMITHING => CraftingRecipeBlockName::SMITHING_TABLE,
				};
				$unlocking = $manager->getRecipeUnlocking($recipe);
				$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
					//the client copies the input's data to the result of user data (bundle/shulker box dyeing) recipes
					$recipe instanceof UserDataShapelessRecipe ? CraftingDataPacket::ENTRY_USER_DATA_SHAPELESS : CraftingDataPacket::ENTRY_SHAPELESS,
					$unlockIndex->getRecipeId($recipe) ?? BE::packUnsignedInt($recipeNetId),
					array_map($converter->coreRecipeIngredientToNet(...), $recipe->getIngredientList()),
					array_map($converter->coreItemStackToNet(...), $recipe->getResults()),
					$nullUUID,
					$typeTag,
					$unlocking?->getPriority() ?? RecipeUnlocking::DEFAULT_PRIORITY,
					self::unlockingRequirementToNet($converter, $unlocking),
					$recipeNetId
				);
			}elseif($recipe instanceof ShapedRecipe){
				$inputs = [];

				for($row = 0, $height = $recipe->getHeight(); $row < $height; ++$row){
					for($column = 0, $width = $recipe->getWidth(); $column < $width; ++$column){
						$inputs[$row][$column] = $converter->coreRecipeIngredientToNet($recipe->getIngredient($column, $row));
					}
				}
				$unlocking = $manager->getRecipeUnlocking($recipe);
				$recipesWithTypeIds[] = new ProtocolShapedRecipe(
					CraftingDataPacket::ENTRY_SHAPED,
					$unlockIndex->getRecipeId($recipe) ?? BE::packUnsignedInt($recipeNetId),
					$inputs,
					array_map($converter->coreItemStackToNet(...), $recipe->getResults()),
					$nullUUID,
					CraftingRecipeBlockName::CRAFTING_TABLE,
					$unlocking?->getPriority() ?? RecipeUnlocking::DEFAULT_PRIORITY,
					true,
					self::unlockingRequirementToNet($converter, $unlocking),
					$recipeNetId,
				);
			}elseif($recipe instanceof DecoratedPotRecipe){
				//hardcoded in the client: only the UUID and the network ID are sent
				$recipesWithTypeIds[] = new ProtocolMultiRecipe(
					CraftingDataPacket::ENTRY_MULTI,
					Uuid::fromString(DecoratedPotRecipe::MULTI_RECIPE_UUID),
					$recipeNetId
				);
			}elseif($recipe instanceof CartographyMapRecipe){
				$recipesWithTypeIds[] = new ProtocolMultiRecipe(
					CraftingDataPacket::ENTRY_MULTI,
					Uuid::fromString($recipe->getUuid()),
					$recipeNetId
				);
			}elseif($recipe instanceof MultiCraftingRecipe){
				$recipesWithTypeIds[] = new ProtocolMultiRecipe(
					CraftingDataPacket::ENTRY_MULTI,
					Uuid::fromString($recipe->getMultiRecipeUuid()),
					$recipeNetId
				);
			}else{
				//TODO: other special recipe types
			}
		}

		foreach(FurnaceType::cases() as $furnaceType){
			$typeTag = match($furnaceType){
				FurnaceType::FURNACE => FurnaceRecipeBlockName::FURNACE,
				FurnaceType::BLAST_FURNACE => FurnaceRecipeBlockName::BLAST_FURNACE,
				FurnaceType::SMOKER => FurnaceRecipeBlockName::SMOKER,
				FurnaceType::CAMPFIRE => FurnaceRecipeBlockName::CAMPFIRE,
				FurnaceType::SOUL_CAMPFIRE => FurnaceRecipeBlockName::SOUL_CAMPFIRE
			};
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				$recipeNetId++;
				$unlocking = $manager->getRecipeUnlocking($recipe);
				$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
					CraftingDataPacket::ENTRY_SHAPELESS,
					$unlockIndex->getRecipeId($recipe) ?? BE::packUnsignedInt($recipeNetId),
					[$converter->coreRecipeIngredientToNet($recipe->getInput())],
					[$converter->coreItemStackToNet($recipe->getResult())],
					$nullUUID,
					$typeTag,
					$unlocking?->getPriority() ?? RecipeUnlocking::DEFAULT_PRIORITY,
					self::unlockingRequirementToNet($converter, $unlocking),
					$recipeNetId //not used, but we need to fill them with something unique regardless
				);
			}
		}

		$index = InventoryManager::SMITHING_RECIPE_NETWORK_OFFSET;
		foreach($manager->getSmithingRecipes() as $recipe){
			if($recipe instanceof SmithingTransformRecipe){
				$recipesWithTypeIds[] = new ProtocolSmithingTransformRecipe(
					CraftingDataPacket::ENTRY_SMITHING_TRANSFORM,
					BE::packUnsignedInt($index),
					$converter->coreRecipeIngredientToNet($recipe->getTemplate()),
					$converter->coreRecipeIngredientToNet($recipe->getInput()),
					$converter->coreRecipeIngredientToNet($recipe->getAddition()),
					$converter->coreItemStackToNet($recipe->getResult()),
					CraftingRecipeBlockName::SMITHING_TABLE,
					$index
				);
			}elseif($recipe instanceof SmithingTrimRecipe){
				$recipesWithTypeIds[] = new ProtocolSmithingTrimRecipe(
					CraftingDataPacket::ENTRY_SMITHING_TRIM,
					BE::packUnsignedInt($index),
					$converter->coreRecipeIngredientToNet($recipe->getTemplate()),
					$converter->coreRecipeIngredientToNet($recipe->getInput()),
					$converter->coreRecipeIngredientToNet($recipe->getAddition()),
					CraftingRecipeBlockName::SMITHING_TABLE,
					$index
				);
			}
			$index++;
		}

		$potionTypeRecipes = [];
		foreach($manager->getPotionTypeRecipes() as $recipe){
			$input = $converter->coreRecipeIngredientToNet($recipe->getInput())->getDescriptor();
			$ingredient = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
			if(!$input instanceof StringIdMetaItemDescriptor || !$ingredient instanceof StringIdMetaItemDescriptor){
				throw new AssumptionFailedError();
			}
			$output = $converter->coreItemStackToNet($recipe->getOutput());
			$potionTypeRecipes[] = new ProtocolPotionTypeRecipe(
				$converter->getItemTypeDictionary()->fromStringId($input->getId()),
				$input->getMeta(),
				$converter->getItemTypeDictionary()->fromStringId($ingredient->getId()),
				$ingredient->getMeta(),
				$output->getId(),
				$output->getMeta()
			);
		}

		$potionContainerChangeRecipes = [];
		$itemTypeDictionary = $converter->getItemTypeDictionary();
		foreach($manager->getPotionContainerChangeRecipes() as $recipe){
			$input = $itemTypeDictionary->fromStringId($recipe->getInputItemId());
			$ingredient = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
			if(!$ingredient instanceof StringIdMetaItemDescriptor){
				throw new AssumptionFailedError();
			}
			$output = $itemTypeDictionary->fromStringId($recipe->getOutputItemId());
			$potionContainerChangeRecipes[] = new ProtocolPotionContainerChangeRecipe(
				$input,
				$itemTypeDictionary->fromStringId($ingredient->getId()),
				$output
			);
		}

		Timings::$craftingDataCacheRebuild->stopTiming();
		return CraftingDataPacket::create($recipesWithTypeIds, $potionTypeRecipes, $potionContainerChangeRecipes, [], true);
	}

	/**
	 * Recipes without unlocking data (e.g. from plugins) are always unlocked.
	 */
	private static function unlockingRequirementToNet(TypeConverter $converter, ?RecipeUnlocking $unlocking) : RecipeUnlockingRequirement{
		if($unlocking === null){
			return new RecipeUnlockingRequirement(null);
		}
		$context = $unlocking->getContext();
		if($context !== RecipeUnlocking::CONTEXT_NONE){
			return new RecipeUnlockingRequirement(null);
		}
		//an empty list (e.g. the crafting table from crimson planks, covered by the planks tag recipe) is never unlocked
		return new RecipeUnlockingRequirement(array_map($converter->coreRecipeIngredientToNet(...), $unlocking->getUnlockingIngredients()));
	}
}
