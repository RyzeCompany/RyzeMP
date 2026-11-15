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

namespace pocketmine\entity\ai;

use pocketmine\block\Block;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WaterLily;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\entity\ai\goal\CroakGoal;
use pocketmine\entity\ai\goal\LongJumpGoal;
use pocketmine\entity\ai\goal\MoveToLiquidGoal;
use pocketmine\entity\ai\navigation\BlockSearch;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Frog;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use function abs;
use function array_filter;
use function count;
use function max;
use function mt_srand;

/**
 * Amphibious and lava animals going back to their liquid (move_to_water / move_to_land / move_to_liquid), axolotl
 * combat, strider saddles, frog variants, jumps and croaks.
 */
final class WaterPetsTest extends MobTestCase{

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261021);
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * Fills a box with water (y 1 to $top), on the floor.
	 */
	private function pool(int $minX, int $minZ, int $maxX, int $maxZ, int $top = 1, ?Block $block = null) : void{
		$block ??= VanillaBlocks::WATER();
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = 1; $y <= $top; ++$y){
					$this->world->setBlockAt($x, $y, $z, $block, false);
				}
			}
		}
		PathBlockTypes::clearCache();
	}

	/**
	 * Replaces the floor with lava.
	 */
	private function lavaPool(int $minX, int $minZ, int $maxX, int $maxZ) : void{
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				$this->world->setBlockAt($x, 0, $z, VanillaBlocks::LAVA(), false);
			}
		}
		PathBlockTypes::clearCache();
	}

	/**
	 * Runs ticks until the condition holds; returns the number of ticks it took, or -1.
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function runUntil(\Closure $condition, int $maxTicks) : int{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return $i;
			}
			$this->runTicks(1);
		}
		return $condition() ? $maxTicks : -1;
	}

	private function player(Item $held, bool $sneaking = false) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(Position::fromObject(new Vector3(0.5, 1, 1.5), $this->world));
		$player->method("isSneaking")->willReturn($sneaking);
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		$player->method("getName")->willReturn("Tester");
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = $entity->getNetworkProperties()->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	public function testBlockSearchFindsTheNearestLiquid() : void{
		self::assertNull(BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::WATER), "no water at all");
		$this->pool(10, 0, 12, 0);
		$this->pool(-5, 3, -5, 3);
		self::assertEquals(new Vector3(-5, 1, 3), BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::WATER));
		self::assertEquals(new Vector3(10, 1, 0), BlockSearch::findNearestLiquid($this->world, 9, 1, 0, 16, 5, BlockSearch::WATER));
		self::assertNull(BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 4, 5, BlockSearch::WATER), "out of range");
		self::assertNull(BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::LAVA));

		$this->lavaPool(3, -1, 3, -1);
		self::assertEquals(new Vector3(3, 0, -1), BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::LAVA, true));
		$this->set(3, 1, -1, VanillaBlocks::STONE());
		PathBlockTypes::clearCache();
		self::assertNull(BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::LAVA, true), "covered: no surface");
	}

	public function testBlockSearchPrefersWaterAtTheMobsLevel() : void{
		//cave water under the floor, right below the mob, and a pool two blocks away
		$this->set(0, -3, 0, VanillaBlocks::WATER());
		$this->pool(2, 0, 3, 0);
		self::assertEquals(new Vector3(2, 1, 0), BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::WATER));
		//but beats the pool once it is farther out than the cave water is deep
		self::assertEquals(new Vector3(0, -3, 0), BlockSearch::findNearestLiquid($this->world, -4, 1, 0, 16, 5, BlockSearch::WATER));

		//water held by a solid block (waterlogged stairs) next to the mob doesn't count
		$this->set(1, 1, 0, VanillaBlocks::STONE_STAIRS());
		$water = VanillaBlocks::WATER()->getStateId();
		$this->world->getChunk(0, 0)?->getSubChunk(0)->setLiquidLayerStateId(1, 1, 0, $water);
		PathBlockTypes::clearCache();
		self::assertTrue(LiquidLayer::hasWaterAt($this->world, 1, 1, 0));
		self::assertEquals(new Vector3(2, 1, 0), BlockSearch::findNearestLiquid($this->world, 0, 1, 0, 16, 5, BlockSearch::WATER));
	}

	public function testAxolotlIgnoresCaveWaterUnderItsFeet() : void{
		$this->set(0, -3, 0, VanillaBlocks::WATER());
		$this->pool(2, -1, 3, 1);
		$axolotl = new Axolotl($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$axolotl->spawnToAll();
		$ticks = $this->runUntil(fn() => $axolotl->isInWater(), 400);
		self::assertNotSame(-1, $ticks, "in the pool, at " . $axolotl->getPosition());
		$axolotl->flagForDespawn();
	}

	public function testTurtleGivesUpOnFencedWater() : void{
		//a pool walled in by stone 3 blocks high
		for($x = 5; $x <= 11; ++$x){
			for($z = -3; $z <= 3; ++$z){
				$wall = $x === 5 || $x === 11 || $z === -3 || $z === 3;
				for($y = 1; $y <= 3; ++$y){
					$this->world->setBlockAt($x, $y, $z, $wall ? VanillaBlocks::STONE() : VanillaBlocks::WATER(), false);
				}
			}
		}
		PathBlockTypes::clearCache();
		$turtle = new Turtle($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$turtle->spawnToAll();
		$running = fn() => count(array_filter($turtle->getGoalSelector()->getRunningGoals(), fn($g) => $g instanceof MoveToLiquidGoal)) > 0;
		self::assertNotSame(-1, $this->runUntil($running, 40), "heads for the pool");
		$this->runTicks(20);
		self::assertFalse($running(), "no path into the pool: the turtle doesn't walk towards it");
		$turtle->flagForDespawn();
	}

	public function testBlockSearchFindsTheNearestLand() : void{
		$this->pool(-10, -10, 10, 10, 2);
		self::assertNull(BlockSearch::findNearestLand($this->world, 0, 1, 0, 8, 5), "water all around");
		$land = BlockSearch::findNearestLand($this->world, 0, 1, 0, 16, 5);
		self::assertNotNull($land);
		self::assertSame(11, max(abs((int) $land->x), abs((int) $land->z)), "the shore: " . $land);
		self::assertSame(1.0, (float) $land->y, "on the floor");
	}

	public function testAxolotlOnLandWalksBackToWater() : void{
		$this->pool(8, -2, 12, 2);
		$axolotl = new Axolotl($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$axolotl->spawnToAll();
		$ticks = $this->runUntil(fn() => $axolotl->isInWater(), 800);
		self::assertNotSame(-1, $ticks, "in water, at " . $axolotl->getPosition());
		$axolotl->flagForDespawn();
	}

	public function testHatchlingTurtleHeadsForTheWater() : void{
		$this->pool(-12, -2, -8, 2);
		$turtle = new Turtle($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, Animal::BABY_AGE));
		self::assertTrue($turtle->isBaby());
		$turtle->spawnToAll();
		$ticks = $this->runUntil(fn() => $turtle->isInWater(), 1200);
		self::assertNotSame(-1, $ticks, "in water, at " . $turtle->getPosition());
		$turtle->flagForDespawn();
	}

	public function testAdultTurtleInWaterGoesAshore() : void{
		$this->pool(-6, -6, 6, 6);
		$turtle = new Turtle($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$turtle->spawnToAll();
		$ticks = $this->runUntil(fn() => !$turtle->isInWater(), 1200);
		self::assertNotSame(-1, $ticks, "ashore, at " . $turtle->getPosition());
		$turtle->flagForDespawn();
	}

	public function testStrandedDolphinReturnsToWater() : void{
		$this->pool(5, -3, 9, 3, 2);
		$dolphin = new Dolphin($this->loc(0.5, 1, 0.5));
		$dolphin->spawnToAll();
		$ticks = $this->runUntil(fn() => $dolphin->isInWater(), 600);
		self::assertNotSame(-1, $ticks, "in water, at " . $dolphin->getPosition());
		$dolphin->flagForDespawn();
	}

	public function testStriderWalksBackToLava() : void{
		$this->lavaPool(6, -2, 9, 2);
		$strider = new Strider($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$strider->spawnToAll();
		$this->runTicks(2);
		self::assertTrue($strider->isCold());
		$ticks = $this->runUntil(fn() => $strider->isOnLava(), 800);
		self::assertNotSame(-1, $ticks, "on lava, at " . $strider->getPosition());
		$this->runTicks(2);
		self::assertFalse($strider->isCold());
		$strider->flagForDespawn();
	}

	public function testShearsTakeTheStriderSaddleOff() : void{
		$strider = new Strider($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$shearer = $this->player(VanillaItems::SHEARS());
		self::assertFalse($strider->onInteract($shearer, new Vector3(0, 0, 0)), "nothing to take off");
		$strider->setSaddled(true);
		self::assertSame("action.interact.removesaddle", $strider->getInteractButtonText($shearer));
		self::assertFalse($strider->onInteract($this->player(VanillaItems::SHEARS(), true), new Vector3(0, 0, 0)), "not while sneaking");
		self::assertTrue($strider->isSaddled());

		self::assertTrue($strider->onInteract($shearer, new Vector3(0, 0, 0)));
		self::assertFalse($strider->isSaddled());
		$shears = $shearer->getInventory()->getItemInHand();
		self::assertInstanceOf(Durable::class, $shears);
		self::assertSame(1, $shears->getDamage());
		$saddles = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && $entity->getItem()->getTypeId() === ItemTypeIds::SADDLE){
				$saddles[] = $entity;
			}
		}
		self::assertCount(1, $saddles);
		self::assertEqualsWithDelta(2.7, $saddles[0]->getPosition()->y, 0.3, "dropped 1.7 blocks up");
		$strider->flagForDespawn();
	}

	public function testAxolotlAttacksDrownedEvenDuringItsCooldown() : void{
		$this->pool(-6, -6, 6, 6, 4);
		$axolotl = new Axolotl($this->loc(0.5, 2, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$axolotl->spawnToAll();
		$cod = new Cod($this->loc(2.5, 2, 0.5));
		$cod->spawnToAll();
		//a hit starts the cooldown: no more fish
		self::assertTrue($axolotl->attackMelee($cod, 0.5));
		$cod->flagForDespawn();
		$this->runTicks(1);

		$fish = new Cod($this->loc(-2.5, 2, 0.5));
		$fish->spawnToAll();
		$this->runTicks(60);
		self::assertNull($axolotl->getAttackTarget(), "no fish during the cooldown");

		$drowned = new Drowned($this->loc(3.5, 1, 2.5));
		$drowned->spawnToAll();
		self::assertTrue(Axolotl::isEnemy($drowned));
		$ticks = $this->runUntil(fn() => $axolotl->getAttackTarget() === $drowned, 200);
		self::assertNotSame(-1, $ticks, "targets the drowned");
		$axolotl->flagForDespawn();
		$fish->flagForDespawn();
		$drowned->flagForDespawn();
	}

	public function testAxolotlPlaysDeadBelowEightHealth() : void{
		$this->pool(-3, -3, 3, 3, 3);
		for($i = 0; $i < 10; ++$i){
			$axolotl = new Axolotl($this->loc(0.5, 2, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
			$axolotl->spawnToAll();
			$this->runTicks(1);
			$axolotl->setHealth(10);
			$axolotl->attack(new EntityDamageEvent($axolotl, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3));
			self::assertTrue($axolotl->isPlayingDead(), "always below 8 health");
			self::assertTrue($axolotl->getEffects()->has(VanillaEffects::REGENERATION()));
			$axolotl->flagForDespawn();

			//not for every kind of damage
			$other = new Axolotl($this->loc(1.5, 2, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
			$other->spawnToAll();
			$this->runTicks(1);
			$other->setHealth(10);
			$other->attack(new EntityDamageEvent($other, EntityDamageEvent::CAUSE_FALL, 3));
			self::assertFalse($other->isPlayingDead(), "fall damage");
			$other->flagForDespawn();
		}
		//not on land
		$dry = new Axolotl($this->loc(10.5, 1, 10.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$dry->spawnToAll();
		$this->runTicks(1);
		$dry->setHealth(10);
		$dry->attack(new EntityDamageEvent($dry, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 3));
		self::assertFalse($dry->isPlayingDead());
		$dry->flagForDespawn();
	}

	public function testAxolotlPlayDeadDependsOnTheDamage() : void{
		$this->pool(-3, -3, 3, 3, 3);
		$played = 0;
		for($i = 0; $i < 30; ++$i){
			//more than 2 damage at full health: always
			$axolotl = new Axolotl($this->loc(0.5, 2, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
			$axolotl->spawnToAll();
			$this->runTicks(1);
			$axolotl->attack(new EntityDamageEvent($axolotl, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 5));
			self::assertTrue($axolotl->isPlayingDead(), "always for 5 damage");
			$axolotl->flagForDespawn();

			//within 0-2 damage: a 33% chance
			$other = new Axolotl($this->loc(1.5, 2, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
			$other->spawnToAll();
			$this->runTicks(1);
			$other->attack(new EntityDamageEvent($other, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
			$played += $other->isPlayingDead() ? 1 : 0;
			$other->flagForDespawn();
		}
		self::assertGreaterThan(0, $played);
		self::assertLessThan(30, $played);
	}

	public function testTurtleStaysAWhileAfterReachingTheWater() : void{
		$this->pool(3, -4, 8, 4);
		$turtle = new Turtle($this->loc(0.5, 1, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$turtle->spawnToAll();
		$ticks = $this->runUntil(fn() => $turtle->isInWater(), 800);
		self::assertNotSame(-1, $ticks, "in water, at " . $turtle->getPosition());
		//move_to_land waits a full search interval (10 s at least) before heading ashore again
		$this->runTicks(150);
		self::assertTrue($turtle->isInWater(), "still swimming, at " . $turtle->getPosition());
		$turtle->flagForDespawn();
	}

	public function testWhoeverKillsTheAxolotlTargetIsHealed() : void{
		$this->pool(-4, -4, 4, 4, 3);
		$axolotl = new Axolotl($this->loc(0.5, 2, 0.5), CompoundTag::create()->setInt(Animal::TAG_AGE, 0));
		$axolotl->spawnToAll();
		$cod = new Cod($this->loc(1.5, 2, 0.5));
		$cod->spawnToAll();
		//(not an undead killer: they are immune to Regeneration)
		$killer = new Cow($this->loc(8.5, 1, 8.5));
		$killer->spawnToAll();
		$killer->getEffects()->add(new EffectInstance(VanillaEffects::MINING_FATIGUE(), 600));
		$axolotl->setAttackTarget($cod);
		$cod->attack(new EntityDamageByEntityEvent($killer, $cod, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 100));
		self::assertFalse($cod->isAlive());
		$axolotl->setAttackTarget(null);
		self::assertTrue($killer->getEffects()->has(VanillaEffects::REGENERATION()));
		self::assertFalse($killer->getEffects()->has(VanillaEffects::MINING_FATIGUE()));
		$axolotl->flagForDespawn();
		$killer->flagForDespawn();
	}

	public function testFrogVariantsFollowTheBiome() : void{
		$variant = fn(int $biome) => Frog::variantForBiomeTags(MobBiomeInfo::getTagsById($biome));
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::JUNGLE));
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::BAMBOO_JUNGLE));
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::HELL), "nether");
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::SAVANNA));
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::MESA));
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::LUKEWARM_OCEAN));
		self::assertSame(Frog::VARIANT_WARM, $variant(BiomeIds::MANGROVE_SWAMP));
		self::assertSame(Frog::VARIANT_COLD, $variant(BiomeIds::COLD_TAIGA), "snowy taiga");
		self::assertSame(Frog::VARIANT_COLD, $variant(BiomeIds::ICE_PLAINS));
		self::assertSame(Frog::VARIANT_COLD, $variant(BiomeIds::FROZEN_PEAKS));
		self::assertSame(Frog::VARIANT_TEMPERATE, $variant(BiomeIds::PLAINS));
		self::assertSame(Frog::VARIANT_TEMPERATE, $variant(BiomeIds::MEADOW));
		self::assertSame(Frog::VARIANT_TEMPERATE, $variant(BiomeIds::CHERRY_GROVE));
		//frog.json excludes the stony_peaks tag, but the stony peaks biome doesn't have it (only mountains): cold
		self::assertSame(Frog::VARIANT_COLD, $variant(BiomeIds::STONY_PEAKS));
	}

	public function testFrogJumpsOntoALilyPad() : void{
		$this->world->setBlockAt(4, -1, 0, VanillaBlocks::STONE(), false);
		$this->world->setBlockAt(4, 0, 0, VanillaBlocks::WATER(), false);
		$this->world->setBlockAt(4, 1, 0, VanillaBlocks::LILY_PAD(), false);
		PathBlockTypes::clearCache();
		$frog = new Frog($this->loc(0.5, 1, 0.5));
		$frog->spawnToAll();
		$this->runTicks(5);
		self::assertTrue($frog->isOnGround());
		$jumped = false;
		//(one jump: the next one 30 seconds later)
		$goal = new LongJumpGoal($frog, 600, 600, function(bool $jumping) use (&$jumped) : void{
			$jumped = $jumped || $jumping;
		}, 8, -4, 4, 1.0, 2, fn(Block $block) => $block instanceof WaterLily, 1.0);
		$goal->resetCooldown();
		self::assertTrue($goal->canStart(), "a jump onto the lily pad");
		$goal->resetCooldown();
		//in the frog's own goals, above everything else
		$frog->getGoalSelector()->addGoal(0, $goal);
		$landed = $this->runUntil(function() use (&$jumped, $goal) : bool{
			return $jumped && !$goal->isJumping();
		}, 120);
		self::assertNotSame(-1, $landed);
		$pos = $frog->getPosition();
		self::assertEqualsWithDelta(4.5, $pos->x, 0.6, "on the lily pad: " . $pos);
		self::assertEqualsWithDelta(0.5, $pos->z, 0.6, "on the lily pad: " . $pos);
		self::assertEqualsWithDelta(1.0, $pos->y, 0.2, "on the lily pad: " . $pos);
		$frog->flagForDespawn();
	}

	public function testFrogNeverJumpsIntoWater() : void{
		//only water around, no preferred block: no jump
		$this->pool(-9, -9, 9, 9);
		$this->world->setBlockAt(0, 1, 0, VanillaBlocks::AIR(), false);
		PathBlockTypes::clearCache();
		$frog = new Frog($this->loc(0.5, 1, 0.5));
		$frog->spawnToAll();
		$this->runTicks(5);
		$goal = new LongJumpGoal($frog, 0, 0, null, 8, -4, 4, 1.0, 2, fn(Block $block) => $block instanceof WaterLily, 0.5);
		for($i = 0; $i < 20; ++$i){
			$goal->resetCooldown();
			self::assertFalse($goal->canStart());
		}
		$frog->flagForDespawn();
	}

	public function testFrogCroaks() : void{
		$frog = new Frog($this->loc(0.5, 1, 0.5));
		$frog->spawnToAll();
		$this->runTicks(5);
		$changes = [];
		$goal = new CroakGoal($frog, function(bool $c) use (&$changes) : void{
			$changes[] = $c;
		}, 200, 400, 90);
		$goal->resetInterval();
		self::assertTrue($goal->canStart());
		$goal->start();
		self::assertTrue($goal->canContinue());
		$goal->stop();
		self::assertSame([true, false], $changes);
		self::assertFalse($goal->canStart(), "10-20 seconds before the next croak");

		//the frog's own goal sets the CROAKING flag
		$ticks = $this->runUntil(fn() => $frog->isCroaking(), 1600);
		self::assertNotSame(-1, $ticks, "croaked");
		$this->runTicks(1);
		self::assertTrue(self::flag($frog, EntityMetadataFlags::CROAKING));
		$frog->flagForDespawn();
	}

	public function testPregnantFrogHeadsForWater() : void{
		$this->pool(14, -2, 16, 2);
		$frog = new Frog($this->loc(0.5, 1, 0.5));
		$frog->spawnToAll();
		$frog->setPregnant(true);
		$ticks = $this->runUntil(fn() => !$frog->isPregnant() || $frog->isInWater(), 1600);
		self::assertNotSame(-1, $ticks, "at " . $frog->getPosition());
		$frog->flagForDespawn();
	}
}
