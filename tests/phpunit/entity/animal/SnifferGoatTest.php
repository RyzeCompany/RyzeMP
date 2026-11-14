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

namespace pocketmine\entity\animal;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\ItemTypeIds;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use function count;
use function mt_srand;

/**
 * Sniffer search and dig (sniffer.json timer flags and random_search_and_dig), sniffer egg items, snifflets, goat horn
 * blocks and screamer inheritance.
 */
final class SnifferGoatTest extends MobTestCase{

	protected function tearDown() : void{
		mt_srand();
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function sniffer(float $x, float $z, int $scentCooldown) : Sniffer{
		$sniffer = new Sniffer($this->loc($x, 1, $z), CompoundTag::create()
			->setInt(Animal::TAG_AGE, 0)
			->setInt(Sniffer::TAG_DIG_COOLDOWN, $scentCooldown));
		$sniffer->spawnToAll();
		return $sniffer;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$properties = new EntityMetadataCollection();
		(new \ReflectionMethod($entity, "syncNetworkData"))->invoke($entity, $properties);
		$flags = $properties->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		if($flags === null){
			return false; //no flag of that group set
		}
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	/**
	 * @return ItemEntity[]
	 * @phpstan-return list<ItemEntity>
	 */
	private function items(int $typeId) : array{
		$items = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isClosed() && $entity->getItem()->getTypeId() === $typeId){
				$items[] = $entity;
			}
		}
		return $items;
	}

	private function seedItems() : int{
		return count($this->items(ItemTypeIds::TORCHFLOWER_SEEDS)) + count($this->items(ItemTypeIds::PITCHER_POD));
	}

