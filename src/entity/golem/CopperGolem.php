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

namespace pocketmine\entity\golem;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\OpenDoorGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\HeldItemsTrait;
use pocketmine\entity\object\LightningBolt;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Axe;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\CopperWaxApplySound;
use pocketmine\world\sound\CopperWaxRemoveSound;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\ScrapeSound;
use pocketmine\world\vibration\GameEvent;
use function floor;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;

/**
 * Bedrock values (entities/copper_golem.json, 1.21.110): 12 health, 0.6x0.98, movement 0.2, panics at 1.5x, wanders.
 * Moves items from copper chests to chests (see {@link CopperGolemTransportItemsGoal}); the carried items are shown in
 * its hand, dropped when it dies and handed to a player who interacts with an empty hand.
 * Oxidizes one stage every 420-460 minutes (actor property minecraft:oxidation_level, vanilla 504000-552000 ticks)
 * unless waxed with honeycomb; an axe removes the wax or scrapes off one stage, and so does a lightning strike. Once
 * fully oxidized, every tick has a 0.58% chance to turn it into an oxidized copper golem statue facing the same way
 * (vanilla CopperGolem.turnToStatue), if its block is free; scraping an unoxidized statue with an axe brings it back
 * (see {@link \pocketmine\block\CopperGolemStatue}). Can wear a poppy (minecraft:has_flower) taken from an iron golem
 * offering one ({@link TakeFlowerGoal}), sheared off. No fall damage; lightning deals no damage.
 * Loot (loot_tables/entities/copper_golem.json): 1-3 copper ingots, plus the poppy. Pressing copper buttons is not
 * implemented.
 */
class CopperGolem extends Mob{
	use HeldItemsTrait;

	public const TAG_OXIDATION = "OxidationLevel"; //TAG_Int
	public const TAG_WAXED = "IsWaxed"; //TAG_Byte
	public const TAG_HAS_FLOWER = "HasFlower"; //TAG_Byte
	public const TAG_OXIDATION_TIMER = "OxidationTimer"; //TAG_Int

	public const OXIDATION_LEVELS = ["unoxidized", "exposed", "weathered", "oxidized"];

	/** Ticks between two oxidation stages (vanilla nextIntBetweenInclusive(504000, 552000)). */
	public const OXIDATION_MIN_TICKS = 504000;
	public const OXIDATION_MAX_TICKS = 552000;
	/** Chance per tick for a fully oxidized golem to turn into a statue. */
	public const TURN_TO_STATUE_CHANCE = 0.0058;

