<?php
declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\ai\goal\CreeperSwellGoal;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\item\FlintSteel;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\Explosion;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * Bedrock creeper with cancellable explosion and persistent charging/fuse NBT.
 * Destruction of terrain obeys /gamerule mobgriefing and event cancellation.
 */
class Creeper extends Mob implements Explosive{
	private const TAG_POWERED = "powered";
	private const TAG_FUSE = "Fuse";
	private const TAG_IGNITED = "ignited";
	private bool $powered = false;
	private bool $ignited = false;
	private bool $exploded = false;
	private int $fuse = 30;
	private int $swell = 0;
	private int $swellDirection = -1;

	public static function getNetworkTypeId() : string{ return EntityIds::CREEPER; }
	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6); }
	public function getName() : string{ return "Creeper"; }

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		parent::initEntity($nbt);
		$this->powered = $nbt->getByte(self::TAG_POWERED, 0) !== 0;
		$this->fuse = max(1, $nbt->getShort(self::TAG_FUSE, 30));
		$this->ignited = $nbt->getByte(self::TAG_IGNITED, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_POWERED, $this->powered ? 1 : 0);
		$nbt->setShort(self::TAG_FUSE, $this->fuse);
		$nbt->setByte(self::TAG_IGNITED, $this->ignited ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->getGoalSelector()->addGoal(1, new CreeperSwellGoal($this));
		parent::registerGoals();
	}

	public function setPowered(bool $value) : void{
		$this->powered = $value;
		$this->networkPropertiesDirty = true;
	}
	public function isPowered() : bool{ return $this->powered; }
	public function isIgnited() : bool{ return $this->ignited; }
	public function isExploded() : bool{ return $this->exploded; }
	public function getSwell() : int{ return $this->swell; }
	public function getFuse() : int{ return $this->fuse; }

	public function setSwellDirection(int $direction) : void{
		$next = $direction > 0 ? 1 : -1;
		if($this->swellDirection !== $next){
			$this->swellDirection = $next;
			$this->networkPropertiesDirty = true;
		}
	}

	public function ignite() : void{
		if(!$this->ignited){
			$this->ignited = true;
			$this->setSwellDirection(1);
			$this->networkPropertiesDirty = true;
		}
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		$item = $player->getInventory()->getItemInHand();
		if($item instanceof FlintSteel && !$this->ignited){
			$this->ignite();
			if($player->hasFiniteResources()){
				$item->applyDamage(1);
				$player->getInventory()->setItemInHand($item->isBroken() ? VanillaItems::AIR() : $item);
			}
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			$this->flagForDespawn();
			return false;
		}
		if($this->isClosed() || !$this->isAlive() || $this->exploded){return $changed;}
		if($this->ignited){$this->setSwellDirection(1);}
		$this->swell = max(0, min($this->fuse,
			$this->swell + ($this->swellDirection > 0 ? $tickDiff : -$tickDiff)));
		if($this->swell >= $this->fuse){$this->explode();}
		return $changed;
	}

	public function explode() : void{
		if($this->exploded || $this->isClosed()){return;}
		$this->exploded = true;
		$event = new EntityPreExplodeEvent($this, $this->powered ? 6.0 : 3.0);
		$event->call();
		if($event->isCancelled()){
			$this->exploded = false;
			$this->swell = 0;
			$this->ignited = false;
			$this->setSwellDirection(-1);
			return;
		}
		$this->flagForDespawn();
		$explosion = new Explosion(Position::fromObject($this->getPosition(), $this->getWorld()),
			$event->getRadius(), $this, $event->getFireChance());
		if($event->isBlockBreaking() && $this->getWorld()->getGameRules()->getBool(GameRules::MOB_GRIEFING)){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->exploded){$source->cancel();return;}
		parent::attack($source);
	}

	public function getDrops() : array{
		return $this->exploded ? [] : [VanillaItems::GUNPOWDER()->setCount(mt_rand(0, 2))];
	}
	public function getXpDropAmount() : int{ return 5; }
	public function getPickedItem() : ?Item{ return VanillaItems::CREEPER_SPAWN_EGG(); }

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::IGNITED, $this->swellDirection > 0 || $this->ignited);
		$properties->setGenericFlag(EntityMetadataFlags::POWERED, $this->powered);
		$properties->setInt(EntityMetadataProperties::FUSE_LENGTH, $this->fuse);
	}
}
