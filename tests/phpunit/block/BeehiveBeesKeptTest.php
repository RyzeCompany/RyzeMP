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

use pocketmine\block\tile\Beehive as BeehiveTile;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use function array_filter;
use function count;

/**
 * Beehives keeping their bees: mined with Silk Touch (B3-10), and while the front is blocked (B3-09).
 */
final class BeehiveBeesKeptTest extends MobTestCase{

	private function bees() : int{
		return count(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Bee && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	private function placeHive(int $x, int $y, int $z, int $bees, int $ticksLeft) : BeehiveTile{
		$this->world->setBlockAt($x, $y, $z, VanillaBlocks::BEEHIVE()->setFacing(Facing::NORTH));
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(BeehiveTile::class, $tile);
		for($i = 0; $i < $bees; ++$i){
			$bee = new Bee(new Location($x + 0.5, $y + 2, $z + 0.5, $this->world, 0, 0));
			$tile->addOccupant(Bee::getNetworkTypeId(), $bee->saveNBT(), $ticksLeft);
			$bee->flagForDespawn();
		}
		$this->world->scheduleDelayedBlockUpdate(new Vector3($x, $y, $z), Beehive::BEE_UPDATE_INTERVAL);
		$this->runTicks(1);
		return $tile;
	}

	/**
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	private function droppedHives() : array{
		$items = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn() && $entity->getItem()->getTypeId() === VanillaBlocks::BEEHIVE()->asItem()->getTypeId()){
				$items[] = $entity->getItem();
			}
		}
		return $items;
	}

	public function testSilkTouchKeepsBeesAfterPlacingAgain() : void{
		$this->world->setTime(1000);
		$this->placeHive(0, 1, 0, 3, Beehive::TICKS_WITH_NECTAR);
		$axe = VanillaItems::DIAMOND_AXE()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SILK_TOUCH()));
		$this->world->useBreakOn(new Vector3(0, 1, 0), $axe, null, false);
		self::assertSame(0, $this->bees(), "no bee comes out");
		$drops = $this->droppedHives();
		self::assertCount(1, $drops);
		$occupants = $drops[0]->getNamedTag()->getListTag(BeehiveTile::TAG_OCCUPANTS);
		self::assertNotNull($occupants);
		self::assertCount(3, $occupants);

		//placed again: the bees are back inside and leave when their time is up
		$item = $drops[0];
		self::assertTrue($this->world->useItemOn(new Vector3(4, 0, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5), null, false));
		$tile = $this->world->getTileAt(4, 1, 0);
		self::assertInstanceOf(BeehiveTile::class, $tile);
		self::assertSame(3, $tile->getOccupantCount());
		$this->runTicks(Beehive::TICKS_WITH_NECTAR + Beehive::BEE_UPDATE_INTERVAL * 2);
		self::assertSame(0, $tile->getOccupantCount());
		self::assertSame(3, $this->bees());
	}

	public function testBreakingWithoutSilkTouchReleasesBees() : void{
		$this->world->setTime(1000);
		$this->placeHive(0, 1, 0, 2, Beehive::TICKS_WITH_NECTAR);
		$axe = VanillaItems::DIAMOND_AXE();
		$this->world->useBreakOn(new Vector3(0, 1, 0), $axe, null, false);
		self::assertSame(2, $this->bees());
		$drops = $this->droppedHives();
		self::assertCount(1, $drops);
		self::assertNull($drops[0]->getNamedTag()->getListTag(BeehiveTile::TAG_OCCUPANTS));
	}

	public function testBlockedFrontKeepsBeesUntilCleared() : void{
		$this->world->setTime(1000);
		self::assertFalse(Bee::isShelterTime($this->world));
		$this->world->setBlockAt(0, 1, -1, VanillaBlocks::STONE()); //the hive faces north
		$tile = $this->placeHive(0, 1, 0, 2, Beehive::BEE_UPDATE_INTERVAL);
		$this->runTicks(Beehive::BEE_UPDATE_INTERVAL * 3);
		self::assertSame(2, $tile->getOccupantCount(), "the bees can't leave by a solid front");
		self::assertSame(0, $this->bees());

		//a block without a collision box doesn't block them
		$this->world->setBlockAt(0, 1, -1, VanillaBlocks::TALL_GRASS());
		$this->runTicks(Beehive::BEE_UPDATE_INTERVAL + 2);
		self::assertSame(0, $tile->getOccupantCount());
		self::assertSame(2, $this->bees());
	}

	public function testBreakingABlockedHiveStillReleasesBees() : void{
		$this->world->setTime(1000);
		$this->world->setBlockAt(0, 1, -1, VanillaBlocks::STONE());
		$this->placeHive(0, 1, 0, 1, Beehive::TICKS_WITH_NECTAR);
		$axe = VanillaItems::DIAMOND_AXE();
		$this->world->useBreakOn(new Vector3(0, 1, 0), $axe, null, false);
		self::assertSame(1, $this->bees());
	}
}
