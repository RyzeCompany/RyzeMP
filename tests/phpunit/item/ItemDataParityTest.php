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

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * Max stack sizes and durabilities compared with the Java 26.3 item components (java_item_data.json, made by
 * tools/generate-java-item-parity-data.php), so that new drift fails. Items are found by their Java name; the ones this
 * server names differently are skipped. Bedrock wins where it differs (BEDROCK_DIFFERENCES).
 */
final class ItemDataParityTest extends TestCase{

	/**
	 * Java name => [Bedrock stack size or null, Bedrock durability or null] (null: same as Java)
	 */
	private const BEDROCK_DIFFERENCES = [
		"minecraft:fishing_rod" => [null, 384],
		//experimental in Bedrock, stack size not checked
		"minecraft:straw_bed" => [1, null],
	];

	/**
	 * @return array{0: int, 1: int|null}[]
	 * @phpstan-return array<string, array{0: int, 1: int|null}>
	 */
	private static function javaData() : array{
		$json = json_decode(Filesystem::fileGetContents(__DIR__ . "/java_item_data.json"), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		$result = [];
		foreach(Utils::promoteKeys($json) as $name => $data){
			self::assertTrue(is_string($name) && is_array($data) && count($data) === 2);
			/** @phpstan-var array{0: int, 1: int|null} $data */
			$result[$name] = $data;
		}
		return $result;
	}

	public function testStackSizesAndDurabilities() : void{
		$parser = StringToItemParser::getInstance();
		$errors = [];
		$checked = 0;
		foreach(Utils::stringifyKeys(self::javaData()) as $name => [$stackSize, $durability]){
			$item = $parser->parse(substr($name, 10));
			if($item === null){
				continue;
			}
			++$checked;
			[$bedrockStack, $bedrockDurability] = self::BEDROCK_DIFFERENCES[$name] ?? [null, null];
			$stackSize = $bedrockStack ?? $stackSize;
			$durability = $bedrockDurability ?? $durability;

			if($item->getMaxStackSize() !== $stackSize){
				$errors[] = "$name: stack size " . $item->getMaxStackSize() . ", vanilla $stackSize";
			}
			//PocketMine stores Bedrock durability + 1 (an item breaks when its damage reaches the max)
			$ours = $item instanceof Durable ? $item->getMaxDurability() - 1 : null;
			if($ours !== $durability){
				$errors[] = "$name: durability " . ($ours ?? "none") . ", vanilla " . ($durability ?? "none");
			}
		}
		self::assertGreaterThan(1400, $checked);
		self::assertSame([], $errors, implode("\n", $errors));
	}

	public function testOminousBannerRoundTrip() : void{
		$data = GlobalItemDataHandlers::getSerializer()->serializeType(VanillaItems::OMINOUS_BANNER());
		self::assertSame(ItemTypeNames::BANNER, $data->getName());
		self::assertSame(15, $data->getMeta(), "white base like the creative entry");

		$back = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($data->getName(), $data->getMeta(), null, $data->getTag()));
		self::assertTrue($back->equals(VanillaItems::OMINOUS_BANNER()));
	}
}
