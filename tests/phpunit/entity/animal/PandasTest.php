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
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Phantom;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function is_float;
use function mt_srand;

/**
 * Pandas (snacking, sitting, giveable, hitting back, anger broadcast, sneezes, lazy and worried pandas), polar bear cubs
 * calling the adults, and armadillo threats and roll-up conditions.
 */
final class PandasTest extends MobTestCase{
	private int $nextPlayerId = 7400000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
		$this->floor(-32, -8, 47, 8);
	}

	protected function tearDown() : void{
		foreach($this->players as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	private function player(Location $location, ?Item $held = null, bool $riding = false) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held ?? VanillaItems::AIR());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("isRiding")->willReturn($riding);
		$player->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$player->method("getLocation")->willReturn($location);
		$player->method("getPosition")->willReturn($location);
		$player->method("getEyePos")->willReturnCallback(fn() => $location->add(0, 1.62, 0));
		$player->method("getBoundingBox")->willReturnCallback(fn() => new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3));
		$player->method("getId")->willReturn(++$this->nextPlayerId);
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("getInventory")->willReturn($inventory);
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		return $player;
	}

	private function setPlayers(Player ...$players) : void{
		(new \ReflectionProperty(World::class, "players"))->setValue($this->world, $players);
	}

	/**
	 * A mob that doesn't move and can take a lot of damage.
	 *
	 * @phpstan-template T of Mob
	 * @phpstan-param T $mob
	 * @phpstan-return T
	 */
	private static function sturdy(Mob $mob) : Mob{
		$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_LOOK | Goal::FLAG_JUMP, true);
		$mob->setMaxHealth(100000);
		$mob->setHealth(100000);
		return $mob;
	}

	private function panda(float $x, int $gene, bool $baby = false) : Panda{
		$panda = new Panda($this->loc($x, 1, 0.5));
		$panda->setGenes($gene, $gene);
		if($baby){
			$panda->setAge(Animal::BABY_AGE);
		}
		return $panda;
	}

	/**
	 * Runs ticks until the condition holds (at most $maxTicks), and returns whether it does.
	 *
	 * @phpstan-param \Closure() : bool $condition
	 */
	private function runUntil(\Closure $condition, int $maxTicks) : bool{
		for($i = 0; $i < $maxTicks; ++$i){
			if($condition()){
				return true;
			}
			$this->runTicks(1);
		}
		return $condition();
	}

	private function hit(Living $attacker, Living $victim, float $damage = 1.0) : void{
		$victim->attack(new EntityDamageByEntityEvent($attacker, $victim, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $damage));
	}

	public function testPandaPicksUpBambooSitsAndEatsIt() : void{
		$panda = $this->panda(0.5, Panda::GENE_NORMAL);
		$item = $this->world->dropItem(new Vector3(3.5, 1, 0.5), VanillaBlocks::BAMBOO()->asItem()->setCount(3), new Vector3(0, 0, 0));
		self::assertNotNull($item);
		self::assertTrue($this->runUntil(fn() => $panda->hasSnack(), 400), "picks up the bamboo");
		self::assertSame(2, $item->getItem()->getCount(), "takes one");
		self::assertTrue($this->runUntil(fn() => $panda->isSitting(), 20), "sits down");
		self::assertTrue($this->runUntil(fn() => $panda->isEating(), 600), "eats");
		self::assertTrue($this->runUntil(fn() => !$panda->hasSnack(), 600), "eats it up");
		self::assertFalse($panda->isSitting(), "gets up");
		self::assertFalse(Panda::isSnackItem(VanillaItems::APPLE()));
		self::assertTrue(Panda::isSnackItem(VanillaBlocks::CAKE()->asItem()));
	}

	public function testCakeCalmsAnAngryPanda() : void{
		$panda = $this->panda(0.5, Panda::GENE_AGGRESSIVE);
		$cow = self::sturdy(new Cow($this->loc(6.5, 1, 0.5)));
		$panda->setAttackTarget($cow);
		self::assertSame($cow, $panda->getAttackTarget());
		$player = $this->player($this->loc(2.5, 1, 0.5), VanillaBlocks::CAKE()->asItem()->setCount(2));
		self::assertTrue($panda->onInteract($player, new Vector3(0, 0, 0)));
		self::assertNull($panda->getAttackTarget(), "on_give: on_calm");
		self::assertTrue($panda->hasSnack());
		self::assertSame(1, $player->getInventory()->getItemInHand()->getCount());
		self::assertFalse($panda->onInteract($player, new Vector3(0, 0, 0)), "3 s cooldown");
		self::assertFalse($panda->onInteract($this->player($this->loc(2.5, 1, 0.5), VanillaItems::APPLE()), new Vector3(0, 0, 0)));
		self::assertTrue($this->runUntil(fn() => $panda->isSitting(), 20), "sits down to eat it");
	}

	private function countCakeItems() : int{
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn() && $entity->getItem()->equals(VanillaBlocks::CAKE()->asItem(), false, false)){
				$count += $entity->getItem()->getCount();
			}
		}
		return $count;
	}

	public function testSnackIsNotDuplicatedOnDeathOrUnload() : void{
		$giver = $this->player($this->loc(2.5, 1, 0.5), VanillaBlocks::CAKE()->asItem()->setCount(4));

		$dying = $this->panda(0.5, Panda::GENE_NORMAL);
		self::assertTrue($dying->onInteract($giver, new Vector3(0, 0, 0)));
		$this->runTicks(2);
		$dying->kill();
		$this->runTicks(40);
		self::assertTrue($dying->isClosed());
		self::assertSame(0, $this->countCakeItems(), "the private inventory isn't dropped on death");

		$unloaded = $this->panda(4.5, Panda::GENE_NORMAL);
		self::assertTrue($unloaded->onInteract($giver, new Vector3(0, 0, 0)));
		$this->runTicks(2);
		$nbt = $unloaded->saveNBT();
		$unloaded->close();
		self::assertSame(0, $this->countCakeItems(), "closing keeps the snack in the saved data");
		self::assertNotNull($nbt->getListTag("Mainhand"), "snack saved");
	}

	public function testScaredPandaSitsAgainWhenHit() : void{
		$worried = $this->panda(0.5, Panda::GENE_WORRIED);
		$this->world->getWeather()->setThunder(2000);
		self::assertTrue($this->runUntil(fn() => $worried->isScared() && $worried->isSitting(), 20));
		$this->hit(self::sturdy(new Cow($this->loc(3.5, 1, 0.5))), $worried);
		$this->runTicks(2);
		self::assertTrue($worried->isScared());
		self::assertTrue($worried->isSitting(), "still sits during the storm");
		$this->world->getWeather()->setClear(2000);
	}

	public function testNormalPandaHitsBackOnce() : void{
		$panda = $this->panda(0.5, Panda::GENE_NORMAL);
		$cow = self::sturdy(new Cow($this->loc(2.5, 1, 0.5)));
		$this->runTicks(2);
		$this->hit($cow, $panda);
		$health = $cow->getHealth();
		self::assertTrue($this->runUntil(fn() => $cow->getHealth() < $health, 400), "hits back");
		self::assertEqualsWithDelta(2.0, $health - $cow->getHealth(), 0.001, "attack 2");
		self::assertNull($panda->getAttackTarget(), "attack_once");
		$health = $cow->getHealth();
		$this->runTicks(100);
		self::assertSame($health, $cow->getHealth(), "only once");

		$aggressive = $this->panda(0.5, Panda::GENE_AGGRESSIVE);
		$aggressive->teleport(new Vector3(0.5, 1, 4.5));
		$cow2 = self::sturdy(new Cow($this->loc(2.5, 1, 4.5)));
		$this->runTicks(2);
		$this->hit($cow2, $aggressive);
		$health = $cow2->getHealth();
		self::assertTrue($this->runUntil(fn() => $cow2->getHealth() < $health, 100), "aggressive pandas hit back right away");
		self::assertEqualsWithDelta(6.0, $health - $cow2->getHealth(), 0.001, "attack 6");
		self::assertSame($cow2, $aggressive->getAttackTarget(), "keeps fighting");
		$health = $cow2->getHealth();
		self::assertTrue($this->runUntil(fn() => $cow2->getHealth() < $health, 60), "hits again");
	}

	public function testHurtPandaCallsAggressivePandasWithin41Blocks() : void{
		$victim = self::sturdy($this->panda(-18.5, Panda::GENE_NORMAL));
		$near = self::sturdy($this->panda(21.5, Panda::GENE_AGGRESSIVE)); //40 blocks away
		$far = self::sturdy($this->panda(-18.5, Panda::GENE_AGGRESSIVE));
		$far->teleport(new Vector3(23.5, 1, 0.5)); //42 blocks away
		$normal = self::sturdy($this->panda(-10.5, Panda::GENE_NORMAL));
		$cow = self::sturdy(new Cow($this->loc(-16.5, 1, 0.5)));
		$this->runTicks(2);
		$this->hit($cow, $victim);
		$this->runTicks(10);
		self::assertSame($cow, $near->getAttackTarget(), "aggressive panda within 41 blocks");
		self::assertNull($far->getAttackTarget(), "farther than 41 blocks");
		self::assertNull($normal->getAttackTarget(), "only aggressive pandas are called");

		//a hurt baby calls them too, but doesn't fight itself
		$near->setAttackTarget(null);
		$cow->teleport(new Vector3(-18.5, 1, 4.5));
		$baby = self::sturdy($this->panda(-18.5, Panda::GENE_NORMAL, true));
		$this->runTicks(2);
		$this->hit($cow, $baby);
		$this->runTicks(10);
		self::assertNull($baby->getAttackTarget());
		self::assertSame($cow, $near->getAttackTarget());
	}

	public function testSneezes() : void{
		$drops = 0;
		for($i = 0; $i < 100000; ++$i){
			if(Panda::sneezeDropsSlimeball()){
				++$drops;
			}
		}
		self::assertGreaterThan(50, $drops, "drop_item_chance 0.001");
		self::assertLessThan(160, $drops, "drop_item_chance 0.001");

		$weak = self::sturdy($this->panda(0.5, Panda::GENE_WEAK, true));
		$normal = self::sturdy($this->panda(4.5, Panda::GENE_NORMAL, true));
		$sneezing = new \ReflectionProperty(Panda::class, "sneezing");
		$weakSneezes = $normalSneezes = 0;
		for($i = 0; $i < 5000; ++$i){
			$this->runTicks(1);
			$weakSneezes += $sneezing->getValue($weak) === true ? 1 : 0;
			$normalSneezes += $sneezing->getValue($normal) === true ? 1 : 0;
		}
		self::assertGreaterThanOrEqual(3, $weakSneezes, "weak babies: 1/500 per tick");
		self::assertLessThanOrEqual(25, $weakSneezes, "weak babies: 1/500 per tick");
		self::assertLessThanOrEqual(5, $normalSneezes, "other babies: 1/6000 per tick");
	}

	public function testLazyPandaSpeeds() : void{
		$lazy = $this->panda(0.5, Panda::GENE_LAZY);
		$normal = $this->panda(4.5, Panda::GENE_NORMAL);
		self::assertEqualsWithDelta(0.07, $lazy->getMovementSpeed(), 0.0001);
		self::assertEqualsWithDelta(0.15, $normal->getMovementSpeed(), 0.0001);
		$this->runTicks(2);
		$lazy->attack(new EntityDamageEvent($lazy, EntityDamageEvent::CAUSE_FALL, 1));
		$normal->attack(new EntityDamageEvent($normal, EntityDamageEvent::CAUSE_FALL, 1));
		$speed = new \ReflectionProperty(PanicGoal::class, "speedMultiplier");
		$panicSpeed = function(Panda $panda) use ($speed) : ?float{
			foreach($panda->getGoalSelector()->getRunningGoals() as $goal){
				if($goal instanceof PanicGoal){
					$value = $speed->getValue($goal);
					return is_float($value) ? $value : null;
				}
			}
			return null;
		};
		self::assertTrue($this->runUntil(fn() => $panicSpeed($lazy) !== null && $panicSpeed($normal) !== null, 20));
		self::assertSame(2.5, $panicSpeed($lazy));
		self::assertSame(1.25, $panicSpeed($normal));
	}

	public function testWorriedPandaHidesDuringThunderstorms() : void{
		$worried = $this->panda(0.5, Panda::GENE_WORRIED);
		$normal = $this->panda(6.5, Panda::GENE_NORMAL);
		$this->runTicks(5);
		self::assertFalse($worried->isScared());
		$this->world->getWeather()->setThunder(2000);
		self::assertTrue($this->runUntil(fn() => $worried->isScared(), 20), "scared during thunder");
		self::assertTrue($worried->isSitting(), "sits and covers its face");
		self::assertFalse($normal->isScared());
		$this->world->getWeather()->setClear(2000);
		self::assertTrue($this->runUntil(fn() => !$worried->isScared(), 20), "calm when the storm is over");
	}

	public function testPolarBearCubCallsTheAdults() : void{
		$cub = self::sturdy(new PolarBear($this->loc(-18.5, 1, 0.5)));
		$cub->setAge(Animal::BABY_AGE);
		$adult = self::sturdy(new PolarBear($this->loc(21.5, 1, 0.5))); //40 blocks from the cub
		$player = $this->player($this->loc(-4.5, 1, 0.5)); //14 blocks from the cub, 26 from the adult
		$this->setPlayers($player);
		self::assertTrue($this->runUntil(fn() => $adult->getAttackTarget() === $player, 100), "the cub calls the adults within 41 blocks");
		self::assertNull($cub->getAttackTarget(), "cubs don't attack");

		//the same player staying near: no new call for a while (performance), then again
		$adult->setAttackTarget(null);
		$this->runTicks(40);
		self::assertNull($adult->getAttackTarget(), "no repeated scan for the same target right away");
		self::assertTrue($this->runUntil(fn() => $adult->getAttackTarget() === $player, 100), "called again later");

		//hurt cubs call them too
		$adult->setAttackTarget(null);
		$this->setPlayers();
		$cow = self::sturdy(new Cow($this->loc(-16.5, 1, 0.5)));
		$this->runTicks(40);
		$this->hit($cow, $cub);
		self::assertTrue($this->runUntil(fn() => $adult->getAttackTarget() === $cow, 20));
	}

	public function testArmadilloThreats() : void{
		$player = $this->player($this->loc(0.5, 1, 0.5), riding: true);
		self::assertTrue(Armadillo::isScaryEntity($player), "riding player");
		self::assertFalse(Armadillo::isScaryEntity($this->player($this->loc(0.5, 1, 0.5))));
		self::assertTrue(Armadillo::isScaryEntity(new Phantom($this->loc(30.5, 10, 0.5))), "undead family");
		self::assertFalse(Armadillo::isScaryEntity(new Cow($this->loc(30.5, 1, 4.5))));

		$armadillo = new Armadillo($this->loc(0.5, 1, 0.5));
		$wet = new Armadillo($this->loc(10.5, 1, 0.5));
		$this->world->setBlockAt(10, 1, 0, VanillaBlocks::WATER());
		$this->world->setBlockAt(10, 2, 0, VanillaBlocks::WATER());
		self::sturdy(new Skeleton($this->loc(4.5, 1, 0.5)));
		self::sturdy(new Skeleton($this->loc(13.5, 1, 0.5)));
		self::assertTrue($this->runUntil(fn() => $armadillo->isRolledUp(), 30), "rolls up for a skeleton");
		self::assertFalse($wet->isRolledUp(), "not in water");
		self::assertTrue($this->runUntil(fn() => $armadillo->getState() === Armadillo::STATE_PEEKING, 500), "peeks out");

		$armadillo->setOnFire(5);
		$this->runTicks(1);
		self::assertSame(Armadillo::STATE_UNROLLED, $armadillo->getState(), "unrolls when burning");
	}

	public function testArmadilloRelaxesAndUnrollsWithoutThreats() : void{
		$armadillo = new Armadillo($this->loc(0.5, 1, 0.5));
		$this->runTicks(5);
		$armadillo->attack(new EntityDamageEvent($armadillo, EntityDamageEvent::CAUSE_FALL, 1));
		self::assertFalse($armadillo->isRolledUp(), "only damage from mobs and players is a threat");
		$this->runTicks(120); //the panic is over
		$cow = self::sturdy(new Cow($this->loc(3.5, 1, 0.5)));
		$this->hit($cow, $armadillo);
		self::assertSame(Armadillo::STATE_ROLLED_UP, $armadillo->getState());
		self::assertTrue($this->runUntil(fn() => $armadillo->getState() === Armadillo::STATE_RELAXING, 20), "a cow is no threat");
		self::assertTrue($this->runUntil(fn() => $armadillo->getState() === Armadillo::STATE_UNROLLING, 60));
		self::assertTrue($this->runUntil(fn() => $armadillo->getState() === Armadillo::STATE_UNROLLED, 40));
	}
}
