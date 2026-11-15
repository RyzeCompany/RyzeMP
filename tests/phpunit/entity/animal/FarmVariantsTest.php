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

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\BiomeDefinitionSupplement;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\GoalSelector;
use pocketmine\entity\ai\goal\MountPathingGoal;
use pocketmine\entity\ai\goal\PrioritizedGoal;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Egg;
use pocketmine\entity\property\EntityPropertyRegistry;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\entity\Zombie;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\types\biome\BiomeDefinitionEntry;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\generator\vanilla\BiomeMapping;
use pocketmine\world\mobs\spawn\BundledSpawnRules;
use pocketmine\world\mobs\spawn\SpawnRules;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;
use function array_fill_keys;
use function array_map;
use function count;
use function in_array;
use function mt_srand;

/**
 * Farm animal variants: climate and sound variants of cows, pigs and chickens, coloured eggs and egg hatching, sheep
 * colours by biome and colour mixing, sheared sheep and dyes, lambs eating grass, baby zombies riding farm animals,
 * rabbit coats, mooshroom flowers and the dappled forest biome.
 */
final class FarmVariantsTest extends MobTestCase{
	private int $nextPlayerId = 7300000;
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	protected function setUp() : void{
		parent::setUp();
		mt_srand(20261022);
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

	/**
	 * Sets the biome of the block column the mobs of the location stand in (y 1).
	 */
	private function biome(int $x, int $z, int $biomeId) : void{
		for($y = 0; $y < 4; ++$y){
			$this->world->setBiomeId($x, $y, $z, $biomeId);
		}
	}

	private function player(Item $held) : Player{
		$location = $this->loc(2.5, 1, 2.5);
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("hasFiniteResources")->willReturn(true);
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

	private function useOn(Mob $mob, Item $held) : bool{
		return $mob->onInteract($this->player($held), new Vector3(0, 0, 0));
	}

	/**
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	private function droppedItems() : array{
		$items = [];
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isClosed()){
				$items[] = $entity->getItem();
			}
		}
		return $items;
	}

	/**
	 * @phpstan-param class-string<Goal> $class
	 */
	private static function goalPriority(Mob $mob, string $class) : ?int{
		/** @var PrioritizedGoal[] $goals */
		$goals = (new \ReflectionProperty(GoalSelector::class, "goals"))->getValue($mob->getGoalSelector());
		foreach($goals as $entry){
			if($entry->goal instanceof $class){
				return $entry->priority;
			}
		}
		return null;
	}

	public function testClimateVariantFromBiome() : void{
		$this->biome(0, 0, BiomeIds::DESERT);
		$this->biome(4, 0, BiomeIds::ICE_PLAINS);
		$this->biome(8, 0, BiomeIds::PLAINS);
		$factories = [
			Cow::class => fn(Location $l) => new Cow($l),
			Pig::class => fn(Location $l) => new Pig($l),
			Chicken::class => fn(Location $l) => new Chicken($l)
		];
		foreach($factories as $class => $create){
			$warm = $create($this->loc(0.5, 1, 0.5));
			$cold = $create($this->loc(4.5, 1, 0.5));
			$temperate = $create($this->loc(8.5, 1, 0.5));
			self::assertSame(ClimateVariant::WARM, $warm->getClimateVariant(), "$class in a desert");
			self::assertSame(ClimateVariant::COLD, $cold->getClimateVariant(), "$class in snowy plains");
			self::assertSame(ClimateVariant::TEMPERATE, $temperate->getClimateVariant(), "$class in plains");

			//synced to the client in Bedrock definition order: climate_variant, then sound_variant
			$properties = $warm->getNetworkSyncedProperties()->getIntProperties();
			self::assertSame(1, $properties[0], "$class climate_variant warm");
			self::assertSame($warm->getSoundVariant(), $properties[1]);
		}
		$registry = EntityPropertyRegistry::getInstance();
		self::assertSame(0, $registry->getIndex(EntityIds::COW, "minecraft:climate_variant"));
		self::assertSame(1, $registry->getIndex(EntityIds::PIG, "minecraft:sound_variant"));
		self::assertSame(1, $registry->encodeValue(EntityIds::COW, 1, "moody"));
		self::assertSame(2, $registry->encodeValue(EntityIds::PIG, 1, "mini"));
		self::assertSame(1, $registry->encodeValue(EntityIds::CHICKEN, 1, "picky"));
	}

	public function testVariantsAreSavedAndInherited() : void{
		$this->biome(0, 0, BiomeIds::DESERT);
		$cow = new Cow($this->loc(0.5, 1, 0.5));
		$cow->setSoundVariant(1);
		$nbt = $cow->saveNBT();
		self::assertSame("warm", $nbt->getString(Cow::TAG_CLIMATE_VARIANT));

		//loaded in another biome: keeps its variants
		$loaded = new Cow($this->loc(8.5, 1, 8.5), $nbt);
		self::assertSame(ClimateVariant::WARM, $loaded->getClimateVariant());
		self::assertSame(1, $loaded->getSoundVariant());

		//calves bred in plains take the climate of a parent
		$other = new Cow($this->loc(9.5, 1, 8.5));
		$other->setClimateVariant(ClimateVariant::WARM);
		for($i = 0; $i < 4; ++$i){
			$loaded->setAge(0);
			$baby = $loaded->breed($other);
			self::assertInstanceOf(Cow::class, $baby);
			self::assertSame(ClimateVariant::WARM, $baby->getClimateVariant());
		}

		//a spawned baby keeps the default sound (no randomize_sound_variant)
		$pig = new Pig($this->loc(8.5, 1, 8.5));
		$pig->setSoundVariant(2);
		self::assertTrue($pig->makeSpawnBaby());
		self::assertSame(0, $pig->getSoundVariant());
	}

	public function testMooshroomHasNoClimateAndShearsIntoADefaultCow() : void{
		$this->biome(0, 0, BiomeIds::DESERT);
		$mooshroom = new Mooshroom($this->loc(0.5, 1, 0.5));
		self::assertSame(ClimateVariant::TEMPERATE, $mooshroom->getClimateVariant());
		self::assertSame([], $mooshroom->getNetworkSyncedProperties()->getIntProperties());
		self::assertFalse($mooshroom->saveNBT()->getTag(Cow::TAG_CLIMATE_VARIANT) !== null);
		$cow = $mooshroom->shear();
		self::assertNotNull($cow);
		self::assertSame(ClimateVariant::TEMPERATE, $cow->getClimateVariant(), "entity_transformed: default climate");
		self::assertSame(0, $cow->getSoundVariant());
	}

	public function testChickensLayEggsOfTheirClimateButNotWhileRidden() : void{
		$this->biome(0, 0, BiomeIds::DESERT);
		$chicken = new Chicken($this->loc(0.5, 1, 0.5));
		$chicken->spawnToAll();
		$chicken->setEggLayTime(1);
		$this->runTicks(2);
		$eggs = $this->droppedItems();
		self::assertCount(1, $eggs);
		self::assertSame(ItemTypeIds::BROWN_EGG, $eggs[0]->getTypeId());

		$cold = new Chicken($this->loc(3.5, 1, 3.5));
		$cold->setClimateVariant(ClimateVariant::COLD);
		self::assertSame(ItemTypeIds::BLUE_EGG, $cold->getClimateVariant()->getEggItem()->getTypeId());
		self::assertSame(ItemTypeIds::EGG, ClimateVariant::TEMPERATE->getEggItem()->getTypeId());

		//chicken jockey: no eggs
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity){
				$entity->close();
			}
		}
		$zombie = new Zombie($this->loc(0.5, 1, 0.5));
		$zombie->setBaby(true);
		self::assertTrue($zombie->mount($chicken));
		$chicken->setEggLayTime(1);
		$this->runTicks(2);
		self::assertSame([], $this->droppedItems());
		self::assertGreaterThan(0, $chicken->getEggLayTime(), "the timer restarts");
	}

