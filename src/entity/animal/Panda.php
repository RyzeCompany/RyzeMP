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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\MountPathingGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use function count;
use function floor;
use function mt_rand;

/**
 * Bedrock values (entities/panda.json): 20 health (weak: 10), 1.3x1.25, movement 0.15, babies scale 0.4. Two genes
 * (main and hidden allele, saved as Bedrock's "GeneArray"); the personality (VARIANT) is the main gene, except the
 * recessive brown and weak genes which only show when both alleles match. Normal 0, lazy 1, worried 2, playful 3,
 * brown 4, weak 5, aggressive 6; random genes and inheritance (one allele from each parent, 1/32 mutation) follow
 * Java. Adult pandas hit back once (2 damage, melee_box_attack attack_once), aggressive ones keep fighting (6 damage);
 * a hurt panda (babies included) calls the aggressive pandas within 41 blocks (panda_angry / baby_scared
 * broadcast_anger). Worried pandas flee from other mobs and hide their face during thunderstorms (SCARED), lazy ones
 * lie down (LAYING_DOWN) and move slowly (0.07, panic 2.5x), playful ones roll (ROLLING). Pandas sit down from time to
 * time (SITTING), and snack on bamboo and cake (EATING): they pick it up from the ground or take it from a player's hand
 * (giveable, 3 s cooldown, which also calms an angry panda), see {@link PandaSnackGoal}. Tempted and bred with bamboo,
 * which needs 8 bamboo blocks within 5 blocks. Babies sneeze (weak babies more often) and drop a slime ball once in a
 * thousand sneezes. Carries a baby zombie (seat 1.025 up, cubs 0.75 up and 0.2 back) and walks to its rider's target
 * (behavior.mount_pathing, 1.5x).
 * Loot (loot_tables/entities/panda.json): 0-2 bamboo (+0-1 per Looting level); XP 1-3. The snack it holds isn't dropped
 * on death (minecraft:inventory is private).
 */
class Panda extends Animal{
	public const TAG_GENES = "GeneArray"; //TAG_List<TAG_Compound{MainAllele, HiddenAllele}>
	private const TAG_SNACK = "Mainhand"; //TAG_List<TAG_Compound> (the 1-slot private inventory)

	/** Bedrock angry broadcast_range of panda_angry and baby_scared. */
	public const ANGER_BROADCAST_RANGE = 41.0;
	/** minecraft:giveable cooldown, in ticks. */
	private const GIVE_COOLDOWN = 60;

	public const GENE_NORMAL = 0;
	public const GENE_LAZY = 1;
	public const GENE_WORRIED = 2;
	public const GENE_PLAYFUL = 3;
	public const GENE_BROWN = 4;
	public const GENE_WEAK = 5;
	public const GENE_AGGRESSIVE = 6;

	private int $mainGene = self::GENE_NORMAL;
	private int $hiddenGene = self::GENE_NORMAL;
	private int $actionTicks = 0;
	private bool $lyingDown = false;
	private bool $rolling = false;
	private bool $sneezing = false;
	private bool $sitting = false;
	private bool $eating = false;
	private int $eatTicks = 0;
	private bool $scared = false;
	/** Bamboo or cake the panda holds to eat (its 1-slot private inventory). */
	private ?Item $snack = null;
	private bool $disposing = false;
	/** Server tick until which nothing more can be handed over (giveable cooldown). */
	private int $giveCooldownUntil = 0;

	private static ?int $bambooTypeId = null;
	private static ?int $cakeTypeId = null;

