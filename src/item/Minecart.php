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

use pocketmine\block\Block;
use pocketmine\entity\Location;
use pocketmine\entity\vehicle\AbstractMinecart;
use pocketmine\entity\vehicle\MinecartType;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;

/**
 * A minecart item (plain, with chest, hopper, TNT or command block): used on a rail, it places the cart on it.
 */
class Minecart extends UnstackableItem{

	/**
	 * @param string[] $enchantmentTags
	 */
	public function __construct(
		ItemIdentifier $identifier,
		string $name = "Unknown",
		array $enchantmentTags = [],
		private MinecartType $minecartType = MinecartType::NORMAL
	){
		parent::__construct($identifier, $name, $enchantmentTags);
	}

	public function getMinecartType() : MinecartType{
		return $this->minecartType;
	}

	/**
	 * Creates and spawns the cart of this item at the given position. Does not consume the item.
	 */
	public function spawnMinecart(World $world, Vector3 $pos, float $yaw = 0.0) : AbstractMinecart{
		$cart = $this->minecartType->createEntity(Location::fromObject($pos, $world, $yaw, 0.0));
		if($this->hasCustomName()){
			$cart->setNameTag($this->getCustomName());
		}
		$cart->spawnToAll();
		return $cart;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$pos = AbstractMinecart::getPlacementPosition($blockClicked);
		if($pos === null){
			return ItemUseResult::NONE;
		}
		$this->spawnMinecart($player->getWorld(), $pos, AbstractMinecart::getRailYaw($blockClicked));
		$player->getWorld()->addGameEvent($pos, GameEvent::ENTITY_PLACE, $player);
		$this->pop();
		return ItemUseResult::SUCCESS;
	}
}
