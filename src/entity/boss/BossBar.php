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

namespace pocketmine\entity\boss;

use pocketmine\entity\Living;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\player\Player;
use function abs;
use function max;
use function min;
use function spl_object_id;

/**
 * The boss bar of a boss entity (vanilla "minecraft:boss" component): shown to the players who see the boss within
 * the HUD range, with the boss' health. The bar is attached to the boss entity itself (BossEvent with the boss'
 * actor ID), like vanilla, so no fake entity is needed.
 */
final class BossBar{
	/** Ticks between two checks of which players are in range. */
	public const VIEWER_UPDATE_INTERVAL = 10;

	/**
	 * @var Player[]
	 * @phpstan-var array<int, Player>
	 */
	private array $viewers = [];
	private float $lastPercent = -1.0;
	private int $ticks = 0;
	private bool $visible = true;

	/**
	 * @param float $range HUD range: players farther than this from the boss don't see the bar
	 * @param int   $color BossBarColor::* (vanilla bosses are light purple, i.e. pink)
	 * @phpstan-param BossBarColor::* $color
	 */
	public function __construct(
		private Living $boss,
		private float $range,
		private string $title,
		private int $color = BossBarColor::PINK
	){}

	public function getTitle() : string{
		return $this->title;
	}

	public function setTitle(string $title) : void{
		if($title === $this->title){
			return;
		}
		$this->title = $title;
		foreach($this->viewers as $player){
			$player->getNetworkSession()->sendDataPacket(BossEventPacket::title($this->boss->getId(), $title));
		}
	}

	public function getRange() : float{
		return $this->range;
	}

	/**
	 * Hides the bar from everyone until shown again (e.g. during the boss' death animation).
	 */
	public function setVisible(bool $visible) : void{
		$this->visible = $visible;
		if(!$visible){
			$this->hideAll();
		}else{
			$this->ticks = 0;
		}
	}

	/**
	 * Returns the fraction of health displayed (0-1).
	 */
	public function getPercent() : float{
		$max = $this->boss->getMaxHealth();
		return $max > 0 ? max(0.0, min(1.0, $this->boss->getHealth() / $max)) : 0.0;
	}

	/**
	 * @return Player[]
	 * @phpstan-return array<int, Player>
	 */
	public function getViewers() : array{
		return $this->viewers;
	}

	public function isViewer(Player $player) : bool{
		return isset($this->viewers[spl_object_id($player)]);
	}

	/**
	 * Called every tick by the boss: updates the players who see the bar and the displayed health.
	 */
	public function tick() : void{
		if(!$this->visible){
			return;
		}
		if($this->ticks++ % self::VIEWER_UPDATE_INTERVAL === 0){
			$this->updateViewers();
		}
		$percent = $this->getPercent();
		if(abs($percent - $this->lastPercent) >= 0.001){
			$this->lastPercent = $percent;
			$id = $this->boss->getId();
			foreach($this->viewers as $player){
				$player->getNetworkSession()->sendDataPacket(BossEventPacket::healthPercent($id, $percent));
			}
		}
	}

	private function updateViewers() : void{
		$bossPos = $this->boss->getPosition();
		$rangeSq = $this->range * $this->range;
		$inRange = [];
		foreach($this->boss->getViewers() as $player){
			if($player->isConnected() && $player->getWorld() === $this->boss->getWorld() && $player->getPosition()->distanceSquared($bossPos) <= $rangeSq){
				$inRange[spl_object_id($player)] = $player;
			}
		}
		foreach($this->viewers as $id => $player){
			if(!isset($inRange[$id])){
				$this->hide($player);
			}
		}
		foreach($inRange as $id => $player){
			if(!isset($this->viewers[$id])){
				$this->show($player);
			}
		}
	}

	public function show(Player $player) : void{
		$this->viewers[spl_object_id($player)] = $player;
		$player->getNetworkSession()->sendDataPacket(BossEventPacket::show($this->boss->getId(), $this->title, $this->getPercent(), false, $this->color));
	}

	public function hide(Player $player) : void{
		unset($this->viewers[spl_object_id($player)]);
		if($player->isConnected()){
			$player->getNetworkSession()->sendDataPacket(BossEventPacket::hide($this->boss->getId()));
		}
	}

	public function hideAll() : void{
		foreach($this->viewers as $player){
			$this->hide($player);
		}
		$this->viewers = [];
	}
}
