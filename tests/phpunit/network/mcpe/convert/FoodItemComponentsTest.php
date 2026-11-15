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

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use pocketmine\item\FoodSourceItem;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\Utils;

/**
 * Clients only know that an item is edible from the components the server sends in ItemRegistryPacket, except for
 * the items they define themselves (version 2). Without minecraft:food a client shows no eating animation and never
 * reports that it finished eating, so the food is never consumed.
 */
final class FoodItemComponentsTest extends TestCase{

	public function testFoodItemsHaveFoodComponents() : void{
		$converter = TypeConverter::getInstance();
		$dictionary = $converter->getItemTypeDictionary();
		$entries = [];
		foreach($dictionary->getEntries() as $entry){
			$entries[$entry->getStringId()] = $entry;
		}

		$checked = 0;
		$missing = [];
		foreach(Utils::stringifyKeys(VanillaItems::getAll()) as $name => $item){
			if(!$item instanceof FoodSourceItem){
				continue;
			}
			$networkId = $converter->getItemTranslator()->toNetworkIdQuiet($item);
			if($networkId === null){
				continue;
			}
			$entry = $entries[$dictionary->fromIntId($networkId[0])] ?? null;
			self::assertInstanceOf(ItemTypeEntry::class, $entry);
			if($entry->getVersion() === 2){
				continue; //defined by the client itself
			}
			$checked++;
			$components = $entry->getComponentNbt()->getRoot();
			$inner = $components instanceof CompoundTag ? $components->getCompoundTag("components") : null;
			if($inner === null || $inner->getCompoundTag("minecraft:food") === null){
				$missing[] = $name;
			}
		}

		self::assertGreaterThan(0, $checked);
		self::assertSame([], $missing, "food items sent to clients without the minecraft:food component");
	}
}