	private function mossPatch() : void{
		for($x = 12; $x <= 18; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$this->set($x, 0, $z, VanillaBlocks::MOSS_BLOCK());
			}
		}
	}

	public function testSnifferFindsMossAwayAndDigsThere() : void{
		mt_srand(1234);
		$this->mossPatch();
		$sniffer = $this->sniffer(0.5, 0.5, 0);

		$phases = [];
		$lastPhase = null;
		$digStart = null;
		$seedTick = null;
		for($t = 0; $t < 2400; ++$t){
			$this->runTicks(1);
			$phase = match(true){
				self::flag($sniffer, EntityMetadataFlags::TIMER_FLAG_1) => "scent",
				self::flag($sniffer, EntityMetadataFlags::SEARCHING) => "search",
				self::flag($sniffer, EntityMetadataFlags::DIGGING) => "dig",
				self::flag($sniffer, EntityMetadataFlags::TIMER_FLAG_2) => "standup",
				default => null
			};
			if($phase !== null && $phase !== $lastPhase){
				$phases[] = $phase;
				if($phase === "dig"){
					$digStart = $t;
					$target = $sniffer->getDigTarget();
					self::assertNotNull($target);
					self::assertTrue(Sniffer::isDiggable($this->get($target->getFloorX(), $target->getFloorY(), $target->getFloorZ())), "digs a moss block");
					self::assertGreaterThan(9.0, $sniffer->getPosition()->x, "walked to the moss patch");
				}
			}
			$lastPhase = $phase ?? $lastPhase;
			if($seedTick === null && $this->seedItems() > 0){
				$seedTick = $t;
				self::assertTrue($sniffer->isFeelingHappy(), "happy once the seed is found (timer_flag_3)");
				self::assertTrue(self::flag($sniffer, EntityMetadataFlags::DIGGING), "the seed pops out before the dig ends");
			}
			if($phase === "standup"){
				break;
			}
		}
		self::assertSame(["scent", "search", "dig", "standup"], $phases);
		self::assertNotNull($digStart);
		self::assertNotNull($seedTick);
		self::assertEqualsWithDelta(120, $seedTick - $digStart, 1, "the seed drops after 6 seconds of digging");
		self::assertFalse($sniffer->wantsToDig());
	}

	public function testSnifferOnStoneWithoutDiggableBlocksGivesUp() : void{
		$sniffer = $this->sniffer(0.5, 0.5, 0);
		$this->runTicks(60);
		self::assertFalse($sniffer->isScenting());
		self::assertFalse($sniffer->wantsToDig(), "no target block: on_fail_during_searching");
		self::assertFalse($sniffer->isDigging());
		self::assertFalse($sniffer->canDig(), "scenting cooldown of 400-500 seconds");
	}

	public function testDamageStopsDiggingAndTheSnifferStandsUp() : void{
		$sniffer = $this->sniffer(0.5, 0.5, 5000);
		$sniffer->startDigging();
		self::assertTrue(self::flag($sniffer, EntityMetadataFlags::DIGGING));
		self::assertFalse($sniffer->canBePushedBy($sniffer));
		$sniffer->attack(new EntityDamageEvent($sniffer, EntityDamageEvent::CAUSE_FALL, 1));
		self::assertFalse($sniffer->isDigging());
		self::assertTrue($sniffer->isStandingUp());
		self::assertTrue(self::flag($sniffer, EntityMetadataFlags::TIMER_FLAG_2));
		$this->runTicks(130);
		self::assertSame(0, $this->seedItems(), "no seed from an interrupted dig");
		self::assertFalse($sniffer->isStandingUp(), "stands up for 2-5 seconds");
	}

	public function testPregnantSnifferDropsAnEggItem() : void{
		$a = $this->sniffer(0.5, 0.5, 5000);
		$b = $this->sniffer(2.5, 0.5, 5000);
		self::assertNull($a->breed($b));
		self::assertTrue($a->isPregnant());
		$this->runTicks(1);
		self::assertFalse($a->isPregnant(), "spawn_entity wait 0, single use");
		self::assertCount(1, $this->items(VanillaBlocks::SNIFFER_EGG()->asItem()->getTypeId()));
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->get(0, 1, 0)->getTypeId(), "no egg block placed");
		$this->runTicks(5);
		self::assertCount(1, $this->items(VanillaBlocks::SNIFFER_EGG()->asItem()->getTypeId()));
	}

	public function testSnifflets() : void{
		$snifflet = $this->sniffer(0.5, 0.5, 0);
		$snifflet->setAge(Sniffer::SNIFFLET_AGE);
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($snifflet->getGoalSelector());
		foreach($goals as $entry){
			self::assertNotInstanceOf(FollowParentGoal::class, $entry->goal, "sniffer.json has no behavior.follow_parent");
		}
		$this->mossPatch();
		$this->runTicks(3);
		self::assertTrue($snifflet->isScenting(), "snifflets scent too");
		$this->runTicks(60);
		self::assertFalse($snifflet->wantsToDig(), "but only adults search and dig");
		self::assertFalse($snifflet->isDigging());
	}

	public function testGoatHornBlocks() : void{
		self::assertTrue(Goat::breaksHorn(VanillaBlocks::OAK_LOG()));
		self::assertTrue(Goat::breaksHorn(VanillaBlocks::CHERRY_LOG()));
		self::assertTrue(Goat::breaksHorn(VanillaBlocks::STONE()));
		self::assertTrue(Goat::breaksHorn(VanillaBlocks::EMERALD_ORE()));
		self::assertTrue(Goat::breaksHorn(VanillaBlocks::PACKED_ICE()));
		self::assertFalse(Goat::breaksHorn(VanillaBlocks::OAK_LOG()->setStripped(true)), "stripped log");
		self::assertFalse(Goat::breaksHorn(VanillaBlocks::OAK_WOOD()), "wood");
		self::assertFalse(Goat::breaksHorn(VanillaBlocks::CRIMSON_STEM()), "nether stem");
		self::assertFalse(Goat::breaksHorn(VanillaBlocks::COBBLESTONE()));
		self::assertFalse(Goat::breaksHorn(VanillaBlocks::DEEPSLATE_IRON_ORE()));
	}

	public function testGoatRammingAStrippedLogKeepsItsHorns() : void{
		$goat = new Goat($this->loc(0.5, 1, 0.5));
		$this->set(0, 1, 1, VanillaBlocks::OAK_LOG()->setStripped(true));
		$goat->ramBlock(new Vector3(0, 0, 1));
		self::assertSame(2, $goat->getHornCount());
		$this->set(0, 1, 1, VanillaBlocks::STONE());
		$goat->ramBlock(new Vector3(0, 0, 1));
		self::assertSame(1, $goat->getHornCount());
	}

	public function testScreamerInheritance() : void{
		mt_srand(42);
		$trials = 20000;
		$one = 0;
		$none = 0;
		$both = 0;
		for($i = 0; $i < $trials; ++$i){
			$one += Goat::inheritScreamer(true, false) ? 1 : 0;
			$none += Goat::inheritScreamer(false, false) ? 1 : 0;
			$both += Goat::inheritScreamer(true, true) ? 1 : 0;
		}
		//one screamer parent: 98% x 50% + 2% x 2% = 49.04%
		self::assertEqualsWithDelta(0.4904, $one / $trials, 0.02);
		//2% mutation x 2% screamer alleles
		self::assertLessThan(0.002, $none / $trials);
		self::assertEqualsWithDelta(0.9804, $both / $trials, 0.01);
	}
}
