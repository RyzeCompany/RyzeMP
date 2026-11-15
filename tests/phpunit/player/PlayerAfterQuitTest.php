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

namespace pocketmine\player;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\plugin\CapturingLogger;

/**
 * Plugins keep Player objects after the player quit (repeating HUD/scoreboard tasks, delayed database callbacks) and
 * clients send whatever they like as form responses.
 */
final class PlayerAfterQuitTest extends TestCase{

	private function destroyedPlayer(CapturingLogger $logger) : Player{
		//what is left of a Player after destroyCycles(): no network session any more
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $logger);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, null);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true); //no destructor cleanup for this partial object
		return $player;
	}

	public function testSendersAreNoOpsAfterQuit() : void{
		$logger = new CapturingLogger();
		$player = $this->destroyedPlayer($logger);
		$form = new class implements Form{
			public function handleResponse(Player $player, $data) : void{}

			/**
			 * @return mixed[]
			 * @phpstan-return array<string, mixed>
			 */
			public function jsonSerialize() : array{
				return ["type" => "modal", "title" => "", "content" => "", "button1" => "", "button2" => ""];
			}
		};

		$player->sendMessage("a");
		$player->sendTip("a");
		$player->sendPopup("a");
		$player->sendActionBarMessage("a");
		$player->sendJukeboxPopup("a");
		$player->sendTitle("a", "b", 1, 2, 3);
		$player->sendSubTitle("a");
		$player->setTitleDuration(1, 2, 3);
		$player->removeTitles();
		$player->resetTitles();
		$player->sendToastNotification("a", "b");
		$player->sendForm($form);
		$player->closeAllForms();
		self::assertFalse($player->transfer("127.0.0.1", 19132));
		self::assertFalse($player->hasPendingForm(0), "a form sent after quit must not wait for a response");

		self::assertCount(1, $logger->messages(\LogLevel::DEBUG, "after the player quit"), "logged once, not for every call");
	}

	public function testGetNetworkSessionStillThrows() : void{
		$player = $this->destroyedPlayer(new CapturingLogger());
		$this->expectException(\LogicException::class);
		$player->getNetworkSession();
	}

	public function testInvalidFormResponsesAreRateLimited() : void{
		$logger = new CapturingLogger();
		$player = $this->destroyedPlayer($logger);
		$form = new class implements Form{
			public function handleResponse(Player $player, $data) : void{
				throw new FormValidationException("Expected bool");
			}

			/**
			 * @return mixed[]
			 * @phpstan-return array<string, mixed>
			 */
			public function jsonSerialize() : array{
				return [];
			}
		};
		$forms = [];
		for($i = 0; $i < 20; $i++){
			$forms[$i] = $form;
		}
		(new \ReflectionProperty(Player::class, "forms"))->setValue($player, $forms);
		for($i = 0; $i < 20; $i++){
			self::assertTrue($player->onFormSubmit($i, null));
		}

		self::assertSame([], $logger->messages(\LogLevel::CRITICAL), "a client must not be able to fill the log with criticals and traces");
		$notices = $logger->messages(\LogLevel::NOTICE, "Invalid response to form");
		self::assertCount(1, $notices);
		self::assertStringContainsString("Expected bool", $notices[0]);
		self::assertStringContainsString("Form@anonymous", $notices[0], "the admin must see which form class fails");
		self::assertCount(19, $logger->messages(\LogLevel::DEBUG, "Invalid response to form"));
		self::assertFalse($player->hasPendingForm(0));
	}
}
