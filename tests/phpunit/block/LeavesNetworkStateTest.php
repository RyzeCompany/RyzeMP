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

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\VanillaDataFetcher;
use function file_exists;
use function getenv;

/**
 * The client draws the falling leaf particles itself from the leaves block it was sent; these pin what natural and
 * placed leaves look like on the wire (checked against the hashed IDs of a BDS block palette dump).
 */
class LeavesNetworkStateTest extends TestCase{

	public function testLeavesNetworkIdsMatchBedrockDedicatedServer() : void{
		$translator = TypeConverter::getInstance()->getBlockTranslator();
		//minecraft:oak_leaves {persistent_bit: 0/1, update_bit: 0/1}, network_id of CloudburstMC/Data block_palette.nbt
		self::assertSame(2110714365, $translator->internalIdToNetworkId(VanillaBlocks::OAK_LEAVES()->getStateId()), "natural");
		self::assertSame(-2146163422, $translator->internalIdToNetworkId(VanillaBlocks::OAK_LEAVES()->setNoDecay(true)->getStateId()), "placed");
		self::assertSame(1055407794, $translator->internalIdToNetworkId(VanillaBlocks::OAK_LEAVES()->setCheckDecay(true)->getStateId()), "decay check pending");
		self::assertSame(1895459964, $translator->internalIdToNetworkId(VanillaBlocks::CHERRY_LEAVES()->getStateId()), "natural cherry");
		self::assertSame(129912273, $translator->internalIdToNetworkId(VanillaBlocks::PALE_OAK_LEAVES()->getStateId()), "natural pale oak");
	}

	public function testVanillaGeneratorPlacesNaturalLeaves() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !file_exists($dir . "/" . VanillaDataFetcher::JAVA_BLOCKS_FILE)){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set");
		}
		$blocks = JavaBlocks::get($dir);
		foreach(["minecraft:oak_leaves[distance=1,persistent=false,waterlogged=false]", "minecraft:cherry_leaves[distance=3,persistent=false,waterlogged=false]"] as $java){
			$leaves = $blocks->toPmBlock($blocks->parseState($java));
			self::assertInstanceOf(Leaves::class, $leaves, $java);
			self::assertFalse($leaves->isNoDecay(), "$java: persistent_bit must be 0 like BDS trees");
			self::assertFalse($leaves->isCheckDecay(), "$java: update_bit must be 0 like BDS trees");
		}
	}
}
