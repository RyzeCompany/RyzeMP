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

namespace pocketmine\world\raid;

use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\player\Player;
use function abs;
use function spl_object_id;

/**
 * The red "Raid" bar of a raid. A raid has no entity to attach the bar to, so each player is sent the bar with its own
 * actor ID as the boss (a player sees at most one raid).
 */
final class RaidBossBar{
	/**
	 * @var Player[]
	 * @phpstan-var array<int, Player>
	 */
	private array $viewers = [];
	private string $title = "raid.name";
	private float $percent = 0.0;

	public function getTitle() : string{
		return $this->title;
	}

	public function getPercent() : float{
		return $this->percent;
	}

	/**
	 * @return Player[]
	 * @phpstan-return array<int, Player>
	 */
	public function getViewers() : array{
		return $this->viewers;
	}

	public function setTitle(string $title) : void{
		if($title === $this->title){
			return;
		}
		$this->title = $title;
		foreach($this->viewers as $player){
			$player->getNetworkSession()->sendDataPacket(BossEventPacket::title($player->getId(), $title));
		}
	}

	public function setPercent(float $percent) : void{
		if(abs($percent - $this->percent) < 0.001){
			return;
		}
		$this->percent = $percent;
		foreach($this->viewers as $player){
			$player->getNetworkSession()->sendDataPacket(BossEventPacket::healthPercent($player->getId(), $percent));
		}
	}

	/**
	 * Shows the bar to these players and hides it from the others.
	 *
	 * @param Player[] $players
	 */
	public function setViewers(array $players) : void{
		$new = [];
		foreach($players as $player){
			$new[spl_object_id($player)] = $player;
		}
		foreach($this->viewers as $id => $player){
			if(!isset($new[$id])){
				$this->hide($player);
			}
		}
		foreach($new as $id => $player){
			if(!isset($this->viewers[$id])){
				$this->viewers[$id] = $player;
				$player->getNetworkSession()->sendDataPacket(BossEventPacket::show($player->getId(), $this->title, $this->percent, BossBarColor::RED));
			}
		}
	}

	private function hide(Player $player) : void{
		unset($this->viewers[spl_object_id($player)]);
		if($player->isConnected()){
			$player->getNetworkSession()->sendDataPacket(BossEventPacket::hide($player->getId()));
		}
	}

	public function hideAll() : void{
		foreach($this->viewers as $player){
			$this->hide($player);
		}
	}
}
