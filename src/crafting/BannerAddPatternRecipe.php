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

use pocketmine\block\utils\BannerPatternLayer;
use pocketmine\block\utils\BannerPatternType;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BannerPatternTypeIdMap;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\item\Banner;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Filesystem;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function strlen;
use const JSON_THROW_ON_ERROR;

/**
 * Adding a pattern to a banner in the crafting grid (client-hardcoded multi recipe; Bedrock still has it besides the
 * loom). The arrangements come from BedrockData banner_patterns.json:
 * - "shaped" patterns: dyes of one colour on the marked cells of the 3x3 grid, the banner on any other cell;
 * - "shapeless" patterns: the banner, one dye and the required item (bricks, creeper head, vines, oxeye daisy,
 *   enchanted golden apple, wither skeleton skull) anywhere in the grid.
 * Everything in the grid is used up (Java 1.12 BannerAddPatternRecipe, which Bedrock's recipes follow). A banner
 * takes at most 6 patterns.
 */
final class BannerAddPatternRecipe extends MultiCraftingRecipe{
	/** UUID the client uses for this multi recipe (BedrockProtocol MultiRecipe::TYPE_BANNER_ADD_PATTERN) */
	public const MULTI_RECIPE_UUID = "d81aaeaf-e172-4440-9225-868df030d27b";

	public const MAX_PATTERNS = 6;

	/**
	 * @var BannerPatternType[]|null 9-bit mask of the dye cells => pattern
	 * @phpstan-var array<int, BannerPatternType>|null
	 */
	private static ?array $shapedPatterns = null;
	/**
	 * @var (Item|BannerPatternType)[][]|null the required item and its pattern
	 * @phpstan-var list<array{Item, BannerPatternType}>|null
	 */
	private static ?array $shapelessPatterns = null;

	public function getMultiRecipeUuid() : string{
		return self::MULTI_RECIPE_UUID;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		return [new ExactRecipeIngredient(VanillaBlocks::BANNER()->asItem()), new ExactRecipeIngredient(VanillaItems::DYE())];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		$result = self::computeResult($grid->getContents(), $grid->getGridWidth());
		return $result !== null ? [$result] : null;
	}

	private static function loadPatterns() : void{
		$shaped = [];
		$shapeless = [];
		$data = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BANNER_PATTERNS_JSON), true, flags: JSON_THROW_ON_ERROR);
		$idMap = BannerPatternTypeIdMap::getInstance();
		if(is_array($data)){
			foreach($data as $entry){
				if(!is_array($entry) || !is_string($entry["id"] ?? null) || ($type = $idMap->fromId($entry["id"])) === null){
					continue;
				}
				if(($entry["type"] ?? null) === "shaped" && is_array($entry["dye_arrangement"] ?? null) && count($entry["dye_arrangement"]) === 3){
					$mask = 0;
					for($y = 0; $y < 3; ++$y){
						$row = $entry["dye_arrangement"][$y] ?? null;
						if(!is_string($row) || strlen($row) !== 3){
							continue 2;
						}
						for($x = 0; $x < 3; ++$x){
							if($row[$x] === "#"){
								$mask |= 1 << ($y * 3 + $x);
							}
						}
					}
					$shaped[$mask] = $type;
				}elseif(($entry["type"] ?? null) === "shapeless" && is_string($entry["requires"] ?? null) && ($required = self::requiredItem($entry["requires"])) !== null){
					$shapeless[] = [$required, $type];
				}
			}
		}
		self::$shapedPatterns = $shaped;
		self::$shapelessPatterns = $shapeless;
	}

	/**
	 * Returns the item the given banner_patterns.json "requires" name stands for (the file uses old Java names).
	 */
	private static function requiredItem(string $name) : ?Item{
		return match($name){
			"bricks" => VanillaBlocks::BRICKS()->asItem(),
			"creeper_head" => VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::CREEPER)->asItem(),
			"vines" => VanillaBlocks::VINES()->asItem(),
			"oxeye_daisy" => VanillaBlocks::OXEYE_DAISY()->asItem(),
			"enchanted_golden_apple" => VanillaItems::ENCHANTED_GOLDEN_APPLE(),
			"wither_skeleton_skull" => VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::WITHER_SKELETON)->asItem(),
			default => null
		};
	}

	/**
	 * Returns the banner with the new pattern for the given grid contents (slot => item), or null if they make no
	 * banner pattern.
	 *
	 * @internal exposed for tests
	 * @param Item[] $items
	 * @phpstan-param array<int, Item> $items
	 */
	public static function computeResult(array $items, int $gridWidth) : ?Banner{
		if(self::$shapedPatterns === null || self::$shapelessPatterns === null){
			self::loadPatterns();
		}
		$banner = null;
		$dyeColor = null;
		$dyeMask = 0;
		$dyes = 0;
		$other = null;
		foreach($items as $slot => $item){
			if($item->isNull()){
				continue;
			}
			if($item instanceof Banner){
				if($banner !== null){
					return null;
				}
				$banner = $item;
			}elseif(($color = DyeItems::colorOf($item)) !== null){
				if($dyeColor !== null && $dyeColor !== $color){
					return null;
				}
				$dyeColor = $color;
				$dyes++;
				if($gridWidth === 3){
					$dyeMask |= 1 << $slot;
				}
			}elseif($other === null){
				$other = $item;
			}else{
				return null;
			}
		}
		if($banner === null || $dyeColor === null || count($banner->getPatterns()) >= self::MAX_PATTERNS){
			return null;
		}

		$type = null;
		if($other !== null){
			if($dyes !== 1){
				return null;
			}
			foreach(self::$shapelessPatterns ?? [] as [$required, $patternType]){
				if($other->equals($required, true, false)){
					$type = $patternType;
					break;
				}
			}
		}elseif($gridWidth === 3){
			$type = self::$shapedPatterns[$dyeMask] ?? null;
		}
		if($type === null){
			return null;
		}
		return self::withPattern($banner, $type, $dyeColor);
	}

	private static function withPattern(Banner $banner, BannerPatternType $type, DyeColor $color) : Banner{
		$result = clone $banner;
		$result->setCount(1);
		$patterns = $result->getPatterns();
		$patterns[] = new BannerPatternLayer($type, $color);
		return $result->setPatterns($patterns);
	}
}
