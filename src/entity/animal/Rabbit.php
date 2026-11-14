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

use pocketmine\block\Block;
use pocketmine\block\Carrot;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RaidGardenGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\utils\MobBiomeInfo;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\World;
use function in_array;
use function mt_rand;

/**
 * Bedrock values (entities/rabbit.json): 3 health, 0.82x1 at scale 0.6 (babies 0.4), movement 0.3, hops when it
 * moves. Panics at 2.2x; runs from players (8 blocks), wolves and monsters (4 blocks); tempted and bred with carrots,
 * golden carrots and dandelions; raids carrot crops within 16 blocks (raid_garden, one growth stage per bite, needs
 * mobGriefing). Coats (VARIANT): 0 brown, 1 white, 2 black, 3 splotched, 4 desert, 5 salt and
 * pepper; by biome tags spawns_gold_rabbits / spawns_white_rabbits (white 80, splotched 20), else brown 50, black 40,
 * salt and pepper 10. Babies take a parent's coat, or one of the biome 20% of the time (mutation_factor 0.2).
 * Loot (loot_tables/entities/rabbit.json): 0-1 rabbit hide, 0-1 raw rabbit (cooked when burning), each +0-1 per
 * Looting level; rabbit's foot 10% (+3% per Looting level) on player kills.
 */
class Rabbit extends Animal{
	public const TAG_VARIANT = "Variant"; //TAG_Int

	public const VARIANT_BROWN = 0;
	public const VARIANT_WHITE = 1;
	public const VARIANT_BLACK = 2;
	public const VARIANT_SPLOTCHED = 3;
	public const VARIANT_DESERT = 4;
	public const VARIANT_SALT = 5;

	private int $variant = self::VARIANT_BROWN;

	/**
	 * @var bool[] state ID => whether it is a carrot crop
	 * @phpstan-var array<int, bool>
	 */
	private static array $carrotStates = [];

	public static function getNetworkTypeId() : string{ return EntityIds::RABBIT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.0, 0.82, 0.8);
	}

	public function getName() : string{
		return "Rabbit";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(1.0, 0.6, 0.8); //baby collision box 0.6 x 1 at scale 0.4
	}

	public function getSpawnBabyChance() : int{
		return 25; //entity_spawned: 3 adult / 1 baby
	}

	protected function canStandOnPowderSnow() : bool{
		return true; //can_stand_on_powder_snow
	}

	protected function getAdultScale() : float{
		return 0.6;
	}

	protected function getBabyScale() : float{
		return 0.4;
	}

	public static function variantForBiome(World $world, Vector3 $pos) : int{
		$biomeCoat = self::biomeCoat($world, $pos);
		if($biomeCoat !== null){
			return $biomeCoat;
		}
		$r = mt_rand(0, 99);
		return $r < 50 ? self::VARIANT_BROWN : ($r < 90 ? self::VARIANT_BLACK : self::VARIANT_SALT);
	}

	/**
	 * Coat forced by the biome (the unfiltered is_biome desert / is_snow_covered steps of entity_spawned and
	 * entity_born): desert, or white 80 / splotched 20 where snow falls; null elsewhere.
	 */
	private static function biomeCoat(World $world, Vector3 $pos) : ?int{
		$tags = MobBiomeInfo::getTags($world, $pos);
		if(in_array("spawns_gold_rabbits", $tags, true)){
			return self::VARIANT_DESERT;
		}
		if(in_array("spawns_white_rabbits", $tags, true)){
			return mt_rand(0, 4) === 0 ? self::VARIANT_SPLOTCHED : self::VARIANT_WHITE;
		}
		return null;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(3);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->variant = $nbt->getTag(self::TAG_VARIANT) !== null ? $nbt->getInt(self::TAG_VARIANT) % 6 : self::variantForBiome($this->getWorld(), $this->location);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->variant);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 2.2));
		$this->goalSelector->addGoal(2, new BreedGoal($this, 0.75));
		$this->goalSelector->addGoal(3, new TemptGoal($this, 1.0, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(4, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Player, 8.0, 1.5, 1.8));
		//no search for wolves and monsters in worlds without any
		$this->goalSelector->addGoal(4, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Wolf || $e instanceof Monster, 4.0, 1.5, 1.8, function() : bool{
			$world = $this->getWorld();
			return $world->hasEntitiesOfClass(Wolf::class) || $world->hasEntitiesOfClass(Monster::class);
		}));
		//raid_garden: carrots, search_range 16, goal_radius 1, speed 0.6 (search_height 1, max_to_eat 6, eat_delay 2 s and
		//full_delay 100 s by default)
		$this->goalSelector->addGoal(5, new RaidGardenGoal($this, self::isCarrotState(...), $this->eatCarrot(...), 0.6, 16, 1, 1.0));
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(11, new LookAtPlayerGoal($this, 6.0));
	}

	private static function isCarrotState(int $stateId) : bool{
		return self::$carrotStates[$stateId] ??= RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId) instanceof Carrot;
	}

	/**
	 * Takes a bite of a carrot crop (Java RabbitRaidCarrotGoal): one growth stage less, or the crop is gone when it had
	 * none left.
	 */
	private function eatCarrot(Block $block) : bool{
		if(!$block instanceof Carrot){
			return false;
		}
		$age = $block->getAge();
		if(!RaidGardenGoal::changeBlock($this, $block, $age > 0 ? (clone $block)->setAge($age - 1) : VanillaBlocks::AIR())){
			return false;
		}
		$this->getWorld()->addParticle($block->getPosition()->add(0.5, 0.5, 0.5), new BlockBreakParticle($block));
		return true;
	}

	public function getVariant() : int{
		return $this->variant;
	}

	public function setVariant(int $variant) : void{
		$this->variant = $variant;
		$this->networkPropertiesDirty = true;
	}

	public function isBreedingItem(Item $item) : bool{
		$id = $item->getTypeId();
		return $id === ItemTypeIds::CARROT || $id === ItemTypeIds::GOLDEN_CARROT || $id === VanillaBlocks::DANDELION()->asItem()->getTypeId();
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Rabbit($location);
		//entity_born: in a desert or where snow falls the biome coat always wins over the inherited one
		$coat = self::biomeCoat($this->getWorld(), $this->location);
		if($coat === null){
			//offspring mutation_factor variant 0.2: a random coat instead of a parent's
			$coat = mt_rand(0, 4) === 0 ? self::variantForBiome($this->getWorld(), $this->location) : (mt_rand(0, 1) === 0 || !$partner instanceof Rabbit ? $this->variant : $partner->getVariant());
		}
		$baby->setVariant($coat);
		return $baby;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && $this->onGround && $this->moveControl->isMoving()){
			//rabbits hop (Bedrock movement.skip)
			$this->moveControl->jump();
		}
		return $hasUpdate;
	}

	public function getJumpVelocity() : float{
		return 0.3;
	}

	protected function getAdultDrops() : array{
		$drops = [];
		$hide = mt_rand(0, 1) + $this->lootingBonus();
		if($hide > 0){
			$drops[] = VanillaItems::RABBIT_HIDE()->setCount($hide);
		}
		$meat = mt_rand(0, 1) + $this->lootingBonus();
		if($meat > 0){
			$drops[] = $this->cookedIfBurning(VanillaItems::RAW_RABBIT(), VanillaItems::COOKED_RABBIT())->setCount($meat);
		}
		if($this->wasRecentlyHitByPlayerDirectly() && Utils::getRandomFloat() < 0.1 + 0.03 * $this->getLootingLevel()){
			$drops[] = VanillaItems::RABBIT_FOOT();
		}
		return $drops;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->variant);
	}
}
