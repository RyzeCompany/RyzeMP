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
use pocketmine\loot\LootItemConverter;
use pocketmine\loot\LootTables;
use pocketmine\math\Vector3;
use pocketmine\world\particle\VaultActivateParticle;
use pocketmine\world\particle\VaultDeactivateParticle;
use pocketmine\world\particle\VaultEjectItemParticle;
use pocketmine\world\sound\VaultCloseShutterSound;
use pocketmine\world\sound\VaultEjectItemSound;
use pocketmine\world\sound\VaultInsertItemSound;
use pocketmine\world\sound\VaultOpenShutterSound;
use pocketmine\world\sound\VaultRejectRewardedPlayerSound;
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
		$items = LootTables::get($lootTable)?->generate($this->createLootContext()) ?? [];
		$count = count($items);
		return $count > 0 ? $items[mt_rand(0, $count - 1)] : null;
	}

	public function rollRewards(string $lootTable, int $playerEntityId, Item $key) : array{
		$table = LootTables::get($lootTable);
		if($table === null){
			\GlobalLogger::get()->debug("Loot table $lootTable of the vault at " . $this->tile->getPosition() . " is not available");
			return [];
		}
		$context = $this->createLootContext();
		$context->thisEntity = $this->getWorld()->getEntity($playerEntityId);
		$context->tool = LootItemConverter::fromItem($key);
		return $table->generate($context);
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
		$world->addParticle($pos->floor(), new VaultEjectItemParticle());
		$world->addSound($this->getCenter(), new VaultEjectItemSound());
	}

	public function onActivated(bool $active, bool $ominous) : void{
		$this->getWorld()->addParticle($this->tile->getPosition()->floor(), $active ? new VaultActivateParticle($ominous) : new VaultDeactivateParticle($ominous));
	}

	public function onKeyInserted() : void{
		$this->getWorld()->addSound($this->getCenter(), new VaultInsertItemSound());
	}

	public function onShutter(bool $open) : void{
		$this->getWorld()->addSound($this->getCenter(), $open ? new VaultOpenShutterSound() : new VaultCloseShutterSound());
	}

	public function onInsertFailed(bool $rewardedPlayer) : void{
		$this->getWorld()->addSound($this->getCenter(), $rewardedPlayer ? new VaultRejectRewardedPlayerSound() : new VaultInsertItemSound(false));
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