	public function testEggHatching() : void{
		$hatches = 0;
		$fours = 0;
		$total = 64000;
		for($i = 0; $i < $total; ++$i){
			$count = Egg::rollHatchCount();
			if($count > 0){
				++$hatches;
				if($count === 4){
					++$fours;
				}else{
					self::assertSame(1, $count);
				}
			}
		}
		self::assertEqualsWithDelta($total / 8, $hatches, $total / 8 * 0.1);
		self::assertEqualsWithDelta($hatches / 32, $fours, $hatches / 32 * 0.4);

		$this->biome(0, 0, BiomeIds::ICE_PLAINS);
		$egg = new Egg($this->loc(0.5, 1, 0.5), null);
		$egg->setEggItem(VanillaItems::BROWN_EGG());
		self::assertSame([0 => 1], $egg->getNetworkSyncedProperties()->getIntProperties(), "a brown egg shows as warm");
		$chicks = (new \ReflectionMethod(Egg::class, "hatch"))->invoke($egg, 4);
		self::assertIsArray($chicks);
		self::assertCount(4, $chicks);
		foreach($chicks as $chick){
			self::assertInstanceOf(Chicken::class, $chick);
			self::assertTrue($chick->isBaby());
			self::assertSame(ClimateVariant::WARM, $chick->getClimateVariant(), "brown eggs hatch warm chicks, whatever the biome");
		}
		$plain = new Egg($this->loc(0.5, 1, 0.5), null);
		$plain->setEggItem(VanillaItems::EGG());
		$hatched = (new \ReflectionMethod(Egg::class, "hatch"))->invoke($plain, 1);
		self::assertIsArray($hatched);
		$chick = $hatched[0] ?? null;
		self::assertInstanceOf(Chicken::class, $chick);
		self::assertSame(ClimateVariant::TEMPERATE, $chick->getClimateVariant(), "a plain egg hatches a temperate chick in snowy plains");
		$egg->close();
		$plain->close();
	}