	private int $oxidation = 0;
	private bool $waxed = false;
	private bool $hasFlower = false;
	private int $oxidationTimer = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::COPPER_GOLEM; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.98, 0.6, 0.8);
	}

	public function canBeLeashed() : bool{
		return true;
	}

	public function getName() : string{
		return "Copper Golem";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(12);
		$this->setMovementSpeed(0.2);
		parent::initEntity($nbt);
		$this->setOxidationLevel($nbt->getInt(self::TAG_OXIDATION, 0));
		$this->waxed = $nbt->getByte(self::TAG_WAXED, 0) !== 0;
		$this->setHasFlower($nbt->getByte(self::TAG_HAS_FLOWER, 0) !== 0);
		$this->oxidationTimer = $nbt->getInt(self::TAG_OXIDATION_TIMER, self::nextOxidationDelay());
		$this->loadHeldItems($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_OXIDATION, $this->oxidation);
		$nbt->setByte(self::TAG_WAXED, $this->waxed ? 1 : 0);
		$nbt->setByte(self::TAG_HAS_FLOWER, $this->hasFlower ? 1 : 0);
		$nbt->setInt(self::TAG_OXIDATION_TIMER, $this->oxidationTimer);
		$this->saveHeldItems($nbt);
		return $nbt;
	}

	private static function nextOxidationDelay() : int{
		return mt_rand(self::OXIDATION_MIN_TICKS, self::OXIDATION_MAX_TICKS);
	}

	protected function registerGoals() : void{
		//annotation.open_door: opens the wooden doors on its paths
		$this->goalSelector->addGoal(0, new OpenDoorGoal($this));
		$this->goalSelector->addGoal(2, new PanicGoal($this, 1.5));
		$this->goalSelector->addGoal(3, new CopperGolemTransportItemsGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new TakeFlowerGoal($this, fn() => !$this->hasFlower, fn() => $this->setHasFlower(true)));
		$this->goalSelector->addGoal(5, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(6, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(7, new RandomLookAroundGoal($this));
	}

	public function getOxidationLevel() : int{
		return $this->oxidation;
	}

	public function setOxidationLevel(int $level) : void{
		$this->oxidation = max(0, min(3, $level));
		$this->setActorProperty("minecraft:oxidation_level", self::OXIDATION_LEVELS[$this->oxidation]);
	}

	public function isWaxed() : bool{
		return $this->waxed;
	}

	public function setWaxed(bool $waxed) : void{
		$this->waxed = $waxed;
	}

	public function hasFlower() : bool{
		return $this->hasFlower;
	}

	public function setHasFlower(bool $hasFlower) : void{
		$this->hasFlower = $hasFlower;
		$this->setActorProperty("minecraft:has_flower", $hasFlower);
	}

	/**
	 * Shears the poppy off its head (players and dispensers). Returns false if it has none.
	 */
	public function shear() : bool{
		if(!$this->hasFlower || !$this->isAlive()){
			return false;
		}
		$this->setHasFlower(false);
		$this->getWorld()->dropItem($this->location->add(0, 1, 0), VanillaBlocks::POPPY()->asItem());
		$this->playMobSound(LevelSoundEvent::SHEAR);
		return true;
	}

	/**
	 * Sets the chest animation (actor property minecraft:chest_interaction): one of the
	 * CopperGolemTransportItemsGoal::INTERACTION_* values.
	 */
	public function setChestInteraction(string $interaction) : void{
		$this->setActorProperty("minecraft:chest_interaction", $interaction);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($item->isNull()){
			$held = $this->getMainHandItem();
			if($held->isNull()){
				return false;
			}
			$this->setMainHandItem(VanillaItems::AIR());
			$from = $this->location->add(0, 0.6, 0);
			$this->getWorld()->dropItem($from, $held, $player->getPosition()->subtractVector($from)->normalize()->multiply(0.3));
			return true;
		}
		if($item->getTypeId() === ItemTypeIds::HONEYCOMB && !$this->waxed){
			$this->waxed = true;
			$this->getWorld()->addSound($this->location, new CopperWaxApplySound());
			$this->consumeHeldItem($player);
			return true;
		}
		if($item instanceof Axe && ($this->waxed || $this->oxidation > 0)){
			if($this->waxed){
				$this->waxed = false;
				$this->getWorld()->addSound($this->location, new CopperWaxRemoveSound());
			}else{
				$this->setOxidationLevel($this->oxidation - 1);
				$this->oxidationTimer = self::nextOxidationDelay();
				$this->getWorld()->addSound($this->location, new ScrapeSound());
			}
			$this->damageTool($player, $item);
			return true;
		}
		if($item instanceof Shears && $this->shear()){
			$this->getWorld()->addGameEvent($this->location, GameEvent::SHEAR, $player);
			$this->damageTool($player, $item);
			return true;
		}
		//no "give poppy" interaction: copper golems only get a flower from an iron golem (see TakeFlowerGoal)
		return false;
	}

	private function damageTool(Player $player, Item $item) : void{
		if($player->hasFiniteResources() && $item instanceof Durable){
			$item->applyDamage(1);
			if($item->isBroken()){
				$player->broadcastSound(new ItemBreakSound());
			}
			$player->getInventory()->setItemInHand($item);
		}
	}

	/**
	 * Lightning scrapes off one oxidation stage and deals no damage nor sets it on fire (Bedrock damage_sensor: lightning
	 * deals_damage no, remove_oxidation_layer).
	 */
	public function onStruckByLightning(LightningBolt $bolt) : void{
		if($this->oxidation > 0 && $this->isAlive()){
			$this->setOxidationLevel($this->oxidation - 1);
			$this->oxidationTimer = self::nextOxidationDelay();
		}
	}

	public function attack(EntityDamageEvent $source) : void{
		//damage_sensor: no fall damage, no lightning damage
		if($source->getCause() === EntityDamageEvent::CAUSE_FALL || $source->getCause() === EntityDamageEvent::CAUSE_LIGHTNING){
			$source->cancel();
		}
		parent::attack($source);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive() || $this->waxed || $this->isFlaggedForDespawn()){
			return $hasUpdate;
		}
		if($this->oxidation < 3){
			if(($this->oxidationTimer -= $tickDiff) <= 0){
				$this->oxidationTimer = self::nextOxidationDelay();
				$this->setOxidationLevel($this->oxidation + 1);
			}
		}elseif(mt_rand() / mt_getrandmax() < self::TURN_TO_STATUE_CHANCE * $tickDiff){
			$this->turnToStatue();
		}
		return $hasUpdate;
	}

	/**
	 * Replaces the golem with an oxidized copper golem statue facing the same way, if its block is free (vanilla
	 * CopperGolem.canTurnToStatue). The held items drop.
	 */
	public function turnToStatue() : bool{
		$world = $this->getWorld();
		$pos = new Vector3((int) floor($this->location->x), (int) floor($this->location->y), (int) floor($this->location->z));
		if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) || $world->getBlock($pos)->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}
		$world->setBlock($pos, VanillaBlocks::COPPER_GOLEM_STATUE()->setOxidation(CopperOxidation::OXIDIZED)->setFacing($this->getHorizontalFacing()));
		$held = $this->getMainHandItem();
		if(!$held->isNull()){
			$world->dropItem($this->location->add(0, 0.5, 0), $held);
		}
		if($this->hasFlower){
			$world->dropItem($this->location->add(0, 0.5, 0), VanillaBlocks::POPPY()->asItem());
		}
		$this->flagForDespawn();
		return true;
	}

	public function getDrops() : array{
		$drops = [VanillaItems::COPPER_INGOT()->setCount(mt_rand(1, 3))];
		if($this->hasFlower){
			$drops[] = VanillaBlocks::POPPY()->asItem();
		}
		$held = $this->getMainHandItem();
		if(!$held->isNull()){
			$drops[] = $held;
		}
		return $drops;
	}
}