	public static function getNetworkTypeId() : string{ return EntityIds::PANDA; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.25, 1.3, 1.0);
	}

	public function getName() : string{
		return "Panda";
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	protected function getBabyScale() : float{
		return 0.4;
	}

	/**
	 * Java Panda.Gene.getRandom(): out of 16, lazy, worried, playful and aggressive 1 each, weak 5, brown 2, normal 5.
	 */
	public static function randomGene() : int{
		$i = mt_rand(0, 15);
		return match(true){
			$i === 0 => self::GENE_LAZY,
			$i === 1 => self::GENE_WORRIED,
			$i === 2 => self::GENE_PLAYFUL,
			$i === 4 => self::GENE_AGGRESSIVE,
			$i < 9 => self::GENE_WEAK,
			$i < 11 => self::GENE_BROWN,
			default => self::GENE_NORMAL
		};
	}

	/**
	 * Personality shown by a pair of genes.
	 */
	public static function personality(int $main, int $hidden) : int{
		if($main === self::GENE_BROWN || $main === self::GENE_WEAK){
			return $main === $hidden ? $main : self::GENE_NORMAL;
		}
		return $main;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$genes = $nbt->getListTag(self::TAG_GENES);
		$gene = $genes !== null ? $genes->first() : null;
		if($gene instanceof CompoundTag){
			$this->mainGene = $gene->getInt("MainAllele", 0) % 7;
			$this->hiddenGene = $gene->getInt("HiddenAllele", 0) % 7;
		}else{
			$this->mainGene = self::randomGene();
			$this->hiddenGene = self::randomGene();
		}
		$this->applyPersonality();
		parent::initEntity($nbt);
		$snack = $nbt->getListTag(self::TAG_SNACK, CompoundTag::class)?->first();
		if($snack instanceof CompoundTag){
			try{
				$item = Item::nbtDeserialize($snack);
				$this->snack = $item->isNull() ? null : $item;
			}catch(SavedDataLoadingException){
				//an item that no longer exists
			}
		}
	}

	/**
	 * Health and speed of the personality: weak pandas have 10 health, lazy ones move at 0.07 instead of 0.15.
	 */
	private function applyPersonality() : void{
		$personality = $this->getPersonality();
		$this->setMaxHealth($personality === self::GENE_WEAK ? 10 : 20);
		$this->setMovementSpeed($personality === self::GENE_LAZY ? 0.07 : 0.15);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setTag(self::TAG_GENES, new ListTag([CompoundTag::create()->setInt("MainAllele", $this->mainGene)->setInt("HiddenAllele", $this->hiddenGene)]));
		if($this->snack !== null){
			$nbt->setTag(self::TAG_SNACK, new ListTag([$this->snack->nbtSerialize()]));
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PandaScaredGoal($this));
		//panda_lazy: panic at 2.5x, as lazy pandas walk at half the speed
		$this->goalSelector->addGoal(1, new class($this, 1.25, fn() => $this->getPersonality() !== self::GENE_AGGRESSIVE) extends PanicGoal{
			public function start() : void{
				$this->speedMultiplier = $this->mob instanceof Panda && $this->mob->getPersonality() === Panda::GENE_LAZY ? 2.5 : 1.25;
				parent::start();
			}
		});
		//panda_aggressive: only fire-like damage, never hits (damage_sources, ignore_mob_damage)
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25, fn() => $this->getPersonality() === self::GENE_AGGRESSIVE, PanicGoal::FIRE_CAUSES, true));
		//panda_adult attack 2 with attack_once (one hit back), panda_aggressive attack 6
		$this->goalSelector->addGoal(2, new class($this, 1.2, 2.0) extends MeleeAttackGoal{
			protected function performAttack(Living $target) : void{
				$panda = $this->mob;
				$aggressive = $panda instanceof Panda && $panda->getPersonality() === Panda::GENE_AGGRESSIVE;
				$panda->attackMelee($target, $aggressive ? 6.0 : 2.0);
				if(!$aggressive){
					$panda->setAttackTarget(null);
				}
			}
		});
		$this->goalSelector->addGoal(2, new PandaSnackGoal($this));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new TemptGoal($this, 1.25, fn(Item $item) => $this->isBreedingItem($item)));
		$this->goalSelector->addGoal(5, new MountPathingGoal($this, 1.5));
		//only worried pandas avoid anything: the others don't look around
		$this->goalSelector->addGoal(5, new AvoidEntityGoal($this, fn(Entity $e) => $this->getPersonality() === self::GENE_WORRIED && $e instanceof Living && !$e instanceof Panda, 16.0, 1.0, 1.5, fn() => $this->getPersonality() === self::GENE_WORRIED));
		$this->goalSelector->addGoal(6, new class($this) extends Goal{
			public function __construct(private Panda $panda){}

			public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_JUMP; }

			public function canStart() : bool{
				return $this->panda->isOnGround() && $this->panda->canPerformAction() && $this->panda->wantsToAct();
			}

			public function canContinue() : bool{
				return $this->panda->isActing();
			}

			public function start() : void{
				$this->panda->getNavigation()->stop();
				$this->panda->startAction();
			}

			public function stop() : void{
				$this->panda->stopAction();
			}
		});
		$this->goalSelector->addGoal(5, new PandaSitGoal($this));
		$this->goalSelector->addGoal(13, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(14, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));
		//a hurt panda calls the aggressive pandas around (panda_angry and baby_scared broadcast_anger); the attacker is
		//next to the panda that called them, a bit farther than the broadcast range from the farthest ones
		$this->targetSelector->addGoal(1, new HurtByTargetGoal(
			$this,
			48.0,
			alertRange: self::ANGER_BROADCAST_RANGE,
			alertFilter: fn(Mob $mob) => $mob instanceof Panda && $mob->getPersonality() === self::GENE_AGGRESSIVE
		));
	}

	public function getMainGene() : int{ return $this->mainGene; }

	public function getHiddenGene() : int{ return $this->hiddenGene; }

	public function setGenes(int $main, int $hidden) : void{
		$this->mainGene = $main;
		$this->hiddenGene = $hidden;
		$this->applyPersonality();
		$this->networkPropertiesDirty = true;
	}

	public function getPersonality() : int{
		return self::personality($this->mainGene, $this->hiddenGene);
	}

	public function setAttackTarget(?Living $target) : void{
		//adults hit back (once, unless aggressive); babies only call the aggressive pandas around (baby_scared)
		parent::setAttackTarget($this->isBaby() && $this->getPersonality() !== self::GENE_AGGRESSIVE ? null : $target);
	}

	protected function isAngryWhenTargeting() : bool{
		//on_target_acquired adds panda_angry (minecraft:angry)
		return true;
	}

	/**
	 * Lazy pandas lie down, playful ones roll, from time to time.
	 */
	public function wantsToAct() : bool{
		return match($this->getPersonality()){
			self::GENE_LAZY => mt_rand(0, 132) === 0,
			self::GENE_PLAYFUL => mt_rand(0, 25) === 0,
			default => $this->isBaby() && mt_rand(0, 207) === 0
		};
	}

	public function isActing() : bool{
		return $this->actionTicks > 0;
	}

	public function startAction() : void{
		if($this->getPersonality() === self::GENE_LAZY){
			$this->lyingDown = true;
			$this->actionTicks = mt_rand(200, 600);
		}else{
			$this->rolling = true;
			$this->actionTicks = 32;
		}
		$this->networkPropertiesDirty = true;
	}

	public function stopAction() : void{
		$this->actionTicks = 0;
		$this->lyingDown = $this->rolling = false;
		$this->networkPropertiesDirty = true;
	}

	public function isLyingDown() : bool{
		return $this->lyingDown;
	}

	public function isRolling() : bool{
		return $this->rolling;
	}

	/**
	 * Whether the panda is free to start sitting, snacking, lying down or rolling (Java Panda.canPerformAction()).
	 *
	 * @internal used by the panda's goals
	 */
	public function canPerformAction() : bool{
		return !$this->lyingDown && !$this->rolling && !$this->sitting && !$this->eating && !$this->scared;
	}

	public function isSitting() : bool{
		return $this->sitting;
	}

	/**
	 * @internal used by the panda's goals
	 */
	public function setSitting(bool $sitting) : void{
		if(!$sitting){
			$this->eating = false;
		}
		if($this->sitting !== $sitting){
			$this->sitting = $sitting;
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Stops and sits down, unless in water (Java Panda.tryToSit()).
	 *
	 * @internal used by the panda's goals
	 */
	public function sitDown() : void{
		if(!$this->isInWater()){
			$this->getNavigation()->stop();
			$this->setSitting(true);
		}
	}

	public function isEating() : bool{
		return $this->eating;
	}

	public function isScared() : bool{
		return $this->scared;
	}

	/**
	 * A scared panda sits and hides its face (see {@link PandaScaredGoal}).
	 *
	 * @internal used by the panda's goals
	 */
	public function setScared(bool $scared) : void{
		$this->scared = $scared;
		$this->setSitting($scared);
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Whether pandas snack on the item (bamboo and cake: Bedrock behavior.snacking items, giveable items).
	 *
	 * @internal
	 */
	public static function isSnackItem(Item $item) : bool{
		$typeId = $item->getTypeId();
		return $typeId === (self::$bambooTypeId ??= VanillaBlocks::BAMBOO()->asItem()->getTypeId()) ||
			$typeId === (self::$cakeTypeId ??= VanillaBlocks::CAKE()->asItem()->getTypeId());
	}

	public function hasSnack() : bool{
		return $this->snack !== null;
	}

	/**
	 * Returns the bamboo or cake the panda holds to eat, null if none.
	 */
	public function getSnack() : ?Item{
		return $this->snack !== null ? clone $this->snack : null;
	}

	private function setSnack(?Item $snack) : void{
		$this->snack = $snack;
		$this->eating = false;
		$this->sendSnack($this->getViewers());
	}

	/**
	 * Picks one snack up from the item entity. Returns whether it did.
	 *
	 * @internal used by {@link PandaSnackGoal}
	 */
	public function takeSnack(ItemEntity $entity) : bool{
		if($this->snack !== null || !PickupItemsGoal::isAvailable($entity)){
			return false;
		}
		$item = $entity->getItem();
		if(!self::isSnackItem($item)){
			return false;
		}
		$ev = new EntityItemPickupEvent($this, $entity, (clone $item)->setCount(1), null);
		$ev->call();
		$picked = $ev->getItem();
		if($ev->isCancelled() || $picked->isNull()){
			return false;
		}
		NetworkBroadcastUtils::broadcastEntityEvent(
			$entity->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $this, $entity)
		);
		if($item->getCount() > 1){
			$entity->setStackSize($item->getCount() - 1);
		}else{
			$entity->flagForDespawn();
		}
		$this->setSnack($picked);
		return true;
	}

	/**
	 * Drops the snack the panda holds, if any (it got up before eating it). Nothing is dropped by a dead, despawning
	 * or closing panda: the snack is kept in its saved data, and Bedrock's private inventory isn't dropped on death.
	 *
	 * @internal used by {@link PandaSnackGoal}
	 */
	public function dropSnack() : void{
		if($this->snack !== null && !$this->disposing && $this->isAlive() && !$this->isClosed() && !$this->isFlaggedForDespawn()){
			$snack = $this->snack;
			$this->setSnack(null);
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $snack);
		}
	}

	/**
	 * Eats the snack held while sitting: starts eating with a chance of 1/80 per tick, and finishes after 5 seconds or
	 * a bit more (Java Panda.handleEating()), when the snack is gone and the panda gets up.
	 */
	private function tickEating(int $tickDiff) : void{
		if(!$this->eating){
			if($this->sitting && !$this->scared && mt_rand(0, 79) === 0){
				$this->eating = true;
				$this->eatTicks = 0;
				$this->networkPropertiesDirty = true;
			}
			return;
		}
		$this->eatTicks += $tickDiff;
		if($this->eatTicks % 5 === 0){
			$this->playMobSound(LevelSoundEvent::EAT);
		}
		if($this->eatTicks > 100 && mt_rand(0, 19) === 0){
			$this->setSnack(null);
			$this->getWorld()->addGameEvent($this->location, GameEvent::EAT, $this);
			$this->setSitting(false);
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * @param Player[] $players
	 */
	private function sendSnack(array $players) : void{
		if(count($players) === 0){
			return;
		}
		$item = $this->snack ?? VanillaItems::AIR();
		TypeConverter::broadcastByTypeConverter($players, fn(TypeConverter $converter) : array => [
			MobEquipmentPacket::create($this->getId(), ItemStackWrapper::legacy($converter->coreItemStackToNet($item)), 0, 0, ContainerIds::INVENTORY)
		]);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);
		if($this->snack !== null){
			$this->sendSnack([$player]);
		}
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->scared){
			return false;
		}
		if(parent::onInteractWithItem($player, $item)){
			return true;
		}
		//minecraft:giveable: bamboo and cake, every 3 seconds; the panda calms down and eats it
		if($this->isBaby() || !self::isSnackItem($item) || $this->isInWater() || $this->server->getTick() < $this->giveCooldownUntil){
			return false;
		}
		$this->giveCooldownUntil = $this->server->getTick() + self::GIVE_COOLDOWN;
		$old = $this->snack;
		$this->setSnack((clone $item)->setCount(1));
		if($old !== null && $player->hasFiniteResources()){
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $old);
		}
		$this->consumeHeldItem($player);
		$this->setAttackTarget(null); //on_give: minecraft:on_calm
		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && !$this->scared){
			$this->setSitting(false); //Java Panda.hurtServer(); a scared panda sits again right away (Java Panda.tick())
		}
	}

	protected function onDispose() : void{
		$this->disposing = true; //the goals stop now: the snack stays in the saved data instead of being dropped
		parent::onDispose();
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === (self::$bambooTypeId ??= VanillaBlocks::BAMBOO()->asItem()->getTypeId());
	}

	public function canBeLeashed() : bool{
		return false; //panda.json has no minecraft:leashable
	}

	protected function canFallInLove(Player $player) : bool{
		//Bedrock environment_requirements: 8 bamboo within 5 blocks
		$world = $this->getWorld();
		$bx = (int) floor($this->location->x);
		$by = (int) floor($this->location->y);
		$bz = (int) floor($this->location->z);
		$count = 0;
		for($x = $bx - 5; $x <= $bx + 5; ++$x){
			for($z = $bz - 5; $z <= $bz + 5; ++$z){
				for($y = $by - 2; $y <= $by + 5; ++$y){
					if($world->getBlockAt($x, $y, $z)->getTypeId() === BlockTypeIds::BAMBOO && ++$count >= 8){
						return true;
					}
				}
			}
		}
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Panda($location);
		$fromThis = mt_rand(0, 1) === 0 ? $this->mainGene : $this->hiddenGene;
		$fromPartner = $partner instanceof Panda ? (mt_rand(0, 1) === 0 ? $partner->getMainGene() : $partner->getHiddenGene()) : self::randomGene();
		if(mt_rand(0, 31) === 0){
			$fromThis = self::randomGene();
		}
		$baby->setGenes($fromThis, $fromPartner);
		return $baby;
	}

	public function getRideableSeats() : array{
		return [$this->isBaby() ? RideableSeat::at(0, 0.75, -0.2) : RideableSeat::at(0, 1.025, 0)];
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		return $entity instanceof Zombie && $entity->isBaby(); //family baby_undead, cubs too
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $hasUpdate;
		}
		if($this->actionTicks > 0 && ($this->actionTicks -= $tickDiff) <= 0){
			$this->stopAction();
		}
		if($this->sneezing){
			$this->sneezing = false;
			$this->networkPropertiesDirty = true;
		}elseif($this->isBaby() && mt_rand(0, $this->getPersonality() === self::GENE_WEAK ? 499 : 5999) === 0){
			//behavior.sneeze probability 0.0001666 per tick (panda_sneezing, weak babies: 0.002)
			$this->sneezing = true;
			$this->networkPropertiesDirty = true;
			$this->playMobSound(LevelSoundEvent::SNEEZE);
			if(self::sneezeDropsSlimeball()){
				$this->getWorld()->dropItem($this->location, VanillaItems::SLIMEBALL());
			}
		}
		if($this->snack !== null){
			$this->tickEating($tickDiff);
		}
		return $hasUpdate;
	}

	/**
	 * Whether a sneeze drops a slime ball (behavior.sneeze drop_item_chance 0.001, loot_tables/entities/panda_sneeze.json).
	 *
	 * @internal
	 */
	public static function sneezeDropsSlimeball() : bool{
		return mt_rand(0, 999) === 0;
	}

	protected function getAdultDrops() : array{
		$count = mt_rand(0, 2) + $this->lootingBonus();
		return $count > 0 ? [VanillaBlocks::BAMBOO()->asItem()->setCount($count)] : [];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->getPersonality());
		$properties->setGenericFlag(EntityMetadataFlags::LAYING_DOWN, $this->lyingDown);
		$properties->setGenericFlag(EntityMetadataFlags::ROLLING, $this->rolling);
		$properties->setGenericFlag(EntityMetadataFlags::SNEEZING, $this->sneezing);
		$properties->setGenericFlag(EntityMetadataFlags::SITTING, $this->sitting);
		$properties->setGenericFlag(EntityMetadataFlags::EATING, $this->eating);
		$properties->setGenericFlag(EntityMetadataFlags::SCARED, $this->scared);
	}
}
