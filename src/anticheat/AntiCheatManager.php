<?php

declare(strict_types=1);

namespace pocketmine\anticheat;

use pocketmine\player\Player;

/**
 * Owns anti-cheat state without extending Player or keeping stale players alive.
 */
final class AntiCheatManager{
	private static ?self $instance = null;

	/** @var \WeakMap<Player, AntiCheatSession> */
	private \WeakMap $sessions;

	private function __construct(){
		$this->sessions = new \WeakMap();
	}

	public static function getInstance() : self{
		return self::$instance ??= new self();
	}

	public function getSession(Player $player) : AntiCheatSession{
		return $this->sessions[$player] ??= new AntiCheatSession();
	}

	public function removeSession(Player $player) : void{
		unset($this->sessions[$player]);
	}
}