	public function testSheepColoursByBiome() : void{
		$this->biome(0, 0, BiomeIds::ICE_PLAINS);
		$this->biome(4, 0, BiomeIds::DESERT);
		$black = 0;
		$brown = 0;
		for($i = 0; $i < 300; ++$i){
			$cold = new Sheep($this->loc(0.5, 1, 0.5));
			$warm = new Sheep($this->loc(4.5, 1, 0.5));
			$black += $cold->getColor() === DyeColor::BLACK ? 1 : 0;
			$brown += $warm->getColor() === DyeColor::BROWN ? 1 : 0;
			$cold->close();
			$warm->close();
		}
		self::assertGreaterThan(220, $black, "cold_color: 81.8% black");
		self::assertGreaterThan(220, $brown, "warm_color: 81.8% brown");
	}

	public function testLambColourMixesItsParentsDyes() : void{
		self::assertSame(DyeColor::PINK, Sheep::mixParentColors(DyeColor::RED, DyeColor::WHITE));
		self::assertSame(DyeColor::PINK, Sheep::mixParentColors(DyeColor::WHITE, DyeColor::RED));
		self::assertSame(DyeColor::GRAY, Sheep::mixParentColors(DyeColor::BLACK, DyeColor::WHITE));
		self::assertSame(DyeColor::LIGHT_GRAY, Sheep::mixParentColors(DyeColor::WHITE, DyeColor::GRAY));
		self::assertSame(DyeColor::CYAN, Sheep::mixParentColors(DyeColor::GREEN, DyeColor::BLUE));
		self::assertSame(DyeColor::PURPLE, Sheep::mixParentColors(DyeColor::RED, DyeColor::BLUE));
		self::assertSame(DyeColor::ORANGE, Sheep::mixParentColors(DyeColor::YELLOW, DyeColor::RED));
		self::assertSame(DyeColor::MAGENTA, Sheep::mixParentColors(DyeColor::PINK, DyeColor::PURPLE));
		self::assertSame(DyeColor::LIME, Sheep::mixParentColors(DyeColor::GREEN, DyeColor::WHITE));
		self::assertSame(DyeColor::LIGHT_BLUE, Sheep::mixParentColors(DyeColor::BLUE, DyeColor::WHITE));
		//no recipe: one of the parents
		for($i = 0; $i < 20; ++$i){
			self::assertContains(Sheep::mixParentColors(DyeColor::BLACK, DyeColor::BROWN), [DyeColor::BLACK, DyeColor::BROWN]);
		}

		$red = new Sheep($this->loc(0.5, 1, 0.5));
		$red->setColor(DyeColor::RED);
		$white = new Sheep($this->loc(1.5, 1, 0.5));
		$white->setColor(DyeColor::WHITE);
		$lamb = $red->breed($white);
		self::assertInstanceOf(Sheep::class, $lamb);
		self::assertSame(DyeColor::PINK, $lamb->getColor());
	}

