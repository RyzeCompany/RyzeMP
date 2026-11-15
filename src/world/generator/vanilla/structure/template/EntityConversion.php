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

namespace pocketmine\world\generator\vanilla\structure\template;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;

/**
 * Entities of structure templates (villagers, iron golems, cats, farm animals...): Java entity data to the data of a
 * pending entity (see {@link Chunk::addPendingEntityNBT()}).
 */
final class EntityConversion{
	/** Java entity ids whose Bedrock identifier differs. */
	private const BEDROCK_IDS = [
		"minecraft:villager" => "minecraft:villager_v2",
		"minecraft:zombie_villager" => "minecraft:zombie_villager_v2",
		"minecraft:zombified_piglin" => "minecraft:zombie_pigman",
		"minecraft:evoker" => "minecraft:evocation_illager",
		"minecraft:snow_golem" => "minecraft:snow_golem",
	];

	private function __construct(){
		//NOOP
	}

	public static function bedrockId(string $javaId) : string{
		$javaId = JavaBlocks::namespaced($javaId);
		return self::BEDROCK_IDS[$javaId] ?? $javaId;
	}

	/**
	 * Pending entity data (Bedrock identifier, position, rotation) for a Java entity placed at the given position.
	 */
	public static function pendingEntity(CompoundTag $java, float $x, float $y, float $z, float $yaw, float $pitch) : ?CompoundTag{
		$id = $java->getString("id", "");
		if($id === ""){
			return null;
		}
		return CompoundTag::create()
			->setString(Chunk::PENDING_ENTITY_IDENTIFIER, self::bedrockId($id))
			->setTag(Chunk::PENDING_ENTITY_POS, new ListTag([new DoubleTag($x), new DoubleTag($y), new DoubleTag($z)]))
			->setTag(Chunk::PENDING_ENTITY_ROTATION, new ListTag([new FloatTag($yaw), new FloatTag($pitch)]));
	}

	/**
	 * Yaw and pitch of Java entity data ("Rotation" list).
	 * @phpstan-return array{float, float}
	 */
	public static function rotation(CompoundTag $java) : array{
		$rotation = $java->getListTag("Rotation");
		$values = [];
		foreach($rotation ?? [] as $tag){
			if($tag instanceof FloatTag){
				$values[] = $tag->getValue();
			}
		}
		return [$values[0] ?? 0.0, $values[1] ?? 0.0];
	}
}
