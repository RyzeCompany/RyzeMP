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
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\Utils;
use function hash;
use function hexdec;
use function intval;
use function ksort;
use pocketmine\block\RuntimeBlockStateRegistry;

class BlockTranslatorTest extends TestCase{

	/**
	 * @doesNotPerformAssertions
	 */
	public function testAllBlockStatesSerialize() : void{
		$blockTranslator = TypeConverter::getInstance()->getBlockTranslator();
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state){
			$blockTranslator->internalIdToNetworkId($state->getStateId());
		}
	}
\tpublic function test12651UsesFNV1a32BlockStateHashes() : void{
\t\t$translator = BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51);
\t\t$internalStateId = \pocketmine\block\VanillaBlocks::STONE()->getStateId();
\t\t$data = $translator->internalIdToNetworkStateData($internalStateId);
\t\t$states = $data->getStates();
\t\tksort($states, SORT_STRING);
\t\t$statesTag = CompoundTag::create();
\t\tforeach(Utils::stringifyKeys($states) as $key => $state){
\t\t\t$statesTag->setTag($key, $state);
\t\t}
\t\t$tag = CompoundTag::create()
\t\t\t->setString(BlockStateData::TAG_NAME, $data->getName())
\t\t\t->setTag(BlockStateData::TAG_STATES, $statesTag);
\t\t$binaryNbt = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
\t\t$expected = intval(hexdec(hash("fnv1a32", $binaryNbt)));
\t\tif($expected > 0x7fffffff){
\t\t\t$expected -= 0x100000000;
\t\t}
\t\tself::assertSame($expected, $translator->internalIdToNetworkId($internalStateId));
\t\tself::assertNotSame($translator->getBlockStateDictionary()->lookupStateIdFromData($data), $expected);
\t}

}