	public function testShearedSheepCantBeDyedUntilItEatsGrass() : void{
		$sheep = new Sheep($this->loc(0.5, 1, 0.5));
		$sheep->setColor(DyeColor::WHITE);
		self::assertTrue($sheep->shear());
		self::assertFalse($this->useOn($sheep, VanillaItems::DYE()->setColor(DyeColor::RED)), "sheared: not dyeable");
		self::assertSame(DyeColor::WHITE, $sheep->getColor());
		$sheep->onEatGrass();
		self::assertTrue($this->useOn($sheep, VanillaItems::DYE()->setColor(DyeColor::RED)));
		self::assertSame(DyeColor::RED, $sheep->getColor());
	}

	public function testLambEatingGrassDoesntAge() : void{
		$lamb = new Sheep($this->loc(0.5, 1, 0.5));
		$lamb->setAge(Animal::BABY_AGE);
		$lamb->onEatGrass();
		self::assertSame(Animal::BABY_AGE, $lamb->getAge());
	}

	public function testBabyZombiesRideFarmAnimals() : void{
		$cases = [
			[new Cow($this->loc(0.5, 1, 0.5)), 1.15, 2],
			[new Mooshroom($this->loc(4.5, 1, 0.5)), 1.15, 2],
			[new Sheep($this->loc(8.5, 1, 0.5)), 0.975, 2],
			[new Panda($this->loc(12.5, 1, 0.5)), 1.025, 5],
			[new Pig($this->loc(16.5, 1, 0.5)), 0.7, 1]
		];
		foreach($cases as [$mount, $seatY, $priority]){
			$name = $mount::class;
			$zombie = new Zombie($mount->getLocation());
			$zombie->setBaby(true);
			$adult = new Zombie($mount->getLocation());
			$adult->setBaby(false);
			self::assertFalse($mount->canAddPassenger($adult), "$name: adult zombies don't ride");
			self::assertTrue($mount->canAddPassenger($zombie), "$name: baby_undead");
			self::assertEqualsWithDelta($seatY, $mount->getRideableSeats()[0]->position->y, 0.0001, $name);
			self::assertSame($priority, self::goalPriority($mount, MountPathingGoal::class), "$name mount_pathing priority");
			self::assertTrue($zombie->mount($mount));
			self::assertSame($mount, $zombie->getVehicle());
			$adult->close();
		}

		$sheep = new Sheep($this->loc(0.5, 1, 4.5));
		self::assertTrue($sheep->shear());
		self::assertEqualsWithDelta(0.9, $sheep->getRideableSeats()[0]->position->y, 0.0001);
		$lamb = new Sheep($this->loc(2.5, 1, 4.5));
		$lamb->setAge(Animal::BABY_AGE);
		self::assertEqualsWithDelta(-0.3, $lamb->getRideableSeats()[0]->position->z, 0.0001);
		$calf = new Cow($this->loc(4.5, 1, 4.5));
		$calf->setAge(Animal::BABY_AGE);
		self::assertEqualsWithDelta(1.0, $calf->getRideableSeats()[0]->position->y, 0.0001);
	}

