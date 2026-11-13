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

namespace pocketmine\block\vault;

use pocketmine\block\tile\Vault as TileVault;
use pocketmine\block\utils\VaultState;
use pocketmine\block\Vault;
use pocketmine\item\Item;
use pocketmine\loot\LootContext;
use pocketmine\loot\LootStack;
use pocketmine\loot\LootTables;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\World;
use function count;
use function floor;
use function mt_getrandmax;
use function mt_rand;

/**
 * Runs a vault in its world (see {@link VaultHost}).
 * @internal
 */
final class WorldVaultHost implements VaultHost{
	/** Speed of the ejected items (vanilla DefaultDispenseItemBehavior.spawnItem accuracy 2 * 0.0172275). */
	private const EJECT_DEVIATION = 0.034455;

	private ?Vault $block = null;
	private ?LootTables $displayLoot = null;
	private ?string $lastLootError = null;

	public function __construct(
		private TileVault $tile
	){}

	/**
	 * Sets the vault block being run (its state and ominous flag).
	 */
	public function setBlock(Vault $block) : void{
		$this->block = $block;
	}

	private function getWorld() : World{
		return $this->tile->getPosition()->getWorld();
	}

	private function getCenter() : Vector3{
		$pos = $this->tile->getPosition();
		return new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 0.5, $pos->getFloorZ() + 0.5);
	}

	public function getGameTime() : int{
		return $this->getWorld()->getServer()->getTick();
	}

	public function detectPlayers(float $range) : array{
		$pos = $this->tile->getPosition();
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		$rangeSquared = $range * $range;
		$found = [];
		foreach($this->getWorld()->getPlayers() as $player){
			//vanilla PlayerDetector.INCLUDING_CREATIVE_PLAYERS: block positions, spectators left out
			if($player->isSpectator()){
				continue;
			}
			$playerPos = $player->getPosition();
			$dx = (int) floor($playerPos->x) - $x;
			$dy = (int) floor($playerPos->y) - $y;
			$dz = (int) floor($playerPos->z) - $z;
			if($dx * $dx + $dy * $dy + $dz * $dz < $rangeSquared){
				$found[$player->getUniqueId()->toString()] = $player->getId();
			}
		}
		return $found;
	}

	private function createLootContext() : LootContext{
		$context = LootContext::create();
		$context->world = $this->getWorld();
		$context->origin = $this->getCenter();
		return $context;
	}

	public function rollDisplayItem(string $lootTable) : ?Item{
		$items = $this->rollLoot($lootTable, $this->createLootContext(), display: true);
		$count = count($items);
		return $count > 0 ? $items[mt_rand(0, $count - 1)] : null;
	}

	public function rollRewards(string $lootTable, int $playerEntityId, Item $key) : array{
		$context = $this->createLootContext();
		$context->thisEntity = $this->getWorld()->getEntity($playerEntityId);
		$context->tool = new LootStack(GlobalItemDataHandlers::getSerializer()->serializeType($key)->getName(), $key->getCount());
		return $this->rollLoot($lootTable, $context);
	}

	/** @return list<Item> */
	private function rollLoot(string $name, LootContext $context, bool $display = false) : array{
		try{
			$loot = $display ?
				($this->displayLoot ??= LootTables::forWorld($this->getWorld())) :
				LootTables::forWorld($this->getWorld());
			return $loot?->get($name)?->generate($context) ?? [];
		}catch(\RuntimeException|\JsonException $e){
			$message = $e->getMessage();
			if($message !== $this->lastLootError){
				$this->lastLootError = $message;
				$this->getWorld()->getLogger()->warning("Unable to roll vault loot at " . $this->tile->getPosition() . ": $message");
			}
			return [];
		}
	}

	public function isOminous() : bool{
		return $this->block !== null && $this->block->isOminous();
	}

	public function setVaultState(VaultState $state) : void{
		$this->block?->setVaultState($state);
	}

	public function ejectItem(Item $item) : void{
		$pos = $this->tile->getPosition();
		$world = $this->getWorld();
		//vanilla DefaultDispenseItemBehavior.spawnItem upwards from 1.2 blocks above the bottom of the vault
		$motion = new Vector3(self::triangle(0.0, self::EJECT_DEVIATION), self::triangle(0.2, self::EJECT_DEVIATION), self::triangle(0.0, self::EJECT_DEVIATION));
		$world->dropItem(new Vector3($pos->getFloorX() + 0.5, $pos->getFloorY() + 1.2 - 0.125, $pos->getFloorZ() + 0.5), $item, $motion);
		$this->broadcastAnimation(LevelEvent::ANIMATION_VAULT_EJECT_ITEM);
		$this->broadcastSound(LevelSoundEvent::VAULT_EJECT_ITEM);
	}

	public function onActivated(bool $active, bool $ominous) : void{
		$this->broadcastAnimation($active ? LevelEvent::ANIMATION_VAULT_ACTIVATE : LevelEvent::ANIMATION_VAULT_DEACTIVATE, $ominous ? 1 : 0);
	}

	public function onKeyInserted() : void{
		$this->broadcastSound(LevelSoundEvent::VAULT_INSERT_ITEM);
	}

	public function onShutter(bool $open) : void{
		$this->broadcastSound($open ? LevelSoundEvent::VAULT_OPEN_SHUTTER : LevelSoundEvent::VAULT_CLOSE_SHUTTER);
	}

	public function onInsertFailed(bool $rewardedPlayer) : void{
		$this->broadcastSound($rewardedPlayer ? LevelSoundEvent::VAULT_REJECT_REWARDED_PLAYER : LevelSoundEvent::VAULT_INSERT_ITEM_FAIL);
	}

	private function broadcastAnimation(int $animation, int $data = 0) : void{
		$pos = $this->tile->getPosition();
		$this->getWorld()->broadcastPacketToViewersByTypeConverter($pos, fn(TypeConverter $converter) : array =>
			$this->tile->canSpawnTo($converter) ? [LevelEventPacket::create($animation, $data, $pos->floor())] : []);
	}

	private function broadcastSound(string $sound) : void{
		$pos = $this->getCenter();
		$this->getWorld()->broadcastPacketToViewersByTypeConverter($pos, fn(TypeConverter $converter) : array =>
			$this->tile->canSpawnTo($converter) ? [LevelSoundEventPacket::nonActorSound($sound, $pos, false)] : []);
	}

	private static function randomFloat() : float{
		return mt_rand() / mt_getrandmax();
	}

	/**
	 * Vanilla RandomSource.triangle(): a value around $mode, at most $deviation away.
	 */
	private static function triangle(float $mode, float $deviation) : float{
		return $mode + $deviation * (self::randomFloat() - self::randomFloat());
	}
}
