<?php

declare(strict_types=1);

namespace pocketmine\anticheat;

use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function number_format;

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
		return $this->sessions[$player] ??= new AntiCheatSession($player);
	}

	public function removeSession(Player $player) : void{
		unset($this->sessions[$player]);
	}
	public function notifyViolation(Player $player, string $check, float $weight, float $total, string $mode, string $details = "") : void{
		$session = $player->getNetworkSession();
		$ping = $session->getPing();
		$protocol = $session->getProtocolId();

		$modeColor = $mode === AntiCheatSession::MODE_BLOCK ? TextFormat::RED : TextFormat::YELLOW;
		$message =
			TextFormat::DARK_GRAY . "[" . TextFormat::RED . "AntiCheat" . TextFormat::DARK_GRAY . "] " .
			$modeColor . $mode . TextFormat::GRAY . " " .
			TextFormat::WHITE . $player->getName() . TextFormat::GRAY .
			" check=" . TextFormat::AQUA . $check . TextFormat::GRAY .
			" +" . number_format($weight, 2) .
			" VL=" . number_format($total, 2) .
			" ping=" . $ping . "ms" .
			" proto=" . $protocol .
			($details !== "" ? " " . $details : "");

		$player->getServer()->getLogger()->warning(TextFormat::clean($message));
		foreach($player->getServer()->getOnlinePlayers() as $recipient){
			if($recipient->hasPermission(DefaultPermissionNames::ANTICHEAT_LOG)){
				$recipient->sendMessage($message);
			}
		}
	}

}