	public function testRabbitCoats() : void{
		$this->biome(0, 0, BiomeIds::PLAINS);
		$counts = array_fill_keys([Rabbit::VARIANT_BROWN, Rabbit::VARIANT_BLACK, Rabbit::VARIANT_SALT], 0);
		$total = 4000;
		for($i = 0; $i < $total; ++$i){
			++$counts[Rabbit::variantForBiome($this->world, new Vector3(0.5, 1, 0.5))];
		}
		self::assertCount(3, $counts);
		self::assertEqualsWithDelta(0.5 * $total, $counts[Rabbit::VARIANT_BROWN], 0.05 * $total);
		self::assertEqualsWithDelta(0.4 * $total, $counts[Rabbit::VARIANT_BLACK], 0.05 * $total);
		self::assertEqualsWithDelta(0.1 * $total, $counts[Rabbit::VARIANT_SALT], 0.03 * $total);

		//offspring mutation_factor 0.2: two white parents in plains have plains kits 20% of the time
		$a = new Rabbit($this->loc(0.5, 1, 0.5));
		$b = new Rabbit($this->loc(1.5, 1, 0.5));
		$a->setVariant(Rabbit::VARIANT_WHITE);
		$b->setVariant(Rabbit::VARIANT_WHITE);
		$mutated = 0;
		for($i = 0; $i < 500; ++$i){
			$a->setAge(0);
			$baby = $a->breed($b);
			self::assertInstanceOf(Rabbit::class, $baby);
			$mutated += $baby->getVariant() !== Rabbit::VARIANT_WHITE ? 1 : 0;
			$baby->close();
		}
		self::assertEqualsWithDelta(100, $mutated, 35);
	}

	public function testMooshroomFlowers() : void{
		$mooshroom = new Mooshroom($this->loc(0.5, 1, 0.5));
		$mooshroom->setVariant(Mooshroom::VARIANT_BROWN);
		self::assertTrue($this->useOn($mooshroom, VanillaBlocks::POPPY()->asItem()));
		self::assertFalse($this->useOn($mooshroom, VanillaBlocks::POPPY()->asItem()), "the same flower again");
		self::assertTrue($this->useOn($mooshroom, VanillaBlocks::CORNFLOWER()->asItem()), "another flower replaces it");
		self::assertSame(1, $mooshroom->saveNBT()->getInt(Mooshroom::TAG_STEW_FLOWER), "cornflower mark");

		$baby = new Mooshroom($this->loc(3.5, 1, 0.5));
		$baby->setVariant(Mooshroom::VARIANT_BROWN);
		$baby->setAge(Animal::BABY_AGE);
		self::assertFalse($this->useOn($baby, VanillaBlocks::POPPY()->asItem()), "babies don't take flowers");
		self::assertSame(-1, $baby->saveNBT()->getInt(Mooshroom::TAG_STEW_FLOWER));
	}

	public function testDappledForestBiome() : void{
		$id = BiomeDefinitionSupplement::DAPPLED_FOREST;
		self::assertSame($id, BiomeMapping::toBedrock("dappled_forest"));
		self::assertSame("dappled_forest", LegacyBiomeIdToStringIdMap::getInstance()->legacyToString($id));
		$tags = MobBiomeInfo::getTagsById($id);
		self::assertContains("dappled_forest", $tags);
		self::assertSame(ClimateVariant::COLD, ClimateVariant::fromBiomeTags($tags), "farm animals are cold");

		$rules = BundledSpawnRules::loadFile(Path::join(\pocketmine\RESOURCE_PATH, BundledSpawnRules::FILE));
		self::assertInstanceOf(SpawnRules::class, $rules);
		$identifiers = fn(array $biomeTags) : array => array_map(fn($c) => $c->identifier, $rules->forBiomeTags(array_fill_keys($biomeTags, true), false));
		$dappled = $identifiers($tags);
		$forest = $identifiers(MobBiomeInfo::getTagsById(BiomeIds::FOREST));
		self::assertContains("minecraft:wolf", $forest);
		self::assertNotContains("minecraft:wolf", $dappled, "wolf.json excludes the dappled forest");
		self::assertContains("minecraft:fox", $dappled);
		self::assertContains("minecraft:rabbit", $dappled);

		//sent to clients with the other biome definitions
		$names = [];
		$entries = (new \ReflectionMethod(StaticPacketCache::class, "loadBiomeDefinitionModel"))->invoke(null, BedrockDataFiles::BIOME_DEFINITIONS_JSON);
		self::assertIsArray($entries);
		foreach($entries as $entry){
			self::assertInstanceOf(BiomeDefinitionEntry::class, $entry);
			$names[] = $entry->getBiomeName();
		}
		self::assertTrue(in_array("dappled_forest", $names, true) || in_array("minecraft:dappled_forest", $names, true));
		self::assertGreaterThan(80, count($names));
	}

