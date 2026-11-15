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

namespace pocketmine\entity\object;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;

/**
 * Entities in the void: living ones are hurt, everything else disappears, and nothing far below the world (where block
 * position hashes stop working) crashes the tick.
 */
final class VoidDespawnTest extends MobTestCase{

	private function isGone(Entity $entity) : bool{
		return $entity->isClosed() || $entity->isFlaggedForDespawn();
	}

	public function testItemBelowTheVoidLineDisappears() : void{
		$item = $this->world->dropItem(new Vector3(0.5, $this->world->getMinY() - 30, 0.5), VanillaItems::DIAMOND());
		self::assertNotNull($item);
		$this->runTicks(12);
		self::assertTrue($this->isGone($item), "destroyed by the void damage");
	}

	public function testItemFarBelowTheWorldDisappearsWithoutCrashing() : void{
		//below the range of World::blockHash(): the item's hopper check must not hash its position
		$item = $this->world->dropItem(new Vector3(0.5, $this->world->getMinY() - 700, 0.5), VanillaItems::DIAMOND());
		self::assertNotNull($item);
		$this->runTicks(3);
		self::assertTrue($this->isGone($item));
	}

	public function testItemSurvivingTheVoidDamageIsRemovedBeforeLeavingTheHashRange() : void{
		$item = $this->world->dropItem(new Vector3(0.5, $this->world->getMinY() - 30, 0.5), VanillaItems::DIAMOND(), new Vector3(0, -2, 0));
		self::assertNotNull($item);
		//too sturdy for the void damage (like an item whose void damage a plugin cancels)
		$item->setMaxHealth(1_000_000);
		$item->setHealth(1_000_000);
		for($i = 0; $i < 200 && !$this->isGone($item); ++$i){
			$this->runTicks(1);
			self::assertGreaterThan($this->world->getMinY() - 64 - 5, $item->getPosition()->y, "removed about 64 blocks below the world");
		}
		self::assertTrue($this->isGone($item));
		self::assertTrue($item->isAlive(), "removed, not killed");
	}

	public function testExperienceOrbsFarBelowTheWorldDisappear() : void{
		$orbs = $this->world->dropExperience(new Vector3(0.5, $this->world->getMinY() - 300, 0.5), 20);
		self::assertNotEmpty($orbs);
		$this->runTicks(3);
		foreach($orbs as $orb){
			self::assertTrue($this->isGone($orb));
		}
	}

	/**
	 * @return \Generator<string, array{float}>
	 */
	public static function deathDepthProvider() : \Generator{
		yield "between the void line and the removal line" => [-30];
		yield "far below the world" => [-700];
	}

	/**
	 * @dataProvider deathDepthProvider
	 */
	public function testMobDyingInTheVoidLeavesNothingBehind(float $depth) : void{
		$cow = new Cow(new Location(0.5, $this->world->getMinY() + $depth, 0.5, $this->world, 0, 0));
		$cow->setHealth(1);
		//weaving spreads cobwebs where the mob dies: not below the world
		$cow->getEffects()->add(new EffectInstance(VanillaEffects::WEAVING(), 200));
		$cow->spawnToAll();
		$drops = 0;
		for($i = 0; $i < 60; ++$i){
			$this->runTicks(1);
			foreach($this->world->getEntities() as $entity){
				if($entity instanceof ItemEntity){
					++$drops;
				}
			}
		}
		self::assertFalse($cow->isAlive(), "killed by the void");
		self::assertGreaterThan(0, $drops, "the cow dropped its beef");
		foreach($this->world->getEntities() as $entity){
			self::assertNotInstanceOf(ItemEntity::class, $entity, "the drops were destroyed or removed");
			self::assertNotInstanceOf(ExperienceOrb::class, $entity);
		}
	}
}
