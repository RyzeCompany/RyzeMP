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
use pocketmine\block\EndPortalFrame;
use pocketmine\entity\Location;
use pocketmine\entity\object\EyeOfEnderSignal;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\dimension\EndPortalRing;
use pocketmine\world\generator\structure\AsyncStructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\sound\EndPortalFrameFillSound;
use pocketmine\world\sound\ThrowSound;
use function in_array;

/**
 * Eye of ender: placed into End portal frames; the twelfth eye of a ring opens the portal. Thrown in the air it flies
 * towards the nearest stronghold (see {@link EyeOfEnderSignal}), in worlds whose generator can locate strongholds.
 */
class EnderEye extends Item{
	private const STRONGHOLD = "stronghold";
	private const SEARCH_RADIUS_CHUNKS = 100;

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if(!$blockClicked instanceof EndPortalFrame || $blockClicked->hasEye()){
			return ItemUseResult::NONE;
		}
		$world = $blockClicked->getPosition()->getWorld();
		$world->setBlock($blockClicked->getPosition(), (clone $blockClicked)->setEye(true));
		$world->addSound($blockClicked->getPosition()->add(0.5, 0.5, 0.5), new EndPortalFrameFillSound());
		$this->pop();

		EndPortalRing::tryActivate($world, $blockClicked->getPosition());
		return ItemUseResult::SUCCESS;
	}

	/**
	 * Throws the eye towards the nearest stronghold within 100 chunks (vanilla EnderEyeItem.use). The search may run off
	 * the main thread: the eye is taken right away and given back if no stronghold is found.
	 */
	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$world = $player->getWorld();
		$locator = StructureLocators::get($world);
		if($locator === null || !in_array(self::STRONGHOLD, $locator->getStructureIds(), true)){
			return ItemUseResult::NONE;
		}
		$from = $player->getPosition()->asVector3();
		$onFound = function(?Vector3 $stronghold) use ($player, $world) : void{
			if(!$player->isConnected() || $player->getWorld() !== $world){
				return;
			}
			if($stronghold === null){
				if($player->hasFiniteResources()){
					foreach($player->getInventory()->addItem(VanillaItems::ENDER_EYE()) as $leftover){
						$world->dropItem($player->getPosition(), $leftover);
					}
				}
				return;
			}
			$eye = new EyeOfEnderSignal(Location::fromObject($player->getEyePos(), $world, 0, 0), $stronghold);
			$eye->spawnToAll();
			$world->addSound($player->getPosition(), new ThrowSound());
		};
		if($locator instanceof AsyncStructureLocator){
			$locator->findNearestAsync($world, self::STRONGHOLD, $from, self::SEARCH_RADIUS_CHUNKS, false, 10, fn(?Vector3 $pos, bool $failed, bool $timedOut) => $onFound($pos));
		}else{
			$onFound($locator->findNearest($world, self::STRONGHOLD, $from, self::SEARCH_RADIUS_CHUNKS, false));
		}
		$this->pop();
		return ItemUseResult::SUCCESS;
	}
}