	public function testSpawnedChickNbtRoundTrip() : void{
		$chicken = new Chicken($this->loc(0.5, 1, 0.5), CompoundTag::create()->setString(Chicken::TAG_CLIMATE_VARIANT, "cold")->setInt(Chicken::TAG_SOUND_VARIANT, 1));
		self::assertSame(ClimateVariant::COLD, $chicken->getClimateVariant());
		self::assertSame(1, $chicken->getSoundVariant());
	}

	public function testSavedAnimalsWithoutVariantsKeepTheDefaults() : void{
		$this->biome(0, 0, BiomeIds::DESERT);
		//a cow saved before variants existed: entity_spawned doesn't run again when it loads
		$old = (new Cow($this->loc(0.5, 1, 0.5)))->saveNBT();
		$old->removeTag(Cow::TAG_CLIMATE_VARIANT, Cow::TAG_SOUND_VARIANT);
		for($i = 0; $i < 8; ++$i){
			$cow = new Cow($this->loc(0.5, 1, 0.5), $old);
			self::assertSame(ClimateVariant::TEMPERATE, $cow->getClimateVariant());
			self::assertSame(0, $cow->getSoundVariant());
			$cow->close();
		}
		//a new one still takes the biome climate
		self::assertSame(ClimateVariant::WARM, (new Cow($this->loc(0.5, 1, 0.5), new CompoundTag()))->getClimateVariant());
	}

	public function testEggClimateIsSaved() : void{
		$egg = new Egg($this->loc(0.5, 1, 0.5), null);
		$egg->setEggItem(VanillaItems::BLUE_EGG());
		$loaded = new Egg($this->loc(0.5, 1, 0.5), null, $egg->saveNBT());
		self::assertSame(ClimateVariant::COLD, $loaded->getClimateVariant());
		self::assertSame(ItemTypeIds::BLUE_EGG, $loaded->getEggItem()->getTypeId());
		$egg->close();
		$loaded->close();
	}

	public function testRabbitKitsTakeTheCoatOfADesertOrSnowyBiome() : void{
		$this->biome(0, 0, BiomeIds::DESERT);
		$this->biome(8, 0, BiomeIds::ICE_PLAINS);
		foreach([[0.5, [Rabbit::VARIANT_DESERT]], [8.5, [Rabbit::VARIANT_WHITE, Rabbit::VARIANT_SPLOTCHED]]] as [$x, $coats]){
			$a = new Rabbit($this->loc($x, 1, 0.5));
			$b = new Rabbit($this->loc($x, 1, 0.5));
			$a->setVariant(Rabbit::VARIANT_BLACK);
			$b->setVariant(Rabbit::VARIANT_BLACK);
			for($i = 0; $i < 20; ++$i){
				$a->setAge(0);
				$baby = $a->breed($b);
				self::assertInstanceOf(Rabbit::class, $baby);
				self::assertContains($baby->getVariant(), $coats);
				$baby->close();
			}
			$a->close();
			$b->close();
		}
	}
}
